<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/asset_valuation.php';
require_once __DIR__ . '/../includes/negotiator_service.php';

$user = require_auth();
$uid  = $user['id'];

$listingId = (int)($_GET['id'] ?? 0);
$success   = '';
$error     = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    $offerId = (int)($_POST['offer_id'] ?? 0);
    $action  = clean($_POST['action'] ?? '');
    $message = clean($_POST['message'] ?? '');

    if ($offerId && in_array($action, ['accept', 'reject'], true)) {
        // Verify this offer belongs to user's listing
        $offer = DB::fetch(
            'SELECT o.*, l.user_id AS listing_owner, l.title AS listing_title
             FROM trade_offers o
             JOIN listings l ON l.id = o.listing_id
             WHERE o.id = ? AND l.user_id = ? AND o.status = "pending"',
            [$offerId, $uid]
        );

        if (!$offer) {
            $error = 'پیشنهاد یافت نشد یا دسترسی ندارید.';
        } elseif ($action === 'accept') {
            if (empty($message)) {
                $error = 'لطفاً پیامی برای طرفین بنویسید.';
            } else {
                $result = accept_trade_offer($offerId, $uid, $message);
                if (isset($result['error'])) {
                    $error = $result['error'];
                } else {
                    header('Location: ' . APP_URL . '/trades/view.php?id=' . $result['trade_id'] . '&accepted=1&tab=fee');
                    exit;
                }
            }

        } elseif ($action === 'reject') {
            if (empty($message)) {
                $error = 'لطفاً پیامی برای طرفین بنویسید.';
            } else {
                DB::query('UPDATE trade_offers SET status = "rejected" WHERE id = ?', [$offerId]);
                DB::insert('messages', [
                    'thread_id'    => 'offer_reject_' . $offerId,
                    'from_user_id' => $uid,
                    'to_user_id'   => $offer['from_user_id'],
                    'offer_id'     => $offerId,
                    'body'         => $message,
                ]);
                $success = 'پیشنهاد رد شد.';
            }
        }
    }
}

// Fetch listing and its offers
if ($listingId) {
    $listing = DB::fetch(
        'SELECT * FROM listings WHERE id = ? AND user_id = ?', [$listingId, $uid]
    );
    if (!$listing) {
        header('Location: ' . APP_URL . '/listings/my.php'); exit;
    }
    $offers = DB::fetchAll(
        'SELECT o.*, u.name AS from_name, u.avatar AS from_avatar, u.rating AS from_rating, u.city AS from_city,
                ol.title AS offer_listing_title, ol.id AS offer_listing_id_v,
                (SELECT filename FROM listing_images WHERE listing_id=ol.id AND is_primary=1 LIMIT 1) AS offer_listing_thumb,
                (SELECT t.id FROM trades t WHERE t.offer_id = o.id LIMIT 1) AS trade_id
         FROM trade_offers o
         JOIN users u ON u.id = o.from_user_id
         LEFT JOIN listings ol ON ol.id = o.offer_listing_id
         WHERE o.listing_id = ? AND o.status = "pending"
         ORDER BY o.created_at DESC',
        [$listingId]
    );
} else {
    // All offers across all my listings
    $listing = null;
    $offers  = DB::fetchAll(
        'SELECT o.*, l.title AS listing_title, l.id AS listing_id_v,
                u.name AS from_name, u.avatar AS from_avatar, u.rating AS from_rating,
                ol.title AS offer_listing_title,
                (SELECT t.id FROM trades t WHERE t.offer_id = o.id LIMIT 1) AS trade_id
         FROM trade_offers o
         JOIN listings l ON l.id = o.listing_id
         JOIN users u ON u.id = o.from_user_id
         LEFT JOIN listings ol ON ol.id = o.offer_listing_id
         WHERE l.user_id = ? AND o.status = "pending"
         ORDER BY o.created_at DESC',
        [$uid]
    );
}

render_head('پیشنهادهای معامله');
render_navbar($user);
?>

