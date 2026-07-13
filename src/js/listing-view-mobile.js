/* ── Listing View Mobile — gallery, sheet modal, offer form ─────────────── */

function initListingViewMobile() {
  const root = document.querySelector('.lv-mobile');
  if (!root) return;

  initLvGallery(root);
  initLvReadMore(root);
  initLvOfferSheet(root);
}

function initLvGallery(root) {
  const gallery = root.querySelector('.lv-gallery');
  if (!gallery) return;

  const track   = gallery.querySelector('.lv-gallery__track');
  const counter = gallery.querySelector('.lv-gallery__counter');
  const slides  = gallery.querySelectorAll('.lv-gallery__slide');
  if (!track || slides.length <= 1) return;

  let index = 0;
  let startX = 0;
  let currentX = 0;
  let dragging = false;

  const total = slides.length;

  function updateCounter() {
    if (counter) {
      counter.textContent = `${toPersianNum(index + 1)} از ${toPersianNum(total)}`;
    }
  }

  function setIndex(i) {
    index = Math.max(0, Math.min(total - 1, i));
    track.style.transform = `translateX(-${index * 100}%)`;
    updateCounter();
  }

  function onTouchStart(e) {
    startX = e.touches[0].clientX;
    currentX = startX;
    dragging = true;
    track.style.transition = 'none';
  }

  function onTouchMove(e) {
    if (!dragging) return;
    currentX = e.touches[0].clientX;
    const diff = currentX - startX;
    const offset = (-index * 100) + (diff / gallery.offsetWidth * 100);
    track.style.transform = `translateX(${offset}%)`;
  }

  function onTouchEnd() {
    if (!dragging) return;
    dragging = false;
    track.style.transition = '';
    const diff = currentX - startX;
    const threshold = gallery.offsetWidth * 0.2;
    if (diff < -threshold && index < total - 1) setIndex(index + 1);
    else if (diff > threshold && index > 0) setIndex(index - 1);
    else setIndex(index);
  }

  gallery.addEventListener('touchstart', onTouchStart, { passive: true });
  gallery.addEventListener('touchmove', onTouchMove, { passive: true });
  gallery.addEventListener('touchend', onTouchEnd);

  updateCounter();
}

function initLvReadMore(root) {
  const desc = root.querySelector('.lv-desc');
  const btn  = root.querySelector('.lv-read-more');
  if (!desc || !btn) return;

  if (desc.scrollHeight <= desc.offsetHeight + 4) {
    btn.style.display = 'none';
    desc.classList.remove('is-collapsed');
    return;
  }

  btn.addEventListener('click', () => {
    const collapsed = desc.classList.toggle('is-collapsed');
    btn.innerHTML = collapsed
      ? 'مشاهده بیشتر <i class="bi bi-chevron-down"></i>'
      : 'بستن <i class="bi bi-chevron-up"></i>';
  });
}

