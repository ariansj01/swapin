#!/bin/bash

set -e

DOMAIN="https://swaapin.ir"
ROOT="/var/www/swapin"

FAILED=0


check()
{
    NAME=$1
    URL=$2
    EXPECT="$3"

    RESPONSE=$(curl -I -s "$DOMAIN$URL" | grep "^HTTP" | head -1)

    echo "$NAME => $RESPONSE"

    OK=0

    IFS="|" read -ra CODES <<< "$EXPECT"

    for CODE in "${CODES[@]}"
    do
        if [[ "$RESPONSE" == *"$CODE"* ]]
        then
            OK=1
        fi
    done

    if [ "$OK" -eq 0 ]
    then
        echo "FAILED: $NAME"
        FAILED=1
    fi
}


echo "=============================="
echo " SWAAPIN PRODUCTION GUARD"
echo "=============================="


echo ""
echo "[1] NGINX"

nginx -t


echo ""
echo "[2] PHP SYNTAX (SWAAPIN ONLY)"

find \
"$ROOT/admin" \
"$ROOT/api" \
"$ROOT/includes" \
"$ROOT/listings" \
"$ROOT/*.php" \
-type f \
-name "*.php" \
-print0 2>/dev/null \
| xargs -0 -r -n1 php -l >/dev/null


echo ""
echo "[3] CANONICAL PHP URLS"

check "login.php" "/auth/login.php" "301"
check "trades.php" "/trades.php" "301"
check "wallet.php" "/wallet.php" "301"
check "profile edit.php" "/profile/edit.php" "301"
check "create listing.php" "/listings/create.php" "301"


echo ""
echo "[4] CLEAN URLS"

check "login" "/auth/login" "200|301|302"
check "trades" "/trades" "200|301|302"
check "wallet" "/wallet" "200|301|302"
check "profile" "/profile/edit" "200|301|302"
check "create listing" "/listings/create" "200|301|302"


echo ""
echo "[5] SITEMAP"

check "sitemap" "/sitemap.xml" "200"
check "old sitemap" "/sitemap-main.xml" "301"


echo ""
echo "[6] WWW CANONICAL"

WWW=$(curl -I -s https://www.swaapin.ir | grep -i location || true)

echo "$WWW"

if [[ "$WWW" != *"https://swaapin.ir"* ]]
then
    echo "FAILED: www canonical"
    FAILED=1
fi


echo ""
echo "[7] LISTING SEO"

check "deleted listing" "/listings/view?id=999999" "404"


echo ""
echo "[8] GIT STATUS"

cd "$ROOT"

git status --short


echo ""

if [ "$FAILED" -eq 0 ]
then
    echo "=============================="
    echo " SEO GUARD PASSED"
    echo "=============================="
else
    echo "=============================="
    echo " SEO GUARD FAILED"
    echo "=============================="
    exit 1
fi
