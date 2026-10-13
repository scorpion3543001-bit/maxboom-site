<?php
/**
 * v1.004: ссылки и SEO, избранное, бонусы и рефералы, подарочные сертификаты,
 * доставка по API (СДЭК, Почта России), Telegram для покупателей, кэш страниц, письма.
 */
declare(strict_types=1);

/* ================= Ссылки, SEO, картинки ================= */

function slugify(string $s): string
{
    static $map = ['а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'c','ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya'];
    $s = strtr(mb_strtolower($s), $map);
    $s = trim((string)preg_replace('/[^a-z0-9]+/', '-', $s), '-');
    if (strlen($s) > 60) $s = rtrim(substr($s, 0, 60), '-');
    return $s;
}

function product_url(array $p): string
{
    if (setting('pretty_urls') === '1') return 'p/' . (int)$p['id'] . '-' . slugify((string)$p['name']);
    return 'product.php?id=' . (int)$p['id'];
}

function category_url(int $id, string $title = ''): string
{
    if (setting('pretty_urls') === '1') return 'c/' . $id . ($title !== '' ? '-' . slugify($title) : '');
    return './?cat=' . $id;
}

/** Корень сайта (путь), например «/» или «/shop/». */
function site_root(): string
{
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    if (str_ends_with($dir, '/admin')) $dir = substr($dir, 0, -6);
    return $dir . '/';
}

function abs_url(string $rel): string
{
    return rtrim(base_url(), '/') . '/' . ltrim($rel, './');
}

/** Уменьшенная копия фото с CDN Ozon (экономит трафик и ускоряет страницу). */
function img_url(string $url, int $w = 400): string
{
    if ($url === '' || setting('img_resize') !== '1') return $url;
    if (preg_match('~^(https://(?:ir|cdn\d*)\.ozone\.ru/s3/[^/]+)/(?:wc\d+/)?([^/]+\.(?:jpe?g|png|webp))$~i', $url, $m)) {
        return $m[1] . '/wc' . $w . '/' . $m[2];
    }
    return $url;
}

function low_stock_text(array $p): string
{
    $lim = (int)setting('low_stock');
    $s = (int)$p['stock'];
    if ($lim <= 0 || $s <= 0 || $s > $lim) return '';
    return $s === 1 ? 'Остался последний' : 'Осталось ' . $s . ' шт';
}

/* ================= Кэш ================= */

function kv_get(string $key)
{
    $st = db()->prepare('SELECT value FROM kv_cache WHERE key = ? AND until > ?');
    $st->execute([$key, time()]);
    $v = $st->fetchColumn();
    return $v === false ? null : json_decode((string)$v, true);
}

function kv_set(string $key, $value, int $ttl): void
{
    db()->prepare('INSERT INTO kv_cache(key, value, until) VALUES (?, ?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value, until = excluded.until')
        ->execute([$key, json_encode($value, JSON_UNESCAPED_UNICODE), time() + $ttl]);
}

/** Готовые страницы для гостей кэшируются на 2 минуты — сайт открывается быстрее. */
function page_cache_start(): void
{
    if (PHP_SAPI === 'cli' || setting('page_cache') !== '1' || !empty($_SESSION['uid']) || !empty($_SESSION['admin'])) return;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
    $dir = MB_DATA . '/cache';
    $f = $dir . '/' . md5((string)($_SERVER['REQUEST_URI'] ?? '')) . '.html';
    if (is_file($f) && filemtime($f) > time() - 120) {
        header('X-Cache: HIT');
        readfile($f);
        exit;
    }
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    ob_start(function (string $html) use ($f) {
        if (http_response_code() === 200 && strlen($html) > 500) @file_put_contents($f, $html, LOCK_EX);
        return $html;
    });
}

function page_cache_clear(): void
{
    foreach (glob(MB_DATA . '/cache/*.html') ?: [] as $f) @unlink($f);
}

/* ================= Письма ================= */

function send_mail(string $to, string $subject, string $text): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $host = preg_replace('/^www\./', '', (string)($_SERVER['HTTP_HOST'] ?? parse_url(base_url(), PHP_URL_HOST) ?? 'localhost'));
    $from = setting('email') ?: ('no-reply@' . $host);
    $name = '=?UTF-8?B?' . base64_encode(setting('shop_name')) . '?=';
    $subj = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    if (getenv('MB_MAIL_LOG')) { @file_put_contents(getenv('MB_MAIL_LOG'), "TO: $to\nSUBJ: $subject\n$text\n-----\n", FILE_APPEND); return true; }
    return @mail($to, $subj, $text, "Content-Type: text/plain; charset=UTF-8\r\nFrom: {$name} <{$from}>\r\n");
}

/* ================= Избранное ================= */

