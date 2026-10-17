#!/usr/bin/env bash
# HTTP integration matrix against a running app and a running mock facilitator.
#   BASE_URL   app under test, no trailing slash (e.g. http://127.0.0.1:8412)
#   PAGE       a content page that contains the marker ZZPAIDZZ (default /paid-test)
#   MOCK_LOG   file the mock facilitator logs to (must match the mock's env)
#   PAY_TO     the wallet the app is configured with
#   CORE_SRC   path to the core package's src/ (for the Keccak helper)
#   LLMS_MARK  text that must appear in /llms.txt
set -u
B=${BASE_URL:?BASE_URL required}; PAGE=${PAGE:-/paid-test}; MOCK_LOG=${MOCK_LOG:?MOCK_LOG required}
PAY_TO=${PAY_TO:-0x13C3C28c64cc9d60eEb8a675F3fe27eBC6a4Cd09}
CORE_SRC=${CORE_SRC:-$(cd "$(dirname "$0")/../../src" && pwd)}
LLMS_MARK=${LLMS_MARK:-Paid test post}
T=$(mktemp -d); trap 'rm -rf "$T"' EXIT
HOST=$(echo "$B" | sed -E 's#^https?://([^:/]+).*#\1#')
pass=0; fail=0
ok(){ if [ "$1" = "$2" ]; then pass=$((pass+1)); else fail=$((fail+1)); echo "FAIL: $3 (got '$1' want '$2')"; fi; }
code(){ curl -s -o "$T/body" -D "$T/h" -w '%{http_code}' "$@"; }
hdr(){ grep -i "^$1:" "$T/h" | head -1 | cut -d' ' -f2- | tr -d '\r'; }
UA_H='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36'
UA_G='Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)'
URL="$B$PAGE"

ok "$(code -A "$UA_H" -H 'Accept: text/html' $URL)" 200 "human gets page"
grep -q ZZPAIDZZ "$T/body"; ok $? 0 "human sees content"
ok "$(code -A "$UA_G" $URL)" 402 "gptbot gets 402"
grep -q ZZPAIDZZ "$T/body"; ok $? 1 "402 body has no content"
ok "$(hdr content-type)" "application/json; charset=utf-8" "402 content-type"
ok "$(hdr cache-control | grep -c no-store)" 1 "402 no-store"
PR=$(hdr payment-required)
ok "$(echo "$PR" | base64 -d | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["x402Version"],"|",$j["accepts"][0]["network"],"|",$j["accepts"][0]["amount"],"|",$j["accepts"][0]["payTo"],"|",$j["resource"]["url"];')" "2|eip155:8453|10000|$PAY_TO|$URL" "PAYMENT-REQUIRED v2 envelope"
ok "$(php -r '$j=json_decode(file_get_contents($argv[1]),true); echo $j["x402Version"],"|",$j["accepts"][0]["network"],"|",$j["accepts"][0]["maxAmountRequired"],"|",$j["accepts"][0]["resource"];' "$T/body")" "1|base|10000|$URL" "v1 body"
WA=$(hdr www-authenticate); ok "$(echo "$WA" | grep -c "Payment id=\".*realm=\"$HOST\".*method=\"evm\".*intent=\"charge\".*request=\"")" 1 "MPP WWW-Authenticate"
ok "$(hdr link | grep -c 'x402.json.*describedby')" 1 "402 Link describedby"
ok "$(code -I -A "$UA_G" $URL)" 402 "HEAD gets 402"
ok "$(code -A 'curl/8.5' $B/)" 402 "curl on home gets 402"
ok "$(code -A 'curl/8.5' "$URL?x=1&y=2")" 402 "curl on query url gets 402"
ok "$(php -r '$j=json_decode(file_get_contents($argv[1]),true); echo $j["accepts"][0]["resource"];' "$T/body")" "$URL?x=1&y=2" "query string kept in resource"
ok "$(code -A 'facebookexternalhit/1.1' $URL)" 200 "social preview free"
ok "$(code -A 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' $URL)" 402 "fake googlebot from loopback is demoted and charged"
ok "$(code -A "$UA_G" $B/.well-known/x402.json)" 200 "manifest free"
ok "$(php -r '$j=json_decode(file_get_contents($argv[1]),true); echo $j["x402Version"],"|",$j["resources"][0]["accepts"][0]["payTo"],"|",$j["resources"][0]["metadata"]["paid"]?"paid":"free";' "$T/body")" "2|$PAY_TO|paid" "manifest content"
ok "$(hdr access-control-allow-origin)" "*" "manifest CORS"
ok "$(code -A "$UA_G" $B/llms.txt)" 200 "llms free"; grep -q "$LLMS_MARK" "$T/body"; ok $? 0 "llms lists a page"
ok "$(code -A "$UA_G" $B/agents.txt)" 200 "agents free"; grep -q "Payments: required" "$T/body"; ok $? 0 "agents.txt payments required"

