<?php
/**
 * Покупатели, расчёт цены (опт / персональная скидка / купон), купоны и отзывы.
 * Цены всегда считаются здесь, на сервере — корзина в браузере только показывает результат.
 */
declare(strict_types=1);

/* ---------- Покупатели ---------- */

function current_user(): ?array
{
    static $u = false;
    if ($u !== false) return $u;
    $u = null;
    $id = (int)($_SESSION['uid'] ?? 0);
    if ($id) {
        $st = db()->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([$id]);
        $u = $st->fetch() ?: null;
        if (!$u) unset($_SESSION['uid']);
    }
    return $u;
}

function login_user(int $id): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = $id;
    db()->prepare('UPDATE users SET last_login = ? WHERE id = ?')->execute([now(), $id]);
}

function normalize_phone(string $p): string
{
    $d = preg_replace('/\D+/', '', $p);
    if (strlen($d) === 11 && ($d[0] === '8' || $d[0] === '7')) $d = '7' . substr($d, 1);
    if (strlen($d) === 10) $d = '7' . $d;
    return $d;
}

/** Простое ограничение попыток входа (на сессию и на e-mail). */
function login_throttled(string $key): bool
{
    $f = MB_DATA . '/throttle.json';
    $all = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    $now = time();
    foreach ($all as $k => $v) if ($v['t'] < $now - 900) unset($all[$k]);
    $h = md5($key);
    return ($all[$h]['n'] ?? 0) >= 8;
}

function login_failed(string $key): void
{
    $f = MB_DATA . '/throttle.json';
    $all = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    $h = md5($key);
    $all[$h] = ['n' => ($all[$h]['n'] ?? 0) + 1, 't' => time()];
    @file_put_contents($f, json_encode($all), LOCK_EX);
}

/* ---------- Расчёт цены ---------- */

function wholesale_rule(): array
{
    return ['qty' => max(0, (int)setting('wholesale_qty')), 'percent' => max(0, min(90, (float)setting('wholesale_percent')))];
}

/**
 * Расчёт корзины. $lines = [[id, qty], ...].
 * Скидка на строку = большая из (оптовая, если qty ≥ порога; персональная покупателя).
 * Купон применяется к сумме товаров после этих скидок.
 */
function quote(array $lines, ?array $user, string $couponCode = '', float $deliveryPrice = 0, bool $useBonus = false): array
{
    $w = wholesale_rule();
    $personal = $user ? max(0, min(90, (float)$user['discount_percent'])) : 0;
    $items = [];
    $base = 0.0; $sub = 0.0;
    // Наборы: если в корзине все товары набора — на них действует скидка набора
    $inCart = array_map(fn($l) => (int)($l['id'] ?? $l[0] ?? 0), $lines);
    $bundlePct = [];
    foreach (bundles_active() as $b) {
        if (array_diff($b['ids'], $inCart)) continue;
        foreach ($b['ids'] as $bid) $bundlePct[$bid] = max($bundlePct[$bid] ?? 0, (float)$b['percent']);
    }
    $seen = [];
    foreach (array_slice($lines, 0, 100) as $l) {
        $id = (int)($l['id'] ?? $l[0] ?? 0);
        if (isset($seen[$id])) continue;
        $seen[$id] = 1;
        $qty = max(1, min(999, (int)($l['qty'] ?? $l[1] ?? 1)));
        $p = find_product($id);
        if (!product_on_sale($p)) { $items[] = ['id' => $id, 'ok' => false]; continue; }
        $unit = site_price($p);
        $pct = 0.0; $why = '';
        if ($w['qty'] > 0 && $w['percent'] > 0 && $qty >= $w['qty']) { $pct = $w['percent']; $why = 'опт от ' . $w['qty'] . ' шт'; }
        if ($personal > $pct) { $pct = $personal; $why = 'ваша скидка'; }
        if (($bundlePct[$id] ?? 0) > $pct) { $pct = $bundlePct[$id]; $why = 'набор'; }
        $final = $pct > 0 ? max(1, round($unit * (1 - $pct / 100))) : $unit;
        $items[] = [
            'id' => $id, 'ok' => true, 'qty' => $qty, 'name' => product_title_full($p), 'code' => (string)$p['site_code'],
            'offer_id' => $p['offer_id'], 'img' => img_url(product_image($p), 200), 'weight' => (int)($p['weight_g'] ?? 0),
            'unit' => $unit, 'price' => $final, 'pct' => $pct, 'why' => $why, 'sum' => $final * $qty,
            'stock' => (int)$p['stock'],
        ];
        $base += $unit * $qty;
        $sub += $final * $qty;
    }
    $coupon = null; $couponErr = ''; $couponDisc = 0.0;
    $couponCode = trim($couponCode);
    if ($couponCode !== '') {
        [$coupon, $couponErr] = coupon_check($couponCode, $user, $sub);
        if ($coupon) {
            $couponDisc = $coupon['kind'] === 'fixed' ? min($sub, (float)$coupon['value']) : round($sub * min(100, (float)$coupon['value']) / 100);
        }
    }
    // Баллы: 1 балл = 1 ₽, не больше заданной доли заказа
    $bonusBal = ($user && bonus_on()) ? bonus_balance((int)$user['id']) : 0;
    $bonusMax = floor(max(0, $sub - $couponDisc) * max(0, min(100, (float)setting('bonus_max_percent'))) / 100);
    $bonusUse = $useBonus ? min($bonusBal, $bonusMax) : 0;
    $itemsTotal = max(0, $sub - $couponDisc - $bonusUse);
    return [
        'items' => $items,
        'base' => $base,                       // без скидок
        'subtotal' => $sub,                    // после опт/персональной/набора
        'coupon' => $coupon ? $coupon['code'] : '',
        'coupon_error' => $couponErr,
        'coupon_discount' => $couponDisc,
        'bonus_balance' => $bonusBal,
        'bonus_max' => min($bonusBal, $bonusMax),
        'bonus_use' => $bonusUse,
        'items_total' => $itemsTotal,
        'discount_total' => $base - $itemsTotal,
        'personal' => $personal,
        'wholesale' => $w,
    ];
}

