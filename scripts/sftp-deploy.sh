#!/usr/bin/env bash
# Uploads the deploy package to the one.com web root over SFTP (lftp).
#
#   scripts/sftp-deploy.sh <local-dir> [deploy|dry-run|check] [backup-dir]
#
# Modes:
#   deploy   (default) back up files that will be overwritten, then upload
#   dry-run  show what would be uploaded, change nothing
#   check    only connect and list the remote directory
#
# Safety rules:
#   - Never deletes remote files (no mirror --delete). Files that exist only
#     on the server, e.g. api/config/config.local.php, are left untouched.
#   - Before uploading, every remote file that is about to be overwritten is
#     downloaded to <backup-dir> (default: ./server-backup).
#   - Never creates the target directory: if SFTP_REMOTE_PATH is wrong, the
#     run fails instead of uploading somewhere unexpected.
#   - Refuses to upload secrets (verify-package.sh), and excludes them from
#     the mirror as well.
#   - Host key is verified against SFTP_KNOWN_HOSTS (strict checking).
#   - index.html is uploaded last, so visitors never get an index.html that
#     points to assets that are not on the server yet.
#
# Required env: SFTP_HOST, SFTP_USERNAME, SFTP_PASSWORD, SFTP_REMOTE_PATH
# Optional env: SFTP_PORT (22), SFTP_KNOWN_HOSTS_FILE (~/.ssh/known_hosts)
set -euo pipefail

LOCAL_DIR="${1:?usage: sftp-deploy.sh <local-dir> [deploy|dry-run|check] [backup-dir]}"
MODE="${2:-deploy}"
BACKUP_DIR="${3:-server-backup}"

: "${SFTP_HOST:?SFTP_HOST is not set}"
: "${SFTP_USERNAME:?SFTP_USERNAME is not set}"
: "${SFTP_PASSWORD:?SFTP_PASSWORD is not set}"
: "${SFTP_REMOTE_PATH:?SFTP_REMOTE_PATH is not set}"
SFTP_PORT="${SFTP_PORT:-22}"
KNOWN_HOSTS="${SFTP_KNOWN_HOSTS_FILE:-$HOME/.ssh/known_hosts}"

case "$MODE" in deploy|dry-run|check) ;; *) echo "Unknown mode: $MODE" >&2; exit 2 ;; esac

if [[ ! -s "$KNOWN_HOSTS" ]]; then
  echo "Known hosts file '$KNOWN_HOSTS' is missing or empty - refusing to connect without host key verification." >&2
  exit 1
fi

# Files that must never overwrite the server's copy. Keep in sync with
# the forbidden list in verify-package.sh.
PROTECTED_GLOBS=(
  'config.local.php'
  '*.local.php'
  '.env'
  '.env.*'
  '.user.ini'
  '*.sql'
  '*.sqlite'
)

if [[ "$MODE" != check ]]; then
  # Uploading must target an explicit web root, never the login directory.
  case "${SFTP_REMOTE_PATH%/}" in
    ''|.|/|'~'|..)
      echo "SFTP_REMOTE_PATH='$SFTP_REMOTE_PATH' is not a specific web root - refusing to upload." >&2
      exit 1
      ;;
  esac
  bash "$(dirname "$0")/verify-package.sh" "$LOCAL_DIR"
fi

excludes=""
for g in "${PROTECTED_GLOBS[@]}"; do excludes+=" --exclude-glob '$g'"; done

mirror_flags="--reverse --verbose --no-perms --parallel=4 --exclude '^index\.html\$'$excludes"

case "$MODE" in
  check)
    actions="pwd
cls -la"
    ;;
  dry-run)
    actions="mirror $mirror_flags --dry-run '$LOCAL_DIR' .
!echo '(dry-run) would upload index.html last'"
    ;;
  deploy)
    # Back up every remote file we are about to overwrite. Hashed files in
    # assets/ are new on every build, so they are skipped. Missing remote
    # files are expected (first deploy) and not an error.
    rm -rf "$BACKUP_DIR"
    backups="set cmd:fail-exit no"
    while IFS= read -r -d '' f; do
      rel="${f#"$LOCAL_DIR"/}"
      dir="$(dirname "$rel")"
      mkdir -p "$BACKUP_DIR/$dir"
      backups+=$'\n'"get -O '$BACKUP_DIR/$dir' '$rel'"
    done < <(find "$LOCAL_DIR" -type f ! -path "$LOCAL_DIR/assets/*" -print0)
    backups+=$'\nset cmd:fail-exit yes'

    actions="!echo '--- Backing up remote files that will be overwritten'
$backups
!echo '--- Uploading'
mirror $mirror_flags '$LOCAL_DIR' .
!echo '--- Uploading index.html (last)'
put '$LOCAL_DIR/index.html' -o index.html
!echo 'Upload complete.'"
    ;;
esac

export LFTP_PASSWORD="$SFTP_PASSWORD"

# Hide the expected "No such file" noise from backing up files that do not
# exist yet. Read lftp's own exit status from PIPESTATUS right after the
# pipeline - an "|| true" here would reset it and hide real upload failures.
set +e
lftp -f /dev/stdin <<EOF 2>&1 | grep -v '^get: Access failed: No such file'
set cmd:fail-exit yes
set net:max-retries 3
set net:reconnect-interval-base 5
set net:timeout 30
set sftp:auto-confirm no
set sftp:connect-program "ssh -a -x -o StrictHostKeyChecking=yes -o UserKnownHostsFile=$KNOWN_HOSTS"
open --env-password -u "$SFTP_USERNAME" -p "$SFTP_PORT" "sftp://$SFTP_HOST"
cd "$SFTP_REMOTE_PATH"
$actions
bye
EOF
status=${PIPESTATUS[0]}
set -e

if [[ "$MODE" == deploy ]]; then
  # Drop empty subfolders, but keep $BACKUP_DIR itself: on a first deploy
  # nothing exists remotely, every folder is empty, and deleting the root
  # made the count below fail after a successful upload.
  find "$BACKUP_DIR" -mindepth 1 -type d -empty -delete
  n=$(find "$BACKUP_DIR" -type f | wc -l | tr -d ' ')
  echo "Backed up $n existing remote file(s) to $BACKUP_DIR/."
fi

if ((status != 0)); then
  echo "lftp failed with exit code $status." >&2
fi
exit "$status"
