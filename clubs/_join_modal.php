<?php
$myListings = $myListings ?? [];
$joinCreateUrl = $joinCreateUrl ?? (APP_URL . '/listings/create');
?>
<div class="clm" id="club-join-modal" hidden>
  <div class="clm__backdrop" data-join-close></div>
  <div class="clm__dialog clm__dialog--join" role="dialog" aria-modal="true" aria-labelledby="club-join-title">
    <div class="clm__body" style="grid-column:1/-1">
      <div class="clm__top">
        <button type="button" class="clm__close" data-join-close aria-label="بستن"><i class="bi bi-x-lg"></i></button>
      </div>
      <h2 class="clm__title" id="club-join-title">انتخاب آگهی برای پیوستن</h2>
      <p class="clubs-profile__about">برای عضویت در کلاب باید یکی از آگهی‌های فعال خود را انتخاب کنید. مدیر کلاب این آگهی را همراه درخواست شما می‌بیند.</p>
      <?php if (!$myListings): ?>
      <div class="clubs-empty">هنوز آگهی فعالی ندارید.</div>
      <a class="clm__join" href="<?= h($joinCreateUrl) ?>">ثبت آگهی جدید</a>
      <?php else: ?>
      <form method="POST" class="club-join-pick">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="join">
        <input type="hidden" name="club_id" id="club-join-club-id" value="0">
        <div class="club-join-pick__list">
          <?php foreach ($myListings as $ml): ?>
          <label class="club-join-pick__item">
            <input type="radio" name="listing_id" value="<?= (int)$ml['id'] ?>" required>
            <?php if (!empty($ml['thumb'])): ?>
            <img src="<?= h(UPLOAD_URL . $ml['thumb']) ?>" alt="">
            <?php else: ?>
            <span class="club-join-pick__ph"><i class="bi bi-image"></i></span>
            <?php endif; ?>
            <span><?= h($ml['title']) ?></span>
          </label>
          <?php endforeach; ?>
        </div>
        <button class="clm__join" type="submit">ارسال درخواست عضویت</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<script>
(function () {
  const modal = document.getElementById('club-join-modal');
  if (!modal) return;
  const idInput = document.getElementById('club-join-club-id');
  document.querySelectorAll('[data-open-join]').forEach(btn => {
    btn.addEventListener('click', () => {
      if (btn.disabled) return;
      if (idInput) idInput.value = btn.getAttribute('data-open-join') || '0';
      const listingModal = document.getElementById('club-listing-modal');
      if (listingModal) listingModal.hidden = true;
      modal.hidden = false;
      document.body.classList.add('clm-open');
    });
  });
  modal.querySelectorAll('[data-join-close]').forEach(el => el.addEventListener('click', () => {
    modal.hidden = true;
    document.body.classList.remove('clm-open');
  }));
})();
</script>