function fav_ids(int $userId): array
{
    $st = db()->prepare('SELECT product_id FROM favorites WHERE user_id = ? ORDER BY created_at DESC');
    $st->execute([$userId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

function fav_merge(int $userId, array $ids): array
{
    $st = db()->prepare('INSERT OR IGNORE INTO favorites(user_id, product_id, created_at) VALUES (?, ?, ?)');
    foreach (array_slice(array_unique(array_map('intval', $ids)), 0, 300) as $id) if ($id > 0) $st->execute([$userId, $id, now()]);
    return fav_ids($userId);
}

function fav_set(int $userId, int $pid, bool $on): void
{
    if ($on) db()->prepare('INSERT OR IGNORE INTO favorites(user_id, product_id, created_at) VALUES (?, ?, ?)')->execute([$userId, $pid, now()]);
    else db()->prepare('DELETE FROM favorites WHERE user_id = ? AND product_id = ?')->execute([$userId, $pid]);
}

/* ================= Бонусы и рефералы ================= */

function bonus_on(): bool { return setting('bonus_enabled') === '1'; }

function bonus_balance(int $userId): float
{
    $st = db()->prepare('SELECT COALESCE(SUM(amount), 0) FROM bonus_tx WHERE user_id = ?');
    $st->execute([$userId]);
    return max(0, round((float)$st->fetchColumn()));
}

function bonus_add(int $userId, float $amount, string $reason, ?int $orderId = null): void
{
    if ($userId <= 0 || abs($amount) < 1) return;
    db()->prepare('INSERT INTO bonus_tx(user_id, amount, reason, order_id, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$userId, round($amount), $reason, $orderId, now()]);
}

function ref_code_for(array $u): string
{
    if (!empty($u['ref_code'])) return (string)$u['ref_code'];
    do {
        $code = strtoupper(substr(base_convert(bin2hex(random_bytes(5)), 16, 36), 0, 7));
        $st = db()->prepare('SELECT 1 FROM users WHERE ref_code = ?');
        $st->execute([$code]);
    } while ($st->fetchColumn());
    db()->prepare('UPDATE users SET ref_code = ? WHERE id = ?')->execute([$code, $u['id']]);
    return $code;
}

/** ?ref=КОД в любой ссылке — запоминаем пригласившего на 30 дней. */
function ref_capture(): void
{
    if (setting('ref_enabled') !== '1' || empty($_GET['ref']) || PHP_SAPI === 'cli') return;
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$_GET['ref']));
    if ($code === '') return;
    setcookie('mb_ref', $code, ['expires' => time() + 30 * 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    $_COOKIE['mb_ref'] = $code;
}

function ref_owner_from_cookie(): ?int
{
    $code = (string)($_COOKIE['mb_ref'] ?? '');
    if ($code === '' || setting('ref_enabled') !== '1') return null;
    $st = db()->prepare('SELECT id FROM users WHERE ref_code = ?');
    $st->execute([$code]);
    return ($id = $st->fetchColumn()) ? (int)$id : null;
}

/** Подтверждение e-mail: приветственные баллы, бонус приглашённому, привязка прошлых заказов. */
function user_verified(array $u): void
{
    $pdo = db();
    $pdo->prepare('UPDATE users SET email_verified = 1, verify_token = NULL WHERE id = ?')->execute([$u['id']]);
    if (empty($u['welcome_given'])) {
        $pdo->prepare('UPDATE users SET welcome_given = 1 WHERE id = ?')->execute([$u['id']]);
        if (bonus_on() && (float)setting('bonus_welcome') > 0) bonus_add((int)$u['id'], (float)setting('bonus_welcome'), 'Приветственные баллы');
        if (!empty($u['referred_by']) && bonus_on() && (float)setting('ref_bonus_new') > 0) bonus_add((int)$u['id'], (float)setting('ref_bonus_new'), 'Подарок за регистрацию по приглашению');
    }
    // Заказы, оформленные на этот e-mail без входа, появятся в кабинете
    $pdo->prepare('UPDATE orders SET user_id = ? WHERE user_id IS NULL AND lower(email) = lower(?)')->execute([$u['id'], $u['email']]);
}

function send_verify_mail(array $u): void
{
    $token = bin2hex(random_bytes(16));
    db()->prepare('UPDATE users SET verify_token = ? WHERE id = ?')->execute([hash('sha256', $token), $u['id']]);
    $link = base_url() . '/account.php?verify=' . $token . '&e=' . rawurlencode((string)$u['email']);
    $gift = bonus_on() && (float)setting('bonus_welcome') > 0 ? "\n\nПосле подтверждения начислим " . (int)setting('bonus_welcome') . " приветственных баллов (1 балл = 1 ₽)." : '';
    send_mail((string)$u['email'], 'Подтвердите e-mail — ' . setting('shop_name'),
        "Здравствуйте, {$u['name']}!\n\nПодтвердите e-mail, открыв ссылку:\n{$link}{$gift}\n\nЕсли вы не регистрировались, просто удалите это письмо.");
}

/**
 * Начисления и возвраты при смене статуса заказа.
 * Выполнен → кэшбэк баллами и бонус пригласившему; отменён → возврат списанных баллов и сертификата.
 */
function order_status_effects(array $o, string $status): void
{
    $pdo = db();
    $uid = (int)($o['user_id'] ?? 0);
    if ($status === 'done' && $uid && empty($o['bonus_accrued']) && bonus_on() && ($o['kind'] ?? 'goods') === 'goods') {
        $earn = floor((float)$o['items_total'] * (float)setting('bonus_percent') / 100);
        if ($earn >= 1) bonus_add($uid, $earn, 'Кэшбэк за заказ ' . $o['number'], (int)$o['id']);
        $pdo->prepare('UPDATE orders SET bonus_accrued = 1 WHERE id = ?')->execute([$o['id']]);
        // Пригласившему — после первого выполненного заказа друга
        $u = $pdo->prepare('SELECT * FROM users WHERE id = ?'); $u->execute([$uid]); $u = $u->fetch();
        if ($u && !empty($u['referred_by']) && empty($u['ref_rewarded']) && setting('ref_enabled') === '1') {
            $pdo->prepare('UPDATE users SET ref_rewarded = 1 WHERE id = ?')->execute([$uid]);
            if ((float)setting('ref_bonus_owner') > 0) bonus_add((int)$u['referred_by'], (float)setting('ref_bonus_owner'), 'Друг сделал первый заказ: ' . $u['name']);
        }
    }
    if ($status === 'cancelled' && empty($o['bonus_refunded'])) {
        if ($uid && (float)$o['bonus_used'] > 0) bonus_add($uid, (float)$o['bonus_used'], 'Возврат баллов: заказ ' . $o['number'] . ' отменён', (int)$o['id']);
        if ($uid && !empty($o['bonus_accrued'])) {
            $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM bonus_tx WHERE order_id = ? AND reason LIKE 'Кэшбэк%'");
            $st->execute([$o['id']]);
            if (($back = (float)$st->fetchColumn()) > 0) bonus_add($uid, -$back, 'Списание кэшбэка: заказ ' . $o['number'] . ' отменён', (int)$o['id']);
        }
        if (!empty($o['gift_code']) && (float)$o['gift_used'] > 0) {
            $pdo->prepare("UPDATE gift_cards SET balance = balance + ?, status = 'active' WHERE code = ? COLLATE NOCASE")->execute([(float)$o['gift_used'], $o['gift_code']]);
        }
        if (($o['kind'] ?? '') === 'gift') $pdo->prepare("UPDATE gift_cards SET status = 'cancelled' WHERE order_id = ?")->execute([$o['id']]);
        $pdo->prepare('UPDATE orders SET bonus_refunded = 1 WHERE id = ?')->execute([$o['id']]);
    }
}

/* ================= Подарочные сертификаты ================= */

function gift_on(): bool { return setting('gift_enabled') === '1'; }

function gift_nominals(): array
{
    $n = array_values(array_filter(array_map('intval', preg_split('/[\s,;]+/', setting('gift_nominals'))), fn($v) => $v >= 100));
    return $n ?: [1000, 2000, 3000];
}

function gift_new_code(): string
{
    do {
        $code = 'GIFT-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 4) . '-' . substr(bin2hex(random_bytes(4)), 0, 4));
        $st = db()->prepare('SELECT 1 FROM gift_cards WHERE code = ?');
        $st->execute([$code]);
    } while ($st->fetchColumn());
    return $code;
}

/** @return array{0: ?array, 1: string} */
function gift_check(string $code): array
{
    $code = trim($code);
    if ($code === '') return [null, ''];
    $st = db()->prepare('SELECT * FROM gift_cards WHERE code = ? COLLATE NOCASE');
    $st->execute([$code]);
    $g = $st->fetch();
    if (!$g || $g['status'] === 'pending' || $g['status'] === 'cancelled') return [null, 'Сертификат не найден'];
    if ($g['expires_at'] && $g['expires_at'] < date('Y-m-d')) return [null, 'Срок действия сертификата истёк'];
    if ((float)$g['balance'] <= 0) return [null, 'Сертификат уже израсходован'];
    return [$g, ''];
}

/** Оплачен заказ-сертификат: активируем код и отправляем его получателю. */
function gift_activate_for_order(array $order): void
{
    $st = db()->prepare("SELECT * FROM gift_cards WHERE order_id = ? AND status = 'pending'");
    $st->execute([$order['id']]);
    foreach ($st->fetchAll() as $g) {
        $exp = date('Y-m-d', time() + max(30, (int)setting('gift_days')) * 86400);
        db()->prepare("UPDATE gift_cards SET status = 'active', expires_at = ? WHERE id = ?")->execute([$exp, $g['id']]);
        $text = "Подарочный сертификат " . setting('shop_name') . " на " . money((float)$g['amount']) . "\n\nКод: {$g['code']}\nДействует до " . date('d.m.Y', strtotime($exp))
            . ($g['message'] ? "\n\n«{$g['message']}»" : '')
            . "\n\nВведите код в корзине при оформлении заказа: " . base_url() . '/';
        if ($g['recipient_email']) send_mail($g['recipient_email'], 'Вам подарочный сертификат ' . setting('shop_name'), ($g['recipient_name'] ? "{$g['recipient_name']}, вам подарили сертификат!\n\n" : '') . $text);
        if ($g['buyer_email'] && $g['buyer_email'] !== $g['recipient_email']) send_mail($g['buyer_email'], 'Сертификат ' . $g['code'] . ' оплачен', $text);
    }
}

/* ================= Доставка по API ================= */

function cart_weight(array $items): int
{
    $def = max(50, (int)setting('default_weight_g'));
    $w = 0;
    foreach ($items as $it) if (!empty($it['ok'])) $w += max(1, (int)($it['weight'] ?? 0) ?: $def) * (int)$it['qty'];
    return max(100, $w);
}

function cdek_base(): string
{
    return getenv('MB_CDEK_BASE') ?: (setting('cdek_test') === '1' ? 'https://api.edu.cdek.ru' : 'https://api.cdek.ru');
}

function cdek_ready(): bool
{
    return setting('cdek_api') === '1' && (setting('cdek_test') === '1' || (setting('cdek_client_id') !== '' && setting('cdek_client_secret') !== ''));
}

function cdek_token(): string
{
    $key = 'cdek_token_' . md5(cdek_base() . setting('cdek_client_id'));
    if ($t = kv_get($key)) return (string)$t;
    $id = setting('cdek_client_id'); $sec = setting('cdek_client_secret');
    if (setting('cdek_test') === '1' && ($id === '' || $sec === '')) { $id = 'wqGwiQx0gg8mLtiEKsUinjVSICCjtTEP'; $sec = 'RmAmgvSgSl1yirlz9QupbzOJVqhCxcP5'; }
    $ch = curl_init(cdek_base() . '/v2/oauth/token');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'client_credentials', 'client_id' => $id, 'client_secret' => $sec])]);
    $raw = curl_exec($ch); curl_close($ch);
    $d = json_decode((string)$raw, true);
    if (empty($d['access_token'])) throw new RuntimeException('СДЭК: не удалось авторизоваться — проверьте ключи');
    kv_set($key, $d['access_token'], max(60, (int)($d['expires_in'] ?? 3600) - 120));
    return (string)$d['access_token'];
}

