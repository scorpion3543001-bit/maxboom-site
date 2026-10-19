<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/ozon.php';

ref_capture();
page_cache_start();
$p = find_product((int)($_GET['id'] ?? 0));
if (!product_on_sale($p)) {
    http_response_code(404);
    page_head('Товар не найден');
    echo '<section class="wrap"><div class="empty">Товар не найден или снят с продажи. <a href="./">В каталог</a></div></section>';
    page_foot();
    exit;
}
$price = site_price($p);
$basePrice = base_price($p);
$imgs = product_images($p);
$desc = clean_description(ozon_description($p));
$rt = product_rating((int)$p['id']);
$w = wholesale_rule();
$cu = current_user();
$personal = $cu ? (float)$cu['discount_percent'] : 0;
$wPrice = ($w['qty'] > 0 && $w['percent'] > 0) ? max(1, round($price * (1 - max($w['percent'], $personal) / 100))) : 0;
$myPrice = $personal > 0 ? max(1, round($price * (1 - $personal / 100))) : 0;
$ct = !empty($p['type_id']) ? (cat_titles()[(int)$p['type_id']] ?? '') : '';
$canon = abs_url(product_url($p));
$ld = [[
    '@context' => 'https://schema.org', '@type' => 'Product', 'name' => $p['name'], 'sku' => (string)$p['site_code'],
    'image' => array_slice($imgs, 0, 5), 'description' => mb_strimwidth($desc, 0, 500, '…'),
    'brand' => ['@type' => 'Brand', 'name' => setting('shop_name')],
    'offers' => ['@type' => 'Offer', 'url' => $canon, 'priceCurrency' => 'RUB', 'price' => number_format($price, 2, '.', ''),
                 'availability' => 'https://schema.org/InStock', 'itemCondition' => 'https://schema.org/NewCondition'],
]];
if ($rt['n'] > 0) $ld[0]['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => $rt['avg'], 'reviewCount' => $rt['n']];
$crumbs = [['@type' => 'ListItem', 'position' => 1, 'name' => 'Каталог', 'item' => abs_url('')]];
if ($ct) $crumbs[] = ['@type' => 'ListItem', 'position' => 2, 'name' => $ct, 'item' => abs_url(category_url((int)$p['type_id'], $ct))];
$ld[] = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $crumbs];
page_head($p['name'] . ' — купить за ' . money($price) . ' | ' . setting('shop_name'), 'product', [
    'description' => mb_strimwidth($p['name'] . ' — купить за ' . money($price) . ' у производителя MaxBoom. ' . ($desc !== '' ? preg_replace('/\s+/u', ' ', $desc) : 'Собственное производство из кукурузного пластика PLA.') . ' Доставка СДЭК, Почтой России, Ozon по всей России.', 0, 200, '…'),
    'canonical' => $canon, 'image' => $imgs[0] ?? '', 'jsonld' => $ld, 'jsonld_product' => true,
]);
$low = low_stock_text($p);
?>
<section class="wrap product" data-seen="<?= (int)$p['id'] ?>" data-ecom="<?= e(json_encode(['id' => (string)$p['site_code'], 'name' => $p['name'], 'price' => $price, 'category' => $ct], JSON_UNESCAPED_UNICODE)) ?>">
  <nav class="crumbs"><a href="./">Каталог</a><?php if ($ct): ?> <span>/</span> <a href="<?= e(category_url((int)$p['type_id'], $ct)) ?>"><?= e($ct) ?></a><?php endif; ?></nav>
  <div class="product-grid">
    <div class="gallery">
      <div class="gallery-main">
        <?php if ($imgs): ?><img id="galleryMain" src="<?= e(img_url($imgs[0], 1000)) ?>" alt="<?= e($p['name']) ?>" fetchpriority="high"><?php else: ?><span class="noimg">нет фото</span><?php endif; ?>
      </div>
      <?php if (count($imgs) > 1): ?>
      <div class="thumbs">
        <?php foreach (array_slice($imgs, 0, 12) as $i => $src): ?>
          <button type="button" class="thumb<?= $i === 0 ? ' is-active' : '' ?>" data-src="<?= e(img_url($src, 1000)) ?>"><img src="<?= e(img_url($src, 200)) ?>" alt="" loading="lazy"></button>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <div class="buybox">
      <div class="buy-top"><h1><?= e($p['name']) ?></h1><button class="fav-btn fav-lg" type="button" data-fav="<?= (int)$p['id'] ?>" aria-label="В избранное" title="В избранное"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20.5s-7.5-4.6-9.3-9.2C1.5 8 3.6 4.5 7.1 4.5c2 0 3.6 1.1 4.9 2.8 1.3-1.7 2.9-2.8 4.9-2.8 3.5 0 5.6 3.5 4.4 6.8-1.8 4.6-9.3 9.2-9.3 9.2z"/></svg></button></div>
      <?php if ($rt['n'] > 0): ?><a class="rating-line" href="<?= e(product_url($p)) ?>#reviews"><?= stars($rt['avg']) ?> <b><?= number_format($rt['avg'], 1, ',', '') ?></b> <span class="muted"><?= $rt['n'] ?> <?= plural($rt['n'], 'отзыв', 'отзыва', 'отзывов') ?></span></a><?php endif; ?>
      <div class="meta">Код товара <b class="copy" title="Скопировать" data-copy="<?= e((string)$p['site_code']) ?>"><?= e((string)$p['site_code']) ?></b> — назовите его, если звоните нам</div>

      <?php $vars = variants_of($p); if ($vars): $cur = 0; foreach ($vars as $i => $v) if ((int)$v['id'] === (int)$p['id']) $cur = $i; ?>
      <div class="colors">
        <div class="colors-head">Цвет: <b><?= e(variant_label($p, $cur)) ?></b><span class="muted"><?= count($vars) ?> <?= plural(count($vars), 'вариант', 'варианта', 'вариантов') ?></span></div>
        <div class="colors-list">
          <?php foreach ($vars as $i => $v): $bg = swatch_bg($v); ?>
            <a class="cv<?= $i === $cur ? ' is-on' : '' ?>" href="<?= e(product_url($v)) ?>" title="<?= e(variant_label($v, $i)) ?>"<?= $i === $cur ? ' aria-current="true"' : '' ?>>
              <span class="cv-img"><img src="<?= e(img_url(product_image($v), 200)) ?>" alt="" loading="lazy"></span>
              <?php if ($bg !== ''): ?><span class="cv-dot" style="--sw:<?= e($bg) ?>"></span><?php endif; ?>
              <span class="cv-name"><?= e(variant_label($v, $i)) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <div class="buy-price">
        <?php if ($myPrice): ?>
          <span class="price price-lg"><?= money($myPrice) ?></span><s class="muted"><?= money($price) ?></s>
          <span class="save">Ваша персональная скидка −<?= e(rtrim(rtrim(number_format($personal, 1, ',', ''), '0'), ',')) ?>%</span>
        <?php else: ?>
          <span class="price price-lg<?= $price < $basePrice ? ' price-sale' : '' ?>"><?= money($price) ?></span><?php if ($price < $basePrice): ?><s class="muted"><?= money($basePrice) ?></s><?php endif; ?>
        <?php endif; ?>
        <?php if (($sl = sale_active()) && $price < $basePrice): ?><div class="sale-line"><?= e($sl['title']) ?> −<?= e(rtrim(rtrim(number_format($sl['percent'], 1, ',', ''), '0'), ',')) ?>% · ещё <span class="countdown" data-until="<?= $sl['until'] * 1000 ?>"></span></div><?php endif; ?>
        <?php if ($low): ?><div class="low-line"><span class="low-dot"></span><?= e($low) ?> — успейте заказать</div><?php endif; ?>
        <?php if (bonus_on() && (float)setting('bonus_percent') > 0): ?><div class="bonus-line">+<?= (int)floor($price * (float)setting('bonus_percent') / 100) ?> баллов на следующую покупку</div><?php endif; ?>
        <?php if ($wPrice && $w['percent'] > $personal): ?>
          <div class="opt-line"><span class="opt-badge">Опт</span> от <?= $w['qty'] ?> шт — <b><?= money($wPrice) ?></b>/шт <span class="muted">(−<?= e(rtrim(rtrim(number_format($w['percent'], 1, ',', ''), '0'), ',')) ?>%)</span></div>
        <?php endif; ?>
      </div>

      <div class="qty-row">
        <div class="qty" data-qty>
          <button type="button" data-d="-1" aria-label="Меньше">−</button>
          <input type="number" min="1" max="999" value="1" id="qtyInput" aria-label="Количество">
          <button type="button" data-d="1" aria-label="Больше">+</button>
        </div>
        <button class="btn btn-accent btn-lg btn-add" type="button" data-with-qty
          data-id="<?= (int)$p['id'] ?>" data-name="<?= e(product_title_full($p)) ?>" data-price="<?= $price ?>" data-img="<?= e(img_url($imgs[0] ?? '', 200)) ?>" data-code="<?= e((string)$p['site_code']) ?>">В корзину</button>
      </div>

      <div class="mp-buy">
        <span class="muted">Или купите на маркетплейсе:</span>
        <div class="mp-row">
          <?php if ($l = ozon_link($p)): ?><a class="mp-pill mp-ozon" href="<?= e($l) ?>" target="_blank" rel="noopener"><?= mp_icon('ozon') ?>Ozon</a><?php endif; ?>
          <?php if ($l = wb_link($p)): ?><a class="mp-pill mp-wb" href="<?= e($l) ?>" target="_blank" rel="noopener"><?= mp_icon('wb') ?>Wildberries</a><?php endif; ?>
          <?php if ($l = ym_link($p)): ?><a class="mp-pill mp-ym" href="<?= e($l) ?>" target="_blank" rel="noopener"><?= mp_icon('ym') ?>Яндекс Маркет</a><?php endif; ?>
        </div>
      </div>

      <ul class="perks">
        <?php foreach (delivery_methods() as $k => $m): ?>
          <li><b><?= e($m['title']) ?></b> — <?= ($k === 'cdek' && cdek_ready()) || ($k === 'post' && post_ready()) ? 'по тарифу, рассчитаем в корзине' : ($m['price'] > 0 ? money($m['price']) : 'бесплатно') ?></li>
        <?php endforeach; ?>
        <?php if ((float)setting('free_delivery_from') > 0): ?><li class="hl-li">Бесплатная доставка от <?= money((float)setting('free_delivery_from')) ?></li><?php endif; ?>
      </ul>
    </div>
  </div>

  <?php if (trim($desc) !== ''): ?>
  <div class="desc">
    <h2>Описание</h2>
    <div class="desc-text"><?= nl2br(e($desc)) ?></div>
  </div>
  <?php endif; ?>
  <div class="specs">
    <h2>Характеристики</h2>
    <dl class="req">
      <dt>Материал</dt><dd><?= e(setting('seo_material')) ?></dd>
      <?php if (!empty($p['color'])): ?><dt>Цвет</dt><dd><?= e((string)$p['color']) ?><?php if (($vars = variants_of($p)) && count($vars) > 1): ?> <span class="muted">(ещё <?= count($vars) - 1 ?> <?= plural(count($vars) - 1, 'цвет', 'цвета', 'цветов') ?>)</span><?php endif; ?></dd><?php endif; ?>
      <?php if (!empty($p['weight_g'])): ?><dt>Вес</dt><dd><?= (int)$p['weight_g'] ?> г</dd><?php endif; ?>
      <?php if ($ct): ?><dt>Категория</dt><dd><a href="<?= e(category_url((int)$p['type_id'], $ct)) ?>"><?= e($ct) ?></a></dd><?php endif; ?>
      <dt>Артикул</dt><dd><?= e((string)$p['site_code']) ?></dd>
      <dt>Производство</dt><dd>MaxBoom, Москва — 3D-печать</dd>
      <dt>Доставка</dt><dd>СДЭК, Почта России, Ozon по всей России<?= (float)setting('free_delivery_from') > 0 ? '; бесплатно от ' . money((float)setting('free_delivery_from')) : '' ?></dd>
    </dl>
  </div>

  <?php foreach (bundles_on_sale((int)$p['id']) as $b): ?>
  <div class="bundle" data-bundle="<?= e(json_encode(array_map(fn($x) => ['id' => (int)$x['id'], 'name' => $x['name'], 'price' => site_price($x), 'img' => img_url(product_image($x), 200), 'code' => (string)$x['site_code']], $b['products']), JSON_UNESCAPED_UNICODE)) ?>">
    <div class="bundle-head"><h2><?= e($b['title'] ?: 'Набор') ?></h2><span class="bundle-badge">−<?= e(rtrim(rtrim(number_format((float)$b['percent'], 1, ',', ''), '0'), ',')) ?>% за набор</span></div>
    <div class="bundle-items">
      <?php foreach ($b['products'] as $i => $x): ?><?= $i ? '<span class="bundle-plus" aria-hidden="true">+</span>' : '' ?>
        <a class="bundle-item" href="<?= e(product_url($x)) ?>"><img src="<?= e(img_url(product_image($x), 200)) ?>" alt="" loading="lazy"><span><?= e(mb_strimwidth($x['name'], 0, 44, '…')) ?></span><b><?= money(site_price($x)) ?></b></a>
      <?php endforeach; ?>
    </div>
    <div class="bundle-foot"><span class="muted"><s><?= money($b['sum']) ?></s></span><b class="price"><?= money($b['price']) ?></b><button class="btn btn-accent btn-bundle" type="button">Добавить набор в корзину</button></div>
  </div>
  <?php endforeach; ?>

  <?php $fbt = bought_together($p, 3); if ($fbt): $all = array_merge([$p], $fbt); ?>
  <div class="fbt" data-bundle="<?= e(json_encode(array_map(fn($x) => ['id' => (int)$x['id'], 'name' => $x['name'], 'price' => site_price($x), 'img' => img_url(product_image($x), 200), 'code' => (string)$x['site_code']], $all), JSON_UNESCAPED_UNICODE)) ?>">
    <h2>Часто покупают вместе</h2>
    <div class="fbt-row">
      <?php foreach ($all as $i => $x): ?>
        <label class="fbt-item"><input type="checkbox" checked data-i="<?= $i ?>"><img src="<?= e(img_url(product_image($x), 200)) ?>" alt="" loading="lazy"><span><?= e(mb_strimwidth($x['name'], 0, 40, '…')) ?></span><b><?= money(site_price($x)) ?></b></label>
      <?php endforeach; ?>
    </div>
    <div class="fbt-foot"><span>Вместе: <b class="fbt-sum"><?= money(array_sum(array_map('site_price', $all))) ?></b></span><button class="btn btn-accent btn-bundle" type="button">Добавить выбранные</button></div>
  </div>
  <?php endif; ?>

  <?php if (setting('reviews_enabled') === '1'):
      $rs = db()->prepare("SELECT * FROM reviews WHERE product_id = ? AND status = 'approved' ORDER BY id DESC LIMIT 50");
      $rs->execute([$p['id']]);
      $revs = $rs->fetchAll();
      $mine = null;
      if ($cu) { $m = db()->prepare('SELECT status FROM reviews WHERE product_id = ? AND user_id = ? ORDER BY id DESC LIMIT 1'); $m->execute([$p['id'], $cu['id']]); $mine = $m->fetchColumn() ?: null; }
  ?>
  <div class="reviews" id="reviews">
    <div class="reviews-head">
      <h2>Отзывы<?= $rt['n'] ? ' <small>' . $rt['n'] . '</small>' : '' ?></h2>
      <?php if ($rt['n']): ?><div class="reviews-avg"><b><?= number_format($rt['avg'], 1, ',', '') ?></b><?= stars($rt['avg'], 'stars-lg') ?></div><?php endif; ?>
    </div>
    <?php if (!$revs): ?><p class="muted">Отзывов пока нет. Купили этот товар? Расскажите, как он вам.</p><?php endif; ?>
    <div class="review-list">
      <?php foreach ($revs as $r): ?>
        <article class="review">
          <header><b><?= e($r['name'] ?: 'Покупатель') ?></b><?= stars((float)$r['rating']) ?><?php if ($r['verified']): ?><span class="tag ok">Купил на сайте</span><?php elseif (($r['source'] ?? '') === 'ozon'): ?><span class="tag tag-ozon">Отзыв с Ozon</span><?php endif; ?><time class="muted"><?= e(date('d.m.Y', strtotime($r['created_at']))) ?></time></header>
          <p><?= nl2br(e($r['text'])) ?></p>
          <?php if ($r['reply']): ?><div class="review-reply"><b><?= e(setting('shop_name')) ?></b><p><?= nl2br(e($r['reply'])) ?></p></div><?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
    <?php if (!$cu): ?>
      <p class="review-cta"><a class="btn" href="account.php">Войдите</a>, чтобы оставить отзыв.</p>
    <?php elseif ($mine === 'pending'): ?>
      <p class="alert ok">Спасибо! Ваш отзыв появится после проверки.</p>
    <?php elseif ($mine === 'approved'): ?>
    <?php else: ?>
      <form class="review-form" id="reviewForm">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
        <b>Ваша оценка</b>
        <div class="rate-pick" role="radiogroup" aria-label="Оценка">
          <?php for ($i = 5; $i >= 1; $i--): ?><input type="radio" name="rating" id="r<?= $i ?>" value="<?= $i ?>" <?= $i === 5 ? 'checked' : '' ?>><label for="r<?= $i ?>" title="<?= $i ?>">★</label><?php endfor; ?>
        </div>
        <label>Отзыв<textarea name="text" rows="4" maxlength="2000" required placeholder="Что понравилось, как пользуетесь, что можно улучшить"></textarea></label>
        <button class="btn btn-accent">Отправить отзыв</button>
      </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</section>
