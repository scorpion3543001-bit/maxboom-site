<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$shop   = setting('shop_name');
$phone  = setting('phone');
$email  = setting('email');
$tg     = ltrim(setting('telegram_contact'), '@');
$addr   = setting('legal_address');
$ld = [
    '@context' => 'https://schema.org', '@type' => 'Store', 'name' => $shop, 'url' => abs_url(''),
    'address'  => ['@type' => 'PostalAddress', 'streetAddress' => $addr, 'addressLocality' => 'Москва', 'addressCountry' => 'RU'],
] + ($phone ? ['telephone' => $phone] : []) + ($email ? ['email' => $email] : []);

page_head('Контакты и реквизиты — ' . $shop, 'contacts', [
    'canonical' => abs_url('contacts.php'),
    'description' => 'Контакты магазина ' . $shop . ': адрес ' . $addr . ', телефон, e-mail, Telegram. Реквизиты продавца — ' . setting('legal_name') . '.',
    'jsonld' => [$ld],
]);
?>
<section class="wrap info-page contacts-page">
  <h1 class="page-title">Контакты</h1>
  <div class="info-grid">
    <div class="info-card">
      <span class="option-ico dlv-pickup"></span>
      <h3>Адрес</h3>
      <p class="plain"><?= e($addr) ?></p>
      <?php if (setting('pickup_hours')): ?><p class="muted"><?= e(setting('pickup_hours')) ?></p><?php endif; ?>
      <p><a class="btn btn-ghost btn-sm" href="https://yandex.ru/maps/?text=<?= rawurlencode($addr) ?>" target="_blank" rel="noopener">Открыть на карте</a></p>
    </div>
    <div class="info-card">
      <span class="option-ico dlv-cdek"></span>
      <h3>Связаться</h3>
      <p class="plain">
        <?php if ($phone): ?><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $phone)) ?>"><?= e($phone) ?></a><br><?php endif; ?>
        <?php if ($email): ?><a href="mailto:<?= e($email) ?>"><?= e($email) ?></a><br><?php endif; ?>
        <?php if ($tg): ?><a href="https://t.me/<?= e($tg) ?>" target="_blank" rel="noopener">Telegram @<?= e($tg) ?></a><?php endif; ?>
        <?php if (!$phone && !$email && !$tg): ?><span class="muted">Пишите нам через форму заказа — ответим в тот же день.</span><?php endif; ?>
      </p>
    </div>
    <div class="info-card">
      <span class="option-ico dlv-post"></span>
      <h3>Мы на маркетплейсах</h3>
      <div class="mp-row">
      <?php foreach (['ozon' => ['link_ozon_store', 'Ozon'], 'wb' => ['link_wb_store', 'Wildberries'], 'ym' => ['link_ym_store', 'Яндекс Маркет']] as $mp => [$k, $n]): if (setting($k) === '') continue; ?>
        <a class="mp-pill mp-<?= $mp ?>" href="<?= e(setting($k)) ?>" target="_blank" rel="noopener"><?= mp_icon($mp) ?><?= $n ?></a>
      <?php endforeach; ?>
      </div>
    </div>
  </div>

  <h2>Реквизиты продавца</h2>
  <dl class="req">
    <dt>Продавец</dt><dd><?= e(setting('legal_name')) ?></dd>
    <?php if (setting('legal_ogrnip')): ?><dt>ОГРНИП</dt><dd><?= e(setting('legal_ogrnip')) ?></dd><?php endif; ?>
    <?php if (setting('legal_inn')): ?><dt>ИНН</dt><dd><?= e(setting('legal_inn')) ?></dd><?php endif; ?>
    <dt>Адрес</dt><dd><?= e($addr) ?></dd>
    <?php if ($email): ?><dt>E-mail</dt><dd><a href="mailto:<?= e($email) ?>"><?= e($email) ?></a></dd><?php endif; ?>
    <?php if ($phone): ?><dt>Телефон</dt><dd><?= e($phone) ?></dd><?php endif; ?>
  </dl>
  <p class="muted small">Информация о продавце размещена в соответствии со ст. 8–9 Закона РФ «О защите прав потребителей» и Правилами продажи товаров дистанционным способом.
    Обращения по качеству товара, возврату и обмену принимаются по указанным контактам; срок ответа — до 10 дней.</p>
</section>
<?php page_foot();
