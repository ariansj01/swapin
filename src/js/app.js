/**
 * Swapin (سواپین) — app.js
 * Vanilla JS — no dependencies
 */

function getCsrfToken() {
  return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

function withCsrfHeaders(headers = {}) {
  const token = getCsrfToken();
  if (token) {
    headers['X-CSRF-Token'] = token;
  }
  return headers;
}

function appendCsrf(formData) {
  const token = getCsrfToken();
  if (token && formData instanceof FormData) {
    formData.append('_csrf', token);
  }
  return formData;
}

/* ── Toast notification system ─────────────────────────────────────────── */
function showToast(msg, type = 'info', duration = 3500) {
  const container = document.getElementById('toast-container');
  if (!container) return;

  const icons = { success: 'bi-check-circle-fill', error: 'bi-exclamation-circle-fill', info: 'bi-info-circle-fill', warning: 'bi-exclamation-triangle-fill' };
  const colors = { success: 'var(--success)', error: 'var(--danger)', info: 'var(--info)', warning: 'var(--warning)' };

  const toast = document.createElement('div');
  toast.className = `toast toast-${type === 'error' ? 'error' : type}`;
  const icon = document.createElement('i');
  icon.className = `bi ${icons[type] || icons.info}`;
  icon.style.cssText = `color:${colors[type]};font-size:1rem;flex-shrink:0`;
  const span = document.createElement('span');
  span.textContent = String(msg ?? '');
  toast.appendChild(icon);
  toast.appendChild(span);
  container.appendChild(toast);

  setTimeout(() => {
    toast.style.opacity = '0';
    toast.style.transform = 'translateY(8px)';
    toast.style.transition = 'all .2s ease';
    setTimeout(() => toast.remove(), 220);
  }, duration);
}

/* ── Skeleton loading helpers ──────────────────────────────────────────── */
function skeletonListingCardHtml() {
  return `<article class="listing-card listing-card--skeleton" aria-hidden="true">
    <div class="listing-card__header"><div class="skeleton skeleton-line skeleton-line--sm"></div></div>
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
  </article>`;
}

function showListingsGridSkeleton(count = 6) {
  const grid = document.getElementById('listings-grid');
  if (!grid) return;
  grid.classList.add('is-loading');
  grid.setAttribute('aria-busy', 'true');
  grid.innerHTML = Array.from({ length: count }, skeletonListingCardHtml).join('');
}

function skeletonMatchRowsHtml(count = 3) {
  return Array.from({ length: count }, () =>
    `<div class="match-row match-row--skeleton" aria-hidden="true">
      <div class="skeleton skeleton-circle skeleton-circle--score"></div>
      <div class="match-row__body" style="flex:1">
        <div class="skeleton skeleton-line skeleton-line--sm"></div>
        <div class="skeleton skeleton-line skeleton-line--md"></div>
      </div>
    </div>`
  ).join('');
}

function skeletonNotifItemsHtml(count = 4) {
  return Array.from({ length: count }, () =>
    `<div class="notif-item notif-item--skeleton" aria-hidden="true">
      <div class="skeleton skeleton-circle skeleton-circle--md"></div>
      <div class="notif-item__body" style="flex:1">
        <div class="skeleton skeleton-line skeleton-line--sm"></div>
        <div class="skeleton skeleton-line skeleton-line--md"></div>
      </div>
      <div class="skeleton skeleton-line skeleton-line--xs"></div>
    </div>`
  ).join('');
}

/* ── Dropdown menus ────────────────────────────────────────────────────── */
function initDropdowns() {
  document.querySelectorAll('.dropdown').forEach(dropdown => {
    const btn  = dropdown.querySelector('[id$="-btn"], button');
    const menu = dropdown.querySelector('.dropdown-menu');
    if (!btn || !menu) return;

    btn.addEventListener('click', e => {
      e.stopPropagation();
      const isOpen = menu.classList.contains('open');
      // Close all others
      document.querySelectorAll('.dropdown-menu.open').forEach(m => m.classList.remove('open'));
      if (!isOpen) menu.classList.add('open');
    });
  });

  document.addEventListener('click', () => {
    document.querySelectorAll('.dropdown-menu.open').forEach(m => m.classList.remove('open'));
  });
}

/* ── Tab switching (generic) ───────────────────────────────────────────── */
function switchTab(tabId) {
  const allBtns   = document.querySelectorAll('.tab-btn');
  const allPanels = document.querySelectorAll('.tab-panel');

  allBtns.forEach(btn => btn.classList.remove('active'));
  allPanels.forEach(panel => panel.classList.remove('active'));

  const targetBtn   = document.querySelector(`.tab-btn[data-tab="${tabId}"]`);
  const targetPanel = document.getElementById(`panel-${tabId}`);

  if (targetBtn)   targetBtn.classList.add('active');
  if (targetPanel) targetPanel.classList.add('active');

  // Push to history without reload
  const url = new URL(window.location);
  url.searchParams.set('tab', tabId);
  history.replaceState(null, '', url.toString());
}

/* ── Confirm dialogs ───────────────────────────────────────────────────── */
function confirmAction(msg) {
  return window.confirm(msg);
}

/* ── Password visibility toggle ────────────────────────────────────────── */
function togglePass(inputId, iconId) {
  const input = document.getElementById(inputId);
  const icon  = document.getElementById(iconId);
  if (!input) return;
  const isPass = input.type === 'password';
  input.type   = isPass ? 'text' : 'password';
  if (icon) icon.className = isPass ? 'bi bi-eye-slash' : 'bi bi-eye';
}

/* ── Character counter helper ───────────────────────────────────────────── */
function initCharCounters() {
  document.querySelectorAll('[data-count-target]').forEach(input => {
    const targetId = input.dataset.countTarget;
    const counter  = document.getElementById(targetId);
    if (!counter) return;

    const update = () => { counter.textContent = input.value.length; };
    input.addEventListener('input', update);
    update();
  });
}

/* ── Auto-dismiss alerts ────────────────────────────────────────────────── */
function initAutoDismissAlerts() {
  document.querySelectorAll('.alert[data-auto-dismiss]').forEach(alert => {
    const delay = parseInt(alert.dataset.autoDismiss) || 4000;
    setTimeout(() => {
      alert.style.transition = 'opacity .4s';
      alert.style.opacity = '0';
      setTimeout(() => alert.remove(), 420);
    }, delay);
  });
}

/* ── Smooth scroll to anchor ────────────────────────────────────────────── */
function initSmoothAnchors() {
  document.querySelectorAll('a[href^="#"]').forEach(link => {
    link.addEventListener('click', e => {
      const target = document.querySelector(link.getAttribute('href'));
      if (target) {
        e.preventDefault();
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    });
  });
}

/* ── Sticky navbar shadow on scroll ────────────────────────────────────── */
function initNavbarScroll() {
  const nav = document.querySelector('.navbar');
  if (!nav) return;
  window.addEventListener('scroll', () => {
    nav.style.boxShadow = window.scrollY > 10 ? 'var(--shadow-md)' : 'var(--shadow-sm)';
  }, { passive: true });
}

/* ── Image lazy loading fallback ────────────────────────────────────────── */
function initLazyImages() {
  const imgs = document.querySelectorAll('img[loading="lazy"]');
  if ('loading' in HTMLImageElement.prototype) return; // native support

  if ('IntersectionObserver' in window) {
    const observer = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          const img = entry.target;
          img.src = img.dataset.src || img.src;
          observer.unobserve(img);
        }
      });
    });
    imgs.forEach(img => observer.observe(img));
  }
}

