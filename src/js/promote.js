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

  // Function to format price like fmt_credit in PHP
  function formatPrice(num) {
    // Basic number format with thousands separators
    return new Intl.NumberFormat('fa-IR').format(num) + ' ' + 'تومان';
  }

  document.querySelectorAll('.promote-duration-select').forEach((select) => {
    select.addEventListener('change', function() {
      const planKey = this.dataset.planKey;
      const basePrice = parseInt(this.dataset.basePrice);
      const selectedOption = this.options[this.selectedIndex];
      const multiplier = parseFloat(selectedOption.dataset.priceMultiplier || 1);
      const totalPrice = Math.round(basePrice * multiplier);

      // Update price display
      const priceEl = document.getElementById('price-' + planKey);
      if (priceEl) {
        priceEl.textContent = new Intl.NumberFormat('fa-IR').format(totalPrice) + ' تومان';
      }

      // Update hidden field and form dataset
      const planCard = this.closest('.promote-plan');
      if (planCard) {
        const form = planCard.querySelector('.promote-plan__form');
        if (form) {
          form.dataset.planPrice = new Intl.NumberFormat('fa-IR').format(totalPrice) + ' تومان';
          const hiddenDuration = planCard.querySelector('.promote-selected-duration');
          if (hiddenDuration) hiddenDuration.value = this.value;
        }
      }
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
