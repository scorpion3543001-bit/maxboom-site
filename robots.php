<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');
echo "User-agent: *\nDisallow: /admin/\nDisallow: /cart.php\nDisallow: /checkout.php\nDisallow: /account.php\nDisallow: /order.php\nDisallow: /favorites.php\nDisallow: /api.php\nDisallow: /cron.php\nDisallow: /*?q=\nDisallow: /*&q=\nClean-param: ref&sort&page\n\nSitemap: " . rtrim(base_url(), '/') . "/sitemap.xml\n";
