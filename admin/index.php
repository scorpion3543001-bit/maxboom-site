<?php
/** Админка MaxBoom Shop. Один файл, разделы через ?p=... */
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';
require dirname(__DIR__) . '/inc/ozon.php';
require dirname(__DIR__) . '/inc/payment.php';

$p = (string)($_GET['p'] ?? 'home');

/* ---------- Вход / первая настройка ---------- */
$hasPass = setting('admin_password') !== '';
if (empty($_SESSION['admin'])) {
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $pass = (string)($_POST['password'] ?? '');
        if (!$hasPass) {
            if (mb_strlen($pass) < 8) $err = 'Пароль — минимум 8 символов.';
            elseif ($pass !== (string)($_POST['password2'] ?? '')) $err = 'Пароли не совпадают.';
            else {
                setting_set('admin_password', password_hash($pass, PASSWORD_DEFAULT));
                setting_set('cron_key', bin2hex(random_bytes(12)));
                session_regenerate_id(true);
                $_SESSION['admin'] = true;
                header('Location: ?p=settings&first=1'); exit;
            }
        } else {
            usleep(400000);
            if (password_verify($pass, setting('admin_password'))) {
                session_regenerate_id(true);
                $_SESSION['admin'] = true;
                header('Location: ./'); exit;
            }
            $err = 'Неверный пароль.';
        }
    }
    admin_head('Вход');
    ?>
    <div class="login">
      <div class="login-box">
        <div class="logo"><span class="logo-mark">M</span><span class="logo-text">MAX<b>BOOM</b></span></div>
        <h1><?= $hasPass ? 'Вход в админку' : 'Первый запуск' ?></h1>
        <?php if (!$hasPass): ?><p class="muted">Придумайте пароль администратора.</p><?php endif; ?>
        <?php if ($err): ?><div class="alert"><?= e($err) ?></div><?php endif; ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <label>Пароль<input type="password" name="password" required autofocus autocomplete="<?= $hasPass ? 'current-password' : 'new-password' ?>"></label>
          <?php if (!$hasPass): ?><label>Ещё раз<input type="password" name="password2" required autocomplete="new-password"></label><?php endif; ?>
          <button class="btn btn-accent btn-block btn-lg"><?= $hasPass ? 'Войти' : 'Сохранить и войти' ?></button>
        </form>
      </div>
    </div>
    <?php
    admin_foot();
    exit;
}

/* ---------- AJAX ---------- */
if ($p === 'api') {
    csrf_check();
    $do = (string)($_GET['do'] ?? '');
    $pdo = db();
    if (in_array($do, ['product', 'discount', 'bulk', 'category', 'review'], true)) page_cache_clear();
    switch ($do) {
        case 'product': // сохранить поля одного товара
            $id = (int)($_POST['id'] ?? 0);
            $prod = find_product($id) ?? json_out(['error' => 'нет товара'], 404);
            $field = (string)($_POST['field'] ?? '');
            $val = trim((string)($_POST['value'] ?? ''));
            switch ($field) {
                case 'manual_price':
                    $v = $val === '' ? null : (float)str_replace([' ', ','], ['', '.'], $val);
                    if ($v !== null && $v <= 0) $v = null;
                    $pdo->prepare('UPDATE products SET manual_price = ? WHERE id = ?')->execute([$v, $id]);
                    break;
                case 'hidden':
                    $pdo->prepare('UPDATE products SET hidden = ? WHERE id = ?')->execute([$val === '1' ? 1 : 0, $id]);
                    break;
                case 'wb':
                    if ($val !== '' && ctype_digit($val)) $pdo->prepare("UPDATE products SET wb_nm = ?, wb_url = '' WHERE id = ?")->execute([$val, $id]);
                    elseif ($val === '' || preg_match('~^https?://~i', $val)) $pdo->prepare('UPDATE products SET wb_url = ?, wb_nm = CASE WHEN ? = \'\' THEN NULL ELSE wb_nm END WHERE id = ?')->execute([$val, $val, $id]);
                    else json_out(['error' => 'Укажите артикул WB (цифры) или ссылку'], 400);
                    break;
                case 'ym':
                    if ($val !== '' && !preg_match('~^https?://~i', $val)) json_out(['error' => 'Нужна ссылка https://…'], 400);
                    $pdo->prepare('UPDATE products SET ym_url = ? WHERE id = ?')->execute([$val, $id]);
                    break;
                case 'color':
                    if ($val === '') {
                        $pdo->prepare('UPDATE products SET color = NULL, color_hex = NULL, color_src = NULL WHERE id = ?')->execute([$id]);
                    } else {
                        [$nice, $hex] = color_from_label(mb_substr($val, 0, 40));
                        $pdo->prepare("UPDATE products SET color = ?, color_hex = ?, color_src = 'manual' WHERE id = ?")->execute([$nice, $hex ?: null, $id]);
                    }
                    variants_rebuild();
                    page_cache_clear();
                    break;
                default: json_out(['error' => 'поле?'], 400);
            }
            $prod = find_product($id);
            json_out(['ok' => true, 'price' => money(site_price($prod)), 'manual' => is_manual($prod)]);

        case 'discount':
            $d = (float)str_replace(',', '.', (string)($_POST['value'] ?? ''));
            if ($d < 0 || $d > 95) json_out(['error' => 'от 0 до 95%'], 400);
            setting_set('discount_percent', (string)$d);
            json_out(['ok' => true]);

        case 'bulk':
            $ids = array_map('intval', (array)($_POST['ids'] ?? []));
            $act = (string)($_POST['act'] ?? '');
            if ($act === 'reset_all') { $n = $pdo->exec('UPDATE products SET manual_price = NULL WHERE manual_price IS NOT NULL'); json_out(['ok' => true, 'n' => $n]); }
            if (!$ids) json_out(['error' => 'ничего не выбрано'], 400);
            $in = implode(',', $ids);
            if ($act === 'hide') $pdo->exec("UPDATE products SET hidden = 1 WHERE id IN ($in)");
            elseif ($act === 'show') $pdo->exec("UPDATE products SET hidden = 0 WHERE id IN ($in)");
            elseif ($act === 'reset') $pdo->exec("UPDATE products SET manual_price = NULL WHERE id IN ($in)");
            elseif ($act === 'percent') {
                // Ручная цена = цена Ozon минус указанный процент (для выбранных товаров)
                $pc = (float)str_replace(',', '.', (string)($_POST['percent'] ?? ''));
                if ($pc < 0 || $pc > 95) json_out(['error' => 'от 0 до 95%'], 400);
                $st = $pdo->prepare('UPDATE products SET manual_price = ? WHERE id = ?');
                foreach ($pdo->query("SELECT id, ozon_price FROM products WHERE id IN ($in)") as $r) {
                    $st->execute([round_price((float)$r['ozon_price'] * (1 - $pc / 100)), $r['id']]);
                }
            } else json_out(['error' => 'действие?'], 400);
            json_out(['ok' => true]);

        case 'sync':
            $lock = fopen(MB_DATA . '/sync.lock', 'c');
            if (!flock($lock, LOCK_EX | LOCK_NB)) json_out(['error' => 'Обновление уже идёт, подождите.'], 409);
            try { $msg = ozon_sync(); json_out(['ok' => true, 'message' => $msg]); }
            catch (Throwable $e) { sync_log(false, $e->getMessage()); json_out(['error' => $e->getMessage()], 500); }

        case 'price_sample':
            try { json_out(['ok' => true, 'items' => ozon_price_sample()]); }
            catch (Throwable $e) { json_out(['error' => $e->getMessage()], 500); }

        case 'order_status':
            $id = (int)($_POST['id'] ?? 0);
            $s = (string)($_POST['status'] ?? '');
            if (!isset(order_statuses()[$s])) json_out(['error' => 'статус?'], 400);
            $st = $pdo->prepare('SELECT * FROM orders WHERE id = ?'); $st->execute([$id]);
            $before = $st->fetch() ?: json_out(['error' => 'нет заказа'], 404);
            $pdo->prepare('UPDATE orders SET status = ?, admin_note = COALESCE(?, admin_note) WHERE id = ?')->execute([$s, $_POST['note'] ?? null, $id]);
            $st->execute([$id]); $o = $st->fetch();
            order_status_effects($o, $s);
            $sent = false;
            if ($before['status'] !== $s && !empty($_POST['notify'])) { try { notify_customer($o, 'status'); $sent = true; } catch (Throwable) {} }
            json_out(['ok' => true, 'notified' => $sent]);

        case 'bundle':
            $id = (int)($_POST['id'] ?? 0);
            $act = (string)($_POST['act'] ?? '');
            if ($act === 'toggle') $pdo->prepare('UPDATE bundles SET active = 1 - active WHERE id = ?')->execute([$id]);
            elseif ($act === 'delete') $pdo->prepare('DELETE FROM bundles WHERE id = ?')->execute([$id]);
            elseif ($act === 'percent') $pdo->prepare('UPDATE bundles SET percent = ? WHERE id = ?')->execute([max(0, min(90, (float)str_replace(',', '.', (string)($_POST['value'] ?? '0')))), $id]);
            page_cache_clear();
            json_out(['ok' => true]);

        case 'gift':
            $id = (int)($_POST['id'] ?? 0);
            $g = $pdo->prepare('SELECT * FROM gift_cards WHERE id = ?'); $g->execute([$id]); $g = $g->fetch() ?: json_out(['error' => 'нет'], 404);
            $new = $g['status'] === 'cancelled' ? ((float)$g['balance'] > 0 ? 'active' : 'used') : 'cancelled';
            $pdo->prepare('UPDATE gift_cards SET status = ? WHERE id = ?')->execute([$new, $id]);
            json_out(['ok' => true, 'status' => $new]);

        case 'bonus_adjust':
            $uid = (int)($_POST['id'] ?? 0);
            $amt = (float)str_replace(',', '.', (string)($_POST['amount'] ?? '0'));
            if (abs($amt) < 1) json_out(['error' => 'Укажите количество баллов, например 200 или -100'], 400);
            bonus_add($uid, $amt, mb_substr(trim((string)($_POST['reason'] ?? '')) ?: ($amt > 0 ? 'Начисление от магазина' : 'Списание магазином'), 0, 120));
            json_out(['ok' => true, 'balance' => bonus_balance($uid)]);

        case 'reviews_import':
            try { json_out(['ok' => true, 'message' => ozon_import_reviews(50)]); }
            catch (Throwable $e) { json_out(['error' => str_contains($e->getMessage(), '403') || str_contains(mb_strtolower($e->getMessage()), 'premium') ? 'Ozon не отдаёт отзывы: метод доступен с подпиской Premium Plus. ' . $e->getMessage() : $e->getMessage()], 500); }

        case 'tg_webhook':
            if (!str_starts_with(base_url(), 'https://')) json_out(['error' => 'Нужен https (SSL-сертификат). Пока его нет, бот работает через автообновление (cron) и кнопку в кабинете покупателя.'], 400);
            if (setting('tg_secret') === '') setting_set('tg_secret', bin2hex(random_bytes(16)));
            $r = tg_api('setWebhook', ['url' => base_url() . '/tg_webhook.php', 'secret_token' => setting('tg_secret'), 'allowed_updates' => ['message']]);
            setting_set('tg_bot_username', '');
            tg_bot_username();
            json_out($r['ok'] ? ['ok' => true] : ['error' => 'Telegram: ' . $r['error']], $r['ok'] ? 200 : 500);

        case 'cache_clear':
            page_cache_clear();
            $pdo->exec('DELETE FROM kv_cache');
            json_out(['ok' => true]);

        case 'category':
            $id = (int)($_POST['id'] ?? 0);
            $field = (string)($_POST['field'] ?? '');
            $val = trim((string)($_POST['value'] ?? ''));
            if ($field === 'name') $pdo->prepare('UPDATE categories SET name = ? WHERE id = ?')->execute([mb_substr($val, 0, 60), $id]);
            elseif ($field === 'hidden') $pdo->prepare('UPDATE categories SET hidden = ? WHERE id = ?')->execute([$val === '1' ? 1 : 0, $id]);
            elseif ($field === 'sort') $pdo->prepare('UPDATE categories SET sort = ? WHERE id = ?')->execute([(int)$val, $id]);
            elseif ($field === 'off_sale') $pdo->prepare('UPDATE categories SET off_sale = ? WHERE id = ?')->execute([$val === '1' ? 1 : 0, $id]);
            else json_out(['error' => 'поле?'], 400);
            page_cache_clear();
            $pdo->exec("DELETE FROM kv_cache WHERE key LIKE 'collections_%'");
            json_out(['ok' => true]);

        case 'user':
            $id = (int)($_POST['id'] ?? 0);
            $field = (string)($_POST['field'] ?? '');
            $val = trim((string)($_POST['value'] ?? ''));
            if ($field === 'discount') {
                $d = (float)str_replace(',', '.', $val);
                if ($d < 0 || $d > 90) json_out(['error' => 'от 0 до 90%'], 400);
                $pdo->prepare('UPDATE users SET discount_percent = ? WHERE id = ?')->execute([$d, $id]);
            } elseif ($field === 'note') {
                $pdo->prepare('UPDATE users SET note = ? WHERE id = ?')->execute([mb_substr($val, 0, 300), $id]);
            } else json_out(['error' => 'поле?'], 400);
            json_out(['ok' => true]);

        case 'coupon_toggle':
            $pdo->prepare('UPDATE coupons SET active = 1 - active WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
            json_out(['ok' => true]);

        case 'review':
            $id = (int)($_POST['id'] ?? 0);
            $st = (string)($_POST['status'] ?? '');
            if ($st === 'delete') { $pdo->prepare('DELETE FROM reviews WHERE id = ?')->execute([$id]); json_out(['ok' => true]); }
            if (in_array($st, ['approved', 'rejected', 'pending'], true)) $pdo->prepare('UPDATE reviews SET status = ? WHERE id = ?')->execute([$st, $id]);
            if (isset($_POST['reply'])) $pdo->prepare('UPDATE reviews SET reply = ? WHERE id = ?')->execute([mb_substr(trim((string)$_POST['reply']), 0, 1000), $id]);
            json_out(['ok' => true]);

        case 'tg_test':
            notify_order(['number' => 'ТЕСТ', 'items' => '[]', 'items_total' => 0, 'delivery_price' => 0, 'total' => 0, 'delivery_method' => 'pickup',
                'payment_method' => 'onsite', 'customer_name' => 'Проверка уведомлений', 'phone' => '-', 'email' => '', 'delivery_address' => '', 'comment' => ''], '🔔 Проверка');
            json_out(['ok' => true]);
    }
    json_out(['error' => 'unknown'], 400);
}

if ($p === 'logout') { $_SESSION = []; session_destroy(); header('Location: ./'); exit; }

/* ---------- Сохранение баннеров ---------- */
$bannersSaved = false;
if ($p === 'banners' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!empty($_POST['reset'])) {
        setting_set('banners', '');
    } else {
        $list = [];
        foreach ((array)($_POST['b'] ?? []) as $b) {
            if (!empty($b['delete'])) continue;
            $link = trim((string)($b['link'] ?? ''));
            $bq = mb_substr(trim((string)($b['q'] ?? '')), 0, 60);
            if ($link === '' && $bq !== '') $link = './?' . http_build_query(['q' => $bq, 't' => trim((string)($b['title'] ?? ''))]) . '#catalog';
            if ($link !== '' && !preg_match('~^(https?://|#|/|\./|\?|[pc]/|[a-z0-9_.-]+\.php)~i', $link)) $link = '#catalog';
            $img = trim((string)($b['image'] ?? ''));
            if ($img !== '' && !preg_match('~^https?://~i', $img)) $img = '';
            $list[] = [
                'on' => !empty($b['on']) ? 1 : 0,
                'kicker' => mb_substr(trim((string)($b['kicker'] ?? '')), 0, 60),
                'title' => mb_substr(trim((string)($b['title'] ?? '')), 0, 90),
                'text' => mb_substr(trim((string)($b['text'] ?? '')), 0, 220),
                'btn' => mb_substr(trim((string)($b['btn'] ?? '')), 0, 30),
                'link' => $link,
                'theme' => isset(MB_BANNER_THEMES[$b['theme'] ?? '']) ? $b['theme'] : 'cyan',
                'image' => $img,
                'q' => $bq,
                'count' => $bq !== '' ? (int)(catalog_query($bq, 'new', 1, 1, 0, false)[1] ?? 0) : 0,
            ];
        }
        if (!empty($_POST['add'])) $list[] = ['on' => 1, 'kicker' => '', 'title' => 'Новый баннер', 'text' => '', 'btn' => 'Смотреть подборку', 'link' => '', 'theme' => array_keys(MB_BANNER_THEMES)[count($list) % 7], 'image' => '', 'q' => ''];
        setting_set('banners', json_encode($list, JSON_UNESCAPED_UNICODE));
    }
    page_cache_clear();
    $bannersSaved = true;
}

