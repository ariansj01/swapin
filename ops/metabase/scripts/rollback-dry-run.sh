#!/usr/bin/env bash
set -Eeuo pipefail
backup_dir=${1:?Provide the verified preflight backup directory}
old_conf="$backup_dir/nginx-before-metabase.conf"
new_conf=/var/www/swapin/ops/nginx/swaapin.ir.conf
test -s "$old_conf"
test -s "$new_conf"
! grep -q '/meta/base' "$old_conf"
grep -q 'location \^~ /meta/base/' "$new_conf"
tmp=$(mktemp -d /tmp/swaapin-nginx-rollback.XXXXXX)
trap 'rm -rf -- "$tmp"' EXIT
ln -s /etc/nginx/fastcgi_params "$tmp/fastcgi_params"
ln -s /etc/nginx/fastcgi.conf "$tmp/fastcgi.conf"
ln -s /etc/nginx/snippets "$tmp/snippets"
cat > "$tmp/nginx.conf" <<EOF
pid $tmp/nginx.pid;
events { worker_connections 16; }
http {
  include /etc/nginx/mime.types;
  include $old_conf;
}
EOF
nginx -t -c "$tmp/nginx.conf" -p /etc/nginx/ >/dev/null
cat > "$tmp/nginx.conf" <<EOF
pid $tmp/nginx.pid;
events { worker_connections 16; }
http {
  include /etc/nginx/mime.types;
  include $new_conf;
}
EOF
nginx -t -c "$tmp/nginx.conf" -p /etc/nginx/ >/dev/null
echo 'ROLLBACK_CONFIG_DRY_RUN=PASS'
