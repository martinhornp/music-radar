#!/usr/bin/env bash
# Smoke test after a deployment, as one GitHub Actions attempt.
#
# Three outcomes are kept apart:
#   - the site answers but something is wrong (smoke-test.sh exit 1): fails
#     at once - a real problem with the deployed site;
#   - the site cannot be reached at all from this runner (exit 75; some runner
#     addresses get no answer from the host): not evidence of a broken site.
#     Sets the step output retry=true so the workflow verifies from a new
#     runner - unless this is the last attempt (LAST_ATTEMPT=true): then the
#     deployment is reported as uploaded but NOT verified, and the job fails
#     (never a green "all OK" without a check);
#   - passed.
#
#   SITE_URL=https://... [LAST_ATTEMPT=true] bash scripts/smoke-job.sh deploy/index.html
set -uo pipefail

ip="$(curl -4 -s -m 5 https://api.ipify.org || echo unknown)"
echo "Runner public IPv4: $ip"
summary="${GITHUB_STEP_SUMMARY:-/dev/null}"

bash "$(dirname "$0")/smoke-test.sh" "$SITE_URL" "${1:-}"
code=$?

case "$code" in
  0)
    echo "### Smoke test passed (runner $ip)" >> "$summary"
    exit 0
    ;;
  75)
    if [ "${LAST_ATTEMPT:-false}" = true ]; then
      echo "::error::Deployment uploaded, but the live site could NOT be verified: no HTTP response from this runner ($ip) either. This is not evidence of a broken site - check it from elsewhere."
      echo "### Deployment uploaded - live site NOT verified (unreachable from 3 GitHub runners, last $ip)" >> "$summary"
      exit 1
    fi
    echo "::warning::Deployment uploaded, but the site could not be reached from this runner ($ip) - verifying from a new runner"
    echo "Site unreachable from runner $ip - verifying from a new runner" >> "$summary"
    echo "retry=true" >> "${GITHUB_OUTPUT:-/dev/null}"
    exit 0
    ;;
  *)
    echo "### Smoke test FAILED: the live site answered, but not as expected (runner $ip)" >> "$summary"
    exit "$code"
    ;;
esac
