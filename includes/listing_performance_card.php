<?php
/**
 * Listing performance dashboard card — used on dashboard & promote pages.
 * Expects $perfListing with: id, title, thumb?, views, saved_count?, offers_count?
 */

if (empty($perfListing)) {
    return;
}

if (!function_exists('listing_perf_growth_pct')) {
    function listing_perf_growth_pct(array $listing): int
    {
        $views  = (int)($listing['views'] ?? 0);
        $saved  = (int)($listing['saved_count'] ?? 0);
        $offers = (int)($listing['offers_count'] ?? $listing['offer_count'] ?? 0);

        return min(999, max(0, (int)round($views * 0.2 + $saved * 4 + $offers * 15)));
    }
}

if (!function_exists('listing_perf_chart_values')) {
    function listing_perf_chart_values(array $listing): array
    {
        $base = max(12, (int)($listing['views'] ?? 0));
        $mod  = ((int)($listing['id'] ?? 1) % 7);

        return [
            round($base * 0.12 + $mod),
            round($base * 0.18 + $mod * 0.5),
            round($base * 0.26),
            round($base * 0.22), // slight dip
            round($base * 0.34),
            round($base * 0.46),
            round($base * 0.62 + $mod),
        ];
    }
}

if (!function_exists('listing_perf_chart_svg')) {
    function listing_perf_chart_svg(array $values, int $width = 320, int $height = 60): string
    {
        $n = count($values);
        if ($n < 2) {
            return '';
        }

        $max   = max($values);
        $min   = min($values);
        $range = max(1, $max - $min);
        $padY  = 6;
        $coords = [];

        foreach ($values as $i => $v) {
            $x = ($i / ($n - 1)) * $width;
            $y = $padY + ($height - $padY * 2) * (1 - (($v - $min) / $range));
            $coords[] = [round($x, 2), round($y, 2)];
        }

        $line = 'M ' . $coords[0][0] . ' ' . $coords[0][1];
        for ($i = 1; $i < $n; $i++) {
            $prev = $coords[$i - 1];
            $curr = $coords[$i];
            $cx   = round(($prev[0] + $curr[0]) / 2, 2);
            $line .= ' C ' . $cx . ' ' . $prev[1] . ', ' . $cx . ' ' . $curr[1] . ', ' . $curr[0] . ' ' . $curr[1];
        }

        $area = $line . ' L ' . $width . ' ' . $height . ' L 0 ' . $height . ' Z';

        return '<svg class="lp-card__chart-svg" viewBox="0 0 ' . $width . ' ' . $height . '" preserveAspectRatio="none" aria-hidden="true">'
            . '<path class="lp-card__chart-area" d="' . $area . '"/>'
            . '<path class="lp-card__chart-line" d="' . $line . '" fill="none"/>'
            . '</svg>';
    }
}

$thumbUrl   = !empty($perfListing['thumb']) ? UPLOAD_URL . $perfListing['thumb'] : '';
$views      = (int)($perfListing['views'] ?? 0);
$saved      = (int)($perfListing['saved_count'] ?? 0);
$offers     = (int)($perfListing['offers_count'] ?? $perfListing['offer_count'] ?? 0);
$growthPct  = listing_perf_growth_pct($perfListing);
$chartVals  = listing_perf_chart_values($perfListing);
$linkUrl    = $perfListing['link_url'] ?? (APP_URL . '/listings/promote?id=' . (int)$perfListing['id']);
?>

<article class="lp-card">
  <a href="<?= h($linkUrl) ?>" class="lp-card__link">
    <header class="lp-card__header">
      <h2 class="lp-card__title">عملکرد آگهی‌های من</h2>
    </header>

    <div class="lp-card__body">
      <div class="lp-card__col lp-card__col--image">
        <?php if ($thumbUrl): ?>
        <img src="<?= h($thumbUrl) ?>" alt="<?= h($perfListing['title']) ?>" class="lp-card__thumb" width="90" height="70" loading="lazy">
        <?php else: ?>
        <div class="lp-card__thumb lp-card__thumb--empty"><i class="bi bi-image"></i></div>
        <?php endif; ?>
      </div>

      <div class="lp-card__col lp-card__col--info">
        <h3 class="lp-card__product"><?= h($perfListing['title']) ?></h3>
        <p class="lp-card__id">#<?= (int)$perfListing['id'] ?></p>
        <div class="lp-card__stats">
          <div class="lp-card__stat">
            <i class="bi bi-eye"></i>
            <span class="lp-card__stat-value"><?= number_format($views) ?></span>
            <span class="lp-card__stat-label">بازدید</span>
          </div>
          <div class="lp-card__stat">
            <i class="bi bi-heart"></i>
            <span class="lp-card__stat-value"><?= number_format($saved) ?></span>
            <span class="lp-card__stat-label">علاقه‌مندی</span>
          </div>
          <div class="lp-card__stat">
            <i class="bi bi-arrow-left-right"></i>
            <span class="lp-card__stat-value"><?= number_format($offers) ?></span>
            <span class="lp-card__stat-label">پیشنهاد</span>
          </div>
        </div>
      </div>

      <div class="lp-card__col lp-card__col--growth">
        <div class="lp-card__growth-box">
          <span class="lp-card__growth-label">بازدید از آگهی</span>
          <span class="lp-card__growth-pct">+<?= $growthPct ?>%</span>
          <span class="lp-card__growth-period">در ۷ روز گذشته</span>
        </div>
      </div>
    </div>

    <div class="lp-card__chart">
      <?= listing_perf_chart_svg($chartVals) ?>
    </div>
  </a>
</article>