/* ── Global search input sync ───────────────────────────────────────────── */
function getAppUrl() {
  return document.querySelector('meta[name="app-url"]')?.content?.replace(/\/$/, '') || '';
}

function initGlobalSearch() {
  const globalSearch = document.getElementById('global-search');
  if (!globalSearch) return;

  const appUrl = getAppUrl();
  const homePath = appUrl ? new URL(appUrl + '/').pathname : '/';

  const q = new URLSearchParams(window.location.search).get('q');
  if (q && (window.location.pathname === homePath || window.location.pathname.endsWith('/index.php'))) {
    globalSearch.value = q;
  }

  globalSearch.addEventListener('keydown', e => {
    if (e.key === 'Enter') {
      e.preventDefault();
      const v = globalSearch.value.trim();
      const base = appUrl || window.location.origin;
      window.location.href = v ? `${base}/?q=${encodeURIComponent(v)}` : `${base}/`;
    }
  });
}

/* ── Homepage filter bar ────────────────────────────────────────────────── */
function initHomeFilters() {
  const searchInput = document.getElementById('search-input');
  if (!searchInput) return;

  const appUrl = getAppUrl() || window.location.origin;

  function applyFilter() {
    const p = new URLSearchParams(window.location.search);
    const q    = searchInput.value.trim();
    const city = document.getElementById('city-filter')?.value || '';
    const want = document.getElementById('want-filter')?.value || '';
    const sort = document.getElementById('sort-filter')?.value || 'new';

    if (q)    p.set('q', q);    else p.delete('q');
    if (city) p.set('city', city); else p.delete('city');
    if (want) p.set('want', want); else p.delete('want');
    p.set('sort', sort);
    p.delete('page');

    showListingsGridSkeleton(6);
    const qs = p.toString();
    window.location.href = qs ? `${appUrl}/?${qs}` : `${appUrl}/`;
  }

  let searchTimer;
  searchInput.addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilter, 500);
  });
  searchInput.addEventListener('keydown', e => {
    if (e.key === 'Enter') { clearTimeout(searchTimer); applyFilter(); }
  });

  document.getElementById('city-filter')?.addEventListener('change', applyFilter);
  document.getElementById('want-filter')?.addEventListener('change', applyFilter);
  document.getElementById('sort-filter')?.addEventListener('change', applyFilter);

  document.querySelectorAll('.cat-pill').forEach(pill => {
    pill.addEventListener('click', () => showListingsGridSkeleton(6));
  });
}

