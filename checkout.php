<?php
/** Создание заказа. Цены всегда пересчитываются на сервере — данным из браузера не доверяем. */
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/payment.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: cart.php'); exit; }
csrf_check();

function back(string $msg): never
{
    $_SESSION['checkout_error'] = $msg;
    $_SESSION['checkout_old'] = array_intersect_key($_POST, array_flip(['name', 'phone', 'email', 'delivery', 'address', 'payment', 'comment', 'coupon', 'gift', 'use_bonus', 'city_code', 'city_name', 'pvz', 'pvz_addr', 'post_index']));
    header('Location: cart.php');
    exit;
}

// Простая защита от повторной отправки/спама: не чаще раза в 10 секунд с одной сессии
if (!empty($_SESSION['last_order_at']) && time() - $_SESSION['last_order_at'] < 10) back('Подождите несколько секунд и попробуйте снова.');

$name = trim((string)($_POST['name'] ?? ''));
$phone = trim((string)($_POST['phone'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$delivery = (string)($_POST['delivery'] ?? '');
$address = trim((string)($_POST['address'] ?? ''));
$payment = (string)($_POST['payment'] ?? '');
$comment = trim((string)($_POST['comment'] ?? ''));

$methods = delivery_methods();
if (mb_strlen($name) < 2) back('Укажите имя получателя.');
if (strlen(preg_replace('/\D+/', '', $phone)) < 10) back('Проверьте номер телефона.');
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) back('Проверьте e-mail.');
if (!isset($methods[$delivery])) back('Выберите способ получения.');
$dest = ['city_code' => (int)($_POST['city_code'] ?? 0), 'pvz' => trim((string)($_POST['pvz'] ?? '')), 'index' => preg_replace('/\D/', '', (string)($_POST['post_index'] ?? ''))];
$cdekApi = $delivery === 'cdek' && cdek_ready();
$postApi = $delivery === 'post' && post_ready();
if ($cdekApi) {
    if (!$dest['city_code'] || $dest['pvz'] === '') back('Выберите город и пункт выдачи СДЭК.');
    $address = trim((string)($_POST['city_name'] ?? '')) . ', ПВЗ СДЭК ' . $dest['pvz'] . ': ' . trim((string)($_POST['pvz_addr'] ?? ''));
} elseif ($postApi) {
    if (strlen($dest['index']) !== 6) back('Укажите почтовый индекс (6 цифр).');
    if (mb_strlen($address) < 5) back('Укажите адрес доставки.');
    if (!str_contains($address, $dest['index'])) $address = $dest['index'] . ', ' . $address;
} elseif ($methods[$delivery]['need_address'] && mb_strlen($address) < 5) back('Укажите город и адрес пункта выдачи.');

$allowedPay = [];
if (yk_enabled()) $allowedPay[] = 'online';
if (setting('pickup_pay_on_site') === '1' && $delivery === 'pickup') $allowedPay[] = 'onsite';
if (!yk_enabled() && !in_array('onsite', $allowedPay, true)) $allowedPay[] = 'manual';
if (!$allowedPay) back('Для этого способа доставки доступна только онлайн-оплата, но она ещё не подключена. Свяжитесь с нами.');
if (!in_array($payment, $allowedPay, true)) $payment = $allowedPay[0];
if ($payment === 'online' && setting('yk_receipt') === '1' && $email === '') back('Для онлайн-оплаты укажите e-mail — на него придёт чек.');

$raw = json_decode((string)($_POST['items'] ?? '[]'), true);
if (!is_array($raw) || !$raw) back('Корзина пуста.');
$user = current_user();
$calc = checkout_calc(['items' => $raw, 'coupon' => (string)($_POST['coupon'] ?? ''), 'delivery' => $delivery, 'dest' => $dest,
                       'gift' => (string)($_POST['gift'] ?? ''), 'use_bonus' => !empty($_POST['use_bonus'])], $user);
if ($calc['coupon_error'] !== '') back('Промокод: ' . $calc['coupon_error'] . '.');
if ($calc['gift_error'] !== '') back('Сертификат: ' . $calc['gift_error'] . '.');
if (!$calc['delivery_ready'] && $calc['delivery_error'] !== '') back($calc['delivery_error'] . '.');
$items = [];
foreach ($calc['items'] as $it) {
    if (!$it['ok']) continue;
    $items[] = ['id' => $it['id'], 'offer_id' => $it['offer_id'], 'code' => $it['code'], 'name' => $it['name'],
                'unit' => $it['unit'], 'price' => $it['price'], 'pct' => $it['pct'], 'qty' => $it['qty']];
}
if (!$items) back('Товары из корзины больше не продаются. Обновите страницу.');
$itemsTotal = (float)$calc['items_total'];
if ((float)setting('min_order') > 0 && (float)$calc['subtotal'] < (float)setting('min_order')) back('Минимальная сумма заказа — ' . money((float)setting('min_order')) . '.');

$dlvPrice = (float)$calc['delivery'];
$giftUse = (float)$calc['gift_use'];
$total = (float)$calc['total'];
if ($total <= 0) $payment = 'gift';   // всё покрыл сертификат
$token = bin2hex(random_bytes(12));

$pdo = db();
$pdo->beginTransaction();
try {
    // Сертификат списываем атомарно — второй заказ с тем же остатком не пройдёт
    if ($giftUse > 0) {
        $g = $pdo->prepare("UPDATE gift_cards SET balance = balance - ? WHERE code = ? COLLATE NOCASE AND balance >= ? AND status = 'active'");
        $g->execute([$giftUse, $calc['gift'], $giftUse - 0.001]);
        if ($g->rowCount() === 0) throw new RuntimeException('gift');
        $pdo->prepare("UPDATE gift_cards SET status = 'used' WHERE code = ? COLLATE NOCASE AND balance <= 0")->execute([$calc['gift']]);
    }
    if ($calc['bonus_use'] > 0 && $user && bonus_balance((int)$user['id']) < $calc['bonus_use']) throw new RuntimeException('bonus');
    $pdo->prepare('INSERT INTO orders (created_at, status, customer_name, phone, email, delivery_method, delivery_address, delivery_price, items_total, total, payment_method, comment, items, token, user_id, discount_total, coupon_code, bonus_used, gift_code, gift_used, kind, dlv_meta)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([now(), 'new', $name, $phone, $email, $delivery, $delivery === 'pickup' ? '' : $address, $dlvPrice, $itemsTotal, $total, $payment, $comment,
                   json_encode($items, JSON_UNESCAPED_UNICODE), $token, $user['id'] ?? null, (float)$calc['discount_total'], $calc['coupon'] ?: null,
                   (float)$calc['bonus_use'], $calc['gift'] ?: null, $giftUse, 'goods', json_encode(['dest' => $dest, 'weight' => $calc['weight'], 'eta' => $calc['delivery_eta']], JSON_UNESCAPED_UNICODE)]);
    $id = (int)$pdo->lastInsertId();
    $number = 'MB-' . date('ymd') . '-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
    $pdo->prepare('UPDATE orders SET number = ? WHERE id = ?')->execute([$number, $id]);
    if ($calc['bonus_use'] > 0 && $user) bonus_add((int)$user['id'], -(float)$calc['bonus_use'], 'Оплата баллами заказа ' . $number, $id);
    if ($calc['coupon']) $pdo->prepare('UPDATE coupons SET used = used + 1 WHERE code = ? COLLATE NOCASE')->execute([$calc['coupon']]);
    if ($payment === 'gift') $pdo->prepare("UPDATE orders SET status = 'paid', paid_at = ? WHERE id = ?")->execute([now(), $id]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    back($e->getMessage() === 'gift' ? 'Остаток сертификата изменился. Проверьте его ещё раз.' : ($e->getMessage() === 'bonus' ? 'Баллов меньше, чем нужно. Обновите страницу.' : 'Не удалось оформить заказ. Попробуйте ещё раз.'));
}
// Покупатель с кабинетом: запоминаем телефон для следующего раза
if ($user && ($user['phone'] === '' || $user['phone'] === null)) $pdo->prepare('UPDATE users SET phone = ? WHERE id = ?')->execute([$phone, $user['id']]);
$_SESSION['last_order_at'] = time();
page_cache_clear();

$st = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
$st->execute([$id]);
$order = $st->fetch();

notify_order($order, $payment === 'online' ? '🛒 Новый заказ (ждёт оплаты)' : '🛒 Новый заказ');
try { notify_customer($order, 'created'); } catch (Throwable) {}

$thanks = 'order.php?n=' . rawurlencode($number) . '&t=' . $token . '&clear=1';
if ($payment === 'online') {
    try {
        $url = yk_create_payment($order);
        header('Location: ' . $url);
        exit;
    } catch (Throwable $e) {
        error_log('[MaxBoom] ' . $e->getMessage());
        $thanks .= '&payerr=1';
    }
}
header('Location: ' . $thanks);
