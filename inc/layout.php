<?php
declare(strict_types=1);

function mp_icon(string $mp): string
{
    return match ($mp) {
        'ozon' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="11" fill="#005bff"/><text x="12" y="16" text-anchor="middle" font-size="10" font-weight="800" fill="#fff" font-family="Arial">OZ</text></svg>',
        'wb'   => '<svg viewBox="0 0 24 24" aria-hidden="true"><defs><linearGradient id="wbg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#cb11ab"/><stop offset="1" stop-color="#481173"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#wbg)"/><text x="12" y="16" text-anchor="middle" font-size="9.5" font-weight="800" fill="#fff" font-family="Arial">WB</text></svg>',
        'ym'   => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="11" fill="#ffcc00"/><text x="12" y="16" text-anchor="middle" font-size="9.5" font-weight="800" fill="#000" font-family="Arial">ЯМ</text></svg>',
        default => '',
    };
}

function page_head(string $title, string $active = '', array $meta = []): void
{
    $shop = setting('shop_name');
    $accent = preg_match('/^#[0-9a-f]{6}$/i', setting('theme_accent')) ? setting('theme_accent') : '#00e5ff';
    $q = $_GET['q'] ?? '';
    $desc = $meta['description'] ?? (setting('seo_description') ?: setting('shop_tagline'));
    $desc = mb_strimwidth(trim((string)preg_replace('/\s+/u', ' ', $desc)), 0, 200, '…');
    $mid = preg_replace('/\D/', '', setting('metrika_id'));
    ?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<base href="<?= e(site_root()) ?>">
<title><?= e($title) ?></title>
<meta name="description" content="<?= e($desc) ?>">
<?php if (!empty($meta['noindex'])): ?><meta name="robots" content="noindex,follow">
<?php endif; ?>
<?php if (!empty($meta['canonical'])): ?><link rel="canonical" href="<?= e($meta['canonical']) ?>">
<?php endif; ?>
<?php if (setting('google_verify') !== ''): ?><meta name="google-site-verification" content="<?= e(setting('google_verify')) ?>">
<?php endif; ?>
<?php if (setting('yandex_verify') !== ''): ?><meta name="yandex-verification" content="<?= e(setting('yandex_verify')) ?>">
<?php endif; ?>
<meta property="og:type" content="<?= !empty($meta['jsonld_product']) ? 'product' : 'website' ?>">
<meta property="og:site_name" content="<?= e($shop) ?>">
<meta property="og:title" content="<?= e($title) ?>">
<meta property="og:description" content="<?= e($desc) ?>">
<?php if (!empty($meta['image'])): ?><meta property="og:image" content="<?= e($meta['image']) ?>">
<?php endif; ?>
<?php if (!empty($meta['canonical'])): ?><meta property="og:url" content="<?= e($meta['canonical']) ?>">
<?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preconnect" href="https://ir.ozone.ru" crossorigin><link rel="dns-prefetch" href="https://cdn1.ozone.ru">
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Unbounded:wght@500;700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=<?= MB_VERSION ?>">
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<style>:root{--accent:<?= $accent ?>}</style>
<?php foreach ((array)($meta['jsonld'] ?? []) as $ld): ?>
<script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<?php endforeach; ?>
<script>window.dataLayer=window.dataLayer||[];window.MB={metrika:<?= $mid !== '' ? (int)$mid : 0 ?>,user:<?= current_user() ? 1 : 0 ?>};</script>
<?php if ($mid !== ''): ?>
<script>(function(m,e,t,r,i,k,a){m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};m[i].l=1*new Date();for(var j=0;j<document.scripts.length;j++){if(document.scripts[j].src===r){return}}k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)})(window,document,"script","https://mc.yandex.ru/metrika/tag.js","ym");ym(<?= (int)$mid ?>,"init",{clickmap:true,trackLinks:true,accurateTrackBounce:true,webvisor:true,ecommerce:"dataLayer"});</script>
<noscript><div><img src="https://mc.yandex.ru/watch/<?= (int)$mid ?>" style="position:absolute;left:-9999px" alt=""></div></noscript>
<?php endif; ?>
</head>
<body>
<div class="bg-glow" aria-hidden="true"></div>
<?php if ($sale = sale_active()): ?>
<a class="sale-strip" href="<?= count($sale['cats']) === 1 ? e(category_url($sale['cats'][0], category_title($sale['cats'][0]))) : './?sort=discount#catalog' ?>">
  <span class="sale-fire" aria-hidden="true"></span>
  <b><?= e($sale['title']) ?>: −<?= e(rtrim(rtrim(number_format($sale['percent'], 1, ',', ''), '0'), ',')) ?>%<?= $sale['cats'] ? ' на ' . e(implode(', ', array_map('category_title', array_slice($sale['cats'], 0, 3)))) : ' на весь каталог' ?></b>
  <span class="sale-left">до конца <span class="countdown" data-until="<?= $sale['until'] * 1000 ?>"><?= e(date('d.m H:i', $sale['until'])) ?></span></span>