/* ── Save / unsave button feedback ─────────────────────────────────────── */
function initSaveButtons() {
  const appUrl = getAppUrl();

  document.querySelectorAll('[data-save-toggle]').forEach(btn => {
    btn.addEventListener('click', async (e) => {
      e.preventDefault();
      e.stopPropagation();

      const listingId = btn.dataset.listingId;
      if (!listingId || !appUrl) return;

      const wasSaved = btn.dataset.saveToggle === 'true';
      const icon = btn.querySelector('i');

      btn.disabled = true;
      try {
        const fd = new FormData();
        fd.append('listing_id', listingId);
        appendCsrf(fd);
        const res = await fetch(`${appUrl}/api/save_listing.php`, {
          method: 'POST',
          body: fd,
          credentials: 'same-origin',
          headers: withCsrfHeaders(),
        });
        const data = await res.json();

        if (!res.ok) {
          if (data.error === 'login_required') {
            window.location.href = `${appUrl}/auth/login.php?redirect=${encodeURIComponent(window.location.pathname + window.location.search)}`;
            return;
          }
          throw new Error(data.error || 'save_failed');
        }

        const saved = !!data.saved;
        btn.dataset.saveToggle = saved ? 'true' : 'false';
        btn.classList.toggle('is-saved', saved);
        btn.setAttribute('aria-pressed', saved ? 'true' : 'false');
        btn.setAttribute('aria-label', saved ? 'حذف از علاقه‌مندی‌ها' : 'افزودن به علاقه‌مندی‌ها');
        if (icon) icon.className = saved ? 'bi bi-heart-fill' : 'bi bi-heart';
        showToast(saved ? 'به علاقه‌مندی‌ها اضافه شد' : 'از علاقه‌مندی‌ها حذف شد', 'success', 2500);
      } catch {
        btn.dataset.saveToggle = wasSaved ? 'true' : 'false';
        showToast('خطا در ذخیره. دوباره تلاش کنید.', 'error');
      } finally {
        btn.disabled = false;
      }
    });
  });
}

/* ── Number formatting for credit amounts ──────────────────────────────── */
function getCreditUnit() {
  return document.querySelector('meta[name="credit-unit"]')?.content || 'تومان';
}

function formatKBC(amount) {
  return new Intl.NumberFormat('fa-IR').format(Math.round(amount)) + ' ' + getCreditUnit();
}

/* ── Offer form validation ──────────────────────────────────────────────── */
function initOfferForm() {
  // This is now handled in listings/view.php's inline JS, so we'll keep this empty or remove it
  // to avoid conflicting validation
}

/* ── Loading button state ───────────────────────────────────────────────── */
function initLoadingForms() {
  document.querySelectorAll('form[data-loading]').forEach(form => {
    form.addEventListener('submit', function() {
      const btn     = this.querySelector('[type="submit"]');
      const txtEl   = btn?.querySelector('[data-btn-text]');
      const spinEl  = btn?.querySelector('[data-btn-spinner]');
      if (btn)    btn.disabled = true;
      if (txtEl)  txtEl.style.display = 'none';
      if (spinEl) spinEl.style.display = 'inline-block';
    });
  });
}

