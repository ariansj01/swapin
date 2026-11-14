<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/clubs.php';

$user = auth_user();
clubs_ensure_tables();
if ($user) {
    clubs_touch_presence((int)$user['id']);
}

$slug = clean($_GET['slug'] ?? '');
$tab = clean($_GET['tab'] ?? 'listings');
$q = clean($_GET['q'] ?? '');
$sort = clean($_GET['sort'] ?? 'newest');
$mode = clean($_GET['mode'] ?? 'all');
if (!in_array($sort, ['newest', 'views'], true)) {
    $sort = 'newest';
}
if (!in_array($mode, ['all', 'buy', 'swap'], true)) {
    $mode = 'all';
}
$listMode = $mode === 'all' ? 'listings' : $mode;

$club = $slug !== '' ? clubs_get_by_slug($slug) : null;
if ($club && ($club['status'] ?? '') !== 'approved' && !(function_exists('is_admin_user') && is_admin_user($user))) {
    $club = null;
}
if (!$club) {
    http_response_code(404);
    render_head('کلاب یافت نشد', '', ['robots' => 'noindex, nofollow']);
    render_navbar($user);
    echo '<main id="main-content" class="section"><div class="container"><div class="empty-state"><h1>کلاب یافت نشد</h1><a class="btn btn-primary" href="' . APP_URL . '/clubs">بازگشت به کلاب‌ها</a></div></div></main>';
    render_footer();
    exit;
}

$clubId = (int)$club['id'];
$state = clubs_user_state($user, $clubId);
$loginUrl = APP_URL . '/auth/login?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/clubs/' . $club['slug']);
$flash = (string)($_SESSION['club_flash'] ?? '');
unset($_SESSION['club_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    if (!$user) {
        header('Location: ' . $loginUrl);
        exit;
    }
    $action = clean($_POST['action'] ?? '');
    $uid = (int)$user['id'];
    if ($action === 'join') {
        rate_limit_ip_or_fail('club_join', 20, 3600);
        $res = clubs_join($clubId, $uid, !empty($club['require_approval']), (int)($_POST['listing_id'] ?? 0));
        $_SESSION['club_flash'] = empty($res['ok'])
            ? ($res['error'] ?? 'عضویت ناموفق بود.')
            : ((($res['status'] ?? '') === 'pending')
                ? 'درخواست عضویت ارسال شد. مدیر کلاب آگهی و مشخصات شما را بررسی می‌کند.'
                : 'به کلاب پیوستید.');
    } elseif ($action === 'follow') {
        $on = clubs_follow_toggle($clubId, $uid);
        $_SESSION['club_flash'] = $on ? 'کلاب را دنبال می‌کنید.' : 'دنبال کردن لغو شد.';
    } elseif ($action === 'chat') {
        if (!$state['member']) {
            $_SESSION['club_flash'] = 'برای ارسال پیام باید عضو کلاب باشید.';
        } else {
            rate_limit_ip_or_fail('club_chat', 30, 600);
            $res = clubs_post_message($clubId, $uid, (string)($_POST['body'] ?? ''), (int)($_POST['listing_id'] ?? 0));
            $_SESSION['club_flash'] = empty($res['ok']) ? ($res['error'] ?? 'ارسال ناموفق بود.') : '';
        }
    } elseif ($action === 'swap') {
        if (!$state['member']) {
            $_SESSION['club_flash'] = 'برای پیشنهاد معاوضه باید عضو کلاب باشید.';
        } else {
            $offerId = (int)($state['listing_id'] ?? 0);
            $res = clubs_send_swap_offer((int)($_POST['target_listing_id'] ?? 0), $uid, $offerId);
            $_SESSION['club_flash'] = empty($res['ok']) ? ($res['error'] ?? 'ارسال پیشنهاد ناموفق بود.') : 'درخواست معامله ارسال شد.';
        }
    } elseif ($action === 'approve_join' || $action === 'reject_join') {
        $res = clubs_review_join($clubId, (int)($_POST['member_id'] ?? 0), $action === 'approve_join' ? 'approve' : 'reject', $uid);
        $_SESSION['club_flash'] = empty($res['ok']) ? ($res['error'] ?? 'بررسی ناموفق بود.') : ($action === 'approve_join' ? 'عضویت تأیید شد.' : 'درخواست رد شد.');
    }
    header('Location: ' . clubs_url($club, $tab, $q));
    exit;
}

