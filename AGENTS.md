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


## Deployment QA Checklist

Before every production deployment:

Run:

./scripts/check-seo-analytics.sh


Deployment must not continue if:

- GTM missing from public pages
- GTM exists in admin/dashboard
- Direct GA4 script exists
- Canonical tags removed
- JSON-LD schema removed
- Blog canonical removed
- Sitemap unavailable

## Mandatory Before Production Deploy

Before every deploy execute:

./tools/seo-production-check.sh

./scripts/check-seo-analytics.sh


Deployment is blocked when:

- SEO Guard fails
- Canonical changes unexpectedly
- Sitemap breaks
- GTM disappears from public pages
- GTM appears in admin/dashboard
- Direct GA4 script is introduced