/* ── Modal helpers ──────────────────────────────────────────────────────── */
function openModal(id)  { document.getElementById(id)?.classList.add('show'); }
function closeModal(id) { document.getElementById(id)?.classList.remove('show'); }

// Close modal on overlay click
document.addEventListener('click', e => {
  if (e.target.classList.contains('modal-overlay')) {
    e.target.classList.remove('show');
  }
});

// Close on Escape
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-overlay.show').forEach(m => m.classList.remove('show'));
    document.querySelectorAll('.dropdown-menu.open').forEach(m => m.classList.remove('open'));
  }
});

/* ── Star rating picker ─────────────────────────────────────────────────── */
function initStarPicker(containerId) {
  const container = document.getElementById(containerId || 'star-picker');
  if (!container) return;

  const radios = container.querySelectorAll('input[type="radio"]');
  const icons  = container.querySelectorAll('i');

  radios.forEach((radio, i) => {
    radio.addEventListener('change', () => {
      icons.forEach((icon, j) => {
        icon.className    = j <= i ? 'bi bi-star-fill' : 'bi bi-star';
        icon.style.color  = j <= i ? 'var(--accent)' : 'var(--border-strong)';
      });
    });
  });
}

/* ── Trade type button picker ───────────────────────────────────────────── */
function initTradeTypePicker() {
  document.querySelectorAll('.want-radio').forEach(radio => {
    radio.addEventListener('change', function() {
      document.querySelectorAll('.trade-type-btn').forEach(btn => {
        btn.style.borderColor  = 'var(--border)';
        btn.style.background   = '';
        const icon = btn.querySelector('i');
        if (icon) icon.style.color = 'var(--text-muted)';
      });
      const btn = this.nextElementSibling;
      if (btn) {
        btn.style.borderColor = 'var(--primary)';
        btn.style.background  = 'rgba(26,107,74,.05)';
        const icon = btn.querySelector('i');
        if (icon) icon.style.color = 'var(--primary)';
      }
    });
  });

  // Trigger for pre-selected
  const checked = document.querySelector('.want-radio:checked');
  if (checked) checked.dispatchEvent(new Event('change'));
}

/* ── Image gallery (listing view) ───────────────────────────────────────── */
function initImageGallery() {
  const mainImg = document.getElementById('main-img');
  const thumbs  = document.querySelectorAll('.thumb-img');
  const gallery = mainImg?.closest('.listing-gallery__main');

  if (mainImg && gallery) {
    const hideSkeleton = () => gallery.classList.remove('is-loading');
    if (mainImg.complete && mainImg.naturalWidth > 0) {
      hideSkeleton();
    } else {
      mainImg.addEventListener('load', hideSkeleton, { once: true });
      mainImg.addEventListener('error', hideSkeleton, { once: true });
    }
  }

  if (!mainImg || !thumbs.length) return;

  thumbs.forEach(thumb => {
    thumb.addEventListener('click', function() {
      if (gallery) gallery.classList.add('is-loading');
      mainImg.addEventListener('load', () => gallery?.classList.remove('is-loading'), { once: true });
      mainImg.src = this.src;
      thumbs.forEach(t => t.style.outline = 'none');
      this.style.outline = '2.5px solid var(--primary)';
    });
  });
}

