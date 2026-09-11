# تحلیل فیچر: ربات تایید / رد خودکار آگهی‌ها (AI Auto-Moderation)

> **پروژه:** سواپین (Swaapin)
> **تاریخ:** 2026-09-11

---

## 1. وضعیت فعلی سیستم

### فایل‌های مرتبط

| فایل | توضیحات |
|------|---------|
| `listings/create.php` | آگهی با `review_status='pending'` ثبت می‌شود (خط 133) |
| `admin/listings.php` | جریان تایید دستی توسط ادمین |
| `includes/listing_validator.php` | اعتبارسنجی Rule-Based فعلی (کلمات ممنوعه، regex، لینک‌ها، طول متن) |
| `includes/admin.php` (خط 98-120) | تابع `admin_approve_listing` / `admin_reject_listing` |
| `includes/ai.php` | زیرساخت LLM = آماده (Groq + OpenRouter با Failover، Rate-Limit، JSON ساختاریافته) |
| `includes/ai_system_prompt.txt` | فعلاً فقط Chat / Pricing / Matching دارد — بخش Moderation وجود ندارد |

### جریان فعلی

```
کاربر ثبت آگهی می‌کند
    -> review_status='pending'
    -> ادمین به صورت دستی تأیید یا رد می‌کند
    -> در صورت تأیید: published_at + saved-search alerts + ISO matching فعال می‌شود
```

---

## 2. مزایا

- **[1] کاهش 40 تا 70 درصد بار کاری ادمین**
  - 80 درصد آگهی‌ها ساده و بدون مشکل هستند؛ AI آنها را رها می‌کند
  - ادمین فقط روی موارد مشکوک و پیچیده تمرکز می‌کند

- **[2] زمان انتشار فوری**
  - از چند ساعت منتظر شدن → چند دقیقه
  - تجربه کاربر به طرز چشمگیری بهتر می‌شود

- **[3] قابلیت اسکیل**
  - با رشد تعداد آگهی‌ها نیاز به استخدام ادمین جدید نیست

- **[4] یکپارچگی تصمیم‌گیری**
  - AI همه آگهی‌ها را با یک معیار بررسی می‌کند
  - خطای انسانی (خستگی، تعصب، غفلت) حذف می‌شود

---

## 3. ریسک‌ها و محدودیت‌ها (بحرانی)

### 🔴 RISK 1: False Positive (آگهی سالم رد شود) — شدت: بحرانی

**راهکار:**
- آستانه Auto-Reject خیلی بالا بگذاریم (`confidence >= 0.96`)
- همه موارد رد خودکار را در صف «بررسی مجدد انسان» قرار دهیم
- حداکثر 24 ساعت زمان برای درخواست بازنگری به کاربر بدهیم

### 🔴 RISK 2: False Negative (آگهی مسموم تایید شود) — شدت: بحرانی

**راهکار:**
- دسته‌های پرریسک را هرگز خودکار تایید نکن:
  - `real-estate` (املاک و املاک)
  - `vehicles` (خودرو و وسایل نقلیه)
  - `services` (خدمات)
  - `jobs` (استخدام و کاریابی)
- محدودیت تعداد آگهی در روز برای کاربر تازه‌کار (حداکثر 5 عدد)

### 🟡 RISK 3: هزینه API — شدت: متوسط

**تخمین:**
- 500 آگهی در روز ≈ 500K token در ماه
- مدل Llama 3.3 70B ≈ 1 تا 2 دلار در ماه

**راهکار:**
- صف دسته‌جمعی و استفاده بهینه از Prompt
- لایه Rule-Base قبل از LLM (80 درصد موارد بدون API حل می‌شود)

### 🟡 RISK 4: Attack Adversarial — شدت: متوسط

**راهکار:**
- هفته‌ای یک بار Bad words و Regex rules آپدیت شود
- Few-Shot Examples در داخل System Prompt
- مکانیزم Report توسط کاربران و فیدبک برای بهبود مدل

### 🟡 RISK 5: تاخیر در انتشار — شدت: متوسط

