<?php
/**
 * v1.005 — варианты товара по цвету и подборки для баннеров.
 *
 * Одинаковые товары разных цветов объединяются в одну карточку:
 *  1) по модели Ozon (model_info.model_id) — если в модели больше одного товара;
 *  2) иначе по одинаковому названию без слов-цветов (в пределах категории).
 * Цвет берётся из атрибутов Ozon («Название цвета», «Цвет товара»), а если их нет — из названия.
 */
declare(strict_types=1);

require_once __DIR__ . '/catalog.php';

/** Основа слова цвета => [название, hex]. */
const MB_COLOR_WORDS = [
    'черн' => ['Чёрный', '#1f1f23'], 'чёрн' => ['Чёрный', '#1f1f23'], 'бел' => ['Белый', '#f7f7f5'], 'красн' => ['Красный', '#e5322d'],
    'син' => ['Синий', '#2457d6'], 'голуб' => ['Голубой', '#5ec4ff'], 'зелен' => ['Зелёный', '#2fb34a'], 'зелён' => ['Зелёный', '#2fb34a'],
    'желт' => ['Жёлтый', '#ffd21f'], 'жёлт' => ['Жёлтый', '#ffd21f'], 'оранжев' => ['Оранжевый', '#ff8a1f'],
    'розов' => ['Розовый', '#ff7eb6'], 'фиолетов' => ['Фиолетовый', '#8a4dff'], 'сер' => ['Серый', '#9aa0a8'],
    'коричнев' => ['Коричневый', '#7a4a2a'], 'бежев' => ['Бежевый', '#e3cfae'], 'бирюзов' => ['Бирюзовый', '#1fd1c1'],
    'мятн' => ['Мятный', '#8ef0c8'], 'сиренев' => ['Сиреневый', '#c59cff'], 'лилов' => ['Лиловый', '#b469e8'],
    'бордов' => ['Бордовый', '#8c1c34'], 'салатов' => ['Салатовый', '#a6e84a'], 'золот' => ['Золотой', '#e0b43c'],
    'серебрян' => ['Серебряный', '#c9ced6'], 'серебрист' => ['Серебристый', '#c9ced6'], 'прозрачн' => ['Прозрачный', '#e8f6ff'],
    'рыж' => ['Рыжий', '#f07a22'], 'графитов' => ['Графитовый', '#3e4248'], 'молочн' => ['Молочный', '#f4efe2'],
    'кремов' => ['Кремовый', '#f3e3bf'], 'малинов' => ['Малиновый', '#d81b60'], 'персиков' => ['Персиковый', '#ffb48a'],
    'изумрудн' => ['Изумрудный', '#0f9d6e'], 'лазурн' => ['Лазурный', '#2fa8ff'], 'пудров' => ['Пудровый', '#efc6c6'],
    'разноцветн' => ['Разноцветный', ''], 'мультиколор' => ['Мультиколор', ''], 'хаки' => ['Хаки', '#7d7a4a'],
    'терракотов' => ['Терракотовый', '#c4623f'], 'оливков' => ['Оливковый', '#7f8a3a'], 'песочн' => ['Песочный', '#d9c08f'],
    'небесн' => ['Небесный', '#86ccff'], 'шоколадн' => ['Шоколадный', '#5a3622'], 'горчичн' => ['Горчичный', '#d1a523'],
    'неонов' => ['Неоновый', '#39ff88'], 'перламутров' => ['Перламутровый', '#eae4f2'], 'медн' => ['Медный', '#c27a45'],
];
const MB_COLOR_END = '(?:ый|ий|ой|ая|яя|ое|ее|ые|ие|ого|его|ую|юю|ых|их|ым|им|ыми|ими)';

