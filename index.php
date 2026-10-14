<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/catalog.php';

ref_capture();
page_cache_start();
$q = trim((string)($_GET['q'] ?? ''));
$sort = (string)($_GET['sort'] ?? 'new');
$colTitle = $q !== '' ? mb_substr(trim((string)($_GET['t'] ?? '')), 0, 60) : '';
$colKey = '';
if (($cd = collection_by_key((string)($_GET['col'] ?? ''))) !== null) { $q = $cd['q']; $colTitle = $cd['title']; $colKey = $cd['key']; }
$linkQ = $colKey !== '' ? ['col' => $colKey] : ['q' => $q, 't' => $colTitle];
$cat = max(0, (int)($_GET['cat'] ?? 0));
$page = max(1, (int)($_GET['page'] ?? 1));
[$items, $total] = catalog_query($q, $sort, $page, CATALOG_PER_PAGE, $cat);
$perPage = CATALOG_PER_PAGE;
$catTitle = $cat ? category_title($cat) : '';
$isHome = $q === '' && $cat === 0 && $page === 1;
$cats = categories_visible();

$title = $colTitle !== '' ? $colTitle . ' — ' . setting('shop_name') : ($q !== '' ? "Поиск «{$q}» — " . setting('shop_name')
       : ($catTitle !== '' ? $catTitle . ' — купить в интернет-магазине ' . setting('shop_name') : (setting('seo_title') ?: setting('shop_name') . (setting('shop_alt_name') !== '' ? ' (' . setting('shop_alt_name') . ')' : '') . ' — ' . setting('shop_tagline'))));
$meta = ['noindex' => ($q !== '' && $colKey === '') || $page > 1 || isset($_GET['sort'])];
if ($cat && $catTitle !== '') {
    $meta['canonical'] = abs_url(category_url($cat, $catTitle));
    $meta['description'] = $catTitle . ': ' . $total . ' ' . plural($total, 'товар', 'товара', 'товаров') . ' собственного производства из безопасного кукурузного пластика. Доставка СДЭК, Ozon, Почтой России.';
} elseif ($isHome) {
    $meta['canonical'] = abs_url('');
    $alt = setting('shop_alt_name');
    $names = $alt !== '' ? [$alt, 'MaxBoom Shop'] : ['MaxBoom Shop'];
    $sameAs = array_values(array_filter([setting('link_ozon_store'), setting('link_wb_store'), setting('link_ym_store')]));
    if (setting('seo_description') === '') {
        $meta['description'] = setting('shop_name') . ($alt !== '' ? ' (' . $alt . ')' : '') . ' — собственное производство из безопасного кукурузного пластика: брелоки, фигурки, кашпо, органайзеры и подарки. Доставка СДЭК, Ozon, Почтой России.';
    }
    $meta['jsonld'] = [
        ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => setting('shop_name'), 'alternateName' => $names, 'url' => abs_url(''),
         'inLanguage' => 'ru', 'potentialAction' => ['@type' => 'SearchAction', 'target' => abs_url('') . '?q={search_term_string}', 'query-input' => 'required name=search_term_string']],
        ['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => setting('shop_name'), 'alternateName' => $names, 'url' => abs_url(''),
         'logo' => abs_url('assets/favicon.svg'), 'sameAs' => $sameAs]
            + (setting('phone') ? ['telephone' => setting('phone')] : []),
    ];
}
$meta['image'] = product_image($items[0] ?? ['images' => '[]']);
page_head($title, 'home', $meta);

