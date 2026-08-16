function initListingNearbySections() {
  document.querySelectorAll('.lv-nearby-section[data-listing-id]').forEach((section) => {
    if (section.dataset.initialized === '1') return;
    section.dataset.initialized = '1';
    new ListingNearbySection(section);
  });

  document.querySelectorAll('[data-swap-cta][data-listing-id]').forEach((section) => {
    if (section.dataset.initialized === '1') return;
    section.dataset.initialized = '1';
    new ListingSwapSuggestionsSection(section);
  });
}

const listingNearbyDataCache = new Map();
const listingSwapDataCache = new Map();

function freshnessLabelHtml(label, escapeFn) {
  if (!label || label === 'قدیمی') return '';
  return `<span class="lv-freshness-label">${escapeFn(label)}</span>`;
}

class ListingSwapSuggestionsSection {
  constructor(root) {
    this.root = root;
    this.listingId = root.dataset.listingId;
    this.appUrl = (document.querySelector('meta[name="app-url"]')?.content || '').replace(/\/$/, '');
    this.swapEl = root.querySelector('[data-swap-suggestions]');
    this.defaultRadius = 15;
    this.canFeedback = root.dataset.canFeedback === '1';
    this.canOffer = root.dataset.canOffer === '1';
    this.sourceTitle = root.dataset.sourceTitle || '';
    this.defaultOfferMessage = 'من این آگهی را دارم و علاقه‌مند به معاوضه با آگهی شما هستم.';
    this.csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    this.pendingOfferTargetId = null;
    this.negativeReasons = [
      { id: 'value_mismatch', label: 'ارزش نامناسب' },
      { id: 'wrong_category', label: 'دسته‌بندی اشتباه' },
      { id: 'wrong_item', label: 'مورد معاوضه اشتباه' },
      { id: 'distance_too_far', label: 'فاصله زیاد' },
      { id: 'listing_unavailable', label: 'آگهی دیگر موجود نیست' },
      { id: 'other', label: 'دلیل دیگر' },
    ];
    if (this.canFeedback && this.swapEl) {
      this.swapEl.addEventListener('click', (e) => this.onFeedbackClick(e));
    }
    if (this.canOffer) {
      this.ensureOfferModal();
      this.swapEl?.addEventListener('click', (e) => this.onOfferClick(e));
    }
    this.load();
  }

  async fetchJson(url) {
    const res = await fetch(url, { credentials: 'same-origin' });
    const data = await res.json();
    if (!res.ok || !data.ok) {
      throw new Error(data.error || 'request_failed');
    }
    return data;
  }

  async load() {
    if (!this.listingId || !this.appUrl || !this.swapEl) return;

    const cacheKey = `${this.listingId}:${this.defaultRadius}`;

    try {
      let swapData;
      if (listingSwapDataCache.has(cacheKey)) {
        swapData = listingSwapDataCache.get(cacheKey);
      } else {
        const swapUrl = `${this.appUrl}/api/listing_swap_suggestions.php?listing_id=${encodeURIComponent(this.listingId)}&radius_km=${encodeURIComponent(this.defaultRadius)}&limit=6`;
        swapData = await this.fetchJson(swapUrl).catch(() => ({ ok: true, suggestions: [], source: 'rules' }));
        listingSwapDataCache.set(cacheKey, swapData);
      }
      this.renderSuggestions(swapData.suggestions || [], swapData.source || 'rules', swapData.offer_context || null);
    } catch {
      this.swapEl.innerHTML = '<div class="lv-swap-cta__empty">در حال حاضر پیشنهاد معاوضه در دسترس نیست.</div>';
    }
  }

  renderSuggestions(items, source, offerContext) {
    if (!this.swapEl) return;

    if (offerContext?.default_message) {
      this.defaultOfferMessage = offerContext.default_message;
    }
    if (offerContext?.source_title) {
      this.sourceTitle = offerContext.source_title;
    }

    if (!items.length) {
      this.swapEl.innerHTML = `
        <div class="lv-swap-cta__empty">
          <i class="bi bi-search"></i>
          <p>فعلاً پیشنهاد معاوضه‌ای برای این آگهی در محدوده نزدیک یافت نشد.</p>
          <span>با گسترش جستجو در بخش «آگهی‌های اطراف» می‌توانید گزینه‌های بیشتری ببینید.</span>
        </div>`;
      return;
    }

    const sourceLabel = source === 'rules'
      ? 'پیشنهاد بر اساس قوانین سیستم'
      : 'پیشنهاد هوشمند سواَپین';

    this.swapEl.innerHTML = `
      <p class="lv-swap-cta__source">${this.escape(sourceLabel)}</p>
      <div class="lv-swap-cta__grid">
        ${items.map((item) => this.suggestionHtml(item)).join('')}
      </div>`;
  }

