#!/usr/bin/env bash
set -Eeuo pipefail

APP="${APP_DIR:-/var/www/swapin}"
cd "$APP"

echo "===== SEO STATIC DEPLOY GATE ====="

php -l sitemap.php >/dev/null
php -l sitemap-pages.php >/dev/null
php -l includes/seo.php >/dev/null
php -l listings/all.php >/dev/null

bash -n tools/seo-production-check.sh
bash -n scripts/check-seo-analytics.sh
bash -n tools/seo-critical-guard.sh

grep -q 'Legacy WordPress dated URLs -> current postname URLs' \
    ops/nginx/swaapin.ir.conf

if grep -qE "\['loc'.*/trades" sitemap.php; then
    echo "[FAIL] /trades must not exist in public sitemap"
    exit 1
fi

for slug in test-final about-gol seo-test-page test ai-assistant; do
    if ! grep -Fq "'$slug'" sitemap-pages.php; then
        echo "[FAIL] Test/legacy page slug is not excluded from sitemap-pages.php: $slug"
        exit 1
    fi
done

FUNC_LINE="$(grep -n '^check_legacy_redirect()' tools/seo-production-check.sh | head -1 | cut -d: -f1)"
CALL_LINE="$(grep -n 'check_legacy_redirect "\$BASE/category/electronics"' tools/seo-production-check.sh | head -1 | cut -d: -f1)"

if [ -z "$FUNC_LINE" ] || [ -z "$CALL_LINE" ] || [ "$FUNC_LINE" -ge "$CALL_LINE" ]; then
    echo "[FAIL] SEO redirect helper is declared after first invocation"
    exit 1
fi

echo "[PASS] Static SEO deploy gate"
