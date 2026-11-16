#!/usr/bin/env bash
# Rebuilds the music profile and stores it on the server (weekly workflow).
#
#   IMPORT_TOKEN=... LASTFM_API_KEY=... scripts/profile-refresh.sh https://musik.hornpedersen.dk
#
# 1. fetch the profile in use (GET /api/internal/profile) as "previous"
# 2. build a new one with api/bin/music-profile.php (Last.fm + MusicBrainz)
# 3. sanity check, then upload (POST /api/internal/profile)
# 4. fetch the whole Last.fm artist library (api/bin/lastfm-library.php) and
#    upload it (POST /api/internal/library) - for the artist-interest signal
# The token goes in a header (never in a URL or the log); files live in a
# private temp dir that is removed afterwards.
set -euo pipefail

SITE="${1:?usage: profile-refresh.sh <site-url>}"
SITE="${SITE%/}"
: "${IMPORT_TOKEN:?IMPORT_TOKEN is not set}"
: "${LASTFM_API_KEY:?LASTFM_API_KEY is not set}"

work="$(mktemp -d)"
chmod 700 "$work"
trap 'rm -rf "$work"' EXIT

curl -sS -f -H "X-Import-Token: $IMPORT_TOKEN" -H 'Accept: application/json' \
  -o "$work/previous.json" "$SITE/api/internal/profile"
echo "Current profile: $(php -r '$p = json_decode(file_get_contents($argv[1]), true); echo count($p["artists"] ?? []), " artists";' "$work/previous.json")"

php api/bin/music-profile.php --json --previous="$work/previous.json" > "$work/profile.json"

# Refuse to replace a good profile with an obviously broken one.
php -r '
$new = json_decode(file_get_contents($argv[1]), true);
$old = json_decode(file_get_contents($argv[2]), true);
$n = count($new["artists"] ?? []); $o = count($old["artists"] ?? []);
if ($n === 0 || $n < $o * 0.8 || empty($new["genres"])) { fwrite(STDERR, "New profile looks wrong ($n artists, before $o) - not uploading\n"); exit(1); }
echo "New profile: $n artists\n";
' "$work/profile.json" "$work/previous.json"

curl -sS -f -X POST -H "X-Import-Token: $IMPORT_TOKEN" -H 'Content-Type: application/json' \
  --data-binary "@$work/profile.json" "$SITE/api/internal/profile"
echo

# The Last.fm artist library (all artists with their plays), linked to the
# MusicBrainz ids confirmed in the new profile. Not uploaded if it shrank a lot.
php api/bin/lastfm-library.php --profile="$work/profile.json" > "$work/library.json"
curl -sS -f -H "X-Import-Token: $IMPORT_TOKEN" -H 'Accept: application/json' \
  -o "$work/status.json" "$SITE/api/internal/status"
php -r '
$new = count(json_decode(file_get_contents($argv[1]), true)["artists"] ?? []);
$old = (int) (json_decode(file_get_contents($argv[2]), true)["listening_library"]["artists"] ?? 0);
if ($new === 0 || $new < $old * 0.8) { fwrite(STDERR, "New Last.fm library looks wrong ($new artists, before $old) - not uploading\n"); exit(1); }
echo "Last.fm library: $new artists (before $old)\n";
' "$work/library.json" "$work/status.json"
curl -sS -f -X POST -H "X-Import-Token: $IMPORT_TOKEN" -H 'Content-Type: application/json' \
  --data-binary "@$work/library.json" "$SITE/api/internal/library"
echo