</a>
<?php endif; ?>
<header class="topbar">
  <div class="wrap topbar-in">
    <button class="menu-btn" type="button" data-side-open aria-controls="side" aria-label="Категории товаров">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h10"/></svg>
    </button>
    <a class="logo" href="./" aria-label="<?= e($shop) ?> — на главную">
      <span class="logo-mark">M</span><span class="logo-text"><?= logo_text($shop) ?></span>
    </a>
    <form class="search" action="./" method="get" role="search">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10.5 3a7.5 7.5 0 0 1 5.9 12.1l4.3 4.3-1.4 1.4-4.3-4.3A7.5 7.5 0 1 1 10.5 3Zm0 2a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11Z"/></svg>
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="Название или код товара" autocomplete="off" id="searchInput">
      <div class="search-drop" id="searchDrop" hidden></div>
    </form>
    <nav class="mp-links" aria-label="Мы на маркетплейсах">
      <?php foreach (['ozon' => 'link_ozon_store', 'wb' => 'link_wb_store', 'ym' => 'link_ym_store'] as $mp => $k): if (setting($k) === '') continue; ?>
        <a href="<?= e(setting($k)) ?>" target="_blank" rel="noopener" title="Наш магазин на <?= ['ozon' => 'Ozon', 'wb' => 'Wildberries', 'ym' => 'Яндекс Маркете'][$mp] ?>"><?= mp_icon($mp) ?></a>
      <?php endforeach; ?>
    </nav>
    <?php $cu = current_user(); ?>
    <a class="cart-btn fav-head<?= $active === 'fav' ? ' is-active' : '' ?>" href="favorites.php" aria-label="Избранное" title="Избранное">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20.5s-7.5-4.6-9.3-9.2C1.5 8 3.6 4.5 7.1 4.5c2 0 3.6 1.1 4.9 2.8 1.3-1.7 2.9-2.8 4.9-2.8 3.5 0 5.6 3.5 4.4 6.8-1.8 4.6-9.3 9.2-9.3 9.2z"/></svg>
      <span class="cart-count" id="favCount" hidden>0</span>
    </a>
    <a class="cart-btn acc-btn<?= $active === 'account' ? ' is-active' : '' ?>" href="account.php" aria-label="<?= $cu ? 'Личный кабинет' : 'Войти' ?>" title="<?= $cu ? e($cu['name']) : 'Войти или зарегистрироваться' ?>">
      <svg viewBox="0 0 24 24" aria-hidden="true"><circle class="o" cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/></svg>
      <?php if ($cu && (float)$cu['discount_percent'] > 0): ?><span class="cart-count acc-disc">−<?= (int)$cu['discount_percent'] ?>%</span><?php endif; ?>
    </a>
    <a class="cart-btn<?= $active === 'cart' ? ' is-active' : '' ?>" href="cart.php" aria-label="Корзина">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4h2l2.2 10.2A2 2 0 0 0 9.2 16H18a2 2 0 0 0 2-1.6L21.4 8H6.2"/><circle cx="9.5" cy="20" r="1.5"/><circle cx="17.5" cy="20" r="1.5"/></svg>
      <span class="cart-count" id="cartCount" hidden>0</span>
    </a>
  </div>
  <?php $cats = categories_visible(); $curCat = (int)($_GET['cat'] ?? 0); if ($cats): ?>
  <nav class="catnav<?= $active === 'home' ? ' catnav-home' : '' ?>" aria-label="Категории"><div class="wrap catnav-in">
    <a href="./#catalog" class="<?= $curCat === 0 && $active === 'home' ? 'is-active' : '' ?>">Все товары</a>
    <?php foreach ($cats as $c): ?>
      <a href="<?= e(category_url((int)$c['id'], $c['title'])) ?>" class="<?= $curCat === (int)$c['id'] ? 'is-active' : '' ?>"><?= e($c['title']) ?></a>
    <?php endforeach; ?>
  </div></nav>
  <?php endif; ?>
