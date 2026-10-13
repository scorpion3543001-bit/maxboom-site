<?php
/** Личный кабинет покупателя: вход, регистрация, заказы, купоны, профиль, восстановление пароля. */
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$pdo = db();
$err = '';
$ok = '';
$tab = (string)($_GET['tab'] ?? 'login');
$act = (string)($_POST['act'] ?? '');

function go(string $url): never { header('Location: ' . $url); exit; }

ref_capture();
// Подтверждение e-mail по ссылке из письма
if (!empty($_GET['verify'])) {
    $st = $pdo->prepare('SELECT * FROM users WHERE lower(email) = lower(?) AND verify_token = ?');
    $st->execute([(string)($_GET['e'] ?? ''), hash('sha256', (string)$_GET['verify'])]);
    if ($vu = $st->fetch()) { user_verified($vu); if (!current_user()) login_user((int)$vu['id']); go('account.php?verified=1'); }
    $err = 'Ссылка подтверждения устарела. Войдите и отправьте письмо ещё раз.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
    switch ($act) {
        case 'login':
            $tab = 'login';
            $key = 'login:' . $email;
            if (login_throttled($key)) { $err = 'Слишком много попыток. Подождите 15 минут или восстановите пароль.'; break; }
            $st = $pdo->prepare('SELECT * FROM users WHERE email = ?');
            $st->execute([$email]);
            $u = $st->fetch();
            if ($u && password_verify((string)($_POST['password'] ?? ''), (string)$u['pass_hash'])) {
                login_user((int)$u['id']);
                go((string)($_POST['back'] ?? '') === 'cart' ? 'cart.php' : 'account.php');
            }
            login_failed($key);
            usleep(300000);
            $err = 'Неверный e-mail или пароль.';
            break;

        case 'register':
            $tab = 'register';
            $name = trim((string)($_POST['name'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $pass = (string)($_POST['password'] ?? '');
            if (mb_strlen($name) < 2) $err = 'Укажите имя.';
            elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Проверьте e-mail.';
            elseif (mb_strlen($pass) < 8) $err = 'Пароль — минимум 8 символов.';
            elseif (empty($_POST['agree'])) $err = 'Нужно согласие на обработку персональных данных.';
            else {
                $st = $pdo->prepare('SELECT id FROM users WHERE email = ?');
                $st->execute([$email]);
                if ($st->fetch()) { $err = 'Такой e-mail уже зарегистрирован. Войдите или восстановите пароль.'; break; }
                $pdo->prepare('INSERT INTO users (email, phone, name, pass_hash, created_at, referred_by) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$email, $phone, mb_substr($name, 0, 80), password_hash($pass, PASSWORD_DEFAULT), now(), ref_owner_from_cookie()]);
                $uid = (int)$pdo->lastInsertId();
                login_user($uid);
                $nu = $pdo->query('SELECT * FROM users WHERE id = ' . $uid)->fetch();
                ref_code_for($nu);
                send_verify_mail($nu);
                go((string)($_POST['back'] ?? '') === 'cart' ? 'cart.php' : 'account.php?welcome=1');
            }
            break;

        case 'forgot':
            $tab = 'forgot';
            $st = $pdo->prepare('SELECT * FROM users WHERE email = ?');
            $st->execute([$email]);
            if ($u = $st->fetch()) {
                $token = bin2hex(random_bytes(20));
                $pdo->prepare('UPDATE users SET reset_token = ?, reset_until = ? WHERE id = ?')
                    ->execute([hash('sha256', $token), date('Y-m-d H:i:s', time() + 3600), $u['id']]);
                $link = base_url() . '/account.php?reset=' . $token . '&e=' . rawurlencode($email);
                $subj = '=?UTF-8?B?' . base64_encode('Восстановление пароля — ' . setting('shop_name')) . '?=';
                $from = setting('email') ?: ('no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
                @mail($email, $subj, "Чтобы задать новый пароль, откройте ссылку (действует 1 час):\n\n{$link}\n\nЕсли вы не запрашивали восстановление, просто удалите это письмо.",
                    "Content-Type: text/plain; charset=UTF-8\r\nFrom: {$from}\r\n");
            }
            $ok = 'Если такой e-mail зарегистрирован, мы отправили на него ссылку для восстановления пароля.';
            break;

        case 'reset':
            $tab = 'reset';
            $token = (string)($_POST['token'] ?? '');
            $st = $pdo->prepare('SELECT * FROM users WHERE email = ? AND reset_token = ? AND reset_until > ?');
            $st->execute([$email, hash('sha256', $token), now()]);
            $u = $st->fetch();
            $pass = (string)($_POST['password'] ?? '');
            if (!$u) $err = 'Ссылка устарела. Запросите восстановление ещё раз.';
            elseif (mb_strlen($pass) < 8) $err = 'Пароль — минимум 8 символов.';
            else {
                $pdo->prepare('UPDATE users SET pass_hash = ?, reset_token = NULL, reset_until = NULL WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
                login_user((int)$u['id']);
                go('account.php?changed=1');
            }
            break;

        case 'profile':
            $u = current_user() ?? go('account.php');
            $name = trim((string)($_POST['name'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            if (mb_strlen($name) < 2) { $err = 'Укажите имя.'; break; }
            $pdo->prepare('UPDATE users SET name = ?, phone = ? WHERE id = ?')->execute([mb_substr($name, 0, 80), $phone, $u['id']]);
            $np = (string)($_POST['new_password'] ?? '');
            if ($np !== '') {
                if (!password_verify((string)($_POST['password'] ?? ''), (string)$u['pass_hash'])) { $err = 'Текущий пароль указан неверно.'; break; }
                if (mb_strlen($np) < 8) { $err = 'Новый пароль — минимум 8 символов.'; break; }
                $pdo->prepare('UPDATE users SET pass_hash = ? WHERE id = ?')->execute([password_hash($np, PASSWORD_DEFAULT), $u['id']]);
            }
            go('account.php?saved=1#profile');

        case 'resend_verify':
            $u = current_user() ?? go('account.php');
            if (empty($_SESSION['verify_sent']) || time() - $_SESSION['verify_sent'] > 60) { send_verify_mail($u); $_SESSION['verify_sent'] = time(); }
            go('account.php?sent=1');

        case 'tg_unlink':
            $u = current_user() ?? go('account.php');
            $pdo->prepare('UPDATE users SET tg_chat_id = NULL WHERE id = ?')->execute([$u['id']]);
            go('account.php#tg');

        case 'logout':
            unset($_SESSION['uid']);
            session_regenerate_id(true);
            go('./');
    }
}

$user = current_user();
if (!empty($_GET['reset'])) $tab = 'reset';
page_head(($user ? 'Личный кабинет' : 'Вход') . ' — ' . setting('shop_name'), 'account');
?>
<section class="wrap account-page">
<?php if (!$user): ?>
  <div class="auth">
    <div class="auth-side">
      <h1 class="page-title">Личный кабинет</h1>
      <ul class="auth-perks">
        <li>История заказов и их статусы</li>
        <li>Персональные скидки и промокоды</li>
        <li>Отзывы о купленных товарах</li>
        <li>Быстрое оформление — данные подставятся сами</li>
        <?php if (bonus_on() && (float)setting('bonus_percent') > 0): ?><li>Кэшбэк <?= e(setting('bonus_percent')) ?>% баллами с каждого заказа<?= (float)setting('bonus_welcome') > 0 ? ' и ' . (int)setting('bonus_welcome') . ' баллов за подтверждение e-mail' : '' ?></li><?php endif; ?>
        <li>Избранное на всех устройствах и статусы заказов в Telegram</li>
      </ul>
    </div>
    <div class="auth-box">
      <?php if ($tab === 'reset'): ?>
        <h2>Новый пароль</h2>
        <?php if ($err): ?><div class="alert"><?= e($err) ?></div><?php endif; ?>
        <form method="post" class="auth-form">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="act" value="reset">
          <input type="hidden" name="token" value="<?= e((string)($_GET['reset'] ?? $_POST['token'] ?? '')) ?>">
          <input type="hidden" name="email" value="<?= e((string)($_GET['e'] ?? $_POST['email'] ?? '')) ?>">
          <label>Новый пароль<input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
          <button class="btn btn-accent btn-lg btn-block">Сохранить и войти</button>
        </form>
      <?php elseif ($tab === 'forgot'): ?>
        <h2>Восстановление пароля</h2>
        <?php if ($ok): ?><div class="alert ok"><?= e($ok) ?></div><?php endif; ?>
        <form method="post" class="auth-form">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="act" value="forgot">
          <label>E-mail<input type="email" name="email" required autocomplete="email"></label>
          <button class="btn btn-accent btn-lg btn-block">Прислать ссылку</button>
        </form>
        <p class="muted tiny"><a class="link" href="account.php?tab=login">Вернуться ко входу</a></p>
      <?php else: ?>
        <div class="seg" role="tablist">
          <a href="account.php?tab=login<?= isset($_GET['back']) ? '&back=cart' : '' ?>" class="<?= $tab !== 'register' ? 'is-on' : '' ?>">Вход</a>
          <a href="account.php?tab=register<?= isset($_GET['back']) ? '&back=cart' : '' ?>" class="<?= $tab === 'register' ? 'is-on' : '' ?>">Регистрация</a>
        </div>
        <?php if ($err): ?><div class="alert"><?= e($err) ?></div><?php endif; ?>
        <?php if ($tab === 'register'): ?>
        <form method="post" class="auth-form">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="act" value="register">
          <input type="hidden" name="back" value="<?= e((string)($_GET['back'] ?? '')) ?>">
          <label>Имя<input name="name" required maxlength="80" value="<?= e((string)($_POST['name'] ?? '')) ?>" autocomplete="name"></label>
          <label>E-mail<input type="email" name="email" required value="<?= e((string)($_POST['email'] ?? '')) ?>" autocomplete="email"></label>
          <label>Телефон <small>необязательно</small><input type="tel" name="phone" value="<?= e((string)($_POST['phone'] ?? '')) ?>" autocomplete="tel" placeholder="+7 900 000-00-00"></label>
          <label>Пароль <small>минимум 8 символов</small><input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
          <label class="check"><input type="checkbox" name="agree" value="1" required> Согласен на обработку персональных данных</label>
          <button class="btn btn-accent btn-lg btn-block">Зарегистрироваться</button>
        </form>
        <?php else: ?>
        <form method="post" class="auth-form">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="act" value="login">
          <input type="hidden" name="back" value="<?= e((string)($_GET['back'] ?? '')) ?>">
          <label>E-mail<input type="email" name="email" required value="<?= e((string)($_POST['email'] ?? '')) ?>" autocomplete="email"></label>
          <label>Пароль<input type="password" name="password" required autocomplete="current-password"></label>
          <button class="btn btn-accent btn-lg btn-block">Войти</button>
        </form>
        <p class="muted tiny"><a class="link" href="account.php?tab=forgot">Забыли пароль?</a></p>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
<?php else:
    $st = $pdo->prepare('SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 100');
    $st->execute([$user['id']]);
    $orders = $st->fetchAll();
    $st = $pdo->prepare("SELECT * FROM coupons WHERE active = 1 AND user_id = ? AND (expires_at IS NULL OR expires_at = '' OR expires_at >= ?) AND (max_uses = 0 OR used < max_uses) ORDER BY id DESC");
    $st->execute([$user['id'], date('Y-m-d')]);
    $coupons = $st->fetchAll();
    $statuses = order_statuses();
    $bonus = bonus_on() ? bonus_balance((int)$user['id']) : 0;
    $btx = $pdo->prepare('SELECT * FROM bonus_tx WHERE user_id = ? ORDER BY id DESC LIMIT 12');
    $btx->execute([$user['id']]);
    $btx = $btx->fetchAll();
    $refCode = ref_code_for($user);
    $refCnt = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE referred_by = ' . (int)$user['id'])->fetchColumn();
    $favCnt = count(fav_ids((int)$user['id']));
    $tgUrl = '';
    if (tg_customers_on() && empty($user['tg_chat_id'])) { try { $tgUrl = tg_link_url((int)$user['id'], null); } catch (Throwable) {} }
    $spent = 0.0;
    foreach ($orders as $o) if (in_array($o['status'], ['paid', 'processing', 'shipped', 'ready', 'done'], true)) $spent += (float)$o['total'];
    // Купленные товары без отзыва — предложим оценить
    $toReview = [];
    foreach ($orders as $o) {
        if (!in_array($o['status'], ['done', 'shipped', 'ready', 'paid', 'processing'], true)) continue;
        foreach (json_decode((string)$o['items'], true) ?: [] as $it) $toReview[(int)$it['id']] = $it['name'];
    }
    if ($toReview) {
        $st = $pdo->prepare('SELECT product_id FROM reviews WHERE user_id = ?');
        $st->execute([$user['id']]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $pid) unset($toReview[(int)$pid]);
    }
?>
  <div class="acc-head">
    <div>
      <h1 class="page-title"><?= !empty($_GET['welcome']) ? 'Добро пожаловать, ' : 'Здравствуйте, ' ?><?= e($user['name']) ?></h1>
      <p class="muted"><?= e($user['email']) ?></p>
    </div>
    <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="act" value="logout"><button class="btn btn-ghost">Выйти</button></form>
  </div>
  <?php if (!empty($_GET['saved'])): ?><div class="alert ok">Изменения сохранены.</div><?php endif; ?>
  <?php if (!empty($_GET['verified'])): ?><div class="alert ok">E-mail подтверждён<?= bonus_on() && (float)setting('bonus_welcome') > 0 ? ' — начислили приветственные баллы' : '' ?>. Спасибо!</div><?php endif; ?>
  <?php if (empty($user['email_verified'])): ?>
    <div class="alert verify-alert"><span>Подтвердите e-mail<?= !empty($user['verify_token']) ? ' — мы отправили ссылку на <b>' . e($user['email']) . '</b>.' : ' <b>' . e($user['email']) . '</b> — пришлём ссылку.' ?><?= bonus_on() && (float)setting('bonus_welcome') > 0 ? ' После подтверждения начислим ' . (int)setting('bonus_welcome') . ' баллов.' : '' ?><?= !empty($_GET['sent']) ? ' Письмо отправлено ещё раз.' : '' ?></span>
      <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="act" value="resend_verify"><button class="btn btn-sm"><?= !empty($user['verify_token']) ? 'Отправить ещё раз' : 'Отправить письмо' ?></button></form></div>
  <?php endif; ?>
  <?php if (!empty($_GET['changed'])): ?><div class="alert ok">Пароль изменён.</div><?php endif; ?>
  <?php if ($err): ?><div class="alert"><?= e($err) ?></div><?php endif; ?>

  <div class="acc-tiles">
    <div class="acc-tile"><b><?= count($orders) ?></b><span><?= plural(count($orders), 'заказ', 'заказа', 'заказов') ?></span></div>
    <div class="acc-tile<?= (float)$user['discount_percent'] > 0 ? ' is-hot' : '' ?>"><b><?= (float)$user['discount_percent'] > 0 ? '−' . rtrim(rtrim(number_format((float)$user['discount_percent'], 1, ',', ''), '0'), ',') . '%' : '—' ?></b><span>ваша персональная скидка</span></div>
    <?php if (bonus_on()): ?><a class="acc-tile is-bonus" href="account.php#bonus"><b><?= (int)$bonus ?></b><span><?= plural((int)$bonus, 'балл', 'балла', 'баллов') ?> · 1 балл = 1 ₽</span></a><?php endif; ?>
    <div class="acc-tile"><b><?= count($coupons) ?></b><span><?= plural(count($coupons), 'промокод', 'промокода', 'промокодов') ?></span></div>
    <a class="acc-tile" href="favorites.php"><b><?= $favCnt ?></b><span>в избранном</span></a>
    <?php $w = wholesale_rule(); if ($w['qty'] > 0 && $w['percent'] > 0): ?>
    <div class="acc-tile"><b>−<?= rtrim(rtrim(number_format($w['percent'], 1, ',', ''), '0'), ',') ?>%</b><span>опт от <?= $w['qty'] ?> шт одного товара</span></div>
    <?php endif; ?>
  </div>

  <?php if ($coupons): ?>
  <h2 class="acc-h">Ваши промокоды</h2>
  <div class="coupons">
    <?php foreach ($coupons as $c): ?>
      <div class="coupon">
        <span class="coupon-val"><?= e(coupon_label($c)) ?></span>
        <span class="coupon-code" data-copy="<?= e($c['code']) ?>" title="Скопировать"><?= e($c['code']) ?></span>
        <small class="muted"><?= $c['min_sum'] > 0 ? 'от ' . money((float)$c['min_sum']) . ' · ' : '' ?><?= $c['expires_at'] ? 'до ' . e(date('d.m.Y', strtotime($c['expires_at']))) : 'бессрочно' ?></small>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($toReview && setting('reviews_enabled') === '1'): ?>
  <h2 class="acc-h">Оцените покупки</h2>
  <div class="to-review">
    <?php foreach (array_slice($toReview, 0, 6, true) as $pid => $nm): ?>
      <a class="chip" href="product.php?id=<?= $pid ?>&amp;r=1#reviews"><?= e(mb_strimwidth($nm, 0, 40, '…')) ?></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <h2 class="acc-h">Мои заказы</h2>
  <?php if (!$orders): ?>
    <div class="empty">Заказов пока нет. <a href="./#catalog">Перейти в каталог</a></div>
  <?php else: ?>
  <div class="orders-list">
    <?php foreach ($orders as $o): $its = json_decode((string)$o['items'], true) ?: []; ?>
      <a class="orow" href="order.php?n=<?= e(rawurlencode((string)$o['number'])) ?>&t=<?= e((string)$o['token']) ?>">
        <span class="orow-main"><b><?= e((string)$o['number']) ?></b><small class="muted"><?= e(date('d.m.Y', strtotime($o['created_at']))) ?> · <?= count($its) ?> <?= plural(count($its), 'позиция', 'позиции', 'позиций') ?></small></span>
        <span class="orow-items"><?= e(mb_strimwidth(implode(', ', array_column($its, 'name')), 0, 90, '…')) ?></span>
        <span class="tag st-<?= e($o['status']) ?>"><?= e($statuses[$o['status']] ?? $o['status']) ?></span>
        <b class="orow-sum"><?= money((float)$o['total']) ?></b>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="acc-grid">
    <?php if (bonus_on()): ?>
    <section class="acc-card" id="bonus">
      <h2 class="acc-h">Баллы</h2>
      <p class="muted">Начисляем <?= e(setting('bonus_percent')) ?>% от суммы выполненного заказа. Баллами можно оплатить до <?= (int)setting('bonus_max_percent') ?>% следующего заказа — галочка в корзине.</p>
      <?php if ($btx): ?><ul class="btx"><?php foreach ($btx as $t): ?><li><span><?= e($t['reason']) ?><small class="muted"><?= e(date('d.m.Y', strtotime($t['created_at']))) ?></small></span><b class="<?= $t['amount'] > 0 ? 'ok-text' : '' ?>"><?= $t['amount'] > 0 ? '+' : '' ?><?= (int)$t['amount'] ?></b></li><?php endforeach; ?></ul>
      <?php else: ?><p class="muted tiny">Пока начислений нет.</p><?php endif; ?>
    </section>
    <?php endif; ?>
    <?php if (setting('ref_enabled') === '1' && bonus_on()): $refLink = rtrim(base_url(), '/') . '/?ref=' . $refCode; ?>
    <section class="acc-card" id="ref">
      <h2 class="acc-h">Приведи друга</h2>
      <p class="muted">Друг получит <b><?= (int)setting('ref_bonus_new') ?></b> баллов после регистрации по вашей ссылке, а вы — <b><?= (int)setting('ref_bonus_owner') ?></b>, когда он получит первый заказ.</p>
      <div class="ref-link"><input readonly value="<?= e($refLink) ?>" aria-label="Ваша ссылка"><button class="btn btn-accent" type="button" data-copy="<?= e($refLink) ?>">Скопировать</button></div>
      <p class="tiny muted">Приглашено: <?= $refCnt ?></p>
    </section>
    <?php endif; ?>
    <?php if (tg_customers_on()): ?>
    <section class="acc-card" id="tg">
      <h2 class="acc-h">Telegram</h2>
      <?php if (!empty($user['tg_chat_id'])): ?>
        <p class="ok-text">Подключён — статусы заказов, промокоды и акции приходят в Telegram.</p>
        <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="act" value="tg_unlink"><button class="btn btn-sm btn-ghost">Отключить</button></form>
      <?php elseif ($tgUrl): ?>
        <p class="muted">Узнавайте о статусе заказа первыми и получайте закрытые промокоды.</p>
        <a class="btn tg-btn" href="<?= e($tgUrl) ?>" target="_blank" rel="noopener" id="tgConnect"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21.5 4.2 2.9 11.4c-1 .4-1 1.9.1 2.2l4.6 1.4 1.8 5.6c.3.8 1.3 1 1.9.4l2.6-2.5 4.8 3.5c.7.5 1.7.1 1.9-.7l3.2-15.4c.2-1-.8-1.8-1.7-1.5z"/></svg>Подключить Telegram</a>
        <p class="tiny muted" id="tgHint" hidden>Нажмите «Start» в Telegram и вернитесь сюда — проверим подключение.</p>
      <?php else: ?><p class="muted tiny">Бот временно недоступен.</p><?php endif; ?>
    </section>
    <?php endif; ?>
  </div>

  <h2 class="acc-h" id="profile">Профиль</h2>
  <form method="post" class="profile-form">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="act" value="profile">
    <div class="fields">
      <label>Имя<input name="name" required value="<?= e($user['name']) ?>" autocomplete="name"></label>
      <label>Телефон<input type="tel" name="phone" value="<?= e((string)$user['phone']) ?>" autocomplete="tel"></label>
      <label>Текущий пароль <small>нужен только для смены пароля</small><input type="password" name="password" autocomplete="current-password"></label>
      <label>Новый пароль<input type="password" name="new_password" minlength="8" autocomplete="new-password"></label>
    </div>
    <button class="btn btn-accent" style="margin-top:14px">Сохранить</button>
  </form>
<?php endif; ?>
</section>
<?php page_foot();
