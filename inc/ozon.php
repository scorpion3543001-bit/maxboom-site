<?php
/**
 * Загрузка товаров и цен из Ozon Seller API.
 * Правило: при обновлении меняются только данные Ozon (название, фото, цена Ozon, SKU, остаток).
 * Ручная цена, скрытие и ссылки WB / Яндекс Маркет НЕ трогаются никогда.
 */
declare(strict_types=1);

function ozon_base(): string
{
    return getenv('MB_OZON_BASE') ?: 'https://api-seller.ozon.ru';
}

function ozon_call(string $path, array $body): array
{
    $r = http_json('POST', ozon_base() . $path, [
        'Client-Id: ' . setting('ozon_client_id'),
        'Api-Key: ' . setting('ozon_api_key'),
    ], $body, 60);
    if (!$r['ok']) {
        throw new RuntimeException("Ozon {$path}: " . ($r['code'] ? "HTTP {$r['code']} " : '') . $r['error']);
    }
    return $r['data'] ?? [];
}

/** Все product_id магазина (кроме архивных — их помечаем отдельно). */
function ozon_all_products(): array
{
    $out = [];
    $last = '';
    $guard = 0;
    do {
        $d = ozon_call('/v3/product/list', ['filter' => ['visibility' => 'ALL'], 'last_id' => $last, 'limit' => 1000]);
        $res = $d['result'] ?? $d;
        foreach ($res['items'] ?? [] as $it) {
            $out[(int)$it['product_id']] = ['offer_id' => (string)($it['offer_id'] ?? ''), 'archived' => !empty($it['archived'])];
        }
        $last = (string)($res['last_id'] ?? '');
        $n = count($res['items'] ?? []);
    } while ($last !== '' && $n >= 1000 && ++$guard < 100);
    return $out;
}

/** Цены: product_id => [price, marketing_seller_price, old_price, ...] */
function ozon_all_prices(): array
{
    $out = [];
    $cursor = '';
    $guard = 0;
    do {
        $d = ozon_call('/v5/product/info/prices', ['filter' => ['visibility' => 'ALL'], 'cursor' => $cursor, 'limit' => 1000]);
        foreach ($d['items'] ?? [] as $it) {
            $out[(int)$it['product_id']] = $it['price'] ?? [];
        }
        $cursor = (string)($d['cursor'] ?? '');
        $n = count($d['items'] ?? []);
    } while ($cursor !== '' && $n > 0 && ++$guard < 200);
    return $out;
}

/** Выбор «цены, по которой товар продаётся сейчас» по настройке. */
function ozon_pick_price(array $price): float
{
    $src = setting('price_source');
    $order = match ($src) {
        'price'     => ['price', 'marketing_seller_price'],
        'old_price' => ['old_price', 'price'],
        default     => ['marketing_seller_price', 'price'],
    };
    foreach ($order as $k) {
        $v = (float)($price[$k] ?? 0);
        if ($v > 0) return $v;
    }
    return 0;
}

function ozon_extract_sku(array $it): string
{
    if (!empty($it['sku'])) return (string)$it['sku'];
    foreach ($it['sources'] ?? [] as $s) if (!empty($s['sku'])) return (string)$s['sku'];
    if (!empty($it['fbs_sku'])) return (string)$it['fbs_sku'];
    if (!empty($it['fbo_sku'])) return (string)$it['fbo_sku'];
    return '';
}

function ozon_extract_images(array $it): array
{
    $imgs = [];
    foreach ((array)($it['primary_image'] ?? []) as $u) if ($u) $imgs[] = $u;
    foreach ((array)($it['images'] ?? []) as $u) {
        $u = is_array($u) ? ($u['file_name'] ?? $u['url'] ?? '') : $u;
        if ($u && !in_array($u, $imgs, true)) $imgs[] = $u;
    }
    return $imgs;
}

