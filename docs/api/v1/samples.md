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

# نمونه کدهای برنامه‌نویسی و JSON

در این بخش نمونه‌های واقعی برای تعامل با API فروشگاه ارائه شده است.

---

## 🐘 نمونه کد PHP (cURL)
ارسال یک محصول جدید با تصاویر به صورت Base64.

```php
<?php
$apiKey = 'swk_your_api_key_here';
$baseUrl = 'https://swaapin.ir/api/v1';

$payload = [
    'title' => 'گوشی سامسونگ S23 Ultra',
    'category_id' => 5,
    'description' => 'در حد نو، دارای گارانتی، ۱۲ گیگ رم و ۲۵۶ گیگ حافظه داخلی.',
    'city' => 'تهران',
    'neighborhood' => 'سعادت آباد',
    'latitude' => 35.789,
    'longitude' => 51.389,
    'sell_price' => 55000000,
    'condition' => 'like_new',
    'images_base64' => [
        'data:image/jpeg;base64,' . base64_encode(file_get_contents('path/to/image.jpg'))
    ]
];

$ch = curl_init($baseUrl . '/store/listings');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-Api-Key: ' . $apiKey
    ]
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($httpCode === 201) {
    $data = json_decode($response, true);
    echo "محصول با شناسه " . $data['id'] . " ثبت شد.";
} else {
    echo "خطا در ثبت: " . $response;
}
curl_close($ch);
```

---

## 🚀 نمونه کد JavaScript (Fetch)
دریافت لیست محصولات با استفاده از `async/await`.

```javascript
const API_KEY = 'swk_your_api_key_here';
const BASE_URL = 'https://swaapin.ir/api/v1';

async function getMyListings(page = 1) {
    try {
        const response = await fetch(`${BASE_URL}/store/listings?page=${page}`, {
            method: 'GET',
            headers: {
                'X-Api-Key': API_KEY,
                'Accept': 'application/json'
            }
        });

        const result = await response.json();

        if (response.ok) {
            console.log('محصولات شما:', result.items);
        } else {
            console.error('خطای API:', result.message);
        }
    } catch (error) {
        console.error('خطای شبکه:', error);
    }
}

getMyListings();
```

---

## 📦 نمونه‌های JSON

### بدنه درخواست ایجاد محصول (Create Request)
```json
{
  "title": "دوربین عکاسی Canon EOS R6",
  "category_id": 18,
  "description": "بدنه دوربین به همراه لنز ۲۴-۱۰۵، بسیار کم کارکرد.",
  "city": "اصفهان",
  "neighborhood": "مرداویج",
  "latitude": 32.654,
  "longitude": 51.667,
  "sell_price": 98000000,
  "images_base64": ["/9j/4AAQSkZJRgABAQ..."]
}
```

### پاسخ موفقیت‌آمیز (Success Response)
```json
{
  "ok": true,
  "id": 5012,
  "store_id": 88,
  "url": "https://swaapin.ir/listings/view?id=5012"
}
```

### نمونه پاسخ خطا (Error Response)
```json
{
  "ok": false,
  "error": "validation",
  "fields": {
    "title": "عنوان باید حداقل ۵ کاراکتر باشد",
    "images": "حداقل یک تصویر الزامی است."
  }
}
```