/* ---------- Купоны ---------- */

/** @return array{0: ?array, 1: string} купон или текст ошибки */
function coupon_check(string $code, ?array $user, float $sum): array
{
    $st = db()->prepare('SELECT * FROM coupons WHERE code = ? COLLATE NOCASE');
    $st->execute([$code]);
    $c = $st->fetch();
    if (!$c || !$c['active']) return [null, 'Такого промокода нет'];
    if ($c['expires_at'] && $c['expires_at'] < date('Y-m-d')) return [null, 'Срок действия промокода закончился'];
    if ($c['max_uses'] > 0 && $c['used'] >= $c['max_uses']) return [null, 'Промокод уже использован'];
    if ($c['user_id']) {
        if (!$user) return [null, 'Войдите в личный кабинет, чтобы применить этот промокод'];
        if ((int)$c['user_id'] !== (int)$user['id']) return [null, 'Этот промокод выдан другому покупателю'];
    }
    if ($c['once_per_user'] && $user) {
        $u = db()->prepare("SELECT COUNT(*) FROM orders WHERE user_id = ? AND coupon_code = ? COLLATE NOCASE AND status <> 'cancelled'");
        $u->execute([$user['id'], $c['code']]);
        if ((int)$u->fetchColumn() > 0) return [null, 'Вы уже использовали этот промокод'];
    }
    if ((float)$c['min_sum'] > 0 && $sum < (float)$c['min_sum']) return [null, 'Промокод действует от ' . money((float)$c['min_sum'])];
    return [$c, ''];
}

function coupon_label(array $c): string
{
    return $c['kind'] === 'fixed' ? '−' . money((float)$c['value']) : '−' . rtrim(rtrim(number_format((float)$c['value'], 1, ',', ''), '0'), ',') . '%';
}

/* ---------- Отзывы ---------- */

function product_rating(int $productId): array
{
    $st = db()->prepare("SELECT COUNT(*) n, AVG(rating) avg FROM reviews WHERE product_id = ? AND status = 'approved'");
    $st->execute([$productId]);
    $r = $st->fetch();
    return ['n' => (int)$r['n'], 'avg' => $r['n'] ? round((float)$r['avg'], 1) : 0];
}

/** Рейтинги сразу для многих товаров (для карточек). */
function ratings_map(): array
{
    static $m = null;
    if ($m === null) {
        $m = [];
        foreach (db()->query("SELECT product_id, COUNT(*) n, AVG(rating) avg FROM reviews WHERE status = 'approved' GROUP BY product_id") as $r) {
            $m[(int)$r['product_id']] = ['n' => (int)$r['n'], 'avg' => round((float)$r['avg'], 1)];
        }
    }
    return $m;
}

function stars(float $v, string $cls = ''): string
{
    $pct = max(0, min(100, $v / 5 * 100));
    return '<span class="stars ' . $cls . '" role="img" aria-label="Оценка ' . number_format($v, 1, ',', '') . ' из 5"><span style="width:' . $pct . '%"></span></span>';
}

/** Покупал ли пользователь этот товар (для отметки «Купил на сайте»). */
function user_bought(int $userId, int $productId): bool
{
    $st = db()->prepare("SELECT items FROM orders WHERE user_id = ? AND status IN ('paid','processing','shipped','ready','done')");
    $st->execute([$userId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $json) {
        foreach (json_decode((string)$json, true) ?: [] as $it) if ((int)($it['id'] ?? 0) === $productId) return true;
    }
    return false;
}
