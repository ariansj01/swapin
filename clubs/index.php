<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/clubs.php';

$user = auth_user();
clubs_ensure_tables();
clubs_dispatch_from_hub();

$mainCats = clubs_main_categories();
$catSlug = clean($_GET['cat'] ?? '');
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
if (!$activeCat && $mainCats) {
    $activeCat = $mainCats[0];
}

$club = $activeCat ? clubs_featured_in_root((int)$activeCat['id']) : null;
$adBanner = clubs_banner_active();
$hubUrl = clubs_hub_url((string)($activeCat['slug'] ?? ''));

$flash = (string)($_SESSION['club_flash'] ?? '');
unset($_SESSION['club_flash']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    if (!$user) {
        header('Location: ' . APP_URL . '/auth/login?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/clubs'));
        exit;
    }
    $postedClub = clubs_get((int)($_POST['club_id'] ?? 0)) ?: $club;
    $action = clean($_POST['action'] ?? '');
    $redirTab = $tab;
    if ($postedClub && ($postedClub['status'] ?? '') === 'approved') {
        $clubId = (int)$postedClub['id'];
        $uid = (int)$user['id'];
        if ($action === 'join') {
            rate_limit_ip_or_fail('club_join', 20, 3600);
            $res = clubs_join($clubId, $uid, !empty($postedClub['require_approval']), (int)($_POST['listing_id'] ?? 0));
            $_SESSION['club_flash'] = empty($res['ok'])
                ? ($res['error'] ?? 'عضویت ناموفق بود.')
                : ((($res['status'] ?? '') === 'pending')
                    ? 'درخواست عضویت ارسال شد. مدیر کلاب آگهی و مشخصات شما را بررسی می‌کند.'
                    : 'به کلاب پیوستید.');
        } elseif ($action === 'follow') {
            $on = clubs_follow_toggle($clubId, $uid);
            $_SESSION['club_flash'] = $on ? 'کلاب را دنبال می‌کنید.' : 'دنبال کردن لغو شد.';
        } elseif ($action === 'chat') {
            $statePost = clubs_user_state($user, $clubId);
            if (!$statePost['member']) {
                $_SESSION['club_flash'] = 'برای ارسال پیام باید عضو کلاب باشید.';
            } else {
                rate_limit_ip_or_fail('club_chat', 30, 600);
                $res = clubs_post_message($clubId, $uid, (string)($_POST['body'] ?? ''));
                $_SESSION['club_flash'] = empty($res['ok']) ? ($res['error'] ?? 'ارسال ناموفق بود.') : '';
                $redirTab = 'chat';
            }
        }
    }
    header('Location: ' . clubs_hub_url((string)($activeCat['slug'] ?? ''), $redirTab, $q));
    exit;
}

$stats = $club ? clubs_stats($club) : ['members' => 0, 'posts' => 0, 'trades' => 0, 'views' => 0];
$state = $club ? clubs_user_state($user, (int)$club['id']) : ['member' => false, 'pending' => false, 'following' => false];
$previewMembers = $club ? clubs_members_list((int)$club['id'], 6) : [];
$perPage = 6;
$offset = ($page - 1) * $perPage;
$categoryClubs = $activeCat ? clubs_by_root_category((int)$activeCat['id']) : [];
$listMode = in_array($tab, ['buy', 'swap'], true) ? $tab : 'listings';
$listings = ($activeCat && in_array($tab, ['listings', 'buy', 'swap'], true))
    ? clubs_listings_in_root((int)$activeCat['id'], $listMode, $q, $perPage, $offset)
    : [];
$listingImages = clubs_listing_images_map(array_map(static fn ($row) => (int)$row['id'], $listings));
$members = ($club && $tab === 'members') ? clubs_members_list((int)$club['id']) : [];
$messages = ($club && $tab === 'chat') ? array_reverse(clubs_messages((int)$club['id'])) : [];

