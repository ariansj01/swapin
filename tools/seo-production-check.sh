#!/usr/bin/env bash

set -u

BASE="https://swaapin.ir"
FAILURES=0
CHECKS=0

green='\033[0;32m'
red='\033[0;31m'
yellow='\033[1;33m'
reset='\033[0m'

pass() {
    CHECKS=$((CHECKS + 1))
    printf "${green}[PASS]${reset} %s\n" "$1"
}

fail() {
    CHECKS=$((CHECKS + 1))
    FAILURES=$((FAILURES + 1))
    printf "${red}[FAIL]${reset} %s\n" "$1"
}

info() {
    printf "${yellow}[INFO]${reset} %s\n" "$1"
}

status_code() {
    curl -sS -o /dev/null -w '%{http_code}' "$1"
}

redirect_url() {
    curl -sS -o /dev/null -w '%{redirect_url}' "$1"
}

canonical_of() {
    curl -sS "$1" |
        grep -ioE '<link[^>]+rel=["'"'"']canonical["'"'"'][^>]*>|<link[^>]+canonical[^>]*>' |
        grep -ioE 'href=["'"'"'][^"'"'"']+' |
        head -n1 |
        sed -E 's/^href=["'"'"']//'
}

robots_meta_of() {
    curl -sS "$1" |
        grep -ioE '<meta[^>]+name=["'"'"']robots["'"'"'][^>]*>' |
        head -n1
}

echo
echo "=============================================="
echo " SWAAPIN PRODUCTION SEO GUARD"
echo " $(date)"
echo "=============================================="
echo

# --------------------------------------------------
# 1. Canonical host / protocol
# --------------------------------------------------

code=$(status_code "http://swaapin.ir/")
loc=$(redirect_url "http://swaapin.ir/")

if [ "$code" = "301" ] && [ "$loc" = "$BASE/" ]; then
    pass "HTTP redirects directly to HTTPS canonical host"
else
    fail "HTTP redirect incorrect: status=$code location=$loc"
fi

code=$(status_code "https://www.swaapin.ir/")
loc=$(redirect_url "https://www.swaapin.ir/")

if [ "$code" = "301" ] && [ "$loc" = "$BASE/" ]; then
    pass "HTTPS www redirects to non-www"
else
    fail "HTTPS www redirect incorrect: status=$code location=$loc"
fi

# --------------------------------------------------
# 2. Homepage
# --------------------------------------------------

code=$(status_code "$BASE/")

if [ "$code" = "200" ]; then
    pass "Homepage returns HTTP 200"
else
    fail "Homepage returned HTTP $code"
fi

canonical=$(canonical_of "$BASE/")

if [ "$canonical" = "$BASE/" ]; then
    pass "Homepage canonical is correct"
else
    fail "Homepage canonical incorrect: $canonical"
fi

robots=$(robots_meta_of "$BASE/")

if echo "$robots" | grep -qi 'index' &&
   echo "$robots" | grep -qi 'follow' &&
   ! echo "$robots" | grep -qi 'noindex'; then
    pass "Homepage is index,follow"
else
    fail "Homepage robots meta incorrect: $robots"
fi

# --------------------------------------------------
# 3. robots.txt
# --------------------------------------------------

code=$(status_code "$BASE/robots.txt")

if [ "$code" = "200" ]; then
    pass "robots.txt returns HTTP 200"
else
    fail "robots.txt returned HTTP $code"
fi

robots_txt=$(curl -sS "$BASE/robots.txt")

if echo "$robots_txt" | grep -Eq '^[[:space:]]*Disallow:[[:space:]]*/[[:space:]]*$'; then
    fail "robots.txt contains site-wide Disallow: /"
else
    pass "robots.txt does not block the whole site"
fi

if echo "$robots_txt" | grep -Fq "Sitemap: $BASE/sitemap.xml"; then
    pass "robots.txt references main sitemap"
else
    fail "robots.txt does not reference $BASE/sitemap.xml"
fi

# --------------------------------------------------
# 4. Main public canonical pages
# --------------------------------------------------

check_self_canonical() {
    local url="$1"

    code=$(status_code "$url")

    if [ "$code" != "200" ]; then
        fail "$url expected 200, got $code"
        return
    fi

    canonical=$(canonical_of "$url")

    if [ "$canonical" = "$url" ]; then
        pass "$url is 200 and self-canonical"
    else
        fail "$url canonical mismatch: $canonical"
    fi
}

check_self_canonical "$BASE/about"
check_self_canonical "$BASE/contact"
check_self_canonical "$BASE/terms"
check_self_canonical "$BASE/privacy"
check_self_canonical "$BASE/listings/"
check_self_canonical "$BASE/category/electronics"
check_self_canonical "$BASE/category/home-appliances"