  suggestionHtml(item) {
    const thumbUrl = item.thumb ? `${this.appUrl}/uploads/${item.thumb}` : '';
    const thumb = thumbUrl
      ? `<img src="${this.escapeAttr(thumbUrl)}" alt="" class="lv-swap-card__thumb">`
      : '<div class="lv-swap-card__thumb lv-swap-card__thumb--empty"><i class="bi bi-image"></i></div>';

    const matchLabel = item.match_score_fmt || `${Math.round(item.match_score || 0)}٪ مناسب برای معاوضه`;
    const reasons = Array.isArray(item.reasons) && item.reasons.length
      ? item.reasons
      : (item.reason ? [item.reason] : []);

    const reasonsHtml = reasons.length
      ? `<ul class="lv-swap-card__reasons">${reasons.map((reason) => `<li><i class="bi bi-check2"></i> ${this.escape(reason)}</li>`).join('')}</ul>`
      : '';

    return `<article class="lv-swap-card">
      ${thumb}
      <div class="lv-swap-card__body">
        <div class="lv-swap-card__score">${this.escape(matchLabel)}</div>
        <h3 class="lv-swap-card__title">
          <a href="${this.escapeAttr(item.url || '#')}">${this.escape(item.title || '')}</a>
          ${item.mutual ? '<span class="lv-swap-card__badge">معاوضه دوطرفه</span>' : ''}
        </h3>
        <div class="lv-swap-card__distance"><i class="bi bi-geo-alt"></i> فاصله: <strong>${this.escape(item.distance_fmt || '')}</strong></div>
        ${item.reason ? `<p class="lv-swap-card__summary">${this.escape(item.reason)}</p>` : ''}
        ${reasonsHtml}
        <div class="lv-swap-card__meta">
          ${item.cat_name ? `<span>${this.escape(item.cat_name)}</span>` : ''}
          ${item.value_fmt ? `<span>${this.escape(item.value_fmt)}</span>` : ''}
          ${freshnessLabelHtml(item.freshness_label, (v) => this.escape(v))}
        </div>
        ${this.canFeedback ? this.feedbackHtml(item) : ''}
        ${this.offerActionHtml(item)}
        <a href="${this.escapeAttr(item.url || '#')}" class="lv-swap-card__link">مشاهده آگهی <i class="bi bi-chevron-left"></i></a>
      </div>
    </article>`;
  }

  offerActionHtml(item) {
    if (!this.canOffer) return '';

    const targetId = item.listing_id || 0;
    if (item.offer_status === 'pending') {
      const label = item.offer_status_label || 'پیشنهاد ارسال شد — در انتظار پاسخ';
      return `<div class="lv-swap-offer-status is-pending" data-swap-offer-status data-target-id="${targetId}"><i class="bi bi-clock-history"></i> ${this.escape(label)}</div>`;
    }

    if (!item.can_send_offer) return '';

    return `<button type="button" class="lv-swap-offer-btn" data-swap-offer data-target-id="${targetId}" data-target-title="${this.escapeAttr(item.title || '')}">پیشنهاد معاوضه</button>`;
  }

  ensureOfferModal() {
    if (document.querySelector('[data-swap-offer-modal]')) return;

    const modal = document.createElement('div');
    modal.className = 'lv-swap-offer-modal';
    modal.dataset.swapOfferModal = '';
    modal.hidden = true;
    modal.innerHTML = `
      <button type="button" class="lv-swap-offer-modal__backdrop" data-swap-offer-close aria-label="بستن"></button>
      <div class="lv-swap-offer-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="lv-swap-offer-title">
        <div class="lv-swap-offer-modal__head">
          <h3 id="lv-swap-offer-title" class="lv-swap-offer-modal__title">پیشنهاد معاوضه</h3>
          <button type="button" class="lv-swap-offer-modal__close" data-swap-offer-close aria-label="بستن"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="lv-swap-offer-modal__body">
          <div class="lv-swap-offer-modal__pair">
            <div><span class="lv-swap-offer-modal__label">آگهی شما:</span> <strong data-swap-offer-source-title></strong></div>
            <div><span class="lv-swap-offer-modal__label">آگهی موردنظر:</span> <strong data-swap-offer-target-title></strong></div>
          </div>
          <label class="lv-swap-offer-modal__field">
            <span class="lv-swap-offer-modal__label">پیام:</span>
            <textarea data-swap-offer-message rows="4" maxlength="500" class="form-control"></textarea>
          </label>
          <p class="lv-swap-offer-modal__error" data-swap-offer-error hidden></p>
        </div>
        <div class="lv-swap-offer-modal__actions">
          <button type="button" class="btn btn-outline" data-swap-offer-close>انصراف</button>
          <button type="button" class="btn btn-primary" data-swap-offer-submit>ارسال پیشنهاد</button>
        </div>
      </div>`;

    document.body.appendChild(modal);
    this.offerModal = modal;
    this.offerSourceTitleEl = modal.querySelector('[data-swap-offer-source-title]');
    this.offerTargetTitleEl = modal.querySelector('[data-swap-offer-target-title]');
    this.offerMessageEl = modal.querySelector('[data-swap-offer-message]');
    this.offerErrorEl = modal.querySelector('[data-swap-offer-error]');
    this.offerSubmitBtn = modal.querySelector('[data-swap-offer-submit]');

    modal.addEventListener('click', (e) => {
      if (e.target.closest('[data-swap-offer-close]')) {
        this.closeOfferModal();
      }
    });
    this.offerSubmitBtn?.addEventListener('click', () => this.submitOffer());
  }

