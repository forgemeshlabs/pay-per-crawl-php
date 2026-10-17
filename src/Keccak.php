<?php
declare(strict_types=1);

namespace ForgeMesh\PayPerCrawl;

/**
 * Keccak-256 (the Ethereum variant, pad 0x01), pure PHP, 64-bit lanes.
 * Needed only to check the MPP nonce binding keccak256(id || realm).
 */
final class Keccak
{

	const RATE = 136; // 1088 bits for Keccak-256.

	/** @var int[] Round constants as 64-bit bit patterns (negative when the top bit is set). */
	private static $rc = null;

	private static $rotc = array( 1, 3, 6, 10, 15, 21, 28, 36, 45, 55, 2, 14, 27, 41, 56, 8, 25, 43, 62, 18, 39, 61, 20, 44 );
	private static $piln = array( 10, 7, 11, 17, 18, 3, 5, 16, 8, 21, 24, 4, 15, 23, 19, 13, 12, 2, 20, 14, 22, 9, 6, 1 );

	private static function rc() {
		if ( null === self::$rc ) {
			$hex = array(
				'0000000000000001', '0000000000008082', '800000000000808a', '8000000080008000',
				'000000000000808b', '0000000080000001', '8000000080008081', '8000000000008009',
				'000000000000008a', '0000000000000088', '0000000080008009', '000000008000000a',
				'000000008000808b', '800000000000008b', '8000000000008089', '8000000000008003',
				'8000000000008002', '8000000000000080', '000000000000800a', '800000008000000a',
				'8000000080008081', '8000000000008080', '0000000080000001', '8000000080008008',
			);
			self::$rc = array();
			foreach ( $hex as $h ) {
				self::$rc[] = unpack( 'J', hex2bin( $h ) )[1];
			}
		}
		return self::$rc;
	}

	private static function rotl( $x, $n ) {
		$n &= 63;
		if ( 0 === $n ) {
			return $x;
		}
		$right = ( $x >> ( 64 - $n ) ) & ( ( 1 << $n ) - 1 ); // logical shift.
		return ( $x << $n ) | $right;
	}

	private static function f( array &$st ) {
		$rc = self::rc();
		for ( $round = 0; $round < 24; $round++ ) {
			// Theta.
			$bc = array();
			for ( $i = 0; $i < 5; $i++ ) {
				$bc[ $i ] = $st[ $i ] ^ $st[ $i + 5 ] ^ $st[ $i + 10 ] ^ $st[ $i + 15 ] ^ $st[ $i + 20 ];
			}
			for ( $i = 0; $i < 5; $i++ ) {
				$t = $bc[ ( $i + 4 ) % 5 ] ^ self::rotl( $bc[ ( $i + 1 ) % 5 ], 1 );
				for ( $j = 0; $j < 25; $j += 5 ) {
					$st[ $j + $i ] ^= $t;
				}
			}
			// Rho + Pi.
			$t = $st[1];
			for ( $i = 0; $i < 24; $i++ ) {
				$j        = self::$piln[ $i ];
				$bc0      = $st[ $j ];
				$st[ $j ] = self::rotl( $t, self::$rotc[ $i ] );
				$t        = $bc0;
			}
			// Chi.
			for ( $j = 0; $j < 25; $j += 5 ) {
				for ( $i = 0; $i < 5; $i++ ) {
					$bc[ $i ] = $st[ $j + $i ];
				}
				for ( $i = 0; $i < 5; $i++ ) {
					$st[ $j + $i ] ^= ( ~$bc[ ( $i + 1 ) % 5 ] ) & $bc[ ( $i + 2 ) % 5 ];
				}
			}
			// Iota.
			$st[0] ^= $rc[ $round ];
		}
	}

	/**
	 * @param string $msg Raw bytes.
	 * @return string 32 raw bytes.
	 */
	public static function hash( $msg ) {
		$st  = array_fill( 0, 25, 0 );
		$msg .= "\x01";
		$pad = ( self::RATE - ( strlen( $msg ) % self::RATE ) ) % self::RATE;
		$msg .= str_repeat( "\x00", $pad );
		$msg[ strlen( $msg ) - 1 ] = chr( ord( $msg[ strlen( $msg ) - 1 ] ) | 0x80 );
		$blocks = strlen( $msg ) / self::RATE;
		for ( $b = 0; $b < $blocks; $b++ ) {
			$chunk = substr( $msg, $b * self::RATE, self::RATE );
			$lanes = array_values( unpack( 'P17', $chunk ) );
			for ( $i = 0; $i < 17; $i++ ) {
				$st[ $i ] ^= $lanes[ $i ];
			}
			self::f( $st );
		}
		$out = '';
		for ( $i = 0; $i < 4; $i++ ) {
			$out .= pack( 'P', $st[ $i ] );
		}
		return $out;
	}

	/** @return string 0x-prefixed lowercase hex. */
	public static function hex( $msg ) {
		return '0x' . bin2hex( self::hash( $msg ) );
	}
}