function initLvOfferSheet(root) {
  const sheet     = document.getElementById('lv-offer-sheet');
  const openBtn   = document.getElementById('lv-open-offer');
  const closeBtn  = sheet?.querySelector('.lv-sheet__close');
  const backdrop  = sheet?.querySelector('.lv-sheet__backdrop');
  const form      = document.getElementById('lv-offer-form');
  const submitBtn = sheet?.querySelector('.lv-sheet__submit');

  if (!sheet) return;

  const listingId = root.dataset.listingId || '';
  const storageKey = `lv_offer_${listingId}`;

  function openSheet() {
    sheet.classList.add('is-open');
    document.body.classList.add('lv-modal-open');
    restoreFormState();
    updateSubmitState();
  }

  function closeSheet() {
    saveFormState();
    sheet.classList.remove('is-open');
    document.body.classList.remove('lv-modal-open');
  }

  openBtn?.addEventListener('click', (e) => {
    e.preventDefault();
    const href = openBtn.dataset.loginHref;
    if (href) {
      window.location.href = href;
      return;
    }
    openSheet();
  });

  closeBtn?.addEventListener('click', closeSheet);
  backdrop?.addEventListener('click', closeSheet);

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && sheet.classList.contains('is-open')) closeSheet();
  });

  // Offer item selection
  sheet.querySelectorAll('.lv-offer-item').forEach(item => {
    item.addEventListener('click', (e) => {
      if (e.target.matches('input[type="radio"]')) return;
      const radio = item.querySelector('input[type="radio"]');
      if (radio) {
        radio.checked = true;
        selectOfferItem(radio.value);
      }
    });
  });

  sheet.querySelectorAll('.lv-offer-item__radio').forEach(radio => {
    radio.addEventListener('change', () => {
      if (radio.checked) selectOfferItem(radio.value);
    });
  });

  function selectOfferItem(id) {
    sheet.querySelectorAll('.lv-offer-item').forEach(el => {
      el.classList.toggle('is-selected', el.dataset.listingId === id);
    });
    updateSubmitState();
    saveFormState();
  }

  // Credit direction
  const creditRadios = sheet.querySelectorAll('input[name="credit_direction"]');
  const creditWrap   = sheet.querySelector('.lv-credit-amount');
  const creditInput  = sheet.querySelector('#lv-credit-amount');

  creditRadios.forEach(radio => {
    radio.addEventListener('change', () => {
      const direction = sheet.querySelector('input[name="credit_direction"]:checked')?.value;
      const show = direction === 'pay' || direction === 'receive';
      creditWrap?.classList.toggle('is-visible', show);
      if (!show && creditInput) creditInput.value = '';
      updateSubmitState();
      saveFormState();
    });
  });

  if (creditInput) {
    creditInput.addEventListener('input', () => {
      formatTomanInput(creditInput);
      updateSubmitState();
      saveFormState();
    });
  }

  // Initial submit state when first item is pre-selected
  updateSubmitState();

  const messageInput = sheet.querySelector('textarea[name="message"]');
  messageInput?.addEventListener('input', saveFormState);

  function updateSubmitState() {
    if (!submitBtn) return;
    const selected = sheet.querySelector('.lv-offer-item__radio:checked');
    const direction = sheet.querySelector('input[name="credit_direction"]:checked')?.value;
    const amountRaw = creditInput?.value.replace(/[^\d]/g, '') || '';

    let valid = !!selected;
    if (direction === 'pay' || direction === 'receive') {
      valid = valid && parseInt(amountRaw, 10) > 0;
    }
    submitBtn.disabled = !valid;
  }

  function saveFormState() {
    if (!listingId) return;
    const selected = sheet.querySelector('.lv-offer-item__radio:checked');
    const direction = sheet.querySelector('input[name="credit_direction"]:checked');
    try {
      sessionStorage.setItem(storageKey, JSON.stringify({
        listingId: selected?.value || '',
        creditDirection: direction?.value || 'none',
        creditAmount: creditInput?.value || '',
        message: messageInput?.value || '',
      }));
    } catch (_) { /* quota */ }
  }

  function restoreFormState() {
    try {
      const raw = sessionStorage.getItem(storageKey);
      if (!raw) return;
      const data = JSON.parse(raw);

      if (data.listingId) {
        const radio = sheet.querySelector(`.lv-offer-item__radio[value="${data.listingId}"]`);
        if (radio) {
          radio.checked = true;
          selectOfferItem(data.listingId);
        }
      }

      if (data.creditDirection) {
        const dirRadio = sheet.querySelector(`input[name="credit_direction"][value="${data.creditDirection}"]`);
        if (dirRadio) {
          dirRadio.checked = true;
          dirRadio.dispatchEvent(new Event('change'));
        }
      }

      if (data.creditAmount && creditInput) {
        creditInput.value = data.creditAmount;
        formatTomanInput(creditInput);
      }

      if (data.message && messageInput) messageInput.value = data.message;
    } catch (_) { /* ignore */ }

    updateSubmitState();
  }

  form?.addEventListener('submit', (e) => {
    const selected = sheet.querySelector('.lv-offer-item__radio:checked');
    if (!selected) {
      e.preventDefault();
      showToast('لطفاً یکی از کالاهای خود را انتخاب کنید', 'error');
      return;
    }

    const direction = sheet.querySelector('input[name="credit_direction"]:checked')?.value;
    const amountRaw = creditInput?.value.replace(/[^\d]/g, '') || '';
    if ((direction === 'pay' || direction === 'receive') && parseInt(amountRaw, 10) <= 0) {
      e.preventDefault();
      showToast('لطفاً مبلغ اختلاف قیمت را وارد کنید', 'error');
      return;
    }

    if (creditInput) {
      const hidden = form.querySelector('input[name="credit_amount"]');
      if (hidden) hidden.value = amountRaw;
    }

    sessionStorage.removeItem(storageKey);
    if (submitBtn) submitBtn.disabled = true;
  });

  // Share button
  const shareBtn = root.querySelector('#lv-share-btn');
  shareBtn?.addEventListener('click', () => {
    const title = shareBtn.dataset.title || '';
    if (navigator.share) {
      navigator.share({ title, url: location.href }).catch(() => {});
    } else {
      navigator.clipboard.writeText(location.href).then(() => showToast('لینک کپی شد!', 'success'));
    }
  });

  // Back button
  root.querySelector('.lv-gallery__btn--back')?.addEventListener('click', () => {
    if (history.length > 1) history.back();
    else window.location.href = document.querySelector('meta[name="app-url"]')?.content || '/';
  });
}

function formatTomanInput(input) {
  const digits = input.value.replace(/[^\d]/g, '');
  if (!digits) {
    input.value = '';
    return;
  }
  input.value = parseInt(digits, 10).toLocaleString('fa-IR') + ' تومان';
}

function toPersianNum(n) {
  return String(n).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
}

document.addEventListener('DOMContentLoaded', initListingViewMobile);
