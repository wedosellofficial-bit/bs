/**
 * Billions Store - interface behaviour.
 *
 * Vanilla, no build step, no framework, no CDN. Every feature here
 * degrades: the filter form submits normally without JS, the deposit page
 * shows a static status, and copy buttons fall back to selectable text.
 *
 * Deliberately no Alpine.js. It would be one more third-party script on
 * pages that display deposit addresses and payout addresses, and nothing
 * below needs a reactivity system.
 */
(function () {
  'use strict';

  const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ==================================================================
     Click to copy
     ================================================================== */

  async function copyText(text) {
    // navigator.clipboard needs a secure context. On plain http (local
    // development) it is undefined, so fall back to the old selection
    // trick rather than silently doing nothing.
    if (navigator.clipboard && window.isSecureContext) {
      try {
        await navigator.clipboard.writeText(text);
        return true;
      } catch {
        /* fall through */
      }
    }

    const scratch = document.createElement('textarea');
    scratch.value = text;
    scratch.setAttribute('readonly', '');
    scratch.style.position = 'fixed';
    scratch.style.opacity = '0';
    document.body.appendChild(scratch);
    scratch.select();

    let ok = false;
    try {
      ok = document.execCommand('copy');
    } catch {
      ok = false;
    }

    document.body.removeChild(scratch);
    return ok;
  }

  document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-copy]');
    if (!button) return;

    event.preventDefault();

    const value = button.getAttribute('data-copy');
    const copied = await copyText(value);
    if (!copied) return;

    // Announce to screen readers as well as showing the tick, otherwise
    // the only feedback is visual.
    announce('Copied to clipboard');

    const label = button.querySelector('span[aria-hidden="true"]');
    const original = label ? label.textContent : null;

    button.setAttribute('data-copied', 'true');
    if (label) label.textContent = 'Copied';

    window.setTimeout(() => {
      button.removeAttribute('data-copied');
      if (label && original !== null) label.textContent = original;
    }, 1400);
  });

  /** A polite live region, created once and reused. */
  let liveRegion = null;
  function announce(message) {
    if (!liveRegion) {
      liveRegion = document.createElement('div');
      liveRegion.setAttribute('role', 'status');
      liveRegion.setAttribute('aria-live', 'polite');
      liveRegion.className = 'sr-only-focusable';
      liveRegion.style.position = 'absolute';
      liveRegion.style.width = '1px';
      liveRegion.style.height = '1px';
      liveRegion.style.overflow = 'hidden';
      liveRegion.style.clip = 'rect(0,0,0,0)';
      document.body.appendChild(liveRegion);
    }
    liveRegion.textContent = message;
  }

  /* ==================================================================
     Dropdown menus (<details>)
     ================================================================== */

  document.addEventListener('click', (event) => {
    document.querySelectorAll('details[data-menu][open]').forEach((menu) => {
      if (!menu.contains(event.target)) menu.removeAttribute('open');
    });
  });

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    document.querySelectorAll('details[data-menu][open]').forEach((menu) => {
      menu.removeAttribute('open');
      const summary = menu.querySelector('summary');
      if (summary) summary.focus();
    });
  });

  /* ==================================================================
     Collection filters

     The form is the source of truth. On any change we serialise it,
     fetch the matching grid fragment, and push the same query string
     into the URL - so the address bar always describes what is on
     screen, and Back returns to the previous filter set rather than the
     previous page.
     ================================================================== */

  const filterForm = document.getElementById('filters');

  if (filterForm) {
    const results = document.getElementById('results');
    const countEl = document.querySelector('[data-result-count]');

    let inFlight = null;
    let debounceTimer = null;

    function currentQuery() {
      const data = new FormData(filterForm);
      const params = new URLSearchParams();

      // Repeated trait checkboxes are joined with commas so shared links
      // stay short and readable.
      const traits = data.getAll('traits[]').filter(Boolean);
      if (traits.length) params.set('traits', traits.join(','));

      for (const [key, value] of data.entries()) {
        if (key === 'traits[]' || value === '') continue;
        params.set(key, value);
      }

      // Drop defaults so an unfiltered view has a clean URL.
      if (params.get('status') === 'listed') params.delete('status');
      if (params.get('sort') === 'newest') params.delete('sort');

      // Only send a price bound if the slider was actually moved off its
      // extreme; otherwise every URL carries the full range for nothing.
      const priceFilter = document.querySelector('[data-price-filter]');
      if (priceFilter) {
        const boundMin = priceFilter.getAttribute('data-bound-min');
        const boundMax = priceFilter.getAttribute('data-bound-max');
        if (params.get('min_price') === boundMin) params.delete('min_price');
        if (params.get('max_price') === boundMax) params.delete('max_price');
      }

      return params;
    }

    async function applyFilters(pushState = true, extra = {}) {
      const params = currentQuery();
      for (const [key, value] of Object.entries(extra)) {
        if (value === null) params.delete(key);
        else params.set(key, value);
      }

      const query = params.toString();
      const url = '/collection' + (query ? '?' + query : '');

      if (inFlight) inFlight.abort();
      inFlight = new AbortController();

      results.setAttribute('data-loading', 'true');

      try {
        const response = await fetch('/collection/results' + (query ? '?' + query : ''), {
          headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
          signal: inFlight.signal,
          credentials: 'same-origin',
        });

        if (!response.ok) throw new Error('Request failed');

        const payload = await response.json();

        results.innerHTML = payload.html;
        if (countEl) countEl.textContent = payload.summary;

        if (pushState) {
          window.history.pushState({ filters: true }, '', url);
        }

        announce(payload.summary);
      } catch (error) {
        if (error.name === 'AbortError') return;

        // A failed fetch must not leave a stale grid that disagrees with
        // the controls. Fall back to a full navigation.
        window.location.href = url;
      } finally {
        results.removeAttribute('data-loading');
        inFlight = null;
      }
    }

    // Change events on any control marked data-filter-input.
    filterForm.addEventListener('change', (event) => {
      if (!event.target.matches('[data-filter-input]')) return;
      window.clearTimeout(debounceTimer);
      applyFilters();
    });

    // Typing in the search box and dragging the sliders debounce.
    filterForm.addEventListener('input', (event) => {
      const field = event.target;
      if (!field.matches('[data-filter-input][data-debounce]')) return;

      window.clearTimeout(debounceTimer);
      debounceTimer = window.setTimeout(
        () => applyFilters(),
        Number(field.getAttribute('data-debounce')) || 300
      );
    });

    // Never let the form do a native submit once JS is running.
    filterForm.addEventListener('submit', (event) => {
      event.preventDefault();
      window.clearTimeout(debounceTimer);
      applyFilters();
    });

    // Pagination links inside the fragment.
    filterForm.addEventListener('click', (event) => {
      const pageLink = event.target.closest('[data-page-link]');
      if (pageLink) {
        event.preventDefault();
        const page = new URL(pageLink.href, window.location.origin).searchParams.get('page');
        applyFilters(true, { page: page });
        results.scrollIntoView({ behavior: prefersReducedMotion ? 'auto' : 'smooth', block: 'start' });
        return;
      }

      // Active-filter chips.
      const chip = event.target.closest('[data-remove-trait]');
      if (chip) {
        event.preventDefault();
        const pair = chip.getAttribute('data-remove-trait');
        const box = filterForm.querySelector(`input[name="traits[]"][value="${CSS.escape(pair)}"]`);
        if (box) box.checked = false;
        chip.remove();
        applyFilters();
        return;
      }

      const reset = event.target.closest('[data-filter-reset]');
      if (reset) {
        event.preventDefault();
        filterForm.reset();
        filterForm.querySelectorAll('input[type="checkbox"]').forEach((box) => { box.checked = false; });
        const statusDefault = filterForm.querySelector('input[name="status"][value="listed"]');
        if (statusDefault) statusDefault.checked = true;
        resetPriceSlider();
        applyFilters();
      }
    });

    // Back/forward: re-render from the URL rather than leaving a grid
    // that no longer matches the address bar.
    window.addEventListener('popstate', () => {
      window.location.reload();
    });

    /* ---------------- dual price slider ---------------- */

    const priceFilter = document.querySelector('[data-price-filter]');

    function resetPriceSlider() {
      if (!priceFilter) return;
      const min = priceFilter.querySelector('[data-price-min]');
      const max = priceFilter.querySelector('[data-price-max]');
      if (min) min.value = min.min;
      if (max) max.value = max.max;
      paintPriceSlider();
    }

    function formatMoney(minor) {
      return '$' + (minor / 100).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
      });
    }

    function paintPriceSlider() {
      if (!priceFilter) return;

      const minInput = priceFilter.querySelector('[data-price-min]');
      const maxInput = priceFilter.querySelector('[data-price-max]');
      const fill = priceFilter.querySelector('[data-price-fill]');
      const output = priceFilter.querySelector('[data-price-output]');

      if (!minInput || !maxInput) return;

      const bound = { min: Number(minInput.min), max: Number(minInput.max) };
      let low = Number(minInput.value);
      let high = Number(maxInput.value);

      // Stop the thumbs crossing over. Whichever one is being dragged
      // pushes the other rather than passing through it.
      if (low > high) {
        if (document.activeElement === minInput) {
          high = low;
          maxInput.value = String(high);
        } else {
          low = high;
          minInput.value = String(low);
        }
      }

      const span = Math.max(1, bound.max - bound.min);
      const leftPct = ((low - bound.min) / span) * 100;
      const rightPct = ((high - bound.min) / span) * 100;

      if (fill) {
        fill.style.left = leftPct + '%';
        fill.style.width = Math.max(0, rightPct - leftPct) + '%';
      }

      if (output) output.textContent = formatMoney(low) + ' – ' + formatMoney(high);
    }

    if (priceFilter) {
      priceFilter.addEventListener('input', paintPriceSlider);
      paintPriceSlider();
    }

    /* -------- mobile: move the rail into the disclosure -------- */

    const mobileSlot = document.querySelector('[data-filter-panel-mobile]');
    const panel = document.querySelector('[data-filter-panel]');

    if (mobileSlot && panel) {
      const mobile = window.matchMedia('(max-width: 1023px)');

      const placePanel = () => {
        if (mobile.matches) {
          if (panel.parentElement !== mobileSlot) {
            // Moved, not cloned: two copies of the same named inputs in
            // one form would submit both sets.
            mobileSlot.appendChild(panel);
            panel.classList.remove('hidden', 'lg:block', 'card', 'p-5');
          }
        } else if (panel.parentElement === mobileSlot) {
          const aside = document.querySelector('#filters aside');
          if (aside) {
            aside.appendChild(panel);
            panel.classList.add('hidden', 'lg:block', 'card', 'p-5');
          }
        }
        paintPriceSlider();
      };

      placePanel();
      mobile.addEventListener('change', placePanel);
    }
  }

  /* ==================================================================
     Top-up amount: live BTC conversion
     ================================================================== */

  const amountInput = document.querySelector('[data-topup-amount]');

  if (amountInput) {
    const output = document.querySelector('[data-topup-conversion]');
    const rate = parseFloat(amountInput.getAttribute('data-rate') || '0');

    const update = () => {
      if (!output) return;

      const raw = amountInput.value.replace(/[^0-9.]/g, '');
      const amount = parseFloat(raw);

      if (!rate || !amount || Number.isNaN(amount)) {
        output.textContent = '';
        return;
      }

      // Indicative only, and labelled as such. The binding figure is the
      // one on the deposit page, quoted by the provider.
      output.textContent =
        '≈ ' + (amount / rate).toFixed(8) + ' BTC at the indicative rate. ' +
        'Your exact amount is fixed when you generate the address.';
    };

    amountInput.addEventListener('input', update);
    update();

    document.querySelectorAll('[data-topup-preset]').forEach((button) => {
      button.addEventListener('click', () => {
        amountInput.value = button.getAttribute('data-topup-preset');
        update();
        amountInput.focus();
      });
    });
  }

  /* ==================================================================
     QR rendering

     qrcode.min.js is bundled from npm and served from this origin, so
     the encoded address never leaves the browser. A third-party QR image
     endpoint would hand every deposit address to someone else's logs.
     ================================================================== */

  function renderQrCodes() {
    const canvases = document.querySelectorAll('canvas[data-qr]');
    if (!canvases.length) return;

    if (!window.QRCode) {
      // Script still loading (both are defer, order is not guaranteed on
      // every browser). Try again shortly.
      window.setTimeout(renderQrCodes, 60);
      return;
    }

    canvases.forEach((canvas) => {
      if (canvas.getAttribute('data-rendered') === 'true') return;

      window.QRCode.toCanvas(
        canvas,
        canvas.getAttribute('data-qr'),
        {
          width: 220,
          margin: 1,
          errorCorrectionLevel: 'M',
          color: { dark: '#08080aff', light: '#ffffffff' },
        },
        (error) => {
          if (error) {
            canvas.insertAdjacentHTML(
              'afterend',
              '<p class="mt-2 text-xs text-ink-950">QR could not be drawn. Copy the address below instead.</p>'
            );
            return;
          }
          canvas.setAttribute('data-rendered', 'true');
        }
      );
    });
  }

  renderQrCodes();

  /* ==================================================================
     Deposit status polling

     Read-only. This endpoint reports what the webhook has already
     recorded; it cannot credit anything. Polling backs off so a page
     left open overnight does not hammer the server.
     ================================================================== */

  const depositPanel = document.querySelector('[data-deposit-status]');

  if (depositPanel && depositPanel.getAttribute('data-final') !== 'true') {
    const depositId = depositPanel.getAttribute('data-deposit-id');

    let delay = 5000;
    const maxDelay = 60000;
    let stopped = false;

    async function poll() {
      if (stopped || document.hidden) {
        window.setTimeout(poll, delay);
        return;
      }

      try {
        const response = await fetch(`/account/wallet/deposit/${depositId}/status`, {
          headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
          credentials: 'same-origin',
        });

        if (!response.ok) throw new Error('poll failed');

        const data = await response.json();

        const badge = depositPanel.querySelector('[data-status-badge]');
        const confirmations = depositPanel.querySelector('[data-status-confirmations]');
        const balance = depositPanel.querySelector('[data-status-balance]');

        if (badge && badge.textContent.trim() !== data.label) {
          badge.textContent = data.label;
          badge.className = 'badge ' + (
            data.status === 'credited' ? 'badge-ok'
              : data.status === 'pending' ? 'badge-pending'
                : data.status === 'underpaid' ? 'badge-pending' : 'badge-muted'
          );
          announce('Deposit status: ' + data.label);
        }

        if (confirmations) {
          confirmations.textContent = data.status === 'credited'
            ? ''
            : `${data.confirmations} / ${data.required} confirmations`;
        }

        if (balance) balance.textContent = data.balance;

        if (data.txid_short) {
          const txWrap = depositPanel.querySelector('[data-status-tx]');
          if (txWrap && !txWrap.querySelector('[data-copy]')) {
            txWrap.innerHTML =
              '<span class="text-ink-500">Transaction </span>' +
              '<button type="button" class="ident ident-copy" data-copy="' + escapeAttr(data.txid) + '">' +
              '<span aria-hidden="true">' + escapeHtml(data.txid_short) + '</span></button>' +
              (data.explorer_url
                ? ' <a class="text-ember-500 text-xs hover:underline" target="_blank" rel="noopener noreferrer nofollow" href="' +
                  escapeAttr(data.explorer_url) + '">explorer</a>'
                : '');
          }
        }

        if (data.final) {
          stopped = true;
          depositPanel.setAttribute('data-final', 'true');

          if (data.status === 'credited') {
            // Reload so the header balance and the deposits table both
            // reflect the credit, rather than patching three places.
            window.setTimeout(() => window.location.reload(), 1200);
          }
          return;
        }

        // Steady state until something changes, then back off.
        delay = Math.min(maxDelay, Math.round(delay * 1.35));
      } catch {
        delay = Math.min(maxDelay, delay * 2);
      }

      window.setTimeout(poll, delay);
    }

    window.setTimeout(poll, delay);

    // Check immediately when the tab is brought back to the front.
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden && !stopped) {
        delay = 5000;
      }
    });
  }

  function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    return div.innerHTML;
  }

  function escapeAttr(value) {
    return escapeHtml(value).replace(/"/g, '&quot;');
  }

  /* ==================================================================
     Quote countdown
     ================================================================== */

  document.querySelectorAll('[data-countdown]').forEach((element) => {
    const expiresAt = Number(element.getAttribute('data-countdown')) * 1000;
    if (!expiresAt) return;

    const original = element.textContent;

    const tick = () => {
      const remaining = expiresAt - Date.now();

      if (remaining <= 0) {
        element.textContent = 'Expired';
        element.classList.add('text-rose-400');
        return;
      }

      const minutes = Math.floor(remaining / 60000);
      const seconds = Math.floor((remaining % 60000) / 1000);

      element.textContent =
        minutes >= 60
          ? original
          : `${minutes}m ${String(seconds).padStart(2, '0')}s left`;

      window.setTimeout(tick, 1000);
    };

    tick();
  });

  /* ==================================================================
     Guard against double-submitting a purchase
     ================================================================== */

  document.querySelectorAll('form[data-confirm-purchase]').forEach((form) => {
    form.addEventListener('submit', () => {
      const button = form.querySelector('button[type="submit"]');
      if (!button) return;

      // The server is already safe against a double submit (the item is
      // locked and orders.nft_id is unique). This is only to stop the
      // second click looking like it did nothing.
      window.setTimeout(() => {
        button.disabled = true;
        button.textContent = 'Processing…';
      }, 0);
    });
  });
})();
