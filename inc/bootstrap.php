<?php
/**
 * MaxBoom Shop — ядро сайта.
 * PHP 8.1+, SQLite (PDO). Все настройки хранятся в базе и меняются в админке.
 */
declare(strict_types=1);

const MB_VERSION = '1.007';
define('MB_ROOT', dirname(__DIR__));
define('MB_DATA', MB_ROOT . '/data');

mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Moscow');

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    // Сессии храним в своей папке (на хостинге общая папка сессий бывает недоступна),
    // флаг secure не ставим: за прокси хостинга PHP может «думать», что соединение https, когда это не так
    $sessDir = MB_DATA . '/sessions';
    if (!is_dir($sessDir)) @mkdir($sessDir, 0700, true);
    if (is_dir($sessDir) && is_writable($sessDir)) session_save_path($sessDir);
    session_name('mbshop');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

/* ---------- База данных ---------- */

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    if (!is_dir(MB_DATA)) mkdir(MB_DATA, 0775, true);
    $pdo = new PDO('sqlite:' . MB_DATA . '/shop.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;');
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT);
    CREATE TABLE IF NOT EXISTS products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ozon_product_id INTEGER UNIQUE,
        offer_id TEXT,
        sku TEXT,
        name TEXT,
        description TEXT,
        images TEXT,
        ozon_price REAL DEFAULT 0,
        ozon_old_price REAL DEFAULT 0,
        manual_price REAL,
        hidden INTEGER DEFAULT 0,
        archived INTEGER DEFAULT 0,
        stock INTEGER,
        wb_nm TEXT,
        wb_url TEXT,
        ym_url TEXT,
        sort INTEGER DEFAULT 0,
        created_at TEXT,
        updated_at TEXT
    );
    CREATE INDEX IF NOT EXISTS idx_products_offer ON products(offer_id);
    CREATE TABLE IF NOT EXISTS orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        number TEXT,
        created_at TEXT,
        status TEXT,
        customer_name TEXT,
        phone TEXT,
        email TEXT,
        delivery_method TEXT,
        delivery_address TEXT,
        delivery_price REAL DEFAULT 0,
        items_total REAL DEFAULT 0,
        total REAL DEFAULT 0,
        payment_method TEXT,
        payment_id TEXT,
        paid_at TEXT,
        comment TEXT,
        admin_note TEXT,
        items TEXT,
        token TEXT
    );
    CREATE TABLE IF NOT EXISTS sync_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        at TEXT, ok INTEGER, message TEXT
    );
    ");
    // Старый подзаголовок (про 3D-печать) заменяем на новый
    $pdo->exec("UPDATE settings SET value = 'Собственное производство из безопасного кукурузного пластика' WHERE key = 'shop_tagline' AND value LIKE '%3D-принтер%'");
    // v1.002: категории из Ozon, неоновый стиль
    $cols = array_column($pdo->query('PRAGMA table_info(products)')->fetchAll(), 'name');
    foreach (['category_id' => 'INTEGER', 'type_id' => 'INTEGER', 'site_code' => 'TEXT'] as $c => $t) {
        if (!in_array($c, $cols, true)) $pdo->exec("ALTER TABLE products ADD COLUMN $c $t");
    }
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS categories (
        id INTEGER PRIMARY KEY,          -- type_id Ozon
        ozon_name TEXT,                  -- название из Ozon
        name TEXT,                       -- своё название (если задано)
        parent_name TEXT,                -- родительская категория Ozon
        hidden INTEGER DEFAULT 0,
        sort INTEGER DEFAULT 100
    );
    CREATE INDEX IF NOT EXISTS idx_products_type ON products(type_id);
    CREATE UNIQUE INDEX IF NOT EXISTS idx_products_code ON products(site_code);
    ");
    assign_site_codes($pdo);
    $pdo->exec("UPDATE settings SET value = '#00e5ff' WHERE key = 'theme_accent' AND lower(value) = '#ff6a00'");
    // v1.003: покупатели, купоны, отзывы, опт
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT UNIQUE,
        phone TEXT,
        name TEXT,
        pass_hash TEXT,
        discount_percent REAL DEFAULT 0,
        note TEXT,
        reset_token TEXT,
        reset_until TEXT,
        created_at TEXT,
        last_login TEXT
    );
    CREATE TABLE IF NOT EXISTS coupons (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT UNIQUE COLLATE NOCASE,
        kind TEXT DEFAULT 'percent',      -- percent | fixed
        value REAL DEFAULT 0,
        min_sum REAL DEFAULT 0,
        max_uses INTEGER DEFAULT 0,       -- 0 = без ограничения
        used INTEGER DEFAULT 0,
        once_per_user INTEGER DEFAULT 1,
        user_id INTEGER,                  -- персональный купон
        expires_at TEXT,
        active INTEGER DEFAULT 1,
        note TEXT,
        created_at TEXT
    );
    CREATE TABLE IF NOT EXISTS reviews (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER,
        user_id INTEGER,
        name TEXT,
        rating INTEGER,
        text TEXT,
        verified INTEGER DEFAULT 0,
        status TEXT DEFAULT 'pending',    -- pending | approved | rejected
        reply TEXT,
        created_at TEXT
    );
    CREATE INDEX IF NOT EXISTS idx_reviews_product ON reviews(product_id, status);
    ");
    $ocols = array_column($pdo->query('PRAGMA table_info(orders)')->fetchAll(), 'name');
    foreach (['user_id' => 'INTEGER', 'discount_total' => 'REAL DEFAULT 0', 'coupon_code' => 'TEXT',
              // v1.004
              'bonus_used' => 'REAL DEFAULT 0', 'gift_code' => 'TEXT', 'gift_used' => 'REAL DEFAULT 0', 'kind' => "TEXT DEFAULT 'goods'",
              'bonus_accrued' => 'INTEGER DEFAULT 0', 'bonus_refunded' => 'INTEGER DEFAULT 0', 'tg_chat_id' => 'TEXT', 'dlv_meta' => 'TEXT'] as $c => $t) {
        if (!in_array($c, $ocols, true)) $pdo->exec("ALTER TABLE orders ADD COLUMN $c $t");
    }
    // v1.004: избранное, бонусы, сертификаты, наборы, Telegram, кэш доставки
    $ucols = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(), 'name');
    foreach (['email_verified' => 'INTEGER DEFAULT 0', 'verify_token' => 'TEXT', 'ref_code' => 'TEXT', 'referred_by' => 'INTEGER',
              'ref_rewarded' => 'INTEGER DEFAULT 0', 'tg_chat_id' => 'TEXT', 'welcome_given' => 'INTEGER DEFAULT 0'] as $c => $t) {
        if (!in_array($c, $ucols, true)) $pdo->exec("ALTER TABLE users ADD COLUMN $c $t");
    }
    $pcols = array_column($pdo->query('PRAGMA table_info(products)')->fetchAll(), 'name');
    if (!in_array('weight_g', $pcols, true)) $pdo->exec('ALTER TABLE products ADD COLUMN weight_g INTEGER');
    $rcols = array_column($pdo->query('PRAGMA table_info(reviews)')->fetchAll(), 'name');
    foreach (['source' => "TEXT DEFAULT 'site'", 'ext_id' => 'TEXT'] as $c => $t) {
        if (!in_array($c, $rcols, true)) $pdo->exec("ALTER TABLE reviews ADD COLUMN $c $t");
    }
    $pdo->exec("
    CREATE UNIQUE INDEX IF NOT EXISTS idx_reviews_ext ON reviews(ext_id);
    CREATE UNIQUE INDEX IF NOT EXISTS idx_users_ref ON users(ref_code);
    CREATE TABLE IF NOT EXISTS favorites (user_id INTEGER, product_id INTEGER, created_at TEXT, PRIMARY KEY (user_id, product_id));
    CREATE TABLE IF NOT EXISTS bonus_tx (
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, amount REAL, reason TEXT, order_id INTEGER, created_at TEXT
    );
    CREATE INDEX IF NOT EXISTS idx_bonus_user ON bonus_tx(user_id);
    CREATE TABLE IF NOT EXISTS gift_cards (
        id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT UNIQUE COLLATE NOCASE, amount REAL, balance REAL,
        buyer_email TEXT, recipient_name TEXT, recipient_email TEXT, message TEXT,
        order_id INTEGER, status TEXT DEFAULT 'pending', created_at TEXT, expires_at TEXT
    );
    CREATE TABLE IF NOT EXISTS bundles (
        id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, product_ids TEXT, percent REAL DEFAULT 10,
        active INTEGER DEFAULT 1, sort INTEGER DEFAULT 100, created_at TEXT
    );
    CREATE TABLE IF NOT EXISTS kv_cache (key TEXT PRIMARY KEY, value TEXT, until INTEGER);
    CREATE TABLE IF NOT EXISTS tg_links (token TEXT PRIMARY KEY, user_id INTEGER, order_id INTEGER, created_at TEXT);
    ");
    // v1.005: цвета-варианты, снятие категорий с продажи
    $pcols = array_column($pdo->query('PRAGMA table_info(products)')->fetchAll(), 'name');
    foreach (['model_id' => 'INTEGER', 'color' => 'TEXT', 'color_hex' => 'TEXT', 'color_src' => 'TEXT', 'group_key' => 'TEXT'] as $c => $t) {
        if (!in_array($c, $pcols, true)) $pdo->exec("ALTER TABLE products ADD COLUMN $c $t");
    }
    $ccols = array_column($pdo->query('PRAGMA table_info(categories)')->fetchAll(), 'name');
    if (!in_array('off_sale', $ccols, true)) $pdo->exec('ALTER TABLE categories ADD COLUMN off_sale INTEGER DEFAULT 0');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_products_group ON products(group_key)');
    $v5 = $pdo->query("SELECT value FROM settings WHERE key = 'v1005_init'")->fetchColumn();
    if ($v5 !== '1') {
        $pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('v1005_init', '1')");
        // Баннеры — заново, автоматическими подборками
        $pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('banners', '')");
        try { variants_rebuild($pdo); } catch (Throwable $e) { error_log('[MaxBoom] variants: ' . $e->getMessage()); }
    }
    // v1.006: отзывы Ozon 4★ и 5★ — сразу подгрузить заново; новые подборки
    if ($pdo->query("SELECT value FROM settings WHERE key = 'v1006_init'")->fetchColumn() !== '1') {
        $pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('v1006_init', '1')");
        $pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('ozon_reviews_import', '1')");
        $pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('ozon_reviews_last', '')");
        $pdo->exec("DELETE FROM kv_cache WHERE key LIKE 'collections_%'");
    }
}

