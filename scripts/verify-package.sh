#!/usr/bin/env bash
# Verifies a deploy package before it is uploaded:
#   - the React frontend and the PHP API are both present, including the
#     .htaccess files that protect the API's internals
#   - no secrets / server-only files are included
#
#   scripts/verify-package.sh deploy
set -euo pipefail

DIR="${1:?usage: verify-package.sh <package-dir>}"
errors=()

# Must be present.
required=(
  index.html
  .htaccess
  favicon.svg
  api/index.php
  api/bootstrap.php
  api/routes.php
  api/.htaccess
  api/config/config.php
  api/config/.htaccess
  api/src/.htaccess
  api/data/.htaccess
  api/data/feed.json
)
for f in "${required[@]}"; do
  [[ -f "$DIR/$f" ]] || errors+=("missing: $f")
done

shopt -s nullglob
js=("$DIR"/assets/index-*.js)
css=("$DIR"/assets/index-*.css)
((${#js[@]})) || errors+=("missing: assets/index-*.js (frontend bundle)")
((${#css[@]})) || errors+=("missing: assets/index-*.css (frontend styles)")
shopt -u nullglob

if [[ -f "$DIR/index.html" ]]; then
  grep -q '<title>Martins Music Radar</title>' "$DIR/index.html" || errors+=("index.html is not the Martins Music Radar app")
  for ref in $(grep -oE '/assets/[^"]+' "$DIR/index.html"); do
    [[ -f "$DIR$ref" ]] || errors+=("index.html references $ref, which is not in the package")
  done
fi

# Must never be present. Keep in sync with PROTECTED_GLOBS in sftp-deploy.sh.
while IFS= read -r -d '' f; do
  errors+=("protected file in package: ${f#"$DIR"/}")
done < <(find "$DIR" -type f \( \
  -name 'config.local.php' -o -name '*.local.php' -o \
  -name '.env' -o -name '.env.*' -o -name '.user.ini' -o \
  -name '*.sql' -o -name '*.sqlite' -o -name '*.log' -o \
  -name '*.map' -o -path '*/node_modules/*' -o -path '*/.git/*' -o -path "$DIR/api/var/*" \
  \) -print0)

if ((${#errors[@]})); then
  echo "Deploy package '$DIR' is NOT valid:" >&2
  printf '  - %s\n' "${errors[@]}" >&2
  exit 1
fi

echo "Deploy package '$DIR' OK: $(find "$DIR" -type f | wc -l | tr -d ' ') files, frontend + API present, no protected files."
