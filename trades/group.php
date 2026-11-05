<?php
/**
 * اتاق مبادله چندنفره (۳ تا ۵ نفر)
 * ------------------------------------------------------------------
 * همان زبان بصری «اتاق امن» را دارد، اما در یک اتاق مشترک وضعیت کل گروه
 * را نشان می‌دهد و هر عضو مراحل طرف مقابل خودش را انجام می‌دهد.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/dashboard_layout.php';
require_once __DIR__ . '/../includes/multi_trade.php';

$user = require_auth();
$uid  = (int)$user['id'];

multi_trade_ensure_tables();

function mt_flash_set(string $type, string $msg): void { $_SESSION['mt_flash'] = [$type, $msg]; }
function mt_flash_get(): ?array {
    if (empty($_SESSION['mt_flash'])) return null;
    $f = $_SESSION['mt_flash']; unset($_SESSION['mt_flash']); return $f;
}

$roomId = (int)($_GET['id'] ?? 0);
if (!$roomId) { header('Location: ' . APP_URL . '/trades'); exit; }
$room = multi_trade_room($roomId);
if (!$room) { header('Location: ' . APP_URL . '/trades'); exit; }

$myMember  = multi_trade_member($roomId, $uid);
$isMember  = $myMember && $myMember['status'] === 'active';
$isInvited = $myMember && $myMember['status'] === 'invited';

// اتاق لغو‌شده یا عضوی که ترک کرده است، به لیست معاملات بازمی‌گردد
if ((string)$room['status'] === 'cancelled' || ($myMember && $myMember['status'] === 'left')) {
    header('Location: ' . APP_URL . '/trades');
    exit;
}

// ─── AJAX: دریافت پیام‌های چت گروهی ─────────────────────────────────────────
if (($_GET['action'] ?? '') === 'messages' && $isMember) {
    header('Content-Type: application/json; charset=utf-8');
    $since = (int)($_GET['since'] ?? 0);
    $rows = [];
    foreach (multi_trade_messages($roomId, $since) as $m) {
        $rows[] = [
            'id'        => (int)$m['id'],
            'mine'      => (int)($m['user_id'] ?? 0) === $uid,
            'system'    => $m['type'] === 'system',
            'name'      => (string)($m['user_name'] ?? ''),
            'initial'   => mb_substr((string)($m['user_name'] ?? '?'), 0, 1),
            'body'      => (string)$m['body'],
            'time'      => persian_datetime((string)$m['created_at']),
        ];
    }
    echo json_encode(['ok' => true, 'messages' => $rows], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── پردازش اقدام‌ها ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = ($_POST['ajax'] ?? '') === '1';
    csrf_verify_or_fail($isAjax);
    $action = clean($_POST['action'] ?? '');
    $result = ['ok' => true];

    switch ($action) {
        case 'accept_invite':
            $result = multi_trade_accept_invite($roomId, $uid);
            break;
        case 'decline_invite':
            $result = multi_trade_decline_invite($roomId, $uid);
            if (!isset($result['error'])) { header('Location: ' . APP_URL . '/trades'); exit; }
            break;
        case 'join_request':
            $listingId = (int)($_POST['listing_id'] ?? 0);
            $result = multi_trade_join_request($roomId, $uid, $listingId);
            break;
        case 'vote_join':
            $result = multi_trade_vote_join((int)($_POST['request_id'] ?? 0), $uid, ((int)($_POST['approve'] ?? 0)) === 1);
            break;
        case 'vote_close':
            $result = multi_trade_vote_close($roomId, $uid);
            break;
        case 'leave_room':
            $result = multi_trade_leave_room($roomId, $uid);
            if (!isset($result['error'])) { header('Location: ' . APP_URL . '/trades'); exit; }
            break;
        case 'select_target':
            $result = multi_trade_select_target($roomId, $uid, (int)($_POST['target_member_id'] ?? 0));
            break;
        case 'pay_fee':
            $result = multi_trade_pay_fee($roomId, $uid);
            break;
        case 'pay_diff':
            $result = multi_trade_pay_diff($roomId, $uid);
            break;
        case 'sign_track':
            $result = multi_trade_sign_track((int)($_POST['track_id'] ?? 0), $uid);
            break;
        case 'set_shipping':
            $result = multi_trade_set_track_shipping(
                (int)($_POST['track_id'] ?? 0), $uid,
                clean($_POST['method'] ?? ''), clean($_POST['shipping_date'] ?? ''), clean($_POST['shipping_time'] ?? '')
            );
            break;
        case 'mark_shipped':
            $result = multi_trade_mark_shipped((int)($_POST['track_id'] ?? 0), $uid, clean($_POST['tracking_code'] ?? ''));
            break;
        case 'confirm_received':
            $result = multi_trade_confirm_received((int)($_POST['track_id'] ?? 0), $uid);
            break;
        case 'rate_track':
            $result = multi_trade_rate_track((int)($_POST['track_id'] ?? 0), $uid, (int)($_POST['score'] ?? 0), clean($_POST['comment'] ?? ''));
            break;
        case 'send_message':
            $result = multi_trade_send_message($roomId, $uid, (string)($_POST['body'] ?? ''));
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode($result, JSON_UNESCAPED_UNICODE);
                exit;
            }
            break;
        default:
            $result = ['error' => 'اقدام نامعتبر است.'];
    }

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (isset($result['error'])) {
        mt_flash_set('danger', $result['error']);
    } elseif (isset($result['cycle_error'])) {
        mt_flash_set('warning', $result['cycle_error']);
    } else {
        mt_flash_set('success', 'انجام شد.');
    }
    header('Location: ' . multi_trade_room_url($roomId));
    exit;
}

$flash = mt_flash_get();
$board = multi_trade_board($roomId);
$members    = $board['members'];
$memberById = $board['memberById'];
$tracks     = $board['tracks'];
$stepByTrack = $board['stepByTrack'];

$activeCount = count($members);
$capacityFull = $activeCount >= (int)$room['members_max'];
$allClosed = multi_trade_all_close_votes($roomId);
$myTargetsComplete = $isMember && (int)($myMember['target_member_id'] ?? 0) > 0;

// مسیرهای من
$myTracks = [];
foreach ($tracks as $t) {
    if ((int)$t['deliverer_user_id'] === $uid || (int)$t['receiver_user_id'] === $uid) {
        $myTracks[] = $t;
    }
}

$pendingRequests = $isMember && $room['status'] === 'forming' ? multi_trade_join_requests_pending($roomId) : [];
$myJoinRequest   = (!$isMember && !$isInvited) ? multi_trade_join_request_for_user($roomId, $uid) : null;
$myListings      = (!$isMember && !$isInvited) ? DB::fetchAll(
    "SELECT id, title FROM listings WHERE user_id = ? AND status = 'active' ORDER BY id DESC LIMIT 30",
    [$uid]
) : [];

render_head('مبادله چندنفره #' . $roomId, 'اتاق مبادله چندنفره (۳ تا ۵ نفر)', [
    'robots' => 'noindex, nofollow',
]);
render_panel_styles();
echo '<link rel="stylesheet" href="' . APP_URL . '/src/css/secure-trade.css">' . "\n";
render_navbar($user);
render_user_panel_open($user, 'trades');
?>

<style>
.mt-room { max-width: 1100px; margin: 0 auto; padding: var(--sp-4); }
.mt-head { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom: var(--sp-4); }
.mt-head h1 { font-size: 22px; color:#071A33; margin:0; }
.mt-head .mt-sub { color:#5a6b85; font-size:13px; margin-top:4px; }
.mt-pill { display:inline-flex; align-items:center; gap:6px; padding:6px 12px; border-radius:999px; font-size:12px; font-weight:700; }
.mt-pill--forming { background: rgba(0,102,255,.10); color:#0066ff; }
.mt-pill--locked  { background: rgba(255,201,40,.18); color:#8a6d00; }
.mt-pill--active  { background: rgba(7,26,51,.08); color:#071A33; }
.mt-pill--completed { background: rgba(20,160,90,.12); color:#128a4d; }
.mt-card { background:#fff; border:1px solid #e6ebf3; border-radius:18px; padding:18px; margin-bottom:16px; box-shadow:0 6px 20px rgba(7,26,51,.05); }
.mt-card h2 { font-size:16px; margin:0 0 12px; color:#071A33; display:flex; align-items:center; gap:8px; }
.mt-members { display:grid; grid-template-columns:repeat(auto-fill,minmax(190px,1fr)); gap:12px; }
.mt-member { display:flex; gap:10px; align-items:center; border:1px solid #e6ebf3; border-radius:14px; padding:10px; background:#fbfcfe; }
.mt-member img, .mt-member .mt-av { width:44px; height:44px; border-radius:12px; object-fit:cover; background:#071A33; color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; }
.mt-member__name { font-weight:700; font-size:14px; color:#071A33; }
.mt-member__meta { font-size:12px; color:#6c7a92; }
.mt-member__badges { display:flex; gap:6px; flex-wrap:wrap; margin-top:6px; }
.mt-badge { font-size:11px; padding:2px 8px; border-radius:999px; font-weight:700; }
.mt-badge--ok { background:rgba(20,160,90,.12); color:#128a4d; }
.mt-badge--wait { background:rgba(255,102,0,.12); color:#c25200; }
.mt-btn { display:inline-flex; align-items:center; gap:6px; border:none; cursor:pointer; border-radius:12px; padding:10px 16px; font-weight:700; font-size:13px; }
.mt-btn--primary { background:#071A33; color:#fff; }
.mt-btn--gold { background:#FFC928; color:#071A33; }
.mt-btn--ghost { background:#f1f4f9; color:#071A33; }
.mt-btn--danger { background:#ffe9e6; color:#c0392b; }
.mt-btn:disabled { opacity:.5; cursor:not-allowed; }
.mt-track { border:1px solid #e6ebf3; border-radius:14px; padding:14px; margin-bottom:12px; background:#fff; }
.mt-track--mine { border-color:#FFC928; box-shadow:0 4px 16px rgba(255,201,40,.18); }
.mt-track__head { display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; }
.mt-track__flow { display:flex; align-items:center; gap:8px; font-weight:700; color:#071A33; }
.mt-track__step { font-size:12px; color:#6c7a92; margin-top:6px; }
.mt-actions { display:flex; gap:8px; flex-wrap:wrap; margin-top:10px; }
.mt-input, .mt-select { border:1px solid #dbe3ee; border-radius:10px; padding:8px 10px; font-size:13px; font-family:inherit; }
.mt-cycle { display:flex; flex-wrap:wrap; gap:8px; align-items:center; font-size:13px; }
.mt-cycle span.mt-node { background:#f1f4f9; border-radius:10px; padding:6px 10px; font-weight:700; color:#071A33; }
.mt-chat { display:flex; flex-direction:column; gap:10px; max-height:420px; overflow-y:auto; padding:6px; background:#f8fafd; border-radius:14px; }
.mt-msg { display:flex; gap:8px; align-items:flex-start; }
.mt-msg--mine { flex-direction:row-reverse; }
.mt-msg__av { width:32px; height:32px; border-radius:10px; background:#071A33; color:#fff; display:flex; align-items:center; justify-content:center; font-size:13px; flex:0 0 auto; }
.mt-msg__bubble { background:#fff; border:1px solid #e6ebf3; border-radius:12px; padding:8px 12px; max-width:75%; font-size:13px; }
.mt-msg--mine .mt-msg__bubble { background:#071A33; color:#fff; border-color:#071A33; }
.mt-msg__meta { font-size:10px; color:#93a1b8; margin-top:4px; }
.mt-msg--system { justify-content:center; }
.mt-msg--system .mt-msg__bubble { background:rgba(255,201,40,.16); color:#7a6100; border-color:rgba(255,201,40,.4); text-align:center; font-size:12px; max-width:92%; }
.mt-chat-form { display:flex; gap:8px; margin-top:10px; }
.mt-chat-form input { flex:1; }
.mt-note { background:#f8fafd; border:1px dashed #dbe3ee; border-radius:12px; padding:12px; font-size:13px; color:#42536e; }
.mt-steps-locked__note { display:flex; align-items:center; gap:8px; background:rgba(255,201,40,.14); border:1px solid rgba(255,201,40,.5); border-radius:12px; padding:10px 12px; font-size:13px; font-weight:600; color:#7a6100; margin-bottom:12px; }
.mt-steps-locked__body { filter: blur(4px); opacity:.5; pointer-events:none; user-select:none; }
.mt-tabs { display:flex; gap:8px; margin-bottom:14px; border-bottom:1px solid #e6ebf3; }
.mt-tab { border:none; background:transparent; cursor:pointer; padding:10px 14px; font-size:14px; font-weight:700; color:#6c7a92; border-bottom:3px solid transparent; font-family:inherit; }
.mt-tab.is-active { color:#071A33; border-bottom-color:#FFC928; }
.mt-tab-pane { display:none; }
.mt-tab-pane.is-active { display:block; }
.mt-alert { border-radius:12px; padding:12px 14px; font-size:13px; margin-bottom:14px; font-weight:600; }
.mt-alert--success { background:rgba(20,160,90,.10); color:#128a4d; }
.mt-alert--danger  { background:rgba(200,40,40,.10); color:#c0392b; }
.mt-alert--warning { background:rgba(255,102,0,.12); color:#c25200; }
@media (max-width: 767px){
  .mt-room { padding: 12px; }
  .mt-track__flow { font-size:13px; }
}
</style>

<?php
$statusClass = 'mt-pill--' . ($room['status'] === 'awaiting_seed' ? 'forming' : $room['status']);
?>
<div class="mt-room">
  <div class="mt-head">
    <div>
      <h1><i class="bi bi-people-fill"></i> اتاق مبادله چندنفره #<?= $roomId ?></h1>
      <div class="mt-sub">مبادله گروهی بین <?= MT_ROOM_MIN ?> تا <?= MT_ROOM_MAX ?> نفر — هر عضو فقط با طرف مقابل خودش مراحل را پیش می‌برد.</div>
    </div>
    <div>
      <span class="mt-pill <?= h($statusClass) ?>"><?= h(multi_trade_room_status_label((string)$room['status'])) ?></span>
      <?php if ($isMember && in_array($room['status'], ['awaiting_seed', 'forming'], true)): ?>
        <?php if ($activeCount <= 2): ?>
        <form method="post" style="display:inline-block; margin-inline-start:8px;" onsubmit="return confirm('با این کار معامله به‌طور کامل بسته می‌شود و آگهی‌ها به حالت فعال برمی‌گردند. مطمئن هستید؟');">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="leave_room">
          <button type="submit" class="mt-btn mt-btn--danger"><i class="bi bi-x-octagon"></i> ترک و بستن معامله</button>
        </form>
        <?php else: ?>
        <form method="post" style="display:inline-block; margin-inline-start:8px;" onsubmit="return confirm('آیا از ترک این معامله مطمئن هستید؟ فقط شما از گروه خارج می‌شوید.');">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="leave_room">
          <button type="submit" class="mt-btn mt-btn--danger"><i class="bi bi-box-arrow-right"></i> ترک معامله</button>
        </form>
        <?php endif; ?>
      <?php endif; ?>
      <a href="<?= APP_URL ?>/trades" class="mt-btn mt-btn--ghost" style="margin-inline-start:8px;"><i class="bi bi-arrow-right"></i> بازگشت</a>
    </div>
  </div>

  <?php if ($flash): ?>
  <div class="mt-alert mt-alert--<?= h($flash[0]) ?>"><?= h($flash[1]) ?></div>
  <?php endif; ?>

<?php if ($isInvited): ?>
  <!-- دعوت به تأیید طرف مقابل -->
  <div class="mt-card">
    <h2><i class="bi bi-envelope-paper"></i> دعوت به مبادله چندنفره</h2>
    <p class="mt-note">طرف مقابل درخواست داده این معامله به‌صورت «مبادله چندنفره» (۳ تا ۵ نفر) انجام شود. با تأیید شما اتاق فعال می‌شود و اعضای دیگر می‌توانند درخواست پیوستن بدهند.</p>
    <div class="mt-actions">
      <form method="post"><?= csrf_field() ?>
        <input type="hidden" name="action" value="accept_invite">
        <button class="mt-btn mt-btn--gold" type="submit"><i class="bi bi-check2-circle"></i> تأیید و فعال‌سازی اتاق</button>
      </form>
      <form method="post" onsubmit="return confirm('با رد دعوت، اتاق لغو می‌شود. مطمئن هستید؟');"><?= csrf_field() ?>
        <input type="hidden" name="action" value="decline_invite">
        <button class="mt-btn mt-btn--danger" type="submit"><i class="bi bi-x-circle"></i> رد دعوت</button>
      </form>
    </div>
  </div>

<?php elseif (!$isMember): ?>
  <!-- کاربر غیرعضو: درخواست عضویت -->
  <div class="mt-card">
    <h2><i class="bi bi-person-plus"></i> درخواست پیوستن به اتاق</h2>
    <?php if ($room['status'] !== 'forming'): ?>
      <div class="mt-alert mt-alert--warning">این اتاق در حال حاضر عضو نمی‌پذیرد.</div>
    <?php elseif ($capacityFull): ?>
      <div class="mt-alert mt-alert--warning">ظرفیت این اتاق (<?= MT_ROOM_MAX ?> نفر) تکمیل شده است.</div>
    <?php elseif ($myJoinRequest): ?>
      <div class="mt-alert mt-alert--success">درخواست شما ثبت شد و در انتظار تأیید همه اعضای اتاق است.</div>
    <?php elseif (!$myListings): ?>
      <div class="mt-alert mt-alert--warning">برای پیوستن باید یک آگهی فعال داشته باشید.</div>
    <?php else: ?>
      <p class="mt-note">برای پیوستن، کالایی که می‌خواهید وارد مبادله کنید را انتخاب کنید. ورود شما نیازمند تأیید <b>همه‌ی</b> اعضای فعلی اتاق است.</p>
      <form method="post" style="margin-top:12px; display:flex; gap:8px; flex-wrap:wrap; align-items:center;"><?= csrf_field() ?>
        <input type="hidden" name="action" value="join_request">
        <select name="listing_id" class="mt-select" required>
          <option value="">انتخاب آگهی…</option>
          <?php foreach ($myListings as $l): ?>
            <option value="<?= (int)$l['id'] ?>"><?= h($l['title']) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="mt-btn mt-btn--primary" type="submit"><i class="bi bi-send"></i> ارسال درخواست عضویت</button>
      </form>
    <?php endif; ?>
  </div>

<?php else: ?>
  <!-- ══════════ نمایش کامل اتاق برای اعضای فعال ══════════ -->

  <!-- درخواست‌های پیوستن (بالای لیست اعضا) -->
  <?php if ($pendingRequests): ?>
  <div class="mt-card" style="border:2px solid #FFC928;">
    <h2><i class="bi bi-inbox"></i> درخواست‌های پیوستن</h2>
    <p class="mt-note" style="margin-bottom:12px;">با موافقت <b>همه‌ی</b> اعضای فعلی، این افراد به لیست اعضا اضافه می‌شوند.</p>
    <?php foreach ($pendingRequests as $r): ?>
      <div class="mt-track">
        <div class="mt-track__head">
          <div class="mt-track__flow">
            <span class="mt-av" style="width:32px;height:32px;border-radius:10px;"><?= h(mb_substr((string)$r['user_name'], 0, 1)) ?></span>
            <?= h($r['user_name']) ?>
            <span style="color:#6c7a92;font-weight:400;">— <?= h($r['listing_title'] ?? 'بدون آگهی') ?></span>
          </div>
          <div style="font-size:12px;color:#6c7a92;">تأییدها: <?= (int)$r['approve_count'] ?>/<?= $activeCount ?></div>
        </div>
        <?php
          $myVote = DB::fetch('SELECT decision FROM multi_trade_join_votes WHERE request_id = ? AND member_id = ?', [(int)$r['id'], (int)$myMember['id']]);
        ?>
        <div class="mt-actions">
          <?php if ($myVote): ?>
            <span class="mt-badge <?= $myVote['decision'] === 'approve' ? 'mt-badge--ok' : 'mt-badge--wait' ?>">نظر شما: <?= $myVote['decision'] === 'approve' ? 'موافق' : 'مخالف' ?></span>
          <?php else: ?>
            <form method="post"><?= csrf_field() ?>
              <input type="hidden" name="action" value="vote_join"><input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="approve" value="1">
              <button class="mt-btn mt-btn--gold" type="submit"><i class="bi bi-check2"></i> موافقم</button>
            </form>
            <form method="post"><?= csrf_field() ?>
              <input type="hidden" name="action" value="vote_join"><input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="approve" value="0">
              <button class="mt-btn mt-btn--danger" type="submit"><i class="bi bi-x"></i> مخالفم</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="mt-card">
    <h2><i class="bi bi-people"></i> اعضای اتاق (<?= $activeCount ?> از <?= (int)$room['members_max'] ?>)</h2>
    <div class="mt-members">
      <?php foreach ($members as $m):
        $isMe = (int)$m['user_id'] === $uid;
      ?>
      <div class="mt-member">
        <?php if (!empty($m['listing_thumb'])): ?>
          <img src="<?= UPLOAD_URL . h($m['listing_thumb']) ?>" alt="">
        <?php else: ?>
          <div class="mt-av"><?= h(mb_substr((string)$m['user_name'], 0, 1)) ?></div>
        <?php endif; ?>
        <div style="flex:1;">
          <div class="mt-member__name"><?= h($m['user_name']) ?><?= $isMe ? ' (شما)' : '' ?></div>
          <div class="mt-member__meta"><?= h($m['listing_title'] ?? 'بدون آگهی') ?></div>
          <div class="mt-member__badges">
            <?php if ($room['status'] === 'active' || $room['status'] === 'completed'): ?>
              <span class="mt-badge <?= (int)$m['fee_paid'] ? 'mt-badge--ok' : 'mt-badge--wait' ?>">کارمزد <?= (int)$m['fee_paid'] ? 'پرداخت شد' : 'در انتظار' ?></span>
              <?php if ((float)$m['diff_amount'] != 0): ?>
                <span class="mt-badge <?= (int)$m['diff_paid'] ? 'mt-badge--ok' : 'mt-badge--wait' ?>">ماب‌تفاوت <?= h(fmt_credit(abs((float)$m['diff_amount']))) ?></span>
              <?php endif; ?>
            <?php else: ?>
              <span class="mt-badge <?= (int)$m['close_vote'] ? 'mt-badge--ok' : 'mt-badge--wait' ?>"><?= (int)$m['close_vote'] ? 'آماده بستن' : 'در انتظار' ?></span>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if ($room['status'] === 'forming'): ?>
  <!-- بستن اتاق -->
  <div class="mt-card">
    <h2><i class="bi bi-lock"></i> بستن اتاق و شروع مبادله</h2>
    <?php if ($activeCount < MT_ROOM_MIN): ?>
      <div class="mt-note">برای بستن اتاق حداقل <?= MT_ROOM_MIN ?> عضو لازم است. تا رسیدن به ظرفیت، اعضای جدید می‌توانند با تأیید همه درخواست پیوستن بدهند.</div>
    <?php else: ?>
      <p class="mt-note">با تأیید <b>همه‌ی</b> اعضا، اتاق بسته می‌شود و ورود افراد جدید غیرفعال می‌گردد. سپس هر عضو طرف معامله‌ی خود را انتخاب می‌کند. تا زمانی که همه تأیید نکنند، تأیید درخواست‌های پیوستن همچنان ممکن است.</p>
      <div class="mt-actions">
        <?php if ((int)$myMember['close_vote']): ?>
          <button class="mt-btn mt-btn--ghost" disabled><i class="bi bi-hourglass-split"></i> منتظر تأیید بقیه اعضا (<?= count(array_filter($members, fn($x) => (int)$x['close_vote'])) ?>/<?= $activeCount ?>)</button>
        <?php else: ?>
          <form method="post" onsubmit="return confirm('با تأیید شما، اتاق تنها در صورت موافقت همه‌ی اعضا بسته می‌شود. مطمئن هستید؟');"><?= csrf_field() ?>
            <input type="hidden" name="action" value="vote_close">
            <button class="mt-btn mt-btn--gold" type="submit"><i class="bi bi-lock-fill"></i> بستن اتاق</button>
          </form>
        <?php endif; ?>
      </div>
      <?php if ($allClosed): ?>
        <div class="mt-alert mt-alert--success" style="margin-top:12px;">همه اعضا موافقت کردند؛ اتاق بسته شد.</div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if (in_array($room['status'], ['awaiting_seed', 'forming'], true)): ?>
  <!-- پیش‌نمایش مراحل — تا بسته‌شدن اتاق غیرفعال و blur است -->
  <div class="mt-card">
    <h2><i class="bi bi-list-check"></i> مراحل معامله</h2>
    <div class="mt-steps-locked__note">
      <i class="bi bi-lock-fill"></i>
      تا زمانی که اتاق بسته نشود و طرف معامله‌ها انتخاب نشوند، این مراحل قابل انجام نیستند.
    </div>
    <div class="mt-steps-locked__body" aria-hidden="true">
      <?php foreach ([MT_STEP_FEE, MT_STEP_DIFF, MT_STEP_CONTRACT, MT_STEP_SHIPPING, MT_STEP_DELIVERED, MT_STEP_RECEIVED, MT_STEP_RATING] as $i => $s): ?>
        <div class="mt-track">
          <div class="mt-track__head">
            <div class="mt-track__flow"><i class="bi bi-circle"></i> مرحله <?= $i + 1 ?>: <?= h(multi_trade_step_label($s)) ?></div>
            <span class="mt-badge mt-badge--wait">غیرفعال</span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($room['status'] === 'locked'): ?>
  <!-- انتخاب طرف معامله -->
  <div class="mt-card">
    <h2><i class="bi bi-arrow-left-right"></i> انتخاب طرف معامله</h2>
    <p class="mt-note">هر عضو باید دقیقاً یک نفر را انتخاب کند که کالایش را به او تحویل می‌دهد. انتخاب‌ها باید یک <b>چرخه‌ی کامل</b> بسازند (هر عضو دقیقاً از یک نفر کالا می‌گیرد) تا معامله آغاز شود.</p>
    <?php if ($myTargetsComplete): $cur = (int)$myMember['target_member_id']; $curName = $memberById[$cur]['user_name'] ?? ''; ?>
      <div class="mt-alert mt-alert--success" style="margin-top:12px;">انتخاب شما ثبت شد: کالای شما به <b><?= h($curName) ?></b> تحویل داده می‌شود.</div>
    <?php endif; ?>
    <form method="post" style="margin-top:12px; display:flex; gap:8px; flex-wrap:wrap; align-items:center;"><?= csrf_field() ?>
      <input type="hidden" name="action" value="select_target">
      <select name="target_member_id" class="mt-select" required>
        <option value="">انتخاب دریافت‌کننده…</option>
        <?php foreach ($members as $m): if ((int)$m['id'] === (int)$myMember['id']) continue; ?>
          <option value="<?= (int)$m['id'] ?>" <?= (int)($myMember['target_member_id'] ?? 0) === (int)$m['id'] ? 'selected' : '' ?>>
            <?= h($m['user_name']) ?> — <?= h($m['listing_title'] ?? 'بدون آگهی') ?>
          </option>
        <?php endforeach; ?>
      </select>
      <button class="mt-btn mt-btn--primary" type="submit"><i class="bi bi-check2"></i> ثبت انتخاب</button>
    </form>

    <div style="margin-top:14px;">
      <div style="font-size:13px;font-weight:700;color:#071A33;margin-bottom:6px;">وضعیت انتخاب‌ها:</div>
      <div class="mt-cycle">
        <?php foreach ($members as $m): ?>
          <span class="mt-node"><?= h($m['user_name']) ?><?= (int)($m['target_member_id'] ?? 0) ? ' ← ' . h($memberById[(int)$m['target_member_id']]['user_name'] ?? '') : ' (در انتظار)' ?></span>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if (in_array($room['status'], ['active', 'completed'], true)): ?>
  <!-- تخته وضعیت گروه -->
  <div class="mt-card">
    <h2><i class="bi bi-diagram-3"></i> وضعیت کل گروه</h2>
    <?php if (!empty($board['cycle'])): ?>
      <div class="mt-cycle" style="margin-bottom:14px;">
        <?php $names = []; foreach ($board['cycle'] as $cid) { $names[] = h($memberById[$cid]['user_name'] ?? '?'); } ?>
        <span style="font-weight:700;color:#071A33;">چرخه معامله:</span>
        <span class="mt-node"><?= implode(' ← ', $names) ?></span>
        <span class="mt-node"><?= h($memberById[$board['cycle'][0]]['user_name'] ?? '?') ?></span>
      </div>
    <?php endif; ?>
    <?php foreach ($tracks as $t): $step = $stepByTrack[(int)$t['id']] ?? ''; ?>
      <div class="mt-track">
        <div class="mt-track__head">
          <div class="mt-track__flow">
            <i class="bi bi-box-seam"></i>
            <?= h($t['deliverer_name']) ?> <i class="bi bi-arrow-left"></i> <?= h($t['receiver_name']) ?>
          </div>
          <span class="mt-badge <?= $step === MT_STEP_DONE ? 'mt-badge--ok' : 'mt-badge--wait' ?>"><?= h(multi_trade_step_label($step)) ?></span>
        </div>
        <div class="mt-track__step">کالا: <?= h($t['deliverer_listing_title'] ?? '—') ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- مسیرهای من: اقدام‌های لازم -->
  <div class="mt-card">
    <h2><i class="bi bi-list-check"></i> اقدام‌های من</h2>
    <?php if (!$myTracks): ?>
      <div class="mt-note">مسیری برای شما ثبت نشده است.</div>
    <?php else: ?>
      <div class="mt-tabs" id="mtMyTabs">
        <button type="button" class="mt-tab is-active" data-pane="deliver"><i class="bi bi-box-seam"></i> تحویل من</button>
        <button type="button" class="mt-tab" data-pane="receive"><i class="bi bi-inbox"></i> دریافت من</button>
      </div>
    <?php endif; ?>
    <?php foreach ($myTracks as $t):
      $step = $stepByTrack[(int)$t['id']] ?? '';
      $iAmDeliverer = (int)$t['deliverer_user_id'] === $uid;
      $peerName = $iAmDeliverer ? $t['receiver_name'] : $t['deliverer_name'];
      $didFee = (int)($memberById[(int)($iAmDeliverer ? $t['deliverer_member_id'] : $t['receiver_member_id'])]['fee_paid'] ?? 0) === 1;
      $didDiff = (int)($memberById[(int)($iAmDeliverer ? $t['deliverer_member_id'] : $t['receiver_member_id'])]['diff_paid'] ?? 0) === 1;
      $myDiff = (float)($myMember['diff_amount'] ?? 0);
    ?>
      <div class="mt-track mt-track--mine" data-pane="<?= $iAmDeliverer ? 'deliver' : 'receive' ?>">
        <div class="mt-track__head">
          <div class="mt-track__flow">
            <?= $iAmDeliverer ? 'تحویل کالای شما به ' : 'دریافت کالا از ' ?>
            <b><?= h($peerName) ?></b>
            <?php if ($iAmDeliverer): ?><span style="color:#6c7a92;font-weight:400;">(<?= h($t['deliverer_listing_title'] ?? '—') ?>)</span><?php endif; ?>
          </div>
          <span class="mt-badge <?= $step === MT_STEP_DONE ? 'mt-badge--ok' : 'mt-badge--wait' ?>"><?= h(multi_trade_step_label($step)) ?></span>
        </div>

        <?php if ($step === MT_STEP_FEE): ?>
          <div class="mt-track__step">
            <?php if ($didFee): ?>کارمزد شما پرداخت شد؛ منتظر پرداخت کارمزد طرف مقابل هستید.
            <?php else: ?>کارمزد این مسیر: <?= h(fmt_credit((float)$myMember['fee_amount'])) ?>
            <?php endif; ?>
          </div>
          <?php if (!$didFee): ?>
          <div class="mt-actions">
            <form method="post"><?= csrf_field() ?>
              <input type="hidden" name="action" value="pay_fee">
              <button class="mt-btn mt-btn--gold" type="submit"><i class="bi bi-wallet2"></i> پرداخت کارمزد</button>
            </form>
          </div>
          <?php endif; ?>

        <?php elseif ($step === MT_STEP_DIFF): ?>
          <div class="mt-track__step">
            ماب‌تفاوت شما: <b><?= h(fmt_credit($myDiff)) ?></b>
            <?php if ($myDiff > 0 && $didDiff): ?> — پرداخت شد؛ منتظر تسویه گروه.
            <?php elseif ($myDiff > 0): ?> — باید پرداخت شود.
            <?php else: ?> — طلبکارید؛ پس از واریز سایر اعضا تسویه می‌شود.
            <?php endif; ?>
          </div>
          <?php if ($myDiff > 0 && !$didDiff): ?>
          <div class="mt-actions">
            <form method="post"><?= csrf_field() ?>
              <input type="hidden" name="action" value="pay_diff">
              <button class="mt-btn mt-btn--gold" type="submit"><i class="bi bi-cash-coin"></i> پرداخت ماب‌تفاوت</button>
            </form>
          </div>
          <?php endif; ?>

        <?php elseif ($step === MT_STEP_CONTRACT): ?>
          <div class="mt-track__step">هر دو طرف باید قرارداد این مسیر را امضا کنند.</div>
          <div class="mt-actions">
            <form method="post"><?= csrf_field() ?>
              <input type="hidden" name="action" value="sign_track"><input type="hidden" name="track_id" value="<?= (int)$t['id'] ?>">
              <button class="mt-btn mt-btn--primary" type="submit"><i class="bi bi-pen"></i> امضای قرارداد</button>
            </form>
          </div>

        <?php elseif ($step === MT_STEP_SHIPPING): ?>
          <?php if ($iAmDeliverer): ?>
            <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:8px;"><?= csrf_field() ?>
              <input type="hidden" name="action" value="set_shipping"><input type="hidden" name="track_id" value="<?= (int)$t['id'] ?>">
              <select name="method" class="mt-select" required>
                <option value="in_person">حضوری</option>
                <option value="post">پست</option>
                <option value="tipax">تیپاکس</option>
                <option value="courier">پیک</option>
              </select>
              <input type="text" name="shipping_date" class="mt-input" placeholder="تاریخ (میلادی YYYY-MM-DD)" required>
              <input type="text" name="shipping_time" class="mt-input" placeholder="ساعت (مثلاً 14:30)">
              <button class="mt-btn mt-btn--primary" type="submit"><i class="bi bi-calendar-check"></i> ثبت زمان ارسال</button>
            </form>
          <?php else: ?>
            <div class="mt-track__step">منتظر تعیین زمان و روش ارسال توسط طرف مقابل هستید.</div>
          <?php endif; ?>

        <?php elseif ($step === MT_STEP_DELIVERED): ?>
          <?php if ($iAmDeliverer): ?>
            <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:8px;"><?= csrf_field() ?>
              <input type="hidden" name="action" value="mark_shipped"><input type="hidden" name="track_id" value="<?= (int)$t['id'] ?>">
              <input type="text" name="tracking_code" class="mt-input" placeholder="کد رهگیری (اختیاری)">
              <button class="mt-btn mt-btn--primary" type="submit"><i class="bi bi-truck"></i> ثبت ارسال</button>
            </form>
          <?php else: ?>
            <div class="mt-track__step">منتظر ارسال کالا توسط طرف مقابل هستید.</div>
          <?php endif; ?>

        <?php elseif ($step === MT_STEP_RECEIVED): ?>
          <?php if (!$iAmDeliverer): ?>
            <div class="mt-actions">
              <form method="post" onsubmit="return confirm('دریافت کالا را تأیید می‌کنید؟');"><?= csrf_field() ?>
                <input type="hidden" name="action" value="confirm_received"><input type="hidden" name="track_id" value="<?= (int)$t['id'] ?>">
                <button class="mt-btn mt-btn--gold" type="submit"><i class="bi bi-check2-circle"></i> تأیید دریافت کالا</button>
              </form>
            </div>
          <?php else: ?>
            <div class="mt-track__step">منتظر تأیید دریافت توسط طرف مقابل هستید.</div>
          <?php endif; ?>

        <?php elseif ($step === MT_STEP_RATING): ?>
          <?php
            $myRated = $iAmDeliverer ? (int)$t['deliverer_rated'] : (int)$t['receiver_rated'];
          ?>
          <?php if (!$myRated): ?>
            <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:8px;"><?= csrf_field() ?>
              <input type="hidden" name="action" value="rate_track"><input type="hidden" name="track_id" value="<?= (int)$t['id'] ?>">
              <select name="score" class="mt-select" required>
                <option value="5">۵ - عالی</option><option value="4">۴ - خوب</option>
                <option value="3">۳ - متوسط</option><option value="2">۲ - ضعیف</option><option value="1">۱ - بد</option>
              </select>
              <input type="text" name="comment" class="mt-input" placeholder="نظر (اختیاری)">
              <button class="mt-btn mt-btn--primary" type="submit"><i class="bi bi-star"></i> ثبت امتیاز</button>
            </form>
          <?php else: ?>
            <div class="mt-track__step">امتیاز شما ثبت شد.</div>
          <?php endif; ?>

        <?php else: ?>
          <div class="mt-track__step" style="color:#128a4d;font-weight:700;">این مسیر تکمیل شده است.</div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- چت گروهی -->
  <div class="mt-card">
    <h2><i class="bi bi-chat-dots"></i> گفتگوی گروهی</h2>
    <div class="mt-chat" id="mtChat" data-since="0"></div>
    <form class="mt-chat-form" id="mtChatForm"><?= csrf_field() ?>
      <input type="text" name="body" id="mtChatInput" class="mt-input" placeholder="پیام خود را بنویسید…" autocomplete="off" maxlength="2000">
      <button class="mt-btn mt-btn--primary" type="submit"><i class="bi bi-send"></i> ارسال</button>
    </form>
  </div>
<?php endif; ?>
</div>

<script>
(function () {
  var chat = document.getElementById('mtChat');
  if (!chat) return;
  var roomId = <?= (int)$roomId ?>;
  var csrf = document.querySelector('#mtChatForm input[name="_csrf"]').value;
  var lastId = 0;

  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }

  function append(msgs) {
    msgs.forEach(function (m) {
      var el = document.createElement('div');
      if (m.system) {
        el.className = 'mt-msg mt-msg--system';
        el.innerHTML = '<div class="mt-msg__bubble">' + esc(m.body) + '</div>';
      } else {
        el.className = 'mt-msg' + (m.mine ? ' mt-msg--mine' : '');
        el.innerHTML = '<div class="mt-msg__av">' + esc(m.initial) + '</div>'
          + '<div><div class="mt-msg__bubble">' + esc(m.body) + '</div>'
          + '<div class="mt-msg__meta">' + esc(m.name) + ' — ' + esc(m.time) + '</div></div>';
      }
      chat.appendChild(el);
      if (m.id > lastId) lastId = m.id;
    });
    chat.scrollTop = chat.scrollHeight;
  }

  function load() {
    fetch('<?= APP_URL ?>/trades/group?id=' + roomId + '&action=messages&since=' + lastId, { headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (d && d.ok) append(d.messages); })
      .catch(function () {});
  }

  document.getElementById('mtChatForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var input = document.getElementById('mtChatInput');
    var body = input.value.trim();
    if (!body) return;
    var fd = new FormData();
    fd.append('action', 'send_message'); fd.append('ajax', '1'); fd.append('_csrf', csrf); fd.append('body', body);
    input.value = '';
    fetch('<?= APP_URL ?>/trades/group?id=' + roomId, { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function () { load(); })
      .catch(function () {});
  });

  load();
  setInterval(load, 7000);
})();

// تب‌های «تحویل من» / «دریافت من» (دو معامله‌ی در جریان هر عضو)
(function () {
  var tabs = document.getElementById('mtMyTabs');
  if (!tabs) return;
  var buttons = [].slice.call(tabs.querySelectorAll('.mt-tab'));
  var items = [].slice.call(document.querySelectorAll('.mt-track--mine[data-pane]'));
  function activate(pane) {
    buttons.forEach(function (b) { b.classList.toggle('is-active', b.getAttribute('data-pane') === pane); });
    items.forEach(function (it) { it.style.display = it.getAttribute('data-pane') === pane ? '' : 'none'; });
  }
  buttons.forEach(function (b) {
    b.addEventListener('click', function () { activate(b.getAttribute('data-pane')); });
  });
  activate('deliver');
})();
</script>

<?php render_user_panel_close(); render_footer(); ?>