/* ---------- Настройки ---------- */

const MB_DEFAULTS = [
    'update_repo'        => '',           // GitHub: владелец/репозиторий для автообновления сайта
    'update_branch'      => 'main',
    'update_token'       => '',           // токен GitHub (нужен только для закрытого репозитория)
    'update_auto'        => '1',
    'update_sha'         => '',
    'update_last'        => '',
    'update_last_check'  => '',
    'shop_name'          => 'MaxBoom',
    'shop_tagline'       => 'Собственное производство из безопасного кукурузного пластика',
    'discount_percent'   => '45',
    'price_rounding'     => '1',          // 1, 10, 50, 100 или 9 (окончание на 9)
    'price_source'       => 'marketing_seller_price', // какое поле цены Ozon брать
    'ozon_client_id'     => '',
    'ozon_api_key'       => '',
    'wb_token'           => '',
    'link_ozon_store'    => '',
    'link_wb_store'      => '',
    'link_ym_store'      => '',
    'phone'              => '',
    'email'              => '',
    'telegram_contact'   => '',
    'pickup_enabled'     => '1',
    'pickup_address'     => '',
    'pickup_hours'       => 'Пн–Пт 10:00–19:00',
    'pickup_pay_on_site' => '1',          // при самовывозе можно оплатить на месте
    'cdek_enabled'       => '1',
    'cdek_price'         => '350',
    'ozon_dlv_enabled'   => '1',
    'ozon_dlv_price'     => '250',
    'post_enabled'       => '1',
    'post_price'         => '400',
    'free_delivery_from' => '0',          // 0 = нет бесплатной доставки
    'min_order'          => '0',
    'yk_shop_id'         => '',
    'yk_secret'          => '',
    'yk_receipt'         => '0',          // передавать чек 54-ФЗ
    'yk_vat_code'        => '1',          // 1 = без НДС
    'tg_bot_token'       => '',
    'tg_chat_id'         => '',
    'notify_email'       => '',
    'cron_key'           => '',
    'admin_password'     => '',
    'hide_out_of_stock'  => '0',
    'theme_accent'       => '#00e5ff',
    'wholesale_qty'      => '10',         // опт: от скольких штук одного товара
    'wholesale_percent'  => '10',         // опт: скидка, %
    'reviews_enabled'    => '1',
    'reviews_premod'     => '1',          // отзывы появляются после проверки
    'banners'            => '',           // JSON со слайдами главной (пусто = стандартные)
    'ticker'             => 'Собственное производство · Безопасный кукурузный пластик PLA · Отправка СДЭК, Ozon и Почтой России · Самовывоз из магазина',
    // v1.004 — бонусы, рефералы, сертификаты
    'bonus_enabled'      => '1',
    'bonus_percent'      => '5',          // кэшбэк баллами с выполненного заказа, %
    'bonus_max_percent'  => '30',         // какую часть заказа можно оплатить баллами, %
    'bonus_welcome'      => '100',        // баллы за подтверждение e-mail
    'ref_enabled'        => '1',
    'ref_bonus_new'      => '200',        // баллы приглашённому (после подтверждения e-mail)
    'ref_bonus_owner'    => '300',        // баллы пригласившему (после первого выполненного заказа друга)
    'gift_enabled'       => '1',
    'gift_nominals'      => '500,1000,2000,3000,5000',
    'gift_days'          => '365',
    // доставка по API
    'cdek_api'           => '0',
    'cdek_client_id'     => '',
    'cdek_client_secret' => '',
    'cdek_test'          => '0',
    'cdek_from_city'     => 'Москва',
    'cdek_tariff'        => '136',        // склад — пункт выдачи
    'cdek_markup'        => '0',
    'post_api'           => '0',
    'post_from_index'    => '',
    'post_object'        => '27030',      // посылка стандарт
    'post_markup'        => '0',
    'default_weight_g'   => '300',
    'yandex_maps_key'    => '',
    // Telegram для покупателей
    'tg_customers'       => '1',
    'tg_bot_username'    => '',
    'tg_offset'          => '0',
    'tg_secret'          => '',
    'notify_customer_email' => '1',
    // отзывы с Ozon
    'ozon_reviews_import'=> '1',
    'ozon_reviews_last'  => '',
    // акция с таймером, остатки
    'sale_on'            => '0',
    'sale_title'         => 'Распродажа',
    'sale_percent'       => '10',
    'sale_until'         => '',
    'sale_cats'          => '',
    'low_stock'          => '5',
    // SEO, аналитика, скорость
    'pretty_urls'        => '1',
    'seo_title'          => '',
    'seo_description'    => '',
    'metrika_id'         => '',
    'img_resize'         => '1',
    'page_cache'         => '1',
];

