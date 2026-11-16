#!/usr/bin/env bash
# Writes api/config/config.local.php on the one.com server from environment
# variables (GitHub Secrets in the deploy workflow), over SFTP.
#
#   scripts/sftp-config.sh
#
# Safety rules:
#   - Values are never printed; the file is generated in a private temp dir
#     and removed afterwards.
#   - The file carries a marker. If the server already has a
#     config.local.php WITHOUT that marker (made by hand), nothing is
#     overwritten and the run fails - so a manual configuration is never lost.
#   - Host key is verified against SFTP_KNOWN_HOSTS (strict checking).
#   - The file lives in api/config/, which is not reachable over HTTP (403),
#     and is never part of Git or the deploy package.
#
# Required env: SFTP_HOST, SFTP_USERNAME, SFTP_PASSWORD, SFTP_REMOTE_PATH,
#               DB_HOST, DB_NAME, DB_USER, DB_PASSWORD
# Optional env: SFTP_PORT (22), DB_PORT (3306), SFTP_KNOWN_HOSTS_FILE,
#               IMPORT_TOKEN (daily import endpoint), LASTFM_API_KEY,
#               IMPORT_BUDGET (seconds per import call), FEED_SOURCE (database|file)
set -euo pipefail

for v in SFTP_HOST SFTP_USERNAME SFTP_PASSWORD SFTP_REMOTE_PATH DB_HOST DB_NAME DB_USER DB_PASSWORD; do
  [[ -n "${!v:-}" ]] || { echo "$v is not set" >&2; exit 1; }
done
SFTP_PORT="${SFTP_PORT:-22}"
KNOWN_HOSTS="${SFTP_KNOWN_HOSTS_FILE:-$HOME/.ssh/known_hosts}"
[[ -s "$KNOWN_HOSTS" ]] || { echo "Known hosts file '$KNOWN_HOSTS' is missing - refusing to connect." >&2; exit 1; }
MARKER='MUSIC-RADAR:DEPLOY-GENERATED'
REMOTE_DIR="${SFTP_REMOTE_PATH%/}/api/config"

work="$(mktemp -d)"
chmod 700 "$work"
trap 'rm -rf "$work"' EXIT
export LFTP_PASSWORD="$SFTP_PASSWORD"

lftp_run() { # commands on stdin
  lftp -f /dev/stdin <<EOF
set cmd:fail-exit yes
set net:max-retries 3
set net:timeout 30
set sftp:auto-confirm no
set sftp:connect-program "ssh -a -x -o StrictHostKeyChecking=yes -o UserKnownHostsFile=$KNOWN_HOSTS"
open --env-password -u "$SFTP_USERNAME" -p "$SFTP_PORT" "sftp://$SFTP_HOST"
cd "$REMOTE_DIR"
$(cat)
EOF
}

# 1. Is there a hand-made config.local.php on the server? Then stop.
if echo 'cls -1' | lftp_run | grep -qx 'config.local.php'; then
  echo 'get config.local.php -o '"$work/current.php" | lftp_run
  if ! grep -q "$MARKER" "$work/current.php"; then
    echo "::error::The server already has an api/config/config.local.php that was not made by the deploy - not overwriting it. Move its settings into GitHub Secrets first." >&2
    exit 1
  fi
  echo "Existing config.local.php was made by the deploy - updating it."
fi

# 2. Generate the file (var_export quotes every value safely).
php -r '
$c = ["db" => [
    "host" => getenv("DB_HOST"),
    "port" => (int) (getenv("DB_PORT") ?: 3306),
    "name" => getenv("DB_NAME"),
    "user" => getenv("DB_USER"),
    "password" => getenv("DB_PASSWORD"),
]];
if ((string) getenv("IMPORT_TOKEN") !== "") {
    $c["import"] = ["token" => getenv("IMPORT_TOKEN")];
}
if ((int) getenv("IMPORT_BUDGET") > 0) {
    $c["import"]["budget_seconds"] = (int) getenv("IMPORT_BUDGET");
}
if (in_array(getenv("FEED_SOURCE"), ["database", "file"], true)) {
    $c["feed"] = ["source" => getenv("FEED_SOURCE")];
}
if ((string) getenv("LASTFM_API_KEY") !== "") {
    $c["lastfm"] = ["api_key" => getenv("LASTFM_API_KEY")];
}
echo "<?php\n\n// '"$MARKER"' " . gmdate("Y-m-d\\TH:i:s\\Z") . "\n";
echo "// Written by the deploy workflow from GitHub Secrets. Changes made here are\n// overwritten - change the secrets instead.\n\n";
echo "return " . var_export($c, true) . ";\n";
' > "$work/config.local.php"
php -l "$work/config.local.php" > /dev/null
php -r '$c = require $argv[1]; exit(($c["db"]["name"] ?? "") !== "" && ($c["db"]["password"] ?? "") !== "" ? 0 : 1);' "$work/config.local.php" \
  || { echo "Generated config is incomplete" >&2; exit 1; }

# 3. Upload.
echo "put $work/config.local.php -o config.local.php" | lftp_run
echo "Uploaded api/config/config.local.php (database settings) to the server."
