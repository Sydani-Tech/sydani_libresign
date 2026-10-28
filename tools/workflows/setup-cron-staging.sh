#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Root-only staging repair. Keeps reminder settings unchanged.
set -euo pipefail
umask 0077
if [[ "$(hostname)" != nextcloud-staging || "$(id -u)" != 0 || "$#" != 1 ]]; then
  printf '%s\n' 'Run as root on nextcloud-staging with --repair-nextcloud-cron.' >&2
  exit 1
fi
[[ "$1" = --repair-nextcloud-cron ]]
app=/var/www/html/apps/libresign
check="$app/tools/workflows/check-cron-staging.php"
target=/etc/cron.d/nextcloud-libresign-workflows
[[ -f /var/www/html/cron.php && -f "$check" && ! -L "$target" ]]
systemctl is-active --quiet cron
runuser -u www-data -- /usr/bin/php "$check"
backup=$(mktemp -d /root/libresign-cron-backup.XXXXXX)
crontab -u www-data -l > "$backup/www-data-crontab.txt" 2>/dev/null || true
# Inspect privileged user crontabs and system cron files. Avoid duplicates.
: > "$backup/existing-schedule-files.txt"
while IFS= read -r -d '' file; do
  if awk '!/^[[:space:]]*#/ && /cron[.]php|background-job:execute/ {found=1} END {exit !found}' "$file"; then printf '%s\n' "$file" >> "$backup/existing-schedule-files.txt"; fi
done < <(find /etc/cron.d /var/spool/cron/crontabs -maxdepth 1 -type f -print0; printf '/etc/crontab\0')
installed=0
if [[ -s "$backup/existing-schedule-files.txt" ]]; then
  printf '%s\n' 'STOP: a cron entry already exists. No schedule was changed; review these files before adding another job.' >&2
  cat "$backup/existing-schedule-files.txt" >&2
  printf 'Private scheduler backup: %s\n' "$backup" >&2
  exit 2
else
  [[ ! -e "$target" ]]
  printf '%s\n' 'SHELL=/bin/sh' 'PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin' '*/5 * * * * www-data /usr/bin/php -f /var/www/html/cron.php' > "$backup/new-cron-entry"
  install -o root -g root -m 0644 "$backup/new-cron-entry" "$target"
  installed=1
  printf '%s\n' 'PASS: Nextcloud background jobs scheduled every five minutes as www-data.'
fi
printf 'Private scheduler backup: %s\n' "$backup"
printf '%s\n' 'Running Nextcloud background jobs once. This may take several minutes; queued Nextcloud work may be processed. Reminder emails remain disabled.'
started=$(date +%s)
if ! runuser -u www-data -- /usr/bin/php -f /var/www/html/cron.php > "$backup/first-run.log" 2>&1; then
  if (( installed )); then rm -- "$target"; fi
  printf 'FAIL: cron failed; newly installed entry removed. Private log: %s/first-run.log\n' "$backup" >&2
  exit 1
fi
if ! runuser -u www-data -- /usr/bin/php "$check" "$started"; then
  if (( installed )); then rm -- "$target"; fi
  printf 'FAIL: cron did not record success; newly installed entry removed. Private log: %s/first-run.log\n' "$backup" >&2
  exit 1
fi
printf '%s\n' 'PASS: Nextcloud recorded a successful background run. Reminder settings were not changed.'
