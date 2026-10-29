<style>
  body {
    font-family: 'IRANSans', 'Tahoma', sans-serif;
    direction: rtl;
    text-align: right;
  }
  code, pre {
    font-family: 'Consolas', 'Monaco', monospace;
    direction: ltr;
    text-align: left;
  }
</style>

<p align="center">
  <img src="https://trae-us-1258162260.cos.us-east-1.myqcloud.com/production/01/1730098481358-1c46a6f6.jpg" alt="Swaapin Logo" width="250">
</p>

# مستندات کامل API فروشگاه سواپین
**نسخه ۱.۰.۰**

---

## ۱. مقدمه و راهنمای شروع سریع
این API به فروشگاه‌ها اجازه می‌دهد محصولات خود را به‌صورت خودکار در سواپین مدیریت کنند.

### مراحل راه‌اندازی:
1.  ورود به حساب کاربری و تکمیل فرم ثبت‌نام فروشگاه.
2.  بررسی و تایید درخواست توسط تیم پشتیبانی سواپین.
3.  دریافت کلید اختصاصی API از تیم فنی پس از تایید نهایی.
4.  ارسال کلید در هدر `X-Api-Key`.
5.  استفاده از آدرس پایه: `https://swaapin.ir/api/v1`

---

## ۲. احراز هویت
کلید باید در هدر درخواست ارسال شود:
`X-Api-Key: swk_...`

---

## ۳. اندپوینت‌های محصولات (CRUD)

### ثبت محصول (Create)
`POST /store/listings`
- پارامترها: `title`, `category_id`, `description`, `city`, `neighborhood`, `latitude`, `longitude`, `images_base64`.

### لیست محصولات (List)
`GET /store/listings`
- فیلترها: `page`, `limit`, `status`, `review_status`, `q`.

### مشاهده محصول (Read)
`GET /store/listings/{id}`

### ویرایش محصول (Update)
`PUT/PATCH /store/listings/{id}`
- پشتیبانی از Partial Update و مدیریت تصاویر (`delete_image_ids`).

### حذف محصول (Delete)
`DELETE /store/listings/{id}`
- حذف نرم (تغییر وضعیت به `deleted`).

---

## ۴. نمونه کدهای برنامه‌نویسی

### نمونه PHP (cURL)
```php
<?php
// ... کد کامل در مستندات آنلاین موجود است ...
```

### نمونه JavaScript (Fetch)
```javascript
// ... کد کامل در مستندات آنلاین موجود است ...
```

---

## ۵. خطاها و پاسخ‌ها
تمامی پاسخ‌ها شامل `ok: true/false` هستند. خطاهای اعتبارسنجی با کد `422` و جزئیات در فیلد `fields` بازگردانده می‌شوند.

---

**لینک سواگر:** [Swagger UI](https://app.swaggerhub.com/apis-docs/swapin-9ac/swaapin-store-api/1.0.0)
