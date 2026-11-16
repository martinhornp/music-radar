#!/usr/bin/env bash
# Verifies that a deployed Martins Music Radar site works.
#
#   scripts/smoke-test.sh https://musik.hornpedersen.dk [path/to/built/index.html]
#
# If the built index.html is given, the test also checks that the live site
# serves *this* build (same JS bundle hash) - which proves the upload landed
# in the directory the domain actually serves. Retries to ride out caching.
#
# If the site cannot be reached at all (DNS, connect timeout, TLS ...), the
# test stops after a few quick attempts and says so, instead of reporting
# every individual check as failed.
#
# Exit codes: 0 passed, 1 the site answered but something is wrong,
# 75 no HTTP response at all from here - says nothing about the site itself
# (some GitHub runner addresses get no answer from the host; see smoke-job.sh).
#
# Env: SMOKE_ATTEMPTS (10), SMOKE_DELAY (15 s), SMOKE_CONNECT_ATTEMPTS (3),
#      SMOKE_CONNECT_TIMEOUT (5 s), SMOKE_MAX_TIME (15 s per request)
set -uo pipefail

BASE_URL="${1:?usage: smoke-test.sh <base-url> [built-index.html]}"
BASE_URL="${BASE_URL%/}"
EXPECTED_INDEX="${2:-}"
ATTEMPTS="${SMOKE_ATTEMPTS:-10}"
DELAY="${SMOKE_DELAY:-15}"
CONNECT_ATTEMPTS="${SMOKE_CONNECT_ATTEMPTS:-3}"
CONNECT_TIMEOUT="${SMOKE_CONNECT_TIMEOUT:-5}"
MAX_TIME="${SMOKE_MAX_TIME:-15}"

failures=()

fetch() { # url [extra curl args] -> sets $code, $body, $headers, $curl_exit, $curl_error
  local tmp hdr err url="$1"
  shift
  tmp="$(mktemp)"
  hdr="$(mktemp)"
  err="$(mktemp)"
  code="$(curl -sS -L --connect-timeout "$CONNECT_TIMEOUT" -m "$MAX_TIME" \
    -o "$tmp" -D "$hdr" -w '%{http_code}' -H 'Cache-Control: no-cache' "$@" "$url" 2>"$err")"
  curl_exit=$?
  curl_error="$(head -1 "$err")"
  [[ "$code" =~ ^[0-9]{3}$ ]] || code=000
  body="$(cat "$tmp")"
  headers="$(cat "$hdr")"
  rm -f "$tmp" "$hdr" "$err"
}

fetch_post() { # url json-body -> like fetch
  fetch "$1" -X POST -H 'Content-Type: application/json' --data "$2"
}

# Records a failure for a request that did not get an HTTP response.
# Returns 0 if the request failed at connection level.
no_response() { # label
  if ((curl_exit != 0)) && [[ "$code" == 000 ]]; then
    failures+=("$1: no HTTP response ($curl_error)")
    return 0
  fi
  return 1
}

expect_bundle=""
if [[ -n "$EXPECTED_INDEX" ]]; then
  expect_bundle="$(grep -oE '/assets/index-[A-Za-z0-9_-]+\.js' "$EXPECTED_INDEX" | head -1)"
  [[ -n "$expect_bundle" ]] || { echo "Could not find JS bundle in $EXPECTED_INDEX" >&2; exit 2; }
fi

unreachable=false

