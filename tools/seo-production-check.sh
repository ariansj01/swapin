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
check_self_canonical "$BASE/iran"
check_self_canonical "$BASE/iran/electronics"
check_self_canonical "$BASE/iran/home-kitchen"

# Also verify the legacy /category/* URLs 301-redirect to new canonical-style URLs
# Redirect validation helper must be defined before first use
unquote_url() {
    python3 -c 'import sys; from urllib.parse import unquote; print(unquote(sys.argv[1]))' "$1"
}

check_legacy_redirect() {
    local old="$1"
    local expected="$2"

    code=$(status_code "$old")
    loc=$(redirect_url "$old")

    norm_loc="$(unquote_url "$loc")"
    norm_expected="$(unquote_url "$expected")"

    if [ "$code" != "301" ] || [ "$norm_loc" != "$norm_expected" ]; then
        fail "$old redirect incorrect: status=$code location=$loc expected=$expected"
        return
    fi

    # معیار پذیرش نقشهٔ ۳۰۱: ریدایرکت باید تک‌مرحله‌ای باشد؛
    # مقصد نهایی باید ۲۰۰ بدهد (نه یک ۳۰۱ میانی = زنجیره).
    local final_code
    final_code=$(status_code "$loc")

    if [ "$final_code" = "200" ]; then
        pass "$old redirects (one hop) to $expected"
    else
        fail "$old creates a redirect chain: $expected returned HTTP $final_code"
    fi
}

# آدرس‌هایی که باید با ۴۱۰ Gone پاسخ دهند (صفحه‌ها/فروشگاه تستی — نقشهٔ ۳۰۱)
check_gone() {
    local url="$1"
    local code
    code=$(status_code "$url")

    if [ "$code" = "410" ]; then
        pass "$url returns 410 Gone"
    else
        fail "$url expected 410, got $code"
    fi
}

check_legacy_redirect "$BASE/category/electronics"      "$BASE/iran/electronics"
check_legacy_redirect "$BASE/category/home-kitchen"     "$BASE/iran/home-kitchen"
# Renamed legacy top-level slugs must 301 to the current canonical category
check_legacy_redirect "$BASE/home-appliances"           "$BASE/iran/home-kitchen"
check_legacy_redirect "$BASE/category/home-appliances"  "$BASE/iran/home-kitchen"

# --------------------------------------------------
# 5. Legacy .php duplicates
# --------------------------------------------------



check_legacy_redirect "$BASE/about.php" "$BASE/about"
check_legacy_redirect "$BASE/contact.php" "$BASE/contact"
check_legacy_redirect "$BASE/terms.php" "$BASE/terms"
check_legacy_redirect "$BASE/privacy.php" "$BASE/privacy"

check_legacy_redirect "$BASE/listings" "$BASE/iran"
check_legacy_redirect "$BASE/listings/" "$BASE/iran"
check_legacy_redirect "$BASE/listings/all" "$BASE/iran"
check_legacy_redirect "$BASE/listings/all.php" "$BASE/iran"

# --------------------------------------------------
# 5b. نقشهٔ ۳۰۱ — ریدایرکت تک‌مرحله‌ای آدرس‌های قدیمی (بخش ۱۱)
# منبع: «سواپین — نقشه_ی ۳۰۱.csv». هر مورد باید در «یک مرحله» به مقصد ۲۰۰ برسد.
# --------------------------------------------------

# ── سطح۱: دسته‌های فعلی → کانونیکال /iran/{leaf}
check_legacy_redirect "$BASE/real-estate"          "$BASE/iran/real-estate"
check_legacy_redirect "$BASE/vehicles"             "$BASE/iran/vehicles"
check_legacy_redirect "$BASE/electronics"          "$BASE/iran/electronics"
check_legacy_redirect "$BASE/home-kitchen"         "$BASE/iran/home-kitchen"
check_legacy_redirect "$BASE/personal-items"       "$BASE/iran/personal-items"
check_legacy_redirect "$BASE/leisure-hobbies"      "$BASE/iran/leisure-hobbies"
check_legacy_redirect "$BASE/tools-equipment"      "$BASE/iran/tools-equipment"
check_legacy_redirect "$BASE/jobs"                 "$BASE/iran/jobs"

# ── املاک
check_legacy_redirect "$BASE/real-estate/commercial-office"                 "$BASE/iran/commercial"
check_legacy_redirect "$BASE/real-estate/industrial-land"                   "$BASE/iran/land"
check_legacy_redirect "$BASE/real-estate/residential-apartment"             "$BASE/iran/apartment"
check_legacy_redirect "$BASE/real-estate/residential-apartment/apartment-for-rent" "$BASE/iran/apartment-rent"
check_legacy_redirect "$BASE/real-estate/residential-villa/villa-for-rent"  "$BASE/iran/villa-rent"
check_legacy_redirect "$BASE/real-estate/short-term-rentals"                "$BASE/iran/villa-rent"