/* ---------- Создание промокода ---------- */
$couponMsg = '';
if ($p === 'coupons' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $code = mb_strtoupper(preg_replace('/[^A-Za-zА-Яа-яЁё0-9_-]/u', '', (string)($_POST['code'] ?? '')));
    if ($code === '') $code = 'MB' . strtoupper(bin2hex(random_bytes(3)));
    $kind = ($_POST['kind'] ?? '') === 'fixed' ? 'fixed' : 'percent';
    $val = (float)str_replace(',', '.', (string)($_POST['value'] ?? '0'));
    $uid = null;
    if (($ue = trim((string)($_POST['user_email'] ?? ''))) !== '') {
        $st = db()->prepare('SELECT id FROM users WHERE email = ?'); $st->execute([mb_strtolower($ue)]); $uid = $st->fetchColumn() ?: null;
        if (!$uid) $couponMsg = 'Покупатель с e-mail ' . $ue . ' не найден.';
    }
    if ($couponMsg === '') {
        if ($val <= 0 || ($kind === 'percent' && $val > 90)) $couponMsg = 'Проверьте размер скидки (для процентов — до 90).';
        else {
            try {
                db()->prepare('INSERT INTO coupons (code, kind, value, min_sum, max_uses, once_per_user, user_id, expires_at, active, note, created_at) VALUES (?,?,?,?,?,?,?,?,1,?,?)')
                    ->execute([$code, $kind, $val, (float)($_POST['min_sum'] ?? 0), (int)($_POST['max_uses'] ?? 0), !empty($_POST['once']) ? 1 : 0, $uid,
                               ($_POST['expires_at'] ?? '') ?: null, mb_substr((string)($_POST['note'] ?? ''), 0, 200), now()]);
                $couponMsg = 'ok:Промокод ' . $code . ' создан.';
            } catch (Throwable) { $couponMsg = 'Промокод ' . $code . ' уже существует.'; }
        }
    }
}

/* ---------- Наборы, сертификаты, рассылка, обновление ---------- */
$pageMsg = '';
if ($p === 'bundles' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $codes = array_values(array_unique(array_filter(preg_split('/[\s,;]+/', (string)($_POST['codes'] ?? '')))));
    $ids = [];
    foreach ($codes as $c) {
        $st = db()->prepare('SELECT id FROM products WHERE site_code = ? OR lower(offer_id) = lower(?) LIMIT 1');
        $st->execute([$c, $c]);
        if ($id = $st->fetchColumn()) $ids[] = (int)$id; else $pageMsg = 'Не найден товар с кодом ' . $c . '.';
    }
    $ids = array_values(array_unique($ids));
    if ($pageMsg === '' && count($ids) < 2) $pageMsg = 'В наборе должно быть минимум 2 товара.';
    if ($pageMsg === '') {
        db()->prepare('INSERT INTO bundles (title, product_ids, percent, active, created_at) VALUES (?, ?, ?, 1, ?)')
            ->execute([mb_substr(trim((string)($_POST['title'] ?? '')) ?: 'Набор', 0, 80), json_encode($ids), max(1, min(90, (float)str_replace(',', '.', (string)($_POST['percent'] ?? '10')))), now()]);
        page_cache_clear();
        $pageMsg = 'ok:Набор создан.';
    }
}
if ($p === 'gifts' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $amt = (float)str_replace(',', '.', (string)($_POST['amount'] ?? '0'));
    if ($amt < 100) $pageMsg = 'Номинал — от 100 ₽.';
    else {
        $code = gift_new_code();
        $exp = date('Y-m-d', time() + max(30, (int)setting('gift_days')) * 86400);
        db()->prepare("INSERT INTO gift_cards (code, amount, balance, recipient_name, recipient_email, message, status, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, 'active', ?, ?)")
            ->execute([$code, $amt, $amt, mb_substr(trim((string)($_POST['name'] ?? '')), 0, 80), trim((string)($_POST['email'] ?? '')) ?: null, mb_substr(trim((string)($_POST['note'] ?? '')), 0, 200), now(), $exp]);
        if (!empty($_POST['send']) && filter_var((string)($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
            send_mail((string)$_POST['email'], 'Вам подарочный сертификат ' . setting('shop_name'), "Сертификат на " . money($amt) . "\nКод: {$code}\nДействует до " . date('d.m.Y', strtotime($exp)) . "\n\nВведите код в корзине: " . base_url() . '/');
        }
        $pageMsg = 'ok:Сертификат ' . $code . ' на ' . money($amt) . ' создан.';
    }
}
if ($p === 'mailing' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $text = trim((string)($_POST['text'] ?? ''));
    if (mb_strlen($text) < 5) $pageMsg = 'Напишите текст сообщения.';
    elseif (empty($_POST['confirm'])) $pageMsg = 'Поставьте галочку подтверждения — сообщение уйдёт всем подписчикам.';
    else {
        @set_time_limit(300);
        $chats = db()->query("SELECT DISTINCT tg_chat_id FROM users WHERE tg_chat_id IS NOT NULL AND tg_chat_id <> ''")->fetchAll(PDO::FETCH_COLUMN);
        $ok = 0;
        foreach ($chats as $c) { if (tg_send((string)$c, $text)) $ok++; usleep(60000); }
        $pageMsg = 'ok:Отправлено: ' . $ok . ' из ' . count($chats) . '.';
    }
}
if ($p === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (isset($_POST['upd_save'])) {
        setting_set('update_repo', trim((string)($_POST['update_repo'] ?? '')));
        setting_set('update_branch', trim((string)($_POST['update_branch'] ?? '')) ?: 'main');
        setting_set('update_auto', isset($_POST['update_auto']) ? '1' : '0');
        if (trim((string)($_POST['update_token'] ?? '')) !== '') setting_set('update_token', trim((string)$_POST['update_token']));
        if (!empty($_POST['clear_update_token'])) setting_set('update_token', '');
        $pageMsg = 'ok:Настройки автообновления сохранены.';
    } elseif (isset($_POST['upd_now'])) {
        $r = site_autoupdate(true);
        $pageMsg = $r === '' ? 'ok:Обновлений нет.' : (str_starts_with($r, 'Сайт обновлён') ? 'ok:' . $r : $r);
    } else {
        $pageMsg = site_self_update($_FILES['zip'] ?? null);
    }
    if (str_starts_with($pageMsg, 'ok:')) { page_cache_clear(); db()->exec('DELETE FROM kv_cache'); }
    if (!empty($_GET['json'])) json_out(['message' => $pageMsg]);
}

/** Обновление сайта из zip-архива (папка data/ — база, настройки, кэш — не трогается). */
function site_self_update(?array $f): string
{
    if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) return 'Файл не загрузился.';
    return site_apply_zip($f['tmp_name'], false);
}

/* ---------- Сохранение настроек ---------- */
$saved = false;
if ($p === 'settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $checkboxes = ['pickup_enabled', 'pickup_pay_on_site', 'cdek_enabled', 'ozon_dlv_enabled', 'post_enabled', 'yk_receipt', 'reviews_enabled', 'reviews_premod',
                   'bonus_enabled', 'ref_enabled', 'gift_enabled', 'cdek_api', 'cdek_test', 'post_api', 'tg_customers', 'notify_customer_email', 'ozon_reviews_import',
                   'sale_on', 'pretty_urls', 'img_resize', 'page_cache'];
    $secret = ['ozon_api_key', 'yk_secret', 'wb_token', 'tg_bot_token', 'cdek_client_secret'];
    $internal = ['tg_bot_username', 'tg_offset', 'tg_secret', 'ozon_reviews_last', 'sale_cats', 'update_sha', 'update_last', 'update_last_check', 'update_repo', 'update_branch', 'update_token', 'update_auto'];
    foreach (MB_DEFAULTS as $k => $def) {
        if (in_array($k, ['admin_password', 'cron_key'], true) || in_array($k, $internal, true)) continue;
        if (in_array($k, $checkboxes, true)) { setting_set($k, isset($_POST[$k]) ? '1' : '0'); continue; }
        if (!array_key_exists($k, $_POST)) continue;
        $v = trim((string)$_POST[$k]);
        if (in_array($k, $secret, true) && $v === '') continue; // пустое поле = не менять ключ
        setting_set($k, $v);
    }
    foreach ($secret as $k) if (!empty($_POST['clear_' . $k])) setting_set($k, '');
    if (($np = (string)($_POST['new_password'] ?? '')) !== '') {
        if (mb_strlen($np) >= 8) setting_set('admin_password', password_hash($np, PASSWORD_DEFAULT));
    }
    if (!empty($_POST['regen_cron'])) setting_set('cron_key', bin2hex(random_bytes(12)));
    if (isset($_POST['tg_bot_token']) && trim((string)$_POST['tg_bot_token']) !== '') setting_set('tg_bot_username', '');
    if (isset($_POST['sale_cats']) && is_array($_POST['sale_cats'])) setting_set('sale_cats', implode(',', array_map('intval', $_POST['sale_cats'])));
    elseif (isset($_POST['sale_title'])) setting_set('sale_cats', '');
    page_cache_clear();
    db()->exec("DELETE FROM kv_cache WHERE key LIKE 'cdek_from_%' OR key LIKE 'cdek_token_%'");
    $saved = true;
}

