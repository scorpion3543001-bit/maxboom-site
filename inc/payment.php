<?php
/**
 * ЮKassa (онлайн-оплата) и уведомления о заказах (Telegram + e-mail).
 */
declare(strict_types=1);

function yk_enabled(): bool
{
    return setting('yk_shop_id') !== '' && setting('yk_secret') !== '';
}

function yk_call(string $method, string $path, $body = null, ?string $idem = null): array
{
    $headers = ['Authorization: Basic ' . base64_encode(setting('yk_shop_id') . ':' . setting('yk_secret'))];
    if ($idem) $headers[] = 'Idempotence-Key: ' . $idem;
    $base = getenv('MB_YK_BASE') ?: 'https://api.yookassa.ru';
    return http_json($method, $base . '/v3' . $path, $headers, $body, 30);
}

/** Создаёт платёж и возвращает ссылку на страницу оплаты. */
function yk_create_payment(array $order): string
{
    $items = json_decode($order['items'], true) ?: [];
    $body = [
        'amount' => ['value' => number_format((float)$order['total'], 2, '.', ''), 'currency' => 'RUB'],
        'capture' => true,
        'confirmation' => [
            'type' => 'redirect',
            'return_url' => base_url() . '/order.php?n=' . rawurlencode($order['number']) . '&t=' . $order['token'] . '&clear=1',
        ],
        'description' => 'Заказ ' . $order['number'],
        'metadata' => ['order_id' => (string)$order['id'], 'order_number' => $order['number']],
    ];
    if (setting('yk_receipt') === '1') {
        $vat = (int)setting('yk_vat_code') ?: 1;
        $ri = [];
        $gift = ($order['kind'] ?? 'goods') === 'gift';
        // Строки чека должны в сумме дать сумму платежа. Скидки, баллы и сертификат распределяем пропорционально,
        // остаток округления — на последнюю строку.
        $lines = [];
        foreach ($items as $it) $lines[] = ['name' => $it['name'] . ($it['qty'] > 1 ? ' × ' . $it['qty'] . ' шт' : ''), 'sum' => $it['price'] * $it['qty'], 'subject' => $gift ? 'payment' : 'commodity'];
        if ((float)$order['delivery_price'] > 0) $lines[] = ['name' => 'Доставка', 'sum' => (float)$order['delivery_price'], 'subject' => 'service'];
        $gross = array_sum(array_column($lines, 'sum'));
        $pay = (float)$order['total'];
        $left = $pay;
        foreach ($lines as $k => $l) {
            $amount = $k === array_key_last($lines) ? round($left, 2) : round($l['sum'] * $pay / max(0.01, $gross), 2);
            $left -= $amount;
            $ri[] = [
                'description' => mb_substr($l['name'], 0, 128),
                'quantity' => '1.00',
                'amount' => ['value' => number_format($amount, 2, '.', ''), 'currency' => 'RUB'],
                'vat_code' => $vat,
                'payment_mode' => $gift ? 'advance' : 'full_payment',
                'payment_subject' => $l['subject'],
            ];
        }
        $customer = [];
        if ($order['email']) $customer['email'] = $order['email'];
        elseif ($order['phone']) $customer['phone'] = preg_replace('/\D+/', '', $order['phone']);
        $body['receipt'] = ['customer' => $customer, 'items' => $ri];
    }
    $r = yk_call('POST', '/payments', $body, 'mb-' . $order['id'] . '-' . bin2hex(random_bytes(6)));
    if (!$r['ok'] || empty($r['data']['confirmation']['confirmation_url'])) {
        throw new RuntimeException('ЮKassa: ' . ($r['error'] ?: 'не удалось создать платёж'));
    }
    db()->prepare('UPDATE orders SET payment_id = ?, status = ? WHERE id = ?')->execute([$r['data']['id'], 'awaiting_payment', $order['id']]);
    return $r['data']['confirmation']['confirmation_url'];
}

/**
 * Проверка платежа напрямую в ЮKassa (не доверяем входящим данным).
 * Возвращает true, если заказ оплачен.
 */
