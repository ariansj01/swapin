#!/usr/bin/env bash
set -Eeuo pipefail
cd /opt/swaapin-metabase
docker compose config --quiet
for service in postgres metabase; do
    id=$(docker compose ps -q "$service")
    test -n "$id"
    test "$(docker inspect -f '{{.State.Running}}' "$id")" = true
    test "$(docker inspect -f '{{.State.Health.Status}}' "$id")" = healthy
done
curl -fsS --max-time 10 http://127.0.0.1:3000/api/health | grep -q 'ok'
test "$(curl -sS -o /dev/null -w '%{http_code}' --max-time 15 https://swaapin.ir/meta/base/)" = 200
test "$(curl -sS -o /dev/null -w '%{http_code}' --max-time 15 https://swaapin.ir/meta/base/api/health)" = 200
test "$(curl -sS -o /dev/null -w '%{http_code}' --max-time 15 https://swaapin.ir/meta/base)" = 308
curl -sSI --max-time 15 https://swaapin.ir/meta/base/ | grep -qi '^x-robots-tag: noindex, nofollow, noarchive'
for port in 3000 5432 3306; do
    sockets=$(ss -H -lnt "sport = :$port")
    test -n "$sockets"
    if printf '%s\n' "$sockets" | grep -Eq '([[:space:]]0\.0\.0\.0:|\[::\]:|[[:space:]]\*:)'"$port"'([[:space:]]|$)'; then
        echo "PUBLIC_PORT_$port=FAIL" >&2
        exit 1
    fi
done
nginx -t >/dev/null
echo 'METABASE_HEALTHCHECK=PASS'