</header>
<?php if ($active !== 'home') echo cat_sidebar(0, true); ?>
<main>
<?php
}

/**
 * Боковое меню категорий: слева на главной/в каталоге, на телефоне — выезжающая панель.
 * $drawerOnly — на остальных страницах только выезжающая панель (кнопка ☰ в шапке).
 */
function cat_sidebar(int $cur = 0, bool $drawerOnly = false, string $q = ''): string
{
    $cats = categories_visible();
    $total = 0; foreach ($cats as $c) $total += (int)$c['cnt'];
    $cols = collections_live(7);
    ob_start(); ?>
<aside class="side<?= $drawerOnly ? ' side-drawer' : '' ?>" id="side" aria-label="Категории товаров">
  <div class="side-head"><b>Каталог</b><button class="side-x" type="button" data-side-close aria-label="Закрыть"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button></div>
  <nav class="side-list">
    <a class="side-item side-all<?= $cur === 0 && $q === '' && !$drawerOnly ? ' is-active' : '' ?>" href="./#catalog">
      <span class="side-ico side-ico-all" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
      <span class="side-t">Все товары</span><small><?= $total ?></small>
    </a>
    <?php foreach ($cats as $c): $im = json_decode((string)$c['images'], true)[0] ?? ''; ?>
      <a class="side-item<?= $cur === (int)$c['id'] ? ' is-active' : '' ?>" href="<?= e(category_url((int)$c['id'], $c['title'])) ?>"<?= $cur === (int)$c['id'] ? ' aria-current="page"' : '' ?>>
        <span class="side-ico"><?php if ($im): ?><img src="<?= e(img_url($im, 200)) ?>" alt="" loading="lazy"><?php endif; ?></span>
        <span class="side-t"><?= e($c['title']) ?></span><small><?= (int)$c['cnt'] ?></small>
      </a>
    <?php endforeach; ?>
  </nav>
  <?php if ($cols && !empty($cols[0]['q'])): ?>
  <div class="side-sub">Подборки</div>
  <nav class="side-list side-cols">
    <?php foreach ($cols as $i => $c): if (empty($c['q'])) continue; ?>
      <a class="side-item side-col t-<?= e(array_keys(MB_BANNER_THEMES)[$i % 7]) ?><?= $q !== '' && mb_strtolower($q) === mb_strtolower($c['q']) ? ' is-active' : '' ?>" href="<?= e(collection_url($c)) ?>">
        <span class="side-ico"><?php if (!empty($c['images'][0])): ?><img src="<?= e(img_url($c['images'][0], 200)) ?>" alt="" loading="lazy"><?php endif; ?></span>
        <span class="side-t"><?= e($c['title']) ?></span><small><?= (int)$c['count'] ?></small>
      </a>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>
</aside>
<div class="side-backdrop" data-side-close hidden></div>
<?php
    return (string)ob_get_clean();
}

function logo_text(string $shop): string
{
    // MaxBoom → MAX + BOOM с акцентом
    if (mb_strtolower($shop) === 'maxboom') return 'MAX<b>BOOM</b>';
    return e(mb_strtoupper($shop));
}