/* ---------- Страницы ---------- */
admin_head(['home' => 'Обзор', 'products' => 'Товары и цены', 'categories' => 'Категории', 'banners' => 'Баннеры на главной', 'orders' => 'Заказы', 'order' => 'Заказ', 'users' => 'Покупатели', 'user' => 'Покупатель', 'coupons' => 'Промокоды', 'reviews' => 'Отзывы',
    'bundles' => 'Наборы со скидкой', 'gifts' => 'Подарочные сертификаты', 'mailing' => 'Рассылка в Telegram', 'update' => 'Обновление сайта', 'settings' => 'Настройки'][$p] ?? 'Админка', $p);

switch ($p) {
    case 'products': page_products(); break;
    case 'orders': page_orders(); break;
    case 'categories': page_categories(); break;
    case 'users': page_users(); break;
    case 'user': page_user(); break;
    case 'coupons': page_coupons($couponMsg); break;
    case 'reviews': page_reviews(); break;
    case 'banners': page_banners($bannersSaved); break;
    case 'order': page_order(); break;
    case 'settings': page_settings($saved); break;
    case 'bundles': page_bundles($pageMsg); break;
    case 'gifts': page_gifts($pageMsg); break;
    case 'mailing': page_mailing($pageMsg); break;
    case 'update': page_update($pageMsg); break;
    default: page_home();
}
admin_foot();

/* ================================================================== */

function page_home(): void
{
    $pdo = db();
    $cnt = $pdo->query('SELECT COUNT(*) total, SUM(hidden=0 AND archived=0 AND ozon_price>0 AND stock>0) vis, SUM(manual_price>0) man FROM products')->fetch();
    $ord = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(total),0) s FROM orders WHERE created_at >= date('now','-30 day') AND status <> 'cancelled' AND (payment_method <> 'online' OR paid_at IS NOT NULL)")->fetch();
    $newOrders = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('new','paid')")->fetchColumn();
    $log = $pdo->query('SELECT * FROM sync_log ORDER BY id DESC LIMIT 8')->fetchAll();
    $d = setting('discount_percent');
    $sample = $pdo->query('SELECT * FROM products WHERE hidden=0 AND archived=0 AND ozon_price>0 AND (manual_price IS NULL OR manual_price<=0) ORDER BY RANDOM() LIMIT 1')->fetch();
    ?>
    <div class="kpis">
      <div class="kpi"><span>Товаров на сайте</span><b><?= (int)$cnt['vis'] ?></b><small>всего из Ozon <?= (int)$cnt['total'] ?></small></div>
      <div class="kpi"><span>С ручной ценой</span><b><?= (int)$cnt['man'] ?></b><small>не меняются при обновлении</small></div>
      <div class="kpi"><span>Заказы за 30 дней</span><b><?= (int)$ord['n'] ?></b><small><?= money((float)$ord['s']) ?></small></div>
      <a class="kpi kpi-link" href="?p=orders"><span>Требуют внимания</span><b><?= $newOrders ?></b><small>новые и оплаченные →</small></a>
    </div>

    <div class="panels">
      <section class="panel">
        <h2>Скидка на весь каталог</h2>
        <p class="muted">Цена на сайте = цена на Ozon − скидка. Товары с ручной ценой не затрагиваются.</p>
        <div class="disc">
          <input type="range" min="0" max="80" step="1" value="<?= e($d) ?>" id="discRange">
          <div class="disc-val"><input type="number" min="0" max="95" step="0.5" value="<?= e($d) ?>" id="discInput"><span>%</span></div>
          <button class="btn btn-accent" id="discSave" type="button">Применить</button>
        </div>
        <?php if ($sample): ?>
        <div class="disc-preview" data-ozon="<?= (float)$sample['ozon_price'] ?>">
          <span class="muted">Пример: «<?= e(mb_strimwidth($sample['name'], 0, 50, '…')) ?>»</span>
          <span>Ozon <b><?= money((float)$sample['ozon_price']) ?></b> → сайт <b class="accent" id="discPreview"><?= money(site_price($sample)) ?></b></span>
        </div>
        <?php endif; ?>
      </section>

      <section class="panel">
        <h2>Обновление из Ozon</h2>
        <p class="muted">Загружает новые товары, фото, названия и текущие цены Ozon. Ручные цены, скрытые товары и ссылки WB/ЯМ остаются как есть.</p>
        <button class="btn btn-accent" id="syncBtn" type="button">⟳ Обновить сейчас</button>
        <div class="sync-result" id="syncResult"></div>
        <ul class="log">
          <?php foreach ($log as $l): ?><li class="<?= $l['ok'] ? 'ok' : 'bad' ?>"><time><?= e(date('d.m H:i', strtotime($l['at']))) ?></time> <?= e($l['message']) ?></li><?php endforeach; ?>
          <?php if (!$log): ?><li class="muted">Обновлений ещё не было.</li><?php endif; ?>
        </ul>
      </section>
    </div>
    <?php
}

function page_products(): void
{
    $pdo = db();
    $pdo->sqliteCreateFunction('mb_lower', fn($s) => mb_strtolower((string)$s), 1);
    $q = trim((string)($_GET['q'] ?? ''));
    $f = (string)($_GET['f'] ?? 'all');
    $where = ['1=1'];
    $params = [];
    if ($q !== '') { $where[] = '(mb_lower(name) LIKE :q OR mb_lower(offer_id) LIKE :q OR sku LIKE :q OR site_code LIKE :q)'; $params[':q'] = '%' . mb_strtolower($q) . '%'; }
    $where[] = match ($f) {
        'manual' => 'manual_price > 0',
        'hidden' => 'hidden = 1',
        'archived' => 'archived = 1',
        'nowb' => "(wb_nm IS NULL OR wb_nm = '') AND (wb_url IS NULL OR wb_url = '')",
        'noym' => "(ym_url IS NULL OR ym_url = '')",
        'nostock' => '(stock IS NULL OR stock <= 0) AND archived = 0',
        default => 'archived = 0',
    };
    $w = implode(' AND ', $where);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = 100;
    $st = $pdo->prepare("SELECT COUNT(*) FROM products WHERE $w"); $st->execute($params); $total = (int)$st->fetchColumn();
    $st = $pdo->prepare("SELECT * FROM products WHERE $w ORDER BY offer_id LIMIT $per OFFSET " . (($page - 1) * $per)); $st->execute($params);
    $rows = $st->fetchAll();
    $d = setting('discount_percent');
    ?>
    <div class="toolbar">
      <form class="tsearch" method="get"><input type="hidden" name="p" value="products"><input type="hidden" name="f" value="<?= e($f) ?>">
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Артикул, код сайта или название"><button class="btn">Найти</button></form>
      <div class="chips">
        <?php foreach (['all' => 'Все', 'manual' => 'Ручная цена', 'hidden' => 'Скрытые', 'nowb' => 'Без WB', 'noym' => 'Без ЯМ', 'nostock' => 'Нет на остатках', 'archived' => 'Архив Ozon'] as $k => $t): ?>
          <a class="chip<?= $f === $k ? ' is-active' : '' ?>" href="?p=products&f=<?= $k ?>&q=<?= e(rawurlencode($q)) ?>"><?= $t ?></a>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="bulk" id="bulk">
      <span class="muted">Общая скидка: <b><?= e($d) ?>%</b> (<a href="./">изменить</a>)</span>
      <span class="sep"></span>
      <span id="bulkSel" class="muted">Выбрано: 0</span>
      <input type="number" id="bulkPercent" min="0" max="95" placeholder="% скидки" class="sm">
      <button class="btn btn-sm" data-bulk="percent" type="button" title="Задать выбранным ручную цену = Ozon − %">Своя скидка</button>
      <button class="btn btn-sm" data-bulk="reset" type="button">Вернуть авто-цену</button>
      <button class="btn btn-sm" data-bulk="hide" type="button">Скрыть</button>
      <button class="btn btn-sm" data-bulk="show" type="button">Показать</button>
      <span class="sep"></span>
      <button class="btn btn-sm btn-danger" data-bulk="reset_all" type="button">Сбросить ВСЕ ручные цены</button>
    </div>

    <div class="table-wrap">
    <table class="tbl" id="prodTable">
      <thead><tr>
        <th><input type="checkbox" id="checkAll"></th><th></th><th>Артикул / товар</th><th class="r">Ozon</th>
        <th class="r">Авто −<?= e($d) ?>%</th><th>Своя цена</th><th class="r">На сайте</th><th>WB (артикул или ссылка)</th><th>Яндекс Маркет (ссылка)</th><th>На сайте</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $img = product_image($r); ?>
        <tr data-id="<?= (int)$r['id'] ?>" class="<?= $r['hidden'] ? 'is-hidden' : '' ?>">
          <td><input type="checkbox" class="rowcheck" value="<?= (int)$r['id'] ?>"></td>
          <td><?php if ($img): ?><img class="thumb" src="<?= e($img) ?>" alt="" loading="lazy"><?php endif; ?></td>
          <td class="name"><b><?= e($r['offer_id']) ?></b><span class="tiny">код на сайте: <?= e((string)$r['site_code']) ?></span><span><?= e($r['name']) ?></span>
            <?php if ($r['sku']): ?><a class="tiny" href="<?= e(ozon_link($r)) ?>" target="_blank" rel="noopener">Ozon ↗</a><?php endif; ?>
            <?php if (product_on_sale($r)): ?><a class="tiny" href="../product.php?id=<?= (int)$r['id'] ?>" target="_blank">Сайт ↗</a><?php endif; ?>
            <?php if ((int)$r['stock'] <= 0): ?><span class="tag warn">нет на остатках Ozon</span><?php else: ?><span class="tag">остаток <?= (int)$r['stock'] ?></span><?php endif; ?>
            <label class="color-cell tiny"><?php if ($sb = swatch_bg($r)): ?><i style="background:<?= e($sb) ?>"></i><?php endif; ?>Цвет <input class="cell" data-field="color" value="<?= e((string)$r['color']) ?>" placeholder="не указан" maxlength="40"><?php if (!empty($r['group_key'])): ?><span class="tag">в группе цветов</span><?php endif; ?></label></td>
          <td class="r nowrap"><?= money((float)$r['ozon_price']) ?></td>
          <td class="r nowrap muted"><?= money(auto_price((float)$r['ozon_price'])) ?></td>
          <td><input class="cell price-in<?= is_manual($r) ? ' is-manual' : '' ?>" data-field="manual_price" value="<?= is_manual($r) ? e((string)(float)$r['manual_price']) : '' ?>" placeholder="авто" inputmode="decimal"></td>
          <td class="r nowrap"><b class="site-price"><?= money(site_price($r)) ?></b></td>
          <td><input class="cell" data-field="wb" value="<?= e($r['wb_url'] ?: (string)$r['wb_nm']) ?>" placeholder="—"></td>
          <td><input class="cell" data-field="ym" value="<?= e((string)$r['ym_url']) ?>" placeholder="—"></td>
          <td><label class="switch"><input type="checkbox" data-field="hidden" <?= $r['hidden'] ? '' : 'checked' ?>><span></span></label></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="10" class="empty">Нет товаров. <?= $total === 0 && $q === '' ? 'Нажмите «Обновить из Ozon» на главной админки.' : '' ?></td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>
    <p class="muted tiny">Ручная цена сохраняется при выходе из поля (Enter или клик в сторону). Очистите поле — вернётся авто-цена. Найдено: <?= $total ?>.</p>
    <?php if ($total > $per): ?>
      <div class="pager">
        <?php for ($i = 1; $i <= (int)ceil($total / $per); $i++): ?>
          <a class="chip<?= $i === $page ? ' is-active' : '' ?>" href="?p=products&f=<?= e($f) ?>&q=<?= e(rawurlencode($q)) ?>&page=<?= $i ?>"><?= $i ?></a>
        <?php endfor; ?>
      </div>
    <?php endif;
}

