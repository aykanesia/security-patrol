#!/usr/bin/env bash
# Smoke test end-to-end Security Patrol API via HTTP nyata
set -u
BASE="http://127.0.0.1:8000/api/v1"
PHP=/home/node/.openclaw/workspace/tools/bin/php
J='Content-Type: application/json'
A='Accept: application/json'
pass=0; fail=0

req() { # req METHOD PATH TOKEN BODY
  local method="$1" path="$2" token="${3:-}" body="${4:-}"
  local args=(-s --max-time 15 -X "$method" "$BASE$path" -H "$A")
  [ -n "$body" ] && args+=(-H "$J" -d "$body")
  [ -n "$token" ] && args+=(-H "Authorization: Bearer $token")
  curl "${args[@]}"
}

check() {
  local label="$1" expected="$2" got="$3"
  if echo "$got" | grep -q "$expected"; then
    echo "PASS: $label"; pass=$((pass+1))
  else
    echo "FAIL: $label (cari '$expected')"; echo "  → $(echo "$got" | head -c 300)"; fail=$((fail+1))
  fi
}

echo "=== 1. login admin ==="
R=$(req POST /auth/login "" '{"username":"admin","password":"password"}')
check "login admin sukses" '"success":true' "$R"
ADMIN_TOKEN=$(echo "$R" | $PHP -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["data"]["token"] ?? "";')
echo "   token: ${ADMIN_TOKEN:0:16}..."

echo "=== 2. login budi (security) ==="
R=$(req POST /auth/login "" '{"username":"budi","password":"password","device_uuid":"DEV-TEST-001","device_name":"Pixel Test","app_version":"1.0.0"}')
check "login budi sukses" '"success":true' "$R"
BUDI_TOKEN=$(echo "$R" | $PHP -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["data"]["token"] ?? "";')
echo "   token: ${BUDI_TOKEN:0:16}..."

echo "=== 3. GET /me (admin) ==="
R=$(req GET /me "$ADMIN_TOKEN")
check "me admin" 'super_admin' "$R"

echo "=== 4. GET /dashboard/stats (admin) ==="
R=$(req GET /dashboard/stats "$ADMIN_TOKEN")
check "dashboard stats" '"success":true' "$R"

echo "=== 5. coba akses dashboard sbg security (harus 403) ==="
R=$(req GET /dashboard/stats "$BUDI_TOKEN")
check "forbidden utk security" 'FORBIDDEN' "$R"
# verifikasi HTTP status code-nya beneran 403
CODE=$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 "$BASE/dashboard/stats" -H "$A" -H "Authorization: Bearer $BUDI_TOKEN")
if [ "$CODE" = "403" ]; then echo "PASS: HTTP status 403"; pass=$((pass+1)); else echo "FAIL: HTTP status (dapat $CODE)"; fail=$((fail+1)); fi

echo "=== 6. GET /patrol/schedules/today (budi) ==="
R=$(req GET /patrol/schedules/today "$BUDI_TOKEN")
check "jadwal hari ini" '"success":true' "$R"
echo "   $(echo "$R" | head -c 300)"

echo "=== 7. unauth tanpa token → 401 JSON ==="
R=$(req GET /me)
check "unauth 401" 'UNAUTHENTICATED' "$R"

echo
echo "======================================"
echo "HASIL: $pass PASS, $fail FAIL"
exit $fail
