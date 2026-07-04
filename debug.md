## نتیجه بررسی امنیتی

**هیچ فایل Python (`.py`) در پروژه وجود ندارد.** کل کد backend با PHP است.

----
## فایل‌های «خودکار / اجرایی» (CLI + وب)

این فایل‌ها برای اجرای دستی با `php.exe` نوشته شده‌اند، ولی روی XAMPP از مرورگر هم قابل دسترسی‌اند (مگر جداگانه مسدود شده باشند):

| فایل | کارکرد | ریسک |
|------|--------|------|
| `run_migration.php` | اجرای `migration_v2.sql` روی DB | **بحرانی** — بدون احراز هویت |
| `run_admin_migration.php` | migration ادمین + ساخت/همگام admin | **بحرانی** — رمز admin را در خروجی چاپ می‌کند |
| `run_support_migration.php` | migration پشتیبانی | **بالا** |
| `run_categories_fa.php` | UPDATE دسته‌بندی‌ها | **بالا** |
| `seed/seed_demo.php` | ساخت ۶۵ کاربر demo با رمز ثابت | **بالا** |
| `seed/verify.php` | تست داده demo | **متوسط** |
| `test_db.php` | تست اتصال DB با `root`/`''` | **بالا** |
| `admin/install.php` | همگام‌سازی اکانت admin از config | **بحرانی** (فقط چک localhost) |
| `includes/config.php` | bootstrap هر درخواست | **بالا** — خطاها نمایش داده می‌شوند، رمز/DB hardcode |

فایل‌هایی که در `robots.txt` مسدود شده‌اند فقط `run_migration.php` و `/seed/` هستند؛ بقیه migrationها و `test_db.php` و dump دیتابیس مسدود نشده‌اند.

---

## باگ‌های امنیتی — اولویت بحرانی

### 1. Dump دیتابیس در document root

`laasztzg_kala_b_kala.sql` در ریشه پروژه است و از وب قابل دانلود است. شامل:
- hash رمز کاربران
- **داده واقعی**: ایمیل `ariansj.ir@gmail.com`، شماره `09150583289`، کد ملی/حساب در migrationهای بعدی

این جدی‌ترین مشکل پروژه است.

### 2. اسکریپت‌های migration بدون محافظت

```1:8:c:\xampp\htdocs\swaapin\run_migration.php
<?php
/**
 * Run migration_v2.sql against the configured database.
 * Usage: c:\xampp\php\php.exe run_migration.php
 */
require_once __DIR__ . '/includes/config.php';
```

هر کسی می‌تواند با باز کردن URL، schema و داده DB را تغییر دهد. `run_admin_migration.php` علاوه بر آن رمز پیش‌فرض admin را چاپ می‌کند:

```50:54:c:\xampp\htdocs\swaapin\run_admin_migration.php
$admin = DB::fetch('SELECT email, role FROM users WHERE email = ?', [ADMIN_EMAIL]);
echo "\nDone. OK=$ok SKIP=$skip FAIL=$fail\n";
if ($admin) {
    echo "Admin login: {$admin['email']} / " . ADMIN_DEFAULT_PASS . " (role={$admin['role']})\n";
```

### 3. `admin/install.php` — reset رمز admin

```9:19:c:\xampp\htdocs\swaapin\admin\install.php
$host = $_SERVER['HTTP_HOST'] ?? '';
$isLocal = in_array($host, ['localhost', '127.0.0.1'], true)
    || str_starts_with($host, 'localhost:')
    || str_starts_with($host, '127.0.0.1:');
// ...
if (!$isLocal) {
    http_response_code(403);
```

- فقط `HTTP_HOST` چک می‌شود (قابل جعل با Host header در برخی تنظیمات)
- با POST، `admin_sync_credentials()` رمز admin را به مقدار config برمی‌گرداند
- رمز در HTML نمایش داده می‌شود

### 4. Credentials و تنظیمات dev در production

```6:8:c:\xampp\htdocs\swaapin\includes\config.php
define('ADMIN_EMAIL',       'admin@kalabkala.com');
define('ADMIN_DEFAULT_PASS', '1234');
define('APP_URL',           'http://localhost/swaapin');
```

```34:35:c:\xampp\htdocs\swaapin\includes\config.php
ini_set('display_errors', 1);
error_reporting(E_ALL);
```

- رمز admin پیش‌فرض: `1234`
- DB: `root` با پسورد خالی
- `display_errors = 1` → افشای مسیر فایل، query و stack trace

---

## باگ‌های امنیتی — اولویت بالا

### 5. نبود CSRF در پنل admin

هیچ توکن CSRF در پروژه نیست. فرم‌های POST در `admin/users.php`, `admin/kyc.php`, `admin/listings.php` و بقیه admin با session فعال admin قابل جعل cross-site هستند.

مثال خطرناک — ارتقای هر کاربر به admin:

