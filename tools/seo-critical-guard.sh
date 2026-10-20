#!/usr/bin/env bash
set -u

BASE="https://swaapin.ir"
APP="/var/www/swapin"
BLOG="$APP/blog"

FAIL=0

pass() {
    echo "[PASS] $1"
}

fail() {
    echo "[FAIL] $1"
    FAIL=$((FAIL + 1))
}

canonical_of() {
    curl -fsSL --max-time 30 "$1" 2>/dev/null \
      | grep -oiE '<link[^>]+rel=["'"'"']canonical["'"'"'][^>]*>' \
      | head -1 \
      | sed -nE 's/.*href=["'"'"']([^"'"'"']+)["'"'"'].*/\1/p'
}

echo "================================================"
echo " SWAAPIN CRITICAL SEO GUARD"
echo "================================================"

echo
echo "[1] NGINX"

NGINX_OUTPUT="$(nginx -t 2>&1)" || {
    echo "$NGINX_OUTPUT"
    fail "nginx -t failed"
}

echo "$NGINX_OUTPUT"

if echo "$NGINX_OUTPUT" | grep -qi 'conflicting server name'; then
    fail "Duplicate/conflicting Nginx server_name exists"
else
    pass "No conflicting server_name"
fi

EXTRA_VHOSTS="$(find /etc/nginx/sites-enabled -maxdepth 1 -type f \
    ! -name 'swaapin.ir' \
    ! -name 'default' \
    -printf '%f\n' 2>/dev/null || true)"

if echo "$EXTRA_VHOSTS" | grep -q 'swaapin'; then
    fail "Extra Swaapin config exists in sites-enabled: $EXTRA_VHOSTS"
else
    pass "No Swaapin backup config is enabled"
fi

echo
echo "[2] LEGACY WORDPRESS URL"

OLD="$BASE/blog/2026/07/21/%D9%85%D8%B9%D8%A7%D9%88%D8%B6%D9%87-%DA%A9%D8%A7%D9%84%D8%A7-%D8%A8%D8%A7-%DA%A9%D8%A7%D9%84%D8%A7/"
NEW="$BASE/blog/%D9%85%D8%B9%D8%A7%D9%88%D8%B6%D9%87-%DA%A9%D8%A7%D9%84%D8%A7-%D8%A8%D8%A7-%DA%A9%D8%A7%D9%84%D8%A7/"

CODE="$(curl -sS -o /dev/null -w '%{http_code}' "$OLD")"
LOC="$(curl -sSI "$OLD" | awk 'BEGIN{IGNORECASE=1} /^location:/{sub(/\r$/,"",$2); print $2; exit}')"

NORMALIZED_LOC="$(python3 - "$LOC" <<'PYURL'
import sys
from urllib.parse import unquote
print(unquote(sys.argv[1]))
PYURL
)"

NORMALIZED_NEW="$(python3 - "$NEW" <<'PYURL'
import sys
from urllib.parse import unquote
print(unquote(sys.argv[1]))
PYURL
)"

if [ "$CODE" = "301" ] && [ "$NORMALIZED_LOC" = "$NORMALIZED_NEW" ]; then
    pass "Legacy blog URL redirects 301 to current URL"
else
    fail "Legacy blog redirect wrong: code=$CODE location=$LOC expected=$NEW"
fi

NEW_CODE="$(curl -sS -o /dev/null -w '%{http_code}' "$NEW")"

[ "$NEW_CODE" = "200" ] \
    && pass "Current blog URL is 200" \
    || fail "Current blog URL returned $NEW_CODE"

echo
echo "[3] CATEGORY REDIRECT + CANONICAL"

for pair in \
    "$BASE/category/electronics|$BASE/electronics" \
    "$BASE/category/home-appliances|$BASE/home-appliances"
do
    OLD_CAT="${pair%%|*}"
    NEW_CAT="${pair##*|}"

    C="$(curl -sS -o /dev/null -w '%{http_code}' "$OLD_CAT")"
    L="$(curl -sSI "$OLD_CAT" | awk 'BEGIN{IGNORECASE=1} /^location:/{sub(/\r$/,"",$2); print $2; exit}')"

    if [ "$C" = "301" ] && [ "$L" = "$NEW_CAT" ]; then
        pass "$OLD_CAT -> $NEW_CAT"
    else
        fail "$OLD_CAT redirect incorrect: code=$C location=$L"
    fi

    C2="$(curl -sS -o /dev/null -w '%{http_code}' "$NEW_CAT")"
    CAN="$(canonical_of "$NEW_CAT")"

    if [ "$C2" = "200" ] && [ "$CAN" = "$NEW_CAT" ]; then
        pass "$NEW_CAT is 200 and self-canonical"
    else
        fail "$NEW_CAT code=$C2 canonical=$CAN"
    fi
done

echo
echo "[4] PUBLIC SITEMAP"

SITEMAP="$(mktemp)"
trap 'rm -f "$SITEMAP"' EXIT

curl -fsSL "$BASE/sitemap.php" > "$SITEMAP"

if grep -qE '<loc>https://swaapin\.ir/trades/?</loc>' "$SITEMAP"; then
    fail "/trades is still present in public sitemap"
else
    pass "/trades excluded from public sitemap"
fi

echo
echo "[5] ALL SITEMAP URL HTTP STATUS"

while IFS= read -r url; do
    [ -z "$url" ] && continue

    code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 30 "$url" || true)"

    if [ "$code" != "200" ]; then
        fail "Sitemap URL not 200: $code $url"
    fi
done < <(
    {
        curl -fsSL "$BASE/sitemap.php" || true
        curl -fsSL "$BASE/sitemap-pages.php" || true
    } \
      | sed -n 's:.*<loc>\(.*\)</loc>.*:\1:p' \
      | sed 's/&amp;/\&/g' \
      | sort -u
)

echo
echo "[6] WORDPRESS PERMALINK"

if command -v wp >/dev/null 2>&1 && [ -d "$BLOG" ]; then
    P="$(cd "$BLOG" && wp option get permalink_structure --allow-root 2>/dev/null || true)"

    [ "$P" = "/%postname%/" ] \
        && pass "WordPress permalink locked to /%postname%/" \
        || fail "Unexpected WordPress permalink: $P"

    MU="$(cd "$BLOG" && wp plugin list --status=must-use --field=name --allow-root 2>/dev/null || true)"

    for plugin in \
        auto-slug-redirect \
        lock-production-permalink \
        noindex-paginated-archives \
        swaapin-title-fix
    do
        echo "$MU" | grep -qx "$plugin" \
            && pass "MU plugin active: $plugin" \
            || fail "MU plugin missing: $plugin"
    done
fi

echo
echo "================================================"
echo "CRITICAL SEO FAILURES: $FAIL"
echo "================================================"

if [ "$FAIL" -gt 0 ]; then
    echo "SEO CRITICAL GUARD FAILED"
    exit 1
fi

echo "SEO CRITICAL GUARD PASSED"