$coverUrl = $club ? clubs_image_url($club['cover_image'] ?? '') : '';
$avatarUrl = $club ? clubs_image_url($club['avatar_image'] ?? '') : '';
$bannerImg = $adBanner ? clubs_image_url((string)($adBanner['image'] ?? '')) : '';
$joinLabel = $state['member'] ? 'عضو کلاب هستید' : ($state['pending'] ? 'در انتظار تأیید' : 'عضویت در کلاب');
$title = $club ? ($club['name'] . ' | کلاب‌های سواپین') : (($activeCat['name'] ?? 'کلاب‌های سواپین') . ' | کلاب‌های سواپین');
$canonical = $hubUrl;
$cssVer = @filemtime(__DIR__ . '/../src/css/clubs-page.css') ?: time();
$loginUrl = APP_URL . '/auth/login?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/clubs');
$myListings = $user ? clubs_user_listings((int)$user['id']) : [];
$joinCreateUrl = APP_URL . '/listings/create';
$bannerTitle = (string)($adBanner['title'] ?? '');
$bannerSub = (string)($adBanner['subtitle'] ?? '');
if ($bannerTitle === '') {
    $bannerTitle = (string)($club['name'] ?? ($activeCat['name'] ?? 'کلاب‌های سواپین'));
}
if ($bannerSub === '') {
    $bannerSub = (string)($club['description'] ?? 'آدم‌های هم‌علاقه را پیدا کن و معامله را شروع کن.');
}
$bannerCta = trim((string)($adBanner['cta_label'] ?? ''));
$bannerHref = clubs_safe_link((string)($adBanner['link_url'] ?? ''));
if ($adBanner && $bannerCta === '') {
    $bannerCta = 'مشاهده';
}
if (!$adBanner && $club) {
    $bannerCta = $joinLabel;
    $bannerHref = $loginUrl;
}

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
      <?php if ($club): ?>
      <div class="clubs-profile__avatar">
        <?php if ($avatarUrl): ?><img src="<?= h($avatarUrl) ?>" alt=""><?php else: ?><i class="bi bi-people"></i><?php endif; ?>
      </div>
      <h2 class="clubs-profile__name"><a href="<?= h(clubs_url($club)) ?>" style="color:inherit;text-decoration:none"><?= h($club['name']) ?></a></h2>
      <div class="clubs-profile__stats">
        <div class="clubs-profile__stat"><strong><?= clubs_fmt_compact((int)$stats['members']) ?></strong><span>عضو</span></div>
        <div class="clubs-profile__stat"><strong><?= clubs_fmt_compact((int)$stats['posts']) ?></strong><span>پست</span></div>
        <div class="clubs-profile__stat"><strong><?= clubs_fmt_compact((int)$stats['trades']) ?></strong><span>معامله موفق</span></div>
      </div>
      <?php if ($user): ?>
      <?php if ($state['member'] || $state['pending']): ?>
      <button class="clubs-btn-navy" type="button" disabled><?= h($joinLabel) ?></button>
      <?php else: ?>
      <button type="button" class="clubs-btn-navy" data-open-join="<?= (int)$club['id'] ?>"><?= h($joinLabel) ?></button>
      <?php endif; ?>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="follow">
        <input type="hidden" name="club_id" value="<?= (int)$club['id'] ?>">
        <button class="clubs-btn-ghost<?= $state['following'] ? ' is-on' : '' ?>" type="submit">
          <i class="bi bi-plus-square"></i> <?= $state['following'] ? 'در حال دنبال کردن' : 'دنبال کردن' ?>
        </button>
      </form>
      <?php else: ?>
      <a class="clubs-btn-navy" href="<?= h($loginUrl) ?>">عضویت در کلاب</a>
      <a class="clubs-btn-ghost" href="<?= h($loginUrl) ?>"><i class="bi bi-plus-square"></i> دنبال کردن</a>
      <?php endif; ?>

      <?php if ($previewMembers): ?>
      <div class="clubs-profile__block">
        <h3>اعضای کلاب</h3>
        <div class="clubs-managers">
          <?php foreach ($previewMembers as $m): ?>
            <?= avatar_html($m['avatar'] ?? null, $m['name'] ?? '', 'sm') ?>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <div class="clubs-profile__block">
        <h3>درباره این کلاب</h3>
        <p class="clubs-profile__about"><?= h($club['description'] ?? '') ?></p>
        <a class="clubs-profile__rules" href="<?= h(clubs_hub_url((string)($activeCat['slug'] ?? ''), 'rules')) ?>">مشاهده قوانین</a>
      </div>
      <?php else: ?>
      <div class="clubs-profile__avatar"><i class="<?= h(clubs_category_icon($activeCat['icon'] ?? '', (string)($activeCat['name'] ?? ''))) ?>"></i></div>
      <h2 class="clubs-profile__name"><?= h($activeCat['name'] ?? 'کلاب‌های سواپین') ?></h2>
      <p class="clubs-profile__about">در این دسته‌بندی هنوز کلاب تأییدشده‌ای نیست.</p>
      <a class="clubs-btn-navy" href="<?= APP_URL ?>/clubs/create">ساخت کلاب</a>
      <?php endif; ?>
    </aside>

    <section class="clubs-main">
      <div class="clubs-banner<?= $adBanner ? ' is-ad' : '' ?>">
        <?php if ($bannerImg): ?><img src="<?= h($bannerImg) ?>" alt=""><?php endif; ?>
        <div class="clubs-banner__inner">
          <div class="clubs-banner__copy">
            <h1><?= h($bannerTitle) ?></h1>
            <p><?= h($bannerSub) ?></p>
          </div>
          <?php if ($bannerCta !== ''): ?>
            <?php if ($adBanner || !$user): ?>
            <a class="clubs-banner__cta" href="<?= h($bannerHref !== '' ? $bannerHref : '#') ?>"><?= h($bannerCta) ?></a>
            <?php elseif ($club): ?>
            <button type="button" class="clubs-banner__cta" data-open-join="<?= (int)$club['id'] ?>" <?= ($state['member'] || $state['pending']) ? 'disabled' : '' ?>><?= h($bannerCta) ?></button>
            <?php endif; ?>
          <?php endif; ?>
          <?php if ($club): ?>
          <div class="clubs-mbar">
            <div><strong><?= clubs_fmt_compact((int)$stats['members']) ?></strong><span>عضو</span></div>
            <div><strong><?= clubs_fmt_compact((int)$stats['posts']) ?></strong><span>پست</span></div>
            <div><strong><?= clubs_fmt_compact((int)$stats['trades']) ?></strong><span>معامله موفق</span></div>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($club): ?>
      <div class="clubs-mjoin">
        <?php if ($user): ?>
        <button type="button" class="clubs-btn-navy" data-open-join="<?= (int)$club['id'] ?>" <?= ($state['member'] || $state['pending']) ? 'disabled' : '' ?>><?= h($joinLabel) ?></button>
        <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="follow"><input type="hidden" name="club_id" value="<?= (int)$club['id'] ?>"><button class="clubs-btn-ghost<?= $state['following'] ? ' is-on' : '' ?>" type="submit"><?= $state['following'] ? 'در حال دنبال کردن' : 'دنبال کردن' ?></button></form>
        <?php else: ?>
        <a class="clubs-btn-navy" href="<?= h($loginUrl) ?>">عضویت در کلاب</a>
        <a class="clubs-btn-ghost" href="<?= h($loginUrl) ?>">دنبال کردن</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <nav class="clubs-mtabs">
        <?php foreach ($mobileTabs as $key => $label): ?>
        <a class="<?= $tab === $key ? 'is-on' : '' ?>" href="<?= h(clubs_hub_url((string)($activeCat['slug'] ?? ''), $key, $q)) ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
      </nav>

      <div class="clubs-toolbar">
        <nav class="clubs-tabs">
          <?php foreach ($desktopTabs as $key => $label): ?>
          <a class="<?= $tab === $key ? 'is-on' : '' ?>" href="<?= h(clubs_hub_url((string)($activeCat['slug'] ?? ''), $key, $q)) ?>"><?= h($label) ?></a>
          <?php endforeach; ?>
        </nav>
        <form class="clubs-search" method="get" action="<?= APP_URL ?>/clubs">
          <?php if ($activeCat): ?>
          <input type="hidden" name="cat" value="<?= h((string)$activeCat['slug']) ?>">
          <?php endif; ?>
          <?php if (in_array($tab, ['buy', 'swap'], true)): ?>
          <input type="hidden" name="tab" value="<?= h($tab) ?>">
          <?php endif; ?>
          <input type="search" name="q" value="<?= h($q) ?>" placeholder="جستجو در این کلاب...">
          <i class="bi bi-search"></i>
        </form>
      </div>

      <?php if ($tab === 'rules'): ?>
        <article class="clubs-rules">
          <h2>قوانین کلاب</h2>
          <?php if ($club && trim((string)($club['rules'] ?? '')) !== ''): ?>
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
          <?php if ($club && $state['member']): ?>
          <form class="clubs-chat-form" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="chat">
            <input type="hidden" name="club_id" value="<?= (int)$club['id'] ?>">
            <input type="text" name="body" maxlength="500" placeholder="پیام خود را بنویسید..." required>
            <button type="submit" aria-label="ارسال"><i class="bi bi-send-fill"></i></button>
          </form>
          <?php else: ?>
          <p class="fs-sm text-muted">برای گفتگو باید عضو کلاب شوید.</p>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <?php if (!$listings): ?>
          <div class="clubs-empty">آگهی‌ای در این دسته‌بندی پیدا نشد.</div>
        <?php else: ?>
          <div class="clubs-cards">
            <?php
            $clubStatsCache = [];
            $memberCache = [];
            foreach ($listings as $l):
              $ownerClub = clubs_resolve_for_listing($l, $categoryClubs) ?: $club;
              $ownerId = (int)($ownerClub['id'] ?? 0);
              if ($ownerId > 0 && !isset($clubStatsCache[$ownerId])) {
                  $clubStatsCache[$ownerId] = clubs_stats($ownerClub);
              }
              $cStats = $ownerId > 0 ? $clubStatsCache[$ownerId] : ['posts' => 0, 'trades' => 0];
              $clubLogo = $ownerClub ? (clubs_image_url($ownerClub['avatar_image'] ?? '') ?: clubs_image_url($ownerClub['cover_image'] ?? '')) : '';
              $imgs = $listingImages[(int)$l['id']] ?? [];
              if (!$imgs && !empty($l['thumb'])) {
                  $imgs[] = UPLOAD_URL . ltrim((string)$l['thumb'], '/');
              }
              $sellerAvatar = trim((string)($l['seller_avatar'] ?? ''));
              if ($sellerAvatar !== '' && !preg_match('#^https?://#i', $sellerAvatar) && !str_starts_with($sellerAvatar, '//')) {
                  $sellerAvatar = UPLOAD_URL . ltrim($sellerAvatar, '/');
              }
              $mode = (string)($l['listing_mode'] ?? 'swap');
              $modalPayload = [
                  'id' => (int)$l['id'],
                  'title' => (string)$l['title'],
                  'url' => listing_url($l),
                  'city' => (string)($l['city'] ?? ''),
                  'condition' => condition_label((string)($l['condition'] ?? '')),
                  'views' => clubs_fmt_compact((int)($l['views'] ?? 0)),
                  'price' => clubs_listing_price_label($l),
                  'mode' => clubs_deal_type_label($mode),
                  'modeBadge' => $mode === 'sell' ? 'فروش' : 'معاوضه',
                  'desc' => (string)($l['description'] ?? ''),
                  'want' => (string)($l['want_in_return'] ?? ''),
                  'images' => $imgs,
                  'seller' => [
                      'name' => (string)($l['seller_name'] ?? ''),
                      'avatar' => $sellerAvatar,
                      'since' => clubs_member_since_label($l['seller_since'] ?? null),
                      'rating' => fmt_num((float)($l['seller_rating'] ?? 0), 1),
                      'reviews' => fmt_num((int)($l['seller_rating_count'] ?? 0)),
                      'chat' => $user
                          ? (APP_URL . '/chat/view?listing_id=' . (int)$l['id'])
                          : $loginUrl,
                  ],
                  'club' => [
                      'id' => (int)($ownerClub['id'] ?? 0),
                      'name' => (string)($ownerClub['name'] ?? ''),
                      'logo' => $clubLogo,
                      'members' => clubs_fmt_compact((int)($cStats['members'] ?? 0)),
                      'posts' => clubs_fmt_compact((int)($cStats['posts'] ?? 0)),
                      'url' => $ownerClub ? clubs_url($ownerClub) : '',
                      'isMember' => $ownerId > 0 ? ($memberCache[$ownerId] ??= !empty(clubs_user_state($user, $ownerId)['member'])) : false,
                  ],
              ];
            ?>
            <article class="club-item">
              <div class="club-item__media">
                <?php if (!empty($l['thumb'])): ?>
                <img src="<?= h(UPLOAD_URL . $l['thumb']) ?>" alt="">
                <?php endif; ?>
                <span class="club-item__views"><i class="bi bi-eye"></i> <?= clubs_fmt_compact((int)($l['views'] ?? 0)) ?></span>
              </div>
              <div class="club-item__body">
                <div class="club-item__head">
                  <div class="club-item__logo">
                    <?php if ($clubLogo): ?>
                    <img src="<?= h($clubLogo) ?>" alt="">
                    <?php else: ?>
                    <i class="bi bi-people"></i>
                    <?php endif; ?>
                  </div>
                  <div class="club-item__copy">
                    <h3 class="club-item__title"><?= h($l['title']) ?></h3>
                    <p class="club-item__sub"><?php if ($ownerClub): ?><a href="<?= h(clubs_url($ownerClub)) ?>" style="color:inherit;text-decoration:none"><?= h($ownerClub['name']) ?></a><?php endif; ?></p>
                  </div>
                </div>
                <div class="club-item__stats">
                  <span><i class="bi bi-arrow-left-right"></i> <?= clubs_fmt_compact((int)($cStats['trades'] ?? 0)) ?> معامله</span>
                  <span><i class="bi bi-box-seam"></i> <?= clubs_fmt_compact((int)($cStats['posts'] ?? 0)) ?> آگهی</span>
                </div>
                <button type="button" class="club-item__cta" data-club-listing="<?= h(json_encode($modalPayload, JSON_UNESCAPED_UNICODE)) ?>">مشاهده جزئیات <i class="bi bi-arrow-left"></i></button>
              </div>
            </article>
            <?php endforeach; ?>
          </div>
          <?php if (count($listings) >= $perPage): ?>
          <a class="clubs-more" href="<?= h(clubs_hub_url((string)($activeCat['slug'] ?? ''), $tab, $q, $page + 1)) ?>">بارگذاری بیشتر...</a>
          <?php endif; ?>
        <?php endif; ?>

        <div class="clubs-ai">
          <i class="bi bi-stars" style="font-size:1.4rem;color:#2563eb"></i>
          <h3>دستیار هوشمند سواپین</h3>
          <p>قیمت بده، پیشنهاد بگیر و معامله‌ات را سریع‌تر جلو ببر.</p>
          <a class="clubs-banner__cta" href="<?= APP_URL ?>/ai/chat">شروع تضمین با دستیار هوشمند</a>
        </div>
        <?php if ($club): ?>
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
          <a class="clubs-more" href="<?= h(clubs_hub_url((string)($activeCat['slug'] ?? ''), 'chat')) ?>">مشاهده گفتگو</a>
        </div>
        <?php endif; ?>
        <div class="clubs-notice">
          <h3>اعلان کلاب</h3>
          <p>این آگهی ویژه هفته کلاب است. کالای پیشنهادی را با شرایط کلاب معامله کنید.</p>
        </div>
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
  <?php if ($club): ?>
  <a class="clubs-fab" href="<?= h(clubs_hub_url((string)($activeCat['slug'] ?? ''), 'chat')) ?>" aria-label="گفتگوی کلاب"><i class="bi bi-send-fill"></i></a>
  <?php endif; ?>