function cdek_get(string $path, array $q = []): array
{
    $r = http_json('GET', cdek_base() . $path . ($q ? '?' . http_build_query($q) : ''), ['Authorization: Bearer ' . cdek_token()], null, 15);
    if (!$r['ok']) throw new RuntimeException('СДЭК: ' . ($r['error'] ?: 'HTTP ' . $r['code']));
    return (array)$r['data'];
}

function cdek_cities(string $q): array
{
    $q = trim($q);
    if (mb_strlen($q) < 2) return [];
    $key = 'cdek_city_' . md5(mb_strtolower($q));
    if (($c = kv_get($key)) !== null) return $c;
    $out = [];
    foreach (cdek_get('/v2/location/cities', ['city' => $q, 'country_codes' => 'RU', 'size' => 10]) as $c) {
        if (empty($c['code'])) continue;
        $out[] = ['code' => (int)$c['code'], 'city' => (string)($c['city'] ?? ''), 'region' => (string)($c['region'] ?? $c['sub_region'] ?? '')];
    }
    kv_set($key, $out, 86400 * 7);
    return $out;
}

function cdek_points(int $cityCode): array
{
    $key = 'cdek_pvz_' . $cityCode;
    if (($c = kv_get($key)) !== null) return $c;
    $out = [];
    foreach (cdek_get('/v2/deliverypoints', ['city_code' => $cityCode, 'type' => 'ALL', 'country_code' => 'RU']) as $p) {
        if (empty($p['code'])) continue;
        $loc = $p['location'] ?? [];
        $out[] = ['code' => (string)$p['code'], 'name' => (string)($p['name'] ?? ''), 'address' => (string)($loc['address'] ?? $loc['address_full'] ?? ''),
                  'lat' => (float)($loc['latitude'] ?? 0), 'lon' => (float)($loc['longitude'] ?? 0), 'time' => (string)($p['work_time'] ?? ''),
                  'type' => (string)($p['type'] ?? 'PVZ')];
    }
    kv_set($key, $out, 86400);
    return $out;
}

