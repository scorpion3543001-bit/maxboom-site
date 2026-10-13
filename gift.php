<?php
/** Подарочные сертификаты: выбор номинала, онлайн-оплата, код приходит на e-mail. */
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/payment.php';

if (!gift_on()) { header('Location: ./'); exit; }
$noms = gift_nominals();
$err = '';
$cu = current_user();
$old = ['amount' => (string)($noms[1] ?? $noms[0]), 'buyer_name' => $cu['name'] ?? '', 'buyer_email' => $cu['email'] ?? '', 'buyer_phone' => $cu['phone'] ?? ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $old = array_merge($old, array_map(fn($v) => trim((string)$v), $_POST));
    $amount = (int)($old['amount'] === 'custom' ? ($old['custom'] ?? 0) : $old['amount']);
    if (!yk_enabled()) $err = 'Онлайн-оплата пока не подключена — напишите нам, оформим сертификат вручную.';
    elseif ($amount < 300 || $amount > 50000) $err = 'Номинал — от 300 до 50 000 ₽.';
    elseif (mb_strlen($old['buyer_name'] ?? '') < 2) $err = 'Укажите ваше имя.';
    elseif (!filter_var($old['buyer_email'] ?? '', FILTER_VALIDATE_EMAIL)) $err = 'Укажите ваш e-mail — на него придёт чек и код.';
    elseif (($old['recipient_email'] ?? '') !== '' && !filter_var($old['recipient_email'], FILTER_VALIDATE_EMAIL)) $err = 'Проверьте e-mail получателя.';
    elseif (!empty($_SESSION['last_order_at']) && time() - $_SESSION['last_order_at'] < 10) $err = 'Подождите несколько секунд и попробуйте снова.';
    else {
        $pdo = db();
        $token = bin2hex(random_bytes(12));
        $items = [['id' => 0, 'offer_id' => 'GIFT', 'code' => '', 'name' => 'Подарочный сертификат на ' . money($amount), 'unit' => $amount, 'price' => $amount, 'pct' => 0, 'qty' => 1]];
        $pdo->prepare("INSERT INTO orders (created_at, status, customer_name, phone, email, delivery_method, delivery_address, delivery_price, items_total, total, payment_method, comment, items, token, user_id, kind)
                       VALUES (?, 'new', ?, ?, ?, 'email', ?, 0, ?, ?, 'online', ?, ?, ?, ?, 'gift')")
            ->execute([now(), mb_substr($old['buyer_name'], 0, 100), mb_substr($old['buyer_phone'] ?? '', 0, 30), $old['buyer_email'],
                       ($old['recipient_email'] ?? '') ?: $old['buyer_email'], $amount, $amount, mb_substr($old['message'] ?? '', 0, 300),
                       json_encode($items, JSON_UNESCAPED_UNICODE), $token, $cu['id'] ?? null]);
        $id = (int)$pdo->lastInsertId();
        $number = 'MB-' . date('ymd') . '-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
        $pdo->prepare('UPDATE orders SET number = ? WHERE id = ?')->execute([$number, $id]);
        $pdo->prepare('INSERT INTO gift_cards (code, amount, balance, buyer_email, recipient_name, recipient_email, message, order_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([gift_new_code(), $amount, $amount, $old['buyer_email'], mb_substr($old['recipient_name'] ?? '', 0, 80), ($old['recipient_email'] ?? '') ?: null,
                       mb_substr($old['message'] ?? '', 0, 300), $id, 'pending', now()]);
        $_SESSION['last_order_at'] = time();
        $st = $pdo->prepare('SELECT * FROM orders WHERE id = ?'); $st->execute([$id]); $order = $st->fetch();
        notify_order($order, '🎁 Сертификат (ждёт оплаты)');
        try { header('Location: ' . yk_create_payment($order)); exit; }
        catch (Throwable $e) { error_log('[MaxBoom] ' . $e->getMessage()); header('Location: order.php?n=' . rawurlencode($number) . '&t=' . $token . '&payerr=1'); exit; }
    }
}