$stats = clubs_stats($club);
$coverUrl = clubs_image_url($club['cover_image'] ?? '');
$avatarUrl = clubs_image_url($club['avatar_image'] ?? '');
$listings = clubs_listings($club, $listMode, $q, 12, 0, $sort);
$messages = array_reverse(clubs_messages($clubId, 30));
$onlineCount = clubs_online_count($clubId);
$members = $tab === 'members' ? clubs_members_list($clubId, 40) : [];
$pendingJoins = clubs_can_manage($state) ? clubs_pending_joins($clubId) : [];
$myListings = $user ? clubs_user_listings((int)$user['id']) : [];
$joinListing = ($state['member'] && (int)$state['listing_id'] > 0) ? clubs_owned_listing((int)$state['listing_id'], (int)$user['id']) : null;
$joinLabel = $state['member'] ? 'عضو هستی' : ($state['pending'] ? 'در انتظار تأیید' : 'عضویت در کلاب');
$createUrl = APP_URL . '/listings/create' . (!empty($club['category_id']) ? ('?prefill_cat=' . (int)$club['category_id']) : '');
$joinCreateUrl = $createUrl;
$cssVer = @filemtime(__DIR__ . '/../src/css/clubs-club.css') ?: time();
$mentionListings = array_slice($listings, 0, 20);

render_head($club['name'] . ' | کلاب‌های سواپین', $club['description'] ?? '', [
    'canonical'  => clubs_url($club),
    'og_image'   => $coverUrl ?: ($avatarUrl ?: LOGO_URL),
    'body_class' => 'page-club',
]);
render_navbar($user);
?>
<link rel="stylesheet" href="<?= APP_URL ?>/src/css/clubs-page.css?v=<?= $cssVer ?>">
<link rel="stylesheet" href="<?= APP_URL ?>/src/css/clubs-club.css?v=<?= $cssVer ?>">

