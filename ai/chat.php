<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

$user = auth_user();
$url  = APP_URL;

render_head('دستیار هوش مصنوعی', 'مشاوره معاوضه، ارزش‌گذاری و پیشنهاد هوشمند در سواپین');
render_navbar($user);
?>

<section class="ai-chat-page">
  <div class="container-md">

    <div class="ai-chat-page__header">
      <div class="ai-chat-page__badge"><i class="bi bi-stars"></i> هوش مصنوعی سواپین</div>
      <h1>دستیار معاوضه هوشمند</h1>
      <p>ارزش کالا، پیشنهاد معاوضه و راهنمای معامله — با کمک AI</p>
    </div>

    <?php if (!$user): ?>
    <div class="ai-chat-guest">
      <div class="ai-chat-guest__preview">
        <div class="ai-chat-window ai-chat-window--blurred">
          <div class="ai-chat-window__head">
            <span class="ai-dot ai-dot--green"></span>
            <strong>دستیار سواپین</strong>
          </div>
          <div class="ai-chat-window__body">
            <div class="ai-msg ai-msg--bot">
              <div class="ai-msg__avatar"><i class="bi bi-robot"></i></div>
              <div class="ai-msg__bubble">سلام! من دستیار معاوضه سواپین هستم. می‌توانم ارزش کالای شما را تخمین بزنم و بهترین پیشنهادهای معاوضه را پیشنهاد دهم.</div>
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
          <p>این قابلیت فقط برای اعضای سواپین فعال است.</p>
          <div class="ai-chat-guest__actions">
            <a href="<?= $url ?>/auth/login.php?redirect=<?= urlencode('/ai/chat.php') ?>" class="btn btn-accent btn-lg">
              <i class="bi bi-box-arrow-in-right"></i> ورود
            </a>
            <a href="<?= $url ?>/auth/register.php" class="btn btn-outline btn-lg">ثبت‌نام رایگان</a>
          </div>
        </div>
      </div>

      <div class="ai-features-grid">
        <?php
        $features = [
            ['bi-calculator', 'ارزش‌گذاری هوشمند', 'بعد از ثبت کالا، AI ارزش تقریبی SWP را پیشنهاد می‌دهد.'],
            ['bi-arrow-left-right', 'پیشنهاد معاوضه', 'بر اساس نیاز شما، گزینه‌های مناسب معاوضه را پیشنهاد می‌کند.'],
            ['bi-chat-heart', 'مشاوره معامله', 'سؤالات خود را درباره تهاتر امن بپرسید.'],
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
    <div class="ai-chat-layout" id="ai-chat-app">
      <div class="ai-chat-window">
        <div class="ai-chat-window__head">
          <span class="ai-dot ai-dot--green"></span>
          <strong>دستیار سواپین</strong>
          <span class="ai-chat-window__status">آنلاین · Groq AI</span>
        </div>
        <div class="ai-chat-window__body" id="ai-chat-messages">
          <div class="ai-msg ai-msg--bot">
            <div class="ai-msg__avatar"><i class="bi bi-robot"></i></div>
            <div class="ai-msg__bubble">
              سلام <?= h(explode(' ', $user['name'])[0]) ?>! 👋<br>
              من دستیار معاوضه سواپین هستم. می‌توانید درباره ارزش کالا، پیشنهاد معاوضه یا نحوه معامله امن سؤال بپرسید.
              <div class="ai-quick-chips">
                <button type="button" class="ai-chip" data-prompt="چطور ارزش کالایم را تخمین بزنم؟">ارزش‌گذاری کالا</button>
                <button type="button" class="ai-chip" data-prompt="چه کالایی برای معاوضه با لپ‌تاپ من مناسب است؟">پیشنهاد معاوضه</button>
                <button type="button" class="ai-chip" data-prompt="مراحل معامله امن در سواپین چیست؟">معامله امن</button>
                <a href="<?= $url ?>/dashboard.php#swap-matches" class="ai-chip" style="text-decoration:none;display:inline-flex;align-items:center">Matching Engine</a>
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

      <aside class="ai-chat-sidebar">
        <div class="card">
          <div class="card-body">
            <h3 style="font-size:1rem;margin-bottom:var(--sp-3)"><i class="bi bi-lightbulb" style="color:var(--accent-dark)"></i> نکته</h3>
            <p class="fs-sm" style="color:var(--text-muted);line-height:1.7;margin:0">
              پاسخ‌ها از Groq AI با قوانین کنترل‌شده پلتفرم تولید می‌شوند — AI تصمیم مالی نهایی نمی‌گیرد.
            </p>
          </div>
        </div>
        <a href="<?= $url ?>/listings/create.php" class="btn btn-primary" style="width:100%">
          <i class="bi bi-plus-circle"></i> ثبت کالا با قیمت‌گذاری AI
        </a>
      </aside>
    </div>
    <?php endif; ?>

  </div>
</section>

<?php render_footer(); ?>
