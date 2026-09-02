#!/usr/bin/env bash
# E2E full patrol flow via real HTTP:
#   admin buat jadwal "sekarang" → budi start → scan (urutan/GPS/idempotent) → complete → verifikasi
set -u
BASE="http://127.0.0.1:8000/api/v1"
PHP=/home/node/.openclaw/workspace/tools/bin/php
J='Content-Type: application/json'
A='Accept: application/json'
pass=0; fail=0

uuid() { $PHP -r 'echo (function(){ $d=random_bytes(16); $d[6]=chr((ord($d[6])&0x0f)|0x40); $d[8]=chr((ord($d[8])&0x3f)|0x80); $h=bin2hex($d); return substr($h,0,8)."-".substr($h,8,4)."-".substr($h,12,4)."-".substr($h,16,4)."-".substr($h,20); })();'; }

req() {
  local method="$1" path="$2" token="${3:-}" body="${4:-}"
  local args=(-s --max-time 20 -X "$method" "$BASE$path" -H "$A")
  [ -n "$body" ] && args+=(-H "$J" -d "$body")
  [ -n "$token" ] && args+=(-H "Authorization: Bearer $token")
  curl "${args[@]}"
}
field() { echo "$1" | $PHP -r '$d=json_decode(stream_get_contents(STDIN),true); $k=$argv[1]; foreach(explode(".",$k) as $p){ if(!is_array($d)){echo ""; exit;} $d=$d[$p]??null; } echo is_scalar($d)||$d===null ? (string)$d : json_encode($d);' "$2"; }
check() {
  local label="$1" expected="$2" got="$3"
  if echo "$got" | grep -q "$expected"; then echo "PASS: $label"; pass=$((pass+1));
  else echo "FAIL: $label (cari '$expected')"; echo "  → $(echo "$got" | head -c 300)"; fail=$((fail+1)); fi
}

NOW_HM=$($PHP -r 'echo gmdate("H:i", time()-3600);')
END_HM=$($PHP -r 'echo gmdate("H:i", time()+3600);')
DOW=$($PHP -r 'echo (int)gmdate("w");')

echo "=== A. login admin ==="
R=$(req POST /auth/login "" '{"username":"admin","password":"password"}')
ADMIN=$(field "$R" data.token)
[ -n "$ADMIN" ] && echo "PASS: admin token" && pass=$((pass+1)) || { echo "FAIL: admin token kosong → $R"; fail=$((fail+1)); }

echo "=== B. id budi + route Mawar ==="
R=$(req GET /admin/users "$ADMIN")
BUDI_ID=$(echo "$R" | $PHP -r '$d=json_decode(stream_get_contents(STDIN),true); foreach(($d["data"]??[]) as $u){ if(($u["username"]??"")==="budi"){echo $u["id"]; break;}}')
R=$(req GET /admin/routes "$ADMIN")
ROUTE_ID=$(echo "$R" | $PHP -r '$d=json_decode(stream_get_contents(STDIN),true); foreach(($d["data"]??[]) as $r){ if(str_contains($r["name"]??"","Mawar")){echo $r["id"]; break;}}')
echo "   budi_id=${BUDI_ID:-?} route_id=${ROUTE_ID:-?}"

echo "=== C. buat jadwal E2E (window sekarang) ==="
BODY="{\"route_id\":$ROUTE_ID,\"name\":\"E2E Window $(date +%s)\",\"day_of_week\":$DOW,\"start_time\":\"$NOW_HM\",\"end_time\":\"$END_HM\",\"grace_before_minutes\":45,\"grace_after_minutes\":45,\"status\":\"ACTIVE\",\"user_ids\":[$BUDI_ID]}"
R=$(req POST /admin/schedules "$ADMIN" "$BODY")
SCHED_ID=$(field "$R" data.id)
check "jadwal dibuat" '"success":true' "$R"

echo "=== D. login budi ==="
R=$(req POST /auth/login "" '{"username":"budi","password":"password","device_uuid":"DEV-E2E-0001","device_name":"E2E Pixel","app_version":"1.0.0"}')
BUDI=$(field "$R" data.token)
[ -n "$BUDI" ] && echo "PASS: budi token" && pass=$((pass+1)) || { echo "FAIL: budi token → $R"; fail=$((fail+1)); }

echo "=== D2. bersihkan session RUNNING sisa (kalau ada) ==="
R=$(req GET /patrol/current "$BUDI")
OLD_SCODE=$(field "$R" data.session.session_code)
if [ -n "$OLD_SCODE" ]; then
  R=$(req POST /patrol/cancel "$BUDI" "{\"session_code\":\"$OLD_SCODE\",\"reason\":\"e2e cleanup\"}")
  echo "   cancel sisa ${OLD_SCODE}: $(echo "$R" | head -c 120)"
fi

echo "=== E. schedules/today memuat jadwal baru ==="
R=$(req GET /patrol/schedules/today "$BUDI")
check "jadwal hari ini" "$SCHED_ID" "$R"