<main id="main-content" class="clubp">
  <?php if ($flash !== ''): ?>
  <div class="alert alert-success" style="margin-bottom:12px"><?= h($flash) ?></div>
  <?php endif; ?>

  <div class="clubp-banner">
    <?php if ($coverUrl): ?><img src="<?= h($coverUrl) ?>" alt=""><?php endif; ?>
    <div class="clubp-banner__inner">
      <div class="clubp-banner__copy">
        <h1><?= h($club['name']) ?></h1>
        <p><?= h($club['description'] ?? '') ?></p>
        <div class="clubp-banner__stats">
          <div><strong><?= clubs_fmt_compact((int)$stats['members']) ?></strong><span>عضو</span></div>
          <div><strong><?= clubs_fmt_compact((int)$stats['posts']) ?></strong><span>آگهی</span></div>
          <div><strong><?= clubs_fmt_compact((int)$stats['trades']) ?></strong><span>معامله موفق</span></div>
        </div>
      </div>
      <div class="clubp-banner__side">
        <div class="clubp-banner__av">
          <?php if ($avatarUrl): ?><img src="<?= h($avatarUrl) ?>" alt=""><?php else: ?><i class="bi bi-people"></i><?php endif; ?>
        </div>
        <?php if ($user && !($state['member'] || $state['pending'])): ?>
        <button type="button" class="clubp-banner__cta" data-open-join="<?= $clubId ?>">عضویت در کلاب</button>
        <?php elseif ($state['member']): ?>
        <span class="clubp-banner__cta">عضو هستی</span>
        <?php elseif ($state['pending']): ?>
        <span class="clubp-banner__cta"><?= h($joinLabel) ?></span>
        <?php else: ?>
        <a class="clubp-banner__cta" href="<?= h($loginUrl) ?>">عضویت در کلاب</a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <nav class="clubp-mtabs">
    <a class="<?= $tab === 'listings' && $mode !== 'swap' ? 'is-on' : '' ?>" href="<?= h(clubs_url($club)) ?>">کالاهای کلاب</a>
    <a class="<?= $mode === 'swap' ? 'is-on' : '' ?>" href="<?= h(clubs_url($club) . '?mode=swap') ?>">پیشنهادهای مبادله</a>
    <a class="<?= $tab === 'members' ? 'is-on' : '' ?>" href="<?= h(clubs_url($club, 'members')) ?>">اعضا</a>
    <a href="#club-chat">گفت‌وگو</a>
  </nav>

  <?php if ($pendingJoins): ?>
  <section class="clubp-reqs">
    <h2>درخواست‌های عضویت</h2>
    <?php foreach ($pendingJoins as $pj): ?>
    <div class="clubp-req">
      <?= avatar_html($pj['avatar'] ?? null, $pj['name'] ?? '', 'sm') ?>
      <div>
        <strong><?= h($pj['name']) ?></strong>
        <div class="fs-sm text-muted"><?= h($pj['listing_title'] ?? 'بدون آگهی') ?></div>
      </div>
      <?php if (!empty($pj['listing_thumb'])): ?>
      <img src="<?= h(UPLOAD_URL . $pj['listing_thumb']) ?>" alt="">
      <?php endif; ?>
      <div class="clubp-req__btns">
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="approve_join">
          <input type="hidden" name="member_id" value="<?= (int)$pj['id'] ?>">
          <button type="submit" class="clubs-btn-navy" style="height:32px;width:auto;padding:0 10px">تأیید</button>
        </form>
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="reject_join">
          <input type="hidden" name="member_id" value="<?= (int)$pj['id'] ?>">
          <button type="submit" class="clubs-btn-ghost" style="height:32px;width:auto;padding:0 10px;margin:0">رد</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>

  <div class="clubp-toolbar">
    <form class="clubp-filters" method="get" action="<?= h(clubs_url($club)) ?>">
      <select name="sort" onchange="this.form.submit()">
        <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>مرتب‌سازی: جدیدترین</option>
        <option value="views" <?= $sort === 'views' ? 'selected' : '' ?>>پربازدید</option>
      </select>
      <select name="mode" onchange="this.form.submit()">
        <option value="all" <?= $mode === 'all' ? 'selected' : '' ?>>همه انواع معامله</option>
        <option value="buy" <?= $mode === 'buy' ? 'selected' : '' ?>>فروش</option>
        <option value="swap" <?= $mode === 'swap' ? 'selected' : '' ?>>معاوضه</option>
      </select>
    </form>
    <a class="clubp-new" href="<?= h($createUrl) ?>"><i class="bi bi-plus-lg"></i> ثبت آگهی جدید</a>
  </div>

  <div class="clubp-grid">
    <section>
      <?php if ($tab === 'members'): ?>
        <div class="clubs-members">
          <?php foreach ($members as $m): ?>
          <div class="clubs-member">
            <?= avatar_html($m['avatar'] ?? null, $m['name'] ?? '', 'md') ?>
            <div>
              <strong><?= h($m['name']) ?></strong>
              <div class="fs-sm text-muted"><?= h($m['role'] === 'owner' ? 'مدیر' : ($m['role'] === 'moderator' ? 'ناظم' : 'عضو')) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php elseif (!$listings): ?>
        <div class="clubs-empty">آگهی‌ای در این کلاب نیست.</div>
      <?php else: ?>
        <div class="clubp-cards">
          <?php foreach ($listings as $l):
            $isSell = (($l['listing_mode'] ?? 'swap') === 'sell');
            $price = clubs_listing_price_label($l);
          ?>
          <article class="clubc">
            <div class="clubc__media">
              <?php if (!empty($l['thumb'])): ?><img src="<?= h(UPLOAD_URL . $l['thumb']) ?>" alt=""><?php endif; ?>
              <span class="clubc__badge<?= $isSell ? '' : ' is-swap' ?>"><?= $isSell ? 'فروش' : 'معاوضه' ?></span>
              <?php if ($user): ?>
              <button type="button" class="clubc__fav" data-save-toggle="false" data-listing-id="<?= (int)$l['id'] ?>" aria-label="علاقه‌مندی"><i class="bi bi-heart"></i></button>
              <?php endif; ?>
            </div>
            <div class="clubc__body">
              <h3 class="clubc__title"><?= h($l['title']) ?></h3>
              <?php if ($isSell): ?>
              <p class="clubc__price"><?= h($price) ?></p>
              <?php else: ?>
              <p class="clubc__want"><?= h(trim((string)($l['want_in_return'] ?? '')) !== '' ? $l['want_in_return'] : 'با کالای هم‌ارزش معاوضه می‌کنم') ?></p>
              <?php endif; ?>
              <div class="clubc__user">
                <span><?= avatar_html($l['seller_avatar'] ?? null, $l['seller_name'] ?? '', 'sm') ?> <?= h($l['seller_name'] ?? '') ?></span>
                <span><?= timeago((string)($l['created_at'] ?? '')) ?></span>
              </div>
              <div class="clubc__btns">
                <a class="clubc__view" href="<?= h(listing_url($l)) ?>">مشاهده جزئیات</a>
                <?php if ((int)($l['user_id'] ?? 0) === (int)($user['id'] ?? 0)): ?>
                <span class="clubc__view">آگهی شما</span>
                <?php elseif ($state['member'] && $joinListing): ?>
                <button type="button" class="clubc__swap" data-open-swap="<?= (int)$l['id'] ?>" data-swap-title="<?= h($l['title']) ?>">پیشنهاد مبادله</button>
                <?php elseif ($state['member']): ?>
                <button type="button" class="clubc__swap" data-open-join="<?= $clubId ?>">انتخاب آگهی پیوستن</button>
                <?php elseif ($user): ?>
                <button type="button" class="clubc__swap" data-open-join="<?= $clubId ?>">پیشنهاد مبادله</button>
                <?php else: ?>
                <a class="clubc__swap" href="<?= h($loginUrl) ?>">پیشنهاد مبادله</a>
                <?php endif; ?>
              </div>
            </div>
          </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="clubp-ai">
        <h3>دستیار هوشمند سواپین</h3>
        <p>قیمت کالایت خود را با دستیار سواپین تضمین بزنید و احتمال معامله را افزایش دهید.</p>
        <a class="clubp-banner__cta" href="<?= APP_URL ?>/ai/chat">شروع تضمین با دستیار هوشمند</a>
      </div>
    </section>

    <aside class="clubp-chat" id="club-chat">
      <div class="clubp-chat__head">
        <h2>گفت‌وگوی کلاب</h2>
        <span class="clubp-chat__on"><i class="bi bi-circle-fill"></i> <?= fmt_num($onlineCount) ?> آنلاین</span>
      </div>
      <div class="clubp-chat__list">
        <?php foreach ($messages as $msg): ?>
        <div class="clubp-chat__row">
          <?= avatar_html($msg['user_avatar'] ?? null, $msg['user_name'] ?? '', 'sm') ?>
          <div class="clubp-chat__bubble">
            <strong>
              <?= h($msg['user_name']) ?>
              <?php if (clubs_user_is_online($msg['user_last_seen'] ?? null)): ?>
              <i class="bi bi-circle-fill" style="color:#22c55e;font-size:8px"></i>
              <?php endif; ?>
            </strong>
            <p><?= h($msg['body']) ?></p>
            <?php if (!empty($msg['listing_title'])): ?>
            <a class="clubp-chat__mention" href="<?= h(listing_url(['id' => (int)$msg['listing_id'], 'title' => (string)$msg['listing_title']])) ?>">
              <?php if (!empty($msg['listing_thumb'])): ?><img src="<?= h(UPLOAD_URL . $msg['listing_thumb']) ?>" alt=""><?php endif; ?>
              <span><?= h($msg['listing_title']) ?></span>
            </a>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if (!$messages): ?><p class="text-muted">هنوز پیامی نیست.</p><?php endif; ?>
      </div>
      <div class="clubp-notice">
        <strong>اعلان کلاب</strong>
        <p><?= h($club['description'] ?: 'این آگهی ویژه هفته کلاب است.') ?></p>
      </div>
      <?php if ($state['member']): ?>
      <div class="clubp-mentions" id="club-mention-box">
        <?php foreach ($mentionListings as $ml): ?>
        <button type="button" data-mention-id="<?= (int)$ml['id'] ?>" data-mention-title="<?= h($ml['title']) ?>">
          <?php if (!empty($ml['thumb'])): ?><img src="<?= h(UPLOAD_URL . $ml['thumb']) ?>" alt=""><?php endif; ?>
          <span><?= h($ml['title']) ?></span>
        </button>
        <?php endforeach; ?>
      </div>
      <form class="clubp-chat__form" method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="chat">
        <input type="hidden" name="listing_id" id="club-mention-id" value="0">
        <input type="text" name="body" maxlength="500" placeholder="پیام خود را بنویسید..." id="club-chat-body">
        <button type="button" class="clubp-fire" id="club-fire" aria-label="منشن آگهی"><i class="bi bi-fire"></i></button>
        <button type="submit" class="clubp-send" aria-label="ارسال"><i class="bi bi-send-fill"></i></button>
      </form>
      <?php else: ?>
      <p class="fs-sm text-muted">برای گفتگو باید عضو کلاب شوید.</p>
      <?php endif; ?>
    </aside>
  </div>
  <a class="clubp-fab" href="#club-chat" aria-label="گفتگوی کلاب"><i class="bi bi-send-fill"></i></a>