page_head('Подарочный сертификат — ' . setting('shop_name'), 'gift', ['description' => 'Подарочный сертификат ' . setting('shop_name') . ' на ' . implode(', ', array_map(fn($n) => money($n), $noms)) . '. Код приходит на e-mail сразу после оплаты.']);
?>
<section class="wrap gift-page">
  <div class="gift-hero">
    <div class="gift-preview" aria-hidden="true">
      <div class="gift-card-art gift-card-lg"><b>MAX<i>BOOM</i></b><span id="giftPreviewSum"><?= money((float)$old['amount'] ?: $noms[0]) ?></span><small>подарочный сертификат</small></div>
    </div>
    <div>
      <h1 class="page-title">Подарочный сертификат</h1>
      <p class="muted">Подарите возможность выбрать самому: органайзеры, полезные мелочи для дома и всё, что мы делаем из безопасного кукурузного пластика.</p>
      <ul class="plain gift-perks">
        <li>Код придёт на e-mail сразу после оплаты</li>
        <li>Действует <?= (int)setting('gift_days') ?> дней, можно тратить частями</li>
        <li>Вводится в корзине при оформлении заказа</li>
      </ul>
    </div>
  </div>
  <?php if ($err): ?><div class="alert"><?= e($err) ?></div><?php endif; ?>
  <form method="post" class="gift-form" id="giftForm">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <h2 class="step"><span>1</span>Номинал</h2>
    <div class="noms">
      <?php foreach ($noms as $n): ?>
        <label class="nom"><input type="radio" name="amount" value="<?= $n ?>" <?= (string)$n === (string)$old['amount'] ? 'checked' : '' ?>><span><?= money($n) ?></span></label>
      <?php endforeach; ?>
      <label class="nom nom-custom"><input type="radio" name="amount" value="custom" <?= $old['amount'] === 'custom' ? 'checked' : '' ?>><span>Своя сумма <input type="number" name="custom" min="300" max="50000" step="100" value="<?= e((string)($old['custom'] ?? '')) ?>" placeholder="₽"></span></label>
    </div>
    <h2 class="step"><span>2</span>Кому</h2>
    <div class="fields">
      <label>Имя получателя <small>необязательно</small><input name="recipient_name" maxlength="80" value="<?= e((string)($old['recipient_name'] ?? '')) ?>"></label>
      <label>E-mail получателя <small>пусто — пришлём вам</small><input type="email" name="recipient_email" maxlength="120" value="<?= e((string)($old['recipient_email'] ?? '')) ?>"></label>
      <label class="full">Поздравление <small>до 300 символов</small><textarea name="message" rows="2" maxlength="300"><?= e((string)($old['message'] ?? '')) ?></textarea></label>
    </div>
    <h2 class="step"><span>3</span>От кого</h2>
    <div class="fields">
      <label>Ваше имя<input name="buyer_name" required maxlength="100" value="<?= e((string)$old['buyer_name']) ?>" autocomplete="name"></label>
      <label>Ваш e-mail <small>для чека</small><input type="email" name="buyer_email" required maxlength="120" value="<?= e((string)$old['buyer_email']) ?>" autocomplete="email"></label>
      <label>Телефон <small>необязательно</small><input type="tel" name="buyer_phone" maxlength="30" value="<?= e((string)$old['buyer_phone']) ?>" autocomplete="tel"></label>
    </div>
    <button class="btn btn-accent btn-lg" <?= yk_enabled() ? '' : 'disabled' ?>>Оплатить сертификат</button>
    <?php if (!yk_enabled()): ?><p class="muted tiny">Онлайн-оплата скоро заработает. Пока сертификат можно заказать по телефону <?= e(setting('phone')) ?>.</p><?php endif; ?>
  </form>
</section>
<?php page_foot();
