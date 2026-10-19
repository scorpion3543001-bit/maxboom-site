/* MaxBoom Shop — витрина: корзина (в браузере), подсказки поиска, подгрузка каталога. */
(function () {
  'use strict';
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];
  const fmt = n => Math.round(n).toLocaleString('ru-RU').replace(/,/g, ' ') + ' ₽';
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  /* ---------- Корзина ---------- */
  const KEY = 'mb_cart';
  const load = () => { try { return JSON.parse(localStorage.getItem(KEY)) || []; } catch (e) { return []; } };
  const save = c => { try { localStorage.setItem(KEY, JSON.stringify(c)); } catch (e) {} updateCount(true); };

  function updateCount(bump) {
    const el = $('#cartCount');
    if (!el) return;
    const n = load().reduce((s, i) => s + i.qty, 0);
    el.textContent = n > 99 ? '99+' : n;
    el.hidden = n === 0;
    if (bump && n) { el.classList.remove('bump'); void el.offsetWidth; el.classList.add('bump'); }
  }

  function add(item, qty) {
    const c = load();
    const ex = c.find(i => i.id === item.id);
    if (ex) { ex.qty = Math.min(99, ex.qty + qty); Object.assign(ex, { price: item.price, name: item.name, img: item.img }); }
    else c.push({ ...item, qty });
    save(c);
  }

  let toastT;
  function toast(msg, bad) {
    const t = $('#toast');
    if (!t) return;
    t.innerHTML = msg;
    t.classList.toggle('bad', !!bad);
    t.classList.add('show');
    clearTimeout(toastT);
    toastT = setTimeout(() => t.classList.remove('show'), 2600);
  }

  document.addEventListener('click', e => {
    const b = e.target.closest('.btn-add');
    if (!b) return;
    const qtyIn = b.hasAttribute('data-with-qty') ? $('#qtyInput') : null;
    const qty = Math.max(1, Math.min(99, parseInt(qtyIn?.value || '1', 10) || 1));
    add({ id: +b.dataset.id, name: b.dataset.name, price: +b.dataset.price, img: b.dataset.img, code: b.dataset.code }, qty);
    track('add', [{ id: b.dataset.code || b.dataset.id, name: b.dataset.name, price: +b.dataset.price, quantity: qty }]);
    flyToCart(b);
    const old = b.textContent;
    b.classList.add('is-added'); b.textContent = '✓ В корзине';
    setTimeout(() => { b.classList.remove('is-added'); b.textContent = old; }, 1400);
    toast('Добавлено в корзину · <a href="cart.php" style="text-decoration:underline">Оформить</a>');
  });

  /* ---------- Аналитика (Яндекс Метрика: e-commerce и цели) ---------- */
  function track(kind, products) {
    try {
      window.dataLayer = window.dataLayer || [];
      dataLayer.push({ ecommerce: { currencyCode: 'RUB', [kind]: { products } } });
      if (window.MB && MB.metrika && window.ym && kind === 'add') ym(MB.metrika, 'reachGoal', 'cart');
    } catch (e) {}
  }
  function goal(name) { try { if (window.MB && MB.metrika && window.ym) ym(MB.metrika, 'reachGoal', name); } catch (e) {} }

  /* ---------- Фото: если уменьшенной копии нет — показываем оригинал ---------- */
  document.addEventListener('error', e => {
    const im = e.target;
    if (im.tagName !== 'IMG' || im.dataset.fb) return;
    const orig = im.src.replace(/\/wc\d+\//, '/');
    if (orig !== im.src) { im.dataset.fb = 1; im.src = orig; }
  }, true);

  /* ---------- Избранное ---------- */
  const FAV = 'mb_fav';
  const favLoad = () => { try { return (JSON.parse(localStorage.getItem(FAV)) || []).map(Number); } catch (e) { return []; } };
  const favSave = ids => { try { localStorage.setItem(FAV, JSON.stringify([...new Set(ids)].slice(0, 300))); } catch (e) {} favPaint(); };
  function favPaint() {
    const ids = new Set(favLoad());
    $$('[data-fav]').forEach(b => { const on = ids.has(+b.dataset.fav); b.classList.toggle('is-on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); b.title = on ? 'Убрать из избранного' : 'В избранное'; });
    const c = $('#favCount'); if (c) { c.textContent = ids.size > 99 ? '99+' : ids.size; c.hidden = ids.size === 0; }
  }
  document.addEventListener('click', async e => {
    const b = e.target.closest('[data-fav]');
    if (!b) return;
    e.preventDefault();
    const id = +b.dataset.fav, ids = favLoad(), on = !ids.includes(id);
    favSave(on ? [id, ...ids] : ids.filter(x => x !== id));
    if (on) { b.classList.remove('pop'); void b.offsetWidth; b.classList.add('pop'); toast('Добавлено в <a href="favorites.php" style="text-decoration:underline">избранное</a>'); goal('fav'); }
    if (window.MB && MB.user) fetch('api.php?action=fav', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id, on }) }).catch(() => {});
    if (!on && $('#favGrid')) { b.closest('.card')?.remove(); if (!$('#favGrid .card')) $('#favEmpty').hidden = false; }
  });
  async function favInit() {
    if (window.MB && MB.user) {
      try {
        const r = await fetch('api.php?action=fav', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ merge: favLoad() }) });
        const d = await r.json(); if (Array.isArray(d.ids)) favSave(d.ids);
      } catch (e) {}
    }
    favPaint();
    const g = $('#favGrid');
    if (g) {
      const ids = favLoad();
      if (!ids.length) { $('#favEmpty').hidden = false; return; }
      const d = await (await fetch('api.php?action=cards&ids=' + ids.join(','))).json();
      g.innerHTML = d.html; $('#favEmpty').hidden = !!d.html; favPaint();
    }
  }
  favInit();

  /* ---------- Вы смотрели ---------- */
  const SEEN = 'mb_seen';
  (async () => {
    let seen = []; try { seen = (JSON.parse(localStorage.getItem(SEEN)) || []).map(Number); } catch (e) {}
    const cur = $('[data-seen]');
    const curId = cur ? +cur.dataset.seen : 0;
    if (curId) {
      try { localStorage.setItem(SEEN, JSON.stringify([curId, ...seen.filter(x => x !== curId)].slice(0, 24))); } catch (e) {}
      try { const p = JSON.parse(cur.dataset.ecom || 'null'); if (p) track('detail', [p]); } catch (e) {}
    }
    const shelf = $('#seenShelf');
    const ids = seen.filter(x => x !== curId).slice(0, 12);
    if (!shelf || ids.length < 2) return;
    try {
      const d = await (await fetch('api.php?action=cards&ids=' + ids.join(','))).json();
      if (d.ids && d.ids.length >= 2) { shelf.innerHTML = d.html; $('#seenBlock').hidden = false; favPaint(); }
    } catch (e) {}
  })();

  /* ---------- Наборы и «Часто покупают вместе» ---------- */
  document.addEventListener('click', e => {
    const b = e.target.closest('.btn-bundle');
    if (!b) return;
    const box = b.closest('[data-bundle]');
    let list = []; try { list = JSON.parse(box.dataset.bundle); } catch (err) { return; }
    const checks = $$('input[type=checkbox][data-i]', box);
    if (checks.length) list = list.filter((x, i) => checks[i].checked);
    if (!list.length) return toast('Отметьте товары', true);
    list.forEach(x => add({ id: x.id, name: x.name, price: x.price, img: x.img, code: x.code }, 1));
    track('add', list.map(x => ({ id: x.code, name: x.name, price: x.price, quantity: 1 })));
    b.classList.add('is-added'); const old = b.textContent; b.textContent = '✓ Добавлено: ' + list.length;
    setTimeout(() => { b.classList.remove('is-added'); b.textContent = old; }, 1600);
    toast('Набор в корзине · <a href="cart.php" style="text-decoration:underline">Оформить</a>');
  });
  $$('.fbt').forEach(box => box.addEventListener('change', () => {
    let list = []; try { list = JSON.parse(box.dataset.bundle); } catch (e) {}
    const checks = $$('input[data-i]', box);
    const sum = list.reduce((s, x, i) => s + (checks[i].checked ? x.price : 0), 0);
    $('.fbt-sum', box).textContent = fmt(sum);
    checks.forEach(c => c.closest('.fbt-item').classList.toggle('is-off', !c.checked));
  }));

  /* ---------- Таймер акции ---------- */
  const cds = $$('.countdown[data-until]');
  if (cds.length) {
    const tick = () => cds.forEach(el => {
      let s = Math.max(0, Math.floor((+el.dataset.until - Date.now()) / 1000));
      const d = Math.floor(s / 86400); s %= 86400;
      const h = String(Math.floor(s / 3600)).padStart(2, '0'), m = String(Math.floor(s % 3600 / 60)).padStart(2, '0'), sec = String(s % 60).padStart(2, '0');
      el.textContent = (d ? d + ' д ' : '') + h + ':' + m + ':' + sec;
    });
    tick(); setInterval(tick, 1000);
  }

  /* ---------- Telegram в кабинете ---------- */
  const tgc = $('#tgConnect');
  if (tgc) tgc.addEventListener('click', () => {
    $('#tgHint').hidden = false;
    let n = 0;
    const t = setInterval(async () => {
      if (++n > 40) return clearInterval(t);
      try { const d = await (await fetch('api.php?action=tg_check')).json(); if (d.linked) { clearInterval(t); location.reload(); } } catch (e) {}
    }, 4000);
  });

  function flyToCart(btn) {
    if (matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    const card = btn.closest('.card, .product');
    const img = card && card.querySelector('.card-img img, #galleryMain');
    const target = $('#cartCount')?.parentElement;
    if (!img || !target) return;
    const a = img.getBoundingClientRect(), t = target.getBoundingClientRect();
    const f = document.createElement('div');
    f.className = 'fly';
    f.innerHTML = `<img src="${esc(img.currentSrc || img.src)}" alt="">`;
    f.style.left = (a.left + a.width / 2 - 32) + 'px';
    f.style.top = (a.top + a.height / 2 - 32) + 'px';
    document.body.appendChild(f);
    requestAnimationFrame(() => {
      f.style.transform = `translate(${t.left + t.width / 2 - (a.left + a.width / 2)}px, ${t.top + t.height / 2 - (a.top + a.height / 2)}px) scale(.25)`;
      f.style.opacity = '.2';
    });
    setTimeout(() => f.remove(), 800);
  }

  /* ---------- Баннеры-подборки (карусель) ---------- */
  const bn = $('#bn');
  if (bn) {
    const slides = $$('.bn-slide', bn), tabs = $$('.bn-tab'), dur = +bn.dataset.dur || 6000;
    const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
    document.documentElement.style.setProperty('--bn-dur', dur + 'ms');
    let cur = 0, timer, paused = false;
    const show = (n, user) => {
      cur = (n + slides.length) % slides.length;
      slides.forEach((s, i) => {
        const on = i === cur;
        s.classList.toggle('is-on', on);
        s.setAttribute('aria-hidden', on ? 'false' : 'true');
        on ? s.removeAttribute('tabindex') : s.setAttribute('tabindex', '-1');
      });
      tabs.forEach((t, i) => {
        t.classList.remove('is-on'); void t.offsetWidth;
        t.classList.toggle('is-on', i === cur); t.setAttribute('aria-selected', i === cur ? 'true' : 'false');
      });
      const tab = tabs[cur], bar = tab && tab.parentElement;
      if (user && bar && bar.scrollWidth > bar.clientWidth) bar.scrollTo({ left: tab.offsetLeft - bar.clientWidth / 2 + tab.clientWidth / 2, behavior: 'smooth' });
      clearTimeout(timer);
      if (slides.length > 1 && !paused && !reduce) timer = setTimeout(() => show(cur + 1), dur);
    };
    const pause = on => { paused = on; bn.parentElement.classList.toggle('paused', on); if (on) clearTimeout(timer); else show(cur); };
    tabs.forEach((t, i) => t.addEventListener('click', () => show(i, true)));
    $('.bn-prev', bn)?.addEventListener('click', () => show(cur - 1, true));
    $('.bn-next', bn)?.addEventListener('click', () => show(cur + 1, true));
    bn.parentElement.addEventListener('mouseenter', () => pause(true));
    bn.parentElement.addEventListener('mouseleave', () => pause(false));
    bn.addEventListener('focusin', () => pause(true));
    bn.addEventListener('focusout', () => pause(false));
    bn.addEventListener('keydown', e => { if (e.key === 'ArrowRight') show(cur + 1, true); if (e.key === 'ArrowLeft') show(cur - 1, true); });
    let x0 = null, y0 = 0, moved = false;
    bn.addEventListener('touchstart', e => { x0 = e.touches[0].clientX; y0 = e.touches[0].clientY; moved = false; }, { passive: true });
    bn.addEventListener('touchend', e => {
      if (x0 === null) return;
      const dx = e.changedTouches[0].clientX - x0, dy = e.changedTouches[0].clientY - y0;
      if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy)) { moved = true; show(cur + (dx < 0 ? 1 : -1), true); }
      x0 = null;
    });
    bn.addEventListener('click', e => { if (moved) { e.preventDefault(); moved = false; } }, true);
    bn.addEventListener('click', e => { const a = e.target.closest('.bn-slide'); if (a) goal('banner_click', { title: a.getAttribute('aria-label') }); });
    document.addEventListener('visibilitychange', () => { if (document.hidden) clearTimeout(timer); else if (!paused) show(cur); });
    show(0);
  }

  /* ---------- Ряды товаров: большие стрелки по бокам + полоса прокрутки ---------- */
  const shelfArrows = sh => {
    if (sh.dataset.arrows) return;
    sh.dataset.arrows = 1;
    const wrap = document.createElement('div');
    wrap.className = 'shelf-wrap';
    sh.before(wrap); wrap.append(sh);
    const mk = (cls, label, d) => {
      const b = document.createElement('button');
      b.type = 'button'; b.className = 'shelf-arrow ' + cls; b.setAttribute('aria-label', label);
      b.innerHTML = `<svg viewBox="0 0 24 24" aria-hidden="true"><path d="${d}"/></svg>`;
      wrap.append(b); return b;
    };
    const prev = mk('shelf-prev', 'Прокрутить назад', 'M15 5l-7 7 7 7');
    const next = mk('shelf-next', 'Прокрутить вперёд', 'M9 5l7 7-7 7');
    const step = () => Math.max(sh.clientWidth * 0.85, 220);
    prev.addEventListener('click', () => sh.scrollBy({ left: -step(), behavior: 'smooth' }));
    next.addEventListener('click', () => sh.scrollBy({ left: step(), behavior: 'smooth' }));
    const upd = () => {
      const max = sh.scrollWidth - sh.clientWidth - 4;
      prev.hidden = sh.scrollLeft <= 4;
      next.hidden = sh.scrollLeft >= max;
    };
    sh.addEventListener('scroll', upd, { passive: true });
    addEventListener('resize', upd);
    new MutationObserver(upd).observe(sh, { childList: true });
    upd();
  };
  $$('.shelf').forEach(shelfArrows);

  /* ---------- Цвета на карточке: переключение без перехода ---------- */
  document.addEventListener('click', e => {
    const sw = e.target.closest('.sw');
    if (!sw || !sw.dataset.v) return;
    const card = sw.closest('.card');
    if (!card) return;
    e.preventDefault();
    if (sw.classList.contains('is-on')) { location.href = sw.href; return; }
    let v; try { v = JSON.parse(sw.dataset.v); } catch (err) { return; }
    $$('.sw', card).forEach(x => x.classList.toggle('is-on', x === sw));
    const im = $('.card-img img', card);
    if (im && v.img) { im.classList.add('sw-fade'); const n = new Image(); n.onload = n.onerror = () => { im.src = v.img; im.classList.remove('sw-fade'); }; n.src = v.img; }
    $$('.card-img, .card-title', card).forEach(a => a.href = v.href);
    const ttl = $('.card-title', card); if (ttl && v.title) ttl.textContent = v.title;
    const pr = $('.price', card); if (pr) pr.textContent = v.pf;
    const po = $('.price-old', card); if (po) { po.textContent = v.old; po.hidden = !v.old; }
    const add = $('.btn-add', card);
    if (add) Object.assign(add.dataset, { id: v.id, name: v.name, price: v.price, img: v.img2, code: v.code });
    const fv = $('[data-fav]', card); if (fv) fv.dataset.fav = v.id;
    const art = $('.card-art', card); if (art && art.firstChild) art.firstChild.textContent = art.firstChild.textContent.replace(/\d{6}/, v.code);
    card.dataset.id = v.id;
    favPaint();
  });

  /* ---------- Меню категорий (телефон) ---------- */
  const side = $('#side'), sideBg = $('.side-backdrop');
  const sideHome = side && document.createComment('side');
  if (side) side.before(sideHome);
  const sideToggle = on => {
    if (!side) return;
    // на время показа панель переносится в <body>, чтобы быть поверх шапки
    if (on && side.parentElement !== document.body) document.body.append(side, sideBg);
    if (!on && side.parentElement === document.body) setTimeout(() => { if (!side.classList.contains('is-open')) sideHome.after(side, sideBg); }, 360);
    side.classList.toggle('is-open', on);
    document.body.classList.toggle('side-lock', on);
    if (sideBg) sideBg.hidden = !on;
    $$('[data-side-open]').forEach(b => b.setAttribute('aria-expanded', on ? 'true' : 'false'));
    if (on) $('.side-item.is-active, .side-item', side)?.focus({ preventScroll: true });
  };
  document.addEventListener('click', e => {
    if (e.target.closest('[data-side-open]')) { e.preventDefault(); sideToggle(!side?.classList.contains('is-open')); }
    else if (e.target.closest('[data-side-close]')) sideToggle(false);
  });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && side?.classList.contains('is-open')) sideToggle(false); });

  /* ---------- Количество на карточке ---------- */
  $$('[data-qty]').forEach(box => {
    const inp = $('input', box);
    box.addEventListener('click', e => {
      const d = e.target.closest('button')?.dataset.d;
      if (!d) return;
      inp.value = Math.max(1, Math.min(99, (parseInt(inp.value, 10) || 1) + +d));
    });
  });

  /* ---------- Галерея ---------- */
  const thumbs = $$('.thumb');
  const galGo = t => { if (!t) return; $('#galleryMain').src = t.dataset.src; thumbs.forEach(x => x.classList.toggle('is-active', x === t)); t.scrollIntoView({ block: 'nearest', inline: 'nearest' }); };
  thumbs.forEach(t => t.addEventListener('click', () => galGo(t)));
  const gm = $('.gallery-main');
  if (gm && thumbs.length > 1) {
    let gx = null;
    gm.addEventListener('touchstart', e => { gx = e.touches[0].clientX; }, { passive: true });
    gm.addEventListener('touchend', e => {
      if (gx === null) return;
      const dx = e.changedTouches[0].clientX - gx; gx = null;
      if (Math.abs(dx) < 40) return;
      const i = thumbs.findIndex(t => t.classList.contains('is-active'));
      galGo(thumbs[(i + (dx < 0 ? 1 : -1) + thumbs.length) % thumbs.length]);
    });
  }
  $$('[data-copy]').forEach(el => el.addEventListener('click', () => {
    navigator.clipboard?.writeText(el.dataset.copy).then(() => toast('Скопировано'));
  }));

  /* ---------- Отзыв ---------- */
  const rf = $('#reviewForm');
  if (rf) rf.addEventListener('submit', async e => {
    e.preventDefault();
    const btn = $('button', rf); btn.disabled = true;
    try {
      const r = await fetch('api.php?action=review', { method: 'POST', body: new FormData(rf) });
      const d = await r.json();
      if (!r.ok || d.error) throw new Error(d.error || 'Ошибка');
      rf.outerHTML = `<p class="alert ok">${d.status === 'approved' ? 'Спасибо! Отзыв опубликован.' : 'Спасибо! Отзыв появится после проверки.'}</p>`;
    } catch (err) { toast(err.message, true); btn.disabled = false; }
  });

  /* ---------- Подсказки поиска ---------- */
  const si = $('#searchInput'), sd = $('#searchDrop');
  if (si && sd) {
    let timer, ctrl, focus = -1;
    const close = () => { sd.hidden = true; focus = -1; };
    si.addEventListener('input', () => {
      clearTimeout(timer);
      const q = si.value.trim();
      if (q.length < 2) return close();
      timer = setTimeout(async () => {
        ctrl?.abort(); ctrl = new AbortController();
        try {
          const r = await fetch('api.php?action=suggest&q=' + encodeURIComponent(q), { signal: ctrl.signal });
          const d = await r.json();
          if (!d.items.length) { sd.innerHTML = '<a class="sd-all" href="./?q=' + encodeURIComponent(q) + '">Ничего не найдено</a>'; sd.hidden = false; return; }
          sd.innerHTML = d.items.map(i => `<a href="${esc(i.url || 'product.php?id=' + i.id)}">${i.img ? `<img src="${esc(i.img)}" alt="">` : ''}<span class="sd-name">${esc(i.name)}<small>Код ${esc(i.code)}</small></span><span class="sd-price">${esc(i.price)}</span></a>`).join('')
            + `<a class="sd-all" href="./?q=${encodeURIComponent(q)}#catalog">Все результаты →</a>`;
          sd.hidden = false; focus = -1;
        } catch (e) {}
      }, 180);
    });
    si.addEventListener('keydown', e => {
      const links = $$('a', sd);
      if (sd.hidden || !links.length) return;
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        focus = (focus + (e.key === 'ArrowDown' ? 1 : -1) + links.length) % links.length;
        links.forEach((l, i) => l.classList.toggle('is-focus', i === focus));
      } else if (e.key === 'Enter' && focus >= 0) { e.preventDefault(); location = links[focus].href; }
      else if (e.key === 'Escape') close();
    });
    document.addEventListener('click', e => { if (!e.target.closest('.search')) close(); });
  }

  /* ---------- «Показать ещё» ---------- */
  const more = $('#loadMore');
  if (more) more.addEventListener('click', async e => {
    e.preventDefault();
    more.textContent = 'Загрузка…';
    const { page, q, sort, cat } = more.dataset;
    try {
      const r = await fetch(`api.php?action=page&page=${page}&q=${encodeURIComponent(q)}&sort=${encodeURIComponent(sort)}&cat=${encodeURIComponent(cat || 0)}`);
      const d = await r.json();
      $('#grid').insertAdjacentHTML('beforeend', d.html);
      if (d.more) { more.dataset.page = +page + 1; more.textContent = 'Показать ещё'; } else more.parentElement.remove();
    } catch (err) { location = more.href; }
  });

  /* ---------- Страница корзины ---------- */
  const form = $('#checkoutForm');
  if (form) initCart();

  async function initCart() {
    const list = $('#cartList');
    const cfg = window.MB_CHECKOUT || {};
    let q = null, qTimer, qSeq = 0;
    if (!load().length) { $('#cartEmpty').hidden = false; return; }
    form.hidden = false;
    renderLocal();
    await requote(true);

    // Пока ждём сервер — показываем то, что знаем
    function renderLocal() {
      list.innerHTML = load().map(i => row(i, { price: i.price, unit: i.price, pct: 0, sum: i.price * i.qty })).join('');
    }
    function row(i, s) {
      return `<div class="ci" data-id="${i.id}">
          ${i.img ? `<img src="${esc(i.img)}" alt="">` : '<span></span>'}
          <div class="ci-name"><a href="product.php?id=${i.id}">${esc(i.name)}</a>
            <small>Код ${esc(i.code || '')} · ${s.pct > 0 ? `<s>${fmt(s.unit)}</s> ` : ''}${fmt(s.price)}/шт${s.pct > 0 ? ` <span class="ci-badge">−${+s.pct}% · ${esc(s.why)}</span>` : ''}</small></div>
          <div class="qty qty-sm"><button type="button" data-d="-1" aria-label="Меньше">−</button><input type="number" value="${i.qty}" min="1" max="999" aria-label="Количество"><button type="button" data-d="1" aria-label="Больше">+</button></div>
          <div class="ci-sum">${fmt(s.sum)}</div>
          <button type="button" class="ci-del" title="Удалить" aria-label="Удалить">×</button>
        </div>`;
    }

    async function requote(first) {
      const seq = ++qSeq;
      const dlv = $('input[name=delivery]:checked', form);
      try {
        const r = await fetch('api.php?action=quote', { method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ items: load().map(i => ({ id: i.id, qty: i.qty })), coupon: $('#couponField').value, delivery: dlv ? dlv.value : '',
            gift: $('#giftField') ? $('#giftField').value : '', use_bonus: !!($('#useBonus') && $('#useBonus').checked), dest: dest() }) });
        const d = await r.json();
        if (seq !== qSeq) return;
        q = d;
        // Убираем то, что больше не продаётся
        const okIds = new Set(d.items.filter(x => x.ok).map(x => x.id));
        const before = load();
        const kept = before.filter(i => okIds.has(i.id));
        if (kept.length !== before.length) { save(kept); toast('Некоторые товары закончились и убраны из корзины', true); }
        kept.forEach(i => { const x = d.items.find(y => y.id === i.id); if (x) Object.assign(i, { name: x.name, img: x.img, code: x.code, price: x.unit }); });
        save(kept);
        if (!kept.length) { form.hidden = true; $('#cartEmpty').hidden = false; return; }
        list.innerHTML = kept.map(i => row(i, d.items.find(y => y.id === i.id))).join('');
        promoState(d);
        giftState(d);
        dlvState(d);
      } catch (e) { if (first) renderLocal(); }
      totals();
    }
    const requoteSoon = () => { clearTimeout(qTimer); qTimer = setTimeout(requote, 250); };

    function promoState(d) {
      const msg = $('#promoMsg');
      if (!$('#couponField').value) { msg.textContent = ''; msg.className = 'promo-msg'; return; }
      if (d.coupon_error) { msg.textContent = d.coupon_error; msg.className = 'promo-msg bad'; }
      else if (d.coupon) { msg.textContent = `Промокод ${d.coupon} применён: −${fmt(d.coupon_discount)}`; msg.className = 'promo-msg ok'; }
    }
    function giftState(d) {
      const msg = $('#giftMsg'); if (!msg) return;
      if (!$('#giftField').value) { msg.textContent = ''; msg.className = 'promo-msg'; return; }
      if (d.gift_error) { msg.textContent = d.gift_error; msg.className = 'promo-msg bad'; }
      else if (d.gift) { msg.textContent = `Сертификат применён: −${fmt(d.gift_use)} (остаток на нём ${fmt(d.gift_balance - d.gift_use)})`; msg.className = 'promo-msg ok'; }
    }
    function dlvState(d) {
      const dlv = $('input[name=delivery]:checked', form);
      const m = $('#dlvMsg');
      if (dlv) {
        const cell = $(`[data-dlv-price="${dlv.value}"]`, form);
        if (cell && (dlv.dataset.api ? d.delivery_ready || d.delivery > 0 : true)) cell.textContent = d.delivery > 0 ? fmt(d.delivery) : 'бесплатно';
      }
      if (m) { const t = d.delivery_error || d.delivery_note || ''; m.textContent = t; m.hidden = !t; m.classList.toggle('warn', !!d.delivery_note && !d.delivery_error); }
      const eta = $('#sumEta'); if (eta) eta.textContent = d.delivery_eta ? ' · ' + d.delivery_eta : '';
      const pe = $('#postEta'); if (pe) pe.textContent = d.delivery_eta ? 'Срок: ' + d.delivery_eta : '';
      const bm = $('#bonusMax'); if (bm) bm.textContent = d.bonus_max + ' ₽';
      const be = $('#bonusEarn'); if (be && cfg.bonusPercent) be.textContent = Math.floor(d.items_total * cfg.bonusPercent / 100);
    }
    function dest() {
      const f = form.elements;
      return { city_code: f.city_code ? +f.city_code.value || 0 : 0, pvz: f.pvz ? f.pvz.value : '', index: f.post_index ? f.post_index.value.replace(/\D/g, '') : '' };
    }
    const gb = $('#giftBtn');
    if (gb) {
      gb.addEventListener('click', () => { $('#giftField').value = $('#giftInput').value.trim(); requote(); });
      $('#giftInput').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); gb.click(); } });
    }
    const ub = $('#useBonus'); if (ub) ub.addEventListener('change', requote);
    const pi = form.elements.post_index;
    if (pi) pi.addEventListener('input', () => { if (pi.value.replace(/\D/g, '').length === 6) requoteSoon(); });

    /* --- Ozon: пункты выдачи с Яндекс Карт --- */
    const ozonCity = $('#ozonCity');
    if (ozonCity) {
      const mapEl = $('#ozonMap'), list = $('#ozonList'), wrap = $('#ozonWrap'), chosen = $('#ozonChosen');
      let omap = null, ocol = null, found = [];
      const loadYm = () => new Promise(res => {
        if (window.ymaps) return ymaps.ready(res);
        const sc = document.createElement('script');
        sc.src = 'https://api-maps.yandex.ru/2.1/?lang=ru_RU&apikey=' + encodeURIComponent(mapEl.dataset.key);
        sc.onload = () => ymaps.ready(res); document.head.appendChild(sc);
      });
      const pick = i => {
        const p = found[i]; if (!p) return;
        form.elements.address.value = 'ПВЗ Ozon: ' + p.addr + (p.name && !p.addr.includes(p.name) ? ' (' + p.name + ')' : '');
        chosen.innerHTML = `<span>Пункт выдачи Ozon: <b>${esc(p.addr)}</b><small class="muted">${esc(p.hours || '')}</small></span><button type="button" class="link" id="ozonChange">Изменить</button>`;
        chosen.hidden = false; wrap.hidden = true;
        form.elements.address.dispatchEvent(new Event('input', { bubbles: true }));
      };
      chosen.addEventListener('click', e => { if (e.target.id === 'ozonChange') { wrap.hidden = false; chosen.hidden = true; } });
      list.addEventListener('click', e => { const b = e.target.closest('.pvz'); if (b) pick(+b.dataset.i); });
      async function findOzon() {
        const q = ozonCity.value.trim(); if (q.length < 2) { toast('Укажите город', true); return; }
        wrap.hidden = false; chosen.hidden = true;
        list.innerHTML = '<p class="muted tiny">Ищем пункты выдачи Ozon…</p>';
        try {
          await loadYm();
          const geo = await ymaps.geocode(q, { results: 1 });
          const g = geo.geoObjects.get(0); if (!g) { list.innerHTML = '<p class="muted tiny">Место не найдено.</p>'; return; }
          const bounds = g.properties.get('boundedBy');
          if (!omap) omap = new ymaps.Map(mapEl, { center: g.geometry.getCoordinates(), zoom: 12, controls: ['zoomControl'] });
          omap.setBounds(bounds, { checkZoomRange: true });
          const res = await ymaps.search('Ozon пункт выдачи', { boundedBy: bounds, strictBounds: true, results: 50 });
          found = [];
          if (ocol) omap.geoObjects.remove(ocol);
          ocol = new ymaps.Clusterer({ preset: 'islands#invertedBlueClusterIcons' });
          res.geoObjects.each(o => {
            const name = o.properties.get('name') || '', addr = o.properties.get('address') || o.properties.get('description') || '';
            if (!/ozon|озон/i.test(name + addr)) return;
            const i = found.push({ name, addr: addr || name, hours: o.properties.get('CompanyMetaData.Hours.text') || '', coords: o.geometry.getCoordinates() }) - 1;
            const pm = new ymaps.Placemark(found[i].coords, { hintContent: found[i].addr }, { preset: 'islands#blueDotIcon' });
            pm.events.add('click', () => pick(i)); ocol.add(pm);
          });
          omap.geoObjects.add(ocol);
          if (found.length) omap.setBounds(ocol.getBounds(), { checkZoomRange: true, zoomMargin: 30 });
          list.innerHTML = found.length ? found.map((p, i) => `<button type="button" class="pvz" data-i="${i}"><b>${esc(p.addr)}</b><small>${esc(p.hours || p.name)}</small></button>`).join('') : '<p class="muted tiny">Пункты Ozon рядом не найдены — впишите адрес пункта вручную ниже.</p>';
        } catch (e) { list.innerHTML = '<p class="muted tiny">Карта недоступна — впишите адрес пункта вручную ниже.</p>'; }
      }
      $('#ozonFind').addEventListener('click', findOzon);
      ozonCity.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); findOzon(); } });
    }

    /* --- СДЭК: город → пункт выдачи --- */
    const cityIn = $('#cdekCity');
    if (cityIn) {
      const drop = $('#cdekCityDrop');
      let ct, pts = [], map = null, marks = null;
      cityIn.addEventListener('input', () => {
        clearTimeout(ct);
        const q = cityIn.value.trim();
        form.elements.city_code.value = ''; form.elements.pvz.value = ''; $('#pvzWrap').hidden = true; $('#pvzChosen').hidden = true;
        if (q.length < 2) { drop.hidden = true; return; }
        ct = setTimeout(async () => {
          const d = await (await fetch('api.php?action=cdek_cities&q=' + encodeURIComponent(q))).json();
          drop.innerHTML = d.items.length ? d.items.map(c => `<a href="#" data-code="${c.code}" data-name="${esc(c.city)}"><span class="sd-name">${esc(c.city)}<small>${esc(c.region)}</small></span></a>`).join('') : `<span class="sd-all">${esc(d.error || 'Город не найден')}</span>`;
          drop.hidden = false;
        }, 220);
      });
      drop.addEventListener('click', e => {
        const a = e.target.closest('a[data-code]'); if (!a) return;
        e.preventDefault();
        cityIn.value = a.dataset.name; drop.hidden = true;
        form.elements.city_code.value = a.dataset.code; form.elements.city_name.value = a.dataset.name;
        loadPoints(+a.dataset.code); requote();
      });
      document.addEventListener('click', e => { if (!e.target.closest('.city-pick')) drop.hidden = true; });
      async function loadPoints(code) {
        $('#pvzList').innerHTML = '<p class="muted tiny">Загружаем пункты выдачи…</p>'; $('#pvzWrap').hidden = false;
        const d = await (await fetch('api.php?action=cdek_points&city=' + code)).json();
        pts = d.items || [];
        renderPts('');
        drawMap();
      }
      function renderPts(q) {
        q = q.toLowerCase();
        const list = pts.filter(p => !q || (p.address + ' ' + p.name).toLowerCase().includes(q)).slice(0, 200);
        $('#pvzCount').textContent = pts.length ? `${pts.length} ${pts.length % 10 === 1 && pts.length % 100 !== 11 ? 'пункт' : 'пунктов'}` : '';
        $('#pvzList').innerHTML = list.length ? list.map(p => `<button type="button" class="pvz${form.elements.pvz.value === p.code ? ' is-on' : ''}" data-code="${esc(p.code)}"><b>${esc(p.address)}</b><small>${esc(p.name)}${p.time ? ' · ' + esc(p.time) : ''}</small></button>`).join('') : '<p class="muted tiny">Ничего не нашли.</p>';
      }
      function choose(code) {
        const p = pts.find(x => x.code === code); if (!p) return;
        form.elements.pvz.value = p.code; form.elements.pvz_addr.value = p.address;
        $('#pvzChosen').innerHTML = `<span>Пункт выдачи: <b>${esc(p.address)}</b><small class="muted">${esc(p.time || '')}</small></span><button type="button" class="link" id="pvzChange">Изменить</button>`;
        $('#pvzChosen').hidden = false; $('#pvzWrap').hidden = true;
        requote();
      }
      $('#pvzList').addEventListener('click', e => { const b = e.target.closest('.pvz'); if (b) choose(b.dataset.code); });
      $('#pvzSearch').addEventListener('input', e => renderPts(e.target.value));
      $('#pvzChosen').addEventListener('click', e => { if (e.target.id === 'pvzChange') { $('#pvzWrap').hidden = false; $('#pvzChosen').hidden = true; renderPts(''); } });
      function drawMap() {
        const el = $('#pvzMap'); if (!el || !pts.length) return;
        const go = () => ymaps.ready(() => {
          const c = pts.find(p => p.lat) || {}; if (!c.lat) return;
          if (!map) map = new ymaps.Map(el, { center: [c.lat, c.lon], zoom: 11, controls: ['zoomControl'] });
          if (marks) map.geoObjects.remove(marks);
          marks = new ymaps.Clusterer({ preset: 'islands#invertedBlueClusterIcons' });
          pts.filter(p => p.lat).forEach(p => { const pm = new ymaps.Placemark([p.lat, p.lon], { hintContent: p.address }, { preset: 'islands#blueDotIcon' }); pm.events.add('click', () => choose(p.code)); marks.add(pm); });
          map.geoObjects.add(marks);
          map.setBounds(marks.getBounds(), { checkZoomRange: true, zoomMargin: 30 });
        });
        if (window.ymaps) return go();
        const sc = document.createElement('script');
        sc.src = 'https://api-maps.yandex.ru/2.1/?lang=ru_RU&apikey=' + encodeURIComponent(el.dataset.key);
        sc.onload = go; document.head.appendChild(sc);
      }
      if (form.elements.city_code.value) {
        loadPoints(+form.elements.city_code.value).then(() => { if (form.elements.pvz.value) choose(form.elements.pvz.value); });
      }
    }

    $('#promoBtn').addEventListener('click', () => { $('#couponField').value = $('#promoInput').value.trim(); requote(); });
    $('#promoInput').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); $('#promoBtn').click(); } });

    list.addEventListener('click', e => {
      const r = e.target.closest('.ci'); if (!r) return;
      const id = +r.dataset.id;
      const items = load();
      const it = items.find(i => i.id === id); if (!it) return;
      if (e.target.closest('.ci-del')) {
        save(items.filter(i => i.id !== id));
        r.remove();
        if (!load().length) { form.hidden = true; $('#cartEmpty').hidden = false; return; }
        return requoteSoon();
      }
      const d = e.target.closest('button')?.dataset.d;
      if (d) { it.qty = Math.max(1, Math.min(999, it.qty + +d)); save(items); $('input', r).value = it.qty; requoteSoon(); }
    });
    list.addEventListener('change', e => {
      if (e.target.tagName !== 'INPUT') return;
      const id = +e.target.closest('.ci').dataset.id;
      const items = load(); const it = items.find(i => i.id === id);
      it.qty = Math.max(1, Math.min(999, parseInt(e.target.value, 10) || 1));
      save(items); requoteSoon();
    });

    $$('input[name=delivery]', form).forEach(r => r.addEventListener('change', requote));
    $$('input[name=payment]', form).forEach(r => r.addEventListener('change', totals));

    function totals() {
      const items = load();
      const cnt = items.reduce((s, i) => s + i.qty, 0);
      const base = q ? q.base : items.reduce((s, i) => s + i.price * i.qty, 0);
      const itemsTotal = q ? q.items_total : base;
      const bonus = q ? q.bonus_use : 0;
      const disc = q ? q.discount_total - bonus : 0;
      const dlv = $('input[name=delivery]:checked', form);
      const dp = q ? q.delivery : (dlv ? +dlv.dataset.price : 0);
      const gift = q ? q.gift_use : 0;
      const total = q ? q.total : itemsTotal + dp;
      $('#sumCount').textContent = cnt;
      $('#sumItems').textContent = fmt(base);
      $('#sumDiscRow').hidden = !(disc > 0);
      $('#sumDisc').textContent = '−' + fmt(disc);
      $('#sumBonusRow').hidden = !(bonus > 0);
      $('#sumBonus').textContent = '−' + fmt(bonus);
      $('#sumGiftRow').hidden = !(gift > 0);
      $('#sumGift').textContent = '−' + fmt(gift);
      $('#sumDlv').textContent = q && !q.delivery_ready && dlv && dlv.dataset.api ? 'рассчитаем' : (dp ? fmt(dp) : 'бесплатно');
      $('#sumTotal').textContent = fmt(total);
      const api = dlv ? dlv.dataset.api : '';
      if ($('#cdekBox')) $('#cdekBox').hidden = api !== 'cdek';
      if ($('#postBox')) $('#postBox').hidden = api !== 'post';
      if ($('#ozonBox')) $('#ozonBox').hidden = !(dlv && dlv.value === 'ozon');
      if ($('#addrLabel')) $('#addrLabel').textContent = api === 'post' ? 'Адрес: город, улица, дом, квартира' : 'Город и адрес пункта выдачи / отделения';
      const fb = $('#freeBar');
      if (fb && cfg.freeFrom > 0) {
        fb.hidden = false;
        const left = cfg.freeFrom - itemsTotal;
        fb.classList.toggle('done', left <= 0);
        $('#freeText').textContent = left > 0 ? `Добавьте товаров ещё на ${fmt(left)} — доставка будет бесплатной` : 'Доставка бесплатная';
        $('#freeFill').style.width = Math.min(100, itemsTotal / cfg.freeFrom * 100) + '%';
      }
      const needAddr = dlv && dlv.dataset.addr === '1';
      $('#addrBlock').hidden = !needAddr;
      const onsite = $('#payOnSite');
      if (onsite) {
        const isPickup = dlv && dlv.value === 'pickup';
        onsite.classList.toggle('is-disabled', !isPickup);
        const r = $('input', onsite);
        if (!isPickup && r.checked) { const online = $('input[name=payment][value=online]', form); if (online) online.checked = true; }
      }
      const pay = $('input[name=payment]:checked', form) || $('input[name=payment]', form);
      const btn = $('#submitBtn');
      const short = cfg.minOrder > 0 && (q ? q.subtotal : itemsTotal) < cfg.minOrder;
      btn.disabled = short;
      btn.textContent = short ? 'Минимальный заказ ' + fmt(cfg.minOrder) : (total <= 0 && q ? 'Оформить — оплачено сертификатом' : (pay && pay.value === 'online' ? 'Перейти к оплате · ' + fmt(total) : 'Оформить заказ'));
    }

    form.addEventListener('input', e => e.target.classList?.remove('is-invalid'));
    form.addEventListener('submit', e => {
      let bad = null;
      const req = ['name', 'phone'];
      if (!$('#addrBlock').hidden) req.push('address');
      req.forEach(n => {
        const f = form.elements[n];
        const ok = n === 'phone' ? f.value.replace(/\D/g, '').length >= 10 : f.value.trim().length >= (n === 'address' ? 5 : 2);
        f.classList.toggle('is-invalid', !ok);
        if (!ok && !bad) bad = f;
      });
      const em = form.elements.email;
      if (em.value && !/^\S+@\S+\.\S+$/.test(em.value)) { em.classList.add('is-invalid'); bad = bad || em; }
      if (q && q.coupon_error && $('#couponField').value) { e.preventDefault(); $('#promoInput').focus(); toast('Промокод не подходит — уберите его или введите другой', true); return; }
      if (q && q.gift_error && $('#giftField') && $('#giftField').value) { e.preventDefault(); $('#giftInput').focus(); toast('Сертификат не подходит — проверьте код', true); return; }
      const dsel = $('input[name=delivery]:checked', form);
      if (dsel && dsel.dataset.api === 'cdek' && !form.elements.pvz.value) { e.preventDefault(); $('#cdekCity').focus(); toast('Выберите город и пункт выдачи СДЭК', true); return; }
      if (dsel && dsel.dataset.api === 'post' && form.elements.post_index.value.replace(/\D/g, '').length !== 6) { e.preventDefault(); form.elements.post_index.focus(); toast('Укажите почтовый индекс', true); return; }
      if (bad) { e.preventDefault(); bad.focus(); toast('Проверьте выделенные поля', true); return; }
      $('#itemsField').value = JSON.stringify(load().map(i => ({ id: i.id, qty: i.qty })));
      $('#submitBtn').disabled = true;
      $('#submitBtn').textContent = 'Оформляем…';
      goal('checkout');
    });
  }

  updateCount();
})();