<?php
$rel = [];
if (!empty($p['type_id'])) {
    $st = db()->prepare('SELECT * FROM products WHERE type_id = ? AND id <> ? AND ' . visible_where() . ' ORDER BY ABS(id - ' . (int)$p['id'] . ') LIMIT 8');
    $st->execute([$p['type_id'], $p['id']]);
    $rel = $st->fetchAll();
}
if (count($rel) < 4) {
    $st = db()->prepare('SELECT * FROM products WHERE id <> ? AND ' . visible_where() . ' ORDER BY RANDOM() LIMIT ' . (8 - count($rel)));
    $st->execute([$p['id']]);
    foreach ($st->fetchAll() as $r) if (!in_array($r['id'], array_column($rel, 'id'))) $rel[] = $r;
}
if ($rel): ?>
<section class="wrap section">
  <div class="section-head"><h2><?= $ct ? 'Похожие товары: ' . e($ct) : 'Вам может понравиться' ?></h2><?php if ($ct): ?><a class="more-link" href="<?= e(category_url((int)$p['type_id'], $ct)) ?>">Все <?= e(mb_strtolower($ct)) ?></a><?php endif; ?></div>
  <div class="shelf"><?php foreach ($rel as $r) echo product_card($r); ?></div>
</section>
<?php endif; ?>
<section class="wrap section" id="seenBlock" hidden>
  <div class="section-head"><h2>Вы смотрели</h2></div>
  <div class="shelf" id="seenShelf"></div>
</section>
<?php page_foot();