**راهکار:**
- حالت Async + Queue پردازش پس‌زمینه
- پیام روان برای کاربر: «آگهی شما در صف بررسی خودکار قرار گرفت و ظرف چند دقیقه منتشر می‌شود»

### 🟡 RISK 6: کمبود شفافیت برای کاربر — شدت: متوسط

**راهکار:**
- AI دلیل رد را خط به خط و مشخص به کاربر بگوید (نه پیام کلی)
- لینک «درخواست بررسی توسط ادمین» در صفحه رد شده

---

## 4. معماری پیشنهادی (سه لایه‌ای Funnel)

```
 ثبت آگهی توسط کاربر
      |
      v
 [لایه 1]  Rule-Based قاطع (فوری - 0 هزینه)
 ------------------------------------------
   + listing_validator فعلی
   + Price Sanity Check
       (قیمت > 10x میانگین همان دسته -> Flag)
   + حداقل تعداد عکس (اختیاری برای برخی دسته‌ها)
   + Duplicate Title برای همان کاربر در 24 ساعت گذشته
   + User Velocity Check
       (کاربر تازه‌کار با بیش از 5 آگهی در روز -> Flag)
 ------------------------------------------
 خروجی:
   CLEAR      -> به لایه 2 برای نهایی
   BLOCKED    -> مستقیم REJECT + دلیل برای کاربر
   SUSPICIOUS -> مستقیم ESCALATE انسان
      |
      v
 [لایه 2]  LLM AI تصمیم‌گیری اصلی
 ------------------------------------------
   + mode=moderation با Prompt تخصصی
   + خروجی JSON ساختاریافته:
 ------------------------------------------
      |
      v
 [لایه 3]  Threshold + Human-in-the-Loop
 ------------------------------------------
   قوانین تصمیم نهایی:
   A) decision=approved AND confidence >= 0.92
      AND دسته در لیست SAFE دسته‌ها است
      -> ✅ AUTO_APPROVE

   B) decision=rejected AND confidence >= 0.96
      AND unsafe_flags خالی است
      AND دسته در لیست AUTO_REJECT_ALLOW است
      -> ❌ AUTO_REJECT

   C) هر وضعیت دیگر
      -> 🛡️ ESCALATE
 ------------------------------------------
      |
      v
 ثبت نتیجه در دیتابیس + Audit Log + ارسال اطلاعیه به کاربر
```

### خروجی JSON ساختاریافته لایه 2

```json
{
  "decision": "approved|rejected|escalated",
  "confidence": 0.xx,
  "reason_codes": [
    "PRICE_UNREALISTIC",
    "CATEGORY_MISMATCH",
    "BAD_WORDS",
    "DUPLICATE_CONTENT",
    "SCAM_PATTERN",
    "ADULT_CONTENT",
    "COUNTERFEIT_GOODS",
    "INSUFFICIENT_INFO"
  ],
  "review_note": "...متن فارسی که برای کاربر نمایش داده می‌شود...",
  "unsafe_flags": ["scam", "adult", "counterfeit", "weapon", "drug"],
  "rule_signals": {
    "bad_words_found": [],
    "links_count": 0,
    "price_anomaly": false,
    "text_quality": 0.xx,
    "description_vs_title_ratio": 1.8
  }
}
```

### Failover لایه 2

- اگر API در دسترس نباشد → Rule-only نتیجه بده
- وضعیت را در صف «تلاش مجدد» قرار بده (برای پردازش بعدی)

### لایه 3 — دسته‌های Never Auto-Approve

- `real-estate` / `vehicles` / `services` / `jobs`

---

## 5. تغییرات فنی مورد نیاز در پروژه

### 5.1 مایگریشن دیتابیس (جدول listings)

افزودن ستون‌های جدید:

| ستون | نوع | توضیحات |
|------|-----|---------|
| `auto_moderation_queued_at` | `DATETIME NULLABLE` | زمان ورود به صف پردازش |
| `auto_moderated_at` | `DATETIME NULLABLE` | زمان پردازش توسط AI |
| `auto_moderation_result` | `ENUM('approved','rejected','escalated') NULL` | نتیجه نهایی AI |
| `auto_moderation_confidence` | `TINYINT UNSIGNED NULL` | میزان اطمینان (0 تا 100) |
| `auto_moderation_provider` | `VARCHAR(20) NULL` | `rules` / `groq` / `openrouter` / `fallback` |
| `auto_moderation_data` | `JSON NULL` | خروجی خام AI برای Audit و Debug |
| `auto_moderation_review_note` | `TEXT NULL` | دلیل رد یا تأیید برای نمایش به کاربر |