function cdek_from_code(): int
{
    $key = 'cdek_from_' . md5(setting('cdek_from_city'));
    if ($c = kv_get($key)) return (int)$c;
    $c = cdek_cities(setting('cdek_from_city') ?: 'Москва')[0]['code'] ?? 44;
    kv_set($key, $c, 86400 * 30);
    return (int)$c;
}

function cdek_calc(int $toCode, int $weight): array
{
    $key = 'cdek_calc_' . $toCode . '_' . (int)ceil($weight / 250);
    if ($c = kv_get($key)) return $c;
    $r = http_json('POST', cdek_base() . '/v2/calculator/tariff', ['Authorization: Bearer ' . cdek_token()], [
        'tariff_code' => (int)setting('cdek_tariff') ?: 136,
        'from_location' => ['code' => cdek_from_code()],
        'to_location' => ['code' => $toCode],
        'packages' => [['weight' => (int)(ceil($weight / 250) * 250), 'length' => 20, 'width' => 15, 'height' => 10]],
    ], 15);
    if (!$r['ok'] || !isset($r['data']['total_sum'])) {
        $msg = $r['data']['errors'][0]['message'] ?? $r['error'] ?? '';
        throw new RuntimeException('СДЭК не смог рассчитать доставку' . ($msg ? ': ' . $msg : ''));
    }
    $d = $r['data'];
    $out = ['price' => (float)$d['total_sum'], 'min' => (int)($d['period_min'] ?? 0), 'max' => (int)($d['period_max'] ?? 0)];
    kv_set($key, $out, 3600 * 6);
    return $out;
}

