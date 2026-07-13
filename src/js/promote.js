// Promote page — accordion + plan confirm
(function () {
  document.querySelectorAll('.promote-accordion__trigger').forEach((btn) => {
    btn.addEventListener('click', () => {
      const item = btn.closest('.promote-accordion__item');
      const wasOpen = item.classList.contains('is-open');
      document.querySelectorAll('.promote-accordion__item').forEach((el) => el.classList.remove('is-open'));
      if (!wasOpen) item.classList.add('is-open');
    });
  });

  document.querySelectorAll('.promote-plan__form').forEach((form) => {
    form.addEventListener('submit', (e) => {
      const name = form.dataset.planName || 'این پلن';
      const price = form.dataset.planPrice || '';
      if (!confirm(`پلن «${name}» به مبلغ ${price} فعال شود؟`)) {
        e.preventDefault();
      }
    });
  });
})();
