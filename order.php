<?php
/** Страница заказа: «спасибо», статус оплаты, повторная оплата. Доступ — по секретной ссылке. */
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/payment.php';

$st = db()->prepare('SELECT * FROM orders WHERE number = ?');
$st->execute([(string)($_GET['n'] ?? '')]);
$order = $st->fetch();
if (!$order || !hash_equals((string)$order['token'], (string)($_GET['t'] ?? ''))) {
    http_response_code(404);
    page_head('Заказ не найден');
    echo '<section class="wrap"><div class="empty">Заказ не найден. <a href="./">В каталог</a></div></section>';
    page_foot();
    exit;
}

// Повторная попытка оплаты
if (($_GET['pay'] ?? '') === '1' && $order['payment_method'] === 'online' && empty($order['paid_at']) && yk_enabled() && $order['status'] !== 'cancelled') {
    try { header('Location: ' . yk_create_payment($order)); exit; } catch (Throwable $e) { $payErr = true; }
}

if ($order['payment_method'] === 'online' && empty($order['paid_at'])) {
    yk_refresh_order($order);
    $st->execute([$order['number']]);
    $order = $st->fetch();
}
$items = json_decode($order['items'], true) ?: [];
$methods = delivery_methods();
$paid = !empty($order['paid_at']);
$payErr = !empty($payErr) || !empty($_GET['payerr']);
$statuses = order_statuses();

