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
    const updatePlanPrice = () => {
      const planKey = select.dataset.planKey;
      const basePrice = parseInt(select.dataset.basePrice || '0', 10);
      const selectedOption = select.options[select.selectedIndex];
      const multiplier = parseFloat(selectedOption.dataset.priceMultiplier || '1');
      const explicitPrice = parseInt(selectedOption.dataset.price || '0', 10);
      const totalPrice = explicitPrice > 0 ? explicitPrice : Math.round(basePrice * multiplier);

      const priceEl = document.getElementById('price-' + planKey);
      if (priceEl) {
        priceEl.textContent = formatPrice(totalPrice);
      }

      const planCard = select.closest('.promote-plan');
      if (planCard) {
        planCard.querySelectorAll('.promote-plan__form').forEach((form) => {
          form.dataset.planPrice = formatPrice(totalPrice);
        });
        planCard.querySelectorAll('.promote-selected-duration').forEach((hidden) => {
          hidden.value = select.value;
        });
      }
    };

    select.addEventListener('change', updatePlanPrice);
    updatePlanPrice();
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

  document.querySelectorAll('.promote-plan__credit-form').forEach((form) => {
    form.addEventListener('submit', (e) => {
      const name = form.dataset.planName || 'این پلن';
      if (!confirm(`پلن «${name}» با ۱ اعتبار ارتقای رایگان فعال شود؟`)) {
        e.preventDefault();
      }
    });
  });
})();