function setting(string $key): string
{
    if (!isset($GLOBALS['__mb_settings'])) {
        $GLOBALS['__mb_settings'] = [];
        foreach (db()->query('SELECT key, value FROM settings') as $r) $GLOBALS['__mb_settings'][$r['key']] = $r['value'];
    }
    return $GLOBALS['__mb_settings'][$key] ?? (MB_DEFAULTS[$key] ?? '');
}

function setting_set(string $key, string $value): void
{
    $st = db()->prepare('INSERT INTO settings(key, value) VALUES(?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $st->execute([$key, $value]);
    unset($GLOBALS['__mb_settings']);
}

/* ---------- Цены ---------- */

/** Цена на сайте: ручная (если задана) или цена Ozon минус общая скидка. */
function site_price(array $p): float
{
    $base = base_price($p);
    $s = sale_percent_for($p);
    return $s > 0 ? max(1, round_price($base * (1 - $s / 100))) : $base;
}

/** Цена без акции: ручная или цена Ozon минус общая скидка. */
function base_price(array $p): float
{
    if ($p['manual_price'] !== null && $p['manual_price'] !== '' && (float)$p['manual_price'] > 0) {
        return (float)$p['manual_price'];
    }
    return auto_price((float)$p['ozon_price']);
}

/** Активная акция с таймером: [percent, until_ts, title, cats[]] или null. */
function sale_active(): ?array
{
    static $s = false;
    if ($s !== false) return $s;
    $s = null;
    if (setting('sale_on') !== '1') return null;
    $pc = max(0, min(90, (float)setting('sale_percent')));
    $until = strtotime(str_replace('T', ' ', setting('sale_until'))) ?: 0;
    if ($pc <= 0 || $until <= time()) return null;
    $cats = array_filter(array_map('intval', preg_split('/[\s,;]+/', setting('sale_cats'))));
    return $s = ['percent' => $pc, 'until' => $until, 'title' => setting('sale_title') ?: 'Акция', 'cats' => array_values($cats)];
}

function sale_percent_for(array $p): float
{
    $s = sale_active();
    if (!$s) return 0;
    if ($s['cats'] && !in_array((int)($p['type_id'] ?? 0), $s['cats'], true)) return 0;
    return $s['percent'];
}

function auto_price(float $ozon): float
{
    if ($ozon <= 0) return 0;
    $d = max(0, min(95, (float)setting('discount_percent')));
    return round_price($ozon * (1 - $d / 100));
}

function round_price(float $v): float
{
    $mode = setting('price_rounding');
    switch ($mode) {
        case '10':  return max(10, round($v / 10) * 10);
        case '50':  return max(50, round($v / 50) * 50);
        case '100': return max(100, round($v / 100) * 100);
        case '9':   $r = floor($v / 10) * 10 + 9; return $r > $v + 5 ? $r - 10 : $r; // окончание на 9
        default:    return round($v);
    }
}

function is_manual(array $p): bool
{
    return $p['manual_price'] !== null && $p['manual_price'] !== '' && (float)$p['manual_price'] > 0;
}

/** Процент скидки относительно цены на Ozon — для бейджа. */
function discount_badge(array $p): int
{
    $o = (float)$p['ozon_price'];
    $s = site_price($p);
    if ($o <= 0 || $s >= $o) return 0;
    return (int)round((1 - $s / $o) * 100);
}

function money(float $v): string
{
    return number_format($v, 0, ',', ' ') . ' ₽';
}

/* ---------- Товары ---------- */

function product_images(array $p): array
{
    $imgs = json_decode((string)$p['images'], true);
    return is_array($imgs) ? array_values(array_filter($imgs)) : [];
}

function product_image(array $p): string
{
    return product_images($p)[0] ?? '';
}

function ozon_link(array $p): string
{
    if (!empty($p['sku'])) return 'https://www.ozon.ru/product/' . rawurlencode((string)$p['sku']) . '/';
    return setting('link_ozon_store');
}

function wb_link(array $p): string
{
    if (!empty($p['wb_url'])) return $p['wb_url'];
    if (!empty($p['wb_nm'])) return 'https://www.wildberries.ru/catalog/' . rawurlencode((string)$p['wb_nm']) . '/detail.aspx';
    return setting('link_wb_store');
}

function ym_link(array $p): string
{
    return !empty($p['ym_url']) ? $p['ym_url'] : setting('link_ym_store');
}

function visible_where(): string
{
    // На сайте только то, что есть на остатках Ozon; категории, снятые с продажи, не показываем
    $w = 'hidden = 0 AND archived = 0 AND ozon_price > 0 AND stock > 0';
    if ($off = off_sale_cats()) $w .= ' AND (type_id IS NULL OR type_id NOT IN (' . implode(',', $off) . '))';
    return $w;
}

/** ID категорий, снятых с продажи (кэш на запрос). */
function off_sale_cats(bool $reset = false): array
{
    static $c = null;
    if ($reset) $c = null;
    if ($c === null) {
        try { $c = array_map('intval', db()->query('SELECT id FROM categories WHERE off_sale = 1')->fetchAll(PDO::FETCH_COLUMN)); }
        catch (Throwable $e) { $c = []; }
    }
    return $c;
}

function find_product(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM products WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/* ---------- Доставка ---------- */

function delivery_methods(): array
{
    $m = [];
    if (setting('pickup_enabled') === '1') {
        $m['pickup'] = ['title' => 'Самовывоз из магазина', 'price' => 0, 'hint' => setting('pickup_address'), 'need_address' => false];
    }
    if (setting('cdek_enabled') === '1') {
        $m['cdek'] = ['title' => 'СДЭК', 'price' => (float)setting('cdek_price'), 'hint' => 'До пункта выдачи СДЭК', 'need_address' => true];
    }
    if (setting('ozon_dlv_enabled') === '1') {
        $m['ozon'] = ['title' => 'Ozon Доставка', 'price' => (float)setting('ozon_dlv_price'), 'hint' => 'До пункта выдачи Ozon', 'need_address' => true];
    }
    if (setting('post_enabled') === '1') {
        $m['post'] = ['title' => 'Почта России', 'price' => (float)setting('post_price'), 'hint' => 'До отделения почты', 'need_address' => true];
    }
    return $m;
}

function delivery_price(string $method, float $itemsTotal): float
{
    $m = delivery_methods()[$method] ?? null;
    if (!$m) return 0;
    $free = (float)setting('free_delivery_from');
    if ($free > 0 && $itemsTotal >= $free) return 0;
    return (float)$m['price'];
}

/* ---------- Прочее ---------- */

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function csrf_check(): void
{
    $t = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
    if (!hash_equals(csrf_token(), (string)$t)) {
        http_response_code(403);
        exit('Сессия устарела. Обновите страницу.');
    }
}

function base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    if (str_ends_with($dir, '/admin')) $dir = substr($dir, 0, -6);
    return ($https ? 'https' : 'http') . '://' . $host . $dir;
}

function json_out($data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function http_json(string $method, string $url, array $headers, $body = null, int $timeout = 30): array
{
    $ch = curl_init($url);
    $h = array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $h,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE));
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) return ['ok' => false, 'code' => 0, 'error' => $err ?: 'нет ответа', 'data' => null];
    $data = json_decode($raw, true);
    return ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'error' => $code >= 300 ? ($data['message'] ?? $data['description'] ?? mb_substr($raw, 0, 300)) : '', 'data' => $data];
}