$icons = [
  'factory' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 21V10l6 3V10l6 3V6l6-3v18z"/><path d="M7 17h2M12 17h2M17 17h2"/></svg>',
  'leaf'    => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 19c8 0 14-6 14-15C10 4 5 9 5 15v4z"/><path d="M5 19 13 11"/></svg>',
  'truck'   => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 6h11v10H2zM13 10h4l4 3v3h-8z"/><circle cx="6" cy="18" r="2"/><circle cx="17" cy="18" r="2"/></svg>',
  'card'    => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 10h19M6 15h4"/></svg>',
];
?>
<div class="wrap shop">
<?= cat_sidebar($cat, false, $q) ?>
<div class="shop-main">
<?php if ($isHome): ?>
<?php $slides = banners(); $arrow = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>'; ?>
<section class="hero" aria-label="Подборки товаров" aria-roledescription="карусель">
  <h1 class="sr-only"><?= e(setting('shop_name')) ?><?php if (setting('shop_alt_name') !== ''): ?> (<?= e(setting('shop_alt_name')) ?>)<?php endif; ?> — собственное производство из безопасного кукурузного пластика</h1>
  <div class="bn" id="bn" data-dur="6000">
    <?php foreach ($slides as $i => $b):
      $imgs = $b['image'] ? [$b['image']] : (is_array($b['images']) ? $b['images'] : banner_images((int)$b['cat'], 3, (string)$b['q']));
      $imgs = array_slice($imgs, 0, 3);
      $word = preg_match('/\d{4}/', $b['title'], $wm) ? $wm[0] : mb_strtoupper((string)(preg_split('/[\s-]+/u', trim(preg_replace('/[^\pL\s-]/u', '', $b['title'])))[0] ?? ''));
      $cnt = (int)($b['count'] ?? 0); ?>
    <a class="bn-slide t-<?= e($b['theme']) ?><?= $i === 0 ? ' is-on' : '' ?>" href="<?= e($b['link'] ?: '#catalog') ?>" aria-hidden="<?= $i === 0 ? 'false' : 'true' ?>"<?= $i ? ' tabindex="-1"' : '' ?> aria-label="<?= e($b['title']) ?>">
      <?php if ($b['theme'] === 'newyear'): ?><span class="bn-garland" aria-hidden="true"></span><?php endif; ?>
      <span class="bn-word" aria-hidden="true"><?= e($word) ?></span>
      <span class="bn-blob" aria-hidden="true"></span>
      <span class="bn-text">
        <?php if ($b['kicker']): ?><span class="bn-kicker"><?= e($b['kicker']) ?></span><?php endif; ?>
        <h2 class="bn-title"><?= e($b['title']) ?></h2>
        <?php if ($b['text']): ?><span class="bn-desc"><?= e($b['text']) ?></span><?php endif; ?>
        <span class="bn-cta"><?= e($b['btn'] ?: 'Смотреть') ?><?= $arrow ?></span>
      </span>
      <span class="bn-stage n<?= count($imgs) ?>" aria-hidden="true">
        <?php foreach ($imgs as $k => $src): ?><span class="bn-card c<?= $k ?>"><img src="<?= e(img_url($src, 400)) ?>" alt="" loading="<?= $i ? 'lazy' : 'eager' ?>"<?= $i === 0 && $k === 0 ? ' fetchpriority="high"' : '' ?>></span><?php endforeach; ?>
        <?php if ($cnt > 1): ?><span class="bn-sticker"><b><?= $cnt ?></b><?= plural($cnt, 'товар', 'товара', 'товаров') ?></span><?php endif; ?>
      </span>
    </a>
    <?php endforeach; ?>
    <?php if (count($slides) > 1): ?>
      <button class="bn-arrow bn-prev" type="button" aria-label="Предыдущий баннер"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg></button>
      <button class="bn-arrow bn-next" type="button" aria-label="Следующий баннер"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 5l7 7-7 7"/></svg></button>
    <?php endif; ?>
  </div>
  <?php if (count($slides) > 1): ?>
  <div class="bn-tabs" role="tablist" aria-label="Выбрать подборку">
    <?php foreach ($slides as $i => $b): $ti = $b['image'] ?: ((is_array($b['images']) ? $b['images'] : [])[0] ?? ''); ?>
      <button type="button" role="tab" class="bn-tab t-<?= e($b['theme']) ?><?= $i === 0 ? ' is-on' : '' ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>">
        <span class="bn-tab-ico"><?php if ($ti): ?><img src="<?= e(img_url($ti, 200)) ?>" alt="" loading="lazy"><?php endif; ?></span>
        <span class="bn-tab-t"><?= e($b['title']) ?></span><i class="bn-prog"></i>
      </button>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<?php $tick = array_values(array_filter(array_map('trim', preg_split('/[·|•]/u', setting('ticker'))))); if ($tick): ?>
<div class="ticker" aria-hidden="true"><div class="ticker-track">
  <?php for ($k = 0; $k < 2; $k++) foreach ($tick as $t) echo '<span>' . e($t) . '</span>'; ?>
</div></div>
<?php endif; ?>

