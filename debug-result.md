### زیرساخت امنیتی
- فایل جدید `includes/security.php`: CSRF، rate limiting، `require_cli()`، session امن، سرویس فایل‌های خصوصی KYC
- `includes/config.php`: محیط `APP_ENV` / `SWAPIN_ENV`، `display_errors` خاموش در production، کوکی `SameSite` + `Secure` روی HTTPS، آپلود سخت‌تر با `getimagesize`

### فایل‌های خطرناک
| اقدام | فایل |
|--------|------|
| حذف | `test_db.php`, `admin/install.php` |
| انتقال | `laasztzg_kala_b_kala.sql` → `database/` |
| CLI-only | همه `run_*.php` و `seed/*.php` |
| `.htaccess` | ریشه، `database/`, `storage/`, `seed/`, `uploads/` |

### CSRF
- توکن CSRF در `<meta>` + همه فرم‌های POST
- APIها: `save_listing`, `ai_chat`, `ai_valuate`, `contact`, `review`
- JS: `app.js`, `ai-pricing.js`, `emailjs-contact.js`

### باگ‌های منطقی
- واریز آزمایشی فقط در dev (`WALLET_DEMO_DEPOSIT`)
- پذیرش/رد پیشنهاد فقط با POST (نه GET)
- BNPL دیگر خودکار approve نمی‌شود
- `offer_listing_id` باید متعلق به کاربر باشد
- OTP: rate limit + حذف `error_log` کد
- KYC در `storage/private/` + دسترسی از `media/private.php`

### روی سرور (production)
1. `SWAPIN_ENV=production` یا host غیر-local
2. `APP_URL`, `DB_*`, `ADMIN_DEFAULT_PASS` را عوض کن
3. dump از `database/laasztzg_kala_b_kala.sql` import کن
4. migrationها از SSH:
   ```bash
   php run_migration.php
   php run_admin_migration.php
   php run_support_migration.php
   ```
5. `composer install` + secrets + پوشه `uploads/`