# ── وسایل نقلیه
check_legacy_redirect "$BASE/vehicles/cars"                                 "$BASE/iran/car"
check_legacy_redirect "$BASE/vehicles/cars/passenger-cars"                  "$BASE/iran/car"
check_legacy_redirect "$BASE/vehicles/cars/classic-cars"                    "$BASE/iran/car"
check_legacy_redirect "$BASE/vehicles/cars/rental-cars"                     "$BASE/iran/services"
check_legacy_redirect "$BASE/vehicles/car-rental-services"                  "$BASE/iran/services"
check_legacy_redirect "$BASE/vehicles/bicycles"                             "$BASE/iran/bicycles"
check_legacy_redirect "$BASE/vehicles/motorcycles"                          "$BASE/iran/motorcycles"
check_legacy_redirect "$BASE/vehicles/commercial-vehicles"                  "$BASE/iran/heavy-vehicles"
check_legacy_redirect "$BASE/vehicles/heavy-equipment"                      "$BASE/iran/heavy-vehicles"

# ── کالای دیجیتال
check_legacy_redirect "$BASE/electronics/audio-visual"                      "$BASE/iran/audio-video"
check_legacy_redirect "$BASE/electronics/audio-visual/cameras"              "$BASE/iran/camera"
check_legacy_redirect "$BASE/electronics/audio-visual/headphones-speakers"  "$BASE/iran/audio-video"
check_legacy_redirect "$BASE/electronics/computers"                         "$BASE/iran/computer"
check_legacy_redirect "$BASE/electronics/computers/laptops"                 "$BASE/iran/laptop"
check_legacy_redirect "$BASE/electronics/computers/networking"              "$BASE/iran/computer"
check_legacy_redirect "$BASE/electronics/console-gaming"                    "$BASE/iran/game-consoles"
check_legacy_redirect "$BASE/electronics/mobile-tablet"                     "$BASE/iran/mobile-phones"
check_legacy_redirect "$BASE/electronics/mobile-tablet/mobile-phones"       "$BASE/iran/mobile-phones"
check_legacy_redirect "$BASE/electronics/mobile-tablet/tablets"             "$BASE/iran/tablet"
check_legacy_redirect "$BASE/electronics/phones"                            "$BASE/iran/mobile-phones"

# ── خانه و آشپزخانه
check_legacy_redirect "$BASE/home-kitchen/appliances"                       "$BASE/iran/home-appliances"
check_legacy_redirect "$BASE/home-kitchen/appliances/kitchen-appliances"    "$BASE/iran/home-appliances"
check_legacy_redirect "$BASE/home-kitchen/gardening-plants"                 "$BASE/iran/plants"
check_legacy_redirect "$BASE/home-kitchen/home-textile"                     "$BASE/iran/carpet"
check_legacy_redirect "$BASE/home-kitchen/kitchenware"                      "$BASE/iran/kitchenware"

# ── وسایل شخصی + نسل قدیمی
check_legacy_redirect "$BASE/personal-items/baby-products"                  "$BASE/iran/baby-kids"
check_legacy_redirect "$BASE/personal-items/bags-luggage"                   "$BASE/iran/accessories"
check_legacy_redirect "$BASE/personal-items/watches-jewelry"                "$BASE/iran/jewellery"
check_legacy_redirect "$BASE/personal-items/eyeglasses"                     "$BASE/iran/accessories"
check_legacy_redirect "$BASE/clothing"                                      "$BASE/iran/personal-items"
check_legacy_redirect "$BASE/clothing/mens-clothing"                        "$BASE/iran/men-clothing"
check_legacy_redirect "$BASE/clothing/womens-clothing"                      "$BASE/iran/women-clothing"
check_legacy_redirect "$BASE/clothing/shoes"                                "$BASE/iran/shoes"

# ── سرگرمی، ابزار و استخدام
check_legacy_redirect "$BASE/leisure-hobbies/books-magazines"               "$BASE/iran/books"
check_legacy_redirect "$BASE/leisure-hobbies/collectibles"                  "$BASE/iran/art-collectibles"
check_legacy_redirect "$BASE/leisure-hobbies/pets-animals"                  "$BASE/iran/pets"
check_legacy_redirect "$BASE/leisure-hobbies/sports-fitness"                "$BASE/iran/sports"
check_legacy_redirect "$BASE/leisure-hobbies/travel-tourism-items"          "$BASE/iran/services"
check_legacy_redirect "$BASE/tools-equipment/construction"                  "$BASE/iran/building-materials"
check_legacy_redirect "$BASE/tools-equipment/hand-tools"                    "$BASE/iran/tools"
check_legacy_redirect "$BASE/tools-equipment/industrial-machinery"          "$BASE/iran/industrial-equipment"
check_legacy_redirect "$BASE/jobs/fulltime-jobs"                            "$BASE/iran/jobs"
check_legacy_redirect "$BASE/services/home-services/cleaning-services"      "$BASE/iran/services"

