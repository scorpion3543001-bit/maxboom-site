<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/payment.php';

$free = (float)setting('free_delivery_from');
page_head('Доставка и оплата — ' . setting('shop_name'));
?>
<section class="wrap info-page">
  <h1 class="page-title">Доставка и оплата</h1>
  <div class="info-grid">
    <?php foreach (delivery_methods() as $k => $m): ?>
      <div class="info-card">
        <span class="option-ico dlv-<?= $k ?>"></span>
        <h3><?= e($m['title']) ?></h3>
        <p class="price"><?= ($k === 'cdek' && cdek_ready()) || ($k === 'post' && post_ready()) ? 'По тарифу' : ($m['price'] > 0 ? money($m['price']) : 'Бесплатно') ?></p>
        <p class="muted"><?= e($m['hint']) ?><?= $k === 'pickup' && setting('pickup_hours') ? '<br>' . e(setting('pickup_hours')) : '' ?></p>
      </div>
    <?php endforeach; ?>
  </div>
  <?php if ($free > 0): ?><p class="note">🚚 Доставка бесплатно при заказе от <b><?= money($free) ?></b>.</p><?php endif; ?>

  <h2>Оплата</h2>
  <ul class="plain">
    <?php if (yk_enabled()): ?><li>Онлайн банковской картой или через СБП — безопасно, через ЮKassa. Чек придёт на e-mail.</li><?php endif; ?>
    <?php if (setting('pickup_pay_on_site') === '1' && setting('pickup_enabled') === '1'): ?><li>При самовывозе — оплата в магазине при получении.</li><?php endif; ?>
  </ul>

  <h2>Удобнее на маркетплейсе?</h2>
  <p class="muted">Все наши товары есть на Ozon, Wildberries и Яндекс Маркете — на карточке каждого товара есть ссылки.</p>
  <div class="mp-row">
    <?php foreach (['ozon' => ['link_ozon_store', 'Ozon'], 'wb' => ['link_wb_store', 'Wildberries'], 'ym' => ['link_ym_store', 'Яндекс Маркет']] as $mp => [$k, $n]): if (setting($k) === '') continue; ?>
      <a class="mp-pill mp-<?= $mp ?>" href="<?= e(setting($k)) ?>" target="_blank" rel="noopener"><?= mp_icon($mp) ?><?= $n ?></a>
    <?php endforeach; ?>
  </div>
</section>
<?php page_foot();
