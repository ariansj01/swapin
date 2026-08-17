#!/bin/bash

set -e

DOMAIN="https://swaapin.ir"
ROOT="/var/www/swapin"

FAILED=0


check()
{
    NAME=$1
    URL=$2
    EXPECT=$3

    RESPONSE=$(curl -I -s "$DOMAIN$URL" | grep "^HTTP" | head -1)

    echo "$NAME => $RESPONSE"

    if [[ "$RESPONSE" != *"$EXPECT"* ]]
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
echo "[2] PHP SYNTAX"

find "$ROOT" -name "*.php" \
-not -path "$ROOT/blog/*" \
-exec php -l {} \; | grep "Errors parsing" && FAILED=1 || true



echo ""
echo "[3] CANONICAL PHP URLS"

check "login.php" "/auth/login.php" "301"
check "trades.php" "/trades.php" "301"
check "wallet.php" "/wallet.php" "301"
check "profile edit.php" "/profile/edit.php" "301"
check "create listing.php" "/listings/create.php" "301"



echo ""
echo "[4] CLEAN URLS"

check "login" "/auth/login" "200"
check "trades" "/trades" "200"
check "wallet" "/wallet" "200"
check "profile" "/profile/edit" "200"
check "create listing" "/listings/create" "200"



echo ""
echo "[5] SITEMAP"

check "sitemap" "/sitemap.xml" "200"
check "old sitemap" "/sitemap-main.xml" "301"



echo ""
echo "[6] WWW CANONICAL"

curl -I -s https://www.swaapin.ir | grep "location" || FAILED=1



echo ""
echo "[7] LISTING SEO"

check "deleted listing" "/listings/view?id=999999" "404"



echo ""
echo "[8] PERMISSIONS"

find "$ROOT/uploads" -type d -exec chmod 775 {} \; 2>/dev/null || true
find "$ROOT/uploads" -type f -exec chmod 664 {} \; 2>/dev/null || true

find "$ROOT/storage" -type d -exec chmod 775 {} \; 2>/dev/null || true
find "$ROOT/storage" -type f -exec chmod 664 {} \; 2>/dev/null || true



echo ""
echo "[9] GIT STATUS"

cd "$ROOT"

git status --short



echo ""
if [ $FAILED -eq 0 ]
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
