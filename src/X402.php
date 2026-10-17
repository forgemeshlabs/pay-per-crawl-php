<?php

declare(strict_types=1);

namespace ForgeMesh\PayPerCrawl;

use ForgeMesh\PayPerCrawl\Contracts\HttpClientInterface;

/**
 * x402 (v1 + v2) and MPP wire formats: challenge envelopes, payment parsing,
 * facilitator verify/settle. Depends only on an HttpClientInterface.
 */
final class X402
{

	const NETWORK_V2     = 'eip155:8453';
	const NETWORK_V1     = 'base';
	const USDC           = '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913';
	const USDC_NAME      = 'USD Coin';
	const USDC_VERSION   = '2';
	const USDC_DECIMALS  = 6;
	const TIMEOUT_SECS   = 300;
	const MPP_PROBLEMS   = 'https://paymentauth.org/problems/';
	const FACILITATORS   = array(
		'payai' => 'https://facilitator.payai.network',
	);

	/**
	 * @param array<string,mixed> $cfg pay_to, price_usd, facilitator_url, facilitator_auth, mpp_secret,
	 *                                 realm, site_name, manifest_url, plugin (generator id)
	 */
	public function __construct( private array $cfg, private HttpClientInterface $http ) {
	}

	public static function usd_to_units( $usd ) {
		return (string) (int) round( (float) $usd * 1000000 );
	}

	public static function units_to_usd( $units ) {
		return ( (float) $units ) / 1000000;
	}

	// ── Requirements ────────────────────────────────────────────────────────

	public function requirements_v2( $resource_url, $description = '' ) {
		return array(
			'scheme'            => 'exact',
			'network'           => self::NETWORK_V2,
			'amount'            => self::usd_to_units( $this->cfg['price_usd'] ),
			'asset'             => self::USDC,
			'payTo'             => $this->cfg['pay_to'],
			'maxTimeoutSeconds' => self::TIMEOUT_SECS,
			'extra'             => array( 'name' => self::USDC_NAME, 'version' => self::USDC_VERSION ),
		);
	}

	public function requirements_v1( $resource_url, $description = '' ) {
		return array(
			'scheme'            => 'exact',
			'network'           => self::NETWORK_V1,
			'maxAmountRequired' => self::usd_to_units( $this->cfg['price_usd'] ),
			'asset'             => self::USDC,
			'payTo'             => $this->cfg['pay_to'],
			'maxTimeoutSeconds' => self::TIMEOUT_SECS,
			'resource'          => $resource_url,
			'description'       => $description,
			'mimeType'          => 'text/html',
			'extra'             => array( 'name' => self::USDC_NAME, 'version' => self::USDC_VERSION ),
		);
	}

	/** v2 envelope that goes base64 into PAYMENT-REQUIRED. */
	public function envelope_v2( $resource_url, $description, $error = 'Payment required' ) {
		return array(
			'x402Version' => 2,
			'error'       => $error,
			'resource'    => array( 'url' => $resource_url, 'description' => $description, 'mimeType' => 'text/html' ),
			'accepts'     => array( $this->requirements_v2( $resource_url, $description ) ),
		);
	}

	/** v1 body for stock x402-fetch 1.x clients. */
	public function body_v1( $resource_url, $description, $error = 'Payment required', $invalid_reason = null ) {
		$body = array(
			'x402Version' => 1,
			'error'       => $error,
			'accepts'     => array( $this->requirements_v1( $resource_url, $description ) ),
			'provider'    => array( 'name' => $this->cfg['site_name'], 'plugin' => (string) ( $this->cfg['plugin'] ?? 'forgemesh-pay-per-crawl-php' ) ),
			'docs'        => array( 'x402' => 'https://x402.org', 'manifest' => $this->cfg['manifest_url'] ),
		);
		if ( $invalid_reason ) {
			$body['invalidReason'] = $invalid_reason;
		}
		return $body;
	}

	// ── Inbound payment headers ────────────────────────────────────────────

