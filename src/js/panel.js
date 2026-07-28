function initScrollReset() {
  if ('scrollRestoration' in window.history) {
    window.history.scrollRestoration = 'manual';
  }

  const resetToTop = () => {
    window.scrollTo({ top: 0, left: 0, behavior: 'auto' });
    document.documentElement.scrollTop = 0;
    document.body.scrollTop = 0;
  };

  const restoreTop = () => {
    window.requestAnimationFrame(() => {
      resetToTop();
      window.requestAnimationFrame(resetToTop);
    });
  };

  window.addEventListener('load', restoreTop);
  window.addEventListener('pageshow', restoreTop);
  restoreTop();
}

initScrollReset();

// User panel sidebar toggle (all panel pages)
(function () {
  const toggle  = document.getElementById('dash-sidebar-toggle');
  const sidebar = document.getElementById('dash-sidebar');
  const overlay = document.getElementById('dash-sidebar-overlay');

  if (!toggle || !sidebar) return;

  function setSidebarOpen(open) {
    sidebar.classList.toggle('is-open', open);
    if (overlay) overlay.hidden = !open;
    document.body.classList.toggle('panel-sidebar-open', open);
  }

  toggle.addEventListener('click', (e) => {
    // Only toggle if the click is on the toggle itself or its direct children
    if (e.target === toggle || toggle.contains(e.target)) {
      setSidebarOpen(!sidebar.classList.contains('is-open'));
    }
  });
  overlay?.addEventListener('click', () => setSidebarOpen(false));

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') setSidebarOpen(false);
  });
})();
