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
  <img src="https://trae-us-1258162260.cos.us-east-1.myqcloud.com/production/01/1730098481358-1c46a6f6.jpg" alt="Swaapin Logo" width="200">
</p>

# مستندات API فروشگاه سواپین (نسخه ۱.۰)

به مستندات فنی API فروشگاه سواپین خوش آمدید. این رابط برنامه‌نویسی به شما اجازه می‌دهد تا محصولات فروشگاه خود را به‌صورت خودکار در پلتفرم سواپین مدیریت کنید.

---

## 🚀 راهنمای شروع سریع (Quick Start)

برای شروع استفاده از API، مراحل زیر را دنبال کنید:

1.  **ورود به حساب کاربری:** وارد پنل کاربری خود در سواپین شوید.
2.  **ثبت درخواست فروشگاه:** فرم ثبت‌نام و اطلاعات فروشگاه را به‌طور کامل تکمیل و ارسال کنید.
3.  **تایید و احراز هویت:** پس از بررسی و تایید توسط تیم پشتیبانی، وضعیت فروشگاه شما به «تایید شده» تغییر می‌کند.
4.  **دریافت کلید API:** پس از تایید، کلید اختصاصی API توسط تیم فنی سواپین در اختیار شما قرار خواهد گرفت.
5.  **تست اتصال:** از نمونه کدهای موجود در بخش [نمونه کدها](./samples.md) برای اولین فراخوانی استفاده کنید.

---

## 🌍 آدرس پایه (Base URL)

تمامی درخواست‌ها باید به آدرس زیر ارسال شوند:

```text
https://swaapin.ir/api/v1
```

---

## 🔑 احراز هویت (Authentication)

احراز هویت در تمامی اندپوینت‌های فروشگاه الزامی است. شما باید کلید API خود را در هدر درخواست ارسال کنید.

### روش پیشنهادی (Custom Header)
```http
X-Api-Key: swk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

### روش استاندارد (Bearer Token)
```http
Authorization: Bearer swk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

---

## 📑 فهرست اندپوینت‌ها (Endpoints)

| متد | مسیر | توضیح |
| :--- | :--- | :--- |
| `POST` | `/store/listings` | ثبت محصول جدید |
| `GET` | `/store/listings` | دریافت لیست محصولات فروشگاه |
| `GET` | `/store/listings/{id}` | مشاهده جزئیات یک محصول |
| `PUT/PATCH` | `/store/listings/{id}` | ویرایش محصول موجود |
| `DELETE` | `/store/listings/{id}` | حذف (غیرفعال‌سازی) محصول |

---

## 🔗 ابزارهای کمکی

- **Swagger UI:** برای تست آنلاین و مشاهده دقیق مدل‌ها از لینک زیر استفاده کنید:
  [Swaapin Store API on SwaggerHub](https://app.swaggerhub.com/apis-docs/swapin-9ac/swaapin-store-api/1.0.0)
- **فهرست دسته‌بندی‌ها:** برای دریافت `category_id` معتبر: `GET /categories`
- **فهرست شهرها:** برای دریافت نام دقیق شهرها: `GET /cities`

---

## 📘 بخش‌های مستندات

- [مدیریت محصولات (CRUD)](./listings.md)
- [نمونه کدهای برنامه نویسی](./samples.md)
- [ساختار خطاها و پاسخ‌ها](./errors.md)
