<?php
// includes/stories.php
// استوری‌های صفحه اصلی (نمایش مشابه اینستاگرام) + توابع مدیریت

function stories_ensure_tables(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (!db_has_table('stories')) {
        DB::query("CREATE TABLE IF NOT EXISTS `stories` (
          `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
          `title` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
          `image` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
          `caption` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
          `link_url` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
          `sort_order` int NOT NULL DEFAULT 0,
          `is_active` tinyint(1) NOT NULL DEFAULT 1,
          `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_stories_active` (`is_active`, `sort_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

/** استوری‌های فعال برای نمایش در سایت */
function stories_active(): array {
    stories_ensure_tables();
    return DB::fetchAll('SELECT * FROM stories WHERE is_active = 1 ORDER BY sort_order ASC, id DESC');
}

/** همه استوری‌ها برای پنل مدیریت */
function stories_all(): array {
    stories_ensure_tables();
    return DB::fetchAll('SELECT * FROM stories ORDER BY sort_order ASC, id DESC');
}

function stories_get(int $id): ?array {
    if ($id <= 0) {
        return null;
    }
    stories_ensure_tables();
    return DB::fetch('SELECT * FROM stories WHERE id = ?', [$id]);
}

/** فقط لینک‌های http(s) یا مسیر داخلی مجاز هستند */
function stories_safe_link(string $url): string {
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    return preg_match('#^(https?://|/)#i', $url) ? $url : '';
}

/** رندر بخش استوری‌ها به همراه استایل و اسکریپت مودال (فقط اگر استوری فعال وجود داشته باشد) */
function stories_render_section(): void {
    $stories = stories_active();
    if (!$stories) {
        return;
    }

    $storyData = [];
    foreach ($stories as $s) {
        $storyData[] = [
            'image'   => UPLOAD_URL . (string)$s['image'],
            'title'   => (string)($s['title'] ?? ''),
            'caption' => (string)($s['caption'] ?? ''),
            'link'    => stories_safe_link((string)($s['link_url'] ?? '')),
        ];
    }
    $json = json_encode($storyData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    ?>
<style>
.sw-stories { margin-top: 20px; }
.sw-stories__title { display: flex; align-items: center; gap: 8px; font-size: 1.05rem; font-weight: 800; margin: 0 0 14px; color: #071A33; }
.sw-stories__title i { color: #FF6600; }
.sw-stories__track { display: flex; gap: 16px; overflow-x: auto; padding: 4px 2px 10px; scrollbar-width: thin; -webkit-overflow-scrolling: touch; }
.sw-story { display: flex; flex-direction: column; align-items: center; gap: 8px; background: none; border: 0; padding: 0; cursor: pointer; flex: 0 0 auto; width: 86px; }
.sw-story__ring {
  width: 76px; height: 76px; border-radius: 50%; padding: 3px; display: block;
  background: conic-gradient(from 200deg, #0066ff, #ff6600, #0066ff);
  transition: transform .18s ease;
}
.sw-story__ring img { width: 100%; height: 100%; border-radius: 50%; object-fit: cover; border: 3px solid #fff; display: block; background: #f3f4f6; }
.sw-story:hover .sw-story__ring { transform: scale(1.05); }
.sw-story__name { font-size: .75rem; font-weight: 600; color: #374151; max-width: 86px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.sw-story-modal { position: fixed; inset: 0; z-index: 2000; display: flex; align-items: center; justify-content: center; }
.sw-story-modal[hidden] { display: none !important; }
.sw-story-modal__backdrop { position: absolute; inset: 0; background: rgba(4, 10, 22, .92); border: 0; cursor: pointer; }
.sw-story-modal__stage {
  position: relative; z-index: 1; display: flex; flex-direction: column; overflow: hidden;
  width: min(420px, 92vw); height: min(760px, 88vh);
  background: #0b1220; border-radius: 16px; box-shadow: 0 24px 70px rgba(0, 0, 0, .6);
}
.sw-story-modal__bars { position: absolute; top: 10px; left: 10px; right: 10px; display: flex; gap: 4px; z-index: 5; }
.sw-story-modal__bar { flex: 1; height: 3px; border-radius: 2px; background: rgba(255, 255, 255, .35); overflow: hidden; }
.sw-story-modal__bar > i { display: block; height: 100%; width: 0; background: #fff; }
.sw-story-modal__head { position: absolute; top: 22px; left: 12px; right: 12px; display: flex; align-items: center; gap: 10px; z-index: 6; }
.sw-story-modal__avatar { width: 34px; height: 34px; border-radius: 50%; object-fit: cover; border: 2px solid rgba(255,255,255,.8); flex: 0 0 auto; }
.sw-story-modal__name { flex: 1; min-width: 0; color: #fff; font-size: .85rem; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sw-story-modal__close { border: 0; background: transparent; color: #fff; font-size: 1.05rem; cursor: pointer; line-height: 1; padding: 6px; }
.sw-story-modal__media { position: relative; flex: 1; min-height: 0; }
.sw-story-modal__media img { width: 100%; height: 100%; object-fit: cover; display: block; }
.sw-story-modal__nav { position: absolute; top: 0; bottom: 0; width: 32%; border: 0; background: transparent; cursor: pointer; z-index: 3; padding: 0; }
.sw-story-modal__nav--prev { right: 0; }
.sw-story-modal__nav--next { left: 0; }
.sw-story-modal__bottom {
  position: absolute; left: 0; right: 0; bottom: 0; z-index: 6;
  display: flex; flex-direction: column; gap: 10px;
  padding: 44px 16px 18px;
  background: linear-gradient(to top, rgba(0, 0, 0, .82), transparent);
}
.sw-story-modal__caption { margin: 0; color: #fff; font-size: .9rem; line-height: 1.7; }
.sw-story-modal__link {
  display: inline-flex; align-items: center; justify-content: center; gap: 6px; align-self: flex-start;
  padding: 8px 16px; border-radius: 999px; background: rgba(255, 255, 255, .92); color: #071A33;
  font-size: .8rem; font-weight: 800; text-decoration: none;
}
.sw-story-modal__bottom[hidden],
.sw-story-modal__caption[hidden],
.sw-story-modal__link[hidden] { display: none !important; }
body.sw-story-open { overflow: hidden; }
@media (max-width: 560px) {
  .sw-story-modal__stage { width: 100vw; height: 100vh; border-radius: 0; }
}
</style>

<section class="sw-stories" aria-label="استوری‌ها">
  <div class="container">
    <h2 class="sw-stories__title" style="margin-right: -10%;"><i class="bi bi-lightning-charge-fill"></i> استوری‌ها</h2>
    <div class="sw-stories__track" style="width: 120%;margin-right: -10%;">
      <?php foreach ($stories as $i => $s): ?>
      <button type="button" class="sw-story" data-story-index="<?= (int)$i ?>" aria-label="مشاهده استوری <?= h($s['title'] !== '' ? $s['title'] : '') ?>">
        <span class="sw-story__ring">
          <img src="<?= UPLOAD_URL . h($s['image']) ?>" alt="<?= h($s['title'] !== '' ? $s['title'] : 'استوری') ?>" loading="lazy">
        </span>
        <span class="sw-story__name"><?= h($s['title'] !== '' ? $s['title'] : 'استوری') ?></span>
      </button>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<div class="sw-story-modal" id="sw-story-modal" hidden aria-hidden="true">
  <div class="sw-story-modal__backdrop" data-story-close></div>
  <div class="sw-story-modal__stage" role="dialog" aria-modal="true" aria-label="نمایش استوری">
    <div class="sw-story-modal__bars" id="sw-story-bars"></div>
    <header class="sw-story-modal__head">
      <img class="sw-story-modal__avatar" id="sw-story-avatar" src="" alt="">
      <span class="sw-story-modal__name" id="sw-story-name"></span>
      <button type="button" class="sw-story-modal__close" data-story-close aria-label="بستن"><i class="bi bi-x-lg"></i></button>
    </header>
    <div class="sw-story-modal__media">
      <img id="sw-story-image" src="" alt="">
      <button type="button" class="sw-story-modal__nav sw-story-modal__nav--prev" data-story-prev aria-label="استوری قبلی"></button>
      <button type="button" class="sw-story-modal__nav sw-story-modal__nav--next" data-story-next aria-label="استوری بعدی"></button>
      <div class="sw-story-modal__bottom" id="sw-story-bottom" hidden>
        <p class="sw-story-modal__caption" id="sw-story-caption" hidden></p>
        <a class="sw-story-modal__link" id="sw-story-link" href="#" hidden target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-left"></i> مشاهده</a>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var DATA = <?= $json ?: '[]' ?>;
  var DURATION = 5000;
  var root = document.getElementById('sw-story-modal');
  if (!root || !DATA.length) { return; }

  var barsWrap  = document.getElementById('sw-story-bars');
  var elImage   = document.getElementById('sw-story-image');
  var elAvatar  = document.getElementById('sw-story-avatar');
  var elName    = document.getElementById('sw-story-name');
  var elBottom  = document.getElementById('sw-story-bottom');
  var elCaption = document.getElementById('sw-story-caption');
  var elLink    = document.getElementById('sw-story-link');
  var bars = [];
  var current = 0;
  var timer = null;

  DATA.forEach(function () {
    var bar = document.createElement('span');
    bar.className = 'sw-story-modal__bar';
    bar.appendChild(document.createElement('i'));
    barsWrap.appendChild(bar);
    bars.push(bar);
  });

  function clearTimer() {
    if (timer) { clearTimeout(timer); timer = null; }
  }

  function render(index) {
    current = index;
    var item = DATA[index] || {};
    elImage.src = item.image || '';
    elAvatar.src = item.image || '';
    elName.textContent = item.title || 'استوری';

    if (item.caption) { elCaption.textContent = item.caption; elCaption.hidden = false; }
    else { elCaption.textContent = ''; elCaption.hidden = true; }

    if (item.link) { elLink.href = item.link; elLink.hidden = false; }
    else { elLink.removeAttribute('href'); elLink.hidden = true; }

    elBottom.hidden = !(item.caption || item.link);

    bars.forEach(function (bar, i) {
      var fill = bar.firstElementChild;
      fill.style.transition = 'none';
      fill.style.width = i < index ? '100%' : '0%';
    });

    var active = bars[index].firstElementChild;
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        active.style.transition = 'width ' + DURATION + 'ms linear';
        active.style.width = '100%';
      });
    });

    clearTimer();
    timer = setTimeout(next, DURATION);
  }

  function open(index) {
    root.hidden = false;
    root.setAttribute('aria-hidden', 'false');
    document.body.classList.add('sw-story-open');
    render(index);
  }

  function close() {
    clearTimer();
    root.hidden = true;
    root.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('sw-story-open');
    elImage.removeAttribute('src');
  }

  function next() {
    if (current >= DATA.length - 1) { close(); return; }
    render(current + 1);
  }

  function prev() {
    render(current > 0 ? current - 1 : 0);
  }

  Array.prototype.forEach.call(document.querySelectorAll('.sw-story'), function (btn) {
    btn.addEventListener('click', function () {
      open(parseInt(btn.getAttribute('data-story-index'), 10) || 0);
    });
  });

  Array.prototype.forEach.call(root.querySelectorAll('[data-story-close]'), function (el) {
    el.addEventListener('click', close);
  });
  Array.prototype.forEach.call(root.querySelectorAll('[data-story-next]'), function (el) {
    el.addEventListener('click', function (e) { e.stopPropagation(); next(); });
  });
  Array.prototype.forEach.call(root.querySelectorAll('[data-story-prev]'), function (el) {
    el.addEventListener('click', function (e) { e.stopPropagation(); prev(); });
  });

  document.addEventListener('keydown', function (e) {
    if (root.hidden) { return; }
    if (e.key === 'Escape') { close(); }
    else if (e.key === 'ArrowLeft') { next(); }
    else if (e.key === 'ArrowRight') { prev(); }
  });
})();
</script>
    <?php
}