  onOfferClick(e) {
    const btn = e.target.closest('[data-swap-offer]');
    if (!btn || btn.disabled) return;
    e.preventDefault();

    const targetId = btn.dataset.targetId;
    const targetTitle = btn.dataset.targetTitle || '';
    this.openOfferModal(targetId, targetTitle);
  }

  openOfferModal(targetId, targetTitle) {
    if (!this.offerModal) return;

    this.pendingOfferTargetId = targetId;
    if (this.offerSourceTitleEl) this.offerSourceTitleEl.textContent = this.sourceTitle;
    if (this.offerTargetTitleEl) this.offerTargetTitleEl.textContent = targetTitle;
    if (this.offerMessageEl) this.offerMessageEl.value = this.defaultOfferMessage;
    if (this.offerErrorEl) {
      this.offerErrorEl.hidden = true;
      this.offerErrorEl.textContent = '';
    }
    if (this.offerSubmitBtn) this.offerSubmitBtn.disabled = false;

    this.offerModal.hidden = false;
    document.body.classList.add('lv-swap-offer-open');
    this.offerMessageEl?.focus();
  }

  closeOfferModal() {
    if (!this.offerModal) return;
    this.offerModal.hidden = true;
    document.body.classList.remove('lv-swap-offer-open');
    this.pendingOfferTargetId = null;
  }

  offerErrorMessage(code, data) {
    const map = {
      login_required: 'برای ارسال پیشنهاد وارد حساب شوید.',
      source_not_owned: 'فقط صاحب آگهی می‌تواند پیشنهاد ارسال کند.',
      cannot_offer_own_listing: 'نمی‌توانید برای آگهی خودتان پیشنهاد بدهید.',
      source_not_active: 'آگهی شما فعال نیست.',
      target_not_active: 'آگهی موردنظر دیگر فعال نیست.',
      duplicate_pending_offer: 'شما از قبل یک پیشنهاد در انتظار برای این آگهی دارید.',
      suggestion_context_not_found: 'لطفاً صفحه را تازه‌سازی کنید و دوباره تلاش کنید.',
      message_required: 'لطفاً پیام پیشنهاد را وارد کنید.',
      message_too_long: 'پیام پیشنهاد بیش از حد طولانی است.',
      kyc_blocked: data?.message || 'محدودیت احراز هویت برای این معامله.',
      csrf_invalid: 'نشست منقضی شده — صفحه را تازه‌سازی کنید.',
    };
    return map[code] || 'ارسال پیشنهاد ناموفق بود. دوباره تلاش کنید.';
  }

  markOfferSent(targetId) {
    const card = this.swapEl?.querySelector(`[data-swap-offer][data-target-id="${targetId}"]`)?.closest('.lv-swap-card');
    if (!card) return;

    const btn = card.querySelector('[data-swap-offer]');
    btn?.remove();

    const status = document.createElement('div');
    status.className = 'lv-swap-offer-status is-pending';
    status.dataset.swapOfferStatus = '';
    status.dataset.targetId = String(targetId);
    status.innerHTML = '<i class="bi bi-clock-history"></i> پیشنهاد ارسال شد — در انتظار پاسخ';

    const link = card.querySelector('.lv-swap-card__link');
    if (link) {
      link.before(status);
    } else {
      card.querySelector('.lv-swap-card__body')?.appendChild(status);
    }
  }

