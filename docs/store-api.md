# مستندات API فروشگاه (Store API)

> ⚠️ **توجه:** این مستندات به مکان جدیدی منتقل شده است. برای مشاهده آخرین نسخه و راهنمای کامل، به پوشه جدید مراجعه کنید:
>
> ### 🔗 [مشاهده مستندات جدید API نسخه ۱.۰](./api/v1/introduction.md)

---

این API به هر فروشگاه اجازه می‌دهد محصولات خود را از یک سیستم بیرونی (سایت، انبار، نرم‌افزار حسابداری، ربات و ...) به‌صورت خودکار در سواَپین ثبت کند.

- Base URL: `https://swaapin.ir/api/v1`
- قالب پاسخ: `JSON` با کدگذاری UTF-8
- احراز هویت: کلید API مخصوص همان فروشگاه

> محصولی که با این API ثبت می‌شود، **فقط برای همان فروشگاه** (مالک کلید) درج می‌شود و مانند آگهی‌های عادی با وضعیت «فعال» و در انتظار بازبینی (`review_status = pending`) ذخیره می‌گردد.

---

## ۱) دریافت کلید API

کلید API از پنل ادمین ساخته می‌شود:

1. وارد **پنل مدیریت → فروشگاه‌ها** شوید.
2. فروشگاه موردنظر را ویرایش کنید.
3. در بخش **«کلید API فروشگاه»** روی **«تولید کلید API»** بزنید.
4. کلید فقط **یک‌بار** نمایش داده می‌شود؛ بلافاصله آن را کپی و در جای امن ذخیره کنید.

نکات:

- با زدن **«تولید و تعویض کلید جدید»**، کلید قبلی **بلافاصله غیرفعال** می‌شود.
- با **«غیرفعال کردن کلید فعلی»** کلید جاری باطل می‌شود (تا زمان تولید کلید بعدی، API این فروشگاه کار نمی‌کند).
- در پنل ادمین فقط پیش‌شمارهٔ کلید (مثل `swk_1a2b3c4d`) و تاریخ ساخت/آخرین استفاده دیده می‌شود.
- کلید به‌صورت هش‌شده (`sha256`) در جدول `store_api_keys` ذخیره می‌شود.

قالب کلید:

```
swk_<40 hex character>
```

---

## ۲) ارسال کلید در درخواست

کلید باید در هدر ارسال شود. روش پیشنهادی:

```
X-Api-Key: swk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

روش جایگزین:

```
Authorization: Bearer swk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

در صورت نبود/نامعتبر بودن کلید، پاسخ `401` برگردانده می‌شود:

```json
{ "ok": false, "error": "invalid_api_key", "message": "کلید API نامعتبر یا غیرفعال است." }
```

---

## ۳) پیش‌نیازها (دریافت شناسه‌ها)

- فهرست دسته‌بندی‌ها (برای فیلد `category_id`):

```
GET /api/v1/categories
```

- فهرست شهرها (برای فیلد `city` تا نام شهر دقیقاً از فهرست انتخاب شود):

```
GET /api/v1/cities
```

---

## ۴) اندپوینت ثبت محصول

```
POST /api/v1/store/listings
```

### ۴‑۱) روش اول: multipart/form-data (آپلود فایل تصویر)

مناسب وقتی سیستم شما فایل تصویر را در اختیار دارد.

| فیلد | نوع | الزامی | توضیح |
|---|---|---|---|
| `title` | string | ✅ | عنوان محصول، حداقل ۵ کاراکتر |
| `category_id` | int | ✅ | شناسه دسته‌بندی از `GET /categories` |
| `description` | string | ✅ | توضیحات، حداقل ۲۰ کاراکتر |
| `city` | string | ✅ | نام دقیق شهر (از `GET /cities`) |
| `latitude` | float | ✅ | عرض جغرافیایی، داخل محدوده ایران |
| `longitude` | float | ✅ | طول جغرافیایی، داخل محدوده ایران |
| `neighborhood` | string | ✅ | نام محله همان شهر |
| `images[]` | file | ✅ | حداقل ۱ و حداکثر ۸ تصویر (jpg / png / webp / gif، هر کدام حداکثر ۵ مگابایت) |
| `sell_price` | number | – | قیمت فروش نقدی به تومان (پیش‌فرض ۰) |
| `estimated_value` | int | – | ارزش تخمینی برای معاوضه به تومان (پیش‌فرض ۰) |
| `listing_mode` | string | – | یکی از `swap` / `sell` / `both` (پیش‌فرض `both`) |
| `condition` | string | – | یکی از `new` / `like_new` / `good` / `fair` / `poor` (پیش‌فرض `good`) |
| `want_description` | string | – | توضیح کالای موردنیاز برای معاوضه |
| `listing_attr_*` | string | – | فیلدهای اختصاصی بر اساس نوع کسب‌وکار (اختیاری) |

