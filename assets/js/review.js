/* Tokenes ügyfélértékelő oldal: review.html?token=... */
(function () {
  'use strict';
  const { el, $, clear } = TB;
  const token = new URLSearchParams(location.search).get('token') || '';

  function done(title, text) {
    const root = clear($('#review-root'));
    root.appendChild(el('div', { class: 'card', tabindex: '-1' }, [el('h1', { text: title }), el('p', { text: text }), el('a', { class: 'tb-btn', href: '/', text: 'Tovább a weboldalra' })]));
    root.firstChild.focus();
  }

  async function load() {
    if (!/^[a-f0-9]{16,64}$/i.test(token)) {
      TB.state($('#review-root'), 'error', 'Hiányzó vagy érvénytelen értékelő link.');
      return;
    }
    try {
      const data = await TB.api('/api/review.php?action=request&token=' + encodeURIComponent(token));
      const r = data.request || {};
      clear($('#review-root'));
      $('#review-intro').textContent = (r.recipient_first_name ? 'Kedves ' + r.recipient_first_name + '! ' : '') + 'Kérjük, értékelje a(z) ' + (r.source_label || 'munka') + ' során nyújtott szolgáltatásunkat. A link érvényes: ' + TB.date(r.expires_at) + '.';
      $('#review-form').classList.remove('hidden');
    } catch (err) {
      TB.state($('#review-root'), 'error', TB.errorMessage(err));
    }
  }

  $('#review-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const errorBox = $('#review-error');
    errorBox.classList.add('hidden');
    const checked = document.querySelector('input[name="rating"]:checked');
    if (!checked) {
      errorBox.textContent = 'Kérjük, válasszon 1 és 5 csillag között.';
      errorBox.classList.remove('hidden');
      $('#star5').focus();
      return;
    }
    const button = $('#review-submit');
    button.disabled = true;
    try {
      await TB.api('/api/review.php?action=submit', { method: 'POST', body: { action: 'submit', token, rating: parseInt(checked.value, 10), feedback: $('#feedback').value.trim(), public_consent: $('#consent').checked } });
      $('#review-form').classList.add('hidden');
      done('Köszönjük az értékelést!', parseInt(checked.value, 10) < 3
        ? 'Sajnáljuk, hogy nem volt teljesen elégedett. Kollégánk hamarosan felveszi Önnel a kapcsolatot a probléma rendezése érdekében.'
        : 'Visszajelzése segít bennünket abban, hogy még jobb szolgáltatást nyújtsunk.');
    } catch (err) {
      errorBox.textContent = TB.errorMessage(err);
      errorBox.classList.remove('hidden');
    } finally {
      button.disabled = false;
    }
  });

  load();
})();
