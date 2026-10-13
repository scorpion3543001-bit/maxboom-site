<?php
/** Telegram webhook (работает только по https). Без https бот опрашивает Telegram сам — см. tg_poll(). */
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

$secret = setting('tg_secret');
if ($secret === '' || !hash_equals($secret, (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''))) { http_response_code(403); exit; }
$u = json_decode((string)file_get_contents('php://input'), true);
if (is_array($u)) { try { tg_process_update($u); } catch (Throwable $e) { error_log('[MaxBoom tg] ' . $e->getMessage()); } }
echo 'ok';