# --------------------------------------------------
# 5. Legacy .php duplicates
# --------------------------------------------------

check_legacy_redirect() {
    local old="$1"
    local expected="$2"

    code=$(status_code "$old")
    loc=$(redirect_url "$old")

    if [ "$code" = "301" ] && [ "$loc" = "$expected" ]; then
        pass "$old redirects permanently to $expected"
    else
        fail "$old redirect incorrect: status=$code location=$loc expected=$expected"
    fi
}

check_legacy_redirect "$BASE/about.php" "$BASE/about"
check_legacy_redirect "$BASE/contact.php" "$BASE/contact"
check_legacy_redirect "$BASE/terms.php" "$BASE/terms"
check_legacy_redirect "$BASE/privacy.php" "$BASE/privacy"

check_legacy_redirect "$BASE/listings/all" "$BASE/listings/"
check_legacy_redirect "$BASE/listings/all.php" "$BASE/listings/"

# --------------------------------------------------
# 6. Sitemap endpoints
# --------------------------------------------------

for sitemap in \
    "$BASE/sitemap.xml" \
    "$BASE/sitemap.php" \
    "$BASE/sitemap-pages.php"
do
    code=$(status_code "$sitemap")

    if [ "$code" = "200" ]; then
        pass "$sitemap returns HTTP 200"
    else
        fail "$sitemap returned HTTP $code"
    fi
done

# --------------------------------------------------
# 7. Every URL emitted by Swaapin sitemaps
# --------------------------------------------------

info "Checking every URL emitted by Swaapin sitemaps..."

mapfile -t sitemap_urls < <(
    {
        curl -sS "$BASE/sitemap.php"
        curl -sS "$BASE/sitemap-main.xml"
        curl -sS "$BASE/sitemap-pages.php"
    } |
    grep -oP '(?<=<loc>).*?(?=</loc>)' |
    sort -u
)

if [ "${#sitemap_urls[@]}" -eq 0 ]; then
    fail "No URLs discovered in Swaapin sitemaps"
else
    for url in "${sitemap_urls[@]}"; do
        [ -z "$url" ] && continue

        code=$(status_code "$url")

        if [ "$code" = "200" ]; then
            pass "Sitemap URL 200: $url"
        else
            fail "Sitemap URL is not 200: $code $url"
        fi
    done
fi

# --------------------------------------------------
# 8. Private URL must not enter sitemap
# --------------------------------------------------

if printf '%s\n' "${sitemap_urls[@]}" | grep -Fxq "$BASE/support"; then
    fail "/support is present in sitemap"
else
    pass "/support is excluded from sitemap"
fi

# --------------------------------------------------
# 10. Sitemap canonical integrity
# --------------------------------------------------

info "Checking canonical integrity for sitemap URLs..."

for url in "${sitemap_urls[@]}"; do
    [ -z "$url" ] && continue

    # Query-based listing detail pages may intentionally use dynamic canonicals.
    # We still require a canonical to exist, use the canonical host, and resolve to 200.
    canonical=$(canonical_of "$url")

    if [ -z "$canonical" ]; then
        fail "Missing canonical: $url"
        continue
    fi

    if echo "$canonical" | grep -q '^https://www\.swaapin\.ir'; then
        fail "Canonical uses www: $url -> $canonical"
        continue
    fi

    if ! echo "$canonical" | grep -q '^https://swaapin\.ir'; then
        fail "Canonical uses unexpected host: $url -> $canonical"
        continue
    fi

    canonical_code=$(status_code "$canonical")

    if [ "$canonical_code" != "200" ]; then
        fail "Canonical target is not 200: $url -> $canonical [$canonical_code]"
        continue
    fi

    pass "Canonical valid: $url -> $canonical"
done

# --------------------------------------------------
# 9. Canonicals must never use www
# --------------------------------------------------

for url in \
    "$BASE/" \
    "$BASE/about" \
    "$BASE/contact" \
    "$BASE/listings/"
do
    canonical=$(canonical_of "$url")

    if echo "$canonical" | grep -q '://www\.swaapin\.ir'; then
        fail "$url has www canonical: $canonical"
    else
        pass "$url canonical does not use www"
    fi
done

echo
echo "=============================================="
echo " SEO GUARD RESULT"
echo " Checks:   $CHECKS"
echo " Failures: $FAILURES"
echo "=============================================="

if [ "$FAILURES" -gt 0 ]; then
    echo
    printf "${red}SEO PRODUCTION CHECK FAILED${reset}\n"
    exit 1
fi

echo
printf "${green}SEO PRODUCTION CHECK PASSED${reset}\n"
exit 0