<?php endif; ?>
</main>

<div class="clm" id="club-listing-modal" hidden>
  <div class="clm__backdrop" data-clm-close></div>
  <div class="clm__dialog" role="dialog" aria-modal="true" aria-labelledby="clm-title">
    <div class="clm__gallery">
      <div class="clm__hero">
        <img id="clm-hero" src="" alt="">
        <span class="clm__count" id="clm-count"><i class="bi bi-camera"></i> <span>1 / 1</span></span>
      </div>
      <div class="clm__thumbs" id="clm-thumbs"></div>
      <div class="clm__actions">
        <div class="clm__deal"><i class="bi bi-arrow-left-right"></i> نوع معامله: <b id="clm-mode"></b></div>
        <a class="clm__join" id="clm-enter" href="#" hidden>ورود به کلاب</a>
        <button type="button" class="clm__join" id="clm-join-btn" data-open-join="0"><i class="bi bi-people"></i> پیوستن به کلاب</button>
        <button type="button" class="clm__ghost" id="clm-save" data-listing-id="0"><i class="bi bi-bookmark"></i> ذخیره آگهی</button>
        <button type="button" class="clm__ghost" id="clm-share"><i class="bi bi-share"></i> اشتراک‌گذاری</button>
      </div>
    </div>
    <div class="clm__body">
      <div class="clm__top">
        <button type="button" class="clm__close" data-clm-close aria-label="بستن"><i class="bi bi-x-lg"></i></button>
        <span class="clm__badge" id="clm-badge">معاوضه</span>
      </div>
      <h2 class="clm__title" id="clm-title"></h2>
      <div class="clm__meta">
        <span id="clm-city"><i class="bi bi-geo-alt"></i> <em></em></span>
        <span id="clm-cond"><i class="bi bi-box-seam"></i> <em></em></span>
        <span id="clm-views"><i class="bi bi-eye"></i> <em></em> بازدید</span>
      </div>
      <div class="clm__price"><i class="bi bi-gem"></i> <strong id="clm-price"></strong></div>

      <div class="clm__seller">
        <img class="clm__seller-av" id="clm-seller-av" src="" alt="">
        <div class="clm__seller-copy">
          <strong id="clm-seller-name"></strong>
          <span id="clm-seller-since"></span>
        </div>
        <div class="clm__seller-side">
          <div class="clm__rate"><i class="bi bi-star-fill"></i> <b id="clm-rate"></b> <small id="clm-reviews"></small></div>
          <a class="clm__chat" id="clm-chat" href="#"><i class="bi bi-chat-dots"></i> چت با فروشنده</a>
        </div>
      </div>

      <div class="clm__clubbox">
        <h3><i class="bi bi-people"></i> این آگهی در کلاب‌های زیر قابل مشاهده است:</h3>
        <div class="clm__clubrow">
          <img id="clm-club-logo" src="" alt="">
          <div>
            <strong id="clm-club-name"></strong>
            <div class="clm__clubstats">
              <span><i class="bi bi-people"></i> <em id="clm-club-members"></em> عضو</span>
              <span><i class="bi bi-megaphone"></i> <em id="clm-club-posts"></em> آگهی</span>
            </div>
          </div>
          <a class="clm__club-go" id="clm-club-go" href="#"><i class="bi bi-chevron-left"></i> مشاهده کلاب</a>
        </div>
      </div>

      <div class="clm__tabs">
        <button type="button" class="is-on" data-clm-tab="desc">توضیحات</button>
        <button type="button" data-clm-tab="want">مبادله با</button>
        <button type="button" data-clm-tab="comments">نظرات کاربران</button>
      </div>
      <div class="clm__panel is-on" data-clm-panel="desc" id="clm-desc"></div>
      <div class="clm__panel" data-clm-panel="want" id="clm-want"></div>
      <div class="clm__panel" data-clm-panel="comments">هنوز نظری برای این آگهی ثبت نشده است.</div>
    </div>
  </div>