/* ── Upload zone (create listing) ───────────────────────────────────────── */
function initUploadZone() {
  const zone  = document.getElementById('upload-zone');
  const input = document.getElementById('images');
  const grid  = document.getElementById('preview-grid');
  const maxImages = parseInt(zone?.dataset.max || 8);

  if (!zone || !input || !grid) return;

  let files = [];

  zone.addEventListener('click', e => {
    if (e.target === zone || e.target.closest('.upload-zone') === zone) {
      input.click();
    }
  });

  input.addEventListener('change', () => addFiles(Array.from(input.files)));

  zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('dragging'); });
  zone.addEventListener('dragleave', () => zone.classList.remove('dragging'));
  zone.addEventListener('drop', e => {
    e.preventDefault();
    zone.classList.remove('dragging');
    addFiles(Array.from(e.dataTransfer.files));
  });

  function addFiles(newFiles) {
    newFiles.forEach(f => {
      if (files.filter(Boolean).length >= maxImages) return;
      if (!f.type.match('image.*')) return;
      const idx = files.length;
      files.push(f);
      const reader = new FileReader();
      reader.onload = ev => renderPreview(ev.target.result, idx);
      reader.readAsDataURL(f);
    });
    syncInput();
  }

  function renderPreview(src, idx) {
    const wrap = document.createElement('div');
    wrap.className = 'preview-img-wrap';
    wrap.id = 'prev-' + idx;
    const isPrimary = files.filter(Boolean).indexOf(files[idx]) === 0;
    if (isPrimary) wrap.style.outline = '2.5px solid var(--primary)';
    wrap.innerHTML = `<img src="${src}" alt=""><button type="button" class="preview-img-remove" aria-label="Remove"><i class="bi bi-x"></i></button>`;
    wrap.querySelector('button').addEventListener('click', () => removeImg(idx));
    grid.appendChild(wrap);
  }

  function removeImg(idx) {
    files[idx] = null;
    document.getElementById('prev-' + idx)?.remove();
    syncInput();
  }

  function syncInput() {
    const dt = new DataTransfer();
    files.filter(Boolean).forEach(f => dt.items.add(f));
    input.files = dt.files;
  }
}

/* ── Quick amount buttons (wallet) ─────────────────────────────────────── */
function initQuickAmounts() {
  document.querySelectorAll('[data-quick-amount]').forEach(btn => {
    btn.addEventListener('click', function() {
      const targetName = this.dataset.quickAmount;
      const input = document.querySelector(`[name="${targetName}"]`);
      if (input) input.value = this.dataset.amount;
    });
  });
}

/* ── Password strength meter ────────────────────────────────────────────── */
function initPasswordStrength() {
  const passInput = document.getElementById('password');
  const strengthEl = document.getElementById('pass-strength');
  if (!passInput || !strengthEl) return;

  passInput.addEventListener('input', function() {
    const v = this.value;
    let score = 0;
    if (v.length >= 8) score++;
    if (/[A-Z]/.test(v)) score++;
    if (/[0-9]/.test(v)) score++;
    if (/[^A-Za-z0-9]/.test(v)) score++;

    const labels = [
      '',
      '<span style="color:var(--danger)">ضعیف</span>',
      '<span style="color:var(--warning)">متوسط</span>',
      '<span style="color:var(--info)">خوب</span>',
      '<span style="color:var(--success)">قوی ✓</span>',
    ];
    strengthEl.innerHTML = v ? 'قدرت رمز: ' + (labels[score] || '') : '';
  });
}

/* ── Login tab switcher ─────────────────────────────────────────────────── */
function switchLoginTab(t) {
  const emailPanel = document.getElementById('tab-email');
  const otpPanel   = document.getElementById('tab-otp');
  const btns       = document.querySelectorAll('.tab-btn');

  if (emailPanel) emailPanel.classList.toggle('active', t === 'email');
  if (otpPanel)   otpPanel.classList.toggle('active', t !== 'email');
  btns.forEach((b, i) => b.classList.toggle('active', i === (t === 'email' ? 0 : 1)));
}

/* ── Copy to clipboard ──────────────────────────────────────────────────── */
async function copyToClipboard(text, successMsg = 'کپی شد!') {
  try {
    await navigator.clipboard.writeText(text);
    showToast(successMsg, 'success');
  } catch {
    showToast('امکان کپی وجود ندارد.', 'error');
  }
}

/* ── Share button helper ────────────────────────────────────────────────── */
function shareOrCopy(title, url) {
  if (navigator.share) {
    navigator.share({ title, url }).catch(() => {});
  } else {
    copyToClipboard(url, 'لینک کپی شد!');
  }
}

/* ── Confirm forms ──────────────────────────────────────────────────────── */
function initConfirmForms() {
  document.querySelectorAll('form[data-confirm]').forEach(form => {
    form.addEventListener('submit', function(e) {
      const msg = this.dataset.confirm;
      if (!window.confirm(msg)) e.preventDefault();
    });
  });
}