function page_categories(): void
{
    $rows = db()->query("SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.type_id = c.id AND p.archived = 0) AS cnt FROM categories c ORDER BY c.sort, cnt DESC")->fetchAll();
    ?>
    <p class="muted">Категории создаются автоматически из Ozon при каждом обновлении товаров. Здесь можно задать своё название и порядок (меньше — выше).</p>
    <p class="muted"><b>В продаже</b> — выключите, и все товары категории исчезнут с сайта: их не будет в каталоге, поиске и баннерах, а купить их нельзя (даже если товар уже лежит в корзине). <b>В меню</b> — только убирает категорию из списка слева, товары остаются в продаже.</p>
    <div class="table-wrap">
    <table class="tbl" id="catTable">
      <thead><tr><th>Категория Ozon</th><th>Название на сайте</th><th class="r">Товаров</th><th>Порядок</th><th>В продаже</th><th>В меню</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $c): ?>
        <tr data-id="<?= (int)$c['id'] ?>" class="<?= $c['hidden'] ? 'is-hidden' : '' ?><?= !empty($c['off_sale']) ? ' is-off' : '' ?>">
          <td><b><?= e($c['ozon_name']) ?></b><br><span class="muted tiny"><?= e($c['parent_name']) ?></span></td>
          <td><input class="cell" data-field="name" value="<?= e((string)$c['name']) ?>" placeholder="<?= e($c['ozon_name']) ?>"></td>
          <td class="r"><?= (int)$c['cnt'] ?></td>
          <td><input class="cell" data-field="sort" value="<?= (int)$c['sort'] ?>" inputmode="numeric" style="width:80px;min-width:0"></td>
          <td><label class="switch switch-sale" title="Продавать товары этой категории"><input type="checkbox" data-field="off_sale" <?= !empty($c['off_sale']) ? '' : 'checked' ?>><span></span></label></td>
          <td><label class="switch" title="Показывать в меню категорий"><input type="checkbox" data-field="hidden" <?= $c['hidden'] ? '' : 'checked' ?>><span></span></label></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="6" class="empty">Категорий пока нет — нажмите «Обновить сейчас» на странице «Обзор».</td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>
    <?php
}

function page_banners(bool $saved): void
{
    $list = banners(false);
    ?>
    <?php if ($saved): ?><div class="alert ok">Баннеры сохранены.</div><?php endif; ?>
    <p class="<?= setting('banners') === '' ? 'alert ok' : 'muted' ?>"><?= setting('banners') === '' ? 'Сейчас показываются 7 автоматических подборок (кот-копилка, кашпо, брелоки и т.д.) — они собираются из ваших товаров и обновляются сами. Можно отредактировать и сохранить — тогда будут показываться ваши.' : 'Сейчас показываются ваши баннеры. Кнопка «Вернуть автоматические» снова включит сборку из категорий.' ?></p>
    <p class="muted">Баннер целиком кликабельный и ведёт на подборку. Укажите слова подборки (например «кот денег») — в баннер подставятся фото этих товаров, а по клику покупатель увидит только их. Или задайте ссылку сами: <code>#catalog</code> — к каталогу, <code>?cat=ID</code> — на категорию.</p>
    <form method="post" class="banners-form">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <?php foreach ($list as $i => $b): ?>
      <section class="panel banner-edit theme-<?= e($b['theme']) ?>">
        <div class="banner-edit-head">
          <h2>Слайд <?= $i + 1 ?></h2>
          <label class="check"><input type="checkbox" name="b[<?= $i ?>][on]" value="1" <?= $b['on'] ? 'checked' : '' ?>> Показывать</label>
          <label class="check tiny"><input type="checkbox" name="b[<?= $i ?>][delete]" value="1"> удалить</label>
        </div>
        <div class="grid2">
          <label>Надпись сверху<input name="b[<?= $i ?>][kicker]" value="<?= e($b['kicker']) ?>" maxlength="60"></label>
          <label>Цвет
            <select name="b[<?= $i ?>][theme]"><?php foreach (MB_BANNER_THEMES as $k => $t): ?><option value="<?= $k ?>" <?= $b['theme'] === $k ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></label>
          <label class="full" style="grid-column:1/-1">Заголовок<input name="b[<?= $i ?>][title]" value="<?= e($b['title']) ?>" maxlength="90"></label>
          <label class="full" style="grid-column:1/-1">Текст<textarea name="b[<?= $i ?>][text]" rows="2" maxlength="220"><?= e($b['text']) ?></textarea></label>
          <label>Текст кнопки<input name="b[<?= $i ?>][btn]" value="<?= e($b['btn']) ?>" maxlength="30"></label>
          <label>Ссылка<input name="b[<?= $i ?>][link]" value="<?= e($b['link']) ?>"></label>
          <label class="full" style="grid-column:1/-1">Подборка — слова из названия товаров <small class="muted">например «кот денег» или «кашпо». Фото и ссылка подставятся сами (ссылку тогда оставьте пустой)</small><input name="b[<?= $i ?>][q]" value="<?= e((string)$b['q']) ?>" maxlength="60" placeholder="кот копилка"></label>
          <label class="full" style="grid-column:1/-1">Картинка (ссылка, необязательно)<input name="b[<?= $i ?>][image]" value="<?= e($b['image']) ?>" placeholder="https://…"></label>
        </div>
      </section>
      <?php endforeach; ?>
      <div class="savebar">
        <button class="btn btn-accent btn-lg">Сохранить</button>
        <button class="btn btn-lg" name="add" value="1">+ Добавить слайд</button>
        <button class="btn btn-ghost btn-lg" name="reset" value="1" onclick="return confirm('Вернуть автоматические подборки?')">Вернуть автоматические</button>
      </div>
    </form>
    <?php
}

function page_users(): void
{
    $q = trim((string)($_GET['q'] ?? ''));
    $w = '1=1'; $prm = [];
    if ($q !== '') { $w = '(lower(email) LIKE :q OR lower(name) LIKE :q OR phone LIKE :q)'; $prm[':q'] = '%' . mb_strtolower($q) . '%'; }
    $st = db()->prepare("SELECT u.*, (SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id) AS cnt,
        (SELECT COALESCE(SUM(total),0) FROM orders o WHERE o.user_id = u.id AND o.status IN ('paid','processing','shipped','ready','done')) AS spent
        FROM users u WHERE $w ORDER BY u.id DESC LIMIT 300");
    $st->execute($prm);
    $rows = $st->fetchAll();
    ?>
    <div class="toolbar"><form class="tsearch" method="get"><input type="hidden" name="p" value="users"><input type="search" name="q" value="<?= e($q) ?>" placeholder="Имя, e-mail или телефон"><button class="btn">Найти</button></form></div>
    <div class="table-wrap">
    <table class="tbl" id="userTable">
      <thead><tr><th>Покупатель</th><th>Телефон</th><th class="r">Заказов</th><th class="r">Купил на</th><th>Скидка, %</th><th>Заметка</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $u): ?>
        <tr data-id="<?= (int)$u['id'] ?>">
          <td class="name"><b><?= e($u['name']) ?></b><span><?= e($u['email']) ?></span><span class="tiny muted">с <?= e(date('d.m.Y', strtotime($u['created_at']))) ?></span></td>
          <td class="nowrap"><?= e((string)$u['phone']) ?></td>
          <td class="r"><?= (int)$u['cnt'] ?></td>
          <td class="r nowrap"><?= money((float)$u['spent']) ?></td>
          <td><input class="cell" data-field="discount" value="<?= (float)$u['discount_percent'] ?: '' ?>" placeholder="0" inputmode="decimal" style="width:90px;min-width:0"></td>
          <td><input class="cell" data-field="note" value="<?= e((string)$u['note']) ?>" placeholder="—"></td>
          <td><a class="btn btn-sm" href="?p=user&id=<?= (int)$u['id'] ?>">Открыть</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="7" class="empty">Покупателей пока нет. Они появятся, когда кто-то зарегистрируется на сайте.</td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>
    <?php
}

