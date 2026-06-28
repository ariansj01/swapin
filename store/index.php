<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

$user   = require_auth();
$uid    = (int)$user['id'];
$isStore = is_store_seller($user);
$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_listings'])) {
    if (!$isStore) {
        $error = 'برای ثبت گروهی، نوع حساب خود را در پروفایل به «فروشگاه» تغییر دهید.';
    } else {
        $lines   = array_filter(array_map('trim', explode("\n", $_POST['bulk_listings'] ?? '')));
        $created = 0;
        $skipped = 0;

        foreach ($lines as $line) {
            if (!can_create_listing($user)) {
                $skipped++;
                continue;
            }

            $parts = array_map('trim', explode('|', $line));
            if (count($parts) < 4) {
                $skipped++;
                continue;
            }

            [$title, $catSlug, $value, $want] = $parts;
            $cat = DB::fetch('SELECT id FROM categories WHERE slug = ? AND is_active = 1', [clean($catSlug)]);
            if (!$cat || mb_strlen($title) < 5 || mb_strlen($want) < 5) {
                $skipped++;
                continue;
            }

            DB::insert('listings', [
                'user_id'         => $uid,
                'category_id'     => (int)$cat['id'],
                'title'           => clean($title),
                'description'     => clean($title) . ' — ثبت‌شده از پنل فروشگاه.',
                'condition'       => 'good',
                'estimated_value' => max(0, (float)$value),
                'want_in_return'  => clean($want),
                'want_type'       => 'item',
                'listing_mode'    => 'swap',
                'sell_price'      => 0,
                'city'            => $user['city'] ?? null,
                'status'          => 'active',
            ]);
            $created++;
        }

        if ($created > 0) {
            $success = "$created آگهی ثبت شد." . ($skipped ? " ($skipped ردیف رد یا نادیده گرفته شد)" : '');
        } else {
            $error = 'هیچ آگهی‌ای ثبت نشد. فرمت یا سقف آگهی را بررسی کنید.';
        }
    }
}

$activeCount = can_create_listing_count($user);
$limit       = get_listing_limit($user);
$inventory   = DB::fetchAll(
    'SELECT l.*, c.name AS cat_name, c.slug AS cat_slug,
            (SELECT COUNT(*) FROM trade_offers WHERE listing_id = l.id AND status = "pending") AS pending_offers
     FROM listings l
     JOIN categories c ON c.id = l.category_id
     WHERE l.user_id = ? AND l.status = "active"
     ORDER BY l.created_at DESC',
    [$uid]
);
$totalValue = array_sum(array_map(fn($r) => (float)$r['estimated_value'], $inventory));

render_head('پنل فروشگاه');
render_navbar($user);
?>

<div class="section-sm">
  <div class="container">

    <div class="d-flex align-center gap-3 mb-6" style="flex-wrap:wrap">
      <div style="flex:1">
        <h1 style="font-size:1.5rem;margin:0 0 var(--sp-2)">
          <i class="bi bi-shop" style="color:var(--primary)"></i>
          پنل فروشگاه B2B
        </h1>
        <p class="fs-sm" style="color:var(--text-muted);margin:0">
          مدیریت موجودی و ثبت گروهی کالاهای راکد برای معاوضه
        </p>
      </div>
      <?php if ($isStore && !empty($user['store_name'])): ?>
      <span class="badge badge-gold"><i class="bi bi-building"></i> <?= h($user['store_name']) ?></span>
      <?php endif; ?>
    </div>

    <?php if (!$isStore): ?>
    <div class="alert alert-info mb-6">
      <i class="bi bi-info-circle"></i>
      <div>
        برای استفاده از پنل فروشگاه، در
        <a href="<?= APP_URL ?>/profile/edit.php">ویرایش پروفایل</a>
        نوع حساب را «فروشگاه / کسب‌وکار» انتخاب کنید.
      </div>
    </div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="alert alert-success mb-5"><i class="bi bi-check-circle"></i> <?= h($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-danger mb-5"><i class="bi bi-exclamation-circle"></i> <?= h($error) ?></div>
    <?php endif; ?>

    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:var(--sp-4);margin-bottom:var(--sp-6)">
      <div class="stat-pill" style="padding:var(--sp-4)">
        <div style="font-size:1.5rem;font-weight:800;color:var(--primary)"><?= $activeCount ?> / <?= $limit ?></div>
        <div class="fs-sm" style="color:var(--text-muted)">آگهی فعال</div>
      </div>
      <div class="stat-pill" style="padding:var(--sp-4)">
        <div style="font-size:1.5rem;font-weight:800;color:var(--accent-dark)"><?= fmt_credit($totalValue) ?></div>
        <div class="fs-sm" style="color:var(--text-muted)">ارزش کل موجودی</div>
      </div>
      <div class="stat-pill" style="padding:var(--sp-4)">
        <div style="font-size:1.5rem;font-weight:800;color:var(--success)"><?= count(array_filter($inventory, fn($i) => (int)$i['pending_offers'] > 0)) ?></div>
        <div class="fs-sm" style="color:var(--text-muted)">آگهی با پیشنهاد</div>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-6);align-items:start">
      <div class="card">
        <div class="card-header">
          <h3 style="margin:0;font-size:1rem"><i class="bi bi-upload"></i> ثبت گروهی آگهی</h3>
        </div>
        <div class="card-body">
          <p class="fs-sm" style="color:var(--text-muted);line-height:1.7">
            هر خط = یک کالا. فرمت:
            <code dir="ltr">عنوان | slug-دسته | ارزش-SWP | نیازمند</code>
          </p>
          <form method="POST">
            <textarea class="form-control" name="bulk_listings" rows="10" <?= $isStore ? '' : 'disabled' ?>
                      placeholder="لپ‌تاپ Dell | laptops | 4500000 | موبایل / طلا&#10;مبل راحتی | furniture | 2000000 | لوازم آشپزخانه"></textarea>
            <button type="submit" class="btn btn-accent w-100 mt-4" <?= $isStore ? '' : 'disabled' ?>>
              <i class="bi bi-plus-circle"></i> ثبت همه
            </button>
          </form>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <h3 style="margin:0;font-size:1rem"><i class="bi bi-boxes"></i> موجودی فروشگاه</h3>
        </div>
        <div class="card-body" style="padding:0;max-height:420px;overflow-y:auto">
          <?php if (empty($inventory)): ?>
          <div class="empty-state" style="padding:var(--sp-8)">
            <i class="bi bi-inbox"></i>
            <p>هنوز موجودی ثبت نشده</p>
          </div>
          <?php else: ?>
          <?php foreach ($inventory as $item): ?>
          <div style="display:flex;align-items:center;justify-content:space-between;gap:var(--sp-3);padding:var(--sp-3) var(--sp-4);border-bottom:1px solid var(--border)">
            <div style="min-width:0">
              <div style="font-weight:600;font-size:.875rem"><?= h(mb_strimwidth($item['title'], 0, 40, '…')) ?></div>
              <div class="fs-xs" style="color:var(--text-muted)">
                <?= h(category_label($item['cat_slug'] ?? '', $item['cat_name'] ?? '')) ?>
                · <?= fmt_credit((float)$item['estimated_value']) ?>
                <?php if ((int)$item['pending_offers'] > 0): ?>
                · <span style="color:var(--warning)"><?= (int)$item['pending_offers'] ?> پیشنهاد</span>
                <?php endif; ?>
              </div>
            </div>
            <a href="<?= APP_URL ?>/listings/view.php?id=<?= $item['id'] ?>" class="btn btn-ghost btn-sm">مشاهده</a>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

  </div>
</div>

<?php render_footer(); ?>
