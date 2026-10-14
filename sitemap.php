<?php
/** sitemap.xml для Яндекса и Google: главная, категории, товары в наличии. */
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

header('Content-Type: application/xml; charset=utf-8');
$base = rtrim(base_url(), '/') . '/';
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
$u = fn(string $loc, string $mod = '', string $pr = '0.6') => '<url><loc>' . htmlspecialchars($loc, ENT_XML1) . '</loc>' . ($mod ? '<lastmod>' . $mod . '</lastmod>' : '') . '<priority>' . $pr . '</priority></url>' . "\n";
echo $u($base, date('Y-m-d'), '1.0');
foreach (categories_visible() as $c) echo $u($base . category_url((int)$c['id'], $c['title']), date('Y-m-d'), '0.8');
foreach (db()->query('SELECT id, name, updated_at FROM products WHERE ' . visible_where() . ' ORDER BY id') as $p) {
    echo $u($base . product_url($p), substr((string)$p['updated_at'], 0, 10));
}
foreach (collections_live(50) as $c) {
    if (empty($c['key'])) continue;
    echo $u($base . '?col=' . rawurlencode((string)$c['key']), date('Y-m-d'), '0.7');
}
if (gift_on()) echo $u($base . 'gift.php', '', '0.5');
echo $u($base . 'delivery.php', '', '0.4');
echo '</urlset>';