نمونه با `curl`:

```bash
curl -X POST "https://swaapin.ir/api/v1/store/listings" \
  -H "X-Api-Key: swk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" \
  -F "title=لپ‌تاپ ایسوس Vivobook 15" \
  -F "category_id=12" \
  -F "description=لپ‌تاپ در حد نو، سلامت کامل، همراه با شارژر اصلی و جعبه." \
  -F "city=تهران" \
  -F "latitude=35.6892" \
  -F "longitude=51.3890" \
  -F "neighborhood=ونک" \
  -F "sell_price=28500000" \
  -F "estimated_value=30000000" \
  -F "listing_mode=both" \
  -F "condition=like_new" \
  -F "want_description=گوشی آیفون ۱۳ یا بالاتر" \
  -F "images[]=@C:\photos\asus-1.jpg" \
  -F "images[]=@C:\photos\asus-2.jpg"
```

### ۴‑۲) روش دوم: JSON با تصاویر base64

مناسب وقتی سیستم شما JSON می‌فرستد و تصاویر را به‌صورت base64 دارد.

```
Content-Type: application/json
```

```json
{
  "title": "لپ‌تاپ ایسوس Vivobook 15",
  "category_id": 12,
  "description": "لپ‌تاپ در حد نو، سلامت کامل، همراه با شارژر اصلی و جعبه.",
  "city": "تهران",
  "latitude": 35.6892,
  "longitude": 51.3890,
  "neighborhood": "ونک",
  "sell_price": 28500000,
  "estimated_value": 30000000,
  "listing_mode": "both",
  "condition": "like_new",
  "want_description": "گوشی آیفون ۱۳ یا بالاتر",
  "images_base64": [
    "/9j/4AAQSkZJRgABAQAAAQABAAD...",
    "data:image/png;base64,iVBORw0KGgoAAAANSUhEUg..."
  ]
}
```

هر عضو `images_base64` می‌تواند base64 خالص یا Data URI کامل (`data:image/png;base64,...`) باشد.

---

## ۵) فهرست و مشاهده محصولات (Select)

### ۵‑۱) دریافت لیست محصولات فروشگاه
این اندپوینت لیست محصولاتی که توسط این فروشگاه ثبت شده‌اند را برمی‌گرداند.

```
GET /api/v1/store/listings
```

**پارامترهای Query:**
| پارامتر | نوع | پیش‌فرض | توضیح |
|---|---|---|---|
| `page` | int | 1 | شماره صفحه |
| `limit` | int | 20 | تعداد در هر صفحه (حداکثر ۵۰) |
| `status` | string | – | فیلتر وضعیت محصول (active, archived) |
| `review_status` | string | – | فیلتر وضعیت بازبینی (pending, approved, rejected) |
| `q` | string | – | جستجو در عنوان و توضیحات |

**نمونه پاسخ:**
```json
{
  "ok": true,
  "page": 1,
  "limit": 20,
  "total": 45,
  "items": [
    {
      "id": 4213,
      "title": "لپ‌تاپ ایسوس Vivobook 15",
      "status": "active",
      "review_status": "approved",
      "city": "تهران",
      "neighborhood": "ونک",
      "category": "لپ‌تاپ",
      "estimated_value": 30000000,
      "sell_price": 28500000,
      "listing_mode": "both",
      "thumb": "https://swaapin.ir/uploads/listing/thumb_abc.jpg",
      "created_at": "2023-10-27 10:00:00",
      "url": "https://swaapin.ir/listings/view?id=4213"
    }
  ]
}
```

### ۵‑۲) دریافت جزئیات یک محصول
```
GET /api/v1/store/listings/{id}
```

**نمونه پاسخ:**
```json
{
  "ok": true,
  "listing": {
    "id": 4213,
    "title": "لپ‌تاپ ایسوس Vivobook 15",
    "description": "لپ‌تاپ در حد نو...",
    "status": "active",
    "review_status": "approved",
    "category_id": 12,
    "category": "لپ‌تاپ",
    "condition": "like_new",
    "listing_mode": "both",
    "estimated_value": 30000000,
    "sell_price": 28500000,
    "city": "تهران",
    "neighborhood": "ونک",
    "latitude": 35.6892,
    "longitude": 51.3890,
    "images": [
      { "id": 101, "url": "...", "is_primary": true },
      { "id": 102, "url": "...", "is_primary": false }
    ],
    "created_at": "2023-10-27 10:00:00",
    "url": "https://swaapin.ir/listings/view?id=4213"
  }
}
```

