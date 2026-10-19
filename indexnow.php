<?php
/** Файл-ключ IndexNow: /<ключ>.txt должен отдавать сам ключ. */
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
$key = setting('indexnow_key');
if ($key === '' || !hash_equals($key, (string)($_GET['key'] ?? ''))) { http_response_code(404); exit('not found'); }
header('Content-Type: text/plain; charset=utf-8');
echo $key;
