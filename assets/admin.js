/* Админка MaxBoom Shop */
(function () {
  'use strict';
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];
  const fmt = n => Math.round(n).toLocaleString('ru-RU') + ' ₽';

  async function api(action, data) {
    const fd = new FormData();
    fd.append('csrf', window.MB_CSRF);
    for (const [k, v] of Object.entries(data || {})) {
      if (Array.isArray(v)) v.forEach(x => fd.append(k + '[]', x)); else fd.append(k, v);
    }
    const r = await fetch('?p=api&do=' + action, { method: 'POST', body: fd });
    let d = {};
    try { d = await r.json(); } catch (e) { d = { error: 'Ошибка сервера (HTTP ' + r.status + ')' }; }
    if (!r.ok || d.error) throw new Error(d.error || 'Ошибка');
    return d;
  }

  let tt;
  function toast(msg, bad) {
    const t = $('#toast'); t.textContent = msg; t.classList.toggle('bad', !!bad); t.classList.add('show');
    clearTimeout(tt); tt = setTimeout(() => t.classList.remove('show'), 3000);
  }

  function roundPrice(v) {
    switch (window.MB_ROUND) {
      case '10': return Math.max(10, Math.round(v / 10) * 10);
      case '50': return Math.max(50, Math.round(v / 50) * 50);
      case '100': return Math.max(100, Math.round(v / 100) * 100);
      case '9': { let r = Math.floor(v / 10) * 10 + 9; return r > v + 5 ? r - 10 : r; }
      default: return Math.round(v);
    }
  }

  /* ---------- Общая скидка ---------- */
  const range = $('#discRange'), inp = $('#discInput');
  if (range) {
    const prev = $('.disc-preview');
    const upd = v => { if (prev) $('#discPreview').textContent = fmt(roundPrice(+prev.dataset.ozon * (1 - v / 100))); };
    range.addEventListener('input', () => { inp.value = range.value; upd(range.value); });
    inp.addEventListener('input', () => { range.value = inp.value; upd(inp.value); });
    $('#discSave').addEventListener('click', async () => {
      try { await api('discount', { value: inp.value }); toast('Скидка ' + inp.value + '% применена ко всем товарам без ручной цены'); }
      catch (e) { toast(e.message, true); }
    });
  }

  /* ---------- Синхронизация ---------- */
  const sb = $('#syncBtn');
  if (sb) sb.addEventListener('click', async () => {
    const out = $('#syncResult');
    sb.disabled = true; sb.textContent = '⟳ Загружаю из Ozon…'; out.className = 'sync-result'; out.textContent = 'Это может занять минуту для большого каталога.';
    try { const d = await api('sync'); out.className = 'sync-result ok'; out.textContent = d.message; setTimeout(() => location.reload(), 1500); }
    catch (e) { out.className = 'sync-result bad'; out.textContent = e.message; }
    sb.disabled = false; sb.textContent = '⟳ Обновить сейчас';
  });

  /* ---------- Таблица товаров ---------- */
  const table = $('#prodTable');
  if (table) {
    table.addEventListener('change', async e => {
      const el = e.target;
      const field = el.dataset.field; if (!field) return;
      const tr = el.closest('tr');
      const value = el.type === 'checkbox' ? (el.checked ? '0' : '1') : el.value; // переключатель = «показывать»
      try {
        const d = await api('product', { id: tr.dataset.id, field, value });
        if (el.classList.contains('cell')) { el.classList.remove('err'); el.classList.add('saved'); setTimeout(() => el.classList.remove('saved'), 1200); }
        $('.site-price', tr).textContent = d.price;
        if (field === 'manual_price') { el.classList.toggle('is-manual', d.manual); if (!d.manual) el.value = ''; }
        if (field === 'hidden') tr.classList.toggle('is-hidden', !el.checked);
      } catch (err) { el.classList.add('err'); toast(err.message, true); }
    });
    table.addEventListener('keydown', e => { if (e.key === 'Enter' && e.target.classList.contains('cell')) { e.preventDefault(); e.target.blur(); } });

    const all = $('#checkAll');
    const sel = () => $$('.rowcheck:checked').map(c => c.value);
    const upd = () => { $('#bulkSel').textContent = 'Выбрано: ' + sel().length; };
    all.addEventListener('change', () => { $$('.rowcheck').forEach(c => c.checked = all.checked); upd(); });
    table.addEventListener('change', e => { if (e.target.classList.contains('rowcheck')) upd(); });

    $$('[data-bulk]').forEach(b => b.addEventListener('click', async () => {
      const act = b.dataset.bulk;
      const ids = sel();
      if (act === 'reset_all') { if (!confirm('Сбросить ручные цены у ВСЕХ товаров? Цены станут: Ozon − общая скидка.')) return; }
      else if (!ids.length) return toast('Отметьте товары галочками', true);
      const data = { act, ids };
      if (act === 'percent') {
        const p = $('#bulkPercent').value;
        if (p === '') return toast('Укажите % скидки для выбранных', true);
        data.percent = p;
      }
      try { const d = await api('bulk', data); toast(act === 'reset_all' ? 'Сброшено: ' + d.n : 'Готово'); setTimeout(() => location.reload(), 600); }
      catch (e) { toast(e.message, true); }
    }));
  }

  /* ---------- Категории ---------- */
  const ct = $('#catTable');
  if (ct) {
    ct.addEventListener('change', async e => {
      const el = e.target, field = el.dataset.field; if (!field) return;
      const tr = el.closest('tr');
      const value = el.type === 'checkbox' ? (el.checked ? '0' : '1') : el.value;
      try {
        await api('category', { id: tr.dataset.id, field, value });
        if (el.classList.contains('cell')) { el.classList.add('saved'); setTimeout(() => el.classList.remove('saved'), 1200); }
        if (field === 'hidden') tr.classList.toggle('is-hidden', !el.checked);
        if (field === 'off_sale') { tr.classList.toggle('is-off', !el.checked); toast(el.checked ? 'Категория снова в продаже' : 'Категория снята с продажи'); }
      } catch (err) { el.classList.add('err'); toast(err.message, true); }
    });
    ct.addEventListener('keydown', e => { if (e.key === 'Enter' && e.target.classList.contains('cell')) { e.preventDefault(); e.target.blur(); } });
  }

  /* ---------- Покупатели ---------- */
  const ut = $('#userTable');
  if (ut) {
    ut.addEventListener('change', async e => {
      const el = e.target, field = el.dataset.field; if (!field) return;
      const id = el.closest('[data-id]').dataset.id;
      try { await api('user', { id, field, value: el.value }); el.classList.add('saved'); setTimeout(() => el.classList.remove('saved'), 1200); toast(field === 'discount' ? 'Скидка сохранена' : 'Сохранено'); }
      catch (err) { el.classList.add('err'); toast(err.message, true); }
    });
    ut.addEventListener('keydown', e => { if (e.key === 'Enter' && e.target.classList.contains('cell')) { e.preventDefault(); e.target.blur(); } });
  }

  /* ---------- Промокоды ---------- */
  const cpt = $('#couponTable');
  if (cpt) cpt.addEventListener('change', async e => {
    if (!e.target.classList.contains('ctoggle')) return;
    const tr = e.target.closest('tr');
    try { await api('coupon_toggle', { id: tr.dataset.id }); tr.classList.toggle('is-hidden', !e.target.checked); }
    catch (err) { toast(err.message, true); }
  });

  /* ---------- Отзывы ---------- */
  const ra = $('#reviewAdmin');
  if (ra) ra.addEventListener('click', async e => {
    const b = e.target.closest('[data-st]'); if (!b) return;
    const box = b.closest('[data-id]');
    const st = b.dataset.st;
    if (st === 'delete' && !confirm('Удалить отзыв насовсем?')) return;
    const data = { id: box.dataset.id, reply: $('.ra-reply', box).value };
    if (st !== 'reply') data.status = st;
    try {
      await api('review', data);
      if (st === 'reply') toast('Ответ сохранён');
      else { box.style.opacity = .35; toast(st === 'approved' ? 'Опубликован' : st === 'rejected' ? 'Отклонён' : 'Удалён'); setTimeout(() => box.remove(), 600); }
    } catch (err) { toast(err.message, true); }
  });

  /* ---------- Заказ ---------- */
  const os = $('#orderSave');
  if (os) os.addEventListener('click', async () => {
    try {
      const d = await api('order_status', { id: $('#orderStatus').dataset.id, status: $('#orderStatus').value, note: $('#orderNote').value, notify: $('#orderNotify') && $('#orderNotify').checked ? '1' : '' });
      toast(d.notified ? 'Сохранено, покупатель уведомлён' : 'Сохранено');
    } catch (e) { toast(e.message, true); }
  });

  /* ---------- Наборы ---------- */
  const bt = $('#bundleTable');
  if (bt) {
    bt.addEventListener('change', async e => {
      const tr = e.target.closest('tr'); if (!tr) return;
      try {
        if (e.target.classList.contains('btoggle')) { await api('bundle', { id: tr.dataset.id, act: 'toggle' }); tr.classList.toggle('is-hidden', !e.target.checked); }
        if (e.target.classList.contains('bpct')) { await api('bundle', { id: tr.dataset.id, act: 'percent', value: e.target.value }); e.target.classList.add('saved'); setTimeout(() => e.target.classList.remove('saved'), 1200); }
      } catch (err) { toast(err.message, true); }
    });
    bt.addEventListener('click', async e => {
      if (!e.target.classList.contains('bdel')) return;
      if (!confirm('Удалить набор?')) return;
      const tr = e.target.closest('tr');
      try { await api('bundle', { id: tr.dataset.id, act: 'delete' }); tr.remove(); } catch (err) { toast(err.message, true); }
    });
  }

  /* ---------- Сертификаты ---------- */
  const gt = $('#giftTable');
  if (gt) gt.addEventListener('click', async e => {
    if (!e.target.classList.contains('gtoggle')) return;
    const tr = e.target.closest('tr');
    try { await api('gift', { id: tr.dataset.id }); location.reload(); } catch (err) { toast(err.message, true); }
  });

  /* ---------- Баллы покупателя ---------- */
  const bs = $('#bonusSave');
  if (bs) bs.addEventListener('click', async () => {
    try {
      const d = await api('bonus_adjust', { id: $('#bonusPanel').dataset.id, amount: $('#bonusAmt').value, reason: $('#bonusWhy').value });
      $('#bonusBal').textContent = d.balance; toast('Готово'); setTimeout(() => location.reload(), 700);
    } catch (e) { toast(e.message, true); }
  });

  /* ---------- Отзывы с Ozon ---------- */
  const ri = $('#revImport');
  if (ri) ri.addEventListener('click', async () => {
    ri.disabled = true; ri.textContent = 'Загружаю…';
    try { const d = await api('reviews_import'); toast(d.message); setTimeout(() => location.href = '?p=reviews&f=approved', 1200); }
    catch (e) { toast(e.message, true); ri.disabled = false; ri.textContent = 'Загрузить отзывы 4–5★ с Ozon'; }
  });

  /* ---------- Telegram webhook, кэш ---------- */
  const tw = $('#tgWebhook');
  if (tw) tw.addEventListener('click', async () => { try { await api('tg_webhook'); toast('Webhook подключён — бот отвечает мгновенно'); } catch (e) { toast(e.message, true); } });
  const cc = $('#cacheClear');
  if (cc) cc.addEventListener('click', async () => { try { await api('cache_clear'); toast('Кэш очищен'); } catch (e) { toast(e.message, true); } });

  /* ---------- Настройки ---------- */
  const ps = $('#priceSample');
  if (ps) ps.addEventListener('click', async () => {
    const out = $('#priceSampleOut'); out.hidden = false; out.textContent = 'Запрос к Ozon… (сначала сохраните ключи)';
    try {
      const d = await api('price_sample');
      out.textContent = d.items.map(i => 'Артикул ' + i.offer_id + '\n' + Object.entries(i.price).filter(([k]) => /price/.test(k)).map(([k, v]) => '  ' + k + ': ' + v).join('\n')).join('\n\n') || 'Товаров нет';
    } catch (e) { out.textContent = e.message; }
  });
  const tg = $('#tgTest');
  if (tg) tg.addEventListener('click', async () => {
    try { await api('tg_test'); toast('Отправлено (сначала сохраните настройки)'); } catch (e) { toast(e.message, true); }
  });
})();
