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
    <div class="ai-chat-guest-note">
      <i class="bi bi-info-circle"></i>
      <span>شما به‌عنوان مهمان وارد شده‌اید — تا <strong>۴ پیام رایگان</strong> می‌توانید با دستیار گفتگو کنید. برای گفتگوی نامحدود وارد شوید.</span>
      <a href="<?= $url ?>/auth/login?redirect=<?= urlencode('/ai/chat') ?>" class="ai-chat-guest-note__btn">ورود / ثبت‌نام</a>
    </div>
    <?php endif; ?>

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
    $greetName = $user ? explode(' ', (string)$user['name'])[0] : '';
    ?>
    <div class="ai-chat-layout" id="ai-chat-app">

      <div class="ai-chat-main-grid">
        <!-- بخش اول: چت دستیار -->
        <section aria-label="چت دستیار سواَپین" class="ai-chat-panel">
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
                  سلام<?= $greetName !== '' ? ' ' . h($greetName) : '' ?>! 👋<br>
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
        <section id="ai-valuation-section" aria-label="تخمین قیمت کالا" class="ai-valuation-panel">
          <div class="ai-valuation-panel__header">
            <div class="ai-valuation-panel__bar"></div>
            <h2 class="ai-valuation-panel__title">
              <i class="bi bi-calculator-fill"></i>
              تخمین قیمت کالا با AI
            </h2>
          </div>
          <div class="ai-valuation-box">
            <div class="ai-valuation-box__head">
              <h3 class="ai-valuation-box__head-title" style="color: #fff;">
                <i class="bi bi-stars"></i>
                تخمین هوشمند قیمت کالا بر اساس بازار داخلی سواَپین
              </h3>
              <p class="ai-valuation-box__head-desc" style="color: #FFC928;">مشخصات کالای خود را وارد کنید تا قیمت تقریبی آن بر اساس آگهی‌های تاییدشده و آمار دسته‌بندی محاسبه شود.</p>
            </div>

            <div class="ai-valuation-box__body">
              <form id="ai-valuation-form" method="post" class="ai-valuation-form">
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

                <div class="ai-valuation-form__row">
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

                <div id="ai-valuation-error" class="ai-valuation-form__error"></div>

                <button type="submit" class="btn btn-accent btn-lg ai-valuation-submit" id="val-submit-btn">
                  <span id="val-btn-label" class="val-btn-label"><i class="bi bi-magic"></i> محاسبه ارزش با AI</span>
                  <span id="val-btn-loading" class="val-btn-loading"><span class="spinner" style="width:16px;height:16px;border-width:2px"></span> در حال تحلیل بازار و محاسبه…</span>
                </button>
              </form>

              <div id="ai-valuation-result" class="ai-valuation-result">
                <div class="ai-valuation-result__head">
                  <p class="ai-valuation-result__badge">✨ نتیجه ارزش‌گذاری هوشمند</p>
                </div>
                <div class="ai-valuation-result__grid">
                  <div>
                    <div class="ai-valuation-result__value-card">
                      <p class="ai-valuation-result__value-label">ارزش تخمینی</p>
                      <p class="ai-valuation-result__value-num" data-result="value_fmt">—</p>
                    </div>
                  </div>
                  <div class="ai-valuation-result__meta">
                    <div>
                      <p class="ai-valuation-result__meta-item-label">محدوده پیشنهادی</p>
                      <p class="ai-valuation-result__meta-item-value" data-result="range_fmt">—</p>
                    </div>
                    <div>
                      <p class="ai-valuation-result__meta-item-label">ضریب اطمینان</p>
                      <p class="ai-valuation-result__meta-item-value" data-result="confidence">—</p>
                    </div>
                  </div>
                </div>

                <div class="ai-valuation-result__reasons">
                  <p class="ai-valuation-result__reasons-title">
                    <i class="bi bi-info-circle"></i>
                    دلایل ارزش‌گذاری:
                  </p>
                  <ul class="ai-valuation-result__reasons-list" data-result="reasons">
                    <li style="color:var(--text-muted)">—</li>
                  </ul>
                </div>

                <div class="ai-valuation-result__note" data-result="note">—</div>

                <a href="<?= $url ?>/listings/create"
                   id="ai-valuation-create-link"
                   class="btn btn-primary btn-lg ai-valuation-result__create-btn"
                   target="_self">
                  <i class="bi bi-plus-circle"></i> ثبت این کالا در آگهی با همین قیمت
                </a>
              </div>
            </div>
          </div>
        </section>
      </div>

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

  </div>
</section>

<?php render_footer(); ?>