**جدول جدید اختیاری: `listing_moderation_audit`**

`id`, `listing_id`, `moderator_type ('human'|'ai')`, `decision`, `confidence`, `provider`, `raw_payload JSON`, `created_at`, `reviewed_by_admin_id (NULL برای AI)`

---

### 5.2 فایل جدید: `includes/ai_moderator.php`

| تابع | توضیحات |
|------|---------|
| `rules_moderate_listing(array $listing, array $images = []): array` | لایه 1 Rule-Based |
| `ai_moderate_listing(array $listing, array $images = []): array` | تماس با LLM + prompt مناسب + ساخت خروجی استاندارد |
| `auto_moderate_listing(int $listingId): array` | orchestrator: لایه 1 + لایه 2 + لایه 3 (Threshold) + اعمال تصمیم نهایی |
| `auto_moderation_enqueue(int $listingId): void` | علامت زدن queued برای پردازش بعدی در صف |
| `get_safe_auto_approve_categories(): array` | لیست سفید دسته‌هایی که اجازه Auto-Approve دارند (الکترونیک، خانه آشپزخانه، اوقات فراغت، شخصی، ابزار، جامعه) |
| `get_safe_auto_reject_categories(): array` | لیست دسته‌هایی که اجازه Auto-Reject دارند (معمولا همه) |

---

### 5.3 آپدیت `ai_system_prompt.txt`

افزودن بخش جدید:

```
## 4. Moderation Engine Mode

WHEN mode=moderation:
  You are the Persian content moderator AI for a barter marketplace.
  Your job is to APPROVE, REJECT, or ESCALATE user-created listings.

STRICT RULES:
  R1. You MUST respond in JSON with the exact schema defined for
      mode=moderation.
  R2. confidence is between 0.0 and 1.0.
  R3. If any unsafe_flag is true -> confidence for REJECT must be
      >= 0.96.
  R4. Category MUST match one of the platform categories exactly.
  R5. Never APPROVE real-estate, vehicles, services, or jobs.
  R6. review_note MUST be in clear, kind Persian suitable for end
      user.
  R7. If data is insufficient -> decision=escalated with confidence
      0.50 + reason "اطلاعات آگهی کافی نیست".

SCORING GUIDELINES:
  0.95-1.00 -> CLEAR CUT (text is crystal clear, no red flags)
  0.85-0.94 -> HIGH CONFIDENCE (minor issues only like typos)
  0.70-0.84 -> MEDIUM CONFIDENCE (needs human glance)
  0.00-0.69 -> LOW CONFIDENCE (escalate for sure)

OUTPUT FORMAT:
  {"type":"moderation","decision":"approved|rejected|escalated",
   "confidence":0.xx,
   "reason_codes":[...],
   "review_note":"...Persian...",
   "unsafe_flags":[...],
   "rule_signals":{...}}

FEW-SHOT EXAMPLES:
  [Example 1 - Approved Electronics]
  [Example 2 - Rejected Adult Content]
  [Example 3 - Escalated Suspicious Price]
  [Example 4 - Escalated Real Estate]
```

---

### 5.4 فعال‌سازی در `listings/create.php`

بعد از INSERT آگهی جدید:

```php
if (defined('AUTO_MODERATION_ENABLED') && AUTO_MODERATION_ENABLED) {
    // گزینه A: Sync   (منتظر می‌ماند تا LLM پردازش کند)
    //   auto_moderate_listing($listingId);

    // گزینه B: Async  (صف پس‌زمینه - پیشنهادی برای Production)
    auto_moderation_enqueue($listingId);
}
```

---

### 5.5 صف پردازش Async (پیشنهادی برای Production)

**فایل جدید:** `tools/run_listing_moderation_queue.php`

