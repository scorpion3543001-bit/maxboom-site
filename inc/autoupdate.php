<?php
/**
 * v1.007 — автообновление сайта из GitHub.
 * Раз в час (cron) сайт смотрит последний коммит в репозитории и, если он новый,
 * скачивает архив и заменяет файлы. Папка data/ (база, настройки, кэш) не трогается.
 */
declare(strict_types=1);

function upd_http(string $url, string $token = '', ?string $saveTo = null): array
{
    $ch = curl_init($url);
    $h = ['User-Agent: MaxBoom-Site', 'Accept: application/vnd.github+json'];
    if ($token !== '') $h[] = 'Authorization: Bearer ' . $token;
    $fp = null;
    $opts = [CURLOPT_HTTPHEADER => $h, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => 120, CURLOPT_CONNECTTIMEOUT => 15];
    if ($saveTo) { $fp = fopen($saveTo, 'wb'); $opts[CURLOPT_FILE] = $fp; } else $opts[CURLOPT_RETURNTRANSFER] = true;
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($fp) fclose($fp);
    return ['code' => $code, 'body' => is_string($body) ? $body : '', 'error' => $err];
}

/** Распаковать архив сайта поверх текущих файлов. $strip — убрать первую папку (архивы GitHub). */
function site_apply_zip(string $file, bool $strip = false): string
{
    if (!class_exists('ZipArchive')) return 'На хостинге нет модуля zip.';
    $zip = new ZipArchive();
    if ($zip->open($file) !== true) return 'Это не zip-архив.';
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) $names[$i] = str_replace('\\', '/', (string)$zip->getNameIndex($i));
    $prefix = '';
    if ($strip && $names) { $first = reset($names); $prefix = substr($first, 0, (int)strpos($first, '/') + 1); }
    if (!in_array($prefix . 'inc/bootstrap.php', $names, true)) { $zip->close(); return 'В архиве нет файлов сайта MaxBoom.'; }
    // сначала inc/ (кроме bootstrap), потом остальное, bootstrap — последним: сайт не ломается посреди обновления
    uksort($names, function ($a, $b) use ($names, $prefix) {
        $w = fn($n) => $n === $prefix . 'inc/bootstrap.php' ? 2 : (str_starts_with($n, $prefix . 'inc/') ? 0 : 1);
        return $w($names[$a]) <=> $w($names[$b]);
    });
    $n = 0;
    foreach ($names as $i => $full) {
        if ($prefix !== '' && !str_starts_with($full, $prefix)) continue;
        $name = substr($full, strlen($prefix));
        if ($name === '' || str_contains($name, '..') || str_starts_with($name, '/') || preg_match('~^(data|\.git|\.github)(/|$)~', $name)) continue;
        if (preg_match('~(^|/)(README\.md|\.gitignore|\.gitattributes)$~', $name)) continue;
        $dest = MB_ROOT . '/' . $name;
        if (str_ends_with($name, '/')) { if (!is_dir($dest)) @mkdir($dest, 0755, true); continue; }
        if (!is_dir(dirname($dest))) @mkdir(dirname($dest), 0755, true);
        $data = $zip->getFromIndex($i);
        if ($data === false || file_put_contents($dest . '.tmp', $data) === false || !rename($dest . '.tmp', $dest)) { $zip->close(); return 'Не удалось записать ' . $name . '. Проверьте права на папку сайта.'; }
        $n++;
    }
    $zip->close();
    if (function_exists('opcache_reset')) @opcache_reset();
    return 'ok:Обновлено файлов: ' . $n . '.';
}

/** Проверить GitHub и обновить сайт, если есть новый коммит. $force — поставить заново. */
function site_autoupdate(bool $force = false): string
{
    $repo = trim(setting('update_repo'));
    if ($repo === '' || setting('update_auto') !== '1' && !$force) return '';
    if (!preg_match('~^[\w.-]+/[\w.-]+$~', $repo)) return 'Автообновление: неверное имя репозитория.';
    $branch = setting('update_branch') ?: 'main';
    $token = setting('update_token');
    $r = upd_http("https://api.github.com/repos/$repo/commits/" . rawurlencode($branch), $token);
    if ($r['code'] !== 200) { setting_set('update_last_check', now() . ' — ошибка ' . ($r['code'] ?: $r['error'])); return 'Автообновление: GitHub ответил ' . ($r['code'] ?: $r['error']); }
    $d = json_decode($r['body'], true);
    $sha = (string)($d['sha'] ?? '');
    setting_set('update_last_check', now());
    if ($sha === '' || (!$force && $sha === setting('update_sha'))) return '';
    $tmp = MB_DATA . '/update.zip';
    $z = upd_http("https://api.github.com/repos/$repo/zipball/$sha", $token, $tmp);
    if ($z['code'] !== 200 || !is_file($tmp) || filesize($tmp) < 1000) { @unlink($tmp); return 'Автообновление: не скачался архив (' . ($z['code'] ?: $z['error']) . ').'; }
    $res = site_apply_zip($tmp, true);
    @unlink($tmp);
    if (!str_starts_with($res, 'ok:')) return 'Автообновление: ' . $res;
    setting_set('update_sha', $sha);
    setting_set('update_last', now() . ' · ' . substr($sha, 0, 7) . ' · ' . mb_substr(trim((string)($d['commit']['message'] ?? '')), 0, 120));
    page_cache_clear();
    try { db()->exec('DELETE FROM kv_cache'); } catch (Throwable $e) {}
    return 'Сайт обновлён из GitHub: ' . substr($res, 3);
}