	/**
	 * Find a payment on the request. Returns null, or
	 * {rail: x402v2|x402v1|mpp, payload (v2 or v1 structure), error, mpp (parsed credential)}.
	 *
	 * @param array $h lowercased header map.
	 */
	public function extract_payment( array $h, $resource_url ) {
		if ( ! empty( $h['payment-signature'] ) ) {
			$p = self::b64_json( $h['payment-signature'] );
			if ( ! is_array( $p ) || 2 !== (int) ( $p['x402Version'] ?? 0 ) || empty( $p['payload'] ) ) {
				return array( 'rail' => 'x402v2', 'error' => 'malformed_payment_signature' );
			}
			return array( 'rail' => 'x402v2', 'payload' => $p );
		}
		if ( ! empty( $h['x-payment'] ) ) {
			$p = self::b64_json( $h['x-payment'] );
			if ( ! is_array( $p ) || 1 !== (int) ( $p['x402Version'] ?? 0 ) || empty( $p['payload'] ) ) {
				return array( 'rail' => 'x402v1', 'error' => 'malformed_x_payment' );
			}
			return array( 'rail' => 'x402v1', 'payload' => $p );
		}
		if ( ! empty( $h['authorization'] ) && preg_match( '/^Payment\s+([A-Za-z0-9_\-]+)$/i', trim( $h['authorization'] ), $m ) ) {
			$cred = self::b64url_json( $m[1] );
			$err  = $this->validate_mpp( $cred, $resource_url );
			if ( $err ) {
				return array( 'rail' => 'mpp', 'error' => $err['problem'], 'detail' => $err['detail'] );
			}
			$pl = $cred['payload'];
			$v2 = array(
				'x402Version' => 2,
				'resource'    => array( 'url' => $resource_url ),
				'accepted'    => $this->requirements_v2( $resource_url ),
				'payload'     => array(
					'signature'     => $pl['signature'],
					'authorization' => array(
						'from'        => $pl['from'],
						'to'          => $pl['to'],
						'value'       => $pl['value'],
						'validAfter'  => $pl['validAfter'],
						'validBefore' => $pl['validBefore'],
						'nonce'       => $pl['nonce'],
					),
				),
			);
			return array( 'rail' => 'mpp', 'payload' => $v2, 'mpp' => $cred );
		}
		return null;
	}

	/** The EIP-3009 authorization inside any rail's payload, or null. */
	public static function authorization_of( array $payment ) {
		$a = $payment['payload']['payload']['authorization'] ?? null;
		return is_array( $a ) ? $a : null;
	}

	/**
	 * Cheap local checks before talking to the facilitator: right recipient, right
	 * amount, right asset/network. Returns an error code or null.
	 */
	public function precheck( array $payment, $resource_url ) {
		$auth = self::authorization_of( $payment );
		if ( ! $auth || empty( $auth['to'] ) || ! isset( $auth['value'] ) || empty( $auth['nonce'] ) ) {
			return 'malformed_authorization';
		}
		if ( strtolower( $auth['to'] ) !== strtolower( $this->cfg['pay_to'] ) ) {
			return 'wrong_pay_to';
		}
		$want = self::usd_to_units( $this->cfg['price_usd'] );
		if ( (string) $auth['value'] !== $want && ! ( ctype_digit( (string) $auth['value'] ) && (int) $auth['value'] >= (int) $want && 'x402v1' === $payment['rail'] ) ) {
			return 'wrong_amount';
		}
		$p = $payment['payload'];
		if ( 'x402v2' === $payment['rail'] ) {
			$acc = $p['accepted'] ?? array();
			if ( ( $acc['network'] ?? '' ) !== self::NETWORK_V2 || strtolower( $acc['asset'] ?? '' ) !== strtolower( self::USDC ) ) {
				return 'wrong_network_or_asset';
			}
		} elseif ( 'x402v1' === $payment['rail'] ) {
			if ( ( $p['network'] ?? '' ) !== self::NETWORK_V1 || ( $p['scheme'] ?? '' ) !== 'exact' ) {
				return 'wrong_network_or_scheme';
			}
		}
		if ( isset( $auth['validBefore'] ) && ctype_digit( (string) $auth['validBefore'] ) && (int) $auth['validBefore'] < time() ) {
			return 'authorization_expired';
		}
		return null;
	}

	// ── Facilitator ─────────────────────────────────────────────────────────

