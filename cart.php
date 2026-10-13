<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/payment.php';

$methods = delivery_methods();
$err = $_SESSION['checkout_error'] ?? '';
$old = $_SESSION['checkout_old'] ?? [];
unset($_SESSION['checkout_error'], $_SESSION['checkout_old']);
$canPayOnline = yk_enabled();
$canPayOnSite = setting('pickup_pay_on_site') === '1' && isset($methods['pickup']);
$cu = current_user();
if ($cu) $old += ['name' => $cu['name'], 'phone' => (string)$cu['phone'], 'email' => $cu['email']];
$bonusBal = ($cu && bonus_on()) ? bonus_balance((int)$cu['id']) : 0;
$cdekApi = isset($methods['cdek']) && cdek_ready();
$postApi = isset($methods['post']) && post_ready();

page_head('Корзина — ' . setting('shop_name'), 'cart', ['noindex' => true]);
?>
<section class="wrap cart-page">
  <h1 class="page-title">Корзина</h1>
  <?php if ($err): ?><div class="alert"><?= e($err) ?></div><?php endif; ?>

  <div class="cart-empty empty" id="cartEmpty" hidden>
    В корзине пока пусто. <a href="./">Перейти в каталог</a>
  </div>

  <form class="cart-layout" id="checkoutForm" method="post" action="checkout.php" hidden novalidate>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="items" id="itemsField">
    <input type="hidden" name="coupon" id="couponField" value="<?= e((string)($old['coupon'] ?? '')) ?>">
    <input type="hidden" name="gift" id="giftField" value="<?= e((string)($old['gift'] ?? '')) ?>">

    <div class="cart-left">
      <div class="freebar" id="freeBar" hidden><span id="freeText"></span><div class="freebar-track"><div class="freebar-fill" id="freeFill" style="width:0"></div></div></div>
      <div class="cart-list" id="cartList"></div>

      <h2 class="step"><span>1</span>Получатель</h2>
      <div class="fields">
        <label>Имя и фамилия<input name="name" required maxlength="100" value="<?= e($old['name'] ?? '') ?>" autocomplete="name"></label>
        <label>Телефон<input name="phone" type="tel" required maxlength="30" value="<?= e($old['phone'] ?? '') ?>" placeholder="+7 900 000-00-00" autocomplete="tel"></label>
        <label class="full">E-mail <small>для чека и статуса заказа</small><input name="email" type="email" maxlength="120" value="<?= e($old['email'] ?? '') ?>" autocomplete="email"></label>
      </div>

      <h2 class="step"><span>2</span>Получение</h2>
      <div class="options" id="dlvOptions">
        <?php $first = true; foreach ($methods as $k => $m): $checked = ($old['delivery'] ?? null) ? ($old['delivery'] === $k) : $first; $first = false; ?>
        <label class="option">
          <?php $api = ($k === 'cdek' && $cdekApi) ? 'cdek' : (($k === 'post' && $postApi) ? 'post' : ''); ?>
          <input type="radio" name="delivery" value="<?= $k ?>" data-price="<?= $m['price'] ?>" data-addr="<?= $m['need_address'] && $api !== 'cdek' ? 1 : 0 ?>" data-api="<?= $api ?>" <?= $checked ? 'checked' : '' ?>>
          <span class="option-box">
            <span class="option-ico dlv-<?= $k ?>"></span>
            <span class="option-text"><b><?= e($m['title']) ?></b><small><?= e($m['hint']) ?><?= $k === 'pickup' && setting('pickup_hours') ? ' · ' . e(setting('pickup_hours')) : '' ?></small></span>
            <span class="option-price" data-dlv-price="<?= $k ?>"><?= $api ? 'по тарифу' : ($m['price'] > 0 ? money($m['price']) : 'бесплатно') ?></span>
          </span>
        </label>
        <?php endforeach; ?>
      </div>
      <?php if ($cdekApi): ?>
      <div class="dlv-api" id="cdekBox" hidden>
        <div class="fields">
          <label class="full city-pick">Город<input id="cdekCity" autocomplete="off" placeholder="Начните вводить: Москва, Казань…" value="<?= e((string)($old['city_name'] ?? '')) ?>"><div class="search-drop" id="cdekCityDrop" hidden></div></label>
        </div>
        <input type="hidden" name="city_code" value="<?= e((string)($old['city_code'] ?? '')) ?>"><input type="hidden" name="city_name" value="<?= e((string)($old['city_name'] ?? '')) ?>">
        <input type="hidden" name="pvz" value="<?= e((string)($old['pvz'] ?? '')) ?>"><input type="hidden" name="pvz_addr" value="<?= e((string)($old['pvz_addr'] ?? '')) ?>">
        <div class="pvz-wrap" id="pvzWrap" hidden>
          <div class="pvz-tools"><input id="pvzSearch" placeholder="Поиск по адресу или названию" autocomplete="off"><span class="muted tiny" id="pvzCount"></span></div>
          <?php if (setting('yandex_maps_key') !== ''): ?><div class="pvz-map" id="pvzMap" data-key="<?= e(setting('yandex_maps_key')) ?>"></div><?php endif; ?>
          <div class="pvz-list" id="pvzList" role="listbox" aria-label="Пункты выдачи СДЭК"></div>
        </div>
        <div class="pvz-chosen" id="pvzChosen" hidden></div>
      </div>
      <?php endif; ?>
      <?php if ($postApi): ?>
      <div class="dlv-api" id="postBox" hidden>
        <div class="fields"><label>Почтовый индекс<input name="post_index" inputmode="numeric" maxlength="6" pattern="\d{6}" placeholder="101000" value="<?= e((string)($old['post_index'] ?? '')) ?>"></label><p class="muted tiny dlv-eta" id="postEta"></p></div>
      </div>
      <?php endif; ?>
      <div class="fields" id="addrBlock">
        <label class="full"><span id="addrLabel">Город и адрес пункта выдачи / отделения</span><textarea name="address" rows="2" maxlength="400" placeholder="Например: Москва, ПВЗ СДЭК ул. Ленина, 10"><?= e($old['address'] ?? '') ?></textarea></label>
      </div>
      <p class="dlv-msg" id="dlvMsg" hidden></p>

      <h2 class="step"><span>3</span>Оплата</h2>
      <div class="options">
        <?php if ($canPayOnline): ?>
        <label class="option"><input type="radio" name="payment" value="online" <?= ($old['payment'] ?? 'online') === 'online' ? 'checked' : '' ?>>
          <span class="option-box"><span class="option-ico pay-card"></span><span class="option-text"><b>Онлайн картой или СБП</b><small>Через ЮKassa, сразу после оформления</small></span></span></label>
        <?php endif; ?>
        <?php if ($canPayOnSite): ?>
        <label class="option" id="payOnSite"><input type="radio" name="payment" value="onsite" <?= (!$canPayOnline || ($old['payment'] ?? '') === 'onsite') ? 'checked' : '' ?>>
          <span class="option-box"><span class="option-ico pay-cash"></span><span class="option-text"><b>При получении в магазине</b><small>Только для самовывоза</small></span></span></label>
        <?php endif; ?>
        <?php if (!$canPayOnline && !$canPayOnSite): ?>
          <input type="hidden" name="payment" value="manual">
          <p class="muted">После оформления мы свяжемся с вами и пришлём ссылку на оплату.</p>
        <?php endif; ?>
      </div>

      <label class="full comment">Комментарий к заказу<textarea name="comment" rows="2" maxlength="500"><?= e($old['comment'] ?? '') ?></textarea></label>
    </div>

    <aside class="summary">
      <div class="summary-box">
        <div class="sum-row"><span>Товары (<span id="sumCount">0</span>)</span><b id="sumItems">0 ₽</b></div>
        <div class="sum-row sum-disc" id="sumDiscRow" hidden><span>Скидки</span><b id="sumDisc"></b></div>
        <div class="sum-row sum-disc" id="sumBonusRow" hidden><span>Баллами</span><b id="sumBonus"></b></div>
        <div class="sum-row"><span>Доставка<small class="muted" id="sumEta"></small></span><b id="sumDlv">0 ₽</b></div>
        <div class="sum-row sum-disc" id="sumGiftRow" hidden><span>Сертификат</span><b id="sumGift"></b></div>
        <div class="sum-total"><span>Итого</span><b id="sumTotal">0 ₽</b></div>
        <div class="promo">
          <input id="promoInput" placeholder="Промокод" autocomplete="off" value="<?= e((string)($old['coupon'] ?? '')) ?>" aria-label="Промокод">
          <button class="btn" type="button" id="promoBtn">Применить</button>
        </div>
        <div class="promo-msg" id="promoMsg"></div>
        <?php if (gift_on()): ?>
        <details class="gift-in" <?= !empty($old['gift']) ? 'open' : '' ?>><summary>У меня есть подарочный сертификат</summary>
          <div class="promo"><input id="giftInput" placeholder="GIFT-XXXX-XXXX" autocomplete="off" value="<?= e((string)($old['gift'] ?? '')) ?>" aria-label="Код сертификата"><button class="btn" type="button" id="giftBtn">Применить</button></div>
          <div class="promo-msg" id="giftMsg"></div>
        </details>
        <?php endif; ?>
        <?php if ($bonusBal > 0): ?>
        <label class="bonus-use"><input type="checkbox" name="use_bonus" value="1" id="useBonus" <?= !empty($old['use_bonus']) ? 'checked' : '' ?>><span>Списать баллы <small class="muted">у вас <?= (int)$bonusBal ?>, можно до <span id="bonusMax">—</span></small></span></label>
        <?php elseif ($cu && bonus_on() && (float)setting('bonus_percent') > 0): ?>
        <p class="tiny muted">За этот заказ начислим <span id="bonusEarn">—</span> баллов после получения.</p>
        <?php endif; ?>
        <button class="btn btn-accent btn-lg btn-block" type="submit" id="submitBtn">Оформить заказ</button>
        <?php if (!$cu): ?><p class="tiny muted login-hint"><a class="link" href="account.php?back=cart">Войдите</a>, чтобы видеть историю заказов и получать персональные скидки.</p>
        <?php elseif ((float)$cu['discount_percent'] > 0): ?><p class="tiny login-hint ok-text">Ваша персональная скидка −<?= e(rtrim(rtrim(number_format((float)$cu['discount_percent'], 1, ',', ''), '0'), ',')) ?>% уже учтена.</p><?php endif; ?>
        <p class="tiny muted">Нажимая кнопку, вы соглашаетесь на обработку персональных данных для выполнения заказа.</p>
      </div>
    </aside>
  </form>
</section>
<script>
window.MB_CHECKOUT = {
  freeFrom: <?= (float)setting('free_delivery_from') ?>,
  minOrder: <?= (float)setting('min_order') ?>,
  bonusPercent: <?= bonus_on() && $cu ? (float)setting('bonus_percent') : 0 ?>
};
</script>
<?php page_foot();
