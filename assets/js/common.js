/*
 * Tamás Bau – közös frontend segédek (admin központ, mobil PWA, aláírás, értékelés).
 * XSS-védelem: minden dinamikus tartalom textContent / attribútum alapon kerül a DOM-ba (TB.el).
 */
(function (global) {
  'use strict';

  const TB = {};
  let csrfToken = null;
  let currentUser = null;

  class ApiError extends Error {
    constructor(message, status, data) {
      super(message);
      this.status = status;
      this.data = data || {};
      this.kind = TB.errorKind(status);
    }
  }
  TB.ApiError = ApiError;

  TB.errorKind = function (status) {
    if (status === 0) return 'network';
    if (status === 401) return 'auth';
    if (status === 403) return 'forbidden';
    if (status === 404) return 'not_found';
    if (status === 409) return 'conflict';
    if (status === 422) return 'validation';
    if (status === 429) return 'rate_limit';
    if (status >= 500) return 'server';
    return 'error';
  };

  TB.errorMessage = function (err) {
    if (!(err instanceof ApiError)) return (err && err.message) || 'Ismeretlen hiba történt.';
    const prefix = {
      network: 'Nincs kapcsolat a szerverrel. ',
      auth: 'A munkamenet lejárt, jelentkezzen be újra. ',
      forbidden: 'Nincs jogosultsága. ',
      rate_limit: 'Túl sok kérés, próbálja később. ',
      server: 'Szerverhiba. ',
    }[err.kind] || '';
    return prefix + (err.message || '');
  };

  TB.session = async function (force) {
    if (!force && csrfToken !== null) return currentUser;
    const res = await fetch('/api/auth.php?action=me', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    const data = await res.json().catch(() => ({}));
    csrfToken = data.csrf_token || null;
    currentUser = data.user || null;
    TB.sessionInfo = data;
    return currentUser;
  };

  TB.user = function () {
    return currentUser;
  };

  TB.api = async function (url, options) {
    const opts = options || {};
    const method = (opts.method || 'GET').toUpperCase();
    if (method !== 'GET' && csrfToken === null) {
      await TB.session(true);
    }
    const headers = { Accept: 'application/json' };
    if (method !== 'GET') {
      headers['Content-Type'] = 'application/json';
      headers['X-CSRF-Token'] = csrfToken || '';
    }
    let res;
    try {
      res = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers,
        body: opts.body !== undefined ? JSON.stringify(opts.body) : undefined,
        signal: opts.signal,
      });
    } catch (e) {
      if (e && e.name === 'AbortError') throw e;
      throw new ApiError('Hálózati hiba.', 0);
    }
    const data = await res.json().catch(() => ({}));
    if (data && data.csrf_token) csrfToken = data.csrf_token;
    if (res.status === 403 && data && data.error === 'Érvénytelen CSRF token.' && !opts._retried) {
      await TB.session(true);
      return TB.api(url, Object.assign({}, opts, { _retried: true }));
    }
    if (!res.ok || data.ok === false) {
      throw new ApiError(data.error || ('HTTP ' + res.status), res.status, data);
    }
    return data;
  };

  TB.platform = function (module, params) {
    const qs = new URLSearchParams(Object.assign({ module }, params || {}));
    return TB.api('/api/platform.php?' + qs.toString());
  };

  TB.platformPost = function (module, body) {
    return TB.api('/api/platform.php?module=' + encodeURIComponent(module), { method: 'POST', body });
  };

  /* Biztonságos DOM építés: TB.el('div', {class: 'x', onclick: fn}, ['szöveg', childNode]) */
  TB.el = function (tag, attrs, children) {
    const node = document.createElement(tag);
    const a = attrs || {};
    Object.keys(a).forEach((key) => {
      const value = a[key];
      if (value === null || value === undefined || value === false) return;
      if (key.startsWith('on') && typeof value === 'function') {
        node.addEventListener(key.slice(2), value);
      } else if (key === 'class') {
        node.className = value;
      } else if (key === 'text') {
        node.textContent = String(value);
      } else if (key === 'dataset') {
        Object.assign(node.dataset, value);
      } else if (value === true) {
        node.setAttribute(key, '');
      } else {
        node.setAttribute(key, String(value));
      }
    });
    (Array.isArray(children) ? children : children !== undefined ? [children] : []).forEach((child) => {
      if (child === null || child === undefined || child === false) return;
      node.appendChild(child instanceof Node ? child : document.createTextNode(String(child)));
    });
    return node;
  };

  TB.clear = function (node) {
    while (node && node.firstChild) node.removeChild(node.firstChild);
    return node;
  };

  TB.$ = (sel, root) => (root || document).querySelector(sel);
  TB.$$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));

  TB.huf = function (cents) {
    if (cents === null || cents === undefined || cents === '') return '–';
    return new Intl.NumberFormat('hu-HU', { style: 'currency', currency: 'HUF', maximumFractionDigits: 0 }).format(Number(cents) / 100);
  };

  TB.date = function (value) {
    if (!value) return '–';
    const d = new Date(String(value).replace(' ', 'T'));
    return isNaN(d.getTime()) ? String(value) : d.toLocaleString('hu-HU', { dateStyle: 'short', timeStyle: 'short' });
  };

  TB.uuid = function () {
    if (global.crypto && typeof global.crypto.randomUUID === 'function') return global.crypto.randomUUID();
    const b = new Uint8Array(16);
    global.crypto.getRandomValues(b);
    return Array.from(b, (x) => x.toString(16).padStart(2, '0')).join('');
  };

  TB.statusLabels = {
    todo: 'Teendő', in_progress: 'Folyamatban', blocked: 'Akadályozott', done: 'Kész', cancelled: 'Visszavonva',
    draft: 'Tervezet', survey_scheduled: 'Felmérés ütemezve', quoted: 'Árajánlat kiadva', approved: 'Jóváhagyva', scheduled: 'Ütemezve',
    on_hold: 'Szünetel', completed: 'Befejezve', open: 'Nyitott', triaged: 'Osztályozva', waiting_customer: 'Ügyfélre vár',
    resolved: 'Megoldva', closed: 'Lezárva', rejected: 'Elutasítva', pending: 'Függőben', processing: 'Feldolgozás alatt', failed: 'Sikertelen',
    approved_review: 'Jóváhagyva', active: 'Érvényes', revoked: 'Visszavonva', invalidated: 'Érvénytelenítve', sent: 'Elküldve',
    sandbox: 'Sandbox', manual_required: 'Kézi kiállítás szükséges', manual_done: 'Kézzel kiállítva',
  };
  TB.label = (status) => TB.statusLabels[status] || status || '–';

  let toastRegion = null;
  TB.toast = function (message, type) {
    if (!toastRegion) {
      toastRegion = TB.el('div', { class: 'tb-toasts', role: 'status', 'aria-live': 'polite' });
      document.body.appendChild(toastRegion);
    }
    const item = TB.el('div', { class: 'tb-toast tb-toast-' + (type || 'info'), text: message });
    toastRegion.appendChild(item);
    setTimeout(() => item.remove(), type === 'error' ? 7000 : 4000);
  };

  TB.state = function (container, kind, message) {
    const icons = { loading: '⏳', error: '⚠️', empty: '📭' };
    TB.clear(container).appendChild(TB.el('p', { class: 'tb-state tb-state-' + kind, role: kind === 'error' ? 'alert' : null, text: (icons[kind] || '') + ' ' + message }));
  };

  /* Modális párbeszéd fókuszkezeléssel (Esc zár, fókusz visszaáll). */
  TB.modal = function (title, content, actions) {
    const previous = document.activeElement;
    const titleId = 'tb-modal-title-' + Math.random().toString(36).slice(2);
    const close = () => {
      overlay.remove();
      document.removeEventListener('keydown', onKey);
      if (previous && previous.focus) previous.focus();
    };
    const onKey = (e) => {
      if (e.key === 'Escape') close();
      if (e.key === 'Tab') {
        const focusables = TB.$$('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])', dialog).filter((n) => !n.disabled);
        if (!focusables.length) return;
        const first = focusables[0];
        const last = focusables[focusables.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      }
    };
    const footer = TB.el('div', { class: 'tb-modal-actions' });
    (actions || [{ label: 'Bezárás' }]).forEach((action) => {
      footer.appendChild(TB.el('button', {
        type: 'button',
        class: 'tb-btn ' + (action.primary ? 'tb-btn-primary' : ''),
        text: action.label,
        onclick: async () => {
          if (action.onClick) {
            const keep = await action.onClick(close);
            if (keep === true) return;
          }
          close();
        },
      }));
    });
    const dialog = TB.el('div', { class: 'tb-modal', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': titleId }, [
      TB.el('h2', { id: titleId, text: title }),
      content,
      footer,
    ]);
    const overlay = TB.el('div', { class: 'tb-overlay', onclick: (e) => { if (e.target === overlay) close(); } }, [dialog]);
    document.body.appendChild(overlay);
    document.addEventListener('keydown', onKey);
    const firstInput = TB.$('input, select, textarea, button', dialog);
    if (firstInput) firstInput.focus();
    return { close, dialog };
  };

  TB.downloadText = function (filename, text, mime) {
    const blob = new Blob([text], { type: mime || 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = TB.el('a', { href: url, download: filename });
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 2000);
  };

  TB.registerServiceWorker = function () {
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.register('/service-worker.js').catch(() => {});
    }
  };

  global.TB = TB;
})(window);