/** Регулярка «слово-цвет» (в т.ч. составные: «черно-голубой»). */
function color_regex(): string
{
    static $re = null;
    if ($re) return $re;
    $stems = array_keys(MB_COLOR_WORDS);
    usort($stems, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    $alt = implode('|', array_map(fn($s) => preg_quote($s, '/'), $stems));
    // составной цвет: «черно-голубой», «красно-белый»
    $re = '/(?<![а-яёa-z])((?:' . $alt . ')[оe]?-(?:' . $alt . ')' . MB_COLOR_END . '|(?:' . $alt . ')' . MB_COLOR_END . '|хаки|мультиколор)(?![а-яёa-z])/iu';
    return $re;
}

/** Найти цвет в строке. Возвращает [название как в тексте (с заглавной), hex] или null. */
function color_detect(string $s): ?array
{
    if (!preg_match(color_regex(), mb_strtolower($s), $m)) return null;
    $word = $m[1];
    $hex = '';
    foreach (MB_COLOR_WORDS as $stem => [$label, $h]) {
        if (str_starts_with($word, $stem)) { $hex = $h; if (!str_contains($word, '-')) return [$label, $h]; break; }
    }
    // составной цвет — показываем как написано, градиент из двух цветов
    $parts = explode('-', $word);
    $hex2 = '';
    foreach (MB_COLOR_WORDS as $stem => [, $h]) if (isset($parts[1]) && str_starts_with($parts[1], $stem)) { $hex2 = $h; break; }
    return [mb_strtoupper(mb_substr($word, 0, 1)) . mb_substr($word, 1), $hex && $hex2 ? $hex . ',' . $hex2 : $hex];
}

/** Цвет из произвольного названия цвета Ozon: «черный матовый» → [«Черный матовый», hex]. */
function color_from_label(string $label): array
{
    $label = trim(preg_replace('/\s+/u', ' ', $label));
    $d = color_detect($label);
    $nice = mb_strtoupper(mb_substr($label, 0, 1)) . mb_substr($label, 1);
    return [$nice, $d[1] ?? ''];
}

/** Название без слов-цветов — для объединения одинаковых товаров. */
function name_base(string $name): string
{
    $s = mb_strtolower($name);
    $s = preg_replace(color_regex(), ' ', $s);
    $s = preg_replace('/\((?:[^()]*)\)/u', ' ', $s);        // скобки: (PLA), (черный)
    $s = preg_replace('/[^а-яёa-z0-9]+/u', ' ', $s);
    return trim(preg_replace('/\s+/u', ' ', $s));
}

/**
 * Пересобрать группы вариантов и цвета. Ручной цвет (color_src = 'manual') не трогается.
 */
function variants_rebuild(?PDO $pdo = null): string
{
    $pdo = $pdo ?: db();
    $rows = $pdo->query('SELECT id, name, type_id, model_id, color, color_hex, color_src FROM products WHERE archived = 0')->fetchAll();
    $byModel = []; $byName = [];
    $colorUpd = $pdo->prepare('UPDATE products SET color = ?, color_hex = ?, color_src = ? WHERE id = ?');
    $pdo->beginTransaction();
    foreach ($rows as &$r) {
        if (($r['color_src'] ?? '') !== 'manual' && ($r['color_src'] ?? '') !== 'ozon') {
            $d = color_detect((string)$r['name']);
            $c = $d[0] ?? null; $h = $d[1] ?? null;
            if ($c !== $r['color'] || $h !== $r['color_hex']) $colorUpd->execute([$c, $h, $c ? 'name' : null, $r['id']]);
            $r['color'] = $c;
        }
        if (!empty($r['model_id'])) $byModel[(int)$r['model_id']][] = (int)$r['id'];
        $byName[(int)$r['type_id'] . '|' . name_base((string)$r['name'])][] = (int)$r['id'];
    }
    unset($r);
    $group = [];
    foreach ($byModel as $mid => $ids) if (count($ids) > 1) foreach ($ids as $id) $group[$id] = 'm' . $mid;
    foreach ($byName as $key => $ids) {
        $ids = array_values(array_filter($ids, fn($id) => !isset($group[$id])));
        if (count($ids) > 1) foreach ($ids as $id) $group[$id] = 'n' . substr(md5($key), 0, 12);
    }
    $pdo->exec('UPDATE products SET group_key = NULL WHERE group_key IS NOT NULL');
    $st = $pdo->prepare('UPDATE products SET group_key = ? WHERE id = ?');
    foreach ($group as $id => $g) $st->execute([$g, $id]);
    $pdo->commit();
    $groups = count(array_unique($group));
    return "Вариантов по цвету: {$groups} " . plural($groups, 'группа', 'группы', 'групп') . ' (' . count($group) . ' товаров).';
}

/** Все видимые варианты товара (включая сам товар), по порядку. Пусто — если вариантов нет. */
function variants_of(array $p): array
{
    static $cache = [];
    $g = (string)($p['group_key'] ?? '');
    if ($g === '') return [];
    if (!isset($cache[$g])) {
        $st = db()->prepare('SELECT * FROM products WHERE group_key = ? AND ' . visible_where() . ' ORDER BY id');
        $st->execute([$g]);
        $cache[$g] = $st->fetchAll();
    }
    return count($cache[$g]) > 1 ? $cache[$g] : [];
}

/** Подпись варианта: цвет или «Вариант N». */
function variant_label(array $v, int $i): string
{
    $c = trim((string)($v['color'] ?? ''));
    return $c !== '' ? $c : 'Вариант ' . ($i + 1);
}

/** CSS-фон кружка цвета (один цвет или два для составных). */
function swatch_bg(array $v): string
{
    $h = (string)($v['color_hex'] ?? '');
    if ($h === '') return '';
    $parts = array_values(array_filter(explode(',', $h), fn($x) => preg_match('/^#[0-9a-f]{6}$/i', $x)));
    if (!$parts) return '';
    return count($parts) > 1 ? "linear-gradient(135deg,{$parts[0]} 50%,{$parts[1]} 50%)" : $parts[0];
}

/** Название товара с цветом — для корзины и заказа. */
function product_title_full(array $p): string
{
    $c = trim((string)($p['color'] ?? ''));
    if ($c === '' || empty($p['group_key'])) return (string)$p['name'];
    // цвет уже есть в названии («…, розовое») — не дублируем
    if (mb_stripos((string)$p['name'], $c) !== false || color_detect((string)$p['name']) !== null) return (string)$p['name'];
    return $p['name'] . ', ' . mb_strtolower($c);
}

/* ---------- Подборки для баннеров ---------- */

/** Палитры баннеров. */
const MB_BANNER_THEMES = [
    'sunset' => 'Закат', 'lime' => 'Лайм', 'violet' => 'Фиолетовый', 'candy' => 'Конфета', 'ocean' => 'Океан',
    'lemon' => 'Лимон', 'mint' => 'Мятный', 'cyan' => 'Неон голубой', 'pink' => 'Розовый', 'fire' => 'Огонь',
    'newyear' => 'Новогодний',
];

/**
 * Темы подборок: поиск по словам в названии. Первые подходящие (где есть товары) идут в баннеры.
 * Заголовок/текст — живые, для покупателя.
 */
const MB_COLLECTIONS = [
    ['key' => 'god-kozy-2027', 'q' => 'козел|козёл|козл|коза|козоч|козы', 'title' => 'Год Козы 2027', 'kicker' => 'Новый год 2027', 'theme' => 'newyear',
     'text' => 'Символ года — в каждый дом: козлики-кашпо, фигурки и копилки. Подарок, который принесёт удачу.', 'btn' => 'Выбрать символ года'],
    ['key' => 'figurka-konvert', 'q' => 'конверт|для денег|копилк', 'title' => 'Фигурка — подарочный конверт', 'kicker' => 'Дарите деньги красиво',
     'text' => 'Коты, собачки и козлики с тайником для купюр. Положите деньги внутрь — и подарок готов.'],
    ['key' => 'kashpo', 'q' => 'кашпо', 'title' => 'Кашпо для цветов', 'kicker' => 'Для дома и сада', 'text' => 'Котики, козлики и корзинки — растениям тоже нужен характер.'],
    ['key' => 'breloki-shlemy', 'q' => 'брелок шлем', 'title' => 'Брелоки-шлемы', 'kicker' => 'Не тонут в воде', 'text' => 'Мини-шлемы для ключей во всех цветах. Ключи всегда на виду.'],
    ['key' => 'antistress', 'q' => 'антистресс', 'title' => 'Антистресс', 'kicker' => 'Тянуть, мять, крутить', 'text' => 'Фигурки и брелоки, которые успокаивают лучше любых слов.'],
    ['key' => 'organayzery', 'q' => 'органайзер', 'title' => 'Органайзеры', 'kicker' => 'Порядок за минуту', 'text' => 'Для стола, ванной и прихожей. Всё на своих местах.'],
    ['key' => 'podstavki-ochki', 'q' => 'подставка очков', 'title' => 'Подставки для очков', 'kicker' => 'Совы и котики', 'text' => 'Очки больше не теряются — их сторожит пернатый друг.'],
    ['key' => 'pasha', 'q' => 'пасхальная', 'title' => 'Пасхальные подставки', 'kicker' => 'К празднику', 'text' => 'Подставки для яиц и зайчики — стол сразу становится праздничным.'],
    ['key' => 'figurki', 'q' => 'фигурка', 'title' => 'Фигурки', 'kicker' => 'Для полки и стола', 'text' => 'Коты, драконы и статуэтки, которые поднимают настроение.'],
    ['key' => 'derzhateli', 'q' => 'держатель', 'title' => 'Держатели', 'kicker' => 'Удобно и надёжно', 'text' => 'Для телефона, книг, бритвы и всего, что должно быть под рукой.'],
    ['key' => 'breloki', 'q' => 'брелок', 'title' => 'Брелоки', 'kicker' => 'На ключи и рюкзак', 'text' => 'Яркие брелоки на любой вкус — от шлемов до осьминогов.'],
    ['key' => 'veshalki', 'q' => 'вешалка', 'title' => 'Вешалки и крючки', 'kicker' => 'Для прихожей', 'text' => 'Аккуратно, прочно и без лишнего сверления.'],
];

/** Подборка по ключу из адреса (?col=…). */
function collection_by_key(string $key): ?array
{
    foreach (MB_COLLECTIONS as $c) if ($c['key'] === $key) return $c;
    return null;
}

/** Подборки, в которых сейчас есть товары (до $n штук), с фото. Кэш 30 минут. */
function collections_live(int $n = 7): array
{
    $key = 'collections_v2_' . $n;
    if (($c = kv_get($key)) !== null) return $c;
    $out = []; $usedIds = [];
    foreach (MB_COLLECTIONS as $c) {
        [$items] = catalog_query($c['q'], 'new', 1, 6);                  // разные модели
        [$all, $total] = catalog_query($c['q'], 'new', 1, 60, 0, false);  // все товары, включая цвета
        // товары не должны повторять предыдущие подборки (брелоки после брелоков-шлемов)
        $fresh = array_filter($all, fn($p) => !isset($usedIds[(int)$p['id']]));
        if ($total < 2 || count($fresh) < 2) continue;
        $imgs = [];
        foreach (array_merge($items, $all) as $p) {
            $im = product_image($p);
            if ($im !== '' && !in_array($im, $imgs, true)) $imgs[] = $im;
            if (count($imgs) >= 3) break;
        }
        foreach ($all as $p) $usedIds[(int)$p['id']] = 1;
        $out[] = $c + ['count' => $total, 'images' => $imgs];
        if (count($out) >= $n) break;
    }
    // если подборок мало — добавляем крупные категории
    if (count($out) < $n) {
        foreach (categories_visible() as $cat) {
            if (count($out) >= $n) break;
            $st = db()->prepare('SELECT images FROM products WHERE type_id = ? AND ' . visible_where() . " AND images LIKE '%http%' ORDER BY id DESC LIMIT 3");
            $st->execute([$cat['id']]);
            $imgs = array_values(array_filter(array_map(fn($r) => json_decode((string)$r, true)[0] ?? '', $st->fetchAll(PDO::FETCH_COLUMN))));
            $out[] = ['q' => '', 'cat' => (int)$cat['id'], 'title' => $cat['title'], 'kicker' => 'Категория',
                      'text' => 'Собственное производство, отправка по всей России.', 'count' => (int)$cat['cnt'], 'images' => $imgs];
        }
    }
    kv_set($key, $out, 1800);
    return $out;
}

/** Ссылка на подборку: поиск по словам + заголовок. */
function collection_url(array $c): string
{
    if (!empty($c['cat'])) return category_url((int)$c['cat'], (string)$c['title']);
    if (!empty($c['key'])) return './?col=' . rawurlencode($c['key']) . '#catalog';
    return './?' . http_build_query(['q' => $c['q'], 't' => $c['title']]) . '#catalog';
}

/** Баннеры по умолчанию: 7 ярких подборок. */
function banners_default(): array
{
    $themes = array_keys(MB_BANNER_THEMES);
    $list = [];
    foreach (collections_live(7) as $i => $c) {
        $list[] = [
            'on' => 1, 'kicker' => $c['kicker'], 'count' => (int)$c['count'],
            'title' => $c['title'], 'text' => $c['text'], 'btn' => $c['btn'] ?? 'Смотреть подборку',
            'link' => collection_url($c), 'theme' => $c['theme'] ?? $themes[$i % 7], 'image' => '', 'cat' => (int)($c['cat'] ?? 0),
            'q' => (string)$c['q'], 'images' => $c['images'],
        ];
    }
    if (!$list) {
        $list[] = ['on' => 1, 'kicker' => 'Собственное производство', 'title' => 'Полезные вещи из кукурузного пластика',
                   'text' => 'Делаем сами из безопасного пластика PLA растительного происхождения.', 'btn' => 'Смотреть каталог',
                   'link' => '#catalog', 'theme' => 'cyan', 'image' => '', 'cat' => 0, 'q' => '', 'images' => []];
    }
    return $list;
}

/** Фото товаров для баннера: по подборке (словам), категории или случайные. */
function banner_images(int $cat = 0, int $n = 3, string $q = ''): array
{
    if ($q !== '') {
        [$items] = catalog_query($q, 'new', 1, $n);
        return array_values(array_filter(array_map('product_image', $items)));
    }
    $where = visible_where() . " AND images LIKE '%http%'" . ($cat > 0 ? ' AND type_id = ' . $cat : '');
    $rows = db()->query("SELECT images FROM products WHERE $where ORDER BY RANDOM() LIMIT " . (int)$n)->fetchAll(PDO::FETCH_COLUMN);
    $out = [];
    foreach ($rows as $r) { $a = json_decode($r, true); if (!empty($a[0])) $out[] = $a[0]; }
    return $out;
}