/* ── Main init ──────────────────────────────────────────────────────────── */
/* ── Mobile nav drawer ─────────────────────────────────────────────────── */
function initMobileNav() {
  const hamburger = document.getElementById('nav-hamburger');
  const closeBtn  = document.getElementById('nav-hamburger-close');
  const drawer    = document.getElementById('mobile-drawer');
  const overlay   = document.getElementById('mobile-nav-overlay');
  if (!hamburger || !drawer || !overlay) return;

  const open  = () => {
    drawer.classList.add('is-open');
    overlay.classList.add('is-open');
    document.body.style.overflow = 'hidden';
  };
  const close = () => {
    drawer.classList.remove('is-open');
    overlay.classList.remove('is-open');
    document.body.style.overflow = '';
  };

  hamburger.addEventListener('click', open);
  if (closeBtn) closeBtn.addEventListener('click', close);
  overlay.addEventListener('click', close);
  drawer.querySelectorAll('a').forEach(a => a.addEventListener('click', close));

  const searchLink = document.getElementById('mobile-search-link');
  if (searchLink) {
    searchLink.addEventListener('click', e => {
      e.preventDefault();
      openModal('search-modal');
      const searchModalInput = document.getElementById('search-modal-input');
      if (searchModalInput) {
        searchModalInput.focus();
      }
    });
  }
}

/* ── Notification modal ─────────────────────────────────────────────────── */
function initNotifModal() {
  const bell    = document.getElementById('notif-bell-btn');
  const modal   = document.getElementById('notif-modal');
  const closeBtn= document.getElementById('notif-modal-close');
  const listEl  = document.getElementById('notif-list');
  const loadEl  = document.getElementById('notif-loading');
  if (!bell || !modal) return;

  const appUrl = getAppUrl();

  function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
  }

  async function loadNotifications() {
    if (!listEl || !loadEl) return;
    loadEl.innerHTML = skeletonNotifItemsHtml(4);
    loadEl.style.display = 'block';
    loadEl.setAttribute('aria-busy', 'true');
    listEl.style.display = 'none';
    listEl.innerHTML = '';

    try {
      const res  = await fetch(appUrl + '/api/notifications.php');
      const data = await res.json();
      loadEl.style.display = 'none';
      loadEl.setAttribute('aria-busy', 'false');
      listEl.style.display = 'block';

      if (!data.ok || !data.items?.length) {
        listEl.innerHTML = '<div class="notif-empty"><i class="bi bi-bell-slash"></i><p>اعلان جدیدی ندارید.</p></div>';
        return;
      }

      listEl.innerHTML = data.items.map(item => `
        <a href="${escHtml(item.url)}" class="notif-item notif-item--${escHtml(item.type)}">
          <span class="notif-item__icon"><i class="bi ${escHtml(item.icon)}"></i></span>
          <span class="notif-item__body">
            <strong>${escHtml(item.title)}</strong>
            <span>${escHtml(item.body)}</span>
          </span>
          <span class="notif-item__time">${escHtml(item.time_ago)}</span>
        </a>
      `).join('');
    } catch {
      loadEl.style.display = 'none';
      listEl.style.display = 'block';
      listEl.innerHTML = '<div class="notif-empty"><p>خطا در بارگذاری اعلان‌ها.</p></div>';
    }
  }

  bell.addEventListener('click', () => {
    openModal('notif-modal');
    loadNotifications();
  });

  closeBtn?.addEventListener('click', () => closeModal('notif-modal'));
}

/* ── Search modal ─────────────────────────────────────────────────── */
function initSearchModal() {
  const searchModalClose = document.getElementById('search-modal-close');
  const searchModalForm = document.getElementById('search-modal-form');
  
  searchModalClose?.addEventListener('click', () => closeModal('search-modal'));
  
  if (searchModalForm) {
    searchModalForm.addEventListener('submit', (e) => {
      // Let the form submit normally
    });
  }
}