/** Свободный остаток (все склады Ozon): есть − в резерве. Нет данных — считаем, что нет в наличии. */
function ozon_extract_stock(array $it): int
{
    $sum = 0;
    foreach ($it['stocks']['stocks'] ?? [] as $s) $sum += max(0, (int)($s['present'] ?? 0) - (int)($s['reserved'] ?? 0));
    if ($sum === 0 && !empty($it['stocks']['has_stock']) && empty($it['stocks']['stocks'])) $sum = 1;
    return $sum;
}

/** Полная синхронизация. Возвращает текст отчёта. */
function ozon_sync(): string
{
    if (setting('ozon_client_id') === '' || setting('ozon_api_key') === '') {
        throw new RuntimeException('Не указаны Client-Id и API-ключ Ozon (Админка → Настройки).');
    }
    @set_time_limit(600);
    $t0 = microtime(true);
    $list = ozon_all_products();
    $prices = ozon_all_prices();
    $ids = array_keys($list);

    $pdo = db();
    $ins = $pdo->prepare('INSERT INTO products (ozon_product_id, offer_id, sku, name, images, ozon_price, ozon_old_price, archived, stock, created_at, updated_at, sort, category_id, type_id, model_id)
                          VALUES (:pid, :offer, :sku, :name, :images, :price, :old, :arch, :stock, :now, :now, :sort, :cat, :type, :model)
                          ON CONFLICT(ozon_product_id) DO UPDATE SET
                            offer_id = excluded.offer_id, sku = excluded.sku, name = excluded.name, images = excluded.images,
                            ozon_price = excluded.ozon_price, ozon_old_price = excluded.ozon_old_price,
                            archived = excluded.archived, stock = excluded.stock, updated_at = excluded.updated_at,
                            category_id = excluded.category_id, type_id = excluded.type_id, model_id = excluded.model_id');
    $new = 0; $upd = 0; $typesUsed = []; $inStock = 0;
    $existing = array_flip(array_map('intval', $pdo->query('SELECT ozon_product_id FROM products')->fetchAll(PDO::FETCH_COLUMN)));

    foreach (array_chunk($ids, 1000) as $chunk) {
        $d = ozon_call('/v3/product/info/list', ['product_id' => array_map('strval', $chunk)]);
        $items = $d['items'] ?? ($d['result']['items'] ?? []);
        $pdo->beginTransaction();
        foreach ($items as $it) {
            $pid = (int)$it['id'];
            $pr = $prices[$pid] ?? [];
            $price = ozon_pick_price($pr);
            if ($price <= 0) { // запасной вариант — цены из карточки
                $price = (float)($it['marketing_price'] ?? 0) ?: (float)($it['price'] ?? 0);
            }
            $old = (float)($pr['old_price'] ?? ($it['old_price'] ?? 0));
            $arch = ($list[$pid]['archived'] ?? false) || !empty($it['is_archived']) || !empty($it['is_autoarchived']);
            $ins->execute([
                ':pid' => $pid,
                ':offer' => (string)($it['offer_id'] ?? $list[$pid]['offer_id'] ?? ''),
                ':sku' => ozon_extract_sku($it),
                ':name' => (string)($it['name'] ?? ''),
                ':images' => json_encode(ozon_extract_images($it), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':price' => $price,
                ':old' => $old,
                ':arch' => $arch ? 1 : 0,
                ':stock' => ozon_extract_stock($it),
                ':now' => now(),
                ':sort' => $pid,
                ':cat' => (int)($it['description_category_id'] ?? 0) ?: null,
                ':type' => (int)($it['type_id'] ?? 0) ?: null,
                ':model' => (int)($it['model_info']['model_id'] ?? 0) ?: null,
            ]);
            if (!empty($it['type_id'])) $typesUsed[(int)$it['type_id']] = (int)($it['description_category_id'] ?? 0);
            isset($existing[$pid]) ? $upd++ : $new++;
            if (!$arch && $price > 0 && ozon_extract_stock($it) > 0) $inStock++;
        }
        $pdo->commit();
    }

    // Товары, которых больше нет в Ozon, прячем как архивные (ручные данные сохраняются)
    $gone = 0;
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        $gone = $pdo->exec("UPDATE products SET archived = 1 WHERE ozon_product_id NOT IN ($in) AND archived = 0");
    }

    assign_site_codes($pdo);
    $catMsg = '';
    if ($typesUsed) {
        try { $catMsg = ' ' . ozon_sync_categories($typesUsed); } catch (Throwable $e) { $catMsg = ' Категории: ошибка — ' . $e->getMessage(); }
    }

    $wMsg = '';
    try { $wMsg = ' ' . ozon_sync_weights($ids); } catch (Throwable $e) { $wMsg = ''; }
    try { $wMsg .= ' ' . variants_rebuild(); } catch (Throwable $e) { $wMsg .= ' Варианты: ошибка — ' . $e->getMessage(); }

    $wbMsg = '';
    if (setting('wb_token') !== '') {
        try { $wbMsg = ' ' . wb_sync_links(); } catch (Throwable $e) { $wbMsg = ' WB: ошибка — ' . $e->getMessage(); }
    }

    $rvMsg = '';
    if (setting('ozon_reviews_import') === '1' && setting('ozon_reviews_last') < date('Y-m-d H:i:s', time() - 86400)) {
        try { $rvMsg = ' ' . ozon_import_reviews(); } catch (Throwable $e) { $rvMsg = ''; setting_set('ozon_reviews_last', now()); }
    }
    page_cache_clear();
    if ($new + $upd + $gone > 0) {
        try { indexnow_submit(indexnow_changed_since(date('Y-m-d H:i:s', (int)$t0 - 60))); } catch (Throwable $e) {}
    }
    $msg = sprintf('Готово за %.1f с: новых %d, обновлено %d, убрано %d. В наличии на Ozon: %d.%s%s%s', microtime(true) - $t0, $new, $upd, $gone, $inStock, $catMsg, $wbMsg, $rvMsg);
    sync_log(true, $msg);
    return $msg;
}

/**
 * Названия категорий: дерево категорий Ozon → type_id => «Тип товара».
 * Своё название, скрытие и порядок, заданные в админке, не трогаются.
 */
function ozon_sync_categories(array $typesUsed): string
{
    $d = ozon_call('/v1/description-category/tree', ['language' => 'DEFAULT']);
    $types = [];
    $walk = function (array $nodes, string $parent) use (&$walk, &$types) {
        foreach ($nodes as $n) {
            if (!empty($n['type_id'])) {
                $types[(int)$n['type_id']] = ['name' => (string)($n['type_name'] ?? ''), 'parent' => $parent];
            }
            $name = (string)($n['category_name'] ?? $parent);
            if (!empty($n['children'])) $walk($n['children'], $name);
        }
    };
    $walk($d['result'] ?? [], '');
    $st = db()->prepare('INSERT INTO categories (id, ozon_name, parent_name) VALUES (?, ?, ?)
                         ON CONFLICT(id) DO UPDATE SET ozon_name = excluded.ozon_name, parent_name = excluded.parent_name');
    $n = 0;
    foreach (array_keys($typesUsed) as $tid) {
        $t = $types[$tid] ?? null;
        $st->execute([$tid, $t['name'] ?? ('Категория ' . $tid), $t['parent'] ?? '']);
        $n++;
    }
    return "Категорий: {$n}.";
}

/**
 * Характеристики товаров: вес (для СДЭК и Почты) и цвет (для выбора цвета на сайте).
 * Цвет: «Название цвета» (10097), иначе «Цвет товара» (10096). Ручной цвет из админки не меняется.
 */
function ozon_sync_weights(array $ids): string
{
    $pdo = db();
    $stW = $pdo->prepare('UPDATE products SET weight_g = ? WHERE ozon_product_id = ?');
    $stC = $pdo->prepare("UPDATE products SET color = ?, color_hex = ?, color_src = 'ozon' WHERE ozon_product_id = ? AND COALESCE(color_src, '') <> 'manual'");
    $n = 0; $nc = 0;
    foreach (array_chunk($ids, 1000) as $chunk) {
        $last = '';
        for ($guard = 0; $guard < 5; $guard++) {
            $d = ozon_call('/v4/product/info/attributes', ['filter' => ['product_id' => array_map('strval', $chunk), 'visibility' => 'ALL'], 'limit' => 1000, 'last_id' => $last]);
            $pdo->beginTransaction();
            foreach ($d['result'] ?? [] as $it) {
                $w = (float)($it['weight'] ?? 0);
                if ($w > 0) {
                    $unit = strtolower((string)($it['weight_unit'] ?? 'g'));
                    $stW->execute([(int)round($unit === 'kg' ? $w * 1000 : $w), (int)$it['id']]);
                    $n++;
                }
                $name = ''; $base = [];
                foreach ($it['attributes'] ?? [] as $a) {
                    $vals = array_values(array_filter(array_map(fn($v) => trim((string)($v['value'] ?? '')), $a['values'] ?? [])));
                    if ((int)($a['id'] ?? $a['attribute_id'] ?? 0) === 10097 && $vals) $name = $vals[0];
                    if ((int)($a['id'] ?? $a['attribute_id'] ?? 0) === 10096 && $vals) $base = $vals;
                }
                $label = $name !== '' ? $name : implode(', ', array_slice($base, 0, 2));
                if ($label !== '') {
                    [$nice, $hex] = color_from_label($label);
                    if ($hex === '' && $base) $hex = color_from_label($base[0])[1];
                    $stC->execute([mb_substr($nice, 0, 40), $hex ?: null, (int)$it['id']]);
                    $nc++;
                }
            }
            $pdo->commit();
            $last = (string)($d['last_id'] ?? '');
            if ($last === '' || count($d['result'] ?? []) < 1000) break;
        }
    }
    return "Вес: {$n}, цвет: {$nc}.";
}

/**
 * Отзывы с Ozon: берём оценки 4★ и 5★ с текстом и показываем на карточках товаров.
 * Метод Ozon доступен продавцам с подпиской Premium Plus.
 */
function ozon_import_reviews(int $maxPages = 20): string
{
    $pdo = db();
    $bySku = [];
    foreach ($pdo->query('SELECT id, sku FROM products WHERE sku IS NOT NULL AND sku <> \'\'') as $r) $bySku[(string)$r['sku']] = (int)$r['id'];
    $ins = $pdo->prepare("INSERT OR IGNORE INTO reviews (product_id, user_id, name, rating, text, verified, status, created_at, source, ext_id) VALUES (?, NULL, ?, ?, ?, 0, 'approved', ?, 'ozon', ?)");
    $last = ''; $added = 0; $seen = 0; $page = 0;
    do {
        $d = ozon_call('/v1/review/list', ['limit' => 100, 'last_id' => $last, 'sort_dir' => 'DESC', 'status' => 'ALL']);
        $list = $d['reviews'] ?? ($d['result']['reviews'] ?? []);
        foreach ($list as $r) {
            $seen++;
            $rt = (int)($r['rating'] ?? 0);
            if ($rt < 4) continue;
            $text = trim((string)($r['text'] ?? ''));
            if (mb_strlen($text) < 15) continue;
            $pid = $bySku[(string)($r['sku'] ?? '')] ?? 0;
            if (!$pid) continue;
            $ins->execute([$pid, 'Покупатель Ozon', $rt, mb_substr($text, 0, 2000), date('Y-m-d H:i:s', strtotime((string)($r['published_at'] ?? 'now')) ?: time()), 'ozon:' . ($r['id'] ?? md5($text))]);
            $added += $ins->rowCount();
        }
        $last = (string)($d['last_id'] ?? '');
        $more = !empty($d['has_next']) && $last !== '';
    } while ($more && ++$page < $maxPages);
    setting_set('ozon_reviews_last', now());
    return "Отзывы Ozon 4–5★: новых {$added}.";
}

function sync_log(bool $ok, string $msg): void
{
    db()->prepare('INSERT INTO sync_log(at, ok, message) VALUES (?, ?, ?)')->execute([now(), $ok ? 1 : 0, $msg]);
    db()->exec('DELETE FROM sync_log WHERE id NOT IN (SELECT id FROM sync_log ORDER BY id DESC LIMIT 200)');
}

/** Описание товара подгружается при первом открытии карточки и кэшируется. */
function ozon_description(array $p): string
{
    if ($p['description'] !== null) return (string)$p['description'];
    $desc = '';
    if (setting('ozon_client_id') !== '' && setting('ozon_api_key') !== '') {
        try {
            $r = http_json('POST', ozon_base() . '/v1/product/info/description', [
                'Client-Id: ' . setting('ozon_client_id'), 'Api-Key: ' . setting('ozon_api_key'),
            ], ['product_id' => (string)$p['ozon_product_id']], 6);
            if ($r['ok']) $desc = (string)($r['data']['result']['description'] ?? '');
            else return '';
        } catch (Throwable) { return ''; }
    }
    db()->prepare('UPDATE products SET description = ? WHERE id = ?')->execute([$desc, $p['id']]);
    return $desc;
}

/** Пример цен первого товара — чтобы в админке было видно, какое поле какое. */
function ozon_price_sample(): array
{
    $d = ozon_call('/v5/product/info/prices', ['filter' => ['visibility' => 'VISIBLE'], 'cursor' => '', 'limit' => 3]);
    $out = [];
    foreach ($d['items'] ?? [] as $it) $out[] = ['offer_id' => $it['offer_id'] ?? '', 'price' => $it['price'] ?? []];
    return $out;
}

/* ---------- Wildberries: подтягиваем nmID по артикулу продавца ---------- */

function wb_sync_links(): string
{
    $base = getenv('MB_WB_BASE') ?: 'https://content-api.wildberries.ru';
    $map = [];
    $cursor = ['limit' => 100];
    $guard = 0;
    do {
        $r = http_json('POST', $base . '/content/v2/get/cards/list', ['Authorization: ' . setting('wb_token')], [
            'settings' => ['cursor' => $cursor, 'filter' => ['withPhoto' => -1]],
        ], 60);
        if (!$r['ok']) throw new RuntimeException('HTTP ' . $r['code'] . ' ' . $r['error']);
        $cards = $r['data']['cards'] ?? [];
        foreach ($cards as $c) {
            if (!empty($c['vendorCode']) && !empty($c['nmID'])) $map[mb_strtolower(trim((string)$c['vendorCode']))] = (string)$c['nmID'];
        }
        $cur = $r['data']['cursor'] ?? [];
        $cursor = ['limit' => 100, 'updatedAt' => $cur['updatedAt'] ?? null, 'nmID' => $cur['nmID'] ?? null];
        usleep(700000); // лимит WB — не чаще ~100 запросов в минуту
    } while (count($cards) >= 100 && ++$guard < 500);

    $n = 0;
    $st = db()->prepare("UPDATE products SET wb_nm = ? WHERE id = ? AND (wb_url IS NULL OR wb_url = '')");
    foreach (db()->query('SELECT id, offer_id FROM products') as $p) {
        $k = mb_strtolower(trim((string)$p['offer_id']));
        if (isset($map[$k])) { $st->execute([$map[$k], $p['id']]); $n++; }
    }
    return "WB: найдено ссылок {$n}.";
}