function page_user(): void
{
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([(int)($_GET['id'] ?? 0)]);
    $u = $st->fetch();
    if (!$u) { echo '<div class="empty">Покупатель не найден.</div>'; return; }
    $st = db()->prepare('SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC');
    $st->execute([$u['id']]);
    $orders = $st->fetchAll();
    $st = db()->prepare('SELECT * FROM coupons WHERE user_id = ? ORDER BY id DESC');
    $st->execute([$u['id']]);
    $coupons = $st->fetchAll();
    $statuses = order_statuses();
    ?>
    <a href="?p=users" class="muted">← Все покупатели</a>
    <div class="panels">
      <section class="panel" id="userTable" data-single="1">
        <h2><?= e($u['name']) ?></h2>
        <p><?= e($u['email']) ?><br><?= e((string)$u['phone']) ?></p>
        <div data-id="<?= (int)$u['id'] ?>">
          <label>Персональная скидка, %<input class="cell" data-field="discount" value="<?= (float)$u['discount_percent'] ?: '' ?>" placeholder="0" inputmode="decimal"></label>
          <label>Заметка (видите только вы)<input class="cell" data-field="note" value="<?= e((string)$u['note']) ?>"></label>
        </div>
        <p class="tiny muted">Скидка применяется ко всем товарам, когда покупатель вошёл в кабинет.</p>
        <p class="tiny"><?= !empty($u['email_verified']) ? '<span class="tag ok">e-mail подтверждён</span>' : '<span class="tag warn">e-mail не подтверждён</span>' ?>
          <?= !empty($u['tg_chat_id']) ? ' <span class="tag ok">Telegram подключён</span>' : '' ?>
          <?php if (!empty($u['referred_by'])): ?> <a class="tag" href="?p=user&id=<?= (int)$u['referred_by'] ?>">пришёл по приглашению</a><?php endif; ?></p>
      </section>
      <?php if (bonus_on()): $tx = db()->prepare('SELECT * FROM bonus_tx WHERE user_id = ? ORDER BY id DESC LIMIT 20'); $tx->execute([$u['id']]); $tx = $tx->fetchAll(); ?>
      <section class="panel" id="bonusPanel" data-id="<?= (int)$u['id'] ?>">
        <h2>Баллы: <span id="bonusBal"><?= (int)bonus_balance((int)$u['id']) ?></span></h2>
        <div class="grid2">
          <label>Начислить / списать <small>например 200 или -100</small><input id="bonusAmt" inputmode="numeric"></label>
          <label>Причина<input id="bonusWhy" maxlength="120" placeholder="подарок на день рождения"></label>
        </div>
        <button class="btn btn-accent btn-sm" type="button" id="bonusSave">Провести</button>
        <ul class="log" style="margin-top:12px"><?php foreach ($tx as $t): ?><li class="<?= $t['amount'] > 0 ? 'ok' : '' ?>"><time><?= e(date('d.m.y', strtotime($t['created_at']))) ?></time> <?= $t['amount'] > 0 ? '+' : '' ?><?= (int)$t['amount'] ?> — <?= e($t['reason']) ?></li><?php endforeach; ?></ul>
      </section>
      <?php endif; ?>
      <section class="panel">
        <h2>Промокоды покупателя</h2>
        <?php foreach ($coupons as $c): ?><p><b><?= e($c['code']) ?></b> <?= e(coupon_label($c)) ?> <span class="tag<?= $c['active'] ? ' ok' : '' ?>"><?= $c['active'] ? 'активен' : 'выключен' ?></span> <span class="muted tiny">использован <?= (int)$c['used'] ?></span></p><?php endforeach; ?>
        <?php if (!$coupons): ?><p class="muted">Персональных промокодов нет.</p><?php endif; ?>
        <a class="btn btn-accent" href="?p=coupons&user=<?= e(rawurlencode((string)$u['email'])) ?>">Выдать промокод</a>
      </section>
    </div>
    <h2 style="margin-top:24px">Заказы</h2>
    <div class="table-wrap"><table class="tbl">
      <thead><tr><th>№</th><th>Дата</th><th class="r">Сумма</th><th>Статус</th></tr></thead>
      <tbody>
      <?php foreach ($orders as $o): ?>
        <tr class="rowlink" onclick="location='?p=order&id=<?= (int)$o['id'] ?>'"><td><b><?= e($o['number']) ?></b></td><td><?= e(date('d.m.y H:i', strtotime($o['created_at']))) ?></td><td class="r"><?= money((float)$o['total']) ?></td><td><span class="tag st-<?= e($o['status']) ?>"><?= e($statuses[$o['status']] ?? $o['status']) ?></span></td></tr>
      <?php endforeach; ?>
      <?php if (!$orders): ?><tr><td colspan="4" class="empty">Заказов нет.</td></tr><?php endif; ?>
      </tbody>
    </table></div>
    <?php
}

function page_coupons(string $msg): void
{
    $rows = db()->query('SELECT c.*, u.email AS uemail FROM coupons c LEFT JOIN users u ON u.id = c.user_id ORDER BY c.id DESC LIMIT 300')->fetchAll();
    ?>
    <?php if ($msg !== ''): ?><div class="alert<?= str_starts_with($msg, 'ok:') ? ' ok' : '' ?>"><?= e(preg_replace('/^ok:/', '', $msg)) ?></div><?php endif; ?>
    <section class="panel">
      <h2>Новый промокод</h2>
      <form method="post" class="coupon-form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <div class="grid3">
          <label>Код <small>пусто — сгенерировать</small><input name="code" maxlength="30" placeholder="например, SPRING10"></label>
          <label>Тип<select name="kind"><option value="percent">Процент от суммы</option><option value="fixed">Фиксированная сумма, ₽</option></select></label>
          <label>Размер скидки<input name="value" required inputmode="decimal" placeholder="10"></label>
          <label>Мин. сумма заказа, ₽<input name="min_sum" type="number" min="0" value="0"></label>
          <label>Сколько раз можно применить <small>0 — без ограничений</small><input name="max_uses" type="number" min="0" value="0"></label>
          <label>Действует до <small>необязательно</small><input name="expires_at" type="date"></label>
          <label>Только для покупателя (e-mail) <small>необязательно</small><input name="user_email" type="email" value="<?= e((string)($_GET['user'] ?? '')) ?>"></label>
          <label>Заметка<input name="note" maxlength="200" placeholder="для чего промокод"></label>
        </div>
        <label class="check"><input type="checkbox" name="once" value="1" checked> Один раз на покупателя</label>
        <button class="btn btn-accent">Создать промокод</button>
      </form>
    </section>
    <div class="table-wrap">
    <table class="tbl" id="couponTable">
      <thead><tr><th>Код</th><th>Скидка</th><th>Условия</th><th>Для кого</th><th class="r">Использован</th><th>Активен</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $c): ?>
        <tr data-id="<?= (int)$c['id'] ?>" class="<?= $c['active'] ? '' : 'is-hidden' ?>">
          <td><b><?= e($c['code']) ?></b><?= $c['note'] ? '<br><span class="muted tiny">' . e($c['note']) . '</span>' : '' ?></td>
          <td class="nowrap"><?= e(coupon_label($c)) ?></td>
          <td class="tiny"><?= $c['min_sum'] > 0 ? 'от ' . money((float)$c['min_sum']) . '<br>' : '' ?><?= $c['expires_at'] ? 'до ' . e(date('d.m.Y', strtotime($c['expires_at']))) . '<br>' : '' ?><?= $c['once_per_user'] ? 'один раз на покупателя' : '' ?></td>
          <td class="tiny"><?= $c['uemail'] ? e($c['uemail']) : 'все' ?></td>
          <td class="r"><?= (int)$c['used'] ?><?= $c['max_uses'] ? ' / ' . (int)$c['max_uses'] : '' ?></td>
          <td><label class="switch"><input type="checkbox" class="ctoggle" <?= $c['active'] ? 'checked' : '' ?>><span></span></label></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="6" class="empty">Промокодов пока нет.</td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>
    <?php
}

function page_reviews(): void
{
    $f = (string)($_GET['f'] ?? 'pending');
    $w = in_array($f, ['pending', 'approved', 'rejected'], true) ? 'r.status = ' . db()->quote($f) : '1=1';
    $rows = db()->query("SELECT r.*, p.name AS pname, u.email AS uemail FROM reviews r LEFT JOIN products p ON p.id = r.product_id LEFT JOIN users u ON u.id = r.user_id WHERE $w ORDER BY r.id DESC LIMIT 300")->fetchAll();
    ?>
    <div class="toolbar">
      <button class="btn btn-accent" type="button" id="revImport">Загрузить отзывы 4–5★ с Ozon</button>
      <span class="muted tiny"><?= setting('ozon_reviews_last') ? 'Последняя загрузка ' . e(date('d.m H:i', strtotime(setting('ozon_reviews_last')))) . '. ' : '' ?>Берём только отзывы на 5 звёзд с текстом; новые подтягиваются раз в сутки при обновлении из Ozon.</span>
    </div>
    <div class="chips" style="margin-bottom:14px">
      <?php foreach (['pending' => 'На проверке', 'approved' => 'Опубликованы', 'rejected' => 'Отклонены', 'all' => 'Все'] as $k => $t): ?>
        <a class="chip<?= $f === $k ? ' is-active' : '' ?>" href="?p=reviews&f=<?= $k ?>"><?= $t ?></a>
      <?php endforeach; ?>
    </div>
    <div class="review-admin" id="reviewAdmin">
      <?php foreach ($rows as $r): ?>
        <section class="panel" data-id="<?= (int)$r['id'] ?>">
          <div class="ra-head">
            <b><?= str_repeat('★', (int)$r['rating']) ?><span class="muted"><?= str_repeat('★', 5 - (int)$r['rating']) ?></span></b>
            <a href="../product.php?id=<?= (int)$r['product_id'] ?>#reviews" target="_blank"><?= e((string)$r['pname']) ?> ↗</a>
            <span class="muted tiny"><?= e($r['name']) ?> · <?= e((string)$r['uemail']) ?> · <?= e(date('d.m.Y H:i', strtotime($r['created_at']))) ?></span>
            <?php if ($r['verified']): ?><span class="tag ok">Купил на сайте</span><?php endif; ?>
            <?php if (($r['source'] ?? '') === 'ozon'): ?><span class="tag tag-ozon">с Ozon</span><?php endif; ?>
            <span class="tag st-<?= $r['status'] === 'approved' ? 'done' : ($r['status'] === 'rejected' ? 'cancelled' : 'new') ?>"><?= ['pending' => 'на проверке', 'approved' => 'опубликован', 'rejected' => 'отклонён'][$r['status']] ?? $r['status'] ?></span>
          </div>
          <p><?= nl2br(e($r['text'])) ?></p>
          <label>Ответ магазина <small>будет виден под отзывом</small><textarea class="ra-reply" rows="2"><?= e((string)$r['reply']) ?></textarea></label>
          <div class="ra-actions">
            <button class="btn btn-accent btn-sm" data-st="approved" type="button">Опубликовать</button>
            <button class="btn btn-sm" data-st="rejected" type="button">Отклонить</button>
            <button class="btn btn-sm" data-st="reply" type="button">Сохранить ответ</button>
            <button class="btn btn-sm btn-danger" data-st="delete" type="button">Удалить</button>
          </div>
        </section>
      <?php endforeach; ?>
      <?php if (!$rows): ?><div class="empty">Здесь пусто.</div><?php endif; ?>
    </div>
    <?php
}

function page_orders(): void
{
    $s = (string)($_GET['s'] ?? '');
    $statuses = order_statuses();
    $w = isset($statuses[$s]) ? 'WHERE status = ' . db()->quote($s) : '';
    $rows = db()->query("SELECT * FROM orders $w ORDER BY id DESC LIMIT 300")->fetchAll();
    $methods = delivery_methods();
    ?>
    <div class="chips">
      <a class="chip<?= $s === '' ? ' is-active' : '' ?>" href="?p=orders">Все</a>
      <?php foreach ($statuses as $k => $t): ?><a class="chip<?= $s === $k ? ' is-active' : '' ?>" href="?p=orders&s=<?= $k ?>"><?= $t ?></a><?php endforeach; ?>
    </div>
    <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>№</th><th>Дата</th><th>Покупатель</th><th>Получение</th><th>Оплата</th><th class="r">Сумма</th><th>Статус</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $o): ?>
        <tr class="rowlink" onclick="location='?p=order&id=<?= (int)$o['id'] ?>'">
          <td><a href="?p=order&id=<?= (int)$o['id'] ?>"><b><?= e($o['number']) ?></b></a></td>
          <td class="nowrap"><?= e(date('d.m.y H:i', strtotime($o['created_at']))) ?></td>
          <td><?= e($o['customer_name']) ?><br><span class="muted tiny"><?= e($o['phone']) ?></span></td>
          <td><?= e($methods[$o['delivery_method']]['title'] ?? $o['delivery_method']) ?></td>
          <td><?= $o['payment_method'] === 'online' ? ($o['paid_at'] ? '<span class="tag ok">оплачен</span>' : '<span class="tag warn">не оплачен</span>') : '<span class="tag">при получении</span>' ?></td>
          <td class="r nowrap"><b><?= money((float)$o['total']) ?></b></td>
          <td><span class="tag st-<?= e($o['status']) ?>"><?= e($statuses[$o['status']] ?? $o['status']) ?></span></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="7" class="empty">Заказов пока нет.</td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>
    <?php
}