function post_ready(): bool
{
    return setting('post_api') === '1' && preg_match('/^\d{6}$/', setting('post_from_index'));
}

function post_calc(string $to, int $weight): array
{
    $key = 'post_calc_' . $to . '_' . (int)ceil($weight / 250);
    if ($c = kv_get($key)) return $c;
    $base = getenv('MB_POST_BASE') ?: 'https://tariff.pochta.ru';
    $url = $base . '/v2/calculate/tariff/delivery?json&' . http_build_query([
        'object' => (int)setting('post_object') ?: 27030, 'from' => setting('post_from_index'), 'to' => $to,
        'weight' => (int)(ceil($weight / 250) * 250), 'pack' => 10, 'date' => date('Ymd'),
    ]);
    $r = http_json('GET', $url, [], null, 15);
    $d = $r['data'] ?? [];
    $kop = (float)($d['paynds'] ?? $d['pay'] ?? 0);
    if (!$r['ok'] || $kop <= 0) {
        $err = is_array($d['errors'] ?? null) ? ($d['errors'][0]['msg'] ?? '') : '';
        throw new RuntimeException('Почта России не смогла рассчитать доставку' . ($err ? ': ' . $err : '') . '. Проверьте индекс.');
    }
    $out = ['price' => ceil($kop / 100), 'min' => (int)($d['delivery']['min'] ?? 0), 'max' => (int)($d['delivery']['max'] ?? 0)];
    kv_set($key, $out, 3600 * 12);
    return $out;
}

/** Цена доставки с учётом API и бесплатной доставки. $dest = ['city_code', 'pvz', 'index']. */
function delivery_quote(string $method, float $itemsTotal, int $weight, array $dest): array
{
    $m = delivery_methods()[$method] ?? null;
    if (!$m) return ['price' => 0, 'ready' => false, 'error' => 'Выберите способ получения', 'eta' => ''];
    $free = (float)setting('free_delivery_from');
    $isFree = $free > 0 && $itemsTotal >= $free;
    $res = ['price' => (float)$m['price'], 'ready' => true, 'error' => '', 'eta' => '', 'api' => false];
    try {
        if ($method === 'cdek' && cdek_ready()) {
            $res['api'] = true;
            $code = (int)($dest['city_code'] ?? 0);
            if (!$code) return ['price' => 0, 'ready' => false, 'error' => 'Выберите город, чтобы рассчитать доставку СДЭК', 'eta' => '', 'api' => true];
            $c = cdek_calc($code, $weight);
            $res['price'] = $c['price'] + (float)setting('cdek_markup');
            $res['eta'] = $c['min'] ? ($c['min'] === $c['max'] ? $c['min'] : $c['min'] . '–' . $c['max']) . ' ' . plural((int)$c['max'], 'день', 'дня', 'дней') : '';
            if (empty($dest['pvz'])) { $res['ready'] = false; $res['error'] = 'Выберите пункт выдачи СДЭК'; }
        } elseif ($method === 'post' && post_ready()) {
            $res['api'] = true;
            $idx = preg_replace('/\D/', '', (string)($dest['index'] ?? ''));
            if (strlen($idx) !== 6) return ['price' => 0, 'ready' => false, 'error' => 'Укажите почтовый индекс — рассчитаем стоимость', 'eta' => '', 'api' => true];
            $c = post_calc($idx, $weight);
            $res['price'] = $c['price'] + (float)setting('post_markup');
            $res['eta'] = $c['max'] ? ($c['min'] && $c['min'] !== $c['max'] ? $c['min'] . '–' : '') . $c['max'] . ' ' . plural((int)$c['max'], 'день', 'дня', 'дней') : '';
        }
    } catch (Throwable $e) {
        // API недоступно — берём фиксированную цену из настроек, чтобы не терять заказ
        $res['price'] = (float)$m['price'];
        $res['note'] = $e->getMessage();
    }
    if ($isFree) $res['price'] = 0;
    return $res;
}

