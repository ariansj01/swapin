function initListingNearbySections() {
  document.querySelectorAll('.lv-nearby-section[data-listing-id]').forEach((section) => {
    if (section.dataset.initialized === '1') return;
    section.dataset.initialized = '1';
    new ListingNearbySection(section);
  });
}

const listingNearbyDataCache = new Map();

class ListingNearbySection {
  constructor(root) {
    this.root = root;
    this.listingId = root.dataset.listingId;
    this.appUrl = (document.querySelector('meta[name="app-url"]')?.content || '').replace(/\/$/, '');
    this.radiusSelect = root.querySelector('[data-nearby-radius]');
    this.mapEl = root.querySelector('.lv-nearby-map');
    this.listEl = root.querySelector('[data-nearby-list]');
    this.swapEl = root.querySelector('[data-swap-suggestions]');
    this.loadingEl = root.querySelector('[data-nearby-loading]');
    this.emptyEl = root.querySelector('[data-nearby-empty]');
    this.map = null;
    this.markers = [];

    this.radiusSelect?.addEventListener('change', () => this.load());
    this.load();
  }

  getRadius() {
    return parseFloat(this.radiusSelect?.value || '15') || 15;
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

    const radius = this.getRadius();
    const cacheKey = `${this.listingId}:${radius}`;

    try {
      let nearbyData;
      let swapData;

      if (listingNearbyDataCache.has(cacheKey)) {
        ({ nearbyData, swapData } = listingNearbyDataCache.get(cacheKey));
      } else {
        const nearbyUrl = `${this.appUrl}/api/listing_nearby.php?listing_id=${encodeURIComponent(this.listingId)}&radius_km=${encodeURIComponent(radius)}&limit=24`;
        const swapUrl = `${this.appUrl}/api/listing_swap_suggestions.php?listing_id=${encodeURIComponent(this.listingId)}&radius_km=${encodeURIComponent(radius)}&limit=6`;
        [nearbyData, swapData] = await Promise.all([
          this.fetchJson(nearbyUrl),
          this.fetchJson(swapUrl).catch(() => ({ ok: true, suggestions: [], source: 'rules' })),
        ]);
        listingNearbyDataCache.set(cacheKey, { nearbyData, swapData });
      }

      this.renderMap(nearbyData);
      this.renderList(nearbyData.listings || []);
      this.renderSuggestions(swapData.suggestions || [], swapData.source || 'rules');

      if ((nearbyData.listings || []).length === 0 && this.emptyEl) {
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

  renderMap(data) {
    if (!this.mapEl || typeof L === 'undefined') return;

    const source = data.source;
    const listings = data.listings || [];
    const center = data.center || (source ? { lat: source.lat, lng: source.lng } : null);
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

    this.markers.forEach((m) => m.remove());
    this.markers = [];

    const allPoints = [];
    if (source) {
      allPoints.push([source.lat, source.lng]);
      const marker = L.marker([source.lat, source.lng], { draggable: false }).addTo(this.map);
      marker.bindPopup(this.popupHtml(source, true));
      this.markers.push(marker);
    }

    listings.forEach((item) => {
      allPoints.push([item.lat, item.lng]);
      const marker = L.marker([item.lat, item.lng]).addTo(this.map);
      marker.bindPopup(this.popupHtml(item, false));
      this.markers.push(marker);
    });

    if (allPoints.length > 1) {
      this.map.fitBounds(allPoints, { padding: [28, 28], maxZoom: 14 });
    }

    setTimeout(() => this.map?.invalidateSize(), 150);
  }

  popupHtml(item, isCurrent) {
    const title = this.escape(item.title || '');
    const meta = [
      item.cat_name,
      item.distance_fmt,
      item.city,
    ].filter(Boolean).join(' · ');
    const link = isCurrent
      ? '<span style="color:#00aeef;font-weight:600">موقعیت این آگهی</span>'
      : `<a class="lv-nearby-marker-popup__link" href="${this.escapeAttr(item.url || '#')}">مشاهده آگهی</a>`;

    return `<div class="lv-nearby-marker-popup${isCurrent ? ' lv-nearby-marker-popup--current' : ''}">
      <p class="lv-nearby-marker-popup__title">${title}</p>
      <p class="lv-nearby-marker-popup__meta">${this.escape(meta)}</p>
      ${link}
    </div>`;
  }

  renderList(listings) {
    if (!this.listEl) return;
    if (!listings.length) {
      this.listEl.innerHTML = '';
      return;
    }

    this.listEl.innerHTML = listings.map((item) => {
      const thumb = item.thumb_url
        ? `<img src="${this.escapeAttr(item.thumb_url)}" alt="" class="lv-nearby-item__thumb">`
        : '<div class="lv-nearby-item__thumb lv-nearby-item__thumb--empty"><i class="bi bi-image"></i></div>';

      return `<a href="${this.escapeAttr(item.url)}" class="lv-nearby-item">
        ${thumb}
        <div class="lv-nearby-item__body">
          <p class="lv-nearby-item__title">${this.escape(item.title || '')}</p>
          <div class="lv-nearby-item__meta">
            <span class="lv-nearby-item__distance">${this.escape(item.distance_fmt || '')}</span>
            ${item.cat_name ? `<span>${this.escape(item.cat_name)}</span>` : ''}
            ${item.value_fmt ? `<span>${this.escape(item.value_fmt)}</span>` : ''}
          </div>
        </div>
      </a>`;
    }).join('');
  }

  renderSuggestions(items, source) {
    if (!this.swapEl) return;

    if (!items.length) {
      this.swapEl.innerHTML = '<div class="lv-nearby-empty">پیشنهاد معاوضه هوشمندی در این محدوده یافت نشد.</div>';
      return;
    }

    const sourceLabel = source === 'rules' ? 'پیشنهاد بر اساس قوانین سیستم' : 'پیشنهاد هوشمند سواَپین';

    this.swapEl.innerHTML = `
      <p class="lv-nearby-suggestions__subtitle">${this.escape(sourceLabel)}</p>
      ${items.map((item) => this.suggestionHtml(item)).join('')}
    `;
  }

  suggestionHtml(item) {
    const thumbUrl = item.thumb
      ? `${this.appUrl}/uploads/${item.thumb}`
      : '';
    const thumb = thumbUrl
      ? `<img src="${this.escapeAttr(thumbUrl)}" alt="" class="lv-nearby-item__thumb">`
      : '';

    return `<article class="lv-swap-suggestion">
      <div class="lv-swap-suggestion__score">${Math.round(item.match_score || 0)}%</div>
      <div class="lv-swap-suggestion__content">
        <div class="lv-swap-suggestion__headline">
          <a href="${this.escapeAttr(item.url || '#')}">${this.escape(item.title || '')}</a>
          ${item.mutual ? '<span class="badge badge-success">معاوضه دوطرفه</span>' : ''}
        </div>
        <p class="lv-swap-suggestion__reason">${this.escape(item.reason || '')}</p>
        <div class="lv-swap-suggestion__meta">
          <span class="lv-nearby-item__distance">${this.escape(item.distance_fmt || '')}</span>
          ${item.cat_name ? `<span>${this.escape(item.cat_name)}</span>` : ''}
          ${item.value_fmt ? `<span>${this.escape(item.value_fmt)}</span>` : ''}
        </div>
      </div>
      ${thumb}
    </article>`;
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