function page_order(): void
{
    $st = db()->prepare('SELECT * FROM orders WHERE id = ?');
    $st->execute([(int)($_GET['id'] ?? 0)]);
    $o = $st->fetch();
    if (!$o) { echo '<div class="empty">Заказ не найден.</div>'; return; }
    if ($o['payment_method'] === 'online' && !$o['paid_at'] && $o['payment_id']) { yk_refresh_order($o); $st->execute([$o['id']]); $o = $st->fetch(); }
    $items = json_decode($o['items'], true) ?: [];
    $methods = delivery_methods();
    ?>
    <a href="?p=orders" class="muted">← Все заказы</a>
    <div class="panels">
      <section class="panel">
        <h2>Заказ <?= e($o['number']) ?><?= ($o['kind'] ?? '') === 'gift' ? ' <span class="tag">сертификат</span>' : '' ?></h2>
        <table class="tbl tbl-plain">
          <?php foreach ($items as $it): ?>
            <tr><td><b><?= e($it['offer_id']) ?></b><?= !empty($it['code']) ? ' <span class="tag">код ' . e($it['code']) . '</span>' : '' ?> <?= e($it['name']) ?></td><td class="r nowrap"><?= (int)$it['qty'] ?> × <?= money((float)$it['price']) ?><?= !empty($it['pct']) ? ' <span class="muted tiny">(−' . +$it['pct'] . '%)</span>' : '' ?></td><td class="r nowrap"><?= money($it['qty'] * $it['price']) ?></td></tr>
          <?php endforeach; ?>
          <?php if ((float)($o['bonus_used'] ?? 0) > 0): ?><tr><td>Оплачено баллами</td><td></td><td class="r">−<?= money((float)$o['bonus_used']) ?></td></tr><?php endif; ?>
          <tr><td>Доставка: <?= e($methods[$o['delivery_method']]['title'] ?? ($o['delivery_method'] === 'email' ? 'на e-mail' : $o['delivery_method'])) ?><?php $dm = json_decode((string)($o['dlv_meta'] ?? ''), true); if (!empty($dm['eta'])): ?> <span class="muted tiny">(<?= e($dm['eta']) ?>, вес ~<?= (int)($dm['weight'] ?? 0) ?> г)</span><?php endif; ?></td><td></td><td class="r"><?= money((float)$o['delivery_price']) ?></td></tr>
          <?php if ((float)($o['gift_used'] ?? 0) > 0): ?><tr><td>Сертификат <?= e((string)$o['gift_code']) ?></td><td></td><td class="r">−<?= money((float)$o['gift_used']) ?></td></tr><?php endif; ?>
          <tr class="total"><td>Итого</td><td></td><td class="r"><?= money((float)$o['total']) ?></td></tr>
        </table>
      </section>
      <section class="panel">
        <h2>Покупатель</h2>
        <?php if (!empty($o['user_id'])): ?><p><a class="tag ok" href="?p=user&id=<?= (int)$o['user_id'] ?>">личный кабинет #<?= (int)$o['user_id'] ?> →</a></p><?php endif; ?>
        <?php if ((float)($o['discount_total'] ?? 0) > 0): ?><p>Скидка: <b><?= money((float)$o['discount_total']) ?></b><?= !empty($o['coupon_code']) ? ' · промокод <b>' . e($o['coupon_code']) . '</b>' : '' ?></p><?php endif; ?>
        <p><b><?= e($o['customer_name']) ?></b><br><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $o['phone'])) ?>"><?= e($o['phone']) ?></a><?= $o['email'] ? '<br>' . e($o['email']) : '' ?></p>
        <?php if ($o['delivery_address']): ?><p>📍 <?= e($o['delivery_address']) ?></p><?php endif; ?>
        <?php if ($o['comment']): ?><p>💬 <?= e($o['comment']) ?></p><?php endif; ?>
        <p>Оплата: <?= $o['payment_method'] === 'online' ? ($o['paid_at'] ? '<span class="tag ok">оплачен ' . e(date('d.m.y H:i', strtotime($o['paid_at']))) . '</span>' : '<span class="tag warn">не оплачен</span>') : 'при получении' ?></p>
        <label>Статус
          <select id="orderStatus" data-id="<?= (int)$o['id'] ?>">
            <?php foreach (order_statuses() as $k => $t): ?><option value="<?= $k ?>" <?= $o['status'] === $k ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?>
          </select></label>
        <label>Заметка (трек-номер и т.п.)<textarea id="orderNote" rows="2"><?= e((string)$o['admin_note']) ?></textarea></label>
        <label class="check"><input type="checkbox" id="orderNotify" checked> Сообщить покупателю о смене статуса<?= !empty($o['email']) ? ' (e-mail' . (!empty($o['tg_chat_id']) || !empty($o['user_id']) ? ', Telegram' : '') . ')' : '' ?></label>
        <button class="btn btn-accent" id="orderSave" type="button">Сохранить</button>
        <?php if (!empty($o['user_id']) && bonus_on()): ?><p class="tiny muted">При статусе «Выполнен» покупателю начислится кэшбэк <?= e(setting('bonus_percent')) ?>% баллами<?= !empty($o['bonus_accrued']) ? ' — уже начислен' : '' ?>. При отмене списанные баллы и сертификат вернутся.</p><?php endif; ?>
        <p class="tiny muted">Ссылка для покупателя: <code><?= e(base_url() . '/order.php?n=' . rawurlencode($o['number']) . '&t=' . $o['token']) ?></code></p>
      </section>
    </div>
    <?php
}

function msg_box(string $msg): void
{
    if ($msg !== '') echo '<div class="alert' . (str_starts_with($msg, 'ok:') ? ' ok' : '') . '">' . e(preg_replace('/^ok:/', '', $msg)) . '</div>';
}

function page_bundles(string $msg): void
{
    $rows = db()->query('SELECT * FROM bundles ORDER BY active DESC, sort, id DESC')->fetchAll();
    msg_box($msg);
    ?>
    <section class="panel">
      <h2>Новый набор</h2>
      <p class="muted tiny">Когда покупатель кладёт в корзину все товары набора, на них действует скидка набора (если она больше опта или персональной). Наборы показываются на главной и в карточках товаров.</p>
      <form method="post" class="coupon-form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <div class="grid3">
          <label>Название<input name="title" maxlength="80" placeholder="Например: Порядок в ванной"></label>
          <label>Коды товаров <small>через запятую — код с сайта или артикул Ozon</small><input name="codes" required placeholder="378405, 512090, 774310"></label>
          <label>Скидка на набор, %<input name="percent" type="number" min="1" max="90" step="0.5" value="10"></label>
        </div>
        <button class="btn btn-accent">Создать набор</button>
      </form>
    </section>
    <div class="table-wrap">
    <table class="tbl" id="bundleTable">
      <thead><tr><th>Набор</th><th>Товары</th><th>Скидка, %</th><th>Активен</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $b): $ids = (array)json_decode((string)$b['product_ids'], true); $ok = true; ?>
        <tr data-id="<?= (int)$b['id'] ?>" class="<?= $b['active'] ? '' : 'is-hidden' ?>">
          <td><b><?= e($b['title']) ?></b></td>
          <td class="tiny"><?php foreach ($ids as $id): $pp = find_product((int)$id); $on = product_on_sale($pp); $ok = $ok && $on; ?>
            <div><?= $pp ? e((string)$pp['site_code']) . ' — ' . e(mb_strimwidth($pp['name'], 0, 50, '…')) : 'товар удалён' ?><?= $on ? '' : ' <span class="tag warn">нет в наличии</span>' ?></div>
          <?php endforeach; ?><?php if (!$ok): ?><div class="tag warn">Набор скрыт, пока чего-то нет в наличии</div><?php endif; ?></td>
          <td><input class="cell bpct" value="<?= (float)$b['percent'] ?>" inputmode="decimal" style="width:80px;min-width:0"></td>
          <td><label class="switch"><input type="checkbox" class="btoggle" <?= $b['active'] ? 'checked' : '' ?>><span></span></label></td>
          <td><button class="btn btn-sm btn-danger bdel" type="button">Удалить</button></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="5" class="empty">Наборов пока нет.</td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>
    <?php
}

function page_gifts(string $msg): void
{
    $rows = db()->query('SELECT g.*, o.number FROM gift_cards g LEFT JOIN orders o ON o.id = g.order_id ORDER BY g.id DESC LIMIT 300')->fetchAll();
    $st = ['pending' => 'ждёт оплаты', 'active' => 'активен', 'used' => 'израсходован', 'cancelled' => 'отключён'];
    msg_box($msg);
    ?>
    <section class="panel">
      <h2>Выпустить сертификат вручную</h2>
      <p class="muted tiny">Покупатели могут купить сертификат сами на странице <a class="link" href="../gift.php" target="_blank">gift.php</a> (нужна ЮKassa). Здесь — для подарков, конкурсов и продажи в магазине.</p>
      <form method="post" class="coupon-form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <div class="grid3">
          <label>Номинал, ₽<input name="amount" type="number" min="100" step="50" required value="1000"></label>
          <label>Кому (имя)<input name="name" maxlength="80"></label>
          <label>E-mail получателя<input name="email" type="email"></label>
          <label>Заметка<input name="note" maxlength="200" placeholder="конкурс в Telegram"></label>
        </div>
        <label class="check"><input type="checkbox" name="send" value="1" checked> Отправить код на e-mail получателя</label>
        <button class="btn btn-accent">Выпустить</button>
      </form>
    </section>
    <div class="table-wrap">
    <table class="tbl" id="giftTable">
      <thead><tr><th>Код</th><th class="r">Номинал</th><th class="r">Остаток</th><th>Кому</th><th>Заказ</th><th>Действует до</th><th>Статус</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $g): ?>
        <tr data-id="<?= (int)$g['id'] ?>">
          <td><b><?= e($g['code']) ?></b><?= $g['message'] ? '<br><span class="muted tiny">' . e(mb_strimwidth((string)$g['message'], 0, 60, '…')) . '</span>' : '' ?></td>
          <td class="r nowrap"><?= money((float)$g['amount']) ?></td>
          <td class="r nowrap"><b><?= money((float)$g['balance']) ?></b></td>
          <td class="tiny"><?= e((string)$g['recipient_name']) ?><br><?= e((string)($g['recipient_email'] ?: $g['buyer_email'])) ?></td>
          <td><?= $g['number'] ? '<a href="?p=order&id=' . (int)$g['order_id'] . '">' . e($g['number']) . '</a>' : '<span class="muted tiny">вручную</span>' ?></td>
          <td class="nowrap"><?= $g['expires_at'] ? e(date('d.m.Y', strtotime($g['expires_at']))) : '—' ?></td>
          <td><span class="tag st-<?= $g['status'] === 'active' ? 'done' : ($g['status'] === 'cancelled' ? 'cancelled' : 'new') ?>"><?= e($st[$g['status']] ?? $g['status']) ?></span>
            <?php if ($g['status'] !== 'pending'): ?><button class="btn btn-sm gtoggle" type="button"><?= $g['status'] === 'cancelled' ? 'Включить' : 'Отключить' ?></button><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="7" class="empty">Сертификатов пока нет.</td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>
    <?php
}

