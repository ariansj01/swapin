#!/usr/bin/env bash
set -Eeuo pipefail

backup_root=/root/swaapin-metabase-preflight
install -d -m 0700 "$backup_root"
backup_dir=$(mktemp -d "$backup_root/pre-$(date -u +%Y%m%dT%H%M%SZ)-XXXXXX")
chmod 0700 "$backup_dir"

cp -a /var/www/swapin/ops/nginx/swaapin.ir.conf "$backup_dir/nginx-source.conf"
cp -a /etc/nginx/sites-available/swaapin.ir "$backup_dir/nginx-active.conf"
cp -a /etc/nginx/nginx.conf "$backup_dir/nginx-main.conf"
cp -a /etc/fstab "$backup_dir/fstab"
nginx -T > "$backup_dir/nginx-effective.conf" 2> "$backup_dir/nginx-test.stderr"

git -C /var/www/swapin rev-parse HEAD > "$backup_dir/git-head.txt"
git -C /var/www/swapin status --short --branch > "$backup_dir/git-status.txt"
git -C /var/www/swapin diff --binary > "$backup_dir/git-unstaged.patch"
git -C /var/www/swapin diff --cached --binary > "$backup_dir/git-staged.patch"

mariadb-dump --single-transaction --quick --routines --triggers --events --databases swapin | gzip -1 > "$backup_dir/swapin.sql.gz"
test -s "$backup_dir/swapin.sql.gz"
gzip -t "$backup_dir/swapin.sql.gz"

find "$backup_dir" -type f -exec chmod 0600 {} +
(
  cd "$backup_dir"
  sha256sum nginx-source.conf nginx-active.conf nginx-main.conf nginx-effective.conf fstab swapin.sql.gz > SHA256SUMS
  chmod 0600 SHA256SUMS
)

printf 'BACKUP_DIR=%s\n' "$backup_dir"
printf 'DB_BACKUP_BYTES=%s\n' "$(stat -c %s "$backup_dir/swapin.sql.gz")"
printf 'DB_BACKUP_GZIP_OK=YES\n'
printf 'NGINX_BACKUP_MATCHES_SOURCE=%s\n' "$(cmp -s "$backup_dir/nginx-source.conf" "$backup_dir/nginx-active.conf" && echo YES || echo NO)"
