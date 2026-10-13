<?php
/** Публичный API сайта: подсказки поиска, подгрузка каталога, актуальные цены корзины. */
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/catalog.php';

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'suggest':
        $q = trim((string)($_GET['q'] ?? ''));
        if (mb_strlen($q) < 2) json_out(['items' => []]);
        [$items] = catalog_query($q, 'new', 1, 8);
        json_out(['items' => array_map(fn($p) => [
            'id' => (int)$p['id'], 'name' => $p['name'], 'code' => (string)$p['site_code'],
            'price' => money(site_price($p)), 'img' => img_url(product_image($p), 200), 'url' => product_url($p),
        ], $items)]);

    case 'page':
        $q = trim((string)($_GET['q'] ?? ''));
        $page = max(1, (int)($_GET['page'] ?? 1));
        [$items, $total] = catalog_query($q, (string)($_GET['sort'] ?? 'new'), $page, CATALOG_PER_PAGE, max(0, (int)($_GET['cat'] ?? 0)));
        json_out([
            'html' => implode('', array_map('product_card', $items)),
            'more' => $page * CATALOG_PER_PAGE < $total,
        ]);

    case 'cart':
        // Корзина хранится в браузере; сервер возвращает актуальные цены и наличие
        $ids = array_slice(array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? '')))), 0, 100);
        $out = [];
        foreach ($ids as $id) {
            $p = find_product($id);
            $ok = product_on_sale($p);
            $out[$id] = $ok
                ? ['ok' => true, 'name' => product_title_full($p), 'price' => site_price($p), 'ozon' => (float)$p['ozon_price'], 'img' => product_image($p), 'code' => (string)$p['site_code']]
                : ['ok' => false];
        }
        json_out(['items' => $out]);

    case 'quote':
        // Расчёт корзины: опт, персональная скидка, наборы, промокод, баллы, доставка по API, сертификат
        $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
        json_out(checkout_calc($in, current_user()));

    case 'cards':
        // Карточки товаров по списку id (избранное, «Вы смотрели»)
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? '')))))), 0, 60);
        $html = '';
        $ok = [];
        foreach ($ids as $id) { $p = find_product($id); if (product_on_sale($p)) { $html .= product_card($p); $ok[] = $id; } }
        json_out(['html' => $html, 'ids' => $ok]);

    case 'fav':
        // Избранное покупателя с кабинетом (у гостей — в браузере)
        $u = current_user();
        if (!$u) json_out(['ids' => []]);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
            if (isset($in['merge'])) json_out(['ids' => fav_merge((int)$u['id'], (array)$in['merge'])]);
            fav_set((int)$u['id'], (int)($in['id'] ?? 0), !empty($in['on']));
        }
        json_out(['ids' => fav_ids((int)$u['id'])]);

    case 'cdek_cities':
        try { json_out(['items' => cdek_ready() ? cdek_cities((string)($_GET['q'] ?? '')) : []]); }
        catch (Throwable $e) { json_out(['items' => [], 'error' => $e->getMessage()]); }

    case 'cdek_points':
        try { json_out(['items' => cdek_ready() ? cdek_points((int)($_GET['city'] ?? 0)) : []]); }
        catch (Throwable $e) { json_out(['items' => [], 'error' => $e->getMessage()]); }

    case 'tg_check':
        // Без webhook: забираем обновления у Telegram и смотрим, подключился ли покупатель
        $u = current_user();
        if (!$u) json_out(['linked' => false]);
        tg_poll();
        $st = db()->prepare('SELECT tg_chat_id FROM users WHERE id = ?');
        $st->execute([$u['id']]);
        json_out(['linked' => (string)$st->fetchColumn() !== '']);

    case 'review':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST'], 405);
        csrf_check();
        $u = current_user();
        if (!$u) json_out(['error' => 'Войдите в личный кабинет, чтобы оставить отзыв'], 401);
        if (setting('reviews_enabled') !== '1') json_out(['error' => 'Отзывы отключены'], 403);
        $pid = (int)($_POST['product_id'] ?? 0);
        $p = find_product($pid);
        if (!$p) json_out(['error' => 'Товар не найден'], 404);
        $rating = max(1, min(5, (int)($_POST['rating'] ?? 0)));
        $text = trim((string)($_POST['text'] ?? ''));
        if (mb_strlen($text) < 10) json_out(['error' => 'Напишите хотя бы пару предложений'], 400);
        $dup = db()->prepare("SELECT COUNT(*) FROM reviews WHERE product_id = ? AND user_id = ? AND status <> 'rejected'");
        $dup->execute([$pid, $u['id']]);
        if ((int)$dup->fetchColumn() > 0) json_out(['error' => 'Вы уже оставили отзыв на этот товар'], 409);
        $status = setting('reviews_premod') === '1' ? 'pending' : 'approved';
        db()->prepare('INSERT INTO reviews (product_id, user_id, name, rating, text, verified, status, created_at) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$pid, $u['id'], mb_substr((string)$u['name'], 0, 60), $rating, mb_substr($text, 0, 2000), user_bought((int)$u['id'], $pid) ? 1 : 0, $status, now()]);
        json_out(['ok' => true, 'status' => $status]);

    default:
        json_out(['error' => 'unknown action'], 400);
}