  async submitOffer() {
    if (!this.pendingOfferTargetId || !this.appUrl) return;

    const message = (this.offerMessageEl?.value || '').trim();
    if (!message) {
      if (this.offerErrorEl) {
        this.offerErrorEl.hidden = false;
        this.offerErrorEl.textContent = this.offerErrorMessage('message_required');
      }
      return;
    }

    if (this.offerSubmitBtn) this.offerSubmitBtn.disabled = true;
    if (this.offerErrorEl) this.offerErrorEl.hidden = true;

    try {
      const res = await fetch(`${this.appUrl}/api/listing_swap_offers.php`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          ...(this.csrfToken ? { 'X-CSRF-Token': this.csrfToken } : {}),
        },
        body: JSON.stringify({
          target_listing_id: Number(this.pendingOfferTargetId),
          message,
        }),
      });

      const data = await res.json().catch(() => ({}));

      if (!res.ok || !data.ok) {
        const apiErr = new Error(data.error || 'offer_failed');
        apiErr.payload = data;
        throw apiErr;
      }

      const targetId = this.pendingOfferTargetId;
      this.closeOfferModal();
      this.markOfferSent(targetId);

      const toast = document.createElement('div');
      toast.className = 'lv-swap-offer-toast';
      toast.textContent = data.message_user || 'پیشنهاد معاوضه ارسال شد.';
      document.body.appendChild(toast);
      requestAnimationFrame(() => toast.classList.add('is-visible'));
      setTimeout(() => {
        toast.classList.remove('is-visible');
        setTimeout(() => toast.remove(), 300);
      }, 3200);
    } catch (err) {
      if (this.offerErrorEl) {
        this.offerErrorEl.hidden = false;
        this.offerErrorEl.textContent = this.offerErrorMessage(err.message, err.payload || {});
      }
      if (this.offerSubmitBtn) this.offerSubmitBtn.disabled = false;
    }
  }

  feedbackHtml(item) {
    const id = item.listing_id || 0;
    const reasonButtons = this.negativeReasons.map((r) =>
      `<button type="button" class="lv-swap-feedback__reason" data-swap-feedback-reason="${this.escapeAttr(r.id)}" data-suggested-id="${id}" hidden>${this.escape(r.label)}</button>`
    ).join('');

    return `<div class="lv-swap-feedback" data-swap-feedback-wrap data-suggested-id="${id}">
      <span class="lv-swap-feedback__label">این پیشنهاد مناسب بود؟</span>
      <div class="lv-swap-feedback__actions">
        <button type="button" class="lv-swap-feedback__btn lv-swap-feedback__btn--up" data-swap-feedback="positive" data-suggested-id="${id}" aria-label="مناسب بود">مناسب بود 👍</button>
        <button type="button" class="lv-swap-feedback__btn lv-swap-feedback__btn--down" data-swap-feedback="negative" data-suggested-id="${id}" aria-label="مناسب نبود">مناسب نبود 👎</button>
      </div>
      <div class="lv-swap-feedback__reasons" data-swap-feedback-reasons hidden>${reasonButtons}</div>
      <p class="lv-swap-feedback__status" data-swap-feedback-status hidden></p>
    </div>`;
  }

  onFeedbackClick(e) {
    const reasonBtn = e.target.closest('[data-swap-feedback-reason]');
    if (reasonBtn && !reasonBtn.disabled) {
      const suggestedId = reasonBtn.dataset.suggestedId;
      const reason = reasonBtn.dataset.swapFeedbackReason;
      this.submitFeedback(suggestedId, 'negative', reason, reasonBtn.closest('[data-swap-feedback-wrap]'));
      return;
    }

    const btn = e.target.closest('[data-swap-feedback]');
    if (!btn || btn.disabled) return;

    const suggestedId = btn.dataset.suggestedId;
    const feedback = btn.dataset.swapFeedback;
    const wrap = btn.closest('[data-swap-feedback-wrap]');
    this.submitFeedback(suggestedId, feedback, null, wrap);
  }

  async submitFeedback(suggestedListingId, feedback, reason, wrap) {
    if (!suggestedListingId || !this.appUrl) return;

    const statusEl = wrap?.querySelector('[data-swap-feedback-status]');
    const actions = wrap?.querySelector('.lv-swap-feedback__actions');
    const reasonsEl = wrap?.querySelector('[data-swap-feedback-reasons]');

    const setBusy = (busy) => {
      wrap?.querySelectorAll('button').forEach((b) => { b.disabled = busy; });
    };

    setBusy(true);

    try {
      const body = {
        suggested_listing_id: Number(suggestedListingId),
        feedback,
      };
      if (reason) body.reason = reason;

      const res = await fetch(`${this.appUrl}/api/listing_swap_feedback.php`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          ...(this.csrfToken ? { 'X-CSRF-Token': this.csrfToken } : {}),
        },
        body: JSON.stringify(body),
      });

      const data = await res.json().catch(() => ({}));

      if (!res.ok || !data.ok) {
        throw new Error(data.error || 'feedback_failed');
      }

      if (feedback === 'positive') {
        if (actions) actions.hidden = true;
        if (reasonsEl) reasonsEl.hidden = true;
        if (statusEl) {
          statusEl.hidden = false;
          statusEl.textContent = 'ممنون — بازخورد شما ثبت شد.';
          statusEl.classList.add('is-positive');
        }
      } else {
        wrap?.querySelectorAll('[data-swap-feedback]').forEach((b) => { b.disabled = true; });
        if (reason) {
          if (reasonsEl) reasonsEl.hidden = true;
          if (statusEl) {
            statusEl.hidden = false;
            statusEl.textContent = 'بازخورد شما با دلیل ثبت شد.';
            statusEl.classList.add('is-negative');
          }
        } else {
          if (reasonsEl) {
            reasonsEl.hidden = false;
            reasonsEl.querySelectorAll('[data-swap-feedback-reason]').forEach((b) => {
              b.hidden = false;
              b.disabled = false;
            });
          }
          if (statusEl) {
            statusEl.hidden = false;
            statusEl.textContent = 'ثبت شد. در صورت تمایل دلیل را انتخاب کنید:';
          }
        }
      }
    } catch {
      if (statusEl) {
        statusEl.hidden = false;
        statusEl.textContent = 'ثبت بازخورد ناموفق بود. دوباره تلاش کنید.';
        statusEl.classList.add('is-error');
      }
      setBusy(false);
    }
  }

  escape(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  escapeAttr(value) {
    return this.escape(value).replace(/'/g, '&#39;');
  }
}

class ListingNearbySection {
  constructor(root) {
    this.root = root;
    this.listingId = root.dataset.listingId;
    this.appUrl = (document.querySelector('meta[name="app-url"]')?.content || '').replace(/\/$/, '');
    this.radiusSelect = root.querySelector('[data-nearby-radius]');
    this.sortButtons = root.querySelectorAll('[data-nearby-sort]');
    this.mapEl = root.querySelector('.lv-nearby-map');
    this.mapWrapEl = root.querySelector('.lv-nearby-map-wrap');
    this.panelEl = root.querySelector('[data-nearby-panel]');
    this.sheetEl = root.querySelector('[data-nearby-sheet]');
    this.sheetBodyEl = root.querySelector('[data-nearby-sheet-body]');
    this.listEl = root.querySelector('[data-nearby-list]');
    this.smartMsgEl = root.querySelector('[data-nearby-smart-msg]');
    this.loadingEl = root.querySelector('[data-nearby-loading]');
    this.emptyEl = root.querySelector('[data-nearby-empty]');
    this.map = null;
    this.markerById = new Map();
    this.sortMode = 'distance';
    this.nearbyListings = [];
    this.sourceListing = null;
    this.selectedId = null;
    this.mobileMq = window.matchMedia('(max-width: 767px)');

    this.radiusSelect?.addEventListener('change', () => this.load());
    this.sortButtons.forEach((btn) => {
      btn.addEventListener('click', () => this.setSort(btn.dataset.nearbySort || 'distance'));
    });
    root.querySelector('[data-nearby-sheet-close]')?.addEventListener('click', () => this.closeDetail());
    root.addEventListener('click', (e) => {
      if (e.target.closest('[data-nearby-detail-close]')) {
        e.preventDefault();
        this.closeDetail();
      }
    });
    this.listEl?.addEventListener('click', (e) => this.handleListClick(e));
    this.listEl?.addEventListener('keydown', (e) => this.handleListKeydown(e));
    this.load();
  }

  isMobile() {
    return this.mobileMq.matches;
  }

  itemKey(item) {
    if (!item) return null;
    if (item.is_current) return 'current';
    return String(item.listing_id || '');
  }

  getListingByKey(key) {
    if (key === 'current') return this.sourceListing;
    return this.nearbyListings.find((item) => String(item.listing_id) === String(key)) || null;
  }

  handleListClick(e) {
    const itemEl = e.target.closest('[data-nearby-item-id]');
    if (!itemEl) return;
    const key = itemEl.dataset.nearbyItemId;
    const item = this.getListingByKey(key);
    if (!item) return;

    if (e.target.closest('[data-nearby-item-link]')) {
      return;
    }

    e.preventDefault();
    this.selectListing(item);
  }

  handleListKeydown(e) {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    const itemEl = e.target.closest('[data-nearby-item-id]');
    if (!itemEl || e.target.closest('[data-nearby-item-link]')) return;
    e.preventDefault();
    const item = this.getListingByKey(itemEl.dataset.nearbyItemId);
    if (item) this.selectListing(item);
  }

  setSort(mode) {
    if (!['distance', 'relevant'].includes(mode) || mode === this.sortMode) return;
    this.sortMode = mode;
    this.sortButtons.forEach((btn) => {
      const active = btn.dataset.nearbySort === mode;
      btn.classList.toggle('is-active', active);
      btn.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    this.renderList(this.getSortedListings());
  }

  getSortedListings() {
    const items = [...this.nearbyListings];
    if (this.sortMode === 'relevant') {
      return items.sort((a, b) => {
        const scoreCmp = (b.nearby_score || 0) - (a.nearby_score || 0);
        if (scoreCmp !== 0) return scoreCmp;
        return (a.distance_km || 0) - (b.distance_km || 0);
      });
    }
    return items.sort((a, b) => {
      const distCmp = (a.distance_km || 0) - (b.distance_km || 0);
      if (distCmp !== 0) return distCmp;
      return (b.nearby_score || 0) - (a.nearby_score || 0);
    });
  }

  isSmartRadius() {
    return (this.radiusSelect?.value || '') === 'smart';
  }

  getRadius() {
    if (this.isSmartRadius()) return 15;
    return parseFloat(this.radiusSelect?.value || '15') || 15;
  }

  getCacheKey() {
    if (this.isSmartRadius()) {
      return `${this.listingId}:smart`;
    }
    return `${this.listingId}:${this.getRadius()}`;
  }

  buildNearbyUrl() {
    let url = `${this.appUrl}/api/listing_nearby.php?listing_id=${encodeURIComponent(this.listingId)}&limit=24`;
    if (this.isSmartRadius()) {
      url += '&smart_radius=1';
    } else {
      url += `&radius_km=${encodeURIComponent(this.getRadius())}`;
    }
    return url;
  }

  renderSmartMessage(data) {
    if (!this.smartMsgEl) return;
    if (this.isSmartRadius() && data.message) {
      this.smartMsgEl.hidden = false;
      this.smartMsgEl.textContent = data.message;
    } else {
      this.smartMsgEl.hidden = true;
      this.smartMsgEl.textContent = '';
    }
  }

  setLoading(isLoading) {
    if (this.loadingEl) this.loadingEl.hidden = !isLoading;
  }

  async fetchJson(url) {
    const res = await fetch(url, { credentials: 'same-origin' });
    const data = await res.json();
    if (!res.ok || !data.ok) {
      throw new Error(data.error || 'request_failed');
    }
    return data;
  }

  async load() {
    if (!this.listingId || !this.appUrl) return;

    this.setLoading(true);
    if (this.emptyEl) this.emptyEl.hidden = true;
    this.closeDetail(false);

    const cacheKey = this.getCacheKey();

    try {
      let nearbyData;

      if (listingNearbyDataCache.has(cacheKey)) {
        nearbyData = listingNearbyDataCache.get(cacheKey);
      } else {
        nearbyData = await this.fetchJson(this.buildNearbyUrl());
        listingNearbyDataCache.set(cacheKey, nearbyData);
      }

      this.sourceListing = nearbyData.source || null;
      this.nearbyListings = nearbyData.listings || [];
      this.selectedId = null;
      this.renderSmartMessage(nearbyData);
      this.renderMap(nearbyData);
      this.renderList(this.getSortedListings());

      if (this.nearbyListings.length === 0 && this.emptyEl) {
        this.emptyEl.hidden = false;
        this.emptyEl.textContent = 'در این شعاع، آگهی فعال دیگری یافت نشد.';
      }
    } catch {
      if (this.mapEl) {
        this.mapEl.classList.add('is-empty');
        this.mapEl.textContent = 'بارگذاری نقشه ممکن نشد.';
      }
      if (this.emptyEl) {
        this.emptyEl.hidden = false;
        this.emptyEl.textContent = 'در حال حاضر آگهی اطراف در دسترس نیست.';
      }
    } finally {
      this.setLoading(false);
    }
  }

  markerIconType(item) {
    const key = this.itemKey(item);
    if (item.is_current) return 'current';
    if (this.selectedId === key) return 'selected';
    return 'default';
  }

  createMarkerIcon(type) {
    return L.divIcon({
      className: `lv-nearby-marker lv-nearby-marker--${type}`,
      html: '<span class="lv-nearby-marker__dot"></span>',
      iconSize: [32, 32],
      iconAnchor: [16, 32],
      popupAnchor: [0, -28],
    });
  }

  updateMarkerStates() {
    this.markerById.forEach(({ marker, item }) => {
      marker.setIcon(this.createMarkerIcon(this.markerIconType(item)));
    });
  }

  renderMap(data) {
    if (!this.mapEl || typeof L === 'undefined') return;

    const center = data.center || (this.sourceListing ? { lat: this.sourceListing.lat, lng: this.sourceListing.lng } : null);
    if (!center) return;

    this.mapEl.classList.remove('is-empty');
    this.mapEl.textContent = '';

    if (!this.map) {
      this.map = L.map(this.mapEl, { scrollWheelZoom: false }).setView([center.lat, center.lng], 13);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap',
      }).addTo(this.map);
    } else {
      this.map.setView([center.lat, center.lng], 13);
    }

    this.markerById.forEach(({ marker }) => marker.remove());
    this.markerById.clear();

    const allPoints = [];

    if (this.sourceListing) {
      allPoints.push([this.sourceListing.lat, this.sourceListing.lng]);
      this.addMapMarker(this.sourceListing);
    }

    this.nearbyListings.forEach((item) => {
      allPoints.push([item.lat, item.lng]);
      this.addMapMarker(item);
    });

    if (allPoints.length > 1) {
      this.map.fitBounds(allPoints, { padding: [28, 28], maxZoom: 14 });
    }

    setTimeout(() => this.map?.invalidateSize(), 150);
  }

  addMapMarker(item) {
    const key = this.itemKey(item);
    if (!key || !this.map) return;

    const marker = L.marker([item.lat, item.lng], {
      icon: this.createMarkerIcon(this.markerIconType(item)),
      zIndexOffset: item.is_current ? 1000 : 0,
    }).addTo(this.map);

    marker.on('click', () => this.selectListing(item));
    this.markerById.set(key, { marker, item });
  }

  selectListing(item) {
    if (!item) return;
    const key = this.itemKey(item);
    this.selectedId = key;
    this.updateMarkerStates();
    this.renderListHighlight();
    this.renderDetail(item);
    this.focusMapOn(item);
  }

  focusMapOn(item) {
    if (!this.map || !item) return;
    const targetZoom = Math.max(this.map.getZoom(), 14);
    this.map.flyTo([item.lat, item.lng], targetZoom, { duration: 0.45 });
  }

  closeDetail(updateMarkers = true) {
    this.selectedId = null;
    if (updateMarkers) this.updateMarkerStates();
    this.renderListHighlight();
    if (this.panelEl) {
      this.panelEl.hidden = true;
      this.panelEl.innerHTML = '';
    }
    if (this.sheetEl) {
      this.sheetEl.hidden = true;
      this.sheetEl.setAttribute('aria-hidden', 'true');
    }
    if (this.sheetBodyEl) this.sheetBodyEl.innerHTML = '';
    document.body.classList.remove('lv-nearby-sheet-open');
  }

  renderDetail(item) {
    const html = this.detailCardHtml(item);
    if (this.isMobile()) {
      if (this.panelEl) {
        this.panelEl.hidden = true;
        this.panelEl.innerHTML = '';
      }
      if (this.sheetEl && this.sheetBodyEl) {
        this.sheetBodyEl.innerHTML = html;
        this.sheetEl.hidden = false;
        this.sheetEl.setAttribute('aria-hidden', 'false');
        document.body.classList.add('lv-nearby-sheet-open');
      }
    } else if (this.panelEl) {
      this.panelEl.innerHTML = html;
      this.panelEl.hidden = false;
      if (this.sheetEl) {
        this.sheetEl.hidden = true;
        this.sheetEl.setAttribute('aria-hidden', 'true');
      }
      document.body.classList.remove('lv-nearby-sheet-open');
    }
  }

  detailCardHtml(item) {
    const isCurrent = !!item.is_current;
    const thumb = item.thumb_url
      ? `<img src="${this.escapeAttr(item.thumb_url)}" alt="" class="lv-nearby-detail__thumb">`
      : '<div class="lv-nearby-detail__thumb lv-nearby-detail__thumb--empty"><i class="bi bi-image"></i></div>';

    const scoreHtml = !isCurrent && item.nearby_score
      ? `<span class="lv-nearby-detail__score">${this.escape(item.nearby_score_fmt || `${Math.round(item.nearby_score)}٪ مرتبط`)}</span>`
      : '';

    const hintHtml = !isCurrent && item.relevance_hint
      ? `<p class="lv-nearby-detail__hint"><i class="bi bi-check2-circle"></i> ${this.escape(item.relevance_hint)}</p>`
      : '';

    const valueHtml = item.value_fmt
      ? `<div class="lv-nearby-detail__value">${this.escape(item.value_fmt)}</div>`
      : '';

    const location = item.location_fmt || [item.city, item.neighborhood].filter(Boolean).join(' — ');

    const closeBtn = `<button type="button" class="lv-nearby-detail__close" data-nearby-detail-close aria-label="بستن"><i class="bi bi-x-lg"></i></button>`;

    const actionHtml = isCurrent
      ? `<p class="lv-nearby-detail__current-label"><i class="bi bi-geo-alt-fill"></i> موقعیت این آگهی</p>`
      : `<a href="${this.escapeAttr(item.url || '#')}" class="lv-nearby-detail__btn">مشاهده آگهی <i class="bi bi-chevron-left"></i></a>`;

    return `<article class="lv-nearby-detail${isCurrent ? ' lv-nearby-detail--current' : ''}">
      ${closeBtn}
      <div class="lv-nearby-detail__media">${thumb}</div>
      <div class="lv-nearby-detail__body">
        ${scoreHtml}
        <h3 class="lv-nearby-detail__title">${this.escape(item.title || '')}</h3>
        <div class="lv-nearby-detail__meta">
          ${item.distance_fmt ? `<span><i class="bi bi-signpost-2"></i> ${this.escape(item.distance_fmt)}</span>` : ''}
          ${location ? `<span><i class="bi bi-geo-alt"></i> ${this.escape(location)}</span>` : ''}
          ${item.cat_name ? `<span><i class="bi bi-tag"></i> ${this.escape(item.cat_name)}</span>` : ''}
          ${freshnessLabelHtml(item.freshness_label, (v) => this.escape(v))}
        </div>
        ${valueHtml}
        ${hintHtml}
        ${actionHtml}
      </div>
    </article>`;
  }

  renderListHighlight() {
    if (!this.listEl) return;
    this.listEl.querySelectorAll('[data-nearby-item-id]').forEach((el) => {
      el.classList.toggle('is-active', el.dataset.nearbyItemId === this.selectedId);
    });
  }

  renderList(listings) {
    if (!this.listEl) return;
    if (!listings.length) {
      this.listEl.innerHTML = '';
      return;
    }

    this.listEl.innerHTML = listings.map((item) => {
      const key = this.itemKey(item);
      const thumb = item.thumb_url
        ? `<img src="${this.escapeAttr(item.thumb_url)}" alt="" class="lv-nearby-item__thumb">`
        : '<div class="lv-nearby-item__thumb lv-nearby-item__thumb--empty"><i class="bi bi-image"></i></div>';

      const scoreBadge = item.nearby_score
        ? `<span class="lv-nearby-item__score">${Math.round(item.nearby_score)}٪ مرتبط</span>`
        : '';

      const isActive = this.selectedId === key ? ' is-active' : '';

      return `<article class="lv-nearby-item${isActive}" data-nearby-item-id="${this.escapeAttr(key)}" tabindex="0" role="button" aria-pressed="${this.selectedId === key ? 'true' : 'false'}">
        ${thumb}
        <div class="lv-nearby-item__body">
          <p class="lv-nearby-item__title">${this.escape(item.title || '')}</p>
          <div class="lv-nearby-item__meta">
            <span class="lv-nearby-item__distance">${this.escape(item.distance_fmt || '')}</span>
            ${scoreBadge}
            ${freshnessLabelHtml(item.freshness_label, (v) => this.escape(v))}
            ${item.cat_name ? `<span>${this.escape(item.cat_name)}</span>` : ''}
            ${item.value_fmt ? `<span>${this.escape(item.value_fmt)}</span>` : ''}
          </div>
        </div>
        <a href="${this.escapeAttr(item.url || '#')}" class="lv-nearby-item__link" data-nearby-item-link aria-label="مشاهده آگهی"><i class="bi bi-chevron-left"></i></a>
      </article>`;
    }).join('');

    if (this.selectedId) {
      const activeEl = this.listEl.querySelector(`[data-nearby-item-id="${this.selectedId}"]`);
      activeEl?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
  }

  escape(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  escapeAttr(value) {
    return this.escape(value).replace(/'/g, '&#39;');
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initListingNearbySections);
} else {
  initListingNearbySections();
}