</div>
<script>
(function () {
  const modal = document.getElementById('club-listing-modal');
  if (!modal) return;
  const hero = document.getElementById('clm-hero');
  const thumbs = document.getElementById('clm-thumbs');
  const countEl = document.getElementById('clm-count').querySelector('span');
  let images = [];
  let idx = 0;
  let listingUrl = '';

  function setImg(i) {
    if (!images.length) return;
    idx = (i + images.length) % images.length;
    hero.src = images[idx];
    countEl.textContent = (idx + 1) + ' / ' + images.length;
    thumbs.querySelectorAll('button').forEach((b, n) => b.classList.toggle('is-on', n === idx));
  }
  function closeModal() {
    modal.hidden = true;
    document.body.classList.remove('clm-open');
  }
  function openModal(data) {
    images = Array.isArray(data.images) && data.images.length ? data.images : [];
    listingUrl = data.url || '';
    document.getElementById('clm-title').textContent = data.title || '';
    document.getElementById('clm-badge').textContent = data.modeBadge || 'معاوضه';
    document.querySelector('#clm-city em').textContent = data.city || '—';
    document.querySelector('#clm-cond em').textContent = data.condition || '—';
    document.querySelector('#clm-views em').textContent = data.views || '۰';
    document.getElementById('clm-price').textContent = data.price || '';
    document.getElementById('clm-mode').textContent = data.mode || 'معاوضه';
    document.getElementById('clm-desc').textContent = data.desc || 'توضیحی ثبت نشده است.';
    document.getElementById('clm-want').textContent = data.want || 'موردی برای مبادله ثبت نشده است.';
    const s = data.seller || {};
    const av = document.getElementById('clm-seller-av');
    if (s.avatar) { av.src = s.avatar; av.style.display = ''; }
    else { av.removeAttribute('src'); av.style.display = 'none'; }
    document.getElementById('clm-seller-name').textContent = s.name || '';
    document.getElementById('clm-seller-since').textContent = s.since || '';
    document.getElementById('clm-rate').textContent = s.rating || '۰';
    document.getElementById('clm-reviews').textContent = '(' + (s.reviews || '۰') + ' نظر)';
    document.getElementById('clm-chat').href = s.chat || '#';
    const c = data.club || {};
    const logo = document.getElementById('clm-club-logo');
    if (c.logo) { logo.src = c.logo; logo.style.display = ''; }
    else { logo.removeAttribute('src'); logo.style.display = 'none'; }
    document.getElementById('clm-club-name').textContent = c.name || '';
    document.getElementById('clm-club-members').textContent = c.members || '۰';
    document.getElementById('clm-club-posts').textContent = c.posts || '۰';
    document.getElementById('clm-club-go').href = c.url || '#';
    const joinBtn = document.getElementById('clm-join-btn');
    const enterBtn = document.getElementById('clm-enter');
    if (joinBtn) {
      joinBtn.setAttribute('data-open-join', String(c.id || 0));
      joinBtn.hidden = !!c.isMember;
    }
    if (enterBtn) {
      enterBtn.href = c.url || '#';
      enterBtn.hidden = !(c.url);
    }
    const saveBtn = document.getElementById('clm-save');
    saveBtn.dataset.listingId = data.id || 0;
    saveBtn.dataset.saved = 'false';
    saveBtn.innerHTML = '<i class="bi bi-bookmark"></i> ذخیره آگهی';
    thumbs.innerHTML = '';
    images.forEach((src, n) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.innerHTML = '<img alt="">';
      b.firstChild.src = src;
      b.addEventListener('click', () => setImg(n));
      thumbs.appendChild(b);
    });
    setImg(0);
    modal.querySelectorAll('[data-clm-tab]').forEach(t => t.classList.toggle('is-on', t.dataset.clmTab === 'desc'));
    modal.querySelectorAll('[data-clm-panel]').forEach(p => p.classList.toggle('is-on', p.dataset.clmPanel === 'desc'));
    modal.hidden = false;
    document.body.classList.add('clm-open');
  }
  document.querySelectorAll('[data-club-listing]').forEach(btn => {
    btn.addEventListener('click', () => {
      try { openModal(JSON.parse(btn.getAttribute('data-club-listing') || '{}')); } catch (e) {}
    });
  });
  modal.querySelectorAll('[data-clm-close]').forEach(el => el.addEventListener('click', closeModal));
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && !modal.hidden) closeModal(); });
  modal.querySelectorAll('[data-clm-tab]').forEach(tab => {
    tab.addEventListener('click', () => {
      const key = tab.dataset.clmTab;
      modal.querySelectorAll('[data-clm-tab]').forEach(t => t.classList.toggle('is-on', t === tab));
      modal.querySelectorAll('[data-clm-panel]').forEach(p => p.classList.toggle('is-on', p.dataset.clmPanel === key));
    });
  });
  document.getElementById('clm-save').addEventListener('click', async () => {
    const btn = document.getElementById('clm-save');
    const id = btn.dataset.listingId;
    if (!id) return;
    const fd = new FormData();
    fd.append('listing_id', id);
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    if (csrf) fd.append('_csrf', csrf);
    try {
      const res = await fetch(<?= json_encode(APP_URL . '/api/save_listing.php') ?>, {
        method: 'POST', body: fd, credentials: 'same-origin',
        headers: csrf ? { 'X-CSRF-Token': csrf } : {}
      });
      const data = await res.json();
      if (!res.ok && data.error === 'login_required') {
        window.location.href = <?= json_encode($loginUrl) ?>;
        return;
      }
      const saved = !!data.saved;
      btn.dataset.saved = saved ? 'true' : 'false';
      btn.innerHTML = saved
        ? '<i class="bi bi-bookmark-fill"></i> ذخیره شده'
        : '<i class="bi bi-bookmark"></i> ذخیره آگهی';
    } catch (e) {}
  });
  document.getElementById('clm-share').addEventListener('click', async () => {
    if (!listingUrl) return;
    try {
      if (navigator.share) await navigator.share({ url: listingUrl, title: document.getElementById('clm-title').textContent });
      else { await navigator.clipboard.writeText(listingUrl); }
    } catch (e) {}
  });
})();
</script>
<?php
require __DIR__ . '/_join_modal.php';
render_footer();
