#!/usr/bin/env bash
set -u

BASE="https://swaapin.ir"
FAIL=0
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

pass() { echo "✅ $1"; }
fail() { echo "❌ $1"; FAIL=$((FAIL + 1)); }
warn() { echo "⚠️ $1"; }

fetch() {
    local url="$1"
    local file="$2"
    curl -fsSL --max-time 30 "$url" -o "$file"
}

echo "===== SWAAPIN SEO / ANALYTICS PRODUCTION QA ====="
echo

echo "1) Homepage SEO"

if fetch "$BASE/" "$TMP/home.html"; then
    grep -qi '<title[^>]*>[^<]' "$TMP/home.html" \
        && pass "Title OK" || fail "Missing Title"

    grep -qiE '<meta[^>]+name=["'"'"']description["'"'"']' "$TMP/home.html" \
        && pass "Description OK" || fail "Missing Description"

    grep -qiE '<link[^>]+rel=["'"'"']canonical["'"'"'][^>]+href=["'"'"']https://swaapin\.ir/?["'"'"']' "$TMP/home.html" \
        && pass "Canonical OK" || fail "Missing/Wrong Canonical"

    grep -qi 'application/ld+json' "$TMP/home.html" \
        && pass "Schema OK" || fail "Schema Missing"
else
    fail "Homepage fetch failed"
fi

echo
echo "2) Google Tag Manager"

grep -qiE 'GTM-[A-Z0-9]+' "$TMP/home.html" \
    && pass "GTM Public Enabled" || fail "GTM Missing"

echo
echo "3) Direct GA4 Check"

if grep -qiE 'googletagmanager\.com/gtag/js\?id=G-' "$TMP/home.html"; then
    fail "Direct GA4 found outside GTM"
else
    pass "GA4 Controlled By GTM"
fi

echo
echo "4) Admin Tracking Isolation"

curl -sS --max-time 30 "$BASE/admin/" -o "$TMP/admin.html" || true

if grep -qiE 'GTM-[A-Z0-9]+|googletagmanager\.com/gtag/js\?id=G-' "$TMP/admin.html" 2>/dev/null; then
    fail "Admin Tracking Enabled"
else
    pass "Admin Tracking Disabled"
fi

echo
echo "5) Dashboard Tracking Isolation"

curl -sS --max-time 30 "$BASE/dashboard" -o "$TMP/dashboard.html" || true

if grep -qiE 'GTM-[A-Z0-9]+|googletagmanager\.com/gtag/js\?id=G-' "$TMP/dashboard.html" 2>/dev/null; then
    fail "Dashboard Tracking Enabled"
else
    pass "Dashboard Tracking Disabled"
fi

echo
echo "6) Blog SEO"

if fetch "$BASE/blog/" "$TMP/blog.html"; then
    grep -qiE '<link[^>]+rel=["'"'"']canonical["'"'"'][^>]+href=["'"'"']https://swaapin\.ir/blog/?["'"'"']' "$TMP/blog.html" \
        && pass "Blog Canonical OK" || fail "Blog Canonical Missing/Wrong"

    if grep -qiE '<meta[^>]+name=["'"'"']robots["'"'"'][^>]+content=["'"'"'][^"'"'"']*noindex' "$TMP/blog.html"; then
        fail "Blog is NOINDEX"
    else
        pass "Blog Robots OK"
    fi
else
    fail "Blog fetch failed"
fi

echo
echo "7) Robots.txt"

if fetch "$BASE/robots.txt" "$TMP/robots.txt"; then
    grep -qiE '^sitemap:[[:space:]]*https://swaapin\.ir/sitemap\.xml' "$TMP/robots.txt" \
        && pass "Sitemap Declaration OK" || fail "Sitemap Declaration Missing"
else
    fail "robots.txt fetch failed"
fi

echo
echo "8) Sitemap Availability"

CODE="$(curl -sS -o /dev/null -w '%{http_code}' "$BASE/sitemap.xml" || true)"

if [ "$CODE" = "200" ]; then
    pass "Sitemap Available"
else
    fail "Sitemap Not Found: HTTP $CODE"
fi

echo
echo "===== QA RESULT ====="
echo "Failures: $FAIL"

if [ "$FAIL" -gt 0 ]; then
    echo "===== QA FAILED ====="
    exit 1
fi

echo "===== QA COMPLETE / PASS ====="
