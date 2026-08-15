function initListingLocationPicker(container) {
  if (!container || container.dataset.initialized === '1') return null;

  const appUrl = (document.querySelector('meta[name="app-url"]')?.content || '').replace(/\/$/, '');
  const citySelect = document.querySelector(container.dataset.citySelect || '');
  const latInput = document.querySelector(container.dataset.latInput || '');
  const lngInput = document.querySelector(container.dataset.lngInput || '');
  const neighborhoodSelect = document.querySelector(container.dataset.neighborhoodSelect || '');
  const neighborhoodInput = document.querySelector(container.dataset.neighborhoodInput || '');
  const neighborhoodHidden = document.querySelector(container.dataset.neighborhoodHidden || '');
  const mapEl = container.querySelector('.listing-location-picker__map');
  const coordsEl = container.querySelector('.listing-location-picker__coords');
  const mapWrap = container.querySelector('.listing-location-picker__map-wrap');

  if (!citySelect || !latInput || !lngInput || !mapEl) return null;

  container.dataset.initialized = '1';

  let map = null;
  let marker = null;
  let detectTimer = null;
  let neighborhoods = [];

  function setLoading(isLoading) {
    container.classList.toggle('is-loading', isLoading);
  }

  function updateCoordsLabel(lat, lng) {
    if (!coordsEl) return;
    coordsEl.textContent = lat && lng ? `Lat: ${Number(lat).toFixed(6)}, Lng: ${Number(lng).toFixed(6)}` : '';
  }

  function dispatchChange() {
    container.dispatchEvent(new CustomEvent('listing-location-change', { bubbles: true }));
  }

  function fillNeighborhoodOptions(items, selected) {
    if (neighborhoodSelect) {
      neighborhoodSelect.innerHTML = '<option value="">انتخاب محله</option>';
      items.forEach((name) => {
        const opt = document.createElement('option');
        opt.value = name;
        opt.textContent = name;
        if (name === selected) opt.selected = true;
        neighborhoodSelect.appendChild(opt);
      });
      neighborhoodSelect.disabled = items.length === 0;
      neighborhoodSelect.style.display = items.length ? '' : 'none';
    }

    if (neighborhoodInput) {
      neighborhoodInput.value = selected || neighborhoodInput.value || '';
      neighborhoodInput.style.display = items.length ? 'none' : '';
    }

    syncNeighborhoodHidden();
  }

  function syncNeighborhoodHidden() {
    const value = getNeighborhoodValue();
    if (neighborhoodHidden) neighborhoodHidden.value = value;
  }

  function getNeighborhoodValue() {
    if (neighborhoodSelect && neighborhoodSelect.style.display !== 'none') {
      return neighborhoodSelect.value.trim();
    }
    return neighborhoodInput ? neighborhoodInput.value.trim() : '';
  }

  function setNeighborhoodValue(value) {
    if (neighborhoodSelect && neighborhoodSelect.style.display !== 'none') {
      neighborhoodSelect.value = value || '';
    } else if (neighborhoodInput) {
      neighborhoodInput.value = value || '';
    }
    syncNeighborhoodHidden();
  }

  async function fetchJson(url) {
    const res = await fetch(url, { credentials: 'same-origin' });
    const data = await res.json();
    if (!res.ok || !data.ok) {
      throw new Error(data.error || 'request_failed');
    }
    return data;
  }

  async function detectNeighborhood(lat, lng, city) {
    const data = await fetchJson(
      `${appUrl}/api/listing_location.php?action=detect&city=${encodeURIComponent(city)}&lat=${encodeURIComponent(lat)}&lng=${encodeURIComponent(lng)}`
    );
    neighborhoods = data.neighborhoods || [];
    fillNeighborhoodOptions(neighborhoods, data.neighborhood || '');
    dispatchChange();
  }

  function scheduleDetect(lat, lng, city) {
    clearTimeout(detectTimer);
    detectTimer = setTimeout(() => {
      detectNeighborhood(lat, lng, city).catch(() => {});
    }, 250);
  }

  function setMarkerPosition(lat, lng, detect = true) {
    latInput.value = Number(lat).toFixed(7);
    lngInput.value = Number(lng).toFixed(7);
    updateCoordsLabel(lat, lng);

    if (!map) return;

    if (!marker) {
      marker = L.marker([lat, lng], { draggable: true }).addTo(map);
      marker.on('dragend', () => {
        const pos = marker.getLatLng();
        latInput.value = pos.lat.toFixed(7);
        lngInput.value = pos.lng.toFixed(7);
        updateCoordsLabel(pos.lat, pos.lng);
        scheduleDetect(pos.lat, pos.lng, citySelect.value);
      });
    } else {
      marker.setLatLng([lat, lng]);
    }

    map.setView([lat, lng], map.getZoom() || 13);

    if (detect && citySelect.value) {
      scheduleDetect(lat, lng, citySelect.value);
    } else {
      dispatchChange();
    }
  }

  function ensureMap(centerLat, centerLng, zoom = 13) {
    container.classList.remove('is-hidden');
    if (mapWrap) mapWrap.hidden = false;

    if (map) {
      setTimeout(() => map.invalidateSize(), 150);
      map.setView([centerLat, centerLng], zoom);
      setMarkerPosition(centerLat, centerLng, !latInput.value || !lngInput.value);
      return;
    }

    map = L.map(mapEl, { scrollWheelZoom: true }).setView([centerLat, centerLng], zoom);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; OpenStreetMap',
    }).addTo(map);

    map.on('click', (event) => {
      setMarkerPosition(event.latlng.lat, event.latlng.lng, true);
    });

    setMarkerPosition(centerLat, centerLng, !latInput.value || !lngInput.value);
    setTimeout(() => map.invalidateSize(), 150);
  }

  async function onCityChange() {
    const city = citySelect.value.trim();
    const hadCoords = !!(latInput.value && lngInput.value);
    if (!hadCoords) {
      latInput.value = '';
      lngInput.value = '';
      setNeighborhoodValue('');
      updateCoordsLabel('', '');
    }

    if (!city) {
      latInput.value = '';
      lngInput.value = '';
      setNeighborhoodValue('');
      updateCoordsLabel('', '');
      container.classList.add('is-hidden');
      if (mapWrap) mapWrap.hidden = true;
      dispatchChange();
      return;
    }

    setLoading(true);
    try {
      const data = await fetchJson(
        `${appUrl}/api/listing_location.php?action=city_center&city=${encodeURIComponent(city)}`
      );
      neighborhoods = data.neighborhoods || [];
      const zoom = data.approximate ? 7 : 13;

      if (hadCoords) {
        fillNeighborhoodOptions(neighborhoods, getNeighborhoodValue() || container.dataset.initialNeighborhood || '');
        ensureMap(parseFloat(latInput.value), parseFloat(lngInput.value), 14);
        setMarkerPosition(parseFloat(latInput.value), parseFloat(lngInput.value), false);
      } else {
        fillNeighborhoodOptions(neighborhoods, '');
        ensureMap(data.lat, data.lng, zoom);
      }
    } catch {
      container.classList.add('is-hidden');
      if (mapWrap) mapWrap.hidden = true;
    } finally {
      setLoading(false);
      dispatchChange();
    }
  }

  if (!latInput.value && container.dataset.initialLat) {
    latInput.value = container.dataset.initialLat;
  }
  if (!lngInput.value && container.dataset.initialLng) {
    lngInput.value = container.dataset.initialLng;
  }
  if (!neighborhoodHidden?.value && container.dataset.initialNeighborhood) {
    if (neighborhoodHidden) neighborhoodHidden.value = container.dataset.initialNeighborhood;
  }

  citySelect.addEventListener('change', () => {
    // User changed city deliberately — reset coords
    latInput.value = '';
    lngInput.value = '';
    container.dataset.initialLat = '';
    container.dataset.initialLng = '';
    container.dataset.initialNeighborhood = '';
    onCityChange();
  });
  neighborhoodSelect?.addEventListener('change', () => { syncNeighborhoodHidden(); dispatchChange(); });
  neighborhoodInput?.addEventListener('input', () => { syncNeighborhoodHidden(); dispatchChange(); });

  if (citySelect.value) {
    onCityChange();
  } else {
    container.classList.add('is-hidden');
    if (mapWrap) mapWrap.hidden = true;
  }

  if (typeof IntersectionObserver !== 'undefined') {
    const observer = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting && map) {
          setTimeout(() => map.invalidateSize(), 120);
        }
      });
    }, { threshold: 0.1 });
    observer.observe(container);
  }

  window.addEventListener('resize', () => {
    if (map) map.invalidateSize();
  });

  return {
    isComplete() {
      const city = citySelect.value.trim();
      const lat = latInput.value.trim();
      const lng = lngInput.value.trim();
      const neighborhood = getNeighborhoodValue();
      return !!(city && lat && lng && neighborhood);
    },
    getNeighborhoodValue,
  };
}

function initAllListingLocationPickers() {
  document.querySelectorAll('.listing-location-picker').forEach((container) => {
    initListingLocationPicker(container);
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initAllListingLocationPickers);
} else {
  initAllListingLocationPickers();
}