function order_statuses(): array
{
    return [
        'new'              => 'Новый',
        'awaiting_payment' => 'Ждёт оплаты',
        'paid'             => 'Оплачен',
        'processing'       => 'Собирается',
        'shipped'          => 'Отправлен',
        'ready'            => 'Готов к выдаче',
        'done'             => 'Выполнен',
        'cancelled'        => 'Отменён',
    ];
}

/* ---------- Категории и баннеры (v1.002) ---------- */

/** Видимые категории с числом товаров и картинкой первого товара. */
function categories_visible(): array
{
    $vw = visible_where();
    $sql = "SELECT c.id, COALESCE(NULLIF(c.name, ''), c.ozon_name) AS title, c.sort, COUNT(p.id) AS cnt,
                   (SELECT p2.images FROM products p2 WHERE p2.type_id = c.id AND " . str_replace(['hidden', 'archived', 'ozon_price', 'stock'], ['p2.hidden', 'p2.archived', 'p2.ozon_price', 'p2.stock'], $vw) . " ORDER BY p2.id DESC LIMIT 1) AS images
            FROM categories c JOIN products p ON p.type_id = c.id AND " . str_replace(['hidden', 'archived', 'ozon_price', 'stock'], ['p.hidden', 'p.archived', 'p.ozon_price', 'p.stock'], $vw) . "
            WHERE c.hidden = 0
            GROUP BY c.id HAVING cnt > 0
            ORDER BY c.sort ASC, cnt DESC, title ASC";
    return db()->query($sql)->fetchAll();
}