	public function facilitator_url() {
		$u = trim( (string) ( $this->cfg['facilitator_url'] ?? '' ) );
		if ( '' === $u ) {
			$u = self::FACILITATORS['payai'];
		}
		return rtrim( $u, '/' );
	}

	/**
	 * POST verify then settle. Serve only on a non-empty transaction hash.
	 *
	 * @return array {ok, reason, settle (raw facilitator settle body), payer}
	 */
	public function verify_and_settle( array $payment, $resource_url ) {
		$rail = $payment['rail'];
		$p    = $payment['payload'];
		if ( 'x402v1' === $rail ) {
			$req     = $this->requirements_v1( $resource_url, $this->cfg['site_name'] );
			$version = 1;
		} else {
			$req     = $this->requirements_v2( $resource_url );
			$version = 2;
			// The facilitator deep-compares `accepted` with requirements for v2; send ours.
			$p['accepted'] = $req;
			if ( empty( $p['resource'] ) ) {
				$p['resource'] = array( 'url' => $resource_url );
			}
		}
		$body = array( 'x402Version' => $version, 'paymentPayload' => $p, 'paymentRequirements' => $req );

		$v = $this->post( '/verify', $body );
		if ( is_string( $v ) ) {
			return array( 'ok' => false, 'reason' => 'facilitator_unreachable', 'detail' => $v );
		}
		if ( empty( $v['isValid'] ) ) {
			return array( 'ok' => false, 'reason' => (string) ( $v['invalidReason'] ?? 'verification_failed' ), 'payer' => $v['payer'] ?? null );
		}
		$s = $this->post( '/settle', $body );
		if ( is_string( $s ) ) {
			return array( 'ok' => false, 'reason' => 'facilitator_unreachable', 'detail' => $s );
		}
		$tx = (string) ( $s['transaction'] ?? '' );
		if ( empty( $s['success'] ) || '' === $tx ) {
			return array( 'ok' => false, 'reason' => (string) ( $s['errorReason'] ?? 'settlement_failed' ), 'settle' => $s );
		}
		return array( 'ok' => true, 'settle' => $s, 'tx' => $tx, 'payer' => $s['payer'] ?? ( $v['payer'] ?? null ) );
	}

	/** @return array<string,mixed>|string a decoded JSON object, or an error string */
	private function post( $path, array $body ) {
		$headers = array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' );
		if ( ! empty( $this->cfg['facilitator_auth'] ) ) {
			$headers['Authorization'] = $this->cfg['facilitator_auth'];
		}
		try {
			$r = $this->http->request( 'POST', $this->facilitator_url() . $path, $headers, json_encode( $body, JSON_UNESCAPED_SLASHES ), 45 );
		} catch ( \RuntimeException $e ) {
			return $e->getMessage();
		}
		$json = json_decode( $r['body'], true );
		if ( ! is_array( $json ) ) {
			return 'HTTP ' . $r['status'] . ': ' . substr( $r['body'], 0, 200 );
		}
		return $json;
	}

	// ── MPP ─────────────────────────────────────────────────────────────────