# Payments through the mock facilitator (the mock does not check signatures; the real one does).
mk_auth(){ php -r 'echo json_encode(["from"=>"0x".str_repeat("b",40),"to"=>$argv[2],"value"=>$argv[1],"validAfter"=>"0","validBefore"=>(string)(time()+300),"nonce"=>"0x".bin2hex(random_bytes(32))]);' "$1" "${2:-$PAY_TO}"; }
V2=$(php -r '$a=json_decode($argv[1],true); echo base64_encode(json_encode(["x402Version"=>2,"resource"=>["url"=>$argv[2]],"accepted"=>["scheme"=>"exact","network"=>"eip155:8453","amount"=>"10000","asset"=>"0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913","payTo"=>$argv[3],"maxTimeoutSeconds"=>300,"extra"=>["name"=>"USD Coin","version"=>"2"]],"payload"=>["signature"=>"0xsig","authorization"=>$a]]));' "$(mk_auth 10000)" "$URL" "$PAY_TO")
ok "$(code -A "$UA_G" -H "PAYMENT-SIGNATURE: $V2" $URL)" 200 "v2 paid gets 200"
grep -q ZZPAIDZZ "$T/body"; ok $? 0 "v2 paid sees content"
ok "$(hdr payment-response | base64 -d | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["success"]?"ok":"no","|",substr($j["transaction"],0,6);')" "ok|0xabab" "PAYMENT-RESPONSE receipt"
ok "$(hdr x-payment-response | wc -c | tr -d ' ' | awk '{print ($1>10)?"yes":"no"}')" yes "X-PAYMENT-RESPONSE mirrored"
ok "$(hdr cache-control | grep -c no-store)" 1 "paid response is no-store"
ok "$(grep -c '"path":"\\/verify"' "$MOCK_LOG")" 1 "verify called once"; ok "$(grep -c '"path":"\\/settle"' "$MOCK_LOG")" 1 "settle called once"
ok "$(code -A "$UA_G" -H "PAYMENT-SIGNATURE: $V2" $URL)" 402 "replayed nonce rejected"
grep -q payment_in_flight "$T/body"; ok $? 0 "replay reason in body"
ok "$(grep -c '"path":"\\/verify"' "$MOCK_LOG")" 1 "replay never reached the facilitator"
V1=$(php -r '$a=json_decode($argv[1],true); echo base64_encode(json_encode(["x402Version"=>1,"scheme"=>"exact","network"=>"base","payload"=>["signature"=>"0xsig","authorization"=>$a]]));' "$(mk_auth 10000)")
ok "$(code -A 'x402-fetch/1.2.0' -H "X-PAYMENT: $V1" $URL)" 200 "v1 paid gets 200"
ok "$(grep '"path":"\\/settle"' "$MOCK_LOG" | tail -1 | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["body"]["x402Version"],"|",$j["body"]["paymentRequirements"]["network"];')" "1|base" "v1 forwarded as v1 to facilitator"
LOW=$(php -r '$a=json_decode($argv[1],true); echo base64_encode(json_encode(["x402Version"=>1,"scheme"=>"exact","network"=>"base","payload"=>["signature"=>"0xsig","authorization"=>$a]]));' "$(mk_auth 5000)")
ok "$(code -A 'x402-fetch/1.2.0' -H "X-PAYMENT: $LOW" $URL)" 402 "underpayment rejected"
grep -q wrong_amount "$T/body"; ok $? 0 "underpayment reason"
ok "$(grep -c '"path":"\\/verify"' "$MOCK_LOG")" 2 "underpayment never reached the facilitator"
# MPP credential built from a fresh challenge
code -A "$UA_G" $URL >/dev/null; WA=$(hdr www-authenticate)
CRED=$(php -r '
require $argv[2] . "/Keccak.php";
preg_match("/id=\"([^\"]+)\".*expires=\"([^\"]+)\".*request=\"([^\"]+)\"/", $argv[1], $m);
$id=$m[1]; $realm=$argv[3]; $nonce=\ForgeMesh\PayPerCrawl\Keccak::hex($id.$realm);
$c=["challenge"=>["id"=>$id,"realm"=>$realm,"method"=>"evm","intent"=>"charge","expires"=>$m[2],"request"=>$m[3]],"payload"=>["type"=>"authorization","from"=>"0x".str_repeat("d",40),"to"=>$argv[4],"value"=>"10000","validAfter"=>"0","validBefore"=>(string)(time()+300),"nonce"=>$nonce,"signature"=>"0xsig"]];
echo rtrim(strtr(base64_encode(json_encode($c)),"+/","-_"),"=");' "$WA" "$CORE_SRC" "$HOST" "$PAY_TO")
ok "$(code -A 'mpp-client/1' -H "Authorization: Payment $CRED" $URL)" 200 "MPP paid gets 200"
ok "$(hdr payment-receipt | wc -c | tr -d ' ' | awk '{print ($1>10)?"yes":"no"}')" yes "Payment-Receipt header"
ok "$(code -A 'mpp-client/1' -H "Authorization: Payment $CRED" $URL)" 402 "MPP replay rejected"
ok "$(hdr content-type)" "application/problem+json; charset=utf-8" "MPP problem+json"
echo "matrix pass=$pass fail=$fail"
exit $fail