function page_mailing(string $msg): void
{
    $n = (int)db()->query("SELECT COUNT(DISTINCT tg_chat_id) FROM users WHERE tg_chat_id IS NOT NULL AND tg_chat_id <> ''")->fetchColumn();
    msg_box($msg);
    ?>
    <section class="panel">
      <h2>Сообщение подписчикам бота</h2>
      <?php if (setting('tg_bot_token') === ''): ?><div class="alert">Сначала укажите токен Telegram-бота в настройках.</div><?php endif; ?>
      <p class="muted">Подписчиков: <b><?= $n ?></b> — покупатели, подключившие Telegram в личном кабинете.</p>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <label>Текст<textarea name="text" rows="6" maxlength="3500" placeholder="Например: Только до воскресенья — промокод WEEKEND15 даёт −15% на всё. <?= e(base_url()) ?>"></textarea></label>
        <label class="check"><input type="checkbox" name="confirm" value="1"> Отправить всем <?= $n ?> подписчикам</label>
        <button class="btn btn-accent" <?= $n && setting('tg_bot_token') !== '' ? '' : 'disabled' ?>>Отправить</button>
      </form>
      <p class="tiny muted">Пишите редко и по делу: новинки, акции, личные промокоды. Слишком частые сообщения заставляют отписываться.</p>
    </section>
    <?php
}

function page_update(string $msg): void
{
    msg_box($msg);
    ?>
    <section class="panel">
      <h2>Версия <?= e(MB_VERSION) ?></h2>
      <p class="muted">Загрузите архив обновления MaxBoom (.zip). Файлы сайта заменятся, база данных, товары, заказы и настройки останутся.</p>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <label>Архив обновления<input type="file" name="zip" accept=".zip" required></label>
        <button class="btn btn-accent">Обновить сайт</button>
      </form>
      <p class="tiny muted">Модуль zip на хостинге: <?= class_exists('ZipArchive') ? 'есть' : 'нет — обновляйте через файловый менеджер' ?>.</p>
    </section>
    <section class="panel">
      <h2>Автообновление из GitHub</h2>
      <p class="muted">Каждый час сайт проверяет репозиторий и сам ставит новую версию. База, заказы и настройки не затрагиваются.</p>
      <form method="post" class="grid2">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <label>Репозиторий <small>владелец/название</small><input name="update_repo" value="<?= e(setting('update_repo')) ?>" placeholder="maxboom/maxboom-site"></label>
        <label>Ветка<input name="update_branch" value="<?= e(setting('update_branch')) ?>" placeholder="main"></label>
        <label style="grid-column:1/-1">Токен GitHub <small>только для закрытого репозитория (права: Contents — read)</small><input type="password" name="update_token" value="" placeholder="<?= setting('update_token') !== '' ? '•••••••• сохранён' : 'не нужен для открытого репозитория' ?>" autocomplete="off"></label>
        <?php if (setting('update_token') !== ''): ?><label class="check tiny"><input type="checkbox" name="clear_update_token"> удалить токен</label><?php endif; ?>
        <label class="check" style="grid-column:1/-1"><input type="checkbox" name="update_auto" <?= setting('update_auto') === '1' ? 'checked' : '' ?>> Обновлять автоматически</label>
        <div style="grid-column:1/-1;display:flex;gap:10px;flex-wrap:wrap">
          <button class="btn btn-accent" name="upd_save" value="1">Сохранить</button>
          <button class="btn" name="upd_now" value="1">Проверить и обновить сейчас</button>
        </div>
      </form>
      <p class="tiny muted">Последняя проверка: <?= e(setting('update_last_check') ?: '—') ?>. Последнее обновление: <?= e(setting('update_last') ?: '—') ?>.</p>
    </section>
    <?php
}

function fld(string $k, string $label, string $hint = '', string $type = 'text', array $attrs = []): void
{
    $secret = in_array($k, ['ozon_api_key', 'yk_secret', 'wb_token', 'tg_bot_token', 'cdek_client_secret'], true);
    $val = $secret ? '' : setting($k);
    $ph = $secret && setting($k) !== '' ? '•••••••• сохранён (оставьте пустым, чтобы не менять)' : ($attrs['placeholder'] ?? '');
    $extra = '';
    foreach ($attrs as $a => $v) if ($a !== 'placeholder') $extra .= ' ' . $a . '="' . e($v) . '"';
    echo '<label>' . e($label) . ($hint ? ' <small>' . $hint . '</small>' : '')
        . '<input type="' . $type . '" name="' . $k . '" value="' . e($val) . '" placeholder="' . e($ph) . '"' . $extra . ' autocomplete="off"></label>';
    if ($secret && setting($k) !== '') echo '<label class="check tiny"><input type="checkbox" name="clear_' . $k . '"> удалить сохранённый ключ</label>';
}

function chk(string $k, string $label): void
{
    echo '<label class="check"><input type="checkbox" name="' . $k . '" ' . (setting($k) === '1' ? 'checked' : '') . '> ' . e($label) . '</label>';
}