<?php [$fresh] = catalog_query('', 'new', 1, 12); if (count($fresh) >= 4): ?>
<section class="wrap section">
  <div class="section-head"><h2>Новинки</h2><a class="more-link" href="?sort=new#catalog">Все новинки</a></div>
  <div class="shelf"><?php foreach ($fresh as $p) echo product_card($p); ?></div>
</section>
<?php endif; ?>

<?php // Подборки по категориям: «Кашпо» — и ряд этих товаров
foreach (array_slice(array_values(array_filter($cats, fn($c) => (int)$c['cnt'] >= 3)), 0, 10) as $c):
    [$row, $rowTotal] = catalog_query('', 'new', 1, 14, (int)$c['id']);
    if (count($row) < 3) continue; ?>
<section class="wrap section cat-row">
  <div class="section-head">
    <h2><?= e($c['title']) ?> <small><?= $rowTotal ?></small></h2>
    <a class="more-link" href="<?= e(category_url((int)$c['id'], $c['title'])) ?>">Смотреть все</a>
  </div>
  <div class="shelf"><?php foreach ($row as $p) echo product_card($p); ?></div>
</section>
<?php endforeach; ?>

<?php $bset = array_slice(bundles_on_sale(), 0, 6); if ($bset): ?>
<section class="wrap section">
  <div class="section-head"><h2>Наборы со скидкой</h2></div>
  <div class="shelf shelf-bundles">
    <?php foreach ($bset as $b): ?>
      <div class="bcard" data-bundle="<?= e(json_encode(array_map(fn($x) => ['id' => (int)$x['id'], 'name' => $x['name'], 'price' => site_price($x), 'img' => img_url(product_image($x), 200), 'code' => (string)$x['site_code']], $b['products']), JSON_UNESCAPED_UNICODE)) ?>">
        <div class="bcard-imgs"><?php foreach (array_slice($b['products'], 0, 3) as $x): ?><img src="<?= e(img_url(product_image($x), 200)) ?>" alt="" loading="lazy"><?php endforeach; ?></div>
        <span class="bundle-badge">−<?= e(rtrim(rtrim(number_format((float)$b['percent'], 1, ',', ''), '0'), ',')) ?>%</span>
        <b class="bcard-title"><?= e($b['title'] ?: 'Набор') ?></b>
        <small class="muted"><?= e(mb_strimwidth(implode(' + ', array_map(fn($x) => $x['name'], $b['products'])), 0, 90, '…')) ?></small>
        <div class="bcard-foot"><b class="price"><?= money($b['price']) ?></b><s class="muted"><?= money($b['sum']) ?></s></div>
        <button class="btn btn-accent btn-bundle" type="button">В корзину набором</button>
      </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<section class="wrap section" id="seenBlock" hidden>
  <div class="section-head"><h2>Вы смотрели</h2></div>
  <div class="shelf" id="seenShelf"></div>
</section>

<section class="wrap section">
  <div class="perks-band">
    <div class="perk"><?= $icons['factory'] ?><b>Собственное производство</b><span>Делаем всё сами, поэтому цены ниже, а качество под контролем.</span></div>
    <div class="perk"><?= $icons['leaf'] ?><b>Кукурузный пластик PLA</b><span>Безопасный материал растительного происхождения из кукурузного крахмала.</span></div>
    <div class="perk"><?= $icons['truck'] ?><b>Доставка по России</b><span>СДЭК, Ozon и Почта России<?= setting('pickup_enabled') === '1' ? ', есть самовывоз' : '' ?>.</span></div>
    <div class="perk"><?= $icons['card'] ?><b>Удобная оплата</b><span>Картой или СБП онлайн<?= setting('pickup_pay_on_site') === '1' ? ', при самовывозе — на месте' : '' ?>.</span></div>
  </div>
</section>

<?php [$picks] = catalog_query('', 'random', 1, 8); if (count($picks) >= 4): ?>
<section class="wrap section">
  <div class="section-head"><h2>Рекомендуем</h2></div>
  <div class="grid"><?php foreach ($picks as $p) echo product_card($p); ?></div>
</section>
<?php endif; ?>

<?php if (setting('reviews_enabled') === '1'):
    $lr = db()->query("SELECT r.*, p.name AS pname, p.images FROM reviews r JOIN products p ON p.id = r.product_id WHERE r.status = 'approved' AND r.rating >= 4 ORDER BY r.id DESC LIMIT 8")->fetchAll();
    if ($lr): ?>