<div class="section-sm">
  <div class="container-md">

    <div class="mb-6">
      <a href="<?= APP_URL ?>/trades?tab=received" style="color:var(--text-muted);font-size:.875rem">
        <i class="bi bi-arrow-right"></i> بازگشت به داشبورد
      </a>
      <h2 class="mt-3">
        <?php if ($listing): ?>
        پیشنهادها برای: <?= h($listing['title']) ?>
        <?php else: ?>
        همه پیشنهادهای دریافتی
        <?php endif; ?>
      </h2>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success mb-5"><i class="bi bi-check-circle"></i> <?= h($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-danger mb-5"><i class="bi bi-exclamation-circle"></i> <?= h($error) ?></div>
    <?php endif; ?>

    <?php if (empty($offers)): ?>
    <div class="empty-state">
      <i class="bi bi-inbox"></i>
      <h3>هنوز پیشنهادی نیست</h3>
      <p>وقتی دیگران برای آگهی‌های شما پیشنهاد بدهند، اینجا نمایش داده می‌شود.</p>
      <a href="<?= APP_URL ?>/" class="btn btn-primary">مرور آگهی‌ها</a>
    </div>
    <?php else: ?>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:var(--sp-4);align-items:stretch">
      <?php foreach ($offers as $offer):
        $statusColors = ['pending' => 'warning', 'accepted' => 'success', 'rejected' => 'danger', 'cancelled' => 'info', 'completed' => 'success'];
        $statusColor  = $statusColors[$offer['status']] ?? 'info';
      ?>
      <div class="card" style="<?= $offer['status'] === 'pending' ? 'border-inline-start:4px solid var(--warning)' : '' ?>">
        <div class="card-body">
          <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:var(--sp-4);flex-wrap:wrap">

            <!-- Offer Left -->
            <div style="flex:1;min-width:0">
              <div style="display:flex;align-items:center;gap:var(--sp-3);margin-bottom:var(--sp-3)">
                <?= avatar_html($offer['from_avatar'] ?? null, $offer['from_name'], 'md') ?>
                <div>
                  <div style="font-weight:700;font-size:1.0625rem"><?= h($offer['from_name']) ?></div>
                  <?php if ($offer['from_rating'] > 0): ?>
                  <div class="fs-xs" style="color:var(--accent-dark)">
                    <i class="bi bi-star-fill"></i> <?= number_format((float)$offer['from_rating'], 1) ?>
                  </div>
                  <?php endif; ?>
                </div>
                <span class="badge badge-<?= $statusColor ?> fs-xs" style="margin-inline-start:auto"><?= offer_status_label($offer['status']) ?></span>
              </div>

              <?php if (!$listing): ?>
              <div class="fs-sm mb-2" style="color:var(--text-muted)">
                برای: <strong><?= h($offer['listing_title'] ?? '') ?></strong>
              </div>
              <?php endif; ?>

              <!-- What they're offering -->
              <?php if ($offer['offer_listing_title'] || (float)$offer['offer_credit'] > 0): ?>
              <div style="background:rgba(0,174,239,.04);border:1px solid rgba(0,174,239,.15);border-radius:var(--radius-md);padding:var(--sp-4);margin-bottom:var(--sp-3)">
                <div class="fs-xs" style="color:var(--text-muted);margin-bottom:var(--sp-2)">پیشنهاد:</div>
                <?php if ($offer['offer_listing_title']): ?>
                <div style="display:flex;align-items:center;gap:var(--sp-3)">
                  <?php if ($offer['offer_listing_thumb'] ?? false): ?>
                  <img src="<?= UPLOAD_URL . h($offer['offer_listing_thumb']) ?>" alt="<?= h($offer['offer_listing_title']) ?>"
                       style="width:60px;height:60px;border-radius:var(--radius-md);object-fit:cover">
                  <?php endif; ?>
                  <div style="font-weight:600"><i class="bi bi-box"></i> <?= h($offer['offer_listing_title']) ?></div>
                </div>
                <?php endif; ?>
                <?php if ((float)$offer['offer_credit'] > 0): ?>
                <div class="fs-md mt-2" style="color:var(--primary);font-weight:700">
                  <i class="bi bi-wallet2"></i> + <?= fmt_credit((float)$offer['offer_credit']) ?>
                </div>
                <?php endif; ?>
              </div>
              <?php endif; ?>

              <?php if ($offer['message']): ?>
              <div style="background:var(--bg);border-radius:var(--radius-md);padding:var(--sp-4);font-size:.9375rem;color:var(--text-secondary)">
                <div class="fs-xs mb-2" style="color:var(--text-muted)">پیام پیشنهاد‌دهنده:</div>
                "<?= h($offer['message']) ?>"
              </div>
              <?php endif; ?>

              <div class="fs-xs mt-4" style="color:var(--text-muted)">
                <i class="bi bi-clock"></i> <?= persian_datetime($offer['created_at']) ?>
              </div>
            </div>

            <!-- Actions -->
            <?php if ($offer['status'] === 'pending'): ?>
            <div style="width:100%;min-width:280px;max-width:420px">

              <!-- AI Negotiator Card -->
              <div class="card mb-4" style="border:1px solid var(--border);background:#fafcff">
                <div class="card-header" style="border-bottom:1px solid var(--border)">
                  <div style="display:flex;align-items:center;justify-content:space-between;gap:var(--sp-2);flex-wrap:wrap">
                    <div style="display:flex;align-items:center;gap:var(--sp-2)">
                      <span style="font-size:1.125rem">🤖</span>
                      <strong>تحلیل هوشمند پیشنهاد</strong>
                    </div>
                    <button type="button"
                            class="btn btn-outline btn-sm ai-negotiator-btn"
                            data-offer-id="<?= (int)$offer['id'] ?>"
                            data-state="idle"
                            style="font-size:.8125rem;padding:6px 12px">
                      <span class="ai-negotiator-btn__label"><i class="bi bi-magic"></i> اجرای تحلیل</span>
                      <span class="ai-negotiator-btn__loading" style="display:none"><i class="bi bi-arrow-clockwise spin"></i> در حال تحلیل...</span>
                    </button>
                  </div>
                </div>
                <div class="card-body ai-negotiator-body" id="ai-nego-body-<?= (int)$offer['id'] ?>">
                  <div class="fs-sm" style="color:var(--text-muted);line-height:1.7">
                    با استفاده از ارزش تخمینی دو کالا و وضعیت پیشنهاد، یک تحلیل یک‌طرفه به نفع شما ارائه می‌دهد تا
                    به بهترین تصمیم دست یابید. مبلغ‌ها تخمینی هستند.
                  </div>
                </div>
              </div>

              <form method="POST" class="mb-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="accept">
                <input type="hidden" name="offer_id" value="<?= $offer['id'] ?>">
                <?php if ($listingId): ?>
                <input type="hidden" name="id" value="<?= $listingId ?>">
                <?php endif; ?>
                <div class="form-group">
                  <label class="form-label">پیام پذیرش:</label>
                  <textarea name="message" class="form-control negotiator-msg-accept" rows="2" required placeholder="مثلاً: سلام! پیشنهاد شما را می‌پذیرم. برای هماهنگی بیشتر پیام بده."></textarea>
                </div>
                <button type="submit" class="btn btn-primary w-100">
                  <i class="bi bi-check-lg"></i> پذیرش و ورود به اتاق امن
                </button>
              </form>
              <form method="POST" class="mb-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="offer_id" value="<?= $offer['id'] ?>">
                <div class="form-group">
                  <label class="form-label">پیام رد:</label>
                  <textarea name="message" class="form-control negotiator-msg-reject" rows="2" required placeholder="مثلاً: متشکرم، اما این بار نمی‌تونم."></textarea>
                </div>
                <button type="submit" class="btn btn-ghost w-100" style="color:var(--danger)">
                  <i class="bi bi-x-lg"></i> رد پیشنهاد
                </button>
              </form>
            </div>
            <?php elseif ($offer['status'] === 'accepted' && $offer['trade_id']): ?>
            <div style="width:100%">
              <a href="<?= APP_URL ?>/trades/view.php?id=<?= (int)$offer['trade_id'] ?>" class="btn btn-primary w-100">
                <i class="bi bi-shield-lock"></i> ورود به اتاق امن
              </a>
            </div>
            <?php endif; ?>

          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <?php endif; ?>

  </div>