</main>

<div class="clm" id="club-swap-modal" hidden>
  <div class="clm__backdrop" data-swap-close></div>
  <div class="clm__dialog clm__dialog--join" role="dialog" aria-modal="true">
    <div class="clm__body" style="grid-column:1/-1">
      <div class="clm__top">
        <button type="button" class="clm__close" data-swap-close aria-label="بستن"><i class="bi bi-x-lg"></i></button>
      </div>
      <h2 class="clm__title">تأیید درخواست معامله</h2>
      <p class="clubs-profile__about">درخواست معاوضه با آگهی‌ای که هنگام پیوستن به کلاب انتخاب کردید ارسال می‌شود.</p>
      <?php if ($joinListing): ?>
      <div class="clubp-swap-card">
        <?php if (!empty($joinListing['thumb'])): ?>
        <img src="<?= h(UPLOAD_URL . $joinListing['thumb']) ?>" alt="">
        <?php endif; ?>
        <div>
          <strong><?= h($joinListing['title']) ?></strong>
          <div class="fs-sm text-muted">کالای ورود شما به کلاب</div>
        </div>
      </div>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="swap">
        <input type="hidden" name="target_listing_id" id="club-swap-target" value="0">
        <button class="clm__join" type="submit">تأیید و ارسال درخواست</button>
      </form>
      <?php else: ?>
      <p>ابتدا یک آگهی برای عضویت انتخاب کنید.</p>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php