/**
 * Полный расчёт заказа: товары (опт, персональная, наборы, промокод, баллы) + доставка + сертификат.
 * Используется и корзиной (api.php?action=quote), и оформлением заказа — цифры всегда одинаковые.
 */
function checkout_calc(array $in, ?array $user): array
{
    $q = quote((array)($in['items'] ?? []), $user, (string)($in['coupon'] ?? ''), 0, !empty($in['use_bonus']));
    $method = (string)($in['delivery'] ?? '');
    $dest = (array)($in['dest'] ?? []);
    $q['weight'] = cart_weight($q['items']);
    $dq = $method !== '' ? delivery_quote($method, (float)$q['items_total'], $q['weight'], $dest) : ['price' => 0, 'ready' => false, 'error' => '', 'eta' => ''];
    $q['delivery'] = (float)$dq['price'];
    $q['delivery_ready'] = (bool)$dq['ready'];
    $q['delivery_error'] = (string)$dq['error'];
    $q['delivery_eta'] = (string)($dq['eta'] ?? '');
    $q['delivery_note'] = (string)($dq['note'] ?? '');
    $sum = (float)$q['items_total'] + $q['delivery'];
    [$g, $gErr] = gift_on() ? gift_check((string)($in['gift'] ?? '')) : [null, ''];
    $q['gift'] = $g ? $g['code'] : '';
    $q['gift_error'] = $gErr;
    $q['gift_balance'] = $g ? (float)$g['balance'] : 0;
    $q['gift_use'] = $g ? min((float)$g['balance'], $sum) : 0;
    $q['total'] = max(0, $sum - $q['gift_use']);
    $q['free_from'] = (float)setting('free_delivery_from');
    return $q;
}

/* ================= Наборы ================= */

function bundles_active(): array
{
    static $b = null;
    if ($b !== null) return $b;
    $b = [];
    foreach (db()->query('SELECT * FROM bundles WHERE active = 1 ORDER BY sort, id') as $r) {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)json_decode((string)$r['product_ids'], true)))));
        if (count($ids) < 2) continue;
        $r['ids'] = $ids;
        $b[] = $r;
    }
    return $b;
}

/** Наборы, в которых все товары сейчас продаются. */
function bundles_on_sale(?int $withProduct = null): array
{
    $out = [];
    foreach (bundles_active() as $b) {
        if ($withProduct && !in_array($withProduct, $b['ids'], true)) continue;
        $ps = [];
        foreach ($b['ids'] as $id) { $p = find_product($id); if (!product_on_sale($p)) continue 2; $ps[] = $p; }
        $sum = array_sum(array_map('site_price', $ps));
        $b['products'] = $ps;
        $b['sum'] = $sum;
        $b['price'] = array_sum(array_map(fn($p) => max(1, round(site_price($p) * (1 - (float)$b['percent'] / 100))), $ps));
        $out[] = $b;
    }
    return $out;
}

/** «Часто покупают вместе»: по совместным заказам, иначе — товары из других категорий с хорошим остатком. */
function bought_together(array $p, int $n = 3): array
{
    $pid = (int)$p['id'];
    $key = 'fbt_' . $pid;
    $ids = kv_get($key);
    if ($ids === null) {
        $cnt = [];
        $st = db()->prepare("SELECT items FROM orders WHERE status <> 'cancelled' AND items LIKE ? ORDER BY id DESC LIMIT 500");
        $st->execute(['%"id":' . $pid . ',%']);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $json) {
            $its = json_decode((string)$json, true) ?: [];
            if (!in_array($pid, array_map(fn($i) => (int)($i['id'] ?? 0), $its), true)) continue;
            foreach ($its as $it) { $i = (int)($it['id'] ?? 0); if ($i && $i !== $pid) $cnt[$i] = ($cnt[$i] ?? 0) + 1; }
        }
        arsort($cnt);
        $ids = array_slice(array_keys($cnt), 0, 10);
        kv_set($key, $ids, 3600 * 6);
    }
    $out = [];
    foreach ($ids as $id) { $x = find_product((int)$id); if (product_on_sale($x)) $out[] = $x; if (count($out) >= $n) break; }
    if (count($out) < $n && !empty($p['type_id'])) {
        $have = array_merge([$pid], array_map(fn($x) => (int)$x['id'], $out));
        $st = db()->prepare('SELECT * FROM products WHERE type_id <> ? AND id NOT IN (' . implode(',', $have) . ') AND ' . visible_where() . ' ORDER BY (stock > 20) DESC, RANDOM() LIMIT ' . ($n - count($out)));
        $st->execute([$p['type_id']]);
        foreach ($st->fetchAll() as $x) $out[] = $x;
    }
    return $out;
}