/* ── AI Chat ─────────────────────────────────────────────────────────────── */
function initAiChat() {
  const app     = document.getElementById('ai-chat-app');
  const form    = document.getElementById('ai-chat-form');
  const input   = document.getElementById('ai-chat-input');
  const messages= document.getElementById('ai-chat-messages');
  if (!app || !form || !input || !messages) return;

  const appUrl  = getAppUrl();
  const history = [];
  let sending   = false;

  function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
  }

  function appendMsg(text, role) {
    const wrap = document.createElement('div');
    wrap.className = 'ai-msg ai-msg--' + (role === 'user' ? 'user' : 'bot');
    const safe = escHtml(text).replace(/\n/g, '<br>');
    wrap.innerHTML = role === 'bot'
      ? `<div class="ai-msg__avatar"><i class="bi bi-robot"></i></div><div class="ai-msg__bubble">${safe}</div>`
      : `<div class="ai-msg__bubble">${safe}</div>`;
    messages.appendChild(wrap);
    messages.scrollTop = messages.scrollHeight;
  }

  function appendTyping() {
    const wrap = document.createElement('div');
    wrap.className = 'ai-msg ai-msg--bot ai-msg--typing';
    wrap.id = 'ai-typing-indicator';
    wrap.innerHTML = '<div class="ai-msg__avatar"><i class="bi bi-robot"></i></div><div class="ai-msg__bubble"><span class="ai-typing-dots"><span></span><span></span><span></span></span></div>';
    messages.appendChild(wrap);
    messages.scrollTop = messages.scrollHeight;
  }

  function removeTyping() {
    document.getElementById('ai-typing-indicator')?.remove();
  }

  async function send(text) {
    const msg = text.trim();
    if (!msg || sending) return;
    sending = true;
    input.disabled = true;

    appendMsg(msg, 'user');
    history.push({ role: 'user', content: msg });
    input.value = '';
    appendTyping();

    try {
      const fd = new FormData();
      fd.append('message', msg);
      fd.append('history', JSON.stringify(history.slice(0, -1)));
      appendCsrf(fd);

      const res  = await fetch(appUrl + '/api/ai_chat.php', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: withCsrfHeaders(),
      });
      const data = await res.json();
      removeTyping();

      if (!data.ok || !data.message) {
        if (data.error === 'rate_limited') {
          throw new Error(data.message || 'سقف پیام‌های AI پر شده. کمی بعد دوباره تلاش کنید.');
        }
        throw new Error(data.error || 'خطا');
      }

      appendMsg(data.message, 'bot');
      history.push({ role: 'assistant', content: data.message });
      if (history.length > 20) history.splice(0, history.length - 20);
    } catch (err) {
      removeTyping();
      appendMsg(err.message || 'متأسفانه پاسخ AI دریافت نشد. لطفاً دوباره تلاش کنید.', 'bot');
    }

    sending = false;
    input.disabled = false;
    input.focus();
  }

  form.addEventListener('submit', e => {
    e.preventDefault();
    send(input.value);
  });

  document.querySelectorAll('.ai-chip').forEach(chip => {
    chip.addEventListener('click', () => send(chip.dataset.prompt || chip.textContent));
  });
}

