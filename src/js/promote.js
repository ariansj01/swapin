// Promote page interactions
(function () {
  const toggle  = document.getElementById('dash-sidebar-toggle');
  const sidebar = document.getElementById('dash-sidebar');
  const overlay = document.getElementById('dash-sidebar-overlay');

  function setSidebarOpen(open) {
    if (!sidebar) return;
    sidebar.classList.toggle('is-open', open);
    if (overlay) overlay.hidden = !open;
    document.body.style.overflow = open ? 'hidden' : '';
  }

  if (toggle && sidebar) {
    toggle.addEventListener('click', () => setSidebarOpen(!sidebar.classList.contains('is-open')));
    overlay?.addEventListener('click', () => setSidebarOpen(false));
    document.addEventListener('click', (e) => {
      if (window.innerWidth > 992) return;
      if (!sidebar.contains(e.target) && !toggle.contains(e.target)) {
        setSidebarOpen(false);
      }
    });
  }

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
