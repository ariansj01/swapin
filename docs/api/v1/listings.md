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

# مدیریت محصولات (Store Listings)

این بخش جزئیات عملیات CRUD (ایجاد، خواندن، به‌روزرسانی و حذف) محصولات فروشگاه را شرح می‌دهد.

---

## ۱. ثبت محصول جدید (Create)
ثبت یک محصول جدید به همراه تصاویر و مشخصات فنی.

- **مسیر:** `POST /store/listings`
- **نوع محتوا:** `application/json` یا `multipart/form-data`

### پارامترهای ارسالی:
| پارامتر | نوع | الزامی | توضیح |
| :--- | :--- | :--- | :--- |
| `title` | String | بله | عنوان محصول (۵ تا ۲۰۰ کاراکتر) |
| `category_id` | Integer | بله | شناسه دسته‌بندی معتبر |
| `description` | String | بله | توضیحات کامل (حداقل ۲۰ کاراکتر) |
| `city` | String | بله | نام شهر (مطابق لیست سیستم) |
| `neighborhood` | String | بله | نام محله |
| `latitude` | Float | بله | عرض جغرافیایی |
| `longitude` | Float | بله | طول جغرافیایی |
| `images_base64` | Array | خیر | آرایه‌ای از تصاویر به صورت Base64 (در صورت استفاده از JSON) |
| `sell_price` | Float | خیر | قیمت فروش نقدی به تومان |
| `estimated_value` | Integer | خیر | ارزش تخمینی جهت معاوضه |
| `condition` | String | خیر | وضعیت کالا: `new`, `like_new`, `good`, `fair`, `poor` |
| `listing_mode` | String | خیر | حالت آگهی: `swap`, `sell`, `both` (پیش‌فرض) |

---

## ۲. دریافت لیست محصولات (List)
دریافت محصولات ثبت شده توسط فروشگاه جاری.

- **مسیر:** `GET /store/listings`

### پارامترهای فیلتر (Query Params):
- `page`: شماره صفحه (پیش‌فرض: ۱)
- `limit`: تعداد در هر صفحه (حداکثر ۵۰)
- `status`: وضعیت محصول (`active`, `archived`)
- `review_status`: وضعیت بازبینی (`pending`, `approved`, `rejected`)
- `q`: جستجو در عنوان و توضیحات

---

## ۳. مشاهده جزئیات محصول (Read)
دریافت تمامی اطلاعات یک محصول خاص با استفاده از شناسه.

- **مسیر:** `GET /store/listings/{id}`

---

## ۴. ویرایش محصول (Update)
به‌روزرسانی اطلاعات محصول موجود. این عملیات به صورت **Partial Update** انجام می‌شود.

- **مسیر:** `PUT /store/listings/{id}` یا `PATCH /store/listings/{id}`
- **نکات:**
    - فقط فیلدهایی که ارسال می‌شوند به‌روزرسانی خواهند شد.
    - برای حذف تصاویر قبلی، شناسه‌های آن‌ها را در `delete_image_ids` ارسال کنید.
    - تغییر فیلدهای اصلی باعث انتقال وضعیت محصول به `pending` (در انتظار بازبینی مجدد) می‌شود.

---

## ۵. حذف محصول (Delete)
حذف محصول از لیست نمایش عمومی.

- **مسیر:** `DELETE /store/listings/{id}`
- **توضیح:** این حذف به صورت **Soft Delete** است؛ محصول در دیتابیس باقی می‌ماند اما وضعیت آن به `deleted` تغییر می‌کند.
