#!/usr/bin/env bash
#
# Standalone health check script for deployment verification.
#
# Usage:
#   ./scripts/deploy/health-check.sh [URL] [TIMEOUT] [RETRIES]
#
# Arguments:
#   URL      Health endpoint URL (default: https://api.webhook-relay.dcsuniverse.com/v1/healthz)
#   TIMEOUT  Request timeout in seconds (default: 30)
#   RETRIES  Number of retry attempts (default: 3)
#
# Exit codes:
#   0  Application is healthy
#   1  Application is unhealthy or unreachable

set -euo pipefail

URL="${1:-https://api.webhook-relay.dcsuniverse.com/v1/healthz}"
TIMEOUT="${2:-30}"
RETRIES="${3:-3}"
DELAY=5

echo "Health check: ${URL}"
echo "Timeout: ${TIMEOUT}s | Retries: ${RETRIES} | Delay: ${DELAY}s"

for attempt in $(seq 1 "${RETRIES}"); do
    echo -n "Attempt ${attempt}/${RETRIES}... "

    response=$(curl -sf --max-time "${TIMEOUT}" "${URL}" 2>/dev/null || true)

    if echo "${response}" | grep -q '"healthy"'; then
        echo "HEALTHY"
        echo "${response}" | python3 -m json.tool 2>/dev/null || echo "${response}"
        exit 0
    fi

    echo "FAILED"

    if [ "${attempt}" -lt "${RETRIES}" ]; then
        echo "Retrying in ${DELAY}s..."
        sleep "${DELAY}"
    fi
done

echo ""
echo "ERROR: Health check failed after ${RETRIES} attempts."
echo "Verify manually: ${URL}"
exit 1