$isGift = ($order['kind'] ?? 'goods') === 'gift';
$gifts = [];
if ($isGift && $paid) { $g = db()->prepare("SELECT * FROM gift_cards WHERE order_id = ? AND status <> 'pending'"); $g->execute([$order['id']]); $gifts = $g->fetchAll(); }
$cu = current_user();
$tgLink = '';
if (tg_customers_on() && empty($order['tg_chat_id']) && !($cu && !empty($cu['tg_chat_id'])) && $order['status'] !== 'cancelled' && !$isGift) {
    try { $tgLink = tg_link_url(null, (int)$order['id']); } catch (Throwable) {}
}
page_head('Заказ ' . $order['number'] . ' — ' . setting('shop_name'), '', ['noindex' => true]);
?>
<section class="wrap order-page">
  <div class="order-hero <?= $paid || $order['payment_method'] !== 'online' ? 'ok' : 'wait' ?>">
    <div class="order-ico"><?= $paid || $order['payment_method'] !== 'online' ? '✓' : '₽' ?></div>
    <div>
      <h1>Заказ <?= e($order['number']) ?> <?= $paid ? 'оплачен' : 'оформлен' ?></h1>
      <p class="muted">Статус: <b><?= e($statuses[$order['status']] ?? $order['status']) ?></b>.
        <?php if ($order['payment_method'] === 'online' && !$paid): ?>
          Оплата ещё не поступила.
        <?php elseif ($isGift): ?>
          <?= $paid ? 'Сертификат активирован — код ниже и в письме.' : 'После оплаты пришлём код на e-mail.' ?>
        <?php elseif ($order['payment_method'] === 'onsite'): ?>
          Оплата при получении в магазине — мы сообщим, когда заказ будет готов.
        <?php elseif ($order['payment_method'] === 'manual'): ?>
          Мы свяжемся с вами для подтверждения и оплаты.
        <?php else: ?>
          Спасибо! Мы уже собираем ваш заказ.
        <?php endif; ?>
      </p>
      <?php foreach ($gifts as $g): ?>
        <div class="gift-code-box"><span class="muted">Код сертификата на <?= money((float)$g['amount']) ?></span><b class="copy" data-copy="<?= e($g['code']) ?>" title="Скопировать"><?= e($g['code']) ?></b>
          <small class="muted">Действует до <?= e(date('d.m.Y', strtotime((string)$g['expires_at']))) ?><?= $g['recipient_email'] ? ' · отправили на ' . e($g['recipient_email']) : '' ?></small></div>
      <?php endforeach; ?>
      <?php if ($tgLink): ?><a class="btn tg-btn" href="<?= e($tgLink) ?>" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21.5 4.2 2.9 11.4c-1 .4-1 1.9.1 2.2l4.6 1.4 1.8 5.6c.3.8 1.3 1 1.9.4l2.6-2.5 4.8 3.5c.7.5 1.7.1 1.9-.7l3.2-15.4c.2-1-.8-1.8-1.7-1.5z"/></svg>Следить за заказом в Telegram</a><?php endif; ?>
      <?php if ($payErr): ?><div class="alert">Не удалось перейти к оплате. Попробуйте ещё раз или свяжитесь с нами.</div><?php endif; ?>
      <?php if ($order['payment_method'] === 'online' && !$paid && $order['status'] !== 'cancelled'): ?>
        <a class="btn btn-accent btn-lg" href="order.php?n=<?= e(rawurlencode($order['number'])) ?>&t=<?= e($order['token']) ?>&pay=1">Оплатить <?= money((float)$order['total']) ?></a>
      <?php endif; ?>
    </div>
  </div>

  <div class="order-body">
    <div class="order-items">
      <?php foreach ($items as $it): ?>
        <div class="oi"><span><?= e($it['name']) ?> <?php if (!empty($it['code'])): ?><small class="muted">код <?= e($it['code']) ?></small><?php endif; ?></span><span><?= (int)$it['qty'] ?> × <?= money((float)$it['price']) ?><?php if (!empty($it['pct'])): ?> <small class="muted">(−<?= +$it['pct'] ?>%)</small><?php endif; ?></span></div>
      <?php endforeach; ?>
      <?php if (!empty($order['coupon_code'])): ?><div class="oi"><span>Промокод <?= e($order['coupon_code']) ?></span><span class="ok-text">учтён</span></div><?php endif; ?>
      <?php if ((float)($order['bonus_used'] ?? 0) > 0): ?><div class="oi"><span>Оплачено баллами</span><span class="ok-text">−<?= money((float)$order['bonus_used']) ?></span></div><?php endif; ?>
      <?php if ((float)($order['discount_total'] ?? 0) > 0): ?><div class="oi"><span>Ваша выгода</span><span class="ok-text">−<?= money((float)$order['discount_total']) ?></span></div><?php endif; ?>
      <?php if (!$isGift): ?><div class="oi"><span>Доставка: <?= e($methods[$order['delivery_method']]['title'] ?? $order['delivery_method']) ?></span><span><?= money((float)$order['delivery_price']) ?></span></div><?php endif; ?>
      <?php if ((float)($order['gift_used'] ?? 0) > 0): ?><div class="oi"><span>Сертификат <?= e((string)$order['gift_code']) ?></span><span class="ok-text">−<?= money((float)$order['gift_used']) ?></span></div><?php endif; ?>
      <div class="oi oi-total"><span>Итого</span><span><?= money((float)$order['total']) ?></span></div>
    </div>
    <div class="order-info">
      <p><b><?= e($order['customer_name']) ?></b><br><?= e($order['phone']) ?><?= $order['email'] ? '<br>' . e($order['email']) : '' ?></p>
      <?php if ($isGift): ?>
        <p>Сертификат придёт на <?= e((string)$order['delivery_address']) ?> сразу после оплаты.</p>
      <?php elseif ($order['delivery_method'] === 'pickup'): ?>
        <p>Самовывоз: <?= e(setting('pickup_address')) ?><br><span class="muted"><?= e(setting('pickup_hours')) ?></span></p>
      <?php elseif ($order['delivery_address']): ?>
        <p>Адрес: <?= e($order['delivery_address']) ?></p>
      <?php endif; ?>
      <p class="tiny muted"><?= current_user() ? 'Заказ сохранён в <a class="link" href="account.php">личном кабинете</a>.' : 'Сохраните эту страницу в закладки — по ней можно проверить статус заказа. Или <a class="link" href="account.php?tab=register">зарегистрируйтесь</a>, чтобы видеть заказы в кабинете.' ?></p>
    </div>
  </div>
</section>
<?php if (!empty($_GET['clear'])): ?><script>try{<?php if (!$isGift): ?>localStorage.removeItem('mb_cart');<?php endif; ?>
var k='mb_ec_<?= (int)$order['id'] ?>';if(!sessionStorage.getItem(k)){sessionStorage.setItem(k,1);window.dataLayer=window.dataLayer||[];
dataLayer.push({ecommerce:{currencyCode:'RUB',purchase:{actionField:{id:<?= json_encode((string)$order['number']) ?>,revenue:<?= (float)$order['total'] ?>},products:<?= json_encode(array_map(fn($it) => ['id' => (string)($it['code'] ?: $it['id']), 'name' => $it['name'], 'price' => (float)$it['price'], 'quantity' => (int)$it['qty']], $items), JSON_UNESCAPED_UNICODE) ?>}}});
if(window.MB&&MB.metrika&&window.ym)ym(MB.metrika,'reachGoal','order');}}catch(e){}</script><?php endif; ?>
<?php page_foot();
