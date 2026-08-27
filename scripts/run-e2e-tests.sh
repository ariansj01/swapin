#!/bin/bash
# =======================================================
# Swaapin E2E Test Runner — اجرای تست‌های Cypress
# استفاده: ./scripts/run-e2e-tests.sh [ENV] [SPECS]
# ENV: local | staging | production (پیش‌فرض: local)
# SPECS: مسیر فایل تست (اختیاری، مثلا cypress/e2e/auth/**)
# =======================================================

set -Eeuo pipefail

cd "$(dirname "$0")/.."

ENVIRONMENT="${1:-local}"
SPECS="${2:-}"
BASE_URL=""

case "$ENVIRONMENT" in
    local)
        BASE_URL="http://localhost/swaapin"
        echo "[E2E] محیط: LOCAL — $BASE_URL"
        ;;
    staging)
        BASE_URL="${STAGING_BASE_URL:-https://staging.swaapin.ir}"
        echo "[E2E] محیط: STAGING — $BASE_URL"
        ;;
    production)
        BASE_URL="${PRODUCTION_BASE_URL:-https://swaapin.ir}"
        echo "[E2E] محیط: PRODUCTION — $BASE_URL"
        echo "[E2E] ⚠️  هشدار: تست روی پروداکشن در حال اجراست!"
        ;;
    *)
        echo "خطا: محیط نامعتبر '$ENVIRONMENT' — باید یکی از local, staging, production باشد"
        exit 1
        ;;
esac

if [ ! -d node_modules ]; then
    echo "[E2E] نصب وابستگی‌های npm..."
    npm ci || npm install
fi

if [ "$ENVIRONMENT" = "local" ]; then
    if ! command -v php >/dev/null 2>&1; then
        echo "[E2E] خطا: PHP یافت نشد"
        exit 1
    fi

    PHP_SERVER_LOG="/tmp/swaapin-php-server.log"
    PHP_SERVER_PID=""

    cleanup() {
        if [ -n "$PHP_SERVER_PID" ]; then
            echo "[E2E] توقف سرور PHP (PID=$PHP_SERVER_PID)"
            kill "$PHP_SERVER_PID" 2>/dev/null || true
            sleep 1
        fi
    }
    trap cleanup EXIT INT TERM

    echo "[E2E] استارت سرور PHP: $BASE_URL"
    # دریافت هاست و پورت از BASE_URL
    HOST_PORT=$(echo "$BASE_URL" | sed 's|http[s]*://||' | sed 's|/.*||')
    php -S "$HOST_PORT" -t . > "$PHP_SERVER_LOG" 2>&1 &
    PHP_SERVER_PID=$!
    sleep 3

    if ! kill -0 "$PHP_SERVER_PID" 2>/dev/null; then
        echo "[E2E] خطا در استارت سرور PHP. لاگ:"
        cat "$PHP_SERVER_LOG"
        exit 1
    fi
fi

CYPRESS_ARGS=(
    run
    --config "baseUrl=$BASE_URL"
    --browser chrome
)

if [ -n "$SPECS" ]; then
    CYPRESS_ARGS+=(--spec "$SPECS")
fi

echo "[E2E] اجرای Cypress..."
echo "[E2E] Args: ${CYPRESS_ARGS[*]}"

EXIT_CODE=0
npx cypress "${CYPRESS_ARGS[@]}" || EXIT_CODE=$?

echo
echo "[E2E] ========================================"
if [ "$EXIT_CODE" -eq 0 ]; then
    echo "[E2E] ✅ همه تست‌ها با موفقیت پاس شدند"
else
    echo "[E2E] ❌ $EXIT_CODE تست با خطا روبرو شدند"
    echo "[E2E] اسکرین‌شات‌ها: cypress/screenshots/"
    echo "[E2E] ویدیوها:     cypress/videos/"
fi
echo "[E2E] ========================================"

exit "$EXIT_CODE"
