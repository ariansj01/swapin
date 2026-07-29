#!/bin/bash

SITE="https://swaapin.ir"

echo "===== SWAAPIN SEO / ANALYTICS PRODUCTION QA ====="


echo ""
echo "1) Homepage SEO"

HOME=$(curl -Ls "$SITE/")

echo "$HOME" | grep -q "<title>" \
&& echo "✅ Title OK" \
|| echo "❌ Missing Title"


echo "$HOME" | grep -q 'meta name="description"' \
&& echo "✅ Description OK" \
|| echo "❌ Missing Description"


echo "$HOME" | grep -q "canonical" \
&& echo "✅ Canonical OK" \
|| echo "❌ Missing Canonical"


echo "$HOME" | grep -q "application/ld+json" \
&& echo "✅ Schema OK" \
|| echo "❌ Schema Missing"



echo ""
echo "2) Google Tag Manager"

echo "$HOME" | grep -q "GTM-5GLQN3HL" \
&& echo "✅ GTM Public Enabled" \
|| echo "❌ GTM Missing"



echo ""
echo "3) Direct GA4 Check"

echo "$HOME" | grep -q "G-S0RG4SWX8K"

if [ $? -eq 0 ]
then
 echo "❌ Direct GA4 Found - Should be GTM only"
else
 echo "✅ GA4 Controlled By GTM"
fi



echo ""
echo "4) Admin Tracking Isolation"

ADMIN=$(curl -Ls "$SITE/admin/login.php")

echo "$ADMIN" | grep -q "GTM-5GLQN3HL"

if [ $? -eq 0 ]
then
 echo "❌ GTM leaked into Admin"
else
 echo "✅ Admin Tracking Disabled"
fi



echo ""
echo "5) Dashboard Tracking Isolation"

DASH=$(curl -Ls "$SITE/dashboard.php")

echo "$DASH" | grep -q "GTM-5GLQN3HL"

if [ $? -eq 0 ]
then
 echo "❌ GTM leaked into Dashboard"
else
 echo "✅ Dashboard Tracking Disabled"
fi



echo ""
echo "6) Blog SEO"

BLOG=$(curl -Ls "$SITE/blog/")

echo "$BLOG" | grep -q "canonical"

if [ $? -eq 0 ]
then
 echo "✅ Blog Canonical OK"
else
 echo "❌ Blog Canonical Missing"
fi


echo "$BLOG" | grep -q "robots"

if [ $? -eq 0 ]
then
 echo "✅ Blog Robots OK"
else
 echo "❌ Blog Robots Missing"
fi



echo ""
echo "7) Robots.txt"

ROBOTS=$(curl -Ls "$SITE/robots.txt")

echo "$ROBOTS" | grep -q "Sitemap"

if [ $? -eq 0 ]
then
 echo "✅ Sitemap Declaration OK"
else
 echo "⚠️ Sitemap Declaration Missing"
fi



echo ""
echo "8) Sitemap Availability"

curl -sf "$SITE/sitemap.xml" > /dev/null

if [ $? -eq 0 ]
then
 echo "✅ Sitemap Available"
else
 echo "⚠️ Sitemap Not Found"
fi



echo ""
echo "===== QA COMPLETE ====="