/* ── AI Matching Engine (dashboard) ─────────────────────────────────────── */
function initAiMatch() {
  const hub     = document.getElementById('swap-matches');
  const listEl  = document.getElementById('ai-match-list');
  const loadEl  = document.getElementById('ai-match-loading');
  const refresh = document.getElementById('ai-match-refresh');
  const select  = document.getElementById('ai-match-listing');
  if (!hub || !listEl) return;

  const appUrl = getAppUrl();

  function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
  }

  function renderMatches(matches, source) {
    if (!matches.length) {
      listEl.innerHTML = `
        <div class="empty-state" style="padding:var(--sp-6) 0">
          <i class="bi bi-search"></i>
          <p class="fs-sm" style="color:var(--text-muted)">تطابق AI پیدا نشد. «نیازمند» را دقیق‌تر بنویسید.</p>
        </div>`;
      return;
    }

    listEl.innerHTML = matches.map(m => {
      const badges = [
        (source === 'assistant') ? '<span class="badge badge-gold fs-xs">هوشمند</span>' : '',
        m.mutual ? '<span class="badge badge-gold fs-xs">دوطرفه</span>' : '',
        m.trade_type === 'credit' ? '<span class="badge badge-primary fs-xs">اعتباری</span>' : '',
      ].filter(Boolean).join(' ');

      return `
        <a href="${escHtml(m.url)}" class="match-row" data-listing-id="${escHtml(String(m.listing_id))}">
          <div class="match-row__score">${escHtml(String(m.match_score))}٪</div>
          <div class="match-row__body">
            <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
              <span style="font-weight:700">${escHtml(m.title)}</span>
              ${badges}
            </div>
            <div class="fs-xs" style="color:var(--text-muted)">
              ${escHtml(m.seller_name)} · برای: ${escHtml((m.match_title || '').slice(0, 30))}${(m.match_title || '').length > 30 ? '…' : ''}
            </div>
            ${m.reason ? `<p class="match-row__reason fs-xs">${escHtml(m.reason)}</p>` : ''}
          </div>
          <i class="bi bi-chevron-left" style="color:var(--text-muted)"></i>
        </a>`;
    }).join('');

    const badge = hub.querySelector('.match-hub__title .badge-gold');
    if (source === 'assistant' && !badge) {
      const h2 = hub.querySelector('.match-hub__title h2');
      if (h2) h2.insertAdjacentHTML('beforeend', ' <span class="badge badge-gold fs-xs">هوشمند</span>');
    }
  }

  async function loadMatches(forceRefresh = false) {
    if (loadEl) {
      loadEl.hidden = false;
      loadEl.innerHTML = skeletonMatchRowsHtml(3);
      loadEl.setAttribute('aria-busy', 'true');
    }
    if (refresh) refresh.disabled = true;
    listEl.style.visibility = 'hidden';
    listEl.setAttribute('aria-busy', 'true');

    const params = new URLSearchParams();
    if (select?.value) params.set('listing_id', select.value);
    if (forceRefresh) params.set('refresh', '1');

    try {
      const res  = await fetch(`${appUrl}/api/ai_match.php?${params}`, {
        credentials: 'same-origin',
        headers: forceRefresh ? withCsrfHeaders() : {},
      });
      const data = await res.json();
      if (!data.ok) {
        if (data.error === 'rate_limited') {
          throw new Error(data.message || 'سقف بروزرسانی AI پر شده. کمی بعد دوباره تلاش کنید.');
        }
        throw new Error(data.error || 'خطا');
      }
      renderMatches(data.matches || [], data.source || 'rules');
      if (forceRefresh && typeof showToast === 'function') {
        showToast('پیشنهادهای معاوضه با AI بروزرسانی شد.', 'success');
      }
    } catch (err) {
      if (typeof showToast === 'function') {
        showToast(err.message || 'خطا در بارگذاری تطابق‌های AI.', 'error');
      }
    } finally {
      if (loadEl) {
        loadEl.hidden = true;
        loadEl.setAttribute('aria-busy', 'false');
      }
      listEl.style.visibility = '';
      listEl.setAttribute('aria-busy', 'false');
      if (refresh) refresh.disabled = false;
    }
  }

  refresh?.addEventListener('click', () => loadMatches(true));
  select?.addEventListener('change', () => loadMatches(false));
}

/* ── Support floating widget ───────────────────────────────────────────── */
function initSupportWidget() {
  const toggle = document.getElementById('support-widget-toggle');
  const menu   = document.getElementById('support-widget-menu');
  if (!toggle || !menu) return;

  toggle.addEventListener('click', (e) => {
    e.stopPropagation();
    const open = !menu.hidden;
    menu.hidden = open;
    toggle.setAttribute('aria-expanded', open ? 'false' : 'true');
    toggle.classList.toggle('is-open', !open);
  });

  document.addEventListener('click', (e) => {
    if (!document.getElementById('support-widget')?.contains(e.target)) {
      menu.hidden = true;
      toggle.setAttribute('aria-expanded', 'false');
      toggle.classList.remove('is-open');
    }
  });
}

document.addEventListener('DOMContentLoaded', () => {
  initDropdowns();
  initMobileNav();
  initNotifModal();
  initSearchModal();
  initAiChat();
  initAiMatch();
  initCharCounters();
  initAutoDismissAlerts();
  initSmoothAnchors();
  initNavbarScroll();
  initLazyImages();
  initGlobalSearch();
  initHomeFilters();
  initSaveButtons();
  initOfferForm();
  initLoadingForms();
  initStarPicker();
  initTradeTypePicker();
  initImageGallery();
  initUploadZone();
  initQuickAmounts();
  initPasswordStrength();
  initConfirmForms();
  initSupportWidget();

  // Restore active tab from URL
  const tabParam = new URLSearchParams(window.location.search).get('tab');
  if (tabParam && document.querySelector(`.tab-btn[data-tab="${tabParam}"]`)) {
    switchTab(tabParam);
  }

  // Show success toast if URL has ?toast param
  const toastMsg = new URLSearchParams(window.location.search).get('toast');
  if (toastMsg) {
    const safe = decodeURIComponent(toastMsg).replace(/<[^>]*>/g, '').slice(0, 200);
    if (safe) showToast(safe, 'success');
  }
});
