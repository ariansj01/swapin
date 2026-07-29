# Production SEO Analytics Rules

Before modifying includes/layout.php:

Verify:

- Google Tag Manager
- GA4
- Canonical tags
- Meta description
- Robots meta
- JSON-LD schema


Rules:

1. Public pages:
   - GTM enabled

2. Admin pages:
   - GTM disabled

3. Direct GA4 gtag.js:
   - Forbidden

4. Analytics must be managed through GTM.

Before deployment run:

./scripts/check-seo-analytics.sh
