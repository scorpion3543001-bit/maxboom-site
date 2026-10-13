<?php
/**
 * Уведомления ЮKassa (HTTP-уведомления). Адрес указать в личном кабинете ЮKassa:
 *   https://ваш-сайт.ru/yookassa_webhook.php  — событие payment.succeeded и payment.canceled
 * Данные уведомления не используются напрямую: статус платежа перепроверяется запросом в ЮKassa.
 */
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/payment.php';

$body = json_decode((string)file_get_contents('php://input'), true);
$paymentId = (string)($body['object']['id'] ?? '');
if ($paymentId === '') { http_response_code(200); exit; }

$st = db()->prepare('SELECT * FROM orders WHERE payment_id = ?');
$st->execute([$paymentId]);
$order = $st->fetch();
if ($order) yk_refresh_order($order);
http_response_code(200);
echo 'OK';