require __DIR__ . '/_join_modal.php';
?>
<script>
(function () {
  const swapModal = document.getElementById('club-swap-modal');
  const target = document.getElementById('club-swap-target');
  document.querySelectorAll('[data-open-swap]').forEach(btn => {
    btn.addEventListener('click', () => {
      if (target) target.value = btn.getAttribute('data-open-swap') || '0';
      if (swapModal) { swapModal.hidden = false; document.body.classList.add('clm-open'); }
    });
  });
  if (swapModal) {
    swapModal.querySelectorAll('[data-swap-close]').forEach(el => el.addEventListener('click', () => {
      swapModal.hidden = true; document.body.classList.remove('clm-open');
    }));
  }
  const fire = document.getElementById('club-fire');
  const box = document.getElementById('club-mention-box');
  const mentionId = document.getElementById('club-mention-id');
  if (fire && box) {
    fire.addEventListener('click', () => {
      box.classList.toggle('is-on');
      fire.classList.toggle('is-on');
    });
    box.querySelectorAll('[data-mention-id]').forEach(btn => {
      btn.addEventListener('click', () => {
        mentionId.value = btn.getAttribute('data-mention-id') || '0';
        const input = document.getElementById('club-chat-body');
        if (input && !input.value) input.value = 'آگهی: ' + (btn.getAttribute('data-mention-title') || '');
        box.classList.remove('is-on');
        fire.classList.add('is-on');
      });
    });
  }
})();
</script>
<?php
render_footer();
