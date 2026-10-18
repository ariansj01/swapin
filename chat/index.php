<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/dashboard_layout.php';
require_once __DIR__ . '/../includes/user_chat.php';

$user = require_auth();
$uid  = (int)$user['id'];

user_chat_ensure_tables();
$conversations = user_chat_list($uid, 200);
$unreadTotal   = user_chat_unread_count($uid);

render_head('گفتگوهای من', 'چت با خریداران و فروشندگان در ' . APP_NAME, ['robots' => 'noindex, nofollow']);
render_panel_styles();
render_navbar($user);
render_user_panel_open($user, 'chat');
?>
<link rel="stylesheet" href="<?= APP_URL ?>/src/css/user-chat.css?v=<?= @filemtime(__DIR__ . '/../src/css/user-chat.css') ?: time() ?>">

  <div class="dash-panel">
    <?php render_panel_page_header(
        'گفتگوهای من',
        empty($conversations)
            ? 'هنوز گفتگویی شروع نکرده‌اید'
            : fmt_num(count($conversations)) . ' گفتگو' . ($unreadTotal > 0 ? ' · ' . fmt_num($unreadTotal) . ' پیام خوانده‌نشده' : '')
    ); ?>

    <?php if (empty($conversations)): ?>
      <div class="empty-state">
        <i class="bi bi-chat-dots"></i>
        <h2>هنوز گفتگویی ندارید</h2>
        <p>برای شروع گفتگو، از صفحه یک آگهی روی «چت با فروشنده» بزنید.</p>
        <a href="<?= APP_URL ?>/" class="btn btn-primary">مشاهده آگهی‌ها</a>
      </div>
    <?php else: ?>
      <div class="uc-list">
        <?php foreach ($conversations as $c): ?>
          <?php
            $unread    = (int)($c['unread'] ?? 0);
            $lastAt    = $c['last_at'] ?: ($c['last_message_at'] ?: $c['created_at']);
            $lastBody  = trim((string)($c['last_body'] ?? ''));
            $otherName = (string)($c['other_name'] ?? 'کاربر');
          ?>
          <a href="<?= APP_URL ?>/chat/view?id=<?= (int)$c['id'] ?>"
             class="uc-conv <?= $unread > 0 ? 'uc-conv--unread' : '' ?>">
            <div class="uc-conv__avatar">
              <?= avatar_html($c['other_avatar'] ?? null, $otherName, 'md') ?>
            </div>
            <div class="uc-conv__body">
              <div class="uc-conv__top">
                <span class="uc-conv__name"><?= h($otherName) ?></span>
                <span class="uc-conv__time"><?= h($lastAt ? timeago($lastAt) : '') ?></span>
              </div>
              <?php if (!empty($c['listing_title'])): ?>
                <div class="uc-conv__listing">
                  <i class="bi bi-tag"></i>
                  <span><?= h((string)$c['listing_title']) ?></span>
                </div>
              <?php endif; ?>
              <div class="uc-conv__last">
                <?= $lastBody !== '' ? h(mb_strimwidth($lastBody, 0, 70, '…')) : 'شروع گفتگو' ?>
              </div>
            </div>
            <?php if ($unread > 0): ?>
              <span class="uc-conv__badge"><?= persian_digits((string)$unread) ?></span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
<?php render_user_panel_close(); ?>
<?php render_panel_scripts(); ?>
<?php render_footer(); ?>
