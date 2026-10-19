<?php
/**
 * Автообновление товаров и цен из Ozon.
 * В панели хостинга (Cron) добавьте задание, например раз в час:
 *   php /путь/к/сайту/cron.php
 * или по ссылке:  https://ваш-сайт.ru/cron.php?key=КЛЮЧ_ИЗ_АДМИНКИ
 */
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/ozon.php';

if (PHP_SAPI !== 'cli') {
    $key = setting('cron_key');
    if ($key === '' || !hash_equals($key, (string)($_GET['key'] ?? ''))) { http_response_code(403); exit('forbidden'); }
    header('Content-Type: text/plain; charset=utf-8');
}

$lock = fopen(MB_DATA . '/sync.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit("Обновление уже идёт\n");
try {
    echo ozon_sync(), "\n";
} catch (Throwable $e) {
    sync_log(false, $e->getMessage());
    echo 'Ошибка: ', $e->getMessage(), "\n";
}
flock($lock, LOCK_UN);
// IndexNow: один раз отправляем все страницы, дальше — только изменённые (в ozon_sync)
if (setting('indexnow_full') !== '1' && setting('indexnow_key') !== '') {
    try {
        $base = rtrim(base_url(), '/') . '/';
        $all = [$base, $base . 'sitemap.xml', $base . 'contacts.php', $base . 'delivery.php'];
        foreach (categories_visible() as $c) $all[] = $base . category_url((int)$c['id'], $c['title']);
        foreach (db()->query('SELECT id, name FROM products WHERE ' . visible_where()) as $p) $all[] = $base . product_url($p);
        if (indexnow_submit($all) > 0) { setting_set('indexnow_full', '1'); echo "IndexNow: отправлено " . count($all) . " адресов\n"; }
    } catch (Throwable $e) { echo 'IndexNow: ', $e->getMessage(), "\n"; }
}
// Автообновление файлов сайта из GitHub
try { $u = site_autoupdate(); if ($u !== '') echo $u, "\n"; } catch (Throwable $e) { echo 'Автообновление: ', $e->getMessage(), "\n"; }
// Telegram без webhook: забираем сообщения покупателей боту
try { $n = tg_poll(); if ($n) echo "Telegram: сообщений {$n}\n"; } catch (Throwable $e) {}