function category_title(int $id): string
{
    $st = db()->prepare("SELECT COALESCE(NULLIF(name, ''), ozon_name) FROM categories WHERE id = ?");
    $st->execute([$id]);
    return (string)$st->fetchColumn();
}

function plural(int $n, string $one, string $few, string $many): string
{
    $n = abs($n) % 100; $n1 = $n % 10;
    if ($n > 10 && $n < 20) return $many;
    if ($n1 > 1 && $n1 < 5) return $few;
    if ($n1 === 1) return $one;
    return $many;
}

function banners(bool $onlyActive = true): array
{
    $raw = json_decode(setting('banners'), true);
    $list = is_array($raw) && $raw ? $raw : banners_default();
    $out = [];
    foreach ($list as $b) {
        $b = array_merge(['on' => 1, 'kicker' => '', 'title' => '', 'text' => '', 'btn' => '', 'link' => '', 'theme' => 'cyan', 'image' => '', 'cat' => 0, 'q' => '', 'images' => null], (array)$b);
        if (!$b['cat'] && preg_match('/[?&]cat=(\d+)/', (string)$b['link'], $m)) $b['cat'] = (int)$m[1];
        if (!isset(MB_BANNER_THEMES[$b['theme']])) $b['theme'] = 'cyan';
        if ($onlyActive && (!$b['on'] || trim($b['title']) === '')) continue;
        $out[] = $b;
    }
    return $out;
}


