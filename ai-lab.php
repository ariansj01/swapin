<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/ai_demo.php';

$user = auth_user();
$metaTitle = 'آزمایشگاه هوش مصنوعی سواَپین';
$metaDesc = 'نمایش تعاملی تمام قابلیت‌های هوش مصنوعی سواَپین: بررسی آگهی، ارزش‌گذاری، تطبیق معاوضه، جستجوی نیاز و دستیار گفتگو — بدون ذخیره در پایگاه داده.';
$canonical = APP_URL . '/ai-lab';

$jsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'WebPage',
    'name' => $metaTitle,
    'description' => $metaDesc,
    'url' => $canonical,
    'publisher' => [
        '@type' => 'Organization',
        'name' => APP_NAME,
        'logo' => ['@type' => 'ImageObject', 'url' => LOGO_URL],
    ],
];

render_head($metaTitle, $metaDesc, [
    'canonical' => $canonical,
    'og_type'   => 'website',
    'json_ld'   => $jsonLd,
]);
render_navbar($user);

$cats = ai_demo_categories();
$conds = ['new', 'like_new', 'good', 'fair', 'poor'];

function ailab_cat_options(array $cats, string $selected = 'electronics'): string {
    $html = '';
    foreach ($cats as $c) {
        $sel = $c['slug'] === $selected ? ' selected' : '';
        $html .= '<option value="' . h($c['slug']) . '"' . $sel . '>' . h($c['name']) . '</option>';
    }
    return $html;
}

function ailab_cond_options(array $conds, string $selected = 'good'): string {
    $html = '';
    foreach ($conds as $c) {
        $sel = $c === $selected ? ' selected' : '';
        $html .= '<option value="' . h($c) . '"' . $sel . '>' . h(condition_label($c)) . '</option>';
    }
    return $html;
}
?>
<link rel="stylesheet" href="<?= APP_URL ?>/src/css/ai-lab.css?v=<?= @filemtime(__DIR__ . '/src/css/ai-lab.css') ?: time() ?>">

<section class="ailab-hero">
  <div class="ailab-hero__inner">
    <p class="ailab-hero__eyebrow">آزمایشگاه AI</p>
    <h1 style="color:white">هوش مصنوعی سواَپین را همین‌جا آزمایش کنید</h1>
    <p class="ailab-hero__lead">همان موتور واقعی تولید — بدون ذخیره در دیتابیس.</p>
    <div class="ailab-hero__row">
      <a class="ailab-hero__cta" href="#moderation">شروع</a>
      <span class="ailab-hero__safe">سندباکس</span>
    </div>
    <nav class="ailab-hero__links" aria-label="قابلیت‌ها">
      <a href="#moderation">بررسی آگهی</a>
      <a href="#pricing">ارزش‌گذاری</a>
      <a href="#matching">تطبیق</a>
      <a href="#swap">پیشنهاد نزدیک</a>
      <a href="#search">جستجو</a>
      <a href="#chat">گفتگو</a>
    </nav>
  </div>
</section>

<nav class="ailab-toc" aria-label="بخش‌های آزمایشگاه">
  <div class="container-md">
    <div class="ailab-toc__inner">
      <a href="#moderation">بررسی آگهی</a>
      <a href="#pricing">ارزش‌گذاری</a>
      <a href="#matching">تطبیق معاوضه</a>
      <a href="#swap">پیشنهاد نزدیک</a>
      <a href="#search">جستجوی نیاز</a>
      <a href="#chat">دستیار گفتگو</a>
    </div>
  </div>
</nav>

