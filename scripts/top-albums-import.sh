#!/usr/bin/env bash
# Import of your most played albums (the "Import top albums" workflow): builds
# the list with api/bin/top-albums.php (Last.fm + MusicBrainz) and sends it to
# the server (POST /api/internal/top-albums), which adds the albums it does not
# have yet - a new run never changes or removes one. The token goes in a header
# (never in a URL or the log); files live in a private temp dir that is removed
# afterwards.
#
#   IMPORT_TOKEN=... LASTFM_API_KEY=... scripts/top-albums-import.sh https://musik.hornpedersen.dk [limit]
set -euo pipefail

SITE="${1:?usage: top-albums-import.sh <site-url> [limit]}"
SITE="${SITE%/}"
LIMIT="${2:-100}"
[[ "$LIMIT" =~ ^[0-9]+$ ]] || { echo "limit must be a number" >&2; exit 1; }
: "${IMPORT_TOKEN:?IMPORT_TOKEN is not set}"
: "${LASTFM_API_KEY:?LASTFM_API_KEY is not set}"

work="$(mktemp -d)"
chmod 700 "$work"
trap 'rm -rf "$work"' EXIT

php api/bin/top-albums.php --limit="$LIMIT" > "$work/albums.json"
php -r '
$n = count(json_decode(file_get_contents($argv[1]), true)["albums"] ?? []);
if ($n < $argv[2] / 2) { fwrite(STDERR, "Only $n albums found - looks wrong, not uploading\n"); exit(1); }
' "$work/albums.json" "$LIMIT"
curl -sS -f -X POST -H "X-Import-Token: $IMPORT_TOKEN" -H 'Content-Type: application/json' \
  --data-binary "@$work/albums.json" "$SITE/api/internal/top-albums"
echo