/* ================= Telegram для покупателей ================= */

function tg_api(string $method, array $params = [], int $timeout = 10): array
{
    $tok = setting('tg_bot_token');
    if ($tok === '') return ['ok' => false, 'error' => 'нет токена бота', 'data' => null, 'code' => 0];
    $base = getenv('MB_TG_BASE') ?: 'https://api.telegram.org';
    return http_json('POST', $base . '/bot' . $tok . '/' . $method, [], $params, $timeout);
}

function tg_send(string $chat, string $text, array $extra = []): bool
{
    if ($chat === '') return false;
    $r = tg_api('sendMessage', ['chat_id' => $chat, 'text' => $text, 'disable_web_page_preview' => true] + $extra);
    return $r['ok'];
}

function tg_customers_on(): bool { return setting('tg_customers') === '1' && setting('tg_bot_token') !== ''; }

function tg_bot_username(): string
{
    if (($u = setting('tg_bot_username')) !== '') return $u;
    $r = tg_api('getMe');
    $u = (string)($r['data']['result']['username'] ?? '');
    if ($u !== '') setting_set('tg_bot_username', $u);
    return $u;
}

/** Ссылка «открыть бота» — после нажатия Start бот узнаёт покупателя или заказ. */
function tg_link_url(?int $userId, ?int $orderId): string
{
    $bot = tg_bot_username();
    if ($bot === '') return '';
    $token = substr(bin2hex(random_bytes(12)), 0, 20);
    db()->prepare('INSERT INTO tg_links(token, user_id, order_id, created_at) VALUES (?, ?, ?, ?)')->execute([$token, $userId, $orderId, now()]);
    db()->exec("DELETE FROM tg_links WHERE created_at < datetime('now', '-3 day', 'localtime')");
    return 'https://t.me/' . $bot . '?start=' . $token;
}

function tg_process_update(array $u): void
{
    $m = $u['message'] ?? null;
    if (!$m || empty($m['chat']['id'])) return;
    $chat = (string)$m['chat']['id'];
    $text = trim((string)($m['text'] ?? ''));
    $pdo = db();
    $shop = setting('shop_name');
    if (preg_match('~^/start(?:\s+([a-f0-9]{8,40}))?~', $text, $mm)) {
        if (!empty($mm[1])) {
            $st = $pdo->prepare('SELECT * FROM tg_links WHERE token = ?');
            $st->execute([$mm[1]]);
            if ($l = $st->fetch()) {
                $pdo->prepare('DELETE FROM tg_links WHERE token = ?')->execute([$mm[1]]);
                if ($l['user_id']) {
                    $pdo->prepare('UPDATE users SET tg_chat_id = ? WHERE id = ?')->execute([$chat, $l['user_id']]);
                    $nm = $pdo->query('SELECT name FROM users WHERE id = ' . (int)$l['user_id'])->fetchColumn();
                    tg_send($chat, "Готово, {$nm}! Теперь здесь будут статусы ваших заказов, промокоды и акции {$shop}.\n\n/orders — мои заказы\n/bonus — мои баллы\n/stop — отключить уведомления");
                    return;
                }
                if ($l['order_id']) {
                    $pdo->prepare('UPDATE orders SET tg_chat_id = ? WHERE id = ?')->execute([$chat, $l['order_id']]);
                    $o = $pdo->query('SELECT number, status FROM orders WHERE id = ' . (int)$l['order_id'])->fetch();
                    tg_send($chat, "Будем сообщать о заказе {$o['number']} здесь. Сейчас: " . (order_statuses()[$o['status']] ?? $o['status']) . '.');
                    return;
                }
            }
            tg_send($chat, 'Ссылка устарела. Откройте её заново на сайте ' . base_url());
            return;
        }
        tg_send($chat, "Здравствуйте! Это бот магазина {$shop}.\nЧтобы получать статусы заказов, нажмите «Подключить Telegram» в личном кабинете на сайте: " . base_url() . '/account.php');
        return;
    }
    $st = $pdo->prepare('SELECT * FROM users WHERE tg_chat_id = ?');
    $st->execute([$chat]);
    $user = $st->fetch();
    if ($text === '/orders') {
        $rows = [];
        if ($user) { $o = $pdo->prepare('SELECT number, status, total, created_at FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 5'); $o->execute([$user['id']]); $rows = $o->fetchAll(); }
        else { $o = $pdo->prepare('SELECT number, status, total, created_at FROM orders WHERE tg_chat_id = ? ORDER BY id DESC LIMIT 5'); $o->execute([$chat]); $rows = $o->fetchAll(); }
        if (!$rows) { tg_send($chat, 'Заказов пока нет.'); return; }
        tg_send($chat, "Ваши заказы:\n" . implode("\n", array_map(fn($r) => "• {$r['number']} от " . date('d.m', strtotime($r['created_at'])) . ' — ' . money((float)$r['total']) . ', ' . mb_strtolower(order_statuses()[$r['status']] ?? $r['status']), $rows)));
        return;
    }
    if ($text === '/bonus') {
        tg_send($chat, $user ? 'У вас ' . (int)bonus_balance((int)$user['id']) . ' баллов. 1 балл = 1 ₽, можно оплатить до ' . (int)setting('bonus_max_percent') . '% заказа.' : 'Подключите бота в личном кабинете на сайте, чтобы видеть баллы.');
        return;
    }
    if ($text === '/stop') {
        $pdo->prepare('UPDATE users SET tg_chat_id = NULL WHERE tg_chat_id = ?')->execute([$chat]);
        $pdo->prepare('UPDATE orders SET tg_chat_id = NULL WHERE tg_chat_id = ?')->execute([$chat]);
        tg_send($chat, 'Уведомления отключены. Подключить снова можно в личном кабинете.');
        return;
    }
    tg_send($chat, "/orders — мои заказы\n/bonus — мои баллы\n/stop — отключить уведомления\n\nПо вопросам: " . (setting('phone') ?: setting('telegram_contact') ?: base_url()));
}