</div>

<script>
(function(){
  'use strict';
  var csrf  = '<?= json_encode(csrf_token()) ?>';
  var apiBase = '<?= APP_URL ?>/api';

  function fmt(n) {
    if (!n && n !== 0) return '۰';
    n = Number(n) || 0;
    return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }

  function escHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  function assessmentClass(a) {
    if (a === 'favorable') return 'success';
    if (a === 'unfavorable') return 'danger';
    return 'warning';
  }

  function assessmentLabel(a) {
    if (a === 'favorable') return 'به نفع شما';
    if (a === 'unfavorable') return 'نامناسب';
    return 'متعادل';
  }

  function confidenceBadge(c) {
    c = Number(c) || 0;
    var pct = Math.round(c * 100);
    var cls = 'warning';
    if (pct >= 75) cls = 'success';
    else if (pct < 40) cls = 'danger';
    return '<span class="badge badge-' + cls + ' fs-xs">اطمینان ' + pct + '%</span>';
  }

  function buildBodyHtml(offerId, result, fromRule, aiErr, rateLimited) {
    if (!result) return '';
    var r = result;
    var html = '';

    html += '<div style="display:flex;align-items:center;gap:var(--sp-2);flex-wrap:wrap;margin-bottom:var(--sp-3)">';
    html += '<span class="badge badge-' + assessmentClass(r.assessment) + '" style="font-size:.8125rem">' + assessmentLabel(r.assessment) + '</span>';
    html += confidenceBadge(r.confidence);
    if (fromRule) {
      html += '<span class="badge badge-info fs-xs" title="تحلیل مبتنی بر قوانین بدون اتصال AI"><i class="bi bi-cpu"></i> تحلیل قاعده‌محور</span>';
    } else {
      html += '<span class="badge badge-success fs-xs" title="تحلیل مبتنی بر هوش مصنوعی"><i class="bi bi-stars"></i> AI Analyzed</span>';
    }
    if (rateLimited) {
      html += '<span class="badge badge-warning fs-xs"><i class="bi bi-exclamation-triangle"></i> محدودیت سرعت</span>';
    }
    html += '</div>';

    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-3);margin-bottom:var(--sp-3)">';
    html += '<div style="background:rgba(0,174,239,.05);border:1px solid rgba(0,174,239,.15);border-radius:var(--radius-md);padding:var(--sp-3)">';
    html += '<div class="fs-xs" style="color:var(--text-muted)">کالای شما (تخمینی)</div>';
    html += '<div style="font-weight:700;font-size:1rem;margin-top:4px">' + fmt(r.your_value) + ' <span class="fs-xs" style="color:var(--text-muted);font-weight:500">تومان</span></div>';
    html += '</div>';
    html += '<div style="background:rgba(126,87,194,.06);border:1px solid rgba(126,87,194,.15);border-radius:var(--radius-md);padding:var(--sp-3)">';
    html += '<div class="fs-xs" style="color:var(--text-muted)">کالای طرف مقابل (تخمینی)</div>';
    html += '<div style="font-weight:700;font-size:1rem;margin-top:4px">' + fmt(r.their_value) + ' <span class="fs-xs" style="color:var(--text-muted);font-weight:500">تومان</span></div>';
    html += '</div>';
    html += '</div>';

    if (r.difference) {
      html += '<div style="background:rgba(126,87,194,.06);border-radius:var(--radius-md);padding:var(--sp-3);margin-bottom:var(--sp-3)">';
      html += '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:var(--sp-2)">';
      html += '<div class="fs-sm" style="color:var(--text-secondary)">تفاوت ارزش تخمینی:</div>';
      html += '<div style="font-weight:700;color:var(--accent-dark)">' + (r.difference > 0 ? '−' : '+') + ' ' + fmt(Math.abs(r.difference)) + ' تومان</div>';
      html += '</div>';
      if (r.suggested_cash_difference > 0) {
        html += '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:var(--sp-2);margin-top:var(--sp-2)">';
        html += '<div class="fs-sm" style="color:var(--text-secondary)">مبلغ پیشنهادی مابه‌التفاوت از طرف مقابل:</div>';
        html += '<div style="font-weight:800;color:var(--primary)">' + fmt(r.suggested_cash_difference) + ' تومان</div>';
        html += '</div>';
      }
      html += '</div>';
    }

    if (r.reason) {
      html += '<div style="background:var(--bg);border-radius:var(--radius-md);padding:var(--sp-3);font-size:.9375rem;color:var(--text-secondary);line-height:1.8;margin-bottom:var(--sp-3)">';
      html += '<div class="fs-xs mb-1" style="color:var(--text-muted)">تحلیل:</div>';
      html += escHtml(r.reason);
      html += '</div>';
    }

    if (aiErr && !fromRule) {
      html += '<div class="alert alert-warning fs-xs mb-3" style="padding:8px 12px"><i class="bi bi-exclamation-triangle"></i> پاسخ AI نامعتبر بود؛ از تحلیل قاعده‌محور استفاده شد.</div>';
    }

    if (r.suggested_replies && r.suggested_replies.length) {
      html += '<div class="fs-sm" style="font-weight:700;margin-bottom:var(--sp-2)">پاسخ‌های پیشنهادی:</div>';
      html += '<div style="display:flex;flex-direction:column;gap:var(--sp-2)">';
      var tones = [{label:'دوستانه', icon:'bi-heart', cls:'success'},
                   {label:'مذاکره‌گرا', icon:'bi-chat-dots', cls:'primary'},
                   {label:'قطعی و منطقی', icon:'bi-shield-check', cls:'danger'}];
      r.suggested_replies.forEach(function(txt, i){
        var tone = tones[i] || tones[0];
        html += '<button type="button" ' +
          'class="btn btn-sm ai-nego-reply-btn" ' +
          'data-offer-id="' + offerId + '" ' +
          'data-reply="' + escHtml(txt).replace(/"/g,'&quot;') + '" ' +
          'style="border:1px dashed var(--border);background:#fff;justify-content:space-between;text-align:right;padding:10px 12px;gap:var(--sp-2);border-radius:var(--radius-md);font-size:.875rem;color:var(--text);line-height:1.7">';
        html += '<span style="display:flex;align-items:center;gap:8px"><i class="bi ' + tone.icon + '" style="color:var(--' + tone.cls + ')"></i> <strong style="color:var(--' + tone.cls + ')">' + tone.label + '</strong></span>';
        html += '<span style="flex:1;text-align:right;color:var(--text-secondary);max-width:80%;overflow-wrap:anywhere">' + escHtml(txt) + '</span>';
        html += '<i class="bi bi-clipboard" style="color:var(--text-muted)"></i>';
        html += '</button>';
      });
      html += '</div>';
      html += '<div class="fs-xs" style="color:var(--text-muted);margin-top:8px"><i class="bi bi-info-circle"></i> روی هر مورد کلیک کنید تا متن داخل کادر پیام پذیرش/رد قرار بگیرد. برای ارسال پیام همچنان باید دکمه پذیرش یا رد را کلیک کنید.</div>';
    }
    return html;
  }

  function setBtnLoading(btn, loading) {
    var label  = btn.querySelector('.ai-negotiator-btn__label');
    var load   = btn.querySelector('.ai-negotiator-btn__loading');
    btn.disabled = !!loading;
    btn.setAttribute('data-state', loading ? 'loading' : (btn.getAttribute('data-state') || 'idle'));
    if (label) label.style.display = loading ? 'none' : '';
    if (load)  load.style.display  = loading ? ''     : 'none';
  }

  function findMsgTextarea(offerId, preferAccept) {
    var card = document.getElementById('ai-nego-body-' + offerId);
    if (!card) return null;
    var container = card.closest('.card');
    if (!container) return null;
    var parent = container.parentElement;
    var selector = preferAccept ? '.negotiator-msg-accept' : '.negotiator-msg-reject';
    return parent.querySelector(selector) || parent.querySelector('.negotiator-msg-accept');
  }

  function runAnalyze(btn, offerId) {
    var bodyId = 'ai-nego-body-' + offerId;
    var body = document.getElementById(bodyId);
    if (!body) return;
    setBtnLoading(btn, true);
    body.innerHTML = '<div style="padding:var(--sp-2) 0"><div style="height:14px;background:var(--bg);border-radius:4px;animation:pulse 1.6s infinite;margin-bottom:10px"></div>' +
      '<div style="height:14px;width:75%;background:var(--bg);border-radius:4px;animation:pulse 1.6s infinite;margin-bottom:10px"></div>' +
      '<div style="height:14px;width:55%;background:var(--bg);border-radius:4px;animation:pulse 1.6s infinite"></div>' +
      '<div class="fs-xs mt-3" style="color:var(--text-muted)"><i class="bi bi-magic"></i> در حال ارسال درخواست به هوش مصنوعی... معمولاً کمتر از ۱۰ ثانیه طول می‌کشد.</div></div>';

    var url = apiBase + '/negotiator_analyze.php?offer_id=' + encodeURIComponent(offerId) + '&_=' + Date.now();
    fetch(url, { method:'GET', headers:{ 'X-CSRF-Token': csrf, 'Accept':'application/json' }, credentials:'same-origin' })
      .then(function(res){
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.json();
      })
      .then(function(data){
        setBtnLoading(btn, false);
        if (!data || !data.ok) {
          btn.setAttribute('data-state', 'error');
          btn.querySelector('.ai-negotiator-btn__label').innerHTML = '<i class="bi bi-arrow-clockwise"></i> تلاش مجدد';
          body.innerHTML = '<div class="alert alert-warning fs-sm" style="padding:10px 12px;margin:0">' +
            '<i class="bi bi-exclamation-triangle"></i> تحلیل هوشمند در دسترس نیست.<br>' +
            '<span class="fs-xs" style="color:var(--text-muted);margin-top:6px;display:block">دکمه پذیرش و رد همچنان فعال هستند؛ می‌توانید بدون AI تصمیم بگیرید.</span>' +
            '</div>';
          return;
        }
        btn.setAttribute('data-state', 'success');
        btn.querySelector('.ai-negotiator-btn__label').innerHTML = '<i class="bi bi-arrow-repeat"></i> تحلیل مجدد';
        body.innerHTML = buildBodyHtml(offerId, data.result || null, !!data.from_rule_based, data.ai_error || null, !!data.rate_limited);
        wireReplyButtons();
      })
      .catch(function(err){
        setBtnLoading(btn, false);
        btn.setAttribute('data-state', 'error');
        btn.querySelector('.ai-negotiator-btn__label').innerHTML = '<i class="bi bi-arrow-clockwise"></i> تلاش مجدد';
        body.innerHTML = '<div class="alert alert-warning fs-sm" style="padding:10px 12px;margin:0">' +
          '<i class="bi bi-exclamation-triangle"></i> تحلیل هوشمند در دسترس نیست.<br>' +
          '<span class="fs-xs" style="color:var(--text-muted);margin-top:6px;display:block">دکمه پذیرش و رد همچنان فعال هستند؛ می‌توانید بدون AI تصمیم بگیرید.</span>' +
          '</div>';
      });
  }

  function wireButtons() {
    document.querySelectorAll('.ai-negotiator-btn').forEach(function(btn){
      if (btn.dataset.wired) return;
      btn.dataset.wired = '1';
      btn.addEventListener('click', function(){
        var offerId = btn.dataset.offerId;
        runAnalyze(btn, offerId);
      });
    });
  }

  function wireReplyButtons() {
    document.querySelectorAll('.ai-nego-reply-btn').forEach(function(btn){
      if (btn.dataset.wired) return;
      btn.dataset.wired = '1';
      btn.addEventListener('click', function(){
        var offerId = btn.dataset.offerId;
        var reply   = btn.dataset.reply || '';
        var prefAccept = (reply.indexOf('پذیر') !== -1 || reply.indexOf('مناسبه') !== -1 || reply.indexOf('ادامه') !== -1 || reply.indexOf('توافق') !== -1);
        var ta = findMsgTextarea(offerId, prefAccept);
        if (!ta) return;
        ta.value = reply;
        ta.focus();
        ta.scrollIntoView({behavior:'smooth', block:'center'});
        try { ta.dispatchEvent(new Event('input', {bubbles:true})); } catch(e){}
        btn.classList.add('is-inserted');
        setTimeout(function(){ btn.classList.remove('is-inserted'); }, 900);
      });
    });
  }

  document.addEventListener('DOMContentLoaded', function(){
    wireButtons();
    wireReplyButtons();
  });
})();
</script>

<?php render_footer(); ?>
