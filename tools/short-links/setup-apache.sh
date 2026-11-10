#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
set -euo pipefail
if [ "$#" -ne 2 ];then echo 'Usage: setup-apache.sh --enable-root-url DOMAIN' >&2;exit 1;fi
mode=$1;domain=$2
case "$domain" in
 nextcloud.sydani.org) expected=nextcloud-staging ;;
 documents.sydani.org) expected=nextcloud ;;
 *) echo 'Specify nextcloud.sydani.org or documents.sydani.org.' >&2;exit 1 ;;
esac
render_config() {
 python3 - "$domain" <<'PY'
import sys
host=sys.argv[1];pattern=host.replace('.','[.]')
print('# Managed by LibreSign short-links/setup-apache.sh')
print('<IfModule mod_alias.c>')
print('  <If "%{HTTP_HOST} =~ m#^'+pattern+'(?::[0-9]+)?$#">')
print('    RedirectMatch 302 "^/v/((?:[A-Za-z0-9]{4}-[A-Za-z0-9]{4}|[A-Za-z0-9]{8}|[A-Za-z0-9_-]{22}))$" "https://'+host+'/index.php/apps/libresign/v/$1"')
print('  </If>')
print('</IfModule>')
PY
}
if [ "$mode" = --render-config ];then render_config;exit 0;fi
if [ "$mode" != --enable-root-url ] || [ "$EUID" -ne 0 ];then echo 'Run as root: bash setup-apache.sh --enable-root-url DOMAIN' >&2;exit 1;fi
[ "$(hostname)" = "$expected" ] || { echo 'Wrong server for the supplied domain.' >&2;exit 1; }
app=$(realpath "$(dirname "$0")/../..")
[ -f "$app/lib/Controller/ShortValidationController.php" ] || { echo 'Install the prepared LibreSign release first.' >&2;exit 1; }
command -v apache2ctl >/dev/null
command -v a2enconf >/dev/null
backup=$(mktemp -d /root/libresign-short-url-backup.XXXXXX)
chmod 700 "$backup"
runuser -u www-data -- /usr/bin/php "$app/tools/short-links/configure.php" --status "$domain" > "$backup/status-before.json"
runuser -u www-data -- /usr/bin/php "$app/tools/short-links/configure.php" --prepare-grouped "$domain" > "$backup/status-prepared.json"
code=$(python3 - "$backup/status-prepared.json" <<'PY'
import json,sys
print(json.load(open(sys.argv[1]))['sampleCode'])
PY
)
curl --fail --silent --show-error --max-time 20 -o /dev/null -D "$backup/native.headers" "https://$domain/index.php/apps/libresign/v/$code"
grep -qE '^HTTP/[^ ]+ 303' "$backup/native.headers" || { echo 'App short-route preflight failed.' >&2;exit 1; }
conf=/etc/apache2/conf-available/libresign-short-validation.conf
was_enabled=0
[ ! -e /etc/apache2/conf-enabled/libresign-short-validation.conf ] || was_enabled=1
if [ -f "$conf" ];then
 grep -q '^# Managed by LibreSign short-links/setup-apache.sh$' "$conf" || { echo 'Existing unmanaged Apache file; stopped.' >&2;exit 1; }
 cp -p "$conf" "$backup/previous.conf"
fi
rollback() {
 status=$?
 if [ "$status" -ne 0 ];then
  if [ -f "$backup/previous.conf" ];then install -m0644 "$backup/previous.conf" "$conf";else rm -f -- "$conf";fi
  if [ "$was_enabled" -eq 0 ];then a2disconf libresign-short-validation >/dev/null 2>&1 || true;fi
  if apache2ctl configtest;then systemctl reload apache2;fi
  echo "Setup stopped; original Apache configuration restored. Diagnostics: $backup" >&2
 fi
}
trap rollback EXIT
render_config > "$backup/new.conf"
install -m0644 "$backup/new.conf" "$conf"
a2enconf libresign-short-validation >/dev/null
apache2ctl configtest
systemctl reload apache2
curl --fail --silent --show-error --max-time 20 -o /dev/null -D "$backup/root.headers" "https://$domain/v/$code"
python3 - "$backup/root.headers" "$domain" "$code" <<'PY'
import sys
s=open(sys.argv[1]).read()
assert s.splitlines()[0].split()[1]=='302',s
headers={line.split(':',1)[0].lower():line.split(':',1)[1].strip() for line in s.splitlines()[1:] if ':' in line}
assert headers.get('location')=='https://'+sys.argv[2]+'/index.php/apps/libresign/v/'+sys.argv[3],s
PY
runuser -u www-data -- /usr/bin/php "$app/tools/short-links/configure.php" --enable-root "$domain"
trap - EXIT
echo "PASS: https://$domain/v/<code> is active; eight-character grouped footer links enabled; existing links retained. Private backup: $backup"