function yk_refresh_order(array $order): bool
{
    if ($order['status'] !== 'awaiting_payment' && $order['status'] !== 'new') return !empty($order['paid_at']);
    if (empty($order['payment_id']) || !yk_enabled()) return false;
    $r = yk_call('GET', '/payments/' . rawurlencode($order['payment_id']));
    if (!$r['ok']) return false;
    $p = $r['data'];
    $same = (string)($p['metadata']['order_id'] ?? '') === (string)$order['id']
        && abs((float)($p['amount']['value'] ?? 0) - (float)$order['total']) < 0.01;
    if (($p['status'] ?? '') === 'succeeded' && $same) {
        $st = db()->prepare("UPDATE orders SET status = 'paid', paid_at = ? WHERE id = ? AND paid_at IS NULL");
        $st->execute([now(), $order['id']]);
        if ($st->rowCount() > 0) {
            $order['status'] = 'paid';
            order_paid_effects($order);
            notify_order($order, '💳 Оплачен заказ');
        }
        return true;
    }
    if (($p['status'] ?? '') === 'canceled') {
        db()->prepare("UPDATE orders SET status = 'new', payment_id = NULL WHERE id = ? AND paid_at IS NULL")->execute([$order['id']]);
    }
    return false;
}

/** Что происходит после оплаты: активация сертификата, сообщение покупателю. */
function order_paid_effects(array $order): void
{
    if (($order['kind'] ?? 'goods') === 'gift') {
        gift_activate_for_order($order);
        db()->prepare("UPDATE orders SET status = 'done' WHERE id = ? AND status = 'paid'")->execute([$order['id']]);
    }
    try { notify_customer($order, 'paid'); } catch (Throwable) {}
}

/* ---------- Уведомления ---------- */

function notify_order(array $order, string $title = '🛒 Новый заказ'): void
{
    $items = json_decode($order['items'], true) ?: [];
    $methods = delivery_methods();
    $lines = [];
    foreach ($items as $it) $lines[] = "• " . (!empty($it['code']) ? "[{$it['code']}] " : '') . "{$it['offer_id']} — {$it['name']} × {$it['qty']} = " . money($it['price'] * $it['qty']);
    $dlv = $methods[$order['delivery_method']]['title'] ?? ($order['delivery_method'] === 'email' ? 'на e-mail' : $order['delivery_method']);
    $pay = match ($order['payment_method']) { 'online' => 'онлайн (ЮKassa)', 'gift' => 'сертификатом', 'manual' => 'свяжемся для оплаты', default => 'при получении' };
    $text = "{$title} {$order['number']}\n\n" . implode("\n", $lines)
        . "\n\nТовары: " . money((float)$order['items_total'])
        . ((float)($order['discount_total'] ?? 0) > 0 ? "\nСкидка: " . money((float)$order['discount_total']) . (!empty($order['coupon_code']) ? " (промокод {$order['coupon_code']})" : '') : '')
        . ((float)($order['bonus_used'] ?? 0) > 0 ? "\nОплачено баллами: " . money((float)$order['bonus_used']) : '')
        . ((float)($order['gift_used'] ?? 0) > 0 ? "\nСертификат {$order['gift_code']}: −" . money((float)$order['gift_used']) : '')
        . "\nДоставка: {$dlv} — " . money((float)$order['delivery_price'])
        . "\nИтого: " . money((float)$order['total'])
        . "\nОплата: {$pay}"
        . (!empty($order['user_id']) ? "\nПокупатель с личным кабинетом #{$order['user_id']}" : '')
        . "\n\n👤 {$order['customer_name']}\n📞 {$order['phone']}" . ($order['email'] ? "\n✉️ {$order['email']}" : '')
        . ($order['delivery_address'] ? "\n📍 {$order['delivery_address']}" : '')
        . ($order['comment'] ? "\n💬 {$order['comment']}" : '');

    $tok = setting('tg_bot_token');
    $chat = setting('tg_chat_id');
    if ($tok !== '' && $chat !== '') {
        foreach (preg_split('/[\s,;]+/', $chat) as $c) {
            if ($c === '') continue;
            http_json('POST', 'https://api.telegram.org/bot' . $tok . '/sendMessage', [], ['chat_id' => $c, 'text' => $text, 'disable_web_page_preview' => true], 10);
        }
    }
    $mail = setting('notify_email');
    if ($mail !== '') {
        $subj = '=?UTF-8?B?' . base64_encode($title . ' ' . $order['number']) . '?=';
        @mail($mail, $subj, $text, "Content-Type: text/plain; charset=UTF-8\r\n");
    }
}
