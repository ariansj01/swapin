# مرور مستقل: فرم تخمین قیمت در چت AI

## اطلاعات پیاده‌سازی
- فایل‌های اصلاح‌شده نهایی:
  1. [chat.php](file:///c:/xampp/htdocs/swaapin/ai/chat.php#L76-L204) — فرم sidebar + csrf_field + id‌های صحیح loading spans
  2. [create.php](file:///c:/xampp/htdocs/swaapin/listings/create.php#L40-L60) — query prefill (title/desc/cat/cond/value + `vals['estimated_value']`)
  3. [ai.php](file:///c:/xampp/htdocs/swaapin/includes/ai.php#L332-L550) — helpers + fallback ترکیبی + fallback چت
  4. [app.js](file:///c:/xampp/htdocs/swaapin/src/js/app.js#L984-L1192) — IIFE منطق فرم sidebar
  5. [ai_system_prompt.txt](file:///c:/xampp/htdocs/swaapin/includes/ai_system_prompt.txt#L20-L27) — خط رفتاری Chat Assistant Mode

## تاریخچه مرور (Review History)

### Cycle 1 — ۲۰۲۶-۰۹-۳۰ (نتیجه: `fail` — ۳ یافته قابل اقدام)

**یافته‌های اصلی (همگی رفع‌شده در Cycle 2):**
| سطح | شرح | رفع |
|---|---|---|
| CRITICAL | Not OK: `class=val-btn-label`/`val-btn-loading` در HTML ولی `getElementById` در JS → loading state broken | ✅ افزودن `id="val-btn-label"` و `id="val-btn-loading"` در chat.php خطوط 154-155 |
| HIGH (Security) | فرم فیلد hidden CSRF نداشت | ✅ افزودن `<?= csrf_field() ?>` داخل form در chat.php خط 126 |
| LOW (clarity) | prefill فقط `$suggestedValue` را ست می‌کرد نه `$vals['estimated_value']` | ✅ `$vals['estimated_value'] = $suggestedValue` در create.php خط 59 اضافه شد |

**امتیازات رابریکس Cycle 1 (قبل از رفع CRITICAL):**
- AC-8 دقت: 2 / 3 (pass threshold ≥ 2 → پایدار بود)
- AC-9 تجربه کاربری: 1 / 3 (پایین‌تر از حد نصاب بود → دلیل: loading broken)
- AC-10 یکپارچگی با چت: 2 / 2 (تأیید شد)
- TR-6.2 ظاهر: 1 / 3 (پایین‌تر از حد نصاب بود → دلیل loading broken)

---

### Cycle 2 — ۲۰۲۶-۰۹-۳۰ (پس از رفع یافته‌ها؛ نتیجه نهایی: `pass`)

#### Rule AC ها (همه = pass)

| AC | وضعیت | شواهد |
|---|---|---|
| AC-1 POST به api/ai_valuate.php | ✅ pass | FormData شامل title,description,condition,category_id + _csrf (هم از meta و هم از hidden field در فرم با csrf_field — dual guarantee). id‌های loading صحیح هستند. |
| AC-2 نمایش همه فیلدهای نتیجه | ✅ pass | data-result برای value_fmt, range_fmt, confidence, reasons, note mapping در UI |
| AC-3 مدیریت خطا/rate_limited | ✅ pass | rate_limited پیام فارسی + inline error box |
| AC-4 هدایت به create + prefill | ✅ pass | URLSearchParams: prefill_title/desc/cat/cond/value → سمت سرور clean/cast/whitelist + direct assignment به vals['estimated_value'] |
| AC-5 Fallback بدون LLM | ✅ pass | ai_price_listing_fallback با ترکیب 70% میانگین دسته + 30% seed (اگر catId معتبر داشته باشد) |
| AC-6 حداقل 3 آگهی مشابه + ذکر در reasons | ✅ pass | limit=10 در ai_fetch_similar_listings + "مقایسه با X آگهی مشابه" به reasons افزوده می‌شود |
| AC-7 کاربر مهمان فرم را نمی‌بیند | ✅ pass | فرم داخل `else`ِ `if (!$user)` است — خارج از DOM مهمان |

#### Rubric AC ها (همه بالاتر از آستانه)

| AC | امتیاز | آستانه | وضعیت | دلیل |
|---|---|---|---|---|
| AC-8 دقت قیمت‌گذاری (0-3) | 2 | ≥ 2 | ✅ pass | 10 similar + category_stats + blended fallback. زیرساخت کافی برای ±15%. |
| AC-9 تجربه کاربری فرم (0-3) | 2 | ≥ 2 | ✅ pass | loading state now functional; validation پیام فارسی; ⚠ برای اطمینان پایین; minDelay 2800ms; escHtml ایمن everywhere. (Validation لحظه‌ای (real-time) نداشته ولی کافی است.) |
| AC-10 یکپارچگی با چت (0-2) | 2 | ≥ 1 | ✅ pass | scroll + pulse chip; appendBotMsg به تاریخچه; ai_chat_fallback + system prompt هدایت به sidebar. |
| TR-6.2 ظاهر/responsive (0-3) | 2 | ≥ 2 | ✅ pass | کلاس‌های هماهنگ wizard-form-* + card؛ loading functional; inline styles برای RTL بهانه قابل قبول است. |

#### SEO/Analytics (همه تیک)
- ✅ GTM در chat.php (صفحه عمومی کاربر = public page) فعال (از طریق `render_head`)
- ✅ GTM در هیچ صفحه ادمینی اضافه نشده (چون هیچ فایل ادمینی تغییر نکرد)
- ✅ No direct GA4/gtag (بدون تغییر)
- ✅ canonical در chat.php = `/ai/chat`؛ در create.php حفظ شده
- ✅ robots meta در chat.php همچنان noindex,nofollow (مناسب برای صفحه چت شخصی کاربر)
- ✅ JSON-LD / meta description حفظ شده (بدون تغییر در layout)

#### Security (همه تیک)
- ✅ **CSRF**: dual coverage — `<?= csrf_field() ?>` در فرم + fallback `appendCsrf()` / meta tag در JS
- ✅ **Rate limiting**: 40/hr IP + 3/900s user در api/ai_valuate.php
- ✅ **XSS**: escHtml + textContent برای همه user input به DOM
- ✅ **Injection**: clean() + (int) cast + whitelist برای condition + max(0,value)

---

## نتیجه نهایی Spec Mode: **PASS** ✅

همه ACهای rule پاس شدند. همه ACهای rubric بالاتر از threshold هایشان رفتند. ۳ یافته بحرانی/بالای Cycle 1 همگی رفع شدند. SEO و Analytics طبق AGENTS.md حفظ شده‌اند. امنیت تأیید شد. آماده استقرار در staging/production.
