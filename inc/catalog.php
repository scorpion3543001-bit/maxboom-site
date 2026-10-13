<?php
declare(strict_types=1);

const CATALOG_PER_PAGE = 36;

/** Поиск по коду товара на сайте, артикулу продавца (offer_id), SKU Ozon и названию. Регистр не важен, в т.ч. для кириллицы. */
/** Одинаковые товары разных цветов показываются одной карточкой (по group_key). */
function catalog_query(string $q, string $sort, int $page, int $perPage = CATALOG_PER_PAGE, int $cat = 0, bool $grouped = true): array
{
    $pdo = db();
    $pdo->sqliteCreateFunction('mb_lower', fn($s) => mb_strtolower((string)$s), 1);
    $where = visible_where();
    $params = [];
    $rank = '';
    if ($cat > 0) { $where .= ' AND type_id = :cat'; $params[':cat'] = $cat; }
    if ($q !== '') {
        // «а б|в» — (а И б) ИЛИ в: так задаются подборки из разных товаров
        $alts = []; $n = 0;
        foreach (array_slice(array_filter(array_map('trim', explode('|', mb_strtolower($q)))), 0, 8) as $alt) {
            $parts = [];
            foreach (array_slice(preg_split('/\s+/u', $alt, -1, PREG_SPLIT_NO_EMPTY), 0, 6) as $w) {
                $i = $n++;
                $parts[] = "(mb_lower(name) LIKE :w{$i} ESCAPE '\\' OR mb_lower(offer_id) LIKE :w{$i} ESCAPE '\\' OR sku LIKE :w{$i} ESCAPE '\\' OR site_code LIKE :w{$i} ESCAPE '\\')";
                $params[":w{$i}"] = '%' . str_replace(['%', '_'], ['\%', '\_'], $w) . '%';
            }
            if ($parts) $alts[] = '(' . implode(' AND ', $parts) . ')';
        }
        if ($alts) $where .= ' AND (' . implode(' OR ', $alts) . ')';
        // Точное совпадение артикула — первым
        $rank = '(CASE WHEN site_code = :exact OR mb_lower(offer_id) = :exact OR sku = :exact THEN 0 WHEN mb_lower(offer_id) LIKE :pref THEN 1 ELSE 2 END)';
        $params[':exact'] = mb_strtolower($q);
        $params[':pref'] = mb_strtolower($q) . '%';
    }
    $gk = "COALESCE(group_key, 'p' || id)";
    $st = $pdo->prepare($grouped ? "SELECT COUNT(DISTINCT $gk) FROM products WHERE $where" : "SELECT COUNT(*) FROM products WHERE $where");
    $st->execute(array_diff_key($params, [':exact' => 1, ':pref' => 1]));
    $total = (int)$st->fetchColumn();

    $k = 1 - max(0, min(95, (float)setting('discount_percent'))) / 100;
    $priceExpr = "(CASE WHEN manual_price > 0 THEN manual_price ELSE ozon_price * $k END)";
    $order = match ($sort) {
        'cheap'     => "$priceExpr ASC",
        'expensive' => "$priceExpr DESC",
        'discount'  => (($sa = sale_active()) && $sa['cats'] ? '(type_id IN (' . implode(',', $sa['cats']) . ')) DESC, ' : '') . "($priceExpr / ozon_price) ASC, ozon_price DESC",
        'random'    => 'RANDOM()',
        default     => 'created_at DESC, id DESC',
    };
    $offset = ($page - 1) * $perPage;
    // из группы вариантов берём первый подходящий товар (если искали «красный» — будет красный)
    $from = $grouped ? "products WHERE id IN (SELECT MIN(id) FROM products WHERE $where GROUP BY $gk)" : "products WHERE $where";
    $st = $pdo->prepare("SELECT * FROM $from ORDER BY " . ($rank ? "$rank, " : "") . "$order LIMIT $perPage OFFSET $offset");
    $st->execute($params);
    return [$st->fetchAll(), $total];
}