- تمام آگهی‌هایی که `auto_moderation_queued_at` تنظیم شده و `auto_moderated_at` خالی است را می‌گیرد (LIMIT 20 در هر اجرا)
- برای هر کدام `auto_moderate_listing` را صدا می‌زند
- در خطا 3 بار Retry با فاصله نمایی

**Cron job هر 1 دقیقه:**

```bash
* * * * *  php /var/www/swaapin/tools/run_listing_moderation_queue.php >> /var/log/swaapin-moderation.log 2>&1
```

---

### 5.6 آپدیت پنل ادمین `admin/listings.php`

#### فیلترهای جدید

- `filter=ai_suggested_approved`
- `filter=ai_suggested_rejected`
- `filter=ai_escalated`

#### ستون جدید در لیست آگهی‌ها

ستون «پیشنهاد AI» با:
- میزان اطمینان به صورت درصدی
- رنگ‌بندی: سبز (>=92) / زرد (70-91) / قرمز (<70)
- آیکن: تأیید ✅ / رد ❌ / نیاز به انسان 🛡️

#### پنل جدید در صفحه جزئیات آگهی (بالای فرم‌های تأیید/رد)

پنل «خروجی ربات خودکار» شامل:
- نتیجه AI + میزان اطمینان
- دلیل‌ها (Reason Codes) با ترجمه فارسی
- سیگنال‌ها (تعداد لینک، قیمت غیرعادی، نسبت عنوان/توضیحات)
- Flagهای خطر (Unsafe)
- دکمه سریع: «اعمال پیشنهاد AI» (یک کلیک)

---

### 5.7 تنظیمات در `includes/config.php` / `.env`

| تنظیم | مقدار پیشنهادی | توضیحات |
|-------|---------------|---------|
| `AUTO_MODERATION_ENABLED` | `true` | (یا در فاز اول `false`) |
| `AUTO_MODERATION_MODE` | `'shadow' \| 'enforce'` | فاز اول `shadow` |
| `AUTO_MODERATION_APPROVE_MIN` | `92` | از 0 تا 100 |
| `AUTO_MODERATION_REJECT_MIN` | `96` | از 0 تا 100 |
| `AUTO_MODERATION_MAX_BATCH` | `20` | حداکثر آگهی در هر batch |
| `AUTO_MODERATION_USER_DAILY_LIMIT` | `5` | برای کاربران بدون سابقه |

---

## 6. فازبندی پیاده‌سازی (پیشنهاد جدی)

### 🚧 Phase 0: SHADOW MODE (حداقل 1 هفته - 2 هفته ایده‌آل)

- AI همه آگهی‌ها را بررسی می‌کند
- خروجی در دیتابیس ذخیره می‌شود
- **تأثیری روی review_status واقعی ندارد**
- در پنل ادمین نمایش داده می‌شود (پیشنهاد AI + درصد اطمینان)
- ادمین به صورت عادی تأیید می‌کند ولی خروجی AI مقایسه می‌شود

**خروجی این فاز:**
- اندازه‌گیری دقت واقعی AI روی داده‌های سواپین
- Confusion Matrix: TP / FP / TN / FN
- در هر دسته دقت مجزا چقدر است؟
- آیا آستانه‌های 92/96 مناسب هستند یا باید تغییر کند؟

---

### ✅ Phase 1: AUTO-APPROVE فقط (2 هفته)

- فقط آگهی‌هایی که AI با `confidence >= 0.92` تأیید می‌کند **AND** دسته در لیست SAFE است → خودکار منتشر می‌شود
- همه بقیه موارد (rejected پیشنهادی + escalated) → انسان بررسی می‌کند

**انتظار کاهش بار کاری:** 30 تا 40 درصد

---

### ❌ Phase 2: AUTO-REJECT موارد قطعی (2 هفته)

- فقط موارد واضح ممنوع با:
  - `confidence >= 0.96`
  - **AND** `unsafe_flags` خالی (غیر از موارد فوق‌العاده قطعی)
