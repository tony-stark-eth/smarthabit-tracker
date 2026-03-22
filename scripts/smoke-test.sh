#!/usr/bin/env bash
set -euo pipefail

# Smoke test — verify the app is running correctly
# Usage: ./scripts/smoke-test.sh [base-url]

BASE_URL="${1:-https://localhost}"
ERRORS=0

check() {
    local name="$1" url="$2" expected_status="${3:-200}"
    local status
    status=$(curl -sk -o /dev/null -w '%{http_code}' "$url")
    if [ "$status" = "$expected_status" ]; then
        echo "  ✓ $name ($status)"
    else
        echo "  ✗ $name (expected $expected_status, got $status)"
        ERRORS=$((ERRORS + 1))
    fi
}

echo "Smoke testing $BASE_URL ..."
echo

check "Health endpoint" "$BASE_URL/api/v1/health"
check "Privacy endpoint" "$BASE_URL/api/v1/privacy"
check "VAPID key endpoint" "$BASE_URL/api/v1/vapid-key"
check "Frontend loads" "$BASE_URL/"
check "Manifest" "$BASE_URL/manifest.json"

echo
if [ "$ERRORS" -eq 0 ]; then
    echo "All checks passed ✓"
else
    echo "$ERRORS check(s) failed ✗"
    exit 1
fi
