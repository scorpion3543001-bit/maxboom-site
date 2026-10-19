<?php
/**
 * Товарный фид в формате YML (Yandex Market Language).
 * Адрес: /yml.xml — для Яндекс Вебмастера («Товары и предложения») и Яндекс Маркета.
 */
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

header('Content-Type: application/xml; charset=utf-8');
$base = rtrim(base_url(), '/') . '/';
$x = fn(?string $s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
$cats = [];
foreach (categories_visible() as $c) $cats[(int)$c['id']] = (string)$c['title'];

echo '<?xml version="1.0" encoding="UTF-8"?>', "\n";
echo '<yml_catalog date="', date('Y-m-d\TH:i:sP'), '">', "\n<shop>\n";
echo '<name>', $x(setting('shop_name')), '</name>', "\n";
echo '<company>', $x(setting('legal_name') ?: setting('shop_name')), '</company>', "\n";
echo '<url>', $x($base), '</url>', "\n";
echo "<currencies><currency id=\"RUR\" rate=\"1\"/></currencies>\n<categories>\n";
foreach ($cats as $id => $t) echo '<category id="', $id, '">', $x($t), '</category>', "\n";
echo "</categories>\n";
$free = (float)setting('free_delivery_from');
echo "<delivery-options><option cost=\"", (int)setting('cdek_price'), "\" days=\"2-7\"/></delivery-options>\n<offers>\n";
foreach (db()->query('SELECT * FROM products WHERE ' . visible_where() . ' ORDER BY id') as $p) {
    $price = site_price($p);
    if ($price <= 0) continue;
    $old = base_price($p);
    $imgs = array_slice(product_images($p), 0, 5);
    $desc = trim((string)($p['description'] ?? ''));
    echo '<offer id="', $x((string)$p['site_code']), '" available="true">', "\n";
    echo ' <url>', $x($base . product_url($p)), '</url>', "\n";
    echo ' <price>', number_format($price, 2, '.', ''), '</price>', "\n";
    if ($old > $price) echo ' <oldprice>', number_format($old, 2, '.', ''), '</oldprice>', "\n";
    echo " <currencyId>RUR</currencyId>\n";
    if (!empty($p['type_id']) && isset($cats[(int)$p['type_id']])) echo ' <categoryId>', (int)$p['type_id'], '</categoryId>', "\n";
    foreach ($imgs as $im) echo ' <picture>', $x($im), '</picture>', "\n";
    echo ' <name>', $x(product_title_full($p)), '</name>', "\n";
    echo ' <vendor>', $x(setting('shop_name')), '</vendor>', "\n";
    echo ' <vendorCode>', $x((string)$p['site_code']), '</vendorCode>', "\n";
    if ($desc !== '') echo ' <description><![CDATA[', str_replace(']]>', ']]]]><![CDATA[>', mb_strimwidth($desc, 0, 3000, '…')), ']]></description>', "\n";
    echo " <delivery>true</delivery>\n <pickup>", setting('pickup_enabled') === '1' ? 'true' : 'false', "</pickup>\n";
    echo ' <param name="Материал">', $x(setting('seo_material')), '</param>', "\n";
    if (!empty($p['color'])) echo ' <param name="Цвет">', $x((string)$p['color']), '</param>', "\n";
    if (!empty($p['weight_g'])) echo ' <weight>', number_format((int)$p['weight_g'] / 1000, 3, '.', ''), '</weight>', "\n";
    echo "</offer>\n";
}
echo "</offers>\n</shop>\n</yml_catalog>";
