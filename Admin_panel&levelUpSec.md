# امنیت پنل ادمین

پنل ادمین با **Session + بررسی نقش در سرور** محافظت می‌شود؛ فرانت‌اند فقط رابط کاربری است و هیچ تصمیم امنیتی به آن سپرده نمی‌شود.

---

## مدل کلی

```
کاربر → POST به /admin/login.php
     → اعتبارسنجی email + password + role=admin در DB
     → ساخت Session (user_id)
     → session_regenerate_id(true)
     → دسترسی به صفحات /admin/*
```

هر صفحه ادمین (به‌جز login/logout) در **اول فایل** `require_admin()` را صدا می‌زند. اگر شرط برقرار نباشد، درخواست همانجا قطع می‌شود.

---

## ۱. ورود (Authentication)

ورود از مسیر جداگانه `/admin/login.php` انجام می‌شود، نه از لاگین عادی سایت.

**چک‌های بک‌اند:**
- CSRF token روی فرم POST
- Rate limit: **۵ تلاش در ۱۵ دقیقه** به ازای IP
- کوئری فقط کاربرانی با `role = "admin"` و `is_active = 1`
- `password_verify()` روی hash ذخیره‌شده در DB

```17:20:c:\xampp\htdocs\swaapin\admin\login.php
    $user = DB::fetch('SELECT * FROM users WHERE email = ? AND is_active = 1 AND role = "admin"', [$email]);
    if ($user && password_verify($pass, $user['password_hash'])) {
        login_user((int)$user['id']);
        header('Location: ' . APP_URL . '/admin/'); exit;
```

پیام خطا عمداً کلی است («ایمیل یا رمز اشتباه است») تا مشخص نشود آیا ایمیل وجود دارد یا نه.

---

## ۲. Session

بعد از لاگین موفق، `login_user()` اجرا می‌شود:

```127:130:c:\xampp\htdocs\swaapin\includes\config.php
function login_user(int $uid): void {
    session_regenerate_id(true);
    $_SESSION['user_id'] = $uid;
```

| تنظیم | هدف |
|--------|-----|
| `session_regenerate_id(true)` | جلوگیری از session fixation |
| `HttpOnly` | JS نمی‌تواند کوکی را بخواند |
| `SameSite=Lax` | محدود کردن ارسال کوکی در درخواست cross-site |
| `Secure` (روی HTTPS در production) | ارسال کوکی فقط روی HTTPS |

Session ادمین و کاربر عادی **یکسان** است؛ تفاوت فقط در فیلد `role` در دیتابیس است.

---

## ۳. محافظت صفحات (Authorization)

تابع مرکزی `require_admin()` دو حالت دارد:

```15:29:c:\xampp\htdocs\swaapin\includes\admin.php
function require_admin(): array {
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . APP_URL . '/admin/login.php');
        exit;
    }

    $user = auth_user();
    if (!$user || !is_admin_user($user)) {
        http_response_code(403);
        ...
        exit;
    }

    return $user;
}
```

| وضعیت | نتیجه |
|--------|--------|
| بدون Session | redirect به login |
| Session دارد ولی admin نیست | **403 Forbidden** |
| Session + role=admin | اجازه دسترسی |

تفاوت redirect و 403 مهم است: کاربر لاگین‌شده ولی غیرادمین نباید به login فرستاده شود؛ فقط باید 403 بگیرد.

`auth_user()` هر بار از DB می‌خواند که کاربر `is_active = 1` باشد؛ اگر غیرفعال شده باشد، دسترسی قطع می‌شود.

---

## ۴. CSRF

همه فرم‌های POST ادمین (تأیید KYC، مدیریت کاربران، disputes و …) قبل از پردازش `csrf_verify_or_fail()` را صدا می‌زنند. توکن در session نگه‌داری می‌شود و با `hash_equals()` مقایسه می‌شود.

---

## ۵. مجوز در سطح action

علاوه بر `require_admin()`، هر action دوباره در بک‌اند چک می‌شود. مثال:

```13:18:c:\xampp\htdocs\swaapin\admin\users.php
    if ($userId && $action === 'toggle_active') {
        $target = DB::fetch('SELECT id, is_active, role, name FROM users WHERE id = ?', [$userId]);
        if ($target && $target['role'] !== 'admin') {
            admin_toggle_user_active($userId, !(bool)$target['is_active']);
```

یعنی حتی اگر کسی POST جعل کند، نمی‌تواند ادمین دیگر را غیرفعال کند.

---

## ۶. لایه‌های دیگر