	private function jcs( $value ) {
		if ( is_array( $value ) && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			ksort( $value, SORT_STRING );
			$parts = array();
			foreach ( $value as $k => $v ) {
				$parts[] = json_encode( (string) $k ) . ':' . $this->jcs( $v );
			}
			return '{' . implode( ',', $parts ) . '}';
		}
		if ( is_array( $value ) ) {
			return '[' . implode( ',', array_map( array( $this, 'jcs' ), $value ) ) . ']';
		}
		return json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	public static function b64url( $bin ) {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
	}

	public static function b64url_decode( $s ) {
		return base64_decode( strtr( (string) $s, '-_', '+/' ) );
	}

	public static function b64_json( $s ) {
		$d = base64_decode( (string) $s, true );
		return $d ? json_decode( $d, true ) : null;
	}

	public static function b64url_json( $s ) {
		$d = self::b64url_decode( $s );
		return $d ? json_decode( $d, true ) : null;
	}

	private function challenge_id( $realm, $request_b64, $expires, $digest = '', $opaque = '' ) {
		$input = implode( '|', array( $realm, 'evm', 'charge', $request_b64, $expires, $digest, $opaque ) );
		return self::b64url( hash_hmac( 'sha256', $input, $this->cfg['mpp_secret'], true ) );
	}

	/** WWW-Authenticate: Payment ... header value. */
	public function mpp_challenge( $description = '' ) {
		$request = array(
			'amount'        => self::usd_to_units( $this->cfg['price_usd'] ),
			'currency'      => self::USDC,
			'recipient'     => $this->cfg['pay_to'],
			'methodDetails' => array( 'chainId' => 8453, 'credentialTypes' => array( 'authorization' ), 'decimals' => self::USDC_DECIMALS ),
		);
		if ( $description ) {
			$request['description'] = $description;
		}
		$request_b64 = self::b64url( $this->jcs( $request ) );
		$expires     = gmdate( 'Y-m-d\TH:i:s\Z', time() + self::TIMEOUT_SECS );
		$id          = $this->challenge_id( $this->cfg['realm'], $request_b64, $expires );
		return sprintf( 'Payment id="%s", realm="%s", method="evm", intent="charge", expires="%s", request="%s"', $id, $this->cfg['realm'], $expires, $request_b64 );
	}

	/** @return array|null {problem, detail} on failure, null when valid. */
	private function validate_mpp( $cred, $resource_url ) {
		if ( ! is_array( $cred ) || empty( $cred['challenge'] ) || ! is_array( $cred['challenge'] ) || empty( $cred['payload'] ) || ! is_array( $cred['payload'] ) ) {
			return array( 'problem' => 'malformed-credential', 'detail' => 'Credential must contain challenge and payload objects.' );
		}
		$ch = $cred['challenge'];
		if ( ( $ch['realm'] ?? '' ) !== $this->cfg['realm'] || ( $ch['method'] ?? '' ) !== 'evm' || ( $ch['intent'] ?? '' ) !== 'charge' || empty( $ch['id'] ) || empty( $ch['request'] ) || empty( $ch['expires'] ) ) {
			return array( 'problem' => 'invalid-challenge', 'detail' => 'Challenge fields missing or not issued by this realm.' );
		}
		if ( strtotime( $ch['expires'] ) < time() ) {
			return array( 'problem' => 'payment-expired', 'detail' => 'Challenge has expired; retry with a fresh challenge.' );
		}
		$expected = $this->challenge_id( $ch['realm'], $ch['request'], $ch['expires'], $ch['digest'] ?? '', $ch['opaque'] ?? '' );
		if ( ! hash_equals( $expected, (string) $ch['id'] ) ) {
			return array( 'problem' => 'invalid-challenge', 'detail' => 'Challenge id does not match its parameters.' );
		}
		$request = self::b64url_json( $ch['request'] );
		if ( ! is_array( $request ) || ( $request['amount'] ?? '' ) !== self::usd_to_units( $this->cfg['price_usd'] )
			|| strtolower( $request['recipient'] ?? '' ) !== strtolower( $this->cfg['pay_to'] )
			|| strtolower( $request['currency'] ?? '' ) !== strtolower( self::USDC ) ) {
			return array( 'problem' => 'invalid-challenge', 'detail' => 'Challenge request does not match this resource\'s payment terms.' );
		}
		$pl = $cred['payload'];
		if ( ( $pl['type'] ?? '' ) !== 'authorization' ) {
			return array( 'problem' => 'verification-failed', 'detail' => 'Only credentialTypes ["authorization"] (EIP-3009) is accepted.' );
		}
		foreach ( array( 'from', 'to', 'value', 'validAfter', 'validBefore', 'nonce', 'signature' ) as $f ) {
			if ( ! isset( $pl[ $f ] ) || ! is_string( $pl[ $f ] ) || '' === $pl[ $f ] ) {
				return array( 'problem' => 'verification-failed', 'detail' => "Payload field $f is required." );
			}
		}
		$nonce = Keccak::hex( $ch['id'] . $ch['realm'] );
		if ( strtolower( $pl['nonce'] ) !== $nonce ) {
			return array( 'problem' => 'verification-failed', 'detail' => 'Payload nonce must equal keccak256(challenge.id || challenge.realm).' );
		}
		return null;
	}

	public function mpp_receipt( $tx ) {
		return self::b64url( json_encode( array( 'status' => 'success', 'method' => 'evm', 'timestamp' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'reference' => $tx ) ) );
	}
}
