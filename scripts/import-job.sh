#!/usr/bin/env bash
# One attempt of the daily import on this GitHub runner (daily-import.yml).
# Logs the runner's public IPv4 (a failed lookup changes nothing), then runs
# the import loop. If the server could not be reached at all from this runner
# (import-loop.php exit 75), the attempt is not a failure yet: it sets the
# step output retry=true so the workflow continues on a new runner - unless
# this is the last attempt (LAST_ATTEMPT=true). Any other error fails as before.
#
#   IMPORT_TOKEN=... SITE_URL=https://... [LAST_ATTEMPT=true] bash scripts/import-job.sh
set -u

test -n "${IMPORT_TOKEN:-}" || { echo "::error::Secret IMPORT_TOKEN is missing"; exit 1; }

# Only print something that looks like an IPv4 address.
ip=$(curl -4 -s -m 5 https://api.ipify.org 2>/dev/null || true)
[[ "$ip" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]] || ip=unknown
echo "Runner public IPv4: $ip"

php "$(dirname "$0")/import-loop.php" "$SITE_URL"
code=$?
if [ "$code" -eq 75 ]; then
  if [ "${LAST_ATTEMPT:-false}" = true ]; then
    echo "::error::Could not reach the server from this runner ($ip) either - giving up"
    exit 1
  fi
  echo "::warning::Could not reach the server from this runner ($ip) - continuing on a new runner"
  echo "retry=true" >> "${GITHUB_OUTPUT:-/dev/null}"
  exit 0
fi
exit "$code"