/** Без https (webhook) бот опрашивает Telegram сам: при cron и при нажатии «Проверить» в кабинете. */
function tg_poll(): int
{
    if (!tg_customers_on()) return 0;
    $r = tg_api('getUpdates', ['offset' => (int)setting('tg_offset') + 1, 'timeout' => 0, 'allowed_updates' => ['message']], 15);
    if (!$r['ok']) return 0;
    $n = 0;
    foreach ($r['data']['result'] ?? [] as $u) {
        setting_set('tg_offset', (string)(int)$u['update_id']);
        try { tg_process_update($u); } catch (Throwable) {}
        $n++;
    }
    return $n;
}

/** Сообщение покупателю о заказе: в Telegram (если подключён) и на e-mail. */
function notify_customer(array $o, string $event): void
{
    $statuses = order_statuses();
    $link = base_url() . '/order.php?n=' . rawurlencode((string)$o['number']) . '&t=' . $o['token'];
    $shop = setting('shop_name');
    $text = match ($event) {
        'created' => "Спасибо за заказ {$o['number']} в {$shop}! Сумма " . money((float)$o['total']) . '.',
        'paid'    => "Заказ {$o['number']} оплачен. Собираем его — сообщим, когда отправим.",
        default   => "Заказ {$o['number']}: " . mb_strtolower($statuses[$o['status']] ?? $o['status']) . '.' . (!empty($o['admin_note']) && in_array($o['status'], ['shipped', 'ready'], true) ? "\n" . $o['admin_note'] : ''),
    };
    if ($event === 'status' && $o['status'] === 'done' && bonus_on() && !empty($o['user_id'])) {
        $earn = floor((float)$o['items_total'] * (float)setting('bonus_percent') / 100);
        if ($earn >= 1) $text .= "\nНачислили {$earn} баллов — ими можно оплатить следующий заказ.";
    }
    $text .= "\n\nПодробнее: {$link}";
    $chat = (string)($o['tg_chat_id'] ?? '');
    if ($chat === '' && !empty($o['user_id'])) {
        $st = db()->prepare('SELECT tg_chat_id FROM users WHERE id = ?');
        $st->execute([$o['user_id']]);
        $chat = (string)$st->fetchColumn();
    }
    if ($chat !== '' && setting('tg_bot_token') !== '') tg_send($chat, $text);
    if (!empty($o['email']) && setting('notify_customer_email') === '1') {
        $subj = match ($event) { 'created' => 'Заказ ' . $o['number'] . ' принят', 'paid' => 'Заказ ' . $o['number'] . ' оплачен', default => 'Заказ ' . $o['number'] . ': ' . mb_strtolower($statuses[$o['status']] ?? $o['status']) };
        send_mail((string)$o['email'], $subj . ' — ' . $shop, $text);
    }
}
