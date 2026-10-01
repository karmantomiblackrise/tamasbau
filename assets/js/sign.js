/* Digitális aláírás oldal: sign.html?type=quote|work_order|project&id=123[&back=/mobile.html] */
(function () {
  'use strict';
  const { el, $, clear } = TB;

  const DECLARATIONS = {
    quote: 'Az árajánlatot megismertem, annak tartalmát és feltételeit elfogadom, a munka elvégzését megrendelem.',
    work_order: 'A munkalapon szereplő munkát a helyszínen ellenőriztem, a teljesítést igazolom, a felhasznált anyagokat és a munkaidőt elfogadom.',
    project: 'A projektet a fenti tartalommal átvettem, az átadás-átvételt hiánytalannak és rendben lévőnek igazolom.',
  };

  const params = new URLSearchParams(location.search);
  const type = params.get('type') || '';
  const id = parseInt(params.get('id') || '0', 10);
  const back = params.get('back');
  const BACK_TARGETS = ['/', '/mobile.html', '/admin-center.html', '/index.html'];
  const safeBack = back && (BACK_TARGETS.includes(back) || /^\/mobile\.html#\/wo\/\d+$/.test(back)) ? back : '/';
  $('#back-link').href = safeBack;
  $('#done-back').href = safeBack;

  let documentHash = '';
  let pad = null;

  function renderSnapshot(snapshot) {
    const box = clear($('#doc-preview'));
    const add = (label, value) => {
      if (value === null || value === undefined || value === '') return;
      box.appendChild(el('p', {}, [el('strong', { text: label + ': ' }), String(value)]));
    };
    add('Megnevezés', snapshot.title);
    add('Helyszín / cím', snapshot.location || snapshot.address);
    add('Leírás', snapshot.description || snapshot.body);
    if (snapshot.amount_cents !== undefined && snapshot.amount_cents !== null) add('Összeg', TB.huf(snapshot.amount_cents) + ' (' + (snapshot.currency || 'HUF') + ')');
    add('Érvényes', snapshot.valid_until);
    if (snapshot.actual_minutes) add('Ledolgozott idő', Math.round(snapshot.actual_minutes / 6) / 10 + ' óra');
    if (Array.isArray(snapshot.checklist) && snapshot.checklist.length) {
      box.appendChild(el('strong', { text: 'Ellenőrzőlista:' }));
      box.appendChild(el('ul', {}, snapshot.checklist.map((c) => el('li', { text: (c.done ? '☑ ' : '☐ ') + c.item }))));
    }
    if (Array.isArray(snapshot.materials_and_work) && snapshot.materials_and_work.length) {
      box.appendChild(el('strong', { text: 'Anyagok és munkadíj:' }));
      box.appendChild(el('ul', {}, snapshot.materials_and_work.map((m) => el('li', { text: m.title + ' – ' + m.quantity + ' ' + (m.unit || '') }))));
    }
    if (Array.isArray(snapshot.work_orders) && snapshot.work_orders.length) {
      box.appendChild(el('strong', { text: 'Munkalapok:' }));
      box.appendChild(el('ul', {}, snapshot.work_orders.map((w) => el('li', { text: '#' + w.id + ' ' + w.title + ' – ' + TB.label(w.status) }))));
    }
  }

  async function load() {
    const root = $('#sign-root');
    if (!['quote', 'work_order', 'project'].includes(type) || !id) {
      TB.state(root, 'error', 'Hiányzó vagy érvénytelen dokumentum hivatkozás.');
      return;
    }
    const user = await TB.session(true).catch(() => null);
    if (!user) {
      clear(root).appendChild(el('div', { class: 'card' }, [
        el('h1', { text: 'Bejelentkezés szükséges' }),
        el('p', { text: 'Az aláíráshoz kérjük, jelentkezzen be fiókjába, majd nyissa meg újra ezt a linket.' }),
        el('a', { class: 'tb-btn tb-btn-primary', href: '/', text: 'Bejelentkezés' }),
      ]));
      return;
    }
    try {
      const data = await TB.platform('signatures', { view: 'document', document_type: type, document_id: id });
      const doc = data.document;
      documentHash = doc.hash;
      $('#doc-title').textContent = (doc.label || 'Dokumentum') + ' – #' + doc.id;
      $('#doc-version').textContent = doc.version;
      $('#doc-hash').textContent = doc.hash;
      renderSnapshot(doc.snapshot || {});
      $('#signer-name').value = (doc.default_signer && doc.default_signer.name) || '';
      $('#signer-email').value = (doc.default_signer && doc.default_signer.email) || '';
      $('#declaration').value = DECLARATIONS[type];
      const active = (data.signatures || []).filter((s) => s.status === 'active');
      if (active.length) {
        $('#existing-signatures').textContent = 'Korábbi érvényes aláírás: ' + active.map((s) => s.signer_name + ' (' + TB.date(s.signed_at) + ')' + (s.document_hash === doc.hash ? ' – ez a verzió már alá van írva' : '')).join('; ');
      }
      clear(root);
      $('#sign-form').classList.remove('hidden');
      pad = new SignaturePad($('#sig-pad'), (empty) => $('#sig-hint').classList.toggle('hidden', !empty));
    } catch (err) {
      TB.state(root, 'error', TB.errorMessage(err));
    }
  }

  $('#sig-clear').addEventListener('click', () => pad && pad.clear());
  $('#sig-type').addEventListener('click', () => {
    const name = $('#signer-name').value.trim();
    if (!name) {
      TB.toast('Előbb adja meg a nevet.', 'warn');
      $('#signer-name').focus();
      return;
    }
    pad.typeName(name);
  });

  $('#sign-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const errorBox = $('#sign-error');
    errorBox.classList.add('hidden');
    const name = $('#signer-name').value.trim();
    const email = $('#signer-email').value.trim();
    const problems = [];
    if (name.length < 2) problems.push('adja meg a nevét');
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) problems.push('adjon meg érvényes e-mail címet');
    if (!$('#declaration-accepted').checked) problems.push('fogadja el a nyilatkozatot');
    if (!pad || pad.isEmpty()) problems.push('írja alá a dokumentumot');
    if (problems.length) {
      errorBox.textContent = 'Kérjük, ' + problems.join(', ') + '.';
      errorBox.classList.remove('hidden');
      errorBox.focus && errorBox.focus();
      return;
    }
    const button = $('#sign-submit');
    button.disabled = true;
    button.textContent = 'Mentés folyamatban…';
    try {
      const ipMode = (document.querySelector('input[name="ip-mode"]:checked') || {}).value || 'hash';
      const res = await TB.platformPost('signatures', {
        action: 'create',
        document_type: type,
        document_id: id,
        document_hash: documentHash,
        signer_name: name,
        signer_email: email,
        signer_role: $('#signer-role').value,
        declaration_text: $('#declaration').value.trim(),
        declaration_accepted: true,
        ip_capture_mode: ipMode,
        signature_png: pad.toPNG(),
      });
      $('#sign-form').classList.add('hidden');
      const mailText = res.email_status === 'sent'
        ? 'A dokumentum másolatát elküldtük a(z) ' + email + ' címre.'
        : 'Az e-mail másolat küldése nem sikerült (' + (res.email_error || 'ismeretlen hiba') + '). A dokumentum alább megnyitható, nyomtatható vagy PDF-be menthető; az iroda később újraküldheti.';
      $('#done-text').textContent = 'Aláírás azonosító: #' + res.id + '. ' + mailText;
      $('#print-link').href = '/api/platform.php?module=signatures&view=print&id=' + encodeURIComponent(res.id);
      $('#sign-done').classList.remove('hidden');
      $('#sign-done').focus();
    } catch (err) {
      errorBox.textContent = err.status === 409
        ? err.message + ' Az oldal újratöltése után ellenőrizze a friss tartalmat.'
        : TB.errorMessage(err);
      errorBox.classList.remove('hidden');
    } finally {
      button.disabled = false;
      button.textContent = 'Aláírás véglegesítése';
    }
  });

  TB.registerServiceWorker();
  load();
})();