<section class="wrap section">
  <div class="section-head"><h2>Отзывы покупателей</h2></div>
  <div class="shelf shelf-reviews">
    <?php foreach ($lr as $r): $im = json_decode((string)$r['images'], true)[0] ?? ''; ?>
      <a class="rcard" href="product.php?id=<?= (int)$r['product_id'] ?>#reviews">
        <header><?= stars((float)$r['rating']) ?><?php if ($r['verified']): ?><span class="tag ok">Купил на сайте</span><?php elseif (($r['source'] ?? '') === 'ozon'): ?><span class="tag tag-ozon">с Ozon</span><?php endif; ?></header>
        <p><?= e(mb_strimwidth($r['text'], 0, 220, '…')) ?></p>
        <footer><?php if ($im): ?><img src="<?= e(img_url($im, 200)) ?>" alt="" loading="lazy"><?php endif; ?><span><b><?= e($r['name'] ?: 'Покупатель') ?></b><small class="muted"><?= e(mb_strimwidth($r['pname'], 0, 40, '…')) ?></small></span></footer>
      </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; endif; ?>

<?php if (gift_on()): ?>
<section class="wrap section">
  <a class="gift-band" href="gift.php">
    <span class="gift-card-art" aria-hidden="true"><b>MAX<i>BOOM</i></b><span><?= money((float)(gift_nominals()[1] ?? gift_nominals()[0])) ?></span></span>
    <span class="gift-band-text"><b>Подарочный сертификат</b><span>Пусть выберут сами. Код придёт на e-mail сразу после оплаты, действует <?= (int)setting('gift_days') ?> дней.</span></span>
    <span class="btn btn-accent">Выбрать номинал</span>
  </a>
</section>
<?php endif; ?>

<section class="wrap section">
  <div class="section-head"><h2>Как заказать</h2></div>
  <div class="steps">
    <div class="stepc"><b>Выберите товары</b><span>Добавьте в корзину всё, что понравилось. Найти товар можно по названию или коду.</span></div>
    <div class="stepc"><b>Оформите заказ</b><span>Укажите способ получения и оплатите картой или через СБП.</span></div>
    <div class="stepc"><b>Получите посылку</b><span>Отправим в пункт выдачи СДЭК, Ozon или на почту — или заберите сами.</span></div>
  </div>
</section>
<?php endif; ?>

<section class="wrap section catalog" id="catalog">
  <div class="section-head">
    <h2><?= $colTitle !== '' ? e($colTitle) : ($q !== '' ? 'Поиск: «' . e($q) . '»' : ($catTitle !== '' ? e($catTitle) : 'Весь каталог')) ?> <small><?= $total ?></small></h2>
    <div class="sort">
      <?php foreach (['new' => 'Новинки', 'cheap' => 'Сначала дешевле', 'expensive' => 'Сначала дороже'] + (sale_active() ? ['discount' => 'Со скидкой'] : []) as $k => $t): ?>
        <a class="chip<?= $sort === $k ? ' is-active' : '' ?>" href="?<?= e(http_build_query(array_filter($linkQ + ['cat' => $cat ?: null, 'sort' => $k]))) ?>#catalog"><?= $t ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php if (!$items): ?>
    <div class="empty">
      <?php if ($q !== ''): ?>По запросу «<?= e($q) ?>» ничего нет. Проверьте код товара или напишите часть названия.
      <?php else: ?>Скоро здесь появятся товары.<?php endif; ?>
    </div>
  <?php else: ?>
    <div class="grid" id="grid">
      <?php foreach ($items as $p) echo product_card($p); ?>
    </div>
    <?php if ($page * $perPage < $total): ?>
      <div class="more">
        <a class="btn btn-ghost" id="loadMore" data-page="<?= $page + 1 ?>" data-q="<?= e($q) ?>" data-sort="<?= e($sort) ?>" data-cat="<?= $cat ?>"
           href="?<?= e(http_build_query(array_filter($linkQ + ['cat' => $cat ?: null, 'sort' => $sort, 'page' => $page + 1]))) ?>#catalog">Показать ещё</a>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</section>
</div>
</div>
<?php page_foot();
