#!/usr/bin/env bash
set -Eeuo pipefail

backup_dir=${1:?Pass the verified pre-change backup directory}
source_conf=/var/www/swapin/ops/nginx/swaapin.ir.conf
active_conf=/etc/nginx/sites-available/swaapin.ir
robots=/var/www/swapin/robots.txt

[[ -s "$backup_dir/nginx-before-metabase.conf" && -s "$backup_dir/robots-before-metabase.txt" ]]

rollback() {
  local original_status=$?
  trap - ERR
  cp -a "$backup_dir/nginx-before-metabase.conf" "$source_conf"
  cp -a "$backup_dir/nginx-before-metabase.conf" "$active_conf"
  cp -a "$backup_dir/robots-before-metabase.txt" "$robots"
  nginx -t && systemctl reload nginx
  printf 'NGINX_DEPLOY_ROLLED_BACK=YES\n' >&2
  exit "$original_status"
}
trap rollback ERR

cp -a "$source_conf" "$active_conf"
nginx -t
systemctl reload nginx

[[ $(curl -sS -o /dev/null -w '%{http_code}' --max-time 10 https://swaapin.ir/) == 200 ]]
[[ $(curl -sS -o /dev/null -w '%{http_code}' --max-time 10 https://swaapin.ir/meta/base/) == 200 ]]
[[ $(curl -sS -o /dev/null -w '%{http_code}' --max-time 10 https://swaapin.ir/meta/base/api/health) == 200 ]]
[[ $(curl -sS -o /dev/null -w '%{http_code}' --max-time 10 https://swaapin.ir/meta/base) == 308 ]]

trap - ERR
echo 'NGINX_DEPLOY_MINIMAL_SMOKE=PASS'