<main id="main-content">

  <section class="ailab-section" id="moderation">
    <div class="container-md ailab-grid">
      <div class="ailab-copy">
        <div class="ailab-kicker"><i class="bi bi-robot"></i> ربات نظارت محتوا</div>
        <h2>بررسی آگهی قبل از انتشار</h2>
        <p>
          در واقعیت، بعد از ثبت آگهی وضعیت <code>pending</code> می‌شود و موتور سه‌لایه تصمیم می‌گیرد:
          قوانین فوری (کلمات نامناسب، تماس، لینک، متن کوتاه)، سپس مدل زبانی با پرامپت moderation،
          و در نهایت آستانه تأیید/رد. دسته‌های پرریسک مثل خودرو و املاک هرگز خودکار تأیید نمی‌شوند.
        </p>
        <ol class="ailab-steps">
          <li><span>۱</span><div>فرم را مثل ثبت آگهی پر کنید و «انتشار و بررسی» را بزنید.</div></li>
          <li><span>۲</span><div>لایه قوانین سیگنال‌ها را استخراج می‌کند (اسپم، تماس، تلاش کم).</div></li>
          <li><span>۳</span><div>اگر رد قطعی نباشد، LLM تصمیم <strong>approve / reject / escalate</strong> می‌دهد.</div></li>
          <li><span>۴</span><div>خروجی دقیقاً مثل کارت ادمین است؛ هیچ ردی در دیتابیس نوشته نمی‌شود.</div></li>
        </ol>
        <div class="ailab-code">
          <details>
            <summary>کد مهم: ادغام قوانین + LLM + آستانه</summary>
            <pre><?= h('function ai_mod_merge_results(...) {
  if (rule.reject && confidence >= auto_reject) return reject;
  if (llm.reject && high_confidence) return reject;
  if (category in never_approve) return escalate;
  if (llm.approve && safe_category && conf >= 0.93) return approve;
  return escalate;
}') ?></pre>
          </details>
          <details>
            <summary>خروجی JSON لایه مدل (type=moderation)</summary>
            <pre><?= h('{
  "type": "moderation",
  "decision": "approve|reject|escalate",
  "confidence": 0.0,
  "unsafe_flags": ["spam","contact_info"],
  "reason_codes": ["R004","R010"],
  "persian_note": "توضیح فارسی برای کاربر و ادمین"
}') ?></pre>
          </details>
        </div>
      </div>
      <div class="ailab-panel">
        <div class="ailab-panel__head">
          <h3><i class="bi bi-send-check"></i> فرم انتشار آزمایشی</h3>
          <span class="ailab-sandbox">بدون ذخیره</span>
        </div>
        <div class="ailab-panel__body">
          <form id="form-moderate">
            <?= csrf_field() ?>
            <div class="form-group">
              <label class="form-label" for="mod-title">عنوان</label>
              <input class="form-control" id="mod-title" name="title" required minlength="5"
                     value="مجموعه کتاب داستان نوجوان جلد سخت">
            </div>
            <div class="form-group">
              <label class="form-label" for="mod-desc">توضیحات</label>
              <textarea class="form-control" id="mod-desc" name="description" rows="4">چهار جلد کتاب داستان نوجوان، ترجمه فارسی، جلد سخت و تمیز. صفحات سالم است و برای مطالعه مناسب. معاوضه با کتاب مشابه در دسته کتاب.</textarea>
            </div>
            <div class="form-group">
              <label class="form-label" for="mod-want">در ازای چه می‌خواهید؟</label>
              <input class="form-control" id="mod-want" name="want_in_return" value="کتاب داستان یا رمان فارسی">
            </div>
            <div class="ailab-form-row">
              <div class="form-group">
                <label class="form-label" for="mod-cat">دسته‌بندی</label>
                <select class="form-control" id="mod-cat" name="category_slug"><?= ailab_cat_options($cats, 'book') ?></select>
              </div>
              <div class="form-group">
                <label class="form-label" for="mod-cond">وضعیت</label>
                <select class="form-control" id="mod-cond" name="condition"><?= ailab_cond_options($conds, 'good') ?></select>
              </div>
            </div>
            <div class="ailab-form-row">
              <div class="form-group">
                <label class="form-label" for="mod-val">ارزش تقریبی (تومان)</label>
                <input class="form-control" id="mod-val" name="estimated_value" type="number" value="2500000">
              </div>
              <div class="form-group">
                <label class="form-label" for="mod-city">شهر</label>
                <input class="form-control" id="mod-city" name="city" value="تهران">
              </div>
            </div>
            <p class="fs-xs" style="color:var(--text-muted)">پیش‌فرض روی آگهی سالم دسته امن است تا مسیر تأیید دیده شود. برای رد خودکار، شماره موبایل یا لینک تلگرام در متن بگذارید.</p>
            <button type="submit" class="btn btn-accent w-100"><i class="bi bi-play-fill"></i> انتشار و بررسی</button>
            <div class="ailab-loading" hidden><i class="bi bi-hourglass-split"></i> در حال بررسی آگهی…</div>
            <div class="ailab-result" hidden></div>
          </form>
        </div>
      </div>
    </div>
  </section>

  <section class="ailab-section" id="pricing">
    <div class="container-md ailab-grid">
      <div class="ailab-copy">
        <div class="ailab-kicker"><i class="bi bi-tag-fill"></i> موتور ارزش‌گذاری</div>
        <h2>تخمین ارزش کالا هنگام ثبت</h2>
        <p>
          همان جریانی که در ثبت آگهی بعد از تکمیل فرم اجرا می‌شود: مدل با mode=pricing محدوده تومان،
          اطمینان ۰ تا ۱ و دلیل فارسی برمی‌گرداند. اگر مدل در دسترس نباشد، fallback قاعده‌محور همان قالب را پر می‌کند.
        </p>
        <ol class="ailab-steps">
          <li><span>۱</span><div>عنوان، توضیح، دسته و وضعیت خوانده می‌شود.</div></li>
          <li><span>۲</span><div>در پروداکشن آگهی‌های مشابه دسته هم به کانتکست اضافه می‌شود؛ در دمو این بخش خالی است تا دیتابیس خوانده نشود.</div></li>
          <li><span>۳</span><div>خروجی به نزدیک‌ترین ۱۰۰ هزار تومان گرد و به صورت محدوده نمایش داده می‌شود.</div></li>
        </ol>
        <div class="ailab-code">
          <details open>
            <summary>خروجی JSON ارزش‌گذاری</summary>
            <pre><?= h('{
  "type": "pricing",
  "value_range": { "min": 10000000, "max": 14000000 },
  "confidence": 0.78,
  "reason": "توضیح فارسی بر اساس مشخصات کالا"
}') ?></pre>
          </details>
        </div>
      </div>
      <div class="ailab-panel">
        <div class="ailab-panel__head">
          <h3><i class="bi bi-graph-up-arrow"></i> ارزش‌گذاری هوشمند</h3>
          <span class="ailab-sandbox">بدون ذخیره</span>
        </div>
        <div class="ailab-panel__body">
          <form id="form-pricing">
            <?= csrf_field() ?>
            <div class="form-group">
              <label class="form-label" for="pr-title">عنوان کالا</label>
              <input class="form-control" id="pr-title" name="title" required minlength="5" value="آیفون ۱۳ پرو ۲۵۶ گیگ آبی سیرا">
            </div>
            <div class="form-group">
              <label class="form-label" for="pr-desc">توضیحات</label>
              <textarea class="form-control" id="pr-desc" name="description" rows="3">گوشی در حد نو، باتری سلامت بالا، جعبه و شارژر اصلی. بدون تعویض قطعه.</textarea>
            </div>
            <div class="ailab-form-row">
              <div class="form-group">
                <label class="form-label">دسته</label>
                <select class="form-control" name="category_slug"><?= ailab_cat_options($cats, 'electronics') ?></select>
              </div>
              <div class="form-group">
                <label class="form-label">وضعیت</label>
                <select class="form-control" name="condition"><?= ailab_cond_options($conds, 'like_new') ?></select>
              </div>
            </div>
            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-stars"></i> محاسبه ارزش</button>
            <div class="ailab-loading" hidden><i class="bi bi-hourglass-split"></i> در حال تحلیل کالا…</div>
            <div class="ailab-result" hidden></div>
          </form>
        </div>
      </div>
    </div>
  </section>

  <section class="ailab-section" id="matching">
    <div class="container-md ailab-grid">
      <div class="ailab-copy">
        <div class="ailab-kicker"><i class="bi bi-arrow-left-right"></i> موتور تطبیق</div>
        <h2>پیشنهاد معاوضه با چهار ستون امتیاز</h2>
        <p>
          تطبیق تولید روی آگهی‌های واقعی کاربر اجرا می‌شود. اینجا آگهی شما با چند کالای نمونه سنجیده می‌شود
          تا همان امتیازدهی دیده شود: همخوانی نیاز (~۳۴٪)، نزدیکی ارزش (~۲۴٪)، دسته (~۲۲٪)، احتمال موفقیت (~۲۰٪).
        </p>
        <div class="ailab-code">
          <details open>
            <summary>خروجی JSON تطبیق</summary>
            <pre><?= h('{
  "type": "matching",
  "matches": [{
    "listing_id": 9102,
    "score": 81,
    "trade_type": "direct",
    "reason": "همخوانی نیاز + نزدیکی ارزش"
  }]
}') ?></pre>
          </details>
        </div>
      </div>
      <div class="ailab-panel">
        <div class="ailab-panel__head">
          <h3>آگهی من در برابر کاندیداهای نمونه</h3>
          <span class="ailab-sandbox">کاندیدای ساختگی</span>
        </div>
        <div class="ailab-panel__body">
          <form id="form-matching">
            <?= csrf_field() ?>
            <div class="form-group">
              <label class="form-label">عنوان آگهی شما</label>
              <input class="form-control" name="title" required minlength="5" value="آیفون ۱۳ پرو ۲۵۶ گیگ">
            </div>
            <div class="form-group">
              <label class="form-label">توضیح</label>
              <textarea class="form-control" name="description" rows="2">گوشی سالم، می‌خواهم با لپ‌تاپ یا کنسول عوض کنم.</textarea>
            </div>
            <div class="form-group">
              <label class="form-label">چه می‌خواهید؟</label>
              <input class="form-control" name="want_in_return" value="لپ‌تاپ گیمینگ یا PS5">
            </div>
            <div class="ailab-form-row">
              <div class="form-group">
                <label class="form-label">دسته</label>
                <select class="form-control" name="category_slug"><?= ailab_cat_options($cats, 'electronics') ?></select>
              </div>
              <div class="form-group">
                <label class="form-label">ارزش (تومان)</label>
                <input class="form-control" type="number" name="estimated_value" value="35000000">
              </div>
            </div>
            <button type="submit" class="btn btn-primary w-100">رتبه‌بندی پیشنهادها</button>
            <div class="ailab-loading" hidden><i class="bi bi-hourglass-split"></i> در حال تطبیق…</div>
            <div class="ailab-result" hidden></div>
          </form>
        </div>
      </div>
    </div>
  </section>

  <section class="ailab-section" id="swap">
    <div class="container-md ailab-grid">
      <div class="ailab-copy">
        <div class="ailab-kicker"><i class="bi bi-geo-alt"></i> پیشنهادهای نزدیک</div>
        <h2>امتیاز مؤلفه‌ای معاوضه اطراف شما</h2>
        <p>
          مدل فقط سازگاری معاوضه، ارزش، دسته، مکان و اطمینان را می‌دهد.
          نمره نهایی را بک‌اند حساب می‌کند: حدود ۸۲٪ سازگاری معاوضه + ۱۸٪ مکان. مدل حق ندارد score نهایی بسازد.
        </p>
        <div class="ailab-code">
          <details>
            <summary>کد نمره نهایی بک‌اند</summary>
            <pre><?= h('final_score = round(
  swap_compatibility * 0.82
  + location_score * 0.18
);') ?></pre>
          </details>
          <details>
            <summary>خروجی JSON پیشنهاد نزدیک</summary>
            <pre><?= h('{
  "type": "swap_suggestions",
  "matches": [{
    "listing_id": 9101,
    "swap_compatibility": 84,
    "value_compatibility": 76,
    "category_compatibility": 90,
    "location_score": 70,
    "confidence": 80,
    "reasons": ["نیاز دوطرفه"]
  }]
}') ?></pre>
          </details>
        </div>
      </div>
      <div class="ailab-panel">
        <div class="ailab-panel__head">
          <h3>پیشنهاد اطراف (نمونه)</h3>
          <span class="ailab-sandbox">بدون geo واقعی</span>
        </div>
        <div class="ailab-panel__body">
          <form id="form-swap">
            <?= csrf_field() ?>
            <div class="form-group">
              <label class="form-label">عنوان</label>
              <input class="form-control" name="title" required minlength="5" value="ساعت اپل واچ سری ۸">
            </div>
            <div class="form-group">
              <label class="form-label">در ازای</label>
              <input class="form-control" name="want_in_return" value="هدفون یا اعتبار">
            </div>
            <div class="ailab-form-row">
              <div class="form-group">
                <label class="form-label">شهر شما</label>
                <input class="form-control" name="city" value="تهران">
              </div>
              <div class="form-group">
                <label class="form-label">ارزش</label>
                <input class="form-control" type="number" name="estimated_value" value="14000000">
              </div>
            </div>
            <input type="hidden" name="category_slug" value="watch">
            <input type="hidden" name="description" value="ساعت هوشمند سالم، می‌خواهم با هدفون نویزکنسلینگ عوض کنم.">
            <button type="submit" class="btn btn-primary w-100">محاسبه پیشنهادهای نزدیک</button>
            <div class="ailab-loading" hidden><i class="bi bi-hourglass-split"></i> در حال امتیازدهی مؤلفه‌ها…</div>
            <div class="ailab-result" hidden></div>
          </form>
        </div>
      </div>
    </div>
  </section>

  <section class="ailab-section" id="search">
    <div class="container-md ailab-grid">
      <div class="ailab-copy">
        <div class="ailab-kicker"><i class="bi bi-search-heart"></i> جستجوی هوشمند</div>
        <h2>نیاز را به زبان ساده بنویسید</h2>
        <p>
          مدل mode=need_search متن را به کلیدواژه، دسته، شهر و بازه قیمت تبدیل می‌کند.
          در سایت واقعی بعد از آن روی آگهی‌های فعال کوئری زده می‌شود. در آزمایشگاه همان فیلترها روی چند آگهی نمونه اعمال می‌شود تا چیزی ذخیره یا از دیتابیس خوانده نشود.
        </p>
        <div class="ailab-code">
          <details open>
            <summary>خروجی استخراج نیت جستجو</summary>
            <pre><?= h('{
  "type": "need_search",
  "keywords": ["لپتاپ", "گیمینگ"],
  "category_slug": "electronics",
  "city_hint": "تهران",
  "price_max": 30000000,
  "summary": "لپ‌تاپ گیمینگ در تهران"
}') ?></pre>
          </details>
        </div>
      </div>
      <div class="ailab-panel">
        <div class="ailab-panel__head">
          <h3>جستجو بر اساس نیازمندی</h3>
          <span class="ailab-sandbox">نتایج نمونه</span>
        </div>
        <div class="ailab-panel__body">
          <form id="form-search">
            <?= csrf_field() ?>
            <div class="form-group">
              <label class="form-label" for="need">چه می‌خواهید؟</label>
              <textarea class="form-control" id="need" name="need" rows="3" required minlength="3">لپ‌تاپ گیمینگ دست دوم در تهران تا ۴۰ میلیون</textarea>
            </div>
            <div class="form-group">
              <label class="form-label">شهر (اختیاری)</label>
              <input class="form-control" name="city" value="تهران">
            </div>
            <button type="submit" class="btn btn-accent w-100"><i class="bi bi-stars"></i> جستجوی هوشمند</button>
            <div class="ailab-loading" hidden><i class="bi bi-hourglass-split"></i> در حال فهم نیاز…</div>
            <div class="ailab-result" hidden></div>
          </form>
        </div>
      </div>
    </div>
  </section>

  <section class="ailab-section" id="chat">
    <div class="container-md ailab-grid">
      <div class="ailab-copy">
        <div class="ailab-kicker"><i class="bi bi-chat-dots-fill"></i> دستیار گفتگو</div>
        <h2>راهنمای ثبت، قوانین و تصمیم‌گیری</h2>
        <p>
          چت پروداکشن با همان system prompt موتور سواَپین کار می‌کند و فقط JSON نوع chat برمی‌گرداند.
          این باکس برای مهمان هم باز است و تاریخچه را فقط در مرورگر نگه می‌دارد.
        </p>
        <div class="ailab-code">
          <details open>
            <summary>قرارداد پاسخ چت</summary>
            <pre><?= h('{
  "type": "chat",
  "message": "پاسخ فارسی دستیار"
}') ?></pre>
          </details>
        </div>
      </div>
      <div class="ailab-panel">
        <div class="ailab-panel__head">
          <h3>گفتگو با دستیار</h3>
          <span class="ailab-sandbox">بدون لاگ کاربر</span>
        </div>
        <div class="ailab-panel__body">
          <div class="ailab-chat" id="chat-log">
            <div class="ailab-msg ailab-msg--bot"><div>سلام، من دستیار سواَپین هستم. درباره ثبت آگهی، ارزش‌گذاری یا قوانین معامله بپرسید.</div></div>
          </div>
          <form id="form-chat">
            <?= csrf_field() ?>
            <div class="form-group">
              <input class="form-control" name="message" required maxlength="800" placeholder="مثلاً چطور عنوان بهتری برای آگهی بنویسم؟">
            </div>
            <button type="submit" class="btn btn-primary w-100">ارسال</button>
            <div class="ailab-loading" hidden><i class="bi bi-hourglass-split"></i> در حال پاسخ…</div>
          </form>
        </div>
      </div>
    </div>
  </section>

</main>

<script src="<?= APP_URL ?>/src/js/ai-lab.js?v=<?= @filemtime(__DIR__ . '/src/js/ai-lab.js') ?: time() ?>"></script>
<?php render_footer(); ?>
