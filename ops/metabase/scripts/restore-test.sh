#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
cd /opt/swaapin-metabase
archive=${1:-$(find backups/daily -maxdepth 1 -type f -name 'metabaseappdb-*.dump' | sort -r | head -n 1)}
test -s "$archive"
test_db=metabase_restore_test
cleanup() {
    docker compose exec -T postgres dropdb -U metabase_app --if-exists "$test_db" >/dev/null 2>&1 || true
}
trap cleanup EXIT
cleanup
docker compose exec -T postgres createdb -U metabase_app "$test_db"
docker compose exec -T postgres pg_restore -U metabase_app -d "$test_db" --no-owner --no-acl < "$archive"
count=$(docker compose exec -T postgres psql -U metabase_app -d "$test_db" -Atqc "SELECT count(*) FROM information_schema.tables WHERE table_schema='public'")
test "$count" -gt 0
printf 'RESTORE_TEST=PASS tables=%s\n' "$count"
