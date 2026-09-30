<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

$user = auth_user();
$url  = APP_URL;

render_head('دستیار هوش مصنوعی | سواَپین', 'مشاوره معاوضه، ارزش‌گذاری و پیشنهاد هوشمند در سواَپین', [
    'canonical' => APP_URL . '/ai/chat',
    'robots'    => 'noindex, nofollow',
]);
render_navbar($user);
?>

<section class="ai-chat-page">
  <div class="container-md">

    <div class="ai-chat-page__header">
      <div class="ai-chat-page__badge"><i class="bi bi-stars"></i> هوش مصنوعی سواَپین</div>
      <h1>دستیار معاوضه هوشمند</h1>
      <p>ارزش کالا، پیشنهاد معاوضه و راهنمای معامله — با کمک AI</p>
    </div>

    <?php if (!$user): ?>
    <div class="ai-chat-guest">
      <div class="ai-chat-guest__preview">
        <div class="ai-chat-window ai-chat-window--blurred">
          <div class="ai-chat-window__head">
            <span class="ai-dot ai-dot--green"></span>
            <strong>دستیار سواَپین</strong>
          </div>
          <div class="ai-chat-window__body">
            <div class="ai-msg ai-msg--bot">
              <div class="ai-msg__avatar"><i class="bi bi-robot"></i></div>
              <div class="ai-msg__bubble">سلام! من دستیار معاوضه سواَپین هستم. می‌توانم ارزش کالای شما را تخمین بزنم و بهترین پیشنهادهای معاوضه را پیشنهاد دهم.</div>
            </div>
            <div class="ai-msg ai-msg--user">
              <div class="ai-msg__bubble">آیفون ۱۳ پرو ۲۵۶ گیگ — چه چیزی مناسب معاوضه است؟</div>
            </div>
            <div class="ai-msg ai-msg--bot">
              <div class="ai-msg__avatar"><i class="bi bi-robot"></i></div>
              <div class="ai-msg__bubble">با توجه به ارزش تقریبی، لپ‌تاپ گیمینگ یا PS5 گزینه‌های نزدیک به شما هستند…</div>
            </div>
          </div>
        </div>
        <div class="ai-chat-guest__lock">
          <i class="bi bi-lock-fill"></i>
          <h3>برای گفتگو با دستیار AI وارد شوید</h3>
          <p>این قابلیت فقط برای اعضای سواَپین فعال است.</p>
          <div class="ai-chat-guest__actions">
            <a href="<?= $url ?>/auth/login?redirect=<?= urlencode('/ai/chat') ?>" class="btn btn-accent btn-lg">
              <i class="bi bi-box-arrow-in-right"></i> ورود / ثبت‌نام
            </a>
          </div>
        </div>
      </div>

      <div class="ai-features-grid">
        <?php
        $features = [
            ['bi-calculator', 'ارزش‌گذاری هوشمند', 'بعد از ثبت کالا، AI ارزش تقریبی ' . CREDIT_UNIT . ' را پیشنهاد می‌دهد.'],
            ['bi-arrow-left-right', 'پیشنهاد معاوضه', 'بر اساس نیاز شما، گزینه‌های مناسب معاوضه را پیشنهاد می‌کند.'],
            ['bi-chat-heart', 'مشاوره معامله', 'سؤالات خود را درباره معاوضه امن بپرسید.'],
        ];
        foreach ($features as [$icon, $title, $desc]):
        ?>
        <article class="ai-feature-card">
          <div class="ai-feature-card__icon"><i class="bi <?= $icon ?>"></i></div>
          <h3><?= $title ?></h3>
          <p><?= $desc ?></p>
        </article>
        <?php endforeach; ?>
      </div>
    </div>

    <?php else: ?>
    <?php
    $categories = DB::fetchAll(
        'SELECT c.*, p.name AS parent_name FROM categories c
         LEFT JOIN categories p ON p.id = c.parent_id
         WHERE c.is_active = 1 ORDER BY COALESCE(p.sort_order,c.sort_order), c.sort_order'
    );
    $conditionOptions = [
        'new'      => 'نو',
        'like_new' => 'مثل نو',
        'good'     => 'خوب',
        'fair'     => 'متوسط',
        'poor'     => 'خورده',
    ];
    ?>
    <div class="ai-chat-layout" id="ai-chat-app">

      <!-- بخش اول: چت دستیار -->
      <section aria-label="چت دستیار سواَپین" style="margin-bottom:calc(var(--wizard-gap,20px) + var(--sp-5))">
        <div class="ai-chat-window">
          <div class="ai-chat-window__head">
            <span class="ai-dot ai-dot--green"></span>
            <strong>دستیار سواَپین</strong>
            <span class="ai-chat-window__status">آنلاین · دستیار هوشمند</span>
          </div>
          <div class="ai-chat-window__body" id="ai-chat-messages">
            <div class="ai-msg ai-msg--bot">
              <div class="ai-msg__avatar"><i class="bi bi-robot"></i></div>
              <div class="ai-msg__bubble">
                سلام <?= h(explode(' ', $user['name'])[0]) ?>! 👋<br>
                من دستیار معاوضه سواَپین هستم. می‌توانید درباره ارزش کالا، پیشنهاد معاوضه یا نحوه معامله امن سؤال بپرسید.
                <div class="ai-quick-chips">
                  <button type="button" class="ai-chip" data-prompt="چطور ارزش کالایم را تخمین بزنم؟" data-scroll-target="ai-valuation-section">ارزش‌گذاری کالا</button>
                  <button type="button" class="ai-chip" data-prompt="چه کالایی برای معاوضه با لپ‌تاپ من مناسب است؟">پیشنهاد معاوضه</button>
                  <button type="button" class="ai-chip" data-prompt="مراحل معامله امن در سواَپین چیست؟">معامله امن</button>
                  <a href="<?= $url ?>/dashboard#swap-matches" class="ai-chip" style="text-decoration:none;display:inline-flex;align-items:center">Matching Engine</a>
                </div>
              </div>
            </div>
          </div>
          <form class="ai-chat-window__input" id="ai-chat-form">
            <input type="text" id="ai-chat-input" placeholder="سؤال خود را بنویسید…" autocomplete="off" maxlength="500">
            <button type="submit" class="btn btn-accent" aria-label="ارسال">
              <i class="bi bi-send-fill"></i>
            </button>
          </form>
        </div>
      </section>

      <!-- بخش دوم: تخمین قیمت (زیر چت) -->
      <section id="ai-valuation-section" aria-label="تخمین قیمت کالا" style="margin-bottom:calc(var(--wizard-gap,20px) + var(--sp-4))">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:var(--sp-3)">
          <div style="width:4px;height:28px;border-radius:3px;background:linear-gradient(180deg,var(--primary),var(--accent-dark))"></div>
          <h2 style="font-size:1.3rem;margin:0;font-weight:800;color:var(--primary,#0A2540)">
            <i class="bi bi-calculator-fill" style="margin-left:8px;color:var(--accent-dark)"></i>
            تخمین قیمت کالا با AI
          </h2>
        </div>
        <div class="ai-valuation-box card" style="padding:0;overflow:hidden;border-radius:var(--wizard-radius,16px);box-shadow:0 10px 30px -15px rgba(10,37,64,.18)">
          <div style="padding:var(--wizard-gap,20px);background:linear-gradient(135deg,var(--primary,#0A2540),#0f3a66);color:#fff">
            <h3 style="font-size:1.2rem;margin:0 0 4px 0;font-weight:800">
              <i class="bi bi-stars" style="color:#FFD166;margin-left:8px"></i>
              تخمین هوشمند قیمت کالا بر اساس بازار داخلی سواَپین
            </h3>
            <p style="margin:0;opacity:.92;font-size:.95rem">مشخصات کالای خود را وارد کنید تا قیمت تقریبی آن بر اساس آگهی‌های تاییدشده و آمار دسته‌بندی محاسبه شود.</p>
          </div>

          <div style="padding:var(--wizard-gap,20px)">
            <form id="ai-valuation-form" method="post" style="display:flex;flex-direction:column;gap:var(--sp-4)">
              <?= csrf_field() ?>

              <div class="wizard-form-group">
                <label class="wizard-form-label" for="val-title">عنوان کالا *</label>
                <input type="text" id="val-title" name="title" class="wizard-form-input"
                       placeholder="مثلاً: آیفون ۱۳ پرو ۲۵۶ گیگ — تمیز با گارانتی" maxlength="200">
              </div>

              <div class="wizard-form-group">
                <label class="wizard-form-label" for="val-description">توضیحات کامل *</label>
                <textarea id="val-description" name="description" class="wizard-form-textarea" rows="4"
                          placeholder="سن، برند، مشخصات فنی، وضعیت بدنی، سلامت قطعات داخلی، ایرادات (اگر وجود دارد)…"></textarea>
              </div>

              <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-3)">
                <div class="wizard-form-group">
                  <label class="wizard-form-label" for="val-category">دسته‌بندی *</label>
                  <select id="val-category" name="category_id" class="wizard-form-select">
                    <option value="">انتخاب دسته‌بندی…</option>
                    <?php foreach ($categories as $cat): ?>
                      <?php $label = $cat['parent_name'] ? $cat['parent_name'] . ' › ' . $cat['name'] : $cat['name']; ?>
                      <option value="<?= (int)$cat['id'] ?>"><?= h($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="wizard-form-group">
                  <label class="wizard-form-label" for="val-condition">وضعیت کالا *</label>
                  <select id="val-condition" name="condition" class="wizard-form-select">
                    <?php foreach ($conditionOptions as $val => $lbl): ?>
                      <option value="<?= h($val) ?>" <?= $val === 'good' ? 'selected' : '' ?>><?= h($lbl) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>

              <div id="ai-valuation-error"
                   style="display:none;padding:10px 14px;background:#FEF2F2;border:1px solid #FECACA;color:#B91C1C;border-radius:10px;font-size:.875rem;line-height:1.6"></div>

              <button type="submit" class="btn btn-accent btn-lg" style="width:100%" id="val-submit-btn">
                <span id="val-btn-label" class="val-btn-label" style="display:inline-flex;align-items:center;gap:8px"><i class="bi bi-magic"></i> محاسبه ارزش با AI</span>
                <span id="val-btn-loading" class="val-btn-loading" style="display:none;align-items:center;gap:8px"><span class="spinner" style="width:16px;height:16px;border-width:2px"></span> در حال تحلیل بازار و محاسبه…</span>
              </button>
            </form>

            <div id="ai-valuation-result"
                 style="display:none;margin-top:calc(var(--wizard-gap,20px) + 8px);padding:var(--wizard-gap,20px);border-radius:14px;background:linear-gradient(180deg,rgba(26,107,74,.05),rgba(10,37,64,.02));border:1px solid rgba(26,107,74,.15)">
              <div style="margin-bottom:16px">
                <p style="font-size:.8rem;color:var(--text-muted);margin:0 0 4px 0;text-transform:uppercase;font-weight:800;letter-spacing:.3px">✨ نتیجه ارزش‌گذاری هوشمند</p>
              </div>
              <div style="display:grid;grid-template-columns:1.3fr 1fr;gap:20px;margin-bottom:var(--sp-4)">
                <div>
                  <div style="padding:18px;border-radius:12px;background:var(--primary,#0A2540);color:#fff;box-shadow:0 8px 20px -10px var(--primary,#0A2540)">
                    <p style="font-size:.78rem;opacity:.85;margin:0 0 6px 0">ارزش تخمینی</p>
                    <p style="font-size:1.75rem;font-weight:800;margin:0" data-result="value_fmt">—</p>
                  </div>
                </div>
                <div style="display:flex;flex-direction:column;gap:10px;justify-content:space-between">
                  <div>
                    <p style="font-size:.8rem;color:var(--text-muted);margin:0 0 2px 0">محدوده پیشنهادی</p>
                    <p style="font-size:1.05rem;font-weight:700;margin:0;color:var(--primary)" data-result="range_fmt">—</p>
                  </div>
                  <div>
                    <p style="font-size:.8rem;color:var(--text-muted);margin:0 0 2px 0">ضریب اطمینان</p>
                    <p style="font-size:1.05rem;font-weight:700;margin:0" data-result="confidence">—</p>
                  </div>
                </div>
              </div>

              <div style="margin-bottom:var(--sp-4)">
                <p style="font-size:.85rem;color:var(--text-muted);margin:0 0 8px 0;font-weight:700">
                  <i class="bi bi-info-circle" style="color:var(--accent-dark);margin-left:6px"></i>
                  دلایل ارزش‌گذاری:
                </p>
                <ul data-result="reasons"
                    style="margin:0;padding-right:20px;font-size:.925rem;line-height:2;color:var(--text,#1f2937)">
                  <li style="color:var(--text-muted)">—</li>
                </ul>
              </div>

              <div style="margin-bottom:var(--sp-4);padding:12px 14px;border-radius:10px;background:#FFF8E1;border:1px dashed #F5C518;color:#856404;font-size:.875rem;line-height:1.75" data-result="note">—</div>

              <a href="<?= $url ?>/listings/create.php"
                 id="ai-valuation-create-link"
                 class="btn btn-primary btn-lg"
                 style="width:100%"
                 target="_self">
                <i class="bi bi-plus-circle"></i> ثبت این کالا در آگهی با همین قیمت
              </a>
            </div>
          </div>
        </div>
      </section>

      <aside class="ai-chat-sidebar">
        <div class="card">
          <div class="card-body">
            <h3 style="font-size:1rem;margin-bottom:var(--sp-3)"><i class="bi bi-lightbulb" style="color:var(--accent-dark)"></i> نکته</h3>
            <p class="fs-sm" style="color:var(--text-muted);line-height:1.7;margin:0">
              پاسخ‌ها توسط دستیار هوشمند سواَپین با قوانین کنترل‌شده پلتفرم تولید می‌شوند — تصمیم مالی نهایی با شماست.
            </p>
          </div>
        </div>
        <a href="<?= $url ?>/listings/create" class="btn btn-primary" style="width:100%">
          <i class="bi bi-plus-circle"></i> ثبت کالا با قیمت‌گذاری AI
        </a>
      </aside>
    </div>
    <?php endif; ?>

  </div>
</section>

<?php render_footer(); ?>