function page_foot(): void
{
    $shop = setting('shop_name');
    $phone = setting('phone');
    ?>
</main>
<footer class="footer">
  <div class="wrap footer-in">
    <div>
      <a class="logo logo-sm" href="./"><span class="logo-mark">M</span><span class="logo-text"><?= logo_text($shop) ?></span></a>
      <p class="muted"><?= e(setting('shop_tagline')) ?></p>
    </div>
    <div>
      <h4>Покупателям</h4>
      <a href="delivery.php">Доставка и оплата</a>
      <a href="cart.php">Корзина</a>
      <a href="account.php">Личный кабинет</a>
      <a href="favorites.php">Избранное</a>
      <?php if (gift_on()): ?><a href="gift.php">Подарочные сертификаты</a><?php endif; ?>
      <?php if (setting('ref_enabled') === '1' && bonus_on()): ?><a href="account.php#ref">Приведи друга</a><?php endif; ?>
      <?php if (setting('pickup_address')): ?><span class="muted">Самовывоз: <?= e(setting('pickup_address')) ?></span><?php endif; ?>
    </div>
    <div>
      <h4>Контакты</h4>
      <a href="contacts.php">Контакты и реквизиты</a>
      <?php if ($phone): ?><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $phone)) ?>"><?= e($phone) ?></a><?php endif; ?>
      <?php if (setting('email')): ?><a href="mailto:<?= e(setting('email')) ?>"><?= e(setting('email')) ?></a><?php endif; ?>
      <?php if (setting('telegram_contact')): ?><a href="https://t.me/<?= e(ltrim(setting('telegram_contact'), '@')) ?>" target="_blank" rel="noopener">Telegram <?= e(setting('telegram_contact')) ?></a><?php endif; ?>
    </div>
    <div>
      <h4>Мы на маркетплейсах</h4>
      <div class="mp-row">
      <?php foreach (['ozon' => ['link_ozon_store', 'Ozon'], 'wb' => ['link_wb_store', 'Wildberries'], 'ym' => ['link_ym_store', 'Яндекс Маркет']] as $mp => [$k, $n]): if (setting($k) === '') continue; ?>
        <a class="mp-pill mp-<?= $mp ?>" href="<?= e(setting($k)) ?>" target="_blank" rel="noopener"><?= mp_icon($mp) ?><?= $n ?></a>
      <?php endforeach; ?>
      </div>
    </div>
  </div>
  <div class="wrap footer-bottom muted">© <?= date('Y') ?> <?= e($shop) ?><?php if (setting('shop_alt_name') !== ''): ?> (<?= e(setting('shop_alt_name')) ?>)<?php endif; ?>
    <?php if (setting('legal_name') !== ''): ?><span class="legal"><?= e(setting('legal_name')) ?><?= setting('legal_ogrnip') ? ' · ОГРНИП ' . e(setting('legal_ogrnip')) : '' ?><?= setting('legal_inn') ? ' · ИНН ' . e(setting('legal_inn')) : '' ?><?= setting('legal_address') ? ' · ' . e(setting('legal_address')) : '' ?></span><?php endif; ?></div>
</footer>
<div class="toast" id="toast" role="status" aria-live="polite"></div>
<script src="assets/app.js?v=<?= MB_VERSION ?>"></script>
</body>
</html>
<?php
}

/** Названия видимых категорий (кэш на запрос). */
function cat_titles(): array
{
    static $m = null;
    if ($m === null) { $m = []; foreach (categories_visible() as $c) $m[(int)$c['id']] = $c['title']; }
    return $m;
}