- → خودکار رد می‌شود + دلیل برای کاربر نمایش داده می‌شود
- تمام موارد Auto-Rejected در صف بررسی مجدد انسان قرار می‌گیرند (حداکثر 72 ساعت فرصت بازنگری)

**انتظار کاهش بار کاری:** 50 تا 60 درصد

---

### 🛡️ Phase 3: FULL HITL + بهینه‌سازی (مستمر)

- صف Escalation کامل فعال
- Dashboard آمار:
  - درصد FP داشتیم؟
  - دقت در هر دسته چقدر است؟
  - میانگین زمان انتشار چقدر است؟
  - چند درصد تصمیم AI توسط انسان نادیده گرفته می‌شود؟
- بهینه‌سازی آستانه‌ها و Rules بر اساس داده‌های واقعی

**انتظار نهایی کاهش بار کاری:** 60 تا 75 درصد

---

## 7. آستانه‌های پیشنهادی اولیه

| پارامتر | مقدار | توضیحات |
|---------|--------|---------|
| `MIN_CONFIDENCE_AUTO_APPROVE` | `0.92` | (یا 92 از 100) |
| `MIN_CONFIDENCE_AUTO_REJECT` | `0.96` | (یا 96 از 100 - سخت‌گیرانه‌تر) |
| **Never Auto-Approve Categories** | `real-estate`, `vehicles`, `services`, `jobs` | |
| `MAX_NEW_USER_LISTINGS_PER_DAY` | `5` عدد | کاربران با کمتر از 3 آگهی تأیید شده قبلی |
| `PRICE_ANOMALY_RATIO` | `10` برابر میانگین همان دسته | بیشتر از این مقدار → Flag + Escalate |
| `MIN_DESCRIPTION_TO_TITLE_RATIO` | `1.5` | طول توضیحات باید حداقل 1.5 برابر طول عنوان باشد |
| `MAX_LINKS_IN_DESCRIPTION` | `2` عدد | بیشتر از این → Reject یا Escalate |

---

## 8. معیارهای سنجش موفقیت (KPIs)

| KPI | هدف فاز ۱ | هدف نهایی فاز ۳ |
|-----|-----------|------------------|
| درصد آگهی بدون دخالت انسان | 40% | 70% |
| نرخ False Positive (آگهی سالم رد شد) | < 3% | < 1% |
| نرخ False Negative (آگهی مسموم تایید شد) | < 2% | < 0.5% |
| متوسط زمان تا انتشار | < 5 دقیقه | < 1 دقیقه |
| درصد تصمیم AI تأیید شده توسط انسان | > 80% | > 95% |
| تعداد دفعات نادیده گرفتن AI توسط انسان | < 20% | < 5% |
| میانگین زمان تأیید توسط ادمین (برای موارد Escalated) | < 4 ساعت | < 1 ساعت |

---

## 9. نظر نهایی و پیشنهاد

این فیچر کاملاً قابل پیاده‌سازی و بسیار مفید است؛ زیرا:

- ✅ زیرساخت LLM (`ai.php` + Groq + OpenRouter) کاملاً آماده است
- ✅ توابع `admin_approve_listing` و `admin_reject_listing` موجود هستند
- ✅ `listing_validator` فعلی نقطه شروع خوبی برای لایه 1 است

---

### ⚠️ مهم‌ترین توصیه

> **هرگز مستقیم با حالت Enforce شروع نکنید.**
> حتماً با Phase 0 (Shadow Mode) شروع کنید تا:
> - دقت واقعی AI روی داده‌های واقعی سواپین سنجیده شود
> - آستانه‌ها قبل از فعال شدن واقعی تنظیم گردند
> - ریسک‌های FP و FN حداقل شوند

---

### ⚠️ نکته دوم

> **هرگز روی دسته‌های پرریسک (املاک، خودرو، خدمات، استخدام) Auto-Approve فعال نکنید.**
> این دسته‌ها را مستقیماً به صف ادمین بفرستید.

---

### ⚠️ نکته سوم

> همیشه Human-in-the-Loop به عنوان لایه آخر باقی بماند.
> حتی در فاز نهایی هم مسیر «درخواست بررسی توسط ادمین» برای کاربران باز باشد.

---

*پایان مستندات*