function page_settings(bool $saved): void
{
    $cronUrl = base_url() . '/cron.php?key=' . setting('cron_key');
    ?>
    <?php if ($saved): ?><div class="alert ok">Настройки сохранены.</div><?php endif; ?>
    <?php if (!empty($_GET['first'])): ?><div class="alert ok">Пароль сохранён. Теперь укажите ключи Ozon и данные магазина, затем нажмите «Обновить из Ozon» на главной.</div><?php endif; ?>
    <form method="post" class="settings">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

      <section class="panel"><h2>Ozon API</h2>
        <p class="muted tiny">Личный кабинет Ozon → Настройки → API-ключи. Достаточно ключа с ролью «Admin read only» или «Товары».</p>
        <div class="grid2">
          <?php fld('ozon_client_id', 'Client-Id'); fld('ozon_api_key', 'API-ключ', '', 'password'); ?>
        </div>
        <label>Какую цену Ozon считать «текущей»
          <select name="price_source">
            <?php foreach (['marketing_seller_price' => 'Цена с учётом акций продавца (рекомендуется)', 'price' => 'Цена товара (из карточки, со скидкой продавца)', 'old_price' => 'Цена до скидки (зачёркнутая)'] as $k => $t): ?>
              <option value="<?= $k ?>" <?= setting('price_source') === $k ? 'selected' : '' ?>><?= $t ?></option>
            <?php endforeach; ?>
          </select></label>
        <button type="button" class="btn btn-sm" id="priceSample">Показать, какие цены отдаёт Ozon</button>
        <pre class="sample" id="priceSampleOut" hidden></pre>
      </section>

      <section class="panel"><h2>Цены</h2>
        <div class="grid2">
          <?php fld('discount_percent', 'Скидка от цены Ozon, %', '', 'number', ['min' => '0', 'max' => '95', 'step' => '0.5']); ?>
          <label>Округление цены
            <select name="price_rounding">
              <?php foreach (['1' => 'До рубля', '10' => 'До 10 ₽', '50' => 'До 50 ₽', '100' => 'До 100 ₽', '9' => 'Окончание на 9 (499, 1 299)'] as $k => $t): ?>
                <option value="<?= $k ?>" <?= setting('price_rounding') === $k ? 'selected' : '' ?>><?= $t ?></option>
              <?php endforeach; ?>
            </select></label>
          <?php fld('min_order', 'Минимальная сумма заказа, ₽', '0 — без ограничения', 'number', ['min' => '0']); ?>
        </div>
        <p class="muted tiny">На сайте показываются только товары, которые есть в наличии на Ozon. Остатки обновляются вместе с ценами.</p>
      </section>

      <section class="panel"><h2>Оптовая цена и отзывы</h2>
        <div class="grid2">
          <?php fld('wholesale_qty', 'Опт: от скольких штук одного товара', '0 — опта нет', 'number', ['min' => '0']);
          fld('wholesale_percent', 'Опт: скидка, %', '', 'number', ['min' => '0', 'max' => '90', 'step' => '0.5']); ?>
        </div>
        <p class="muted tiny">Персональная скидка покупателя задаётся в разделе «Покупатели». Если у строки корзины подходят обе — действует бóльшая. Промокод применяется сверху.</p>
        <?php chk('reviews_enabled', 'Включить отзывы на сайте'); chk('reviews_premod', 'Публиковать отзывы только после моей проверки'); ?>
      </section>

      <section class="panel"><h2>Баллы, «Приведи друга», сертификаты</h2>
        <?php chk('bonus_enabled', 'Начислять баллы (1 балл = 1 ₽)'); ?>
        <div class="grid2">
          <?php fld('bonus_percent', 'Кэшбэк с выполненного заказа, %', '', 'number', ['min' => '0', 'max' => '50', 'step' => '0.5']);
          fld('bonus_max_percent', 'Баллами можно оплатить до, % заказа', '', 'number', ['min' => '0', 'max' => '100']);
          fld('bonus_welcome', 'Баллы за подтверждение e-mail', '0 — не давать', 'number', ['min' => '0']); ?>
        </div>
        <?php chk('ref_enabled', 'Программа «Приведи друга»'); ?>
        <div class="grid2">
          <?php fld('ref_bonus_new', 'Баллы другу (после подтверждения e-mail)', '', 'number', ['min' => '0']);
          fld('ref_bonus_owner', 'Баллы пригласившему (после первого заказа друга)', '', 'number', ['min' => '0']); ?>
        </div>
        <?php chk('gift_enabled', 'Продавать подарочные сертификаты (нужна ЮKassa)'); ?>
        <div class="grid2">
          <?php fld('gift_nominals', 'Номиналы, ₽', 'через запятую'); fld('gift_days', 'Срок действия, дней', '', 'number', ['min' => '30']); ?>
        </div>
      </section>

      <section class="panel"><h2>Акция с таймером</h2>
        <?php chk('sale_on', 'Включить акцию'); ?>
        <div class="grid2">
          <?php fld('sale_title', 'Название', 'например «Чёрная пятница»'); fld('sale_percent', 'Дополнительная скидка, %', '', 'number', ['min' => '1', 'max' => '90', 'step' => '0.5']); ?>
          <label>Действует до<input type="datetime-local" name="sale_until" value="<?= e(str_replace(' ', 'T', substr(setting('sale_until'), 0, 16))) ?>"></label>
          <?php fld('low_stock', 'Показывать «Осталось N шт» при остатке не больше', '0 — не показывать', 'number', ['min' => '0']); ?>
        </div>
        <?php $sc = array_map('intval', array_filter(explode(',', setting('sale_cats')))); $cl = categories_visible(); if ($cl): ?>
        <p class="muted tiny" style="margin:8px 0 4px">Категории акции (ничего не выбрано — весь каталог):</p>
        <div class="chips chips-check"><?php foreach ($cl as $c): ?><label class="chip"><input type="checkbox" name="sale_cats[]" value="<?= (int)$c['id'] ?>" <?= in_array((int)$c['id'], $sc, true) ? 'checked' : '' ?>> <?= e($c['title']) ?></label><?php endforeach; ?></div>
        <?php endif; ?>
        <p class="muted tiny">Скидка акции добавляется к цене сайта, на карточках видна старая цена и таймер. После окончания цены возвращаются сами. <?= sale_active() ? '<b class="ok-text">Сейчас акция идёт.</b>' : '' ?></p>
      </section>

      <section class="panel"><h2>Магазин и контакты</h2>
        <div class="grid2">
          <?php fld('shop_name', 'Название'); fld('shop_tagline', 'Подзаголовок');
          fld('ticker', 'Бегущая строка', 'фразы через «·»');
          fld('phone', 'Телефон'); fld('email', 'E-mail для покупателей', '', 'email'); fld('telegram_contact', 'Telegram', 'например @maxboom');
          fld('legal_name', 'Продавец (ИП / ООО)', 'показывается в подвале и на странице «Контакты»'); fld('legal_ogrnip', 'ОГРНИП / ОГРН'); fld('legal_inn', 'ИНН'); fld('legal_address', 'Адрес'); ?>
          <label>Акцентный цвет<input type="color" name="theme_accent" value="<?= e(setting('theme_accent')) ?>"></label>
        </div>
      </section>

      <section class="panel"><h2>Ссылки на магазины</h2>
        <p class="muted tiny">Показываются в шапке и подвале. Ссылка на конкретный товар Ozon строится автоматически; для WB — по токену ниже или вручную в «Товарах»; для Яндекс Маркета — вручную, иначе ведёт на магазин.</p>
        <div class="grid2">
          <?php fld('link_ozon_store', 'Магазин на Ozon', '', 'url', ['placeholder' => 'https://www.ozon.ru/seller/…']);
          fld('link_wb_store', 'Магазин на Wildberries', '', 'url', ['placeholder' => 'https://www.wildberries.ru/seller/…']);
          fld('link_ym_store', 'Магазин на Яндекс Маркете', '', 'url', ['placeholder' => 'https://market.yandex.ru/business--…']);
          fld('wb_token', 'Токен WB (категория «Контент»)', 'необязательно — подтянет ссылки на товары WB по артикулу', 'password'); ?>
        </div>
      </section>

      <section class="panel"><h2>Получение заказа</h2>
        <div class="dlv-grid">
          <div><?php chk('pickup_enabled', 'Самовывоз из магазина'); fld('pickup_address', 'Адрес магазина'); fld('pickup_hours', 'Часы работы'); chk('pickup_pay_on_site', 'Можно оплатить при получении'); ?></div>
          <div><?php chk('cdek_enabled', 'СДЭК'); fld('cdek_price', 'Стоимость, ₽', '', 'number', ['min' => '0']); ?></div>
          <div><?php chk('ozon_dlv_enabled', 'Ozon Доставка'); fld('ozon_dlv_price', 'Стоимость, ₽', '', 'number', ['min' => '0']); ?></div>
          <div><?php chk('post_enabled', 'Почта России'); fld('post_price', 'Стоимость, ₽', '', 'number', ['min' => '0']); ?></div>
        </div>
        <?php fld('free_delivery_from', 'Бесплатная доставка от суммы, ₽', '0 — не действует', 'number', ['min' => '0']); ?>
      </section>

      <section class="panel"><h2>Расчёт доставки по тарифам</h2>
        <p class="muted tiny">Если включить, покупатель выбирает город и пункт выдачи СДЭК (или вводит индекс для Почты), а цена считается по тарифу перевозчика + ваша наценка. Если API не ответит — возьмётся фиксированная цена выше. Вес товаров подтягивается из Ozon.</p>
        <div class="dlv-grid">
          <div><?php chk('cdek_api', 'СДЭК по API'); fld('cdek_client_id', 'Account (client_id)', 'ЛК СДЭК → Интеграция'); fld('cdek_client_secret', 'Secure password', '', 'password');
            fld('cdek_from_city', 'Город отправки'); fld('cdek_tariff', 'Код тарифа', '136 — склад→ПВЗ, 137 — склад→дверь', 'number'); fld('cdek_markup', 'Наценка, ₽', '', 'number'); chk('cdek_test', 'Тестовый режим СДЭК'); ?></div>
          <div><?php chk('post_api', 'Почта России по тарифу'); fld('post_from_index', 'Индекс отправки', '6 цифр'); fld('post_object', 'Вид отправления', '27030 — посылка стандарт', 'number'); fld('post_markup', 'Наценка, ₽', '', 'number'); ?></div>
          <div><?php fld('default_weight_g', 'Вес товара по умолчанию, г', 'если в Ozon не указан', 'number', ['min' => '50']); fld('yandex_maps_key', 'Ключ Яндекс Карт', 'необязательно — покажет ПВЗ на карте'); ?>
            <button type="button" class="btn btn-sm" id="cacheClear">Сбросить кэш тарифов и страниц</button></div>
        </div>
      </section>

      <section class="panel"><h2>Онлайн-оплата ЮKassa</h2>
        <p class="muted tiny">ЮKassa → Интеграция → Ключи API. В разделе «HTTP-уведомления» укажите адрес <code><?= e(base_url()) ?>/yookassa_webhook.php</code> и события payment.succeeded, payment.canceled.</p>
        <div class="grid2">
          <?php fld('yk_shop_id', 'shopId'); fld('yk_secret', 'Секретный ключ', '', 'password'); ?>
        </div>
        <?php chk('yk_receipt', 'Передавать чек 54-ФЗ (если подключены «Чеки от ЮKassa» или своя касса)'); ?>
        <label>Ставка НДС в чеке
          <select name="yk_vat_code">
            <?php foreach (['1' => 'Без НДС', '2' => '0%', '3' => '10%', '4' => '20%', '7' => '5% (УСН)', '8' => '7% (УСН)'] as $k => $t): ?>
              <option value="<?= $k ?>" <?= setting('yk_vat_code') === (string)$k ? 'selected' : '' ?>><?= $t ?></option>
            <?php endforeach; ?>
          </select></label>
      </section>

      <section class="panel"><h2>Уведомления о заказах</h2>
        <div class="grid2">
          <?php fld('tg_bot_token', 'Токен Telegram-бота', 'от @BotFather', 'password'); fld('tg_chat_id', 'Chat ID', 'можно несколько через запятую');
          fld('notify_email', 'E-mail для уведомлений', '', 'email'); ?>
        </div>
        <button type="button" class="btn btn-sm" id="tgTest">Отправить тестовое уведомление</button>
        <h3 class="sub-h">Покупателям</h3>
        <?php chk('tg_customers', 'Покупатели могут подключить этого бота: статусы заказов, баллы, рассылки'); chk('notify_customer_email', 'Писать покупателю на e-mail о заказе и смене статуса'); ?>
        <p class="muted tiny">Бот: <?= setting('tg_bot_token') !== '' ? '@' . e(tg_bot_username() ?: '—') : 'не указан' ?>. Без https бот отвечает при автообновлении (cron) и когда покупатель нажимает «Подключить» в кабинете. С https включите мгновенные ответы:</p>
        <button type="button" class="btn btn-sm" id="tgWebhook">Подключить webhook (нужен https)</button>
      </section>

      <section class="panel"><h2>SEO, аналитика и скорость</h2>
        <div class="grid2">
          <?php fld('seo_title', 'Заголовок главной для поиска', 'пусто — «Название — подзаголовок»'); fld('seo_description', 'Описание сайта для поиска', 'до 200 символов');
          fld('metrika_id', 'Номер счётчика Яндекс Метрики', 'цифры, например 98765432');
          fld('shop_alt_name', 'Второе название магазина', 'в заголовке, футере и данных для поиска, по умолчанию Максбум');
          fld('google_verify', 'Код подтверждения Google', 'content из meta-тега google-site-verification');
          fld('yandex_verify', 'Код подтверждения Яндекс Вебмастера', 'content из meta-тега yandex-verification'); ?>
        </div>
        <?php chk('pretty_urls', 'Красивые адреса страниц (/p/123-nazvanie)'); chk('img_resize', 'Уменьшенные фото с CDN Ozon (страницы грузятся быстрее)'); chk('page_cache', 'Кэшировать страницы для гостей на 2 минуты'); chk('ozon_reviews_import', 'Загружать отзывы 4★ и 5★ с Ozon раз в сутки'); ?>
        <p class="muted tiny">Карта сайта: <a class="link" href="../sitemap.xml" target="_blank"><?= e(base_url()) ?>/sitemap.xml</a> — добавьте её в Яндекс Вебмастер и Google Search Console. В Метрике цели: <code>cart</code> (в корзину), <code>checkout</code> (оформление), <code>order</code> (заказ), <code>fav</code> (избранное); электронная коммерция включена.</p>
      </section>

      <section class="panel"><h2>Автообновление и безопасность</h2>
        <p class="muted tiny">В панели хостинга добавьте Cron раз в час: <code>php <?= e(MB_ROOT) ?>/cron.php</code><br>или вызов ссылки: <code><?= e($cronUrl) ?></code></p>
        <label class="check"><input type="checkbox" name="regen_cron"> Сменить ключ ссылки автообновления</label>
        <label>Новый пароль админки <small>минимум 8 символов, пусто — не менять</small><input type="password" name="new_password" autocomplete="new-password"></label>
        <p class="tiny muted">Версия сайта <?= e(MB_VERSION) ?> · <a class="link" href="?p=update">обновление сайта из архива</a></p>
      </section>

      <div class="savebar"><button class="btn btn-accent btn-lg">Сохранить настройки</button></div>
    </form>
    <?php
}

function admin_head(string $title, string $active = ''): void
{
    ?>
<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($title) ?> — MaxBoom админка</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Unbounded:wght@500;700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/style.css?v=<?= MB_VERSION ?>">
<link rel="stylesheet" href="../assets/admin.css?v=<?= MB_VERSION ?>">
<link rel="icon" href="../assets/favicon.svg" type="image/svg+xml">
<style>:root{--accent:<?= preg_match('/^#[0-9a-f]{6}$/i', setting('theme_accent')) ? setting('theme_accent') : '#00e5ff' ?>}</style>
</head><body class="admin">
<?php if (!empty($_SESSION['admin'])): ?>
<header class="topbar"><div class="wrap topbar-in">
  <a class="logo" href="./"><span class="logo-mark">M</span><span class="logo-text">MAX<b>BOOM</b></span><span class="admin-tag">админка</span></a>
  <nav class="anav">
    <?php $pend = (int)db()->query("SELECT COUNT(*) FROM reviews WHERE status = 'pending'")->fetchColumn();
    foreach (['home' => 'Обзор', 'products' => 'Товары', 'categories' => 'Категории', 'banners' => 'Баннеры', 'orders' => 'Заказы', 'users' => 'Покупатели', 'coupons' => 'Промокоды',
              'bundles' => 'Наборы', 'gifts' => 'Сертификаты', 'reviews' => 'Отзывы' . ($pend ? " <i class=\"nbadge\">$pend</i>" : ''), 'mailing' => 'Рассылка', 'settings' => 'Настройки'] as $k => $t): ?>
      <a href="?p=<?= $k ?>" class="<?= $active === $k || ($k === 'orders' && $active === 'order') || ($k === 'users' && $active === 'user') ? 'is-active' : '' ?>"><?= $t ?></a>
    <?php endforeach; ?>
  </nav>
  <a class="btn btn-ghost btn-sm" href="../" target="_blank">Сайт ↗</a>
  <a class="btn btn-ghost btn-sm" href="?p=logout">Выйти</a>
</div></header>
<main class="wrap amain"><h1 class="page-title"><?= e($title) ?></h1>
<?php else: ?><main><?php endif;
}

function admin_foot(): void
{
    ?>
</main>
<div class="toast" id="toast" role="status" aria-live="polite"></div>
<script>window.MB_CSRF = <?= json_encode(csrf_token()) ?>; window.MB_ROUND = <?= json_encode(setting('price_rounding')) ?>;</script>
<script src="../assets/admin.js?v=<?= MB_VERSION ?>"></script>
</body></html>
<?php
}
