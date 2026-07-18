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