function product_card(array $p): string
{
    $price = site_price($p);
    $basePrice = base_price($p);
    $img = product_image($p);
    $href = product_url($p);
    $sale = $price < $basePrice;
    $low = low_stock_text($p);
    ob_start(); ?>
<?php $vars = variants_of($p); ?>
<article class="card<?= $vars ? ' has-vars' : '' ?>" data-id="<?= (int)$p['id'] ?>">
  <a class="card-img" href="<?= e($href) ?>">
    <?php if ($img): ?><img src="<?= e(img_url($img, 400)) ?>" alt="<?= e($p['name']) ?>" loading="lazy" decoding="async" width="300" height="400"><?php else: ?><span class="noimg">нет фото</span><?php endif; ?>
    <?php if (!empty($p['type_id']) && ($ct = cat_titles()[(int)$p['type_id']] ?? '')): ?><span class="card-cat"><?= e($ct) ?></span><?php endif; ?>
    <?php if ($sale): ?><span class="card-sale">−<?= (int)round((1 - $price / $basePrice) * 100) ?>%</span><?php endif; ?>
  </a>
  <button class="fav-btn" type="button" data-fav="<?= (int)$p['id'] ?>" aria-label="В избранное" title="В избранное"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20.5s-7.5-4.6-9.3-9.2C1.5 8 3.6 4.5 7.1 4.5c2 0 3.6 1.1 4.9 2.8 1.3-1.7 2.9-2.8 4.9-2.8 3.5 0 5.6 3.5 4.4 6.8-1.8 4.6-9.3 9.2-9.3 9.2z"/></svg></button>
  <div class="card-body">
    <div class="price-row">
      <span class="price<?= $sale ? ' price-sale' : '' ?>"><?= money($price) ?></span><?php if ($sale): ?><s class="price-old"><?= money($basePrice) ?></s><?php endif; ?>
    </div>
    <a class="card-title" href="<?= e($href) ?>"><?= e($p['name']) ?></a>
    <?php if ($vars): ?>
    <div class="swatches" role="group" aria-label="Цвета">
      <?php foreach (array_slice($vars, 0, 6) as $i => $v): $vp = site_price($v); $vb = base_price($v); $vi = product_image($v); $bg = swatch_bg($v); ?>
        <a class="sw<?= (int)$v['id'] === (int)$p['id'] ? ' is-on' : '' ?><?= $bg === '' ? ' sw-img' : '' ?>" href="<?= e(product_url($v)) ?>" title="<?= e(variant_label($v, $i)) ?>" aria-label="<?= e(variant_label($v, $i)) ?>"
           data-v="<?= e(json_encode(['id' => (int)$v['id'], 'name' => product_title_full($v), 'title' => (string)$v['name'], 'price' => $vp, 'pf' => money($vp), 'old' => $vp < $vb ? money($vb) : '', 'img' => img_url($vi, 400), 'img2' => img_url($vi, 200), 'code' => (string)$v['site_code'], 'href' => product_url($v)], JSON_UNESCAPED_UNICODE)) ?>"
           <?= $bg !== '' ? 'style="--sw:' . e($bg) . '"' : '' ?>><?php if ($bg === ''): ?><img src="<?= e(img_url($vi, 200)) ?>" alt="" loading="lazy"><?php endif; ?></a>
      <?php endforeach; ?>
      <?php if (count($vars) > 6): ?><a class="sw-more" href="<?= e($href) ?>">+<?= count($vars) - 6 ?></a><?php endif; ?>
      <span class="sw-count"><?= count($vars) ?> <?= plural(count($vars), 'цвет', 'цвета', 'цветов') ?></span>
    </div>
    <?php endif; ?>
    <?php $rm = ratings_map()[(int)$p['id']] ?? null; if ($rm): ?><span class="card-rating"><?= stars($rm['avg']) ?> <?= number_format($rm['avg'], 1, ',', '') ?> <span class="muted">(<?= $rm['n'] ?>)</span></span><?php endif; ?>
    <div class="card-art">Код <?= e((string)$p['site_code']) ?><?php if ($low): ?> · <span class="low-stock"><?= e($low) ?></span><?php endif; ?></div>
    <div class="card-actions">
      <button class="btn btn-accent btn-add" type="button"
        data-id="<?= (int)$p['id'] ?>" data-name="<?= e(product_title_full($p)) ?>" data-price="<?= $price ?>" data-img="<?= e(img_url($img, 200)) ?>" data-code="<?= e((string)$p['site_code']) ?>">В корзину</button>
      <div class="mp-mini">
        <?php if ($l = ozon_link($p)): ?><a href="<?= e($l) ?>" target="_blank" rel="noopener" title="Купить на Ozon"><?= mp_icon('ozon') ?></a><?php endif; ?>
        <?php if ($l = wb_link($p)): ?><a href="<?= e($l) ?>" target="_blank" rel="noopener" title="Купить на Wildberries"><?= mp_icon('wb') ?></a><?php endif; ?>
        <?php if ($l = ym_link($p)): ?><a href="<?= e($l) ?>" target="_blank" rel="noopener" title="Купить на Яндекс Маркете"><?= mp_icon('ym') ?></a><?php endif; ?>
      </div>
    </div>
  </div>
</article>
<?php
    return (string)ob_get_clean();
}
