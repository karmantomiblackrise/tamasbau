/*
 * Admin központ: profit, workflow, SLA, értékelések, aláírások, mobil ütközések, számlázás, 2FA/munkamenetek,
 * diagnosztika, globális kereső és parancspaletta (Ctrl+K vagy "/").
 */
(function () {
  'use strict';
  const { el, $, $$, clear } = TB;

  const SECTIONS = ['profit', 'workflows', 'sla', 'reviews', 'signatures', 'conflicts', 'invoices', 'security', 'health', 'search'];
  const BACKOFFICE = ['admin', 'superadmin', 'project_manager', 'service_agent', 'support_agent', 'quote_manager'];
  const SUPER = ['admin', 'superadmin'];
  const STATUS_OPTIONS = {
    project: ['draft', 'survey_scheduled', 'quoted', 'approved', 'scheduled', 'in_progress', 'on_hold', 'completed', 'cancelled'],
    work_order: ['todo', 'in_progress', 'blocked', 'done', 'cancelled'],
    ticket: ['open', 'triaged', 'scheduled', 'in_progress', 'waiting_customer', 'resolved', 'closed', 'rejected'],
  };
  const ENTITY_LABELS = { project: 'Projekt', work_order: 'Munkalap', ticket: 'Szerviz ticket' };
  const COST_TYPES = { material: 'Anyag', labor: 'Munkaóra', travel: 'Utazás', external: 'Külső költség' };

  let user = null;
  let isBackoffice = false;
  let isSuper = false;
  let paletteActions = [];

  /* ---------- Segédek ---------- */
  function field(label, input, hint) {
    const id = input.id || ('f-' + Math.random().toString(36).slice(2));
    input.id = id;
    return el('div', {}, [el('label', { for: id, text: label }), input, hint ? el('div', { class: 'small muted', text: hint }) : null]);
  }

  function select(options, value, attrs) {
    const s = el('select', attrs || {}, options.map((o) => {
      const [v, t] = Array.isArray(o) ? o : [o, TB.label(o)];
      return el('option', { value: v, text: t });
    }));
    if (value !== undefined && value !== null) s.value = String(value);
    return s;
  }

  function badge(status, text) {
    return el('span', { class: 'badge badge-' + String(status || '').replace(/[^a-z_]/g, ''), text: text || TB.label(status) });
  }

  function table(headers, rows, opts) {
    const o = opts || {};
    if (!rows.length) return el('p', { class: 'tb-state tb-state-empty', text: '📭 ' + (o.empty || 'Nincs megjeleníthető adat.') });
    return el('div', { class: 'table-wrap' }, [el('table', {}, [
      o.caption ? el('caption', { class: 'sr-only', text: o.caption }) : null,
      el('thead', {}, [el('tr', {}, headers.map((h) => el('th', { scope: 'col', class: /^(Összeg|Bevétel|Költség|Margin|Darab|Átlag|#)/.test(h) ? 'num' : null, text: h })))]),
      el('tbody', {}, rows.map((cells) => el('tr', {}, cells.map((c) => (c instanceof Node && c.tagName === 'TD') ? c : el('td', {}, [c]))))),
    ])]);
  }

  function num(value, cls) {
    return el('td', { class: 'num' + (cls ? ' ' + cls : '') }, [value]);
  }

  function pager(total, limit, offset, onChange) {
    if (total <= limit) return null;
    const page = Math.floor(offset / limit) + 1;
    const pages = Math.ceil(total / limit);
    return el('div', { class: 'row mt', role: 'navigation', 'aria-label': 'Lapozás' }, [
      el('button', { type: 'button', class: 'tb-btn', disabled: offset <= 0, text: '← Előző', onclick: () => onChange(Math.max(0, offset - limit)) }),
      el('span', { class: 'small muted', text: page + ' / ' + pages + ' oldal · ' + total + ' tétel' }),
      el('button', { type: 'button', class: 'tb-btn', disabled: offset + limit >= total, text: 'Következő →', onclick: () => onChange(offset + limit) }),
    ]);
  }

  async function run(fn, successMessage) {
    try {
      const res = await fn();
      if (successMessage) TB.toast(successMessage, 'success');
      return res;
    } catch (err) {
      TB.toast(TB.errorMessage(err), 'error');
      return null;
    }
  }

  function card(title, children, headerExtra) {
    return el('section', { class: 'card', 'aria-label': title }, [
      el('div', { class: 'row' }, [el('h2', { text: title }), el('span', { class: 'spacer' })].concat(headerExtra || [])),
    ].concat(children));
  }

  function kpi(label, value, cls) {
    return el('div', { class: 'kpi' + (cls ? ' ' + cls : '') }, [el('div', { class: 'kpi-label', text: label }), el('div', { class: 'kpi-value', text: value })]);
  }

  function main() {
    return clear($('#main'));
  }

  function heading(text, sub) {
    const m = $('#main');
    m.appendChild(el('h1', { text, tabindex: '-1' }));
    if (sub) m.appendChild(el('p', { class: 'muted small', text: sub }));
  }

  function needRole(roles, label) {
    if (roles.includes(user.role)) return true;
    TB.state($('#main'), 'error', 'Ehhez a nézethez (' + label + ') nincs jogosultsága.');
    return false;
  }

  /* ---------- C) Profit ---------- */
  async function renderProfit(params) {
    const m = main();
    if (!needRole(BACKOFFICE, 'Profit')) return;
    const scope = params.get('scope') === 'work_order' ? 'work_order' : 'project';
    heading('Projekt profit dashboard', 'Tervezett vs. tényleges költség, bevétel és margin. A belső költségek csak jogosult adminnak látszanak.');
    const scopeSel = select([['project', 'Projektek'], ['work_order', 'Munkalapok']], scope, { 'aria-label': 'Nézet' });
    scopeSel.addEventListener('change', () => { location.hash = 'profit?scope=' + scopeSel.value; });
    const csv = el('a', { class: 'tb-btn', href: '/api/platform.php?module=profit&view=csv&scope=' + scope, download: '', text: '⬇ CSV export' });
    m.appendChild(el('div', { class: 'row' }, [scopeSel, csv]));
    const body = el('div', { class: 'mt' });
    m.appendChild(body);
    TB.state(body, 'loading', 'Számítás…');
    try {
      const data = await TB.platform('profit', { view: 'overview', scope });
      clear(body);
      const k = data.kpi || {};
      const cards = [kpi('Tételek', String(k.count || 0)), kpi('Tényleges bevétel', TB.huf(k.revenue_cents)), kpi('Tervezett bevétel', TB.huf(k.planned_revenue_cents))];
      if (data.internal_visible) {
        cards.push(kpi('Tényleges költség', TB.huf(k.cost_cents)));
        cards.push(kpi('Tervezett költség', TB.huf(k.planned_cost_cents)));
        cards.push(kpi('Margin', TB.huf(k.margin_cents) + (k.margin_pct !== null && k.margin_pct !== undefined ? ' (' + k.margin_pct + '%)' : ''), Number(k.margin_cents) < 0 ? 'kpi-danger' : ''));
        cards.push(kpi('Riasztások', String(k.alert_count || 0), k.alert_count ? 'kpi-warn' : ''));
        cards.push(kpi('Negatív margin', String(k.negative_margin_count || 0), k.negative_margin_count ? 'kpi-danger' : ''));
      } else {
        body.appendChild(el('p', { class: 'small muted', text: 'Belső költség és margin adatok csak admin / superadmin szerepkörrel láthatók.' }));
      }
      body.appendChild(el('div', { class: 'grid grid-kpi' }, cards));
      const headers = ['#', 'Megnevezés', 'Státusz', 'Bevétel'].concat(data.internal_visible ? ['Költség (terv / tény)', 'Margin', 'Riasztás'] : []);
      const rows = (data.rows || []).map((r) => {
        const p = r.profit || {};
        const a = p.actual || {};
        const pl = p.planned || {};
        const cells = [
          String(r.id),
          el('button', { type: 'button', class: 'tb-btn', text: r.title + (r.parent_title ? ' – ' + r.parent_title : ''), onclick: () => profitDetail(scope, r.id, r.title) }),
          badge(r.status),
          num(TB.huf(a.revenue_cents)),
        ];
        if (data.internal_visible) {
          cells.push(num(TB.huf(pl.cost_cents) + ' / ' + TB.huf(a.cost_cents)));
          cells.push(num(TB.huf(a.margin_cents) + (a.margin_pct !== null && a.margin_pct !== undefined ? ' (' + a.margin_pct + '%)' : ''), Number(a.margin_cents) < 0 ? 'neg' : 'pos'));
          cells.push(el('div', {}, (p.alerts || []).map((al) => el('div', { class: 'small' }, [badge(al.severity === 'critical' ? 'error' : 'warn', al.severity === 'critical' ? 'Kritikus' : 'Figyelem'), ' ', al.message]))));
        }
        return cells;
      });
      body.appendChild(card(scope === 'project' ? 'Projektek' : 'Munkalapok', [table(headers, rows, { caption: 'Profit kimutatás' })]));
    } catch (err) {
      TB.state(body, 'error', TB.errorMessage(err));
    }
  }

  async function profitDetail(scope, id, title) {
    const content = el('div', {});
    TB.state(content, 'loading', 'Betöltés…');
    const modal = TB.modal('Költségelemzés: ' + title, content, [{ label: 'Bezárás' }]);
    modal.dialog.classList.add('tb-modal-wide');
    const load = async () => {
      try {
        const data = await TB.platform('costs', scope === 'project' ? { project_id: id } : { work_order_id: id });
        clear(content);
        const p = data.profit || {};
        if (data.internal_visible && p.breakdown) {
          content.appendChild(table(['Típus', 'Tervezett költség', 'Tényleges költség', 'Bevétel'], Object.keys(p.breakdown).map((t) => [
            COST_TYPES[t] || t, num(TB.huf(p.breakdown[t].planned_cost_cents)), num(TB.huf(p.breakdown[t].actual_cost_cents)), num(TB.huf(p.breakdown[t].revenue_cents)),
          ]), { caption: 'Bontás' }));
          (p.alerts || []).forEach((al) => content.appendChild(el('p', { class: 'small tb-state-error', role: 'alert', text: '⚠ ' + al.message })));
        }
        content.appendChild(el('h3', { class: 'mt', text: 'Költségtételek' }));
        content.appendChild(table(['Típus', 'Megnevezés', 'Mennyiség', 'Egységár'].concat(data.internal_visible ? ['Belső egységköltség'] : []).concat(['']), (data.entries || []).map((e) => {
          const cells = [COST_TYPES[e.entry_type] || e.entry_type, e.title + (e.travel_km ? ' (' + e.travel_km + ' km)' : ''), e.quantity + ' ' + (e.unit || ''), num(TB.huf(e.unit_price_cents))];
          if (data.internal_visible) cells.push(num(TB.huf(e.internal_unit_cost_cents)));
          cells.push(el('button', { type: 'button', class: 'tb-btn tb-btn-danger', text: 'Törlés', 'aria-label': 'Tétel törlése: ' + e.title, onclick: async () => {
            if (!confirm('Biztosan törli a költségtételt?')) return;
            if (await run(() => TB.platformPost('costs', { action: 'delete', id: e.id }), 'Tétel törölve.')) load();
          } }));
          return cells;
        }), { empty: 'Még nincs rögzített költségtétel.' }));
        content.appendChild(costForm(scope, id, data.internal_visible, load));
      } catch (err) {
        TB.state(content, 'error', TB.errorMessage(err));
      }
    };
    load();
  }

  function costForm(scope, id, internal, done) {
    const type = select(Object.keys(COST_TYPES).map((k) => [k, COST_TYPES[k]]), 'material');
    const title = el('input', { maxlength: '180', required: true });
    const qty = el('input', { type: 'number', step: '0.01', min: '0', value: '1' });
    const unit = el('input', { maxlength: '20', value: 'db' });
    const price = el('input', { type: 'number', step: '1', min: '0', placeholder: 'Ft' });
    const cost = el('input', { type: 'number', step: '1', min: '0', placeholder: 'Ft' });
    const plannedQty = el('input', { type: 'number', step: '0.01', min: '0' });
    const plannedPrice = el('input', { type: 'number', step: '1', min: '0', placeholder: 'Ft' });
    const km = el('input', { type: 'number', step: '0.1', min: '0' });
    const billable = el('input', { type: 'checkbox', checked: true });
    const form = el('form', { class: 'card mt' }, [
      el('h3', { text: 'Új költségtétel' }),
      el('div', { class: 'grid grid-2' }, [
        field('Típus', type), field('Megnevezés *', title), field('Mennyiség', qty), field('Egység', unit),
        field('Eladási egységár (Ft)', price),
        internal ? field('Belső egységköltség (Ft)', cost) : null,
        field('Tervezett mennyiség', plannedQty), field('Tervezett egységár (Ft)', plannedPrice), field('Kilométer (utazás)', km),
      ]),
      el('label', { class: 'check' }, [billable, el('span', { text: 'Ügyfélnek számlázható' })]),
      el('button', { type: 'submit', class: 'tb-btn tb-btn-primary mt', text: 'Hozzáadás' }),
    ]);
    const cents = (input) => (input.value === '' ? '' : Math.round(parseFloat(input.value) * 100));
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const body = {
        action: 'add', entry_type: type.value, title: title.value.trim(), quantity: qty.value, unit: unit.value.trim(),
        unit_price_cents: cents(price) || 0, planned_quantity: plannedQty.value, planned_unit_price_cents: cents(plannedPrice), travel_km: km.value,
        billable_to_customer: billable.checked,
      };
      if (internal) body.internal_unit_cost_cents = cents(cost);
      body[scope === 'project' ? 'project_id' : 'work_order_id'] = id;
      if (await run(() => TB.platformPost('costs', body), 'Költségtétel rögzítve.')) done();
    });
    return form;
  }

  /* ---------- D) Workflow ---------- */
  async function renderWorkflows(params) {
    const m = main();
    if (!needRole(BACKOFFICE, 'Workflow')) return;
    heading('Automatizált workflow központ', 'Szabályok, job sor (pending / processing / done / failed), kézi futtatás cron nélkül.');
    const status = params.get('status') || '';
    const ruleKey = params.get('rule_key') || '';
    const offset = parseInt(params.get('offset') || '0', 10) || 0;
    const body = el('div', {});
    m.appendChild(body);
    TB.state(body, 'loading', 'Betöltés…');
    try {
      const data = await TB.platform('workflows', { status, rule_key: ruleKey, offset, limit: 25 });
      clear(body);
      const stats = data.stats || {};
      body.appendChild(el('div', { class: 'grid grid-kpi' }, ['pending', 'processing', 'done', 'failed'].map((s) => kpi(TB.label(s), String(stats[s] || 0), s === 'failed' && stats[s] ? 'kpi-danger' : ''))));
      const runPending = el('button', { type: 'button', class: 'tb-btn tb-btn-primary', text: '▶ Függő jobok futtatása most', disabled: !isSuper, onclick: async () => {
        const res = await run(() => TB.platformPost('workflows', { action: 'run_pending', limit: 25 }));
        if (res) { TB.toast('Feldolgozva: ' + ((res.run && res.run.processed) || []).length + ' job.', 'success'); renderWorkflows(params); }
      } });
      body.appendChild(card('Szabályok', [table(['Szabály', 'Állapot', 'Függő', 'Hibás', 'Kezelő', ''], (data.rules || []).map((r) => {
        const toggle = el('input', { type: 'checkbox', 'aria-label': 'Szabály engedélyezése: ' + r.title, disabled: !isSuper });
        toggle.checked = Number(r.is_enabled) === 1;
        toggle.addEventListener('change', async () => {
          if (!(await run(() => TB.platformPost('workflows', { action: 'toggle_rule', id: r.id, is_enabled: toggle.checked }), toggle.checked ? 'Szabály bekapcsolva.' : 'Szabály kikapcsolva.'))) toggle.checked = !toggle.checked;
        });
        return [
          el('div', {}, [el('strong', { text: r.title }), el('div', { class: 'small muted mono', text: r.rule_key })]),
          el('label', { class: 'check' }, [toggle, el('span', { text: toggle.checked ? 'Aktív' : 'Kikapcsolva' })]),
          num(String(r.pending_count)), num(String(r.failed_count), Number(r.failed_count) ? 'neg' : ''),
          r.has_handler ? badge('ok', 'van') : badge('warn', 'nincs'),
          el('button', { type: 'button', class: 'tb-btn', text: 'Konfiguráció', disabled: !isSuper, onclick: () => editRule(r, () => renderWorkflows(params)) }),
        ];
      }))], [runPending]));

      const statusSel = select([['', 'Minden státusz'], 'pending', 'processing', 'done', 'failed'], status, { 'aria-label': 'Job státusz szűrő' });
      const ruleSel = select([['', 'Minden szabály']].concat((data.rules || []).map((r) => [r.rule_key, r.title])), ruleKey, { 'aria-label': 'Szabály szűrő' });
      const apply = () => { location.hash = 'workflows?status=' + encodeURIComponent(statusSel.value) + '&rule_key=' + encodeURIComponent(ruleSel.value); };
      statusSel.addEventListener('change', apply);
      ruleSel.addEventListener('change', apply);
      const jobs = (data.jobs || []).map((j) => [
        String(j.id), el('span', { class: 'mono small', text: j.rule_key || '–' }), badge(j.status),
        num(j.retries + ' / ' + j.max_retries),
        el('div', { class: 'small' }, [j.last_error ? el('span', { class: 'neg', text: j.last_error }) : '–']),
        el('div', { class: 'small muted', text: 'Létrehozva: ' + TB.date(j.created_at) + (j.processed_at ? ' · Feldolgozva: ' + TB.date(j.processed_at) : '') + (j.next_attempt_at ? ' · Következő próba: ' + TB.date(j.next_attempt_at) : '') }),
        j.status === 'failed'
          ? el('button', { type: 'button', class: 'tb-btn', text: '↻ Újrapróbálás', disabled: !isSuper, onclick: async () => { if (await run(() => TB.platformPost('workflows', { action: 'retry_job', id: j.id }), 'Job újrafuttatva.')) renderWorkflows(params); } })
          : j.status === 'pending'
            ? el('button', { type: 'button', class: 'tb-btn', text: '▶ Futtatás', disabled: !isSuper, onclick: async () => { if (await run(() => TB.platformPost('workflows', { action: 'run_job', id: j.id }), 'Job lefutott.')) renderWorkflows(params); } })
            : '',
      ]);
      body.appendChild(card('Job sor', [
        el('div', { class: 'row' }, [statusSel, ruleSel]),
        table(['#', 'Szabály', 'Státusz', 'Próbák', 'Hiba', 'Időpontok', ''], jobs, { empty: 'Nincs a szűrésnek megfelelő job.' }),
        pager(data.total, data.limit, data.offset, (o) => { location.hash = 'workflows?status=' + encodeURIComponent(status) + '&rule_key=' + encodeURIComponent(ruleKey) + '&offset=' + o; }),
      ]));
      if (!isSuper) body.appendChild(el('p', { class: 'small muted', text: 'Szabályok módosítása és jobok futtatása csak admin / superadmin szerepkörrel lehetséges.' }));
    } catch (err) {
      TB.state(body, 'error', TB.errorMessage(err));
    }
  }

  function editRule(rule, done) {
    const title = el('input', { value: rule.title, maxlength: '180' });
    const config = el('textarea', { rows: '10', class: 'mono', spellcheck: 'false' });
    config.value = JSON.stringify(rule.config || {}, null, 2);
    const error = el('p', { class: 'tb-state-error small', role: 'alert' });
    TB.modal('Szabály konfiguráció: ' + rule.rule_key, el('div', {}, [field('Megnevezés', title), field('Konfiguráció (JSON objektum)', config, 'Pl. {"delay_days": 3}. Érvénytelen JSON nem menthető.'), error]), [
      { label: 'Mégse' },
      { label: 'Mentés', primary: true, onClick: async () => {
        let parsed;
        try {
          parsed = JSON.parse(config.value);
          if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) throw new Error('obj');
        } catch (e) {
          error.textContent = 'A konfiguráció csak érvényes JSON objektum lehet.';
          return true;
        }
        const ok = await run(() => TB.platformPost('workflows', { action: 'update_rule', id: rule.id, title: title.value.trim(), config: parsed }), 'Konfiguráció mentve.');
        if (!ok) return true;
        done();
        return false;
      } },
    ]);
  }

  /* ---------- G) SLA ---------- */
  async function renderSla(params) {
    const m = main();
    if (!needRole(BACKOFFICE, 'SLA')) return;
    const days = parseInt(params.get('days') || '90', 10) || 90;
    heading('Szerviz SLA riport', 'Emergency riasztás, határidő előtti figyelmeztetés, eszkaláció és lejárt SLA-k.');
    const daysSel = select([['7', '7 nap'], ['30', '30 nap'], ['90', '90 nap'], ['365', '365 nap']], String(days), { 'aria-label': 'Időszak' });
    daysSel.addEventListener('change', () => { location.hash = 'sla?days=' + daysSel.value; });
    const runBtn = el('button', { type: 'button', class: 'tb-btn tb-btn-primary', text: '▶ SLA ellenőrzés futtatása most', onclick: async () => {
      const res = await run(() => TB.platformPost('sla', {}));
      if (res) { const r = res.result || {}; TB.toast('Figyelmeztetés: ' + (r.warned || 0) + ', lejárt: ' + (r.breached || 0) + ', eszkalált: ' + (r.escalated || 0), 'success'); renderSla(params); }
    } });
    m.appendChild(el('div', { class: 'row' }, [daysSel, runBtn]));
    const body = el('div', { class: 'mt' });
    m.appendChild(body);
    TB.state(body, 'loading', 'Betöltés…');
    try {
      const data = await TB.platform('sla', { days });
      clear(body);
      const t = (data.report || {}).totals || {};
      body.appendChild(el('div', { class: 'grid grid-kpi' }, [
        kpi('Ticketek', String(t.total || 0)), kpi('Nyitott', String(t.open_count || 0)),
        kpi('Lejárt SLA (nyitott)', String(t.overdue_open || 0), t.overdue_open ? 'kpi-danger' : ''),
        kpi('Későn megoldott', String(t.resolved_late || 0), t.resolved_late ? 'kpi-warn' : ''),
        kpi('Eszkalált', String(t.escalated || 0)), kpi('Átlagos megoldási idő', t.avg_resolution_hours !== null && t.avg_resolution_hours !== undefined ? t.avg_resolution_hours + ' óra' : '–'),
      ]));
      body.appendChild(card('Prioritás szerinti bontás', [table(['Prioritás', 'SLA (óra)', 'Darab', 'Nyitott', 'Lejárt', 'Későn megoldott', 'Eszkalált', 'Átlag (óra)'], ((data.report || {}).by_priority || []).map((p) => [
        badge(p.priority, p.priority), num(String(p.sla_hours)), num(String(p.total)), num(String(p.open_count)), num(String(p.overdue_open), p.overdue_open ? 'neg' : ''), num(String(p.resolved_late)), num(String(p.escalated)), num(p.avg_resolution_hours === null ? '–' : String(p.avg_resolution_hours)),
      ]))]));
      const now = Date.now();
      body.appendChild(card('Nyitott ticketek SLA határidő szerint', [table(['#', 'Tárgy', 'Prioritás', 'Státusz', 'SLA határidő', 'Eszkaláció'], (data.open_tickets || []).map((tk) => {
        const overdue = tk.sla_due_at && new Date(String(tk.sla_due_at).replace(' ', 'T')).getTime() < now;
        return [String(tk.id), el('button', { type: 'button', class: 'tb-btn', text: tk.subject, onclick: () => openItem({ type: 'ticket', id: tk.id, title: tk.subject, status: tk.status }) }), badge(tk.priority, tk.priority), badge(tk.status), el('span', { class: overdue ? 'neg' : '', text: TB.date(tk.sla_due_at) + (overdue ? ' (lejárt)' : '') }), String(tk.escalation_level || 0)];
      }), { empty: 'Nincs nyitott, SLA-val rendelkező ticket.' })]));
      body.appendChild(el('p', { class: 'small muted', text: 'Beállítások (workflow szabály: service_sla_monitor): ' + JSON.stringify(data.config || {}) }));
    } catch (err) {
      TB.state(body, 'error', TB.errorMessage(err));
    }
  }

  /* ---------- I) Értékelések ---------- */
  async function renderReviews(params) {
    const m = main();
    if (!needRole(BACKOFFICE, 'Értékelések')) return;
    heading('Ügyfélértékelések moderálása', 'Publikusan csak jóváhagyott és hozzájárulással rendelkező értékelés jelenik meg. 3 csillag alatt belső follow-up feladat jön létre.');
    const f = { status: params.get('status') || '', max_rating: params.get('max_rating') || '', follow_up: params.get('follow_up') || '', q: params.get('q') || '', offset: parseInt(params.get('offset') || '0', 10) || 0 };
    const statusSel = select([['', 'Minden moderációs státusz'], ['pending', 'Függőben'], ['approved', 'Jóváhagyva'], ['rejected', 'Elutasítva']], f.status, { 'aria-label': 'Moderációs státusz' });
    const ratingSel = select([['', 'Minden értékelés'], ['2', 'Legfeljebb 2 csillag (negatív)'], ['3', 'Legfeljebb 3 csillag'], ['4', 'Legfeljebb 4 csillag']], f.max_rating, { 'aria-label': 'Csillag szűrő' });
    const followSel = select([['', 'Minden follow-up'], ['open', 'Nyitott'], ['in_progress', 'Folyamatban'], ['resolved', 'Megoldva']], f.follow_up, { 'aria-label': 'Follow-up szűrő' });
    const q = el('input', { type: 'search', value: f.q, placeholder: 'Keresés szövegben / ügyfélnévben', 'aria-label': 'Keresés az értékelésekben' });
    const filterForm = el('form', { class: 'row' }, [statusSel, ratingSel, followSel, q, el('button', { type: 'submit', class: 'tb-btn', text: 'Szűrés' })]);
    filterForm.addEventListener('submit', (e) => {
      e.preventDefault();
      location.hash = 'reviews?' + new URLSearchParams({ status: statusSel.value, max_rating: ratingSel.value, follow_up: followSel.value, q: q.value.trim() }).toString();
    });
    [statusSel, ratingSel, followSel].forEach((s) => s.addEventListener('change', () => filterForm.requestSubmit()));
    m.appendChild(filterForm);

    const reqType = select([['project', 'Projekt'], ['work_order', 'Munkalap'], ['service_ticket', 'Szerviz ticket']], 'project', { 'aria-label': 'Forrás típus' });
    const reqId = el('input', { type: 'number', min: '1', placeholder: 'Azonosító', 'aria-label': 'Forrás azonosító' });
    const reqForm = el('form', { class: 'row' }, [reqType, reqId, el('button', { type: 'submit', class: 'tb-btn', text: 'Értékeléskérés küldése' })]);
    reqForm.addEventListener('submit', (e) => { e.preventDefault(); requestReview(reqType.value, parseInt(reqId.value, 10)); });

    const body = el('div', { class: 'mt' });
    m.appendChild(body);
    TB.state(body, 'loading', 'Betöltés…');
    try {
      const data = await TB.platform('reviews', { status: f.status, max_rating: f.max_rating, follow_up: f.follow_up, q: f.q, offset: f.offset, limit: 25 });
      clear(body);
      const rs = data.request_stats || {};
      body.appendChild(card('Értékeléskérések', [el('p', { class: 'small muted', text: 'Függőben: ' + (rs.pending || 0) + ' · Kiküldve: ' + (rs.sent || 0) + ' · Kitöltve: ' + (rs.completed || 0) + ' · Sikertelen küldés: ' + (rs.send_failed || 0) + ' · Lejárt: ' + (rs.expired || 0) }), reqForm]));
      body.appendChild(card('Értékelések (' + (data.total || 0) + ')', [table(['#', 'Ügyfél', 'Csillag', 'Vélemény', 'Moderáció', 'Publikus', 'Follow-up', ''], (data.reviews || []).map((r) => {
        const follow = select([['', '–'], ['open', 'Nyitott'], ['in_progress', 'Folyamatban'], ['resolved', 'Megoldva']], r.follow_up_status || '', { 'aria-label': 'Follow-up státusz #' + r.id });
        follow.addEventListener('change', async () => { if (follow.value) await run(() => TB.platformPost('reviews', { action: 'follow_up', id: r.id, follow_up_status: follow.value }), 'Follow-up frissítve.'); });
        return [
          String(r.id), r.customer_name || '–',
          el('span', { class: Number(r.rating) < 3 ? 'neg' : '', 'aria-label': r.rating + ' csillag', text: '★'.repeat(Number(r.rating)) + '☆'.repeat(5 - Number(r.rating)) }),
          el('div', { class: 'small' }, [r.feedback || '–', r.moderation_note ? el('div', { class: 'muted', text: 'Megjegyzés: ' + r.moderation_note }) : null]),
          badge(r.moderation_status === 'approved' ? 'approved' : r.moderation_status, TB.label(r.moderation_status)),
          el('span', { class: 'small', text: (Number(r.public_visible) ? 'Igen' : 'Nem') + (Number(r.public_consent) ? '' : ' (nincs hozzájárulás)') }),
          follow,
          el('button', { type: 'button', class: 'tb-btn', text: 'Moderálás', onclick: () => moderate(r, () => renderReviews(params)) }),
        ];
      }), { empty: 'Nincs a szűrésnek megfelelő értékelés.' }),
      pager(data.total, data.limit, data.offset, (o) => { location.hash = 'reviews?' + new URLSearchParams(Object.assign({}, f, { offset: String(o) })).toString(); })]));
    } catch (err) {
      TB.state(body, 'error', TB.errorMessage(err));
    }
  }

  function moderate(r, done) {
    const status = select([['approved', 'Jóváhagyás'], ['rejected', 'Elutasítás'], ['pending', 'Függőben hagyás']], r.moderation_status === 'pending' ? 'approved' : r.moderation_status);
    const pub = el('input', { type: 'checkbox', disabled: !Number(r.public_consent) });
    pub.checked = Number(r.public_visible) === 1;
    const note = el('textarea', { maxlength: '500' });
    note.value = r.moderation_note || '';
    TB.modal('Értékelés moderálása #' + r.id, el('div', {}, [
      el('p', { class: 'small', text: r.feedback || '(nincs szöveges vélemény)' }),
      field('Döntés', status),
      el('label', { class: 'check' }, [pub, el('span', { text: Number(r.public_consent) ? 'Megjelenhet a weboldalon (csak jóváhagyás esetén)' : 'Az ügyfél nem járult hozzá a publikus megjelenéshez' })]),
      field('Belső megjegyzés', note),
    ]), [
      { label: 'Mégse' },
      { label: 'Mentés', primary: true, onClick: async () => {
        const res = await run(() => TB.platformPost('reviews', { action: 'moderate', id: r.id, moderation_status: status.value, public_visible: pub.checked, moderation_note: note.value.trim() }), 'Moderáció mentve.');
        if (!res) return true;
        done();
        return false;
      } },
    ]);
  }

  async function requestReview(sourceType, sourceId) {
    if (!(sourceId > 0)) { TB.toast('Adjon meg érvényes azonosítót.', 'error'); return; }
    try {
      await TB.platformPost('reviews', { action: 'request_review', source_type: sourceType, source_id: sourceId });
      TB.toast('Értékeléskérés elküldve az ügyfélnek.', 'success');
    } catch (err) {
      if (err.data && err.data.manual_url) {
        const input = el('input', { readonly: true, value: err.data.manual_url, 'aria-label': 'Kézi értékelési link' });
        TB.modal('E-mail küldés sikertelen', el('div', {}, [el('p', { text: err.message }), input, el('button', { type: 'button', class: 'tb-btn mt', text: 'Link másolása', onclick: () => { input.select(); (navigator.clipboard ? navigator.clipboard.writeText(input.value) : Promise.reject()).then(() => TB.toast('Link a vágólapon.', 'success')).catch(() => document.execCommand && document.execCommand('copy')); } })]));
      } else {
        TB.toast(TB.errorMessage(err), 'error');
      }
    }
  }

  /* ---------- B) Aláírások ---------- */
  async function renderSignatures(params) {
    const m = main();
    if (!needRole(BACKOFFICE, 'Aláírások')) return;
    heading('Digitális aláírások', 'Aláírt dokumentumok, e-mail másolat státusz, visszavonás / érvénytelenítés auditnaplóval.');
    const f = { status: params.get('status') || '', document_type: params.get('document_type') || '', offset: parseInt(params.get('offset') || '0', 10) || 0 };
    const statusSel = select([['', 'Minden státusz'], 'active', 'revoked', 'invalidated'], f.status, { 'aria-label': 'Státusz' });
    const typeSel = select([['', 'Minden dokumentum'], ['quote', 'Árajánlat'], ['work_order', 'Munkalap'], ['project', 'Projekt']], f.document_type, { 'aria-label': 'Dokumentum típus' });
    const apply = () => { location.hash = 'signatures?' + new URLSearchParams({ status: statusSel.value, document_type: typeSel.value }).toString(); };
    statusSel.addEventListener('change', apply);
    typeSel.addEventListener('change', apply);
    m.appendChild(el('div', { class: 'row' }, [statusSel, typeSel]));
    const body = el('div', { class: 'mt' });
    m.appendChild(body);
    TB.state(body, 'loading', 'Betöltés…');
    try {
      const data = await TB.platform('signatures', { view: 'list', status: f.status, document_type: f.document_type, limit: 25, offset: f.offset });
      clear(body);
      const rows = (data.signatures || []).map((s) => [
        String(s.id),
        el('div', {}, [(ENTITY_LABELS[s.document_type] || (s.document_type === 'quote' ? 'Árajánlat' : s.document_type)) + ' #' + s.document_id, el('div', { class: 'small muted mono', text: 'v' + s.document_version + ' · ' + String(s.document_hash || '').slice(0, 16) + '…' })]),
        el('div', {}, [s.signer_name, el('div', { class: 'small muted', text: s.signer_email })]),
        TB.date(s.signed_at),
        el('div', {}, [badge(s.status), s.revoked_reason ? el('div', { class: 'small muted', text: s.revoked_reason }) : null]),
        el('div', {}, [badge(s.email_status === 'sent' ? 'sent' : s.email_status === 'failed' ? 'failed' : 'pending', s.email_status === 'sent' ? 'Elküldve' : s.email_status === 'failed' ? 'Sikertelen' : 'Függőben'), s.email_error ? el('div', { class: 'small neg', text: s.email_error }) : null]),
        el('div', { class: 'row' }, [
          el('a', { class: 'tb-btn', href: '/api/platform.php?module=signatures&view=print&id=' + encodeURIComponent(s.id), target: '_blank', rel: 'noopener', text: 'Nyomtatás / PDF' }),
          el('button', { type: 'button', class: 'tb-btn', text: 'E-mail újraküldés', onclick: () => run(() => TB.platformPost('signatures', { action: 'resend_email', id: s.id }), 'Másolat elküldve.').then(() => renderSignatures(params)) }),
          s.status === 'active' && isSuper ? el('button', { type: 'button', class: 'tb-btn tb-btn-danger', text: 'Visszavonás', onclick: () => revokeSignature(s, () => renderSignatures(params)) }) : null,
        ]),
      ]);
      body.appendChild(card('Aláírások', [table(['#', 'Dokumentum', 'Aláíró', 'Időbélyeg', 'Státusz', 'E-mail', ''], rows, { empty: 'Még nincs aláírás.' }),
        el('div', { class: 'row mt' }, [
          el('button', { type: 'button', class: 'tb-btn', disabled: f.offset <= 0, text: '← Előző', onclick: () => { location.hash = 'signatures?' + new URLSearchParams({ status: f.status, document_type: f.document_type, offset: String(Math.max(0, f.offset - 25)) }).toString(); } }),
          el('button', { type: 'button', class: 'tb-btn', disabled: rows.length < 25, text: 'Következő →', onclick: () => { location.hash = 'signatures?' + new URLSearchParams({ status: f.status, document_type: f.document_type, offset: String(f.offset + 25) }).toString(); } }),
        ])]));
    } catch (err) {
      TB.state(body, 'error', TB.errorMessage(err));
    }
  }

  function revokeSignature(s, done) {
    const mode = select([['revoke', 'Visszavonás (az ügyfél kérésére)'], ['invalidate', 'Érvénytelenítés (hibás / jogosulatlan aláírás)']], 'revoke');
    const reason = el('textarea', { maxlength: '255', required: true });
    TB.modal('Aláírás #' + s.id + ' visszavonása', el('div', {}, [field('Művelet', mode), field('Indoklás (kötelező, auditnaplóba kerül)', reason)]), [
      { label: 'Mégse' },
      { label: 'Megerősítés', primary: true, onClick: async () => {
        if (reason.value.trim().length < 3) { TB.toast('Az indoklás kötelező.', 'error'); return true; }
        const ok = await run(() => TB.platformPost('signatures', { action: mode.value, id: s.id, reason: reason.value.trim() }), 'Aláírás státusza módosítva.');
        if (!ok) return true;
        done();
        return false;
      } },
    ]);
  }

  /* ---------- A) Mobil ütközések ---------- */
  async function renderConflicts() {
    const m = main();
    heading('Mobil szinkronizálási ütközések', 'A terepen offline rögzített, de időközben a szerveren módosult adatok. Néma felülírás nincs: döntsön tételenként.');
    const body = el('div', {});
    m.appendChild(body);
    TB.state(body, 'loading', 'Betöltés…');
    try {
      const data = await TB.platform('mobile', { view: 'conflicts' });
      clear(body);
      body.appendChild(table(['#', 'Munkalap', 'Művelet', 'Munkatárs', 'Ütközés', 'Időpont', ''], (data.conflicts || []).map((c) => {
        const payload = (c.payload || {}).data || {};
        return [
          String(c.id), '#' + c.work_order_id + ' ' + (c.work_order_title || ''),
          el('div', {}, [c.operation_type, el('div', { class: 'small muted mono', text: JSON.stringify(payload).slice(0, 160) })]),
          c.actor_name || '–',
          el('div', { class: 'small' }, [(c.conflict || {}).message || '', el('div', { class: 'muted mono', text: JSON.stringify((c.conflict || {}).server || {}) })]),
          TB.date(c.created_at),
          el('div', { class: 'row' }, [
            el('button', { type: 'button', class: 'tb-btn', text: 'Szerver adat marad', onclick: async () => { if (await run(() => TB.platformPost('mobile', { action: 'resolve_conflict', operation_id: c.id, resolution: 'keep_server' }), 'Feloldva: szerver adat megtartva.')) renderConflicts(); } }),
            el('button', { type: 'button', class: 'tb-btn tb-btn-primary', text: 'Mobil módosítás alkalmazása', onclick: async () => { if (confirm('Biztosan alkalmazza a mobil módosítást?') && await run(() => TB.platformPost('mobile', { action: 'resolve_conflict', operation_id: c.id, resolution: 'apply_mine' }), 'Feloldva: módosítás alkalmazva.')) renderConflicts(); } }),
          ]),
        ];
      }), { empty: 'Nincs feloldatlan ütközés.' }));
    } catch (err) {
      TB.state(body, 'error', TB.errorMessage(err));
    }
  }

  /* ---------- H) Számlázás ---------- */
  async function renderInvoices(params) {
    const m = main();
    if (!needRole(SUPER, 'Számlázás')) return;
    heading('Számlázó adapter', 'Billingo / Számlázz.hu előkészítés. Valódi API kulcs és TB_INVOICE_MODE=live nélkül nem készül éles számla (no-op / sandbox).');
    const status = params.get('status') || '';
    const body = el('div', {});
    m.appendChild(body);
    TB.state(body, 'loading', 'Betöltés…');
    try {
      const data = await TB.platform('invoices', { status, limit: 50 });
      clear(body);
      const p = data.provider || {};
      body.appendChild(el('div', { class: 'grid grid-kpi' }, [kpi('Szolgáltató', p.provider || 'none'), kpi('Mód', p.mode || '–', p.mode === 'live' ? 'kpi-warn' : ''), kpi('Konfigurálva', p.configured ? 'Igen' : 'Nem')]));
      const srcType = select([['order', 'Rendelés'], ['project', 'Projekt']], 'order', { 'aria-label': 'Forrás típus' });
      const srcId = el('input', { type: 'number', min: '1', placeholder: 'Azonosító', 'aria-label': 'Forrás azonosító' });
      const prep = el('form', { class: 'row' }, [srcType, srcId, el('button', { type: 'submit', class: 'tb-btn tb-btn-primary', text: 'Számla-előkészítés' })]);
      prep.addEventListener('submit', async (e) => {
        e.preventDefault();
        const res = await run(() => TB.platformPost('invoices', { action: 'prepare', source_type: srcType.value, source_id: parseInt(srcId.value, 10) || 0 }));
        if (res) { TB.toast('Tervezet #' + res.result.invoice_draft_id + ': ' + TB.label(res.result.status), 'success'); renderInvoices(params); }
      });
      const statusSel = select([['', 'Minden státusz'], 'draft', 'pending', 'sent', 'sandbox', 'failed', 'manual_required', 'manual_done', 'cancelled'], status, { 'aria-label': 'Státusz szűrő' });
      statusSel.addEventListener('change', () => { location.hash = 'invoices?status=' + encodeURIComponent(statusSel.value); });
      body.appendChild(card('Kézi számla-előkészítés', [prep]));
      body.appendChild(card('Számlatervezetek', [statusSel, table(['#', 'Forrás', 'Ügyfél', 'Összeg', 'Szolgáltató / mód', 'Státusz', 'Hiba / hivatkozás', ''], (data.drafts || []).map((d) => [
        String(d.id), (d.source_type === 'order' ? 'Rendelés' : 'Projekt') + ' #' + d.source_id,
        el('div', {}, [d.customer_name || '–', el('div', { class: 'small muted', text: d.customer_email || '' })]),
        num(TB.huf(d.total_cents)), d.provider + ' / ' + d.mode, badge(d.status),
        el('div', { class: 'small' }, [d.error_message ? el('div', { class: 'neg', text: d.error_message }) : null, d.external_id ? 'Külső azonosító: ' + d.external_id : null, d.manual_reference ? 'Kézi számla: ' + d.manual_reference : null]),
        el('div', { class: 'row' }, ['failed', 'manual_required', 'draft', 'pending'].includes(d.status) ? [
          el('button', { type: 'button', class: 'tb-btn', text: '↻ Újra', onclick: async () => { if (await run(() => TB.platformPost('invoices', { action: 'retry', id: d.id }), 'Újraküldés megtörtént.')) renderInvoices(params); } }),
          el('button', { type: 'button', class: 'tb-btn', text: 'Kézzel kiállítva', onclick: () => manualInvoice(d, () => renderInvoices(params)) }),
          d.status !== 'pending' ? el('button', { type: 'button', class: 'tb-btn tb-btn-danger', text: 'Visszavonás', onclick: async () => { if (confirm('Visszavonja a tervezetet?') && await run(() => TB.platformPost('invoices', { action: 'cancel', id: d.id }), 'Tervezet visszavonva.')) renderInvoices(params); } }) : null,
        ] : []),
      ]), { empty: 'Nincs számlatervezet.' })]));
    } catch (err) {
      TB.state(body, 'error', TB.errorMessage(err));
    }
  }

  function manualInvoice(d, done) {
    const ref = el('input', { maxlength: '120', required: true });
    TB.modal('Kézi számla rögzítése – tervezet #' + d.id, el('div', {}, [el('p', { class: 'small', text: 'API hiba esetén állítsa ki a számlát a számlázó felületén, majd rögzítse itt a sorszámot.' }), field('Számla sorszáma *', ref)]), [
      { label: 'Mégse' },
      { label: 'Mentés', primary: true, onClick: async () => {
        if (!ref.value.trim()) { TB.toast('A sorszám kötelező.', 'error'); return true; }
        const ok = await run(() => TB.platformPost('invoices', { action: 'mark_manual_done', id: d.id, manual_reference: ref.value.trim() }), 'Kézi számla rögzítve.');
        if (!ok) return true;
        done();
        return false;
      } },
    ]);
  }

  /* ---------- E) Biztonság / 2FA ---------- */
  function showRecoveryCodes(codes) {
    const text = codes.join('\n');
    TB.modal('Helyreállító kódok', el('div', {}, [
      el('p', { text: 'Mentse el biztonságos helyre! Minden kód csak egyszer használható, és most látja őket utoljára.' }),
      el('pre', { class: 'doc-preview mono', text: text }),
      el('button', { type: 'button', class: 'tb-btn', text: '⬇ Letöltés (.txt)', onclick: () => TB.downloadText('tamasbau-helyreallito-kodok.txt', text + '\n', 'text/plain;charset=utf-8') }),
    ]), [{ label: 'Elmentettem', primary: true }]);
  }

  function passwordCodeModal(title, action, onDone) {
    const pw = el('input', { type: 'password', autocomplete: 'current-password' });
    const code = el('input', { inputmode: 'numeric', autocomplete: 'one-time-code', maxlength: '6', pattern: '[0-9]{6}' });
    TB.modal(title, el('div', {}, [field('Jelenlegi jelszó', pw), field('Hitelesítő alkalmazás kódja (6 számjegy)', code)]), [
      { label: 'Mégse' },
      { label: 'Megerősítés', primary: true, onClick: async () => {
        const res = await run(() => TB.platformPost('security', { action, password: pw.value, code: code.value.trim() }));
        if (!res) return true;
        onDone(res);
        return false;
      } },
    ]);
  }

  async function renderSecurity() {
    const m = main();
    heading('Biztonság: kétlépcsős azonosítás és munkamenetek');
    const body = el('div', {});
    m.appendChild(body);
    TB.state(body, 'loading', 'Betöltés…');
    try {
      const data = await TB.platform('security', {});
      clear(body);
      const t = data.totp || {};
      if (data.setup_required) {
        body.appendChild(el('p', { class: 'card tb-state-error', role: 'alert', text: 'A biztonsági szabályzat szerint a kétlépcsős azonosítás beállítása kötelező a szerepköréhez. Amíg nem állítja be, az admin funkciók nem érhetők el.' }));
      }
      const twofa = [];
      twofa.push(el('p', {}, ['Állapot: ', Number(t.is_enabled) ? badge('ok', 'Bekapcsolva') : badge('warn', 'Kikapcsolva'), ' · Szabályzat: ', data.policy === 'required' ? 'kötelező (admin/superadmin)' : 'opcionális']));
      if (Number(t.is_enabled)) {
        twofa.push(el('p', { class: 'small muted', text: 'Bekapcsolva: ' + TB.date(t.enabled_at) + ' · Utolsó ellenőrzés: ' + TB.date(t.last_verified_at) + ' · Fel nem használt helyreállító kódok: ' + t.recovery_codes_left }));
        if (t.recovery_codes_left < 3) twofa.push(el('p', { class: 'small neg', text: 'Kevés helyreállító kód maradt – generáljon újakat!' }));
        twofa.push(el('div', { class: 'row' }, [
          el('button', { type: 'button', class: 'tb-btn', text: 'Új helyreállító kódok', onclick: () => passwordCodeModal('Új helyreállító kódok', 'regenerate_recovery_codes', (res) => { showRecoveryCodes(res.recovery_codes || []); renderSecurity(); }) }),
          el('button', { type: 'button', class: 'tb-btn tb-btn-danger', text: '2FA kikapcsolása', onclick: () => passwordCodeModal('2FA kikapcsolása', 'disable_totp', () => { TB.toast('2FA kikapcsolva.', 'success'); renderSecurity(); }) }),
        ]));
      } else {
        const setupBox = el('div', {});
        twofa.push(el('button', { type: 'button', class: 'tb-btn tb-btn-primary', text: t.pending_setup ? 'Beállítás újrakezdése' : '2FA beállítása', onclick: async () => {
          const res = await run(() => TB.platformPost('security', { action: 'setup_totp' }));
          if (!res) return;
          clear(setupBox);
          const code = el('input', { inputmode: 'numeric', autocomplete: 'one-time-code', maxlength: '6', pattern: '[0-9]{6}', required: true });
          const form = el('form', {}, [
            el('ol', {}, [
              el('li', { text: 'Telepítsen hitelesítő alkalmazást (pl. Google Authenticator, Microsoft Authenticator, Aegis, 1Password).' }),
              el('li', {}, ['Mobilon koppintson: ', el('a', { href: res.otpauth_uri, text: 'Megnyitás hitelesítő alkalmazásban' }), ', vagy adja hozzá kézzel az alábbi kulccsal (típus: időalapú, 6 számjegy, 30 mp).']),
              el('li', { text: 'Írja be az alkalmazás által mutatott 6 jegyű kódot.' }),
            ]),
            el('p', { class: 'mono doc-preview', 'aria-label': 'Titkos kulcs', text: String(res.secret).replace(/(.{4})/g, '$1 ').trim() }),
            res.app_key_configured ? null : el('p', { class: 'small neg', text: 'Figyelem: a TB_APP_KEY nincs beállítva a .env fájlban, a titkos kulcs titkosítása gyengébb. Állítsa be élesítés előtt!' }),
            field('Ellenőrző kód', code),
            el('button', { type: 'submit', class: 'tb-btn tb-btn-primary mt', text: 'Ellenőrzés és bekapcsolás' }),
          ]);
          form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const ok = await run(() => TB.platformPost('security', { action: 'confirm_totp', code: code.value.trim() }), '2FA bekapcsolva.');
            if (ok) { showRecoveryCodes(ok.recovery_codes || []); renderSecurity(); }
          });
          setupBox.appendChild(form);
          code.focus();
        } }));
        twofa.push(setupBox);
      }
      body.appendChild(card('Kétlépcsős azonosítás (TOTP)', twofa));

      body.appendChild(card('Aktív munkamenetek', [
        table(['Eszköz', 'Létrehozva', 'Utolsó aktivitás', 'Állapot', ''], (data.sessions || []).map((s) => [
          el('span', { class: 'small', text: (s.user_agent || 'Ismeretlen eszköz').slice(0, 120) }),
          TB.date(s.created_at), TB.date(s.last_seen_at),
          Number(s.is_current) ? badge('ok', 'Ez az eszköz') : Number(s.is_revoked) ? badge('revoked', 'Visszavonva') : badge('active', 'Aktív'),
          !Number(s.is_current) && !Number(s.is_revoked) ? el('button', { type: 'button', class: 'tb-btn tb-btn-danger', text: 'Kijelentkeztetés', onclick: async () => { if (await run(() => TB.platformPost('security', { action: 'revoke_session', session_id: s.id }), 'Munkamenet visszavonva.')) renderSecurity(); } }) : '',
        ])),
      ], [el('button', { type: 'button', class: 'tb-btn tb-btn-danger', text: 'Minden más eszköz kijelentkeztetése', onclick: async () => {
        if (!confirm('Minden más eszközön kijelentkezteti magát. Folytatja?')) return;
        const res = await run(() => TB.platformPost('security', { action: 'revoke_other_sessions' }));
        if (res) { TB.toast(res.revoked + ' munkamenet visszavonva.', 'success'); renderSecurity(); }
      } })]));

      body.appendChild(card('Bejelentkezési előzmények', [table(['Időpont', 'Eredmény', 'Kockázat', 'Ok', 'Eszköz'], (data.login_events || []).map((e) => [
        TB.date(e.created_at), Number(e.is_success) ? badge('ok', 'Sikeres') : badge('failed', 'Sikertelen'),
        badge(e.risk_level === 'high' ? 'error' : e.risk_level === 'medium' ? 'warn' : 'ok', { low: 'Alacsony', medium: 'Közepes', high: 'Magas' }[e.risk_level] || e.risk_level || '–'),
        e.failure_reason || '–', el('span', { class: 'small', text: (e.user_agent || '').slice(0, 100) }),
      ]), { empty: 'Nincs bejelentkezési esemény.' })]));

      if (isSuper) {
        const riskBox = el('div', {});
        body.appendChild(card('Kockázati események és admin 2FA lefedettség', [riskBox]));
        TB.state(riskBox, 'loading', 'Betöltés…');
        try {
          const risk = await TB.platform('security', { view: 'risk' });
          clear(riskBox);
          riskBox.appendChild(table(['Admin', 'Szerepkör', '2FA'], (risk.admins || []).map((a) => [a.name + ' <' + a.email + '>', a.role, Number(a.two_factor_enabled) ? badge('ok', 'Be') : badge('failed', 'Nincs')])));
          riskBox.appendChild(table(['Időpont', 'Felhasználó / e-mail', 'Eredmény', 'Kockázat', 'Ok'], (risk.events || []).map((e) => [
            TB.date(e.created_at), e.user_name || e.email_attempt || '–', Number(e.is_success) ? badge('ok', 'Sikeres') : badge('failed', 'Sikertelen'), badge(e.risk_level === 'high' ? 'error' : 'warn', e.risk_level), e.failure_reason || '–',
          ]), { empty: 'Nincs kockázati esemény.' }));
        } catch (err) {
          TB.state(riskBox, 'error', TB.errorMessage(err));
        }
      }
    } catch (err) {
      TB.state(body, 'error', TB.errorMessage(err));
    }
  }

  /* ---------- J) Diagnosztika ---------- */
  async function renderHealth() {
    const m = main();
    if (!needRole(SUPER, 'Diagnosztika')) return;
    heading('Rendszer diagnosztika', 'Adatbázis, migrációk, SMTP konfiguráció, írási jogosultságok, PHP környezet. Titkos adatot nem jelenít meg.');
    const body = el('div', {});
    m.appendChild(body);
    m.insertBefore(el('button', { type: 'button', class: 'tb-btn tb-btn-primary', text: '↻ Ellenőrzés újrafuttatása', onclick: renderHealth }), body);
    TB.state(body, 'loading', 'Ellenőrzés…');
    try {
      const data = await TB.platform('health', {});
      clear(body);
      body.appendChild(el('p', { class: 'mt' }, ['Összesített állapot: ', badge(data.status === 'ok' ? 'ok' : data.status === 'fail' ? 'error' : 'warn', { ok: 'Rendben', degraded: 'Figyelmeztetés', fail: 'Hiba' }[data.status] || data.status), ' · ', TB.date(data.checked_at)]));
      body.appendChild(table(['Ellenőrzés', 'Állapot', 'Részletek'], (data.checks || []).map((c) => [c.label, badge(c.status === 'ok' ? 'ok' : c.status === 'fail' ? 'error' : 'warn', { ok: 'OK', warn: 'Figyelem', fail: 'Hiba' }[c.status] || c.status), el('span', { class: 'small', text: c.message })])));
    } catch (err) {
      TB.state(body, 'error', TB.errorMessage(err));
    }
  }

  /* ---------- F) Globális kereső ---------- */
  let searchAbort = null;
  async function searchApi(q, type, offset, limit) {
    if (searchAbort) searchAbort.abort();
    searchAbort = new AbortController();
    const qs = new URLSearchParams({ module: 'search', q, type: type || '', offset: String(offset || 0), limit: String(limit || 5) });
    return TB.api('/api/platform.php?' + qs.toString(), { signal: searchAbort.signal });
  }

  function itemNode(item, onOpen) {
    return el('button', { type: 'button', class: 'wo-card', onclick: () => (onOpen || openItem)(item) }, [
      el('div', { class: 'row' }, [el('strong', { text: item.title || ('#' + item.id) }), el('span', { class: 'spacer' }), item.status ? badge(item.status) : null]),
      el('div', { class: 'small muted', text: '#' + item.id + (item.subtitle ? ' · ' + item.subtitle : '') + ' · ' + TB.date(item.created_at) }),
    ]);
  }

  async function renderSearch(params) {
    const m = main();
    const q = params.get('q') || '';
    const type = params.get('type') || '';
    const offset = parseInt(params.get('offset') || '0', 10) || 0;
    heading('Keresés: „' + q + '”');
    if (q.length < 2) {
      TB.state(m, 'empty', 'Adjon meg legalább 2 karaktert a kereséshez.');
      return;
    }
    const body = el('div', {});
    m.appendChild(body);
    TB.state(body, 'loading', 'Keresés…');
    try {
      const data = await searchApi(q, type, offset, type ? 20 : 5);
      clear(body);
      if (type) body.appendChild(el('a', { class: 'tb-btn', href: '#search?' + new URLSearchParams({ q }).toString(), text: '← Minden kategória' }));
      const groups = (data.groups || []).filter((g) => g.total > 0);
      if (!groups.length) {
        TB.state(body, 'empty', 'Nincs találat (jogosultsága szerint szűrve).');
        return;
      }
      groups.forEach((g) => {
        const items = el('div', {}, g.items.map((it) => itemNode(it)));
        const extra = [];
        if (!type && g.has_more) extra.push(el('a', { class: 'tb-btn', href: '#search?' + new URLSearchParams({ q, type: g.type }).toString(), text: 'Összes (' + g.total + ')' }));
        body.appendChild(card(g.label + ' (' + g.total + ')', [items, type ? pager(g.total, g.limit, g.offset, (o) => { location.hash = 'search?' + new URLSearchParams({ q, type, offset: String(o) }).toString(); }) : null], extra));
      });
    } catch (err) {
      if (err && err.name === 'AbortError') return;
      TB.state(body, 'error', TB.errorMessage(err));
    }
  }

  async function quickStatus(entity, id, status) {
    return run(() => TB.platformPost('quick', { action: 'change_status', entity, id, status }), 'Státusz módosítva: ' + TB.label(status));
  }

  function openItem(item) {
    const type = item.type;
    const content = el('div', {}, [
      el('p', {}, [el('strong', { text: item.title || '' }), ' ', item.status ? badge(item.status) : null]),
      item.subtitle ? el('p', { class: 'small muted', text: item.subtitle }) : null,
      el('p', { class: 'small muted', text: 'Azonosító: #' + item.id + ' · Létrehozva: ' + TB.date(item.created_at) }),
    ]);
    const entity = type === 'service_ticket' ? 'ticket' : type;
    if (STATUS_OPTIONS[entity] && isBackoffice) {
      const sel = select(STATUS_OPTIONS[entity], item.status);
      content.appendChild(el('div', { class: 'row' }, [field('Státusz', sel), el('button', { type: 'button', class: 'tb-btn tb-btn-primary', text: 'Státusz mentése', onclick: async () => { if (await quickStatus(entity, item.id, sel.value)) item.status = sel.value; } })]));
    }
    const links = [];
    if (type === 'project' || type === 'work_order') {
      links.push(el('button', { type: 'button', class: 'tb-btn', text: 'Költség / profit', onclick: () => profitDetail(type, item.id, item.title) }));
      links.push(el('a', { class: 'tb-btn', href: '/sign.html?type=' + type + '&id=' + encodeURIComponent(item.id) + '&back=' + encodeURIComponent('/admin-center.html'), text: '✍ Aláírás (átadás)' }));
      links.push(el('button', { type: 'button', class: 'tb-btn', text: 'Értékeléskérés', onclick: () => requestReview(type, item.id) }));
    }
    if (type === 'ticket') links.push(el('button', { type: 'button', class: 'tb-btn', text: 'Értékeléskérés', onclick: () => requestReview('service_ticket', item.id) }));
    if (type === 'quote') links.push(el('a', { class: 'tb-btn', href: '/sign.html?type=quote&id=' + encodeURIComponent(item.id) + '&back=' + encodeURIComponent('/admin-center.html'), text: '✍ Elfogadás aláírással' }));
    if ((type === 'order' || type === 'project') && isSuper) links.push(el('button', { type: 'button', class: 'tb-btn', text: 'Számla-előkészítés', onclick: async () => { const r = await run(() => TB.platformPost('invoices', { action: 'prepare', source_type: type, source_id: item.id })); if (r) TB.toast('Számlatervezet #' + r.result.invoice_draft_id + ': ' + TB.label(r.result.status), 'success'); } }));
    if (links.length) content.appendChild(el('div', { class: 'row mt' }, links));
    if ((type === 'customer' || type === 'lead') && isBackoffice) {
      const tl = el('div', { class: 'mt' });
      content.appendChild(el('h3', { class: 'mt', text: 'Kommunikációs idővonal' }));
      content.appendChild(tl);
      TB.state(tl, 'loading', 'Betöltés…');
      TB.platform('timeline', { entity_type: type === 'lead' ? 'lead' : 'user', entity_id: item.id, limit: 30 }).then((data) => {
        clear(tl);
        if (!(data.timeline || []).length) { TB.state(tl, 'empty', 'Nincs idővonal bejegyzés.'); return; }
        tl.appendChild(el('ul', { class: 'small' }, data.timeline.map((e) => el('li', {}, [el('strong', { text: TB.date(e.created_at) + ' · ' + e.title }), e.summary ? ' – ' + e.summary : '']))));
      }).catch((err) => TB.state(tl, 'error', TB.errorMessage(err)));
    }
    content.appendChild(el('p', { class: 'small muted mt' }, ['Teljes szerkesztés a ', el('a', { href: '/', text: 'fő adminfelületen' }), '.']));
    TB.modal((ENTITY_LABELS[entity] || item.type_label || 'Találat') + ' #' + item.id, content);
  }

  /* ---------- Parancspaletta ---------- */
  function quickForm(actionId) {
    const forms = {
      new_project: () => {
        const title = el('input', { maxlength: '180', required: true });
        const userId = el('input', { type: 'number', min: '1' });
        return { title: 'Új projekt', body: [field('Projekt neve *', title), field('Ügyfél azonosító (opcionális)', userId)], submit: () => TB.platformPost('quick', { action: 'new_project', title: title.value.trim(), user_id: userId.value || null }) };
      },
      new_work_order: () => {
        const title = el('input', { maxlength: '180', required: true });
        const projectId = el('input', { type: 'number', min: '1' });
        const assignee = el('input', { type: 'number', min: '1' });
        return { title: 'Új munkalap', body: [field('Munkalap megnevezése *', title), field('Projekt azonosító', projectId), field('Felelős munkatárs azonosító', assignee)], submit: () => TB.platformPost('quick', { action: 'new_work_order', title: title.value.trim(), project_id: projectId.value || null, assigned_to_user_id: assignee.value || null }) };
      },
      new_ticket: () => {
        const subject = el('input', { maxlength: '180', required: true });
        const desc = el('textarea', { maxlength: '4000' });
        const prio = select([['low', 'Alacsony'], ['normal', 'Normál'], ['high', 'Magas'], ['emergency', 'Vészhelyzet (azonnali riasztás)']], 'normal');
        const userId = el('input', { type: 'number', min: '1' });
        return { title: 'Új szerviz ticket', body: [field('Tárgy *', subject), field('Leírás', desc), field('Prioritás', prio), field('Ügyfél azonosító (opcionális)', userId)], submit: () => TB.platformPost('quick', { action: 'new_ticket', subject: subject.value.trim(), description: desc.value.trim(), priority: prio.value, user_id: userId.value || null }) };
      },
      change_status: () => {
        const entity = select(Object.keys(STATUS_OPTIONS).map((k) => [k, ENTITY_LABELS[k]]), 'project');
        const id = el('input', { type: 'number', min: '1', required: true });
        const status = select(STATUS_OPTIONS.project);
        entity.addEventListener('change', () => {
          clear(status);
          STATUS_OPTIONS[entity.value].forEach((s) => status.appendChild(el('option', { value: s, text: TB.label(s) })));
        });
        return { title: 'Státuszváltás', body: [field('Típus', entity), field('Azonosító *', id), field('Új státusz', status)], submit: () => TB.platformPost('quick', { action: 'change_status', entity: entity.value, id: parseInt(id.value, 10) || 0, status: status.value }) };
      },
    };
    const def = forms[actionId] && forms[actionId]();
    if (!def) return;
    TB.modal(def.title, el('div', {}, def.body), [
      { label: 'Mégse' },
      { label: 'Mentés', primary: true, onClick: async () => {
        const res = await run(def.submit, 'Mentve.');
        if (!res) return true;
        if (res.id && res.type) TB.toast(ENTITY_LABELS[res.type] + ' létrehozva: #' + res.id, 'success');
        return false;
      } },
    ]);
  }

  function executeAction(a) {
    if (a.kind === 'form') return quickForm(a.id);
    if (a.kind === 'nav' && a.target) { location.hash = a.target.replace(/^#/, ''); return null; }
    if (a.kind === 'link' && a.target && /^[a-z0-9_./-]+$/i.test(a.target) && !a.target.includes('//')) { location.href = '/' + a.target.replace(/^\//, ''); return null; }
    if (a.kind === 'search') { const input = $('#global-search'); input.value = ''; input.placeholder = 'Ügyfél keresése…'; input.dataset.type = a.type || ''; input.focus(); }
    return null;
  }

  function openPalette() {
    if ($('.tb-overlay .palette-input')) return;
    const input = el('input', { class: 'palette-input', type: 'text', role: 'combobox', 'aria-expanded': 'true', 'aria-controls': 'palette-list', 'aria-autocomplete': 'list', placeholder: 'Parancs vagy keresés…', autocomplete: 'off' });
    const list = el('ul', { class: 'palette-list', id: 'palette-list', role: 'listbox', 'aria-label': 'Parancsok és találatok' });
    let entries = [];
    let index = 0;
    let timer = null;
    const modal = TB.modal('Parancspaletta', el('div', {}, [input, list, el('p', { class: 'small muted', text: '↑/↓ navigáció · Enter végrehajtás · Esc bezárás' })]), []);
    const setActive = (i) => {
      index = Math.max(0, Math.min(entries.length - 1, i));
      $$('li[role="option"]', list).forEach((li, n) => li.setAttribute('aria-selected', n === index ? 'true' : 'false'));
      const active = $$('li[role="option"]', list)[index];
      if (active) { input.setAttribute('aria-activedescendant', active.id); active.scrollIntoView({ block: 'nearest' }); }
    };
    const choose = (entry) => {
      modal.close();
      if (entry.action) executeAction(entry.action);
      if (entry.item) openItem(entry.item);
      if (entry.searchAll) location.hash = 'search?' + new URLSearchParams({ q: entry.searchAll }).toString();
    };
    const draw = (searchGroups) => {
      clear(list);
      entries = [];
      const term = input.value.trim().toLowerCase();
      const acts = paletteActions.filter((a) => !term || (a.label + ' ' + (a.keywords || '')).toLowerCase().includes(term));
      if (acts.length) list.appendChild(el('li', { class: 'palette-group', role: 'presentation', text: 'Gyorsműveletek' }));
      acts.forEach((a) => entries.push({ label: a.label, action: a }));
      const addEntry = (entry) => {
        const n = list.querySelectorAll('li[role="option"]').length;
        list.appendChild(el('li', { role: 'option', id: 'pal-' + n, 'aria-selected': 'false', onclick: () => choose(entry), onmousemove: () => setActive(n) }, [entry.icon || '›', ' ', entry.label]));
      };
      entries.forEach((e) => addEntry(Object.assign(e, { icon: '⚡' })));
      (searchGroups || []).filter((g) => g.total > 0).forEach((g) => {
        list.appendChild(el('li', { class: 'palette-group', role: 'presentation', text: g.label + ' (' + g.total + ')' }));
        g.items.forEach((it) => { const e = { label: (it.title || '#' + it.id) + (it.subtitle ? ' · ' + it.subtitle : ''), item: it, icon: '🔎' }; entries.push(e); addEntry(e); });
      });
      if (term.length >= 2) {
        const e = { label: 'Összes találat: „' + input.value.trim() + '”', searchAll: input.value.trim(), icon: '↵' };
        entries.push(e);
        addEntry(e);
      }
      if (!entries.length) list.appendChild(el('li', { class: 'palette-group', role: 'presentation', text: 'Nincs találat.' }));
      setActive(0);
    };
    input.addEventListener('input', () => {
      draw();
      clearTimeout(timer);
      const term = input.value.trim();
      if (term.length < 2) return;
      timer = setTimeout(async () => {
        list.setAttribute('aria-busy', 'true');
        try {
          const data = await searchApi(term, '', 0, 4);
          if (input.value.trim() === term) draw(data.groups);
        } catch (err) {
          if (!err || err.name !== 'AbortError') list.appendChild(el('li', { class: 'palette-group', role: 'presentation', text: '⚠ ' + TB.errorMessage(err) }));
        } finally {
          list.removeAttribute('aria-busy');
        }
      }, 250);
    });
    input.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowDown') { e.preventDefault(); setActive(index + 1); }
      if (e.key === 'ArrowUp') { e.preventDefault(); setActive(index - 1); }
      if (e.key === 'Enter') { e.preventDefault(); if (entries[index]) choose(entries[index]); }
    });
    draw();
    input.focus();
  }

  /* ---------- Útvonalak ---------- */
  function route() {
    const raw = (location.hash || '').replace(/^#/, '');
    const [name, query] = raw.split('?');
    const params = new URLSearchParams(query || '');
    let section = SECTIONS.includes(name) ? name : (isBackoffice ? 'profit' : 'security');
    if (TB.sessionInfo && TB.sessionInfo.two_factor_setup_required) section = 'security';
    $$('.tb-nav a[data-section]').forEach((a) => {
      if (a.dataset.section === section) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
    });
    const renderers = { profit: renderProfit, workflows: renderWorkflows, sla: renderSla, reviews: renderReviews, signatures: renderSignatures, conflicts: renderConflicts, invoices: renderInvoices, security: renderSecurity, health: renderHealth, search: renderSearch };
    Promise.resolve(renderers[section](params)).finally(() => {
      const h = $('#main h1');
      if (h && document.activeElement && !document.activeElement.closest('.tb-overlay') && document.activeElement.id !== 'global-search') h.focus({ preventScroll: false });
    });
  }

  async function init() {
    try {
      user = await TB.session(true);
    } catch (e) {
      user = null;
    }
    if (!user) {
      const m = main();
      m.appendChild(el('div', { class: 'card' }, [el('h1', { text: 'Bejelentkezés szükséges' }), el('p', { text: 'Az admin központ használatához jelentkezzen be.' }), el('a', { class: 'tb-btn tb-btn-primary', href: '/', text: 'Bejelentkezés' })]));
      return;
    }
    isBackoffice = BACKOFFICE.includes(user.role);
    isSuper = SUPER.includes(user.role);
    $('#who').textContent = user.name + ' (' + user.role + ')';
    if (!isBackoffice) {
      $$('.tb-nav a[data-section]').forEach((a) => { if (a.dataset.section !== 'security') a.classList.add('hidden'); });
      $('#search-form').classList.add('hidden');
      $('#palette-btn').classList.add('hidden');
    }
    if (!isSuper) {
      $$('.tb-nav a[data-section="invoices"], .tb-nav a[data-section="health"]').forEach((a) => a.classList.add('hidden'));
    }
    TB.platform('palette', {}).then((d) => { paletteActions = d.actions || []; }).catch(() => {});

    $('#search-form').addEventListener('submit', (e) => {
      e.preventDefault();
      const input = $('#global-search');
      const q = input.value.trim();
      const params = { q };
      if (input.dataset.type) params.type = input.dataset.type;
      input.dataset.type = '';
      input.placeholder = 'Keresés: ügyfél, rendelés, projekt, ticket… ( / )';
      location.hash = 'search?' + new URLSearchParams(params).toString();
    });
    $('#palette-btn').addEventListener('click', openPalette);
    document.addEventListener('keydown', (e) => {
      const typing = /^(INPUT|TEXTAREA|SELECT)$/.test((e.target && e.target.tagName) || '') || (e.target && e.target.isContentEditable);
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k' && isBackoffice) { e.preventDefault(); openPalette(); }
      else if (e.key === '/' && !typing && isBackoffice && !$('.tb-overlay')) { e.preventDefault(); $('#global-search').focus(); }
    });
    window.addEventListener('hashchange', route);
    route();
  }

  init();
})();
