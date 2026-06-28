<?php
// includes/skeleton.php — skeleton loading placeholders

function skeleton_listing_card(): string {
    return <<<'HTML'
<article class="listing-card listing-card--skeleton" aria-hidden="true">
  <div class="listing-card__header">
    <div class="skeleton skeleton-line skeleton-line--sm"></div>
  </div>
  <div class="listing-card__product">
    <div class="listing-card__details">
      <div class="skeleton skeleton-line skeleton-line--title"></div>
      <div class="skeleton skeleton-line skeleton-line--md"></div>
      <div class="skeleton skeleton-line skeleton-line--value"></div>
    </div>
    <div class="skeleton skeleton-block skeleton-block--img"></div>
  </div>
  <div class="skeleton skeleton-line skeleton-line--meta"></div>
  <div class="skeleton skeleton-line skeleton-line--cta"></div>
</article>
HTML;
}

function skeleton_listing_cards(int $count = 6): void {
    echo '<div class="listings-grid listings-grid--skeleton" aria-busy="true" aria-label="در حال بارگذاری آگهی‌ها">';
    for ($i = 0; $i < $count; $i++) {
        echo skeleton_listing_card();
    }
    echo '</div>';
}

function skeleton_notif_items(int $count = 4): string {
    $items = '';
    for ($i = 0; $i < $count; $i++) {
        $items .= <<<'HTML'
<div class="notif-item notif-item--skeleton" aria-hidden="true">
  <div class="skeleton skeleton-circle skeleton-circle--md"></div>
  <div class="notif-item__body" style="flex:1">
    <div class="skeleton skeleton-line skeleton-line--sm"></div>
    <div class="skeleton skeleton-line skeleton-line--md"></div>
  </div>
  <div class="skeleton skeleton-line skeleton-line--xs"></div>
</div>
HTML;
    }
    return $items;
}

function skeleton_match_rows(int $count = 3): string {
    $rows = '';
    for ($i = 0; $i < $count; $i++) {
        $rows .= <<<'HTML'
<div class="match-row match-row--skeleton" aria-hidden="true">
  <div class="skeleton skeleton-circle skeleton-circle--score"></div>
  <div class="match-row__body" style="flex:1">
    <div class="skeleton skeleton-line skeleton-line--sm"></div>
    <div class="skeleton skeleton-line skeleton-line--md"></div>
  </div>
</div>
HTML;
    }
    return $rows;
}
