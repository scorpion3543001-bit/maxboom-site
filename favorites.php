<?php
/** Избранное: у гостей хранится в браузере, у покупателей с кабинетом — ещё и на сервере (видно с любого устройства). */
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$cu = current_user();
page_head('Избранное — ' . setting('shop_name'), 'fav', ['noindex' => true]);
?>
<section class="wrap section fav-page">
  <h1 class="page-title">Избранное</h1>
  <?php if (!$cu): ?><p class="muted">Список хранится в этом браузере. <a class="link" href="account.php">Войдите</a>, чтобы он был доступен на всех устройствах.</p><?php endif; ?>
  <div class="grid" id="favGrid"></div>
  <div class="empty" id="favEmpty" hidden>Здесь пока пусто. Нажимайте ♡ на карточках товаров — понравившееся соберётся здесь. <a href="./#catalog">В каталог</a></div>
</section>
<?php page_foot();