- **KYC و فایل‌های خصوصی:** فقط از `media/private.php` با auth owner/admin
- **SQL:** prepared statements — ریسک SQL injection پایین
- **خروجی HTML:** escape با `h()` — ریسک XSS پایین
- **robots:** صفحه login با `noindex, nofollow`

---

## ۷. محدودیت‌ها و نکات production

| موضوع | وضعیت |
|--------|--------|
| Rate limit لاگین ادمین | ✅ اعمال شده |
| CSRF + session hardening | ✅ |
| رمز پیش‌فرض `1234` در config | ⚠️ باید عوض شود |
| reset خودکار رمز در migration | ⚠️ خطرناک در production |
| logout با GET | ضعیف — logout CSRF ممکن است |
| تأیید دوم برای `make_admin` | ❌ ندارد |

---

## جمع‌بندی

امنیت پنل ادمین روی **سه ستون** است:

1. **Session امن** — کوکی سخت‌شده + regenerate بعد از لاگین  
2. **Gatekeeper مرکزی** — `require_admin()` در هر صفحه  
3. **دفاع در عمق** — CSRF، rate limit، و چک مجوز در هر action


# اصلاحات امنیتی جدید


---

## بحرانی (Critical)

| مورد | اقدام |
|------|--------|
| **XSS در `?toast=`** | `showToast()` با `textContent` + sanitize پارامتر URL |
| **رمز پیش‌فرض `1234`** | حذف `ADMIN_DEFAULT_PASS` از config |
| **Reset خودکار رمز ادمین** | `admin_sync_credentials()` دیگر رمز را overwrite نمی‌کند |
| **Credential در example** | `mail_secrets.example.php` → placeholder |
| **خرید فوری (buy_now)** | handler سرور غیرفعال شد |
| **Exploit اختلاف** | دیگر با ثبت dispute سپرده auto-refund نمی‌شود |
| **XSS در دکمه Share** | onclick حذف → `data-title` + JS امن |
| **make_admin** | endpoint حذف شد |

---

## احراز هویت و Session

- **Logout** فقط با POST + CSRF (کاربر و ادمین)
- **لاگین ادمین** بدون pre-fill ایمیل + rate limit (۵/۱۵ دقیقه)
- **require_admin()** → بدون session: redirect | non-admin: **403**

---

## Config و Secrets

```php
// از متغیر محیطی:
SWAPIN_DB_HOST, SWAPIN_DB_NAME, SWAPIN_DB_USER, SWAPIN_DB_PASS
SWAPIN_ADMIN_EMAIL, SWAPIN_ADMIN_PASS, SWAPIN_APP_URL
```

---

## Rate Limiting (جدید)

| Endpoint | حد |
|----------|-----|
| messages | 40 / 15min |
| trades | 30 / 15min |
| listing actions | 40 / 15min |
| subscription | 10 / 1h |
| error reports | 10 / 1h |
| save_listing API | 60 / 1h |
| notifications API | 120 / 1h |

---

## سایر

- **Security headers** سراسری (X-Frame-Options, nosniff, HSTS در production)
- **`includes/.htaccess`** + deny در root `.htaccess`
- **Store bulk** → `validate_listing_content()` + `review_status=pending`
- **Messages** → `thread_id` سمت سرور (نه از client)
- **KYC preview** → `private_media_url()` به‌جای URL عمومی
- **AI pricing** → escape در reasons
- **ai_match refresh** → CSRF اجباری

---

## برای production

```bash
# تنظیم env vars
SWAPIN_ENV=production
SWAPIN_DB_PASS=your-secure-password
SWAPIN_ADMIN_PASS=your-strong-admin-password

# ساخت/تنظیم ادمین (CLI)
php run_admin_migration.php
# یا:
SWAPIN_ADMIN_PASS='YourPass123!' php -r "require 'includes/config.php'; admin_sync_credentials(true);"
```

---

## نکته برای local (XAMPP)

اگر ادمین ندارید، یک بار `SWAPIN_ADMIN_PASS` را set کنید و migration را اجرا کنید. بدون این متغیر، ادمین خودکار ساخته **نمی‌شود** (عمداً برای امنیت).

---

## موارد باقی‌مونده (اولویتش پایین‌تره)

- OTP/SMS هنوز کامل نیست
- Welcome bonus قابل abuse است (نیاز به captcha/تأیید email)
- CSP کامل (Content-Security-Policy) اضافه نشده
- Admin dispute resolution هنوز escrow را خودکار release/refund نمی‌کند (باید در admin panel تکمیل شود)