<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/clubs.php';

$user = auth_user();
clubs_ensure_tables();

$mainCats = clubs_main_categories();
$catSlug = clean($_GET['cat'] ?? '');
$slug = clean($_GET['slug'] ?? '');
$tab = clean($_GET['tab'] ?? 'listings');
$q = clean($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$desktopTabs = ['listings' => 'همه آگهی‌ها', 'buy' => 'پیشنهادهای خرید', 'swap' => 'پیشنهادهای مبادله', 'rules' => 'قوانین کلاب'];
$mobileTabs = ['listings' => 'کالاهای کلاب', 'buy' => 'پیشنهادها', 'members' => 'اعضای کلاب', 'chat' => 'گفت‌وگو'];
if (!isset($desktopTabs[$tab]) && !isset($mobileTabs[$tab])) {
    $tab = 'listings';
}

$activeCat = null;
if ($catSlug !== '') {
    foreach ($mainCats as $mc) {
        if ((string)$mc['slug'] === $catSlug) {
            $activeCat = $mc;
            break;
        }
    }
}

$club = null;
if ($slug !== '') {
    $club = clubs_get_by_slug($slug);
    if ($club && ($club['status'] ?? '') !== 'approved' && !(function_exists('is_admin_user') && is_admin_user($user))) {
        $club = null;
    }
}

if ($club && !$activeCat) {
    $rootId = clubs_root_category_id((int)($club['category_id'] ?? 0));
    foreach ($mainCats as $mc) {
        if ((int)$mc['id'] === $rootId) {
            $activeCat = $mc;
            break;
        }
    }
}
if (!$activeCat && $mainCats) {
    $activeCat = $mainCats[0];
}

$categoryClubs = $activeCat ? clubs_by_root_category((int)$activeCat['id']) : [];
$adBanner = clubs_banner_active();

$flash = (string)($_SESSION['club_flash'] ?? '');
unset($_SESSION['club_flash']);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $club) {
    csrf_verify_or_fail();
    if (!$user) {
        header('Location: ' . APP_URL . '/auth/login?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/clubs'));
        exit;
    }
    $action = clean($_POST['action'] ?? '');
    $clubId = (int)$club['id'];
    $uid = (int)$user['id'];
    $redirTab = $tab;
    if ($action === 'join') {
        rate_limit_ip_or_fail('club_join', 20, 3600);
        $res = clubs_join($clubId, $uid, !empty($club['require_approval']));
        $_SESSION['club_flash'] = (($res['status'] ?? '') === 'pending')
            ? 'درخواست عضویت ارسال شد و پس از تأیید مدیر فعال می‌شود.'
            : 'به کلاب پیوستید.';
    } elseif ($action === 'follow') {
        $on = clubs_follow_toggle($clubId, $uid);
        $_SESSION['club_flash'] = $on ? 'کلاب را دنبال می‌کنید.' : 'دنبال کردن لغو شد.';
    } elseif ($action === 'chat') {
        $state = clubs_user_state($user, $clubId);
        if (!$state['member']) {
            $_SESSION['club_flash'] = 'برای ارسال پیام باید عضو کلاب باشید.';
        } else {
            rate_limit_ip_or_fail('club_chat', 30, 600);
            $res = clubs_post_message($clubId, $uid, (string)($_POST['body'] ?? ''));
            $_SESSION['club_flash'] = empty($res['ok']) ? ($res['error'] ?? 'ارسال ناموفق بود.') : '';
            $redirTab = 'chat';
        }
    }
    header('Location: ' . clubs_url($club, $redirTab, $q));
    exit;
}

$stats = $club ? clubs_stats($club) : ['members' => 0, 'posts' => 0, 'trades' => 0];
$state = $club ? clubs_user_state($user, (int)$club['id']) : ['member' => false, 'pending' => false, 'following' => false];
$managers = $club ? clubs_managers((int)$club['id']) : [];
$canSeeListings = $club && (($club['privacy'] ?? 'public') === 'public' || $state['member'] || (function_exists('is_admin_user') && is_admin_user($user)));
$showClubDetail = (bool)$club;
$perPage = 12;
$offset = ($page - 1) * $perPage;
$listMode = in_array($tab, ['buy', 'swap'], true) ? $tab : 'listings';
$listings = ($club && $canSeeListings && in_array($tab, ['listings', 'buy', 'swap'], true))
    ? clubs_listings($club, $listMode, $q, $perPage, $offset)
    : [];
$members = ($club && $tab === 'members') ? clubs_members_list((int)$club['id']) : [];
$messages = ($club && $tab === 'chat') ? array_reverse(clubs_messages((int)$club['id'])) : [];

$coverUrl = $club ? clubs_image_url($club['cover_image'] ?? '') : '';
$avatarUrl = $club ? clubs_image_url($club['avatar_image'] ?? '') : '';
$bannerImg = $adBanner ? clubs_image_url((string)($adBanner['image'] ?? '')) : '';
$joinLabel = $state['member'] ? 'عضو کلاب هستید' : ($state['pending'] ? 'در انتظار تأیید' : 'عضویت در کلاب');
$title = $club ? ($club['name'] . ' | کلاب‌های سواپین') : (($activeCat['name'] ?? 'کلاب‌های سواپین') . ' | کلاب‌های سواپین');
$canonical = $club ? clubs_url($club) : clubs_hub_url((string)($activeCat['slug'] ?? ''));
$cssVer = @filemtime(__DIR__ . '/../src/css/clubs-page.css') ?: time();
$loginUrl = APP_URL . '/auth/login?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/clubs');

render_head($title, $club['description'] ?? 'کلاب‌های سواپین؛ معامله در کنار آدم‌های هم‌علاقه.', [
    'canonical'  => $canonical,
    'og_image'   => $bannerImg ?: ($coverUrl ?: ($avatarUrl ?: LOGO_URL)),
    'body_class' => 'page-clubs',
]);
render_navbar($user);
?>
<link rel="stylesheet" href="<?= APP_URL ?>/src/css/clubs-page.css?v=<?= $cssVer ?>">

<main id="main-content" class="clubs-hub">
<?php if (!$activeCat && !$club): ?>
  <div class="clubs-empty">
    <h1>کلاب‌های سواپین</h1>
    <p>هنوز دسته‌بندی یا کلاب تأییدشده‌ای وجود ندارد.</p>
    <a class="clubs-banner__cta" href="<?= APP_URL ?>/clubs/create">+ ساخت کلاب</a>
  </div>