echo "=== F. start patrol (titik dekat CP001) ==="
R=$(req POST /patrol/start "$BUDI" "{\"schedule_id\":$SCHED_ID,\"latitude\":-6.26000000,\"longitude\":106.79000000,\"device_uuid\":\"DEV-E2E-0001\"}")
check "patrol dimulai" '"success":true' "$R"
SCODE=$(field "$R" data.session_code); echo "   session=${SCODE:-?}"

echo "=== G. current RUNNING ==="
R=$(req GET /patrol/current "$BUDI")
check "current RUNNING" 'RUNNING' "$R"

U1=$(uuid); U2=$(uuid); U3=$(uuid)
echo "=== H. scan CP002 duluan → INVALID_SEQUENCE ==="
R=$(req POST /patrol/checkpoint/scan "$BUDI" "{\"session_code\":\"$SCODE\",\"scan_code\":\"CP002\",\"uuid\":\"$U1\",\"latitude\":-6.26040000,\"longitude\":106.79040000,\"gps_accuracy\":5,\"device_timestamp\":\"2026-09-02 10:00:00\",\"device_uuid\":\"DEV-E2E-0001\"}")
check "urutan salah ditolak" 'INVALID_SEQUENCE' "$R"

echo "=== I. scan CP001 GPS jauh → INVALID_LOCATION ==="
R=$(req POST /patrol/checkpoint/scan "$BUDI" "{\"session_code\":\"$SCODE\",\"scan_code\":\"CP001\",\"uuid\":\"$U2\",\"latitude\":-6.26120000,\"longitude\":106.79120000,\"gps_accuracy\":5,\"device_timestamp\":\"2026-09-02 10:00:05\",\"device_uuid\":\"DEV-E2E-0001\"}")
check "gps jauh ditolak" 'INVALID_LOCATION' "$R"

echo "=== J. scan CP001 valid ==="
R=$(req POST /patrol/checkpoint/scan "$BUDI" "{\"session_code\":\"$SCODE\",\"scan_code\":\"CP001\",\"uuid\":\"$U3\",\"latitude\":-6.26000100,\"longitude\":106.79000100,\"gps_accuracy\":5,\"device_timestamp\":\"2026-09-02 10:00:10\",\"device_uuid\":\"DEV-E2E-0001\"}")
check "CP001 valid" '"VALID"' "$R"

echo "=== K. UUID sama dikirim ulang → ALREADY_PROCESSED ==="
R=$(req POST /patrol/checkpoint/scan "$BUDI" "{\"session_code\":\"$SCODE\",\"scan_code\":\"CP001\",\"uuid\":\"$U3\",\"latitude\":-6.26000100,\"longitude\":106.79000100,\"gps_accuracy\":5,\"device_timestamp\":\"2026-09-02 10:00:11\",\"device_uuid\":\"DEV-E2E-0001\"}")
check "idempotent" 'ALREADY_PROCESSED' "$R"

echo "=== L. scan CP002..CP005 berurutan (valid) ==="
declare -A CPS=( [CP002]="-6.26040000 106.79040000" [CP003]="-6.26080000 106.79020000" [CP004]="-6.26120000 106.79060000" [CP005]="-6.26160000 106.79090000" )
for cp in CP002 CP003 CP004 CP005; do
  read -r la lo <<< "${CPS[$cp]}"
  UX=$(uuid)
  R=$(req POST /patrol/checkpoint/scan "$BUDI" "{\"session_code\":\"$SCODE\",\"scan_code\":\"$cp\",\"uuid\":\"$UX\",\"latitude\":$la,\"longitude\":$lo,\"gps_accuracy\":5,\"device_timestamp\":\"2026-09-02 10:05:00\",\"device_uuid\":\"DEV-E2E-0001\"}")
  if echo "$R" | grep -q '"VALID"'; then echo "PASS: $cp valid"; pass=$((pass+1)); else echo "FAIL: $cp → $(echo "$R" | head -c 250)"; fail=$((fail+1)); fi
done

echo "=== M. progress 5/5 ==="
R=$(req GET /patrol/current "$BUDI")
check "completed 5" '"completed_checkpoint":5' "$R"

echo "=== N. complete patrol ==="
R=$(req POST /patrol/complete "$BUDI" "{\"session_code\":\"$SCODE\",\"latitude\":-6.26160000,\"longitude\":106.79090000}")
check "patrol COMPLETED" 'COMPLETED' "$R"

echo "=== O. history budi memuat session ==="
R=$(req GET /patrol/history "$BUDI")
check "history" "$SCODE" "$R"

echo "=== P. admin: sessions + dashboard + audit ==="
R=$(req GET /sessions "$ADMIN")
check "admin sessions" "$SCODE" "$R"
R=$(req GET /dashboard/stats "$ADMIN")
check "dashboard stats" '"success":true' "$R"
R=$(req GET /admin/audit-logs "$ADMIN")
check "audit logs" '"success":true' "$R"

echo
echo "======================================"
echo "HASIL E2E: $pass PASS, $fail FAIL"
exit $fail
