/*
 * Munkatársi mobil PWA: munkalap lista/részletek, offline művelet-sor idempotency kulccsal,
 * szerver oldali konfliktusjelzés és manuális feloldás.
 */
(function () {
  'use strict';
  const { el, $, clear } = TB;

  const STATUS_ACTIONS = [
    { status: 'in_progress', label: '▶ Megkezdem' },
    { status: 'blocked', label: '⛔ Akadály' },
    { status: 'done', label: '✔ Kész' },
    { status: 'todo', label: '↺ Teendőbe' },
  ];
  const OP_LABELS = { status: 'Státuszváltás', checklist: 'Checklist', note: 'Megjegyzés', time_log: 'Munkaidő', material: 'Anyagfelhasználás' };
  const STATE_LABELS = { pending: 'Küldésre vár', failed: 'Sikertelen', conflict: 'Ütközés' };
  const MAX_BACKOFF_MS = 5 * 60 * 1000;

  let user = null;
  let prefix = 'tb.mobile.';
  let syncing = false;
  let authBlocked = false;
  let timerInterval = null;

  /* ---------- Tárolás ---------- */
  function load(key, fallback) {
    try {
      const raw = localStorage.getItem(prefix + key);
      return raw ? JSON.parse(raw) : fallback;
    } catch (e) {
      return fallback;
    }
  }
  function save(key, value) {
    try {
      localStorage.setItem(prefix + key, JSON.stringify(value));
    } catch (e) {
      TB.toast('A helyi tárhely megtelt, a művelet nem menthető offline.', 'error');
      throw e;
    }
  }
  const queue = () => load('queue', []);
  const saveQueue = (q) => { save('queue', q); updateIndicators(); };

  /* ---------- Állapotjelzők ---------- */
  function updateIndicators() {
    const q = queue();
    const net = $('#net');
    const online = navigator.onLine;
    net.classList.toggle('offline', !online);
    net.classList.toggle('syncing', online && syncing);
    net.textContent = !online ? 'Offline' : syncing ? 'Szinkronizálás…' : 'Online';
    const problems = q.filter((o) => o.state !== 'pending').length;
    $('#queue-count').textContent = String(q.length) + (problems ? ' (' + problems + ' !)' : '');
    $('#queue-btn').setAttribute('aria-label', 'Szinkronizálási sor: ' + q.length + ' függő művelet' + (problems ? ', ebből ' + problems + ' beavatkozást igényel' : ''));
  }

  function banner(message, actions) {
    const box = $('#banner');
    clear(box);
    if (!message) {
      box.classList.add('hidden');
      return;
    }
    box.appendChild(el('p', { text: message }));
    (actions || []).forEach((a) => box.appendChild(a));
    box.classList.remove('hidden');
  }

  /* ---------- Helyi (optimista) alkalmazás ---------- */
  function applyLocal(detail, op) {
    if (!detail || !detail.work_order) return detail;
    const pendingTag = ' (szinkronizálásra vár)';
    if (op.op_type === 'status') detail.work_order.status = op.data.status;
    if (op.op_type === 'checklist') {
      (detail.checklist || []).forEach((c) => { if (Number(c.id) === Number(op.data.item_id)) c.is_done = op.data.is_done ? 1 : 0; });
    }
    if (op.op_type === 'note') (detail.notes = detail.notes || []).unshift({ note: op.data.note, created_at: op.client_created_at, author_name: (user && user.name || '') + pendingTag, local: true });
    if (op.op_type === 'time_log') (detail.time_logs = detail.time_logs || []).unshift({ minutes: op.data.minutes, started_at: op.data.started_at, note: op.data.note, created_at: op.client_created_at, user_name: (user && user.name || '') + pendingTag, local: true });
    if (op.op_type === 'material') (detail.materials = detail.materials || []).unshift({ title: op.data.title + pendingTag, quantity: op.data.quantity, unit: op.data.unit, created_at: op.client_created_at, local: true });
    return detail;
  }

  function withPending(detail, woId) {
    queue().filter((o) => Number(o.work_order_id) === Number(woId) && o.state !== 'conflict').forEach((o) => applyLocal(detail, o));
    return detail;
  }

  function updateListCache(woId, fn) {
    const list = load('list', null);
    if (!list) return;
    list.work_orders.forEach((w) => { if (Number(w.id) === Number(woId)) fn(w); });
    save('list', list);
  }

  /* ---------- Sorba állítás ---------- */
  function enqueue(woId, opType, data, base) {
    const op = {
      idempotency_key: 'm-' + TB.uuid().replace(/[^A-Za-z0-9_-]/g, ''),
      work_order_id: Number(woId),
      op_type: opType,
      data,
      base: base || {},
      client_created_at: new Date().toISOString(),
      state: 'pending',
      attempts: 0,
      next_retry_at: 0,
    };
    const q = queue();
    q.push(op);
    saveQueue(q);
    if (opType === 'status') updateListCache(woId, (w) => { w.status = data.status; });
    if (opType === 'checklist') updateListCache(woId, (w) => { w.checklist_done = Math.max(0, Number(w.checklist_done || 0) + (data.is_done ? 1 : -1)); });
    TB.toast(navigator.onLine ? 'Mentve, küldés folyamatban…' : 'Offline mentve, kapcsolat esetén elküldjük.', 'info');
    sync();
    return op;
  }

  /* ---------- Szinkronizálás ---------- */
  function backoff(op, message, kind) {
    op.attempts = (op.attempts || 0) + 1;
    op.last_error = message;
    op.error_kind = kind;
    op.next_retry_at = Date.now() + Math.min(MAX_BACKOFF_MS, 5000 * Math.pow(2, Math.min(op.attempts, 6)));
  }

  async function sync(manual) {
    if (syncing || !user) return;
    if (!navigator.onLine) {
      if (manual) TB.toast('Nincs internetkapcsolat, a műveletek a sorban maradnak.', 'warn');
      updateIndicators();
      return;
    }
    if (authBlocked && !manual) return;
    const now = Date.now();
    const ready = queue().filter((o) => o.state === 'pending' && (manual || !o.next_retry_at || o.next_retry_at <= now)).slice(0, 50);
    if (!ready.length) {
      if (manual) TB.toast('Nincs küldésre váró művelet.', 'info');
      if (manual) refreshCurrent();
      return;
    }
    syncing = true;
    updateIndicators();
    let response = null;
    let failure = null;
    try {
      response = await TB.platformPost('mobile', {
        action: 'sync_batch',
        operations: ready.map((o) => ({ idempotency_key: o.idempotency_key, work_order_id: o.work_order_id, op_type: o.op_type, data: o.data, base: o.base, client_created_at: o.client_created_at })),
      });
    } catch (err) {
      failure = err;
    }
    const q = queue();
    const byKey = new Map(q.map((o) => [o.idempotency_key, o]));
    let applied = 0;
    let conflicts = 0;
    let failed = 0;
    if (failure) {
      if (failure.kind === 'auth') {
        authBlocked = true;
        banner('A munkamenet lejárt. A műveletek biztonságban vannak a készüléken; jelentkezzen be újra, majd nyomja meg a Szinkronizálás gombot.', [el('a', { class: 'tb-btn tb-btn-primary', href: '/', text: 'Bejelentkezés' })]);
      } else if (failure.kind === 'forbidden') {
        banner('Nincs jogosultsága a mobil munkalapokhoz. Kérjen hozzáférést az irodától.');
      } else {
        ready.forEach((o) => { const item = byKey.get(o.idempotency_key); if (item) backoff(item, TB.errorMessage(failure), failure.kind); });
        if (manual) TB.toast('A küldés nem sikerült: ' + TB.errorMessage(failure) + ' Később automatikusan újrapróbáljuk.', 'warn');
      }
    } else {
      authBlocked = false;
      banner(null);
      (response.results || []).forEach((r) => {
        const item = byKey.get(r.idempotency_key);
        if (!item) return;
        if (r.http === 200) {
          byKey.delete(r.idempotency_key);
          applied++;
        } else if (r.http === 409) {
          item.state = 'conflict';
          item.operation_id = r.operation_id;
          item.conflict = r.conflict || null;
          item.last_error = r.error;
          item.error_kind = 'conflict';
          conflicts++;
        } else if (r.http === 422 || r.http === 403 || r.http === 404) {
          item.state = 'failed';
          item.last_error = r.error;
          item.error_kind = { 422: 'validation', 403: 'forbidden', 404: 'not_found' }[r.http];
          failed++;
        } else {
          backoff(item, r.error || 'Szerverhiba', r.http === 429 ? 'rate_limit' : 'server');
        }
      });
    }
    syncing = false;
    saveQueue(Array.from(byKey.values()));
    if (applied) TB.toast(applied + ' művelet sikeresen szinkronizálva.', 'success');
    if (conflicts) TB.toast(conflicts + ' művelet ütközik a szerver adataival – döntés szükséges.', 'error');
    if (failed) TB.toast(failed + ' művelet nem küldhető el (hibás vagy már nem elérhető adat).', 'error');
    if (applied || conflicts || failed) refreshCurrent();
    if (!failure && queue().some((o) => o.state === 'pending' && o.next_retry_at <= Date.now())) setTimeout(() => sync(), 300);
  }

  /* ---------- Nézetek ---------- */
  function focusMain() {
    const h = $('#main h1');
    if (h) { h.setAttribute('tabindex', '-1'); h.focus(); }
  }

  async function fetchOrCache(cacheKey, loader) {
    if (navigator.onLine) {
      try {
        const data = await loader();
        save(cacheKey, Object.assign({ cached_at: new Date().toISOString() }, data));
        return { data, fromCache: false };
      } catch (err) {
        if (err.kind !== 'network' && err.kind !== 'server') throw err;
      }
    }
    const cached = load(cacheKey, null);
    if (!cached) throw new TB.ApiError('Offline vagyunk és nincs mentett adat ehhez a nézethez.', 0);
    return { data: cached, fromCache: true };
  }

  function cacheNotice(fromCache, data) {
    return fromCache ? el('p', { class: 'small muted', role: 'note', text: 'Offline adat (utolsó frissítés: ' + TB.date(data.cached_at) + ').' }) : null;
  }

  async function renderList() {
    const main = clear($('#main'));
    TB.state(main, 'loading', 'Munkalapok betöltése…');
    try {
      const { data, fromCache } = await fetchOrCache('list', () => TB.platform('mobile', { view: 'list' }));
      clear(main);
      main.appendChild(el('h1', { text: 'Saját munkalapjaim' }));
      const notice = cacheNotice(fromCache, data);
      if (notice) main.appendChild(notice);
      const filter = el('select', { id: 'wo-filter', 'aria-label': 'Szűrés státusz szerint' }, [
        el('option', { value: 'active', text: 'Aktív munkák' }),
        el('option', { value: 'all', text: 'Összes' }),
        el('option', { value: 'done', text: 'Kész' }),
      ]);
      filter.value = load('filter', 'active');
      main.appendChild(el('label', { for: 'wo-filter', class: 'sr-only', text: 'Szűrés' }));
      main.appendChild(filter);
      const listBox = el('div', { class: 'mt', role: 'list' });
      main.appendChild(listBox);
      const draw = () => {
        save('filter', filter.value);
        clear(listBox);
        const rows = (data.work_orders || []).filter((w) => filter.value === 'all' ? true : filter.value === 'done' ? ['done', 'cancelled'].includes(w.status) : !['done', 'cancelled'].includes(w.status));
        if (!rows.length) {
          TB.state(listBox, 'empty', 'Nincs megjeleníthető munkalap.');
          return;
        }
        const pendingIds = new Set(queue().map((o) => Number(o.work_order_id)));
        rows.forEach((w) => listBox.appendChild(el('a', { class: 'wo-card', role: 'listitem', href: '#/wo/' + w.id }, [
          el('div', { class: 'row' }, [
            el('strong', { text: w.title }),
            el('span', { class: 'spacer' }),
            el('span', { class: 'badge badge-' + w.status, text: TB.label(w.status) }),
          ]),
          el('div', { class: 'small muted', text: [w.project_title, w.location].filter(Boolean).join(' · ') || '–' }),
          el('div', { class: 'small muted', text: 'Határidő: ' + TB.date(w.due_at) + ' · Checklist: ' + (w.checklist_done || 0) + '/' + (w.checklist_total || 0) + (pendingIds.has(Number(w.id)) ? ' · ⇅ függő módosítás' : '') }),
        ])));
      };
      filter.addEventListener('change', draw);
      draw();
    } catch (err) {
      TB.state(main, 'error', TB.errorMessage(err));
    }
    focusMain();
  }

  function section(title, children) {
    return el('section', { class: 'card', 'aria-label': title }, [el('h2', { text: title })].concat(children));
  }

  async function renderDetail(id) {
    const main = clear($('#main'));
    TB.state(main, 'loading', 'Munkalap betöltése…');
    let result;
    try {
      result = await fetchOrCache('detail.' + id, () => TB.platform('mobile', { view: 'detail', id }));
    } catch (err) {
      TB.state(main, 'error', TB.errorMessage(err));
      main.appendChild(el('a', { class: 'tb-btn', href: '#/', text: '← Vissza a listához' }));
      return;
    }
    const detail = withPending(JSON.parse(JSON.stringify(result.data)), id);
    const wo = detail.work_order;
    clear(main);
    main.appendChild(el('a', { class: 'tb-btn', href: '#/', text: '← Lista' }));
    main.appendChild(el('h1', { class: 'mt', text: wo.title }));
    const notice = cacheNotice(result.fromCache, result.data);
    if (notice) main.appendChild(notice);
    const myConflicts = queue().filter((o) => Number(o.work_order_id) === Number(id) && o.state !== 'pending');
    if (myConflicts.length) {
      main.appendChild(el('p', { class: 'card tb-state-error', role: 'alert' }, [
        myConflicts.length + ' művelet beavatkozást igényel. ',
        el('a', { href: '#/queue', text: 'Megnyitás a szinkronizálási sorban' }),
      ]));
    }

    const info = [
      el('p', {}, [el('span', { class: 'badge badge-' + wo.status, text: TB.label(wo.status) }), ' ', el('span', { class: 'badge badge-' + (wo.priority || 'normal'), text: 'Prioritás: ' + (wo.priority || 'normal') })]),
      wo.project_title ? el('p', { text: 'Projekt: ' + wo.project_title }) : null,
      wo.location ? el('p', {}, ['Helyszín: ', el('a', { href: 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(wo.location), target: '_blank', rel: 'noopener', text: wo.location })]) : null,
      wo.customer_name ? el('p', { text: 'Ügyfél: ' + wo.customer_name }) : null,
      wo.customer_phone ? el('p', {}, ['Telefon: ', el('a', { href: 'tel:' + String(wo.customer_phone).replace(/[^0-9+]/g, ''), text: wo.customer_phone })]) : null,
      wo.description ? el('p', { class: 'small', text: wo.description }) : null,
      el('p', { class: 'small muted', text: 'Határidő: ' + TB.date(wo.due_at) + ' · Ledolgozott: ' + Math.round((wo.actual_minutes || 0) / 6) / 10 + ' óra' }),
    ];
    main.appendChild(section('Alapadatok', info));

    const serverStatus = result.data.work_order.status;
    const quick = el('div', { class: 'quick-actions' }, STATUS_ACTIONS.filter((a) => a.status !== wo.status).map((a) => el('button', {
      type: 'button',
      class: 'tb-btn tb-btn-lg' + (a.status === 'done' ? ' tb-btn-primary' : ''),
      text: a.label,
      onclick: () => {
        if (a.status === 'done' && !confirm('Biztosan késznek jelöli a munkalapot?')) return;
        enqueue(id, 'status', { status: a.status }, { status: wo.status || serverStatus });
        renderDetail(id);
      },
    })));
    main.appendChild(section('Gyorsműveletek', [quick, el('div', { class: 'row mt' }, [
      el('a', { class: 'tb-btn tb-btn-lg', href: '/sign.html?type=work_order&id=' + encodeURIComponent(id) + '&back=' + encodeURIComponent('/mobile.html#/wo/' + id), text: '✍ Ügyfél aláírás' }),
    ])]));

    const checklist = detail.checklist || [];
    const listNode = el('ul', { class: 'checklist' }, checklist.map((c) => {
      const cbId = 'chk-' + c.id;
      const cb = el('input', { type: 'checkbox', id: cbId });
      cb.checked = Number(c.is_done) === 1;
      cb.addEventListener('change', () => {
        enqueue(id, 'checklist', { item_id: Number(c.id), is_done: cb.checked }, { is_done: Number(c.is_done) === 1 ? 1 : 0 });
        c.is_done = cb.checked ? 1 : 0;
      });
      return el('li', {}, [el('label', { class: 'check', for: cbId }, [cb, el('span', { text: c.item_text })])]);
    }));
    main.appendChild(section('Ellenőrzőlista', checklist.length ? [listNode] : [el('p', { class: 'muted', text: 'Nincs checklist tétel.' })]));

    main.appendChild(section('Munkaidő', timeLogSection(id, detail)));
    main.appendChild(section('Anyagfelhasználás', materialSection(id, detail)));
    main.appendChild(section('Megjegyzések', noteSection(id, detail)));
    if ((detail.signatures || []).length) {
      main.appendChild(section('Aláírások', [el('ul', {}, detail.signatures.map((s) => el('li', { text: s.signer_name + ' – ' + TB.date(s.signed_at) + ' – ' + TB.label(s.status) })))]));
    }
    focusMain();
  }

  function timeLogSection(id, detail) {
    const timer = load('timer', null);
    const display = el('div', { class: 'timer', 'aria-live': 'off', text: '00:00:00' });
    const toggle = el('button', { type: 'button', class: 'tb-btn tb-btn-lg tb-btn-primary' });
    const tick = () => {
      const t = load('timer', null);
      if (!t || Number(t.work_order_id) !== Number(id)) { display.textContent = '00:00:00'; return; }
      const s = Math.floor((Date.now() - new Date(t.started_at).getTime()) / 1000);
      display.textContent = [Math.floor(s / 3600), Math.floor(s / 60) % 60, s % 60].map((n) => String(n).padStart(2, '0')).join(':');
    };
    const refreshToggle = () => {
      const t = load('timer', null);
      const runningHere = t && Number(t.work_order_id) === Number(id);
      toggle.textContent = runningHere ? '⏹ Stop és rögzítés' : '⏱ Indítás';
      toggle.disabled = !!(t && !runningHere);
      toggle.title = t && !runningHere ? 'Másik munkalapon fut az óra (#' + t.work_order_id + ').' : '';
    };
    toggle.addEventListener('click', () => {
      const t = load('timer', null);
      if (t && Number(t.work_order_id) === Number(id)) {
        const minutes = Math.max(1, Math.round((Date.now() - new Date(t.started_at).getTime()) / 60000));
        save('timer', null);
        if (minutes > 1440) {
          TB.toast('A mért idő több mint 24 óra, kérjük rögzítse kézzel a helyes értéket.', 'warn');
        } else {
          enqueue(id, 'time_log', { minutes, started_at: t.started_at, note: 'Stopperrel mérve' });
          renderDetail(id);
          return;
        }
      } else {
        save('timer', { work_order_id: Number(id), started_at: new Date().toISOString() });
        TB.toast('Munkaidő mérés elindult.', 'success');
      }
      refreshToggle();
      tick();
    });
    clearInterval(timerInterval);
    timerInterval = setInterval(tick, 1000);
    tick();
    refreshToggle();
    if (timer && Number(timer.work_order_id) !== Number(id)) {
      display.textContent = '—';
    }

    const minutes = el('input', { id: 'tl-min', type: 'number', min: '1', max: '1440', inputmode: 'numeric', required: true });
    const note = el('input', { id: 'tl-note', maxlength: '500' });
    const form = el('form', { class: 'mt' }, [
      el('label', { for: 'tl-min', text: 'Kézi rögzítés (perc, 1–1440)' }), minutes,
      el('label', { for: 'tl-note', text: 'Megjegyzés' }), note,
      el('button', { type: 'submit', class: 'tb-btn mt', text: 'Munkaidő rögzítése' }),
    ]);
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      const m = parseInt(minutes.value, 10);
      if (!(m >= 1 && m <= 1440)) { TB.toast('A perc értéke 1 és 1440 között legyen.', 'error'); minutes.focus(); return; }
      enqueue(id, 'time_log', { minutes: m, note: note.value.trim() });
      renderDetail(id);
    });
    const logs = (detail.time_logs || []).slice(0, 10);
    return [display, toggle, form, logs.length ? el('ul', { class: 'small mt' }, logs.map((l) => el('li', { text: l.minutes + ' perc – ' + (l.user_name || '') + ' – ' + TB.date(l.started_at || l.created_at) + (l.note ? ' – ' + l.note : '') }))) : null];
  }

  function materialSection(id, detail) {
    const title = el('input', { id: 'mat-title', maxlength: '190', required: true, autocomplete: 'off' });
    const qty = el('input', { id: 'mat-qty', type: 'number', min: '0.01', step: '0.01', inputmode: 'decimal', required: true });
    const unit = el('input', { id: 'mat-unit', maxlength: '20', value: 'db' });
    const form = el('form', {}, [
      el('label', { for: 'mat-title', text: 'Anyag megnevezése' }), title,
      el('div', { class: 'grid grid-2' }, [
        el('div', {}, [el('label', { for: 'mat-qty', text: 'Mennyiség' }), qty]),
        el('div', {}, [el('label', { for: 'mat-unit', text: 'Egység' }), unit]),
      ]),
      el('button', { type: 'submit', class: 'tb-btn mt', text: 'Anyag rögzítése' }),
    ]);
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      const q = parseFloat(String(qty.value).replace(',', '.'));
      if (title.value.trim().length < 2 || !(q > 0)) { TB.toast('Adja meg az anyag nevét és a pozitív mennyiséget.', 'error'); return; }
      enqueue(id, 'material', { title: title.value.trim(), quantity: q, unit: unit.value.trim() || 'db' });
      renderDetail(id);
    });
    const mats = (detail.materials || []).slice(0, 15);
    return [form, mats.length ? el('ul', { class: 'small mt' }, mats.map((m) => el('li', { text: m.title + ' – ' + m.quantity + ' ' + (m.unit || '') }))) : null];
  }

  function noteSection(id, detail) {
    const text = el('textarea', { id: 'note-text', maxlength: '2000', required: true });
    const form = el('form', {}, [
      el('label', { for: 'note-text', text: 'Új megjegyzés / hibajelzés' }), text,
      el('button', { type: 'submit', class: 'tb-btn mt', text: 'Megjegyzés mentése' }),
    ]);
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      if (!text.value.trim()) { TB.toast('A megjegyzés nem lehet üres.', 'error'); text.focus(); return; }
      enqueue(id, 'note', { note: text.value.trim() });
      renderDetail(id);
    });
    const notes = (detail.notes || []).slice(0, 15);
    return [form, notes.length ? el('ul', { class: 'small mt' }, notes.map((n) => el('li', {}, [el('strong', { text: (n.author_name || '') + ' – ' + TB.date(n.created_at) + ': ' }), n.note]))) : null];
  }

  function describe(op) {
    const d = op.data || {};
    if (op.op_type === 'status') return 'Új státusz: ' + TB.label(d.status);
    if (op.op_type === 'checklist') return 'Tétel #' + d.item_id + ' → ' + (d.is_done ? 'kész' : 'nincs kész');
    if (op.op_type === 'note') return String(d.note || '').slice(0, 80);
    if (op.op_type === 'time_log') return d.minutes + ' perc';
    if (op.op_type === 'material') return d.title + ' – ' + d.quantity + ' ' + (d.unit || '');
    return '';
  }

  function describeServer(conflict) {
    if (!conflict) return '';
    const s = conflict.server || {};
    if (s.status) return 'Szerver állapot: ' + TB.label(s.status);
    if (s.item_text !== undefined) return 'Szerveren: „' + s.item_text + '” ' + (Number(s.is_done) ? 'kész' : 'nincs kész');
    return '';
  }

  async function resolve(operationId, resolution, localKey) {
    if (!navigator.onLine) { TB.toast('A feloldáshoz internetkapcsolat szükséges.', 'warn'); return; }
    try {
      await TB.platformPost('mobile', { action: 'resolve_conflict', operation_id: operationId, resolution });
      if (localKey) saveQueue(queue().filter((o) => o.idempotency_key !== localKey));
      TB.toast(resolution === 'apply_mine' ? 'Saját módosítás alkalmazva.' : 'A szerver adata megmaradt, a helyi módosítás elvetve.', 'success');
    } catch (err) {
      if (err.kind === 'not_found' && localKey) saveQueue(queue().filter((o) => o.idempotency_key !== localKey));
      TB.toast(TB.errorMessage(err), 'error');
    }
    renderQueue();
  }

  async function renderQueue() {
    const main = clear($('#main'));
    main.appendChild(el('a', { class: 'tb-btn', href: '#/', text: '← Lista' }));
    main.appendChild(el('h1', { class: 'mt', text: 'Szinkronizálási sor' }));
    const q = queue();
    const box = el('div', { 'aria-live': 'polite' });
    main.appendChild(section('Ezen a készüléken (' + q.length + ')', [box]));
    if (!q.length) TB.state(box, 'empty', 'Minden művelet szinkronizálva.');
    q.forEach((op) => {
      const actions = [];
      if (op.state === 'conflict' && op.operation_id) {
        actions.push(el('button', { type: 'button', class: 'tb-btn', text: 'Szerver adat megtartása', onclick: () => resolve(op.operation_id, 'keep_server', op.idempotency_key) }));
        actions.push(el('button', { type: 'button', class: 'tb-btn tb-btn-primary', text: 'Saját módosítás alkalmazása', onclick: () => { if (confirm('Biztosan felülírja a szerveren lévő adatot a saját módosításával?')) resolve(op.operation_id, 'apply_mine', op.idempotency_key); } }));
      }
      if (op.state === 'pending') {
        actions.push(el('button', { type: 'button', class: 'tb-btn', text: 'Újraküldés most', onclick: () => { const all = queue(); all.forEach((o) => { if (o.idempotency_key === op.idempotency_key) o.next_retry_at = 0; }); saveQueue(all); sync(true).then(renderQueue); } }));
      }
      if (op.state !== 'conflict') {
        actions.push(el('button', { type: 'button', class: 'tb-btn tb-btn-danger', text: 'Elvetés', onclick: () => { if (confirm('Biztosan elveti ezt a műveletet? Nem kerül elküldésre.')) { saveQueue(queue().filter((o) => o.idempotency_key !== op.idempotency_key)); renderQueue(); } } }));
      }
      box.appendChild(el('div', { class: 'queue-item' }, [
        el('div', { class: 'row' }, [
          el('strong', { text: OP_LABELS[op.op_type] || op.op_type }),
          el('a', { href: '#/wo/' + op.work_order_id, text: 'Munkalap #' + op.work_order_id }),
          el('span', { class: 'spacer' }),
          el('span', { class: 'badge badge-' + (op.state === 'pending' ? 'pending' : op.state === 'conflict' ? 'conflict' : 'failed'), text: STATE_LABELS[op.state] || op.state }),
        ]),
        el('div', { class: 'small', text: describe(op) }),
        el('div', { class: 'small muted', text: 'Rögzítve: ' + TB.date(op.client_created_at) + (op.attempts ? ' · Próbálkozások: ' + op.attempts : '') + (op.state === 'pending' && op.next_retry_at > Date.now() ? ' · Következő próba: ' + TB.date(new Date(op.next_retry_at).toISOString()) : '') }),
        op.last_error ? el('div', { class: 'small tb-state-error', text: op.last_error + (op.conflict ? ' ' + describeServer(op.conflict) : '') }) : null,
        actions.length ? el('div', { class: 'row mt' }, actions) : null,
      ]));
    });

    const serverBox = el('div', {});
    main.appendChild(section('Szerveren nyilvántartott ütközések', [serverBox]));
    if (!navigator.onLine) {
      TB.state(serverBox, 'empty', 'Offline módban nem kérhető le.');
    } else {
      TB.state(serverBox, 'loading', 'Betöltés…');
      try {
        const data = await TB.platform('mobile', { view: 'conflicts' });
        const localIds = new Set(queue().map((o) => Number(o.operation_id || 0)));
        const rows = (data.conflicts || []).filter((c) => !localIds.has(Number(c.id)));
        clear(serverBox);
        if (!rows.length) TB.state(serverBox, 'empty', 'Nincs további feloldatlan ütközés.');
        rows.forEach((c) => serverBox.appendChild(el('div', { class: 'queue-item' }, [
          el('div', { class: 'row' }, [el('strong', { text: OP_LABELS[c.operation_type] || c.operation_type }), el('span', { text: '#' + c.work_order_id + ' ' + (c.work_order_title || '') }), el('span', { class: 'spacer' }), el('span', { class: 'small muted', text: c.actor_name || '' })]),
          el('div', { class: 'small', text: describe({ op_type: c.operation_type, data: (c.payload || {}).data || {} }) }),
          el('div', { class: 'small tb-state-error', text: ((c.conflict || {}).message || '') + ' ' + describeServer(c.conflict) }),
          el('div', { class: 'row mt' }, [
            el('button', { type: 'button', class: 'tb-btn', text: 'Szerver adat megtartása', onclick: () => resolve(c.id, 'keep_server') }),
            el('button', { type: 'button', class: 'tb-btn tb-btn-primary', text: 'Módosítás alkalmazása', onclick: () => { if (confirm('Biztosan felülírja a szerver adatát?')) resolve(c.id, 'apply_mine'); } }),
          ]),
        ])));
      } catch (err) {
        TB.state(serverBox, 'error', TB.errorMessage(err));
      }
    }
    focusMain();
  }

  /* ---------- Útvonalak ---------- */
  function route() {
    clearInterval(timerInterval);
    const hash = location.hash || '#/';
    const m = hash.match(/^#\/wo\/(\d+)$/);
    if (m) return renderDetail(parseInt(m[1], 10));
    if (hash === '#/queue') return renderQueue();
    return renderList();
  }

  function refreshCurrent() {
    const hash = location.hash || '#/';
    if (hash === '#/queue' || /^#\/wo\/\d+$/.test(hash) || hash === '#/') route();
  }

  async function init() {
    TB.registerServiceWorker();
    updateIndicators();
    try {
      user = await TB.session(true);
    } catch (e) {
      user = null;
    }
    if (!user) {
      const cachedUser = (() => { try { return JSON.parse(localStorage.getItem('tb.mobile.lastUser') || 'null'); } catch (e) { return null; } })();
      if (!navigator.onLine && cachedUser) {
        user = cachedUser;
      } else {
        const main = clear($('#main'));
        main.appendChild(el('div', { class: 'card' }, [
          el('h1', { text: 'Bejelentkezés szükséges' }),
          el('p', { text: 'A mobil munkalapok használatához jelentkezzen be munkatársi fiókjával.' }),
          el('a', { class: 'tb-btn tb-btn-primary', href: '/', text: 'Bejelentkezés' }),
        ]));
        return;
      }
    } else {
      localStorage.setItem('tb.mobile.lastUser', JSON.stringify({ id: user.id, name: user.name }));
    }
    prefix = 'tb.mobile.' + user.id + '.';
    updateIndicators();
    window.addEventListener('hashchange', route);
    window.addEventListener('online', () => { authBlocked = false; updateIndicators(); TB.toast('Újra online – szinkronizálás…', 'success'); sync(); });
    window.addEventListener('offline', () => { updateIndicators(); TB.toast('Offline mód: a módosításokat a készülék tárolja.', 'warn'); });
    $('#sync-btn').addEventListener('click', () => sync(true));
    setInterval(() => sync(), 30000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) sync(); });
    route();
    sync();
  }

  init();
})();