<?php else: ?>

  <?php if ($flash !== ''): ?>
  <div class="alert alert-success" style="margin-bottom:12px"><?= h($flash) ?></div>
  <?php endif; ?>

  <div class="clubs-chips">
    <?php foreach ($mainCats as $mc): ?>
    <a class="<?= $activeCat && (int)$mc['id'] === (int)$activeCat['id'] ? 'is-on' : '' ?>" href="<?= h(clubs_hub_url((string)$mc['slug'])) ?>"><?= h($mc['name']) ?></a>
    <?php endforeach; ?>
  </div>

  <div class="clubs-hub__grid">
    <aside class="clubs-panel clubs-profile">
      <?php if ($showClubDetail): ?>
      <div class="clubs-profile__avatar">
        <?php if ($avatarUrl): ?><img src="<?= h($avatarUrl) ?>" alt=""><?php else: ?><i class="bi bi-people"></i><?php endif; ?>
      </div>
      <h2 class="clubs-profile__name"><?= h($club['name']) ?></h2>
      <div class="clubs-profile__stats">
        <div class="clubs-profile__stat"><strong><?= clubs_fmt_compact((int)$stats['members']) ?></strong><span>عضو</span></div>
        <div class="clubs-profile__stat"><strong><?= clubs_fmt_compact((int)$stats['posts']) ?></strong><span>پست</span></div>
        <div class="clubs-profile__stat"><strong><?= clubs_fmt_compact((int)$stats['trades']) ?></strong><span>معامله موفق</span></div>
      </div>
      <?php if ($user): ?>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="join">
        <button class="clubs-btn-navy" type="submit" <?= ($state['member'] || $state['pending']) ? 'disabled' : '' ?>><?= h($joinLabel) ?></button>
      </form>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="follow">
        <button class="clubs-btn-ghost<?= $state['following'] ? ' is-on' : '' ?>" type="submit">
          <i class="bi bi-plus-square"></i> <?= $state['following'] ? 'در حال دنبال کردن' : 'دنبال کردن' ?>
        </button>
      </form>
      <?php else: ?>
      <a class="clubs-btn-navy" href="<?= h($loginUrl) ?>">عضویت در کلاب</a>
      <a class="clubs-btn-ghost" href="<?= h($loginUrl) ?>"><i class="bi bi-plus-square"></i> دنبال کردن</a>
      <?php endif; ?>
      <?php else: ?>
      <div class="clubs-profile__avatar"><i class="<?= h(clubs_category_icon($activeCat['icon'] ?? '', (string)($activeCat['name'] ?? ''))) ?>"></i></div>
      <h2 class="clubs-profile__name"><?= h($activeCat['name'] ?? 'کلاب‌های سواپین') ?></h2>
      <div class="clubs-profile__stats">
        <div class="clubs-profile__stat"><strong><?= clubs_fmt_compact(count($categoryClubs)) ?></strong><span>کلاب</span></div>
      </div>
      <p class="clubs-profile__about">کلاب‌های این دسته‌بندی را ببینید و عضو شوید.</p>
      <a class="clubs-btn-navy" href="<?= APP_URL ?>/clubs/create">ساخت کلاب</a>
      <?php endif; ?>

      <?php if ($showClubDetail && $managers): ?>
      <div class="clubs-profile__block">
        <h3>مدیران کلاب</h3>
        <div class="clubs-managers">
          <?php foreach ($managers as $m): ?>
            <?= avatar_html($m['avatar'] ?? null, $m['name'] ?? '', 'sm') ?>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($showClubDetail): ?>
      <div class="clubs-profile__block">
        <h3>درباره این کلاب</h3>
        <p class="clubs-profile__about"><?= h($club['description'] ?? '') ?></p>
        <a class="clubs-profile__rules" href="<?= h(clubs_url($club, 'rules')) ?>">مشاهده قوانین</a>
      </div>
      <?php endif; ?>
    </aside>

    <section class="clubs-main">
      <div class="clubs-banner<?= $adBanner ? ' is-ad' : '' ?>">
        <?php if ($bannerImg): ?><img src="<?= h($bannerImg) ?>" alt=""><?php endif; ?>
        <div class="clubs-banner__inner">
          <div class="clubs-banner__copy">
            <h1><?= h($adBanner['title'] ?? ($club['name'] ?? ($activeCat['name'] ?? 'کلاب‌های سواپین'))) ?></h1>
            <p><?= h($adBanner['subtitle'] ?? ($club['description'] ?? 'آدم‌های هم‌علاقه را پیدا کن و معامله را شروع کن.')) ?></p>
          </div>
          <?php
            $bannerCta = trim((string)($adBanner['cta_label'] ?? ''));
            $bannerHref = clubs_safe_link((string)($adBanner['link_url'] ?? ''));
            if ($adBanner && ($bannerCta !== '' || $bannerHref !== '')):
          ?>
          <a class="clubs-banner__cta" href="<?= h($bannerHref !== '' ? $bannerHref : '#') ?>">
            <?= h($bannerCta !== '' ? $bannerCta : 'مشاهده') ?>
          </a>
          <?php endif; ?>
          <?php if ($showClubDetail): ?>
          <div class="clubs-mbar">
            <div><strong><?= clubs_fmt_compact((int)$stats['members']) ?></strong><span>عضو</span></div>
            <div><strong><?= clubs_fmt_compact((int)$stats['posts']) ?></strong><span>پست</span></div>
            <div><strong><?= clubs_fmt_compact((int)$stats['trades']) ?></strong><span>معامله موفق</span></div>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($showClubDetail): ?>
      <div class="clubs-mjoin">
        <?php if ($user): ?>
        <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="join"><button class="clubs-btn-navy" type="submit" <?= ($state['member'] || $state['pending']) ? 'disabled' : '' ?>><?= h($joinLabel) ?></button></form>
        <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="follow"><button class="clubs-btn-ghost<?= $state['following'] ? ' is-on' : '' ?>" type="submit"><?= $state['following'] ? 'در حال دنبال کردن' : 'دنبال کردن' ?></button></form>
        <?php else: ?>
        <a class="clubs-btn-navy" href="<?= h($loginUrl) ?>">عضویت در کلاب</a>
        <a class="clubs-btn-ghost" href="<?= h($loginUrl) ?>">دنبال کردن</a>
        <?php endif; ?>
      </div>

      <nav class="clubs-mtabs">
        <?php foreach ($mobileTabs as $key => $label): ?>
        <a class="<?= $tab === $key ? 'is-on' : '' ?>" href="<?= h(clubs_url($club, $key, $q)) ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
      </nav>

      <div class="clubs-toolbar">
        <nav class="clubs-tabs">
          <?php foreach ($desktopTabs as $key => $label): ?>
          <a class="<?= $tab === $key ? 'is-on' : '' ?>" href="<?= h(clubs_url($club, $key, $q)) ?>"><?= h($label) ?></a>
          <?php endforeach; ?>
        </nav>
        <form class="clubs-search" method="get" action="<?= h(APP_URL . '/clubs/' . rawurlencode((string)$club['slug'])) ?>">
          <?php if (in_array($tab, ['listings', 'buy', 'swap'], true) && $tab !== 'listings'): ?>
          <input type="hidden" name="tab" value="<?= h($tab) ?>">
          <?php endif; ?>
          <input type="search" name="q" value="<?= h($q) ?>" placeholder="جستجو در این کلاب...">
          <i class="bi bi-search"></i>
        </form>
      </div>

      <?php if ($tab === 'rules'): ?>
        <article class="clubs-rules">
          <h2>قوانین کلاب</h2>
          <?php if (trim((string)($club['rules'] ?? '')) !== ''): ?>
          <p><?= nl2br(h($club['rules'])) ?></p>
          <?php else: ?>
          <p>هنوز قانونی برای این کلاب ثبت نشده است.</p>
          <?php endif; ?>
        </article>
      <?php elseif ($tab === 'members'): ?>
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
          <?php if (!$members): ?><div class="clubs-empty">هنوز عضوی نیست.</div><?php endif; ?>
        </div>
      <?php elseif ($tab === 'chat'): ?>
        <div class="clubs-chat" id="club-chat">
          <h3>گفت‌وگوی کلاب</h3>
          <div class="clubs-chat-list">
            <?php foreach ($messages as $msg): ?>
            <div class="clubs-chat-row">
              <?= avatar_html($msg['user_avatar'] ?? null, $msg['user_name'] ?? '', 'sm') ?>
              <div class="clubs-chat-bubble">
                <strong><?= h($msg['user_name']) ?></strong>
                <p><?= h($msg['body']) ?></p>
              </div>
            </div>
            <?php endforeach; ?>
            <?php if (!$messages): ?><p class="text-muted">هنوز پیامی نیست.</p><?php endif; ?>
          </div>
          <?php if ($state['member']): ?>
          <form class="clubs-chat-form" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="chat">
            <input type="text" name="body" maxlength="500" placeholder="پیام خود را بنویسید..." required>
            <button type="submit" aria-label="ارسال"><i class="bi bi-send-fill"></i></button>
          </form>
          <?php else: ?>
          <p class="fs-sm text-muted">برای گفتگو باید عضو کلاب شوید.</p>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <?php if (!$canSeeListings): ?>
          <div class="clubs-empty">این کلاب خصوصی است. پس از عضویت می‌توانید آگهی‌ها را ببینید.</div>
        <?php elseif (!$listings): ?>
          <div class="clubs-empty">آگهی‌ای در این کلاب پیدا نشد.</div>
        <?php else: ?>
          <div class="clubs-cards">
            <?php foreach ($listings as $l): ?>
            <article class="club-item">
              <div class="club-item__media">
                <?php if (!empty($l['thumb'])): ?>
                <img src="<?= h(UPLOAD_URL . $l['thumb']) ?>" alt="">
                <?php endif; ?>
                <?php if ($user): ?>
                <button type="button" class="club-item__fav" data-save-toggle="<?= 'false' ?>" data-listing-id="<?= (int)$l['id'] ?>" aria-label="علاقه‌مندی"><i class="bi bi-heart"></i></button>
                <?php endif; ?>
              </div>
              <div class="club-item__body">
                <h3 class="club-item__title"><?= h($l['title']) ?></h3>
                <p class="club-item__sub"><?= h(mb_strimwidth((string)($l['description'] ?? ''), 0, 70, '…', 'UTF-8')) ?></p>
                <div class="club-item__user">
                  <?= avatar_html($l['seller_avatar'] ?? null, $l['seller_name'] ?? '', 'sm') ?>
                  <span><?= h($l['seller_name'] ?? '') ?></span>
                </div>
                <div class="club-item__meta">
                  <span><i class="bi bi-eye"></i> <?= clubs_fmt_compact((int)($l['views'] ?? 0)) ?></span>
                </div>
                <a class="club-item__cta" href="<?= h(listing_url($l)) ?>">مشاهده جزئیات <i class="bi bi-arrow-left"></i></a>
              </div>
            </article>
            <?php endforeach; ?>
          </div>
          <?php if (count($listings) >= $perPage): ?>
          <a class="clubs-more" href="<?= h(clubs_url($club, $tab, $q) . (str_contains(clubs_url($club, $tab, $q), '?') ? '&' : '?') . 'page=' . ($page + 1)) ?>">بارگذاری بیشتر...</a>
          <?php endif; ?>
        <?php endif; ?>

        <div class="clubs-ai">
          <i class="bi bi-stars" style="font-size:1.4rem;color:#2563eb"></i>
          <h3>دستیار هوشمند سواپین</h3>
          <p>قیمت بده، پیشنهاد بگیر و معامله‌ات را سریع‌تر جلو ببر.</p>
          <a class="clubs-banner__cta" href="<?= APP_URL ?>/ai/chat">شروع تضمین با دستیار هوشمند</a>
        </div>
        <div class="clubs-chat clubs-chat-preview" id="club-chat-mobile">
          <h3>گفت‌وگوی کلاب</h3>
          <?php $previewMsgs = $tab === 'chat' ? $messages : array_reverse(clubs_messages((int)$club['id'], 3)); ?>
          <div class="clubs-chat-list">
            <?php foreach ($previewMsgs as $msg): ?>
            <div class="clubs-chat-row">
              <?= avatar_html($msg['user_avatar'] ?? null, $msg['user_name'] ?? '', 'sm') ?>
              <div class="clubs-chat-bubble">
                <strong><?= h($msg['user_name']) ?></strong>
                <p><?= h($msg['body']) ?></p>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <a class="clubs-more" href="<?= h(clubs_url($club, 'chat')) ?>">مشاهده گفتگو</a>
        </div>
        <div class="clubs-notice">
          <h3>اعلان کلاب</h3>
          <p>این آگهی ویژه هفته کلاب است. کالای پیشنهادی را با شرایط کلاب معامله کنید.</p>
        </div>
      <?php endif; ?>
      <?php else: ?>
        <?php if (!$categoryClubs): ?>
        <div class="clubs-empty">در این دسته‌بندی هنوز کلابی نیست. اولین کلاب را بسازید.</div>
        <?php else: ?>
        <div class="clubs-cards">
          <?php foreach ($categoryClubs as $c):
            $cCover = clubs_image_url($c['cover_image'] ?? '');
            $cAvatar = clubs_image_url($c['avatar_image'] ?? '');
            $cStats = clubs_stats($c);
          ?>
          <article class="club-item">
            <div class="club-item__media">
              <?php if ($cCover): ?><img src="<?= h($cCover) ?>" alt=""><?php endif; ?>
            </div>
            <div class="club-item__body">
              <h3 class="club-item__title"><?= h($c['name']) ?></h3>
              <p class="club-item__sub"><?= h($c['category_name'] ?? '') ?></p>
              <div class="club-item__user">
                <?php if ($cAvatar): ?><img src="<?= h($cAvatar) ?>" alt=""><?php endif; ?>
                <span><?= clubs_fmt_compact((int)$cStats['members']) ?> عضو</span>
              </div>
              <a class="club-item__cta" href="<?= h(clubs_url($c)) ?>">ورود به کلاب <i class="bi bi-arrow-left"></i></a>
            </div>
          </article>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      <?php endif; ?>
    </section>

    <aside class="clubs-panel clubs-rail">
      <div class="clubs-rail__head">
        <h2>کلاب‌های فعال</h2>
        <a class="clubs-rail__add" href="<?= APP_URL ?>/clubs/create"><i class="bi bi-plus-lg"></i> ساخت کلاب</a>
      </div>
      <?php foreach ($mainCats as $mc):
        $catCount = clubs_count_in_root((int)$mc['id']);
      ?>
      <a class="clubs-rail__item<?= $activeCat && (int)$mc['id'] === (int)$activeCat['id'] ? ' is-on' : '' ?>" href="<?= h(clubs_hub_url((string)$mc['slug'])) ?>">
        <span class="clubs-rail__icon"><i class="<?= h(clubs_category_icon($mc['icon'] ?? '', (string)$mc['name'])) ?>"></i></span>
        <span>
          <span class="clubs-rail__name"><?= h($mc['name']) ?></span>
          <span class="clubs-rail__meta"><?= clubs_fmt_compact($catCount) ?> کلاب</span>
        </span>
      </a>
      <?php endforeach; ?>
      <div class="clubs-rail__promo">
        <p>کلاب خودت رو بساز! با چند کلیک، کلاب اختصاصی خودتو راه‌اندازی کن.</p>
        <a href="<?= APP_URL ?>/clubs/create">ساخت کلاب</a>
      </div>
    </aside>
  </div>
  <?php if ($showClubDetail): ?>
  <a class="clubs-fab" href="<?= h(clubs_url($club, 'chat')) ?>" aria-label="گفتگوی کلاب"><i class="bi bi-send-fill"></i></a>
  <?php endif; ?>
<?php endif; ?>
</main>
<?php
render_footer();