/** Можно ли показывать и продавать товар на сайте (не скрыт, не в архиве, есть цена и остаток на Ozon). */
function product_on_sale(?array $p): bool
{
    return $p && !$p['hidden'] && !$p['archived'] && (float)$p['ozon_price'] > 0 && (int)$p['stock'] > 0
        && !in_array((int)($p['type_id'] ?? 0), off_sale_cats(), true);
}

/**
 * Код товара для покупателей — 6 цифр, свой у каждого товара, не меняется.
 * Артикул продавца (Ozon) покупатели не видят; поиск работает по обоим.
 */
function assign_site_codes(PDO $pdo): int
{
    $ids = $pdo->query("SELECT id FROM products WHERE site_code IS NULL OR site_code = ''")->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) return 0;
    $st = $pdo->prepare('UPDATE products SET site_code = ? WHERE id = ?');
    $chk = $pdo->prepare('SELECT 1 FROM products WHERE site_code = ?');
    foreach ($ids as $id) {
        do { $code = (string)random_int(100000, 999999); $chk->execute([$code]); } while ($chk->fetchColumn());
        $st->execute([$code, $id]);
    }
    return count($ids);
}

/** Убирает из описания Ozon предложения про 3D-печать и принтеры. */
function clean_description(string $html): string
{
    $txt = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</li>'], "\n", $html));
    $out = [];
    foreach (preg_split('/\n/u', $txt) as $line) {
        $parts = preg_split('/(?<=[.!?…])\s+/u', $line);
        $keep = array_filter($parts, fn($s) => !preg_match('/3\s*[-‑]?\s*[dDдД](?![а-яёa-z])|принтер|(?<![а-яё])(на)?печат/iu', $s));
        $out[] = implode(' ', $keep);
    }
    return trim(preg_replace("/\n{3,}/u", "\n\n", implode("\n", $out)));
}

require_once __DIR__ . '/shop.php';
require_once __DIR__ . '/extras.php';
require_once __DIR__ . '/variants.php';
require_once __DIR__ . '/autoupdate.php';