---

## ۶) ویرایش محصول (Edit)

```
PUT /api/v1/store/listings/{id}
```
یا
```
PATCH /api/v1/store/listings/{id}
```

**نکات مهم:**
- این عملیات به‌صورت **Partial Update** عمل می‌کند؛ یعنی فقط فیلدهایی که ارسال می‌کنید به‌روز می‌شوند.
- در صورت تغییر فیلدهای اصلی (عنوان، توضیحات و ...)، وضعیت بازبینی محصول به `pending` تغییر می‌کند.
- برای مدیریت تصاویر در ویرایش، از فیلدهای زیر استفاده کنید:
    - `images[]`: افزودن تصاویر جدید (فایل).
    - `images_base64[]`: افزودن تصاویر جدید (base64).
    - `delete_image_ids`: لیست شناسه‌های تصاویری که باید حذف شوند (به‌صورت آرایه یا رشته جدا شده با کاما).

**نمونه درخواست (JSON):**
```json
{
  "title": "عنوان جدید محصول",
  "sell_price": 29000000,
  "delete_image_ids": [102]
}
```

---

## ۷) حذف محصول (Delete)

```
DELETE /api/v1/store/listings/{id}
```

**توضیح:**
این عملیات محصول را به‌صورت نرم (Soft Delete) حذف می‌کند. محصول در دیتابیس باقی می‌ماند اما وضعیت آن به `deleted` تغییر کرده و دیگر در لیست‌ها نمایش داده نمی‌شود.

**نمونه پاسخ:**
```json
{
  "ok": true,
  "id": 4213,
  "deleted": true
}
```

---

## ۸) پاسخ‌ها

### موفق — `200 OK` یا `201 Created`

```json
{
  "ok": true,
  "id": 4213,
  "store_id": 88,
  "url": "https://swaapin.ir/listings/view?id=4213"
}
```

### خطای اعتبارسنجی — `422 Unprocessable Entity`

```json
{
  "ok": false,
  "error": "validation",
  "fields": {
    "title": "عنوان باید حداقل ۵ کاراکتر باشد",
    "location": "انتخاب موقعیت روی نقشه الزامی است.",
    "neighborhood": "انتخاب محله الزامی است.",
    "images": "حداقل یک تصویر لازم است (images[] یا images_base64[])."
  }
}
```

### خطای کلید — `401 Unauthorized`

```json
{ "ok": false, "error": "invalid_api_key", "message": "کلید API نامعتبر یا غیرفعال است." }
```

### خطای آپلود تصویر — `422`

```json
{ "ok": false, "error": "upload_failed", "message": "آپلود تصاویر ناموفق بود." }
```

### مسیر اشتباه — `404`

```json
{ "ok": false, "error": "not_found", "message": "مسیر API یافت نشد." }
```

---

## ۹) نمونه کد (PHP)

```php
$payload = [
    'title'            => 'لپ‌تاپ ایسوس Vivobook 15',
    'category_id'      => 12,
    'description'      => 'لپ‌تاپ در حد نو، سلامت کامل، همراه با شارژر اصلی و جعبه.',
    'city'             => 'تهران',
    'latitude'         => 35.6892,
    'longitude'        => 51.3890,
    'neighborhood'     => 'ونک',
    'sell_price'       => 28500000,
    'listing_mode'     => 'both',
    'images_base64'    => [base64_encode(file_get_contents('asus-1.jpg'))],
];

$ch = curl_init('https://swaapin.ir/api/v1/store/listings');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'X-Api-Key: swk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
]);
$response = curl_exec($ch);
curl_close($ch);
echo $response;
```

---

## ۱۰) محدودیت‌ها و نکات مهم

- حداکثر تصویر در هر محصول: **۸ عدد**، هر تصویر حداکثر **۵ مگابایت**.
- فرمت‌های مجاز تصویر: `jpg`, `jpeg`, `png`, `webp`, `gif`.
- موقعیت جغرافیایی باید **داخل محدوده ایران** باشد و محله باید از محله‌های همان شهر انتخاب شود (مثل فرم ثبت آگهی معمولی).
- پس از ثبت، محصول ممکن است توسط سیستم بازبینی هوشمند بررسی شود.
- در صورت تعویض کلید در پنل ادمین، تمام درخواست‌های بعدی باید با کلید جدید ارسال شوند.
