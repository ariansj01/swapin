#!/bin/bash

SITE="https://swaapin.ir"

echo "===== SEO / ANALYTICS QA ====="


echo ""
echo "1) Homepage SEO"

HOME=$(curl -Ls $SITE/)

echo "$HOME" | grep -q '<title>' \
&& echo "✅ Title OK" \
|| echo "❌ Missing Title"


echo "$HOME" | grep -q 'meta name="description"' \
&& echo "✅ Description OK" \
|| echo "❌ Missing Description"


echo "$HOME" | grep -q 'canonical' \
&& echo "✅ Canonical OK" \
|| echo "❌ Missing Canonical"



echo ""
echo "2) Schema"

echo "$HOME" | grep -q 'application/ld+json' \
&& echo "✅ Schema OK" \
|| echo "❌ Schema Missing"



echo ""
echo "3) Google Tag Manager"


echo "$HOME" | grep -q 'GTM-5GLQN3HL' \
&& echo "✅ GTM Enabled Public" \
|| echo "❌ GTM Missing"



echo ""
echo "4) Admin must NOT have GTM"


ADMIN=$(curl -Ls $SITE/admin/login.php)


echo "$ADMIN" | grep -q 'GTM-5GLQN3HL'

if [ $? -eq 0 ]
then
 echo "❌ ERROR: GTM leaked into Admin"
else
 echo "✅ Admin Tracking Disabled"
fi



echo ""
echo "5) Direct GA4"


echo "$HOME" | grep -q 'G-S0RG4SWX8K'

if [ $? -eq 0 ]
then
 echo "❌ Direct GA4 detected"
else
 echo "✅ GA4 controlled by GTM"
fi



echo ""
echo "===== DONE ====="