# ── صفحه‌های فرود، اپ و آگهی‌ها
check_legacy_redirect "$BASE/services"             "$BASE/iran/services"
check_legacy_redirect "$BASE/pwa"                  "$BASE/app"
check_legacy_redirect "$BASE/exchange"             "$BASE/"
check_legacy_redirect "$BASE/listings"             "$BASE/iran"
check_legacy_redirect "$BASE/listings/"            "$BASE/iran"
check_legacy_redirect "$BASE/listings/all"         "$BASE/iran"
check_legacy_redirect "$BASE/listings/all.php"     "$BASE/iran"
check_legacy_redirect "$BASE/page/ai-assistant"    "$BASE/ai-assistant"
check_legacy_redirect "$BASE/listings/map"         "$BASE/iran"
check_legacy_redirect "$BASE/category/electronics" "$BASE/iran/electronics"
check_legacy_redirect "$BASE/category/home-kitchen" "$BASE/iran/home-kitchen"

# ── نگاشت پارامتر cat (روی /، /listings/ و /listings/map)
check_legacy_redirect "$BASE/?cat=villa-for-sale"        "$BASE/iran/villa"
check_legacy_redirect "$BASE/?cat=clothing"              "$BASE/iran/personal-items"
check_legacy_redirect "$BASE/?cat=electronics%2Fphones"  "$BASE/iran/mobile-phones"
check_legacy_redirect "$BASE/?cat=home-garden"           "$BASE/iran/home-kitchen"
check_legacy_redirect "$BASE/?cat=vehicles%2Fcars"       "$BASE/iran/car"
check_legacy_redirect "$BASE/?cat=personal-items%2Fjewelry" "$BASE/iran/jewellery"

# ── نگاشت پارامتر city (نام استان فارسی → اسلاگ استان)
check_legacy_redirect "$BASE/?city=%D8%A7%D9%84%D8%A8%D8%B1%D8%B2" "$BASE/alborz-province"

# ── ۴۱۰ Gone: صفحه‌ها/فروشگاه تستی
check_gone "$BASE/page/test"
check_gone "$BASE/page/test-final"
check_gone "$BASE/page/seo-test-page"
check_gone "$BASE/page/about-gol"
check_gone "$BASE/shop/both/myket-test-store"

# ── آگهی: /listings/view?id= باید در یک مرحله به اسلاگ کانونیکال برود
# (اسلاگ برگ از DB می‌آید و پیش‌فرض قابل پیش‌بینی نیست؛ فقط تک‌مرحله‌ای بودن بررسی می‌شود)
check_listing_id_redirect() {
    local id="$1"
    local old="$BASE/listings/view?id=$id"

    code=$(status_code "$old")
    loc=$(redirect_url "$old")

    if [ "$code" != "301" ] || ! echo "$loc" | grep -Eq "^$BASE/[^/]+/$id$"; then
        fail "$old did not 301 to /{slug}/$id (status=$code location=$loc)"
        return
    fi

    final_code=$(status_code "$loc")

    if [ "$final_code" = "200" ]; then
        pass "$old redirects (one hop) to $loc"
    else
        fail "$old creates a redirect chain: $loc returned HTTP $final_code"
    fi
}

check_listing_id_redirect 250

check_legacy_redirect \
    "$BASE/blog/2026/07/21/%D9%85%D8%B9%D8%A7%D9%88%D8%B6%D9%87-%DA%A9%D8%A7%D9%84%D8%A7-%D8%A8%D8%A7-%DA%A9%D8%A7%D9%84%D8%A7/" \
    "$BASE/blog/%D9%85%D8%B9%D8%A7%D9%88%D8%B6%D9%87-%DA%A9%D8%A7%D9%84%D8%A7-%D8%A8%D8%A7-%DA%A9%D8%A7%D9%84%D8%A7/"


# --------------------------------------------------
# 6. Sitemap endpoints
# --------------------------------------------------

for sitemap in \
    "$BASE/sitemap.xml" \
    "$BASE/sitemap-locations.xml" \
    "$BASE/sitemap-categories.xml" \
    "$BASE/sitemap-brands.xml" \
    "$BASE/sitemap-swap.xml" \
    "$BASE/sitemap-ads.xml" \
    "$BASE/sitemap-shops.xml" \
    "$BASE/sitemap-pages.xml"
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
        curl -sS "$BASE/sitemap-locations.xml"
        curl -sS "$BASE/sitemap-categories.xml"
        curl -sS "$BASE/sitemap-brands.xml"
        curl -sS "$BASE/sitemap-swap.xml"
        curl -sS "$BASE/sitemap-ads.xml"
        curl -sS "$BASE/sitemap-shops.xml"
        curl -sS "$BASE/sitemap-pages.xml"
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
    "$BASE/iran"
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