```18:20:c:\xampp\htdocs\swaapin\admin\users.php
    } elseif ($userId && $action === 'make_admin') {
        DB::update('users', ['role' => 'admin'], 'id = ? AND role != "admin"', [$userId]);
        admin_set_flash('نقش مدیر اضافه شد.');
```

### 6. واریز آزمایشی بدون پرداخت واقعی

```13:21:c:\xampp\htdocs\swaapin\wallet.php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deposit_amount'])) {
    $amount = (float)($_POST['deposit_amount'] ?? 0);
    if ($amount < 10 || $amount > 10000) {
        // ...
    } else {
        credit_transact($uid, 'deposit', $amount, 'واریز دستی (درگاه آزمایشی)');
```

هر کاربر لاگین‌شده می‌تواند تا ۱۰٬۰۰۰ اعتبار رایگان بگیرد (برای MVP عمدی است، ولی در production خطرناک).

### 7. تغییر state با GET در پیشنهادات معامله

```12:23:c:\xampp\htdocs\swaapin\listings\offers.php
$acceptId = (int)($_GET['accept'] ?? 0);
$rejectId = (int)($_GET['reject'] ?? 0);

if ($acceptId) {
    $_POST['action']   = 'accept';
    $_POST['offer_id'] = $acceptId;
}
// ...
if ($_SERVER['REQUEST_METHOD'] === 'POST' || $acceptId || $rejectId) {
```

پذیرش/رد پیشنهاد از طریق لینک GET → CSRF + امکان کلیک ناخواسته.

### 8. OTP بدون rate limit

```35:44:c:\xampp\htdocs\swaapin\auth\login.php
            $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            // ...
            error_log("OTP for $phone: $code"); // dev only
```

- کد ۶ رقمی → brute force عملی
- OTP در `error_log` نوشته می‌شود (در dev لو می‌رود)
- SMS واقعی ارسال نمی‌شود

### 9. KYC و آپلودها — دسترسی عمومی

- تصاویر KYC در `uploads/` با URL عمومی (`UPLOAD_URL`) ذخیره می‌شوند
- `upload_image()` فقط `mime_content_type` چک می‌کند؛ `.htaccess` برای جلوگیری از اجرای PHP در uploads نیست
- پوشه `uploads/` در gitignore است ولی محافظت سرور تعریف نشده

### 10. `offer_listing_id` بدون بررسی مالکیت

در `listings/view.php` هنگام ثبت پیشنهاد، `offer_listing_id` بررسی نمی‌شود که متعلق به کاربر باشد. مهاجم می‌تواند آگهی دیگران را به پیشنهاد خود attach کند → مشکل business logic / کلاهبرداری.

---

## باگ‌های امنیتی — اولویت متوسط

| موضوع | جزئیات |
|--------|--------|
| Session ناامن | `cookie_secure` و `SameSite` تنظیم نشده |
| ثبت‌نام انبوه | `WELCOME_BONUS = 10_000_000` برای هر حساب جدید |
| BNPL خودکار | در `trades.php` بعد از `request_bnpl` بلافاصله `approve_bnpl` صدا زده می‌شود |
| API بدون throttle | `api/contact.php` عمومی؛ AI endpoints بدون محدودیت نرخ |
| `test_db.php` | افشای وضعیت DB؛ README خودش گفته در production حذف شود |
| `seed/seed_demo.php` | رمز ثابت `Demo1234!` برای همه demo users |

---

## API endpoints — وضعیت احراز هویت

| Endpoint | Auth | CSRF | یادداشت |
|----------|------|------|---------|
| `api/save_listing.php` | ✅ | ❌ | toggle bookmark |
| `api/ai_chat.php` | ✅ | ❌ | |
| `api/ai_match.php` | ✅ | ❌ | GET هم قبول می‌کند |
| `api/ai_valuate.php` | ✅ | ❌ | |
| `api/notifications.php` | ✅ | — | read-only |
| `api/contact.php` | ❌ | ❌ | عمومی |
| `api/review.php` | ✅ | ❌ | POST redirect |

---

## Python

**۰ فایل.** هیچ reference به Python، cron job خارجی، یا script `.py` در repo نیست.

---

## جمع‌بندی اولویت‌بندی

```
🔴 بحرانی (فوری)
   ├── laasztzg_kala_b_kala.sql در web root
   ├── run_*.php بدون auth
   ├── admin/install.php
   └── display_errors + رمز admin پیش‌فرض در config

🟠 بالا
   ├── CSRF در admin + offers GET
   ├── wallet deposit آزمایشی
   ├── OTP brute force
   ├── uploads/KYC عمومی
   └── offer_listing_id بدون ownership check

🟡 متوسط
   ├── session cookies
   ├── rate limiting
   ├── welcome bonus abuse
   └── test_db / seed scripts در وب
```

---