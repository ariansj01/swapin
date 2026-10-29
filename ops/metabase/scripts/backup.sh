#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
cd /opt/swaapin-metabase
backup_dir=/opt/swaapin-metabase/backups
install -d -m 700 "$backup_dir/daily" "$backup_dir/weekly"
stamp=$(date -u +%Y%m%dT%H%M%SZ)
target="$backup_dir/daily/metabaseappdb-$stamp.dump"
tmp="$target.tmp"
trap 'rm -f -- "$tmp"' EXIT
docker compose exec -T postgres pg_dump -U metabase_app -d metabaseappdb -Fc > "$tmp"
test -s "$tmp"
docker compose exec -T postgres pg_restore -l < "$tmp" >/dev/null
mv -- "$tmp" "$target"
chmod 600 "$target"
week=$(date -u +%G-W%V)
weekly="$backup_dir/weekly/metabaseappdb-$week.dump"
if [[ ! -e "$weekly" ]]; then
    cp -p -- "$target" "$weekly"
fi
mapfile -t daily_files < <(find "$backup_dir/daily" -maxdepth 1 -type f -name 'metabaseappdb-*.dump' -printf '%f\n' | sort -r)
for ((i=7; i<${#daily_files[@]}; i++)); do rm -f -- "$backup_dir/daily/${daily_files[i]}"; done
mapfile -t weekly_files < <(find "$backup_dir/weekly" -maxdepth 1 -type f -name 'metabaseappdb-*.dump' -printf '%f\n' | sort -r)
for ((i=4; i<${#weekly_files[@]}; i++)); do rm -f -- "$backup_dir/weekly/${weekly_files[i]}"; done
printf 'BACKUP_OK=%s\n' "$target"