run_checks() {
  failures=()
  unreachable=false
  local bust="t=$(date +%s)"

  # Reachability first: if the front page gets no HTTP response at all,
  # every other check would fail the same way - stop here.
  fetch "$BASE_URL/?$bust"
  if no_response "GET /"; then
    unreachable=true
    return 1
  fi

  [[ "$code" == 200 ]] || failures+=("GET / returned HTTP $code (expected 200)")
  [[ "$body" == *'<title>Martins Music Radar</title>'* ]] || failures+=("GET / is not the Martins Music Radar app")
  if [[ -n "$expect_bundle" && "$body" != *"$expect_bundle"* ]]; then
    failures+=("GET / does not reference this build's bundle $expect_bundle (old version or wrong directory?)")
  fi

  local bundle
  bundle="$(grep -oE '/assets/index-[A-Za-z0-9_-]+\.js' <<<"$body" | head -1)"
  if [[ -n "$bundle" ]]; then
    fetch "$BASE_URL$bundle"
    no_response "GET $bundle" || [[ "$code" == 200 ]] || failures+=("GET $bundle returned HTTP $code (expected 200)")
  fi

  fetch "$BASE_URL/radar/some/route?$bust"
  no_response "SPA fallback route" ||
    [[ "$code" == 200 && "$body" == *'<title>Martins Music Radar</title>'* ]] ||
    failures+=("SPA fallback route returned HTTP $code (expected the app with 200)")

  fetch "$BASE_URL/api/health?$bust"
  if ! no_response "GET /api/health"; then
    [[ "$code" == 200 ]] || failures+=("GET /api/health returned HTTP $code (expected 200)")
    [[ "$body" == '{"status":"ok"}' ]] || failures+=("GET /api/health should be exactly {\"status\":\"ok\"}, got: ${body:0:200}")
    if grep -qi '^x-powered-by:' <<<"$headers"; then
      failures+=("INFO LEAK: /api/health sends $(grep -i '^x-powered-by:' <<<"$headers" | head -1 | tr -d '\r')")
    fi
  fi

  fetch "$BASE_URL/api/recommendations?$bust"
  no_response "GET /api/recommendations" ||
    [[ "$code" == 200 && "$body" == *'"releases":['* ]] ||
    failures+=("GET /api/recommendations returned HTTP $code (expected the recommendations JSON)")

  # Stored feedback must be readable (database configured on the server);
  # an invalid write must be refused before it reaches the database.
  fetch "$BASE_URL/api/feedback?$bust"
  no_response "GET /api/feedback" ||
    [[ "$code" == 200 && "$body" == *'"items":['* ]] ||
    failures+=("GET /api/feedback returned HTTP $code (expected the stored feedback - is the database configured?)")
  fetch_post "$BASE_URL/api/feedback/not-a-release-id" '{"rating":5}'
  no_response "POST /api/feedback (invalid)" ||
    [[ "$code" == 400 ]] ||
    failures+=("POST /api/feedback with an invalid id returned HTTP $code (expected 400)")

  # The import endpoints must not exist for anyone without the token.
  fetch_post "$BASE_URL/api/internal/import" '{}'
  no_response "POST /api/internal/import (no token)" ||
    [[ "$code" == 404 ]] ||
    failures+=("SECURITY: /api/internal/import without token returned HTTP $code (expected 404)")

  fetch "$BASE_URL/api/does-not-exist?$bust"
  no_response "Unknown API route" ||
    [[ "$code" == 404 && "$body" == *'"error"'* ]] ||
    failures+=("Unknown API route returned HTTP $code (expected JSON 404)")

  # Sensitive files must never be reachable. Only an actual HTTP response
  # tells us anything; a connection error is reported as such, not as exposure.
  local path
  for path in /api/config/config.php /api/config/config.local.php /api/bootstrap.php /api/src/Http/Router.php /api/data/feed.json /api/bin/export-feed.php /api/bin/db-schema.php; do
    fetch "$BASE_URL$path"
    no_response "GET $path (could not verify protection)" && continue
    case "$code" in
      403|404) ;;
      2*) failures+=("SECURITY: $path is publicly readable (HTTP $code)") ;;
      *)  failures+=("$path returned HTTP $code (expected 403/404)") ;;
    esac
  done

  [[ ${#failures[@]} -eq 0 ]]
}

connect_failures=0
for ((i = 1; i <= ATTEMPTS; i++)); do
  if run_checks; then
    echo "Smoke test passed for $BASE_URL (attempt $i)."
    exit 0
  fi

  echo "Attempt $i/$ATTEMPTS failed:"
  printf '  - %s\n' "${failures[@]}"

  if $unreachable; then
    ((connect_failures++))
    if ((connect_failures >= CONNECT_ATTEMPTS)); then
      echo "Smoke test INCONCLUSIVE: $BASE_URL could not be reached from here in $connect_failures attempts (no HTTP response)." >&2
      exit 75
    fi
    ((i < ATTEMPTS)) && sleep 5
  else
    ((i < ATTEMPTS)) && sleep "$DELAY"
  fi
done

echo "Smoke test FAILED for $BASE_URL." >&2
exit 1
