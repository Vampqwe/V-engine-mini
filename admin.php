<?php
declare(strict_types=1);

$isSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_name('pages_admin');
session_set_cookie_params([
    'httponly' => true,
    'secure' => $isSecure,
    'samesite' => 'Strict',
    'path' => '/',
]);
session_start();

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

$clientAddress = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($clientAddress, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Доступ к панели разрешён только с локального компьютера.');
}

$pagesDir = __DIR__ . '/pages';
$routesFile = __DIR__ . '/routes.json';
$aliasesFile = __DIR__ . '/route_aliases.json';

function escapeHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirectToPanel(): never
{
    header('Location: /admin.php', true, 303);
    exit;
}

function setFlash(string $message, string $kind = 'success'): void
{
    $_SESSION['admin_flash'] = ['message' => $message, 'kind' => $kind];
}

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function csrfToken(): string
{
    if (!isset($_SESSION['admin_csrf'])) {
        $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['admin_csrf'];
}

function checkCsrf(): bool
{
    return isset($_POST['csrf'], $_SESSION['admin_csrf'])
        && is_string($_POST['csrf'])
        && hash_equals($_SESSION['admin_csrf'], $_POST['csrf']);
}

function loadJsonFile(string $path): array
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Не удалось прочитать файл ' . basename($path));
    }
    $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new RuntimeException('Некорректный формат ' . basename($path));
    }
    return $data;
}

function writeJsonFile(string $path, array $data): void
{
    $temporaryPath = tempnam(dirname($path), '.admin-');
    if ($temporaryPath === false) {
        throw new RuntimeException('Не удалось создать временный файл.');
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($temporaryPath, $json, LOCK_EX) === false || !rename($temporaryPath, $path)) {
        @unlink($temporaryPath);
        throw new RuntimeException('Не удалось сохранить настройки.');
    }
}

function htmlFiles(string $root): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'html') {
            $files[] = $file->getPathname();
        }
    }
    sort($files, SORT_STRING);
    return $files;
}

function replaceIsoDate(string $value, string $date): string
{
    return preg_replace('/^\d{4}-\d{2}-\d{2}/', $date, $value, 1) ?? $value;
}

function updateDateMetadata(string $html, string $date): array
{
    $updatedFields = 0;
    $html = preg_replace_callback('~<meta\b[^>]*>~i', static function (array $tagMatch) use ($date, &$updatedFields): string {
        if (!preg_match('~\b(?:property|name)\s*=\s*(["\'])article:(?:published|modified)_time\1~i', $tagMatch[0])) {
            return $tagMatch[0];
        }
        return preg_replace_callback('~\bcontent\s*=\s*(["\'])(.*?)\1~i', static function (array $contentMatch) use ($date, &$updatedFields): string {
            $updated = replaceIsoDate($contentMatch[2], $date);
            if ($updated !== $contentMatch[2]) {
                $updatedFields++;
            }
            return 'content=' . $contentMatch[1] . $updated . $contentMatch[1];
        }, $tagMatch[0], 1) ?? $tagMatch[0];
    }, $html) ?? $html;

    $html = preg_replace_callback('~(<script\b(?=[^>]*\btype\s*=\s*["\']application/ld\+json["\'])[^>]*>)(.*?)(</script\s*>)~is', static function (array $scriptMatch) use ($date, &$updatedFields): string {
        $json = preg_replace_callback('~("(?:datePublished|dateModified|lastReviewed)"\s*:\s*")(\d{4}-\d{2}-\d{2})([^"]*)(")~', static function (array $dateMatch) use ($date, &$updatedFields): string {
            if ($dateMatch[2] !== $date) {
                $updatedFields++;
            }
            return $dateMatch[1] . $date . $dateMatch[3] . $dateMatch[4];
        }, $scriptMatch[2]) ?? $scriptMatch[2];
        return $scriptMatch[1] . $json . $scriptMatch[3];
    }, $html) ?? $html;

    return [$html, $updatedFields];
}

function updatePhoneNumbers(string $html, string $displayPhone, string $telPhone): array
{
    $updatedNumbers = 0;
    $html = preg_replace_callback('~\+\d[\d\s().-]*\d~u', static function (array $match) use ($displayPhone, &$updatedNumbers): string {
        $digits = preg_replace('/\D+/', '', $match[0]) ?? '';
        if (strlen($digits) < 8 || strlen($digits) > 15) {
            return $match[0];
        }
        if ($match[0] !== $displayPhone) {
            $updatedNumbers++;
        }
        return $displayPhone;
    }, $html) ?? $html;

    $html = preg_replace_callback('~\bhref\s*=\s*(["\'])tel:[^"\']*\1~i', static function (array $match) use ($telPhone, &$updatedNumbers): string {
        $replacement = 'href=' . $match[1] . 'tel:' . $telPhone . $match[1];
        if ($replacement !== $match[0]) {
            $updatedNumbers++;
        }
        return $replacement;
    }, $html) ?? $html;

    return [$html, $updatedNumbers];
}

function writeChangedFile(string $path, string $contents): void
{
    if (file_put_contents($path, $contents, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось записать файл: ' . basename($path));
    }
}

function importRelativePath(string $uploadedPath, string $fallbackName): ?string
{
    $path = str_replace('\\', '/', $uploadedPath !== '' ? $uploadedPath : $fallbackName);
    if ($path === '' || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) || str_contains($path, "\0")) {
        return null;
    }
    $segments = array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== ''));
    if (count($segments) > 1) {
        array_shift($segments);
    }
    if (!$segments || strtolower(pathinfo(end($segments), PATHINFO_EXTENSION)) !== 'html') {
        return null;
    }
    foreach ($segments as $segment) {
        if ($segment === '.' || $segment === '..' || preg_match('/[\x00-\x1F]/', $segment)) {
            return null;
        }
    }
    return implode(DIRECTORY_SEPARATOR, $segments);
}

function rebuildRouteMap(): array
{
    $command = [PHP_BINARY, __DIR__ . '/build_map.php'];
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__);
    if (!is_resource($process)) {
        return ['ok' => false, 'output' => 'Не удалось запустить build_map.php.'];
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    return ['ok' => $status === 0, 'output' => trim($output)];
}

function validRoutePath(string $path): bool
{
    if ($path === '/') {
        return true;
    }
    return preg_match('#^/[a-zA-Z0-9._~%/-]+$#', $path) === 1
        && !str_contains($path, '//')
        && !str_ends_with($path, '/')
        && !in_array('.', explode('/', $path), true)
        && !in_array('..', explode('/', $path), true);
}

function sendFlashAndRedirect(string $message, string $kind = 'success'): never
{
    setFlash($message, $kind);
    redirectToPanel();
}

$flash = $_SESSION['admin_flash'] ?? null;
unset($_SESSION['admin_flash']);
$csrf = csrfToken();
$isAjaxImport = ($_POST['action'] ?? '') === 'import_html'
    && str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkCsrf()) {
        if ($isAjaxImport) {
            jsonResponse(['ok' => false, 'error' => 'Сессия истекла. Обновите страницу и повторите импорт.'], 403);
        }
        sendFlashAndRedirect('Сессия истекла. Повторите действие.', 'error');
    }

    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'update_dates') {
            $date = (string) ($_POST['date'] ?? '');
            $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) {
                throw new RuntimeException('Укажите корректную дату.');
            }
            $filesChanged = 0;
            $fieldsChanged = 0;
            foreach (htmlFiles($pagesDir) as $path) {
                $original = file_get_contents($path);
                if ($original === false) {
                    continue;
                }
                [$updated, $fieldCount] = updateDateMetadata($original, $date);
                if ($updated !== $original) {
                    writeChangedFile($path, $updated);
                    $filesChanged++;
                    $fieldsChanged += $fieldCount;
                }
            }
            sendFlashAndRedirect("Дата обновлена в $filesChanged файлах ($fieldsChanged полей).");
        }

        if ($action === 'update_phone') {
            $displayPhone = trim((string) ($_POST['phone'] ?? ''));
            $digits = preg_replace('/\D+/', '', $displayPhone) ?? '';
            if (!preg_match('/^\+[0-9\s().-]+$/', $displayPhone) || strlen($digits) < 8 || strlen($digits) > 15) {
                throw new RuntimeException('Введите номер в международном формате, например +375 (29) 390-03-03.');
            }
            $telPhone = '+' . $digits;
            $filesChanged = 0;
            $numbersChanged = 0;
            foreach (htmlFiles($pagesDir) as $path) {
                $original = file_get_contents($path);
                if ($original === false) {
                    continue;
                }
                [$updated, $numberCount] = updatePhoneNumbers($original, $displayPhone, $telPhone);
                if ($updated !== $original) {
                    writeChangedFile($path, $updated);
                    $filesChanged++;
                    $numbersChanged += $numberCount;
                }
            }
            sendFlashAndRedirect("Номер обновлён в $filesChanged файлах ($numbersChanged совпадений).");
        }

        if ($action === 'save_alias') {
            $routes = loadJsonFile($routesFile);
            $aliases = loadJsonFile($aliasesFile);
            $source = trim((string) ($_POST['source'] ?? ''));
            $target = trim((string) ($_POST['target'] ?? ''));
            if (!validRoutePath($source) || !validRoutePath($target)) {
                throw new RuntimeException('Укажите корректные пути без домена, query и завершающего слэша.');
            }
            if ($source === $target || isset($routes[$source]) || !isset($routes[$target])) {
                throw new RuntimeException('Источник должен быть свободным URL, а цель — существующим canonical-маршрутом.');
            }
            $aliases[$source] = $target;
            ksort($aliases, SORT_STRING);
            writeJsonFile($aliasesFile, $aliases);
            sendFlashAndRedirect("Редирект $source → $target сохранён.");
        }

        if ($action === 'delete_alias') {
            $aliases = loadJsonFile($aliasesFile);
            $source = (string) ($_POST['source'] ?? '');
            if (!isset($aliases[$source])) {
                throw new RuntimeException('Редирект уже удалён или не найден.');
            }
            unset($aliases[$source]);
            writeJsonFile($aliasesFile, $aliases);
            sendFlashAndRedirect("Редирект $source удалён.");
        }

        if ($action === 'import_html') {
            $files = $_FILES['files'] ?? null;
            if (!is_array($files) || !isset($files['name']) || !is_array($files['name'])) {
                jsonResponse(['ok' => false, 'error' => 'Выберите папку с HTML-файлами.'], 400);
            }
            $imported = [];
            $skipped = [];
            $errors = [];
            $pageRoot = realpath($pagesDir);
            foreach ($files['name'] as $index => $originalName) {
                $uploadError = $files['error'][$index] ?? UPLOAD_ERR_NO_FILE;
                if ($uploadError !== UPLOAD_ERR_OK) {
                    if ($uploadError !== UPLOAD_ERR_NO_FILE) {
                        $errors[] = $originalName . ': ошибка загрузки #' . $uploadError . ' (проверьте лимиты PHP).';
                    }
                    continue;
                }
                $relativePath = importRelativePath((string) ($files['full_path'][$index] ?? ''), (string) $originalName);
                $temporaryPath = (string) ($files['tmp_name'][$index] ?? '');
                if ($relativePath === null || !is_uploaded_file($temporaryPath)) {
                    $errors[] = $originalName . ': допустимы только HTML-файлы с безопасным путём.';
                    continue;
                }
                $segments = explode(DIRECTORY_SEPARATOR, $relativePath);
                $filename = array_pop($segments);
                $destinationDirectory = $pagesDir;
                $unsafeDirectory = false;
                foreach ($segments as $segment) {
                    $destinationDirectory .= DIRECTORY_SEPARATOR . $segment;
                    if (is_link($destinationDirectory)) {
                        $unsafeDirectory = true;
                        break;
                    }
                    if (!is_dir($destinationDirectory) && !mkdir($destinationDirectory, 0755) && !is_dir($destinationDirectory)) {
                        $unsafeDirectory = true;
                        break;
                    }
                }
                $realDirectory = realpath($destinationDirectory);
                if ($unsafeDirectory || !$pageRoot || !$realDirectory || !str_starts_with($realDirectory . DIRECTORY_SEPARATOR, $pageRoot . DIRECTORY_SEPARATOR)) {
                    $errors[] = $originalName . ': небезопасная папка назначения.';
                    continue;
                }
                $destination = $realDirectory . DIRECTORY_SEPARATOR . $filename;
                if (file_exists($destination)) {
                    $skipped[] = $relativePath . ' (файл уже существует)';
                    continue;
                }
                if (!move_uploaded_file($temporaryPath, $destination)) {
                    $errors[] = $relativePath . ': не удалось переместить файл.';
                    continue;
                }
                $imported[] = $relativePath;
            }

            $routeBuild = null;
            if (!empty($_POST['final_chunk'])) {
                if ($imported) {
                    $routeBuild = rebuildRouteMap();
                }
                $message = 'Импорт завершён: добавлено ' . count($imported) . ', пропущено ' . count($skipped) . ', ошибок ' . count($errors) . '.';
                if ($routeBuild && !$routeBuild['ok']) {
                    $message .= ' Не удалось пересобрать routes.json.';
                }
                setFlash($message, ($errors || ($routeBuild && !$routeBuild['ok'])) ? 'error' : 'success');
            }
            jsonResponse([
                'ok' => !$errors,
                'imported' => $imported,
                'skipped' => $skipped,
                'errors' => $errors,
                'routeBuild' => $routeBuild,
            ]);
        }

        throw new RuntimeException('Неизвестное действие.');
    } catch (Throwable $exception) {
        if ($isAjaxImport) {
            jsonResponse(['ok' => false, 'error' => $exception->getMessage()], 400);
        }
        sendFlashAndRedirect($exception->getMessage(), 'error');
    }
}

try {
    $routes = loadJsonFile($routesFile);
    $aliases = loadJsonFile($aliasesFile);
} catch (Throwable $exception) {
    $routes = [];
    $aliases = [];
    $flash = ['message' => $exception->getMessage(), 'kind' => 'error'];
}
$pageCount = count(htmlFiles($pagesDir));
$flash ??= null;
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Управление сайтом | 103vet</title>
    <style>
        :root{color-scheme:light;--ink:#1d2b24;--muted:#65746b;--line:#dce4dc;--paper:#f3f5ef;--surface:#fff;--green:#206849;--green-dark:#174b35;--lime:#d8e6ba;--red:#a63d33;--soft-red:#fff1ee;--mono:"IBM Plex Mono",ui-monospace,monospace}
        *{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;background:var(--paper);color:var(--ink);font:15px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}button,input,select{font:inherit}a{color:inherit}.layout{min-height:100vh;display:grid;grid-template-columns:250px minmax(0,1fr)}aside{position:sticky;top:0;height:100vh;padding:28px 20px;background:#1f3a2e;color:#f4f6ed;display:flex;flex-direction:column}.brand{display:flex;align-items:center;gap:12px;padding:0 8px 26px;border-bottom:1px solid #ffffff2a}.brand-mark{width:38px;height:38px;border:1px solid #b8d096;border-radius:9px;display:grid;place-items:center;color:#d9e8bb;font:bold 17px var(--mono)}.brand strong{display:block;font-size:15px}.brand span{display:block;color:#b9c8bc;font-size:12px}.nav-label{margin:25px 9px 9px;color:#a8b8ac;text-transform:uppercase;font-size:10px;font-weight:700;letter-spacing:.1em}.nav{display:grid;gap:4px}.nav a{padding:10px 11px;text-decoration:none;border-radius:5px;color:#d6e0d8;font-size:14px}.nav a:hover,.nav a:focus-visible{background:#ffffff16;color:white}.aside-bottom{margin-top:auto;color:#b9c8bc;font-size:12px;padding:14px 8px 0;border-top:1px solid #ffffff2a}.aside-bottom form{margin-top:14px}.logout{padding:8px 11px;border:1px solid #ffffff50;background:transparent;color:#fff;border-radius:5px;cursor:pointer}.main{max-width:1200px;width:100%;padding:38px clamp(22px,5vw,68px) 70px;margin:0 auto}.topline{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;margin-bottom:28px}.eyebrow{margin:0 0 5px;color:var(--green);font:700 11px var(--mono);text-transform:uppercase}.topline h1{margin:0;font:500 32px/1.15 Georgia,"Times New Roman",serif;letter-spacing:0}.topline p{margin:8px 0 0;color:var(--muted)}.date-stamp{padding:9px 12px;border:1px solid var(--line);border-radius:5px;background:#ffffffa8;color:var(--muted);font:12px var(--mono);white-space:nowrap}.flash{padding:13px 15px;border:1px solid #b5d3c0;border-left:4px solid var(--green);border-radius:5px;background:#f6fff8;margin:0 0 22px}.flash.error{border-color:#e4b7b0;border-left-color:var(--red);background:var(--soft-red);color:#77342e}.stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));border:1px solid var(--line);border-radius:7px;background:var(--surface);margin-bottom:36px}.stat{padding:17px 20px;border-right:1px solid var(--line)}.stat:last-child{border:0}.stat strong{display:block;font:500 26px/1.1 Georgia,serif}.stat span{color:var(--muted);font-size:12px}.section{scroll-margin-top:22px;margin:0 0 44px}.section-head{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin-bottom:14px;padding-bottom:11px;border-bottom:1px solid var(--line)}.section-head h2{margin:0;font:500 23px/1.2 Georgia,"Times New Roman",serif;letter-spacing:0}.section-head p{margin:4px 0 0;color:var(--muted);font-size:13px}.section-index{color:#7a8b7e;font:12px var(--mono)}.panel{padding:22px 24px;background:var(--surface);border:1px solid var(--line);border-radius:7px}.form-grid{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:end;gap:14px}.field label{display:block;margin-bottom:7px;font-weight:650;font-size:13px}.field input,.field select{width:100%;height:43px;padding:9px 11px;border:1px solid #b9c5b9;border-radius:5px;background:white;color:var(--ink)}.field input:focus,.field select:focus{outline:2px solid #82ae8c;outline-offset:1px}.btn{min-height:42px;padding:9px 16px;border:1px solid var(--green);border-radius:5px;background:var(--green);color:white;font-weight:700;cursor:pointer}.btn:hover{background:var(--green-dark)}.btn.secondary{border-color:#b9c5b9;background:#fff;color:var(--ink)}.btn.secondary:hover{background:#f1f5ee}.btn.danger{min-height:32px;padding:5px 9px;border-color:#dbb7b1;background:white;color:var(--red);font-size:12px}.note{margin:12px 0 0;color:var(--muted);font-size:12px}.import-box{display:flex;align-items:center;gap:16px;flex-wrap:wrap}.import-meta{flex:1;min-width:220px}.import-meta strong{display:block}.import-meta span{color:var(--muted);font-size:13px}.file-input{position:absolute;width:1px;height:1px;opacity:0;pointer-events:none}.progress-wrap{display:none;margin-top:18px}.progress-wrap.active{display:block}.progress-line{display:flex;justify-content:space-between;color:var(--muted);font-size:12px;margin-bottom:7px}.progress{height:7px;border-radius:99px;background:#e6ebe3;overflow:hidden}.progress span{display:block;width:0;height:100%;background:#79a66d;transition:width .15s}.import-result{margin-top:14px;font-size:13px;white-space:pre-line}.alias-grid{display:grid;grid-template-columns:minmax(180px,1fr) minmax(260px,1.4fr) auto;gap:12px;align-items:end}.alias-table{width:100%;border-collapse:collapse;margin-top:20px;font-size:13px}.alias-table th{text-align:left;color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.06em;padding:9px 8px;border-bottom:1px solid var(--line)}.alias-table td{padding:10px 8px;border-bottom:1px solid #edf0ea;vertical-align:middle}.alias-table code{font:12px var(--mono);overflow-wrap:anywhere}.alias-table form{margin:0}.empty{color:var(--muted);padding:16px 0}.footer-note{margin-top:42px;padding-top:14px;border-top:1px solid var(--line);color:var(--muted);font-size:12px}
        @media(max-width:850px){.layout{grid-template-columns:1fr}aside{position:relative;height:auto;padding:16px 18px}.brand{padding-bottom:14px}.nav-label,.aside-bottom{display:none}.nav{grid-template-columns:repeat(4,1fr);margin-top:12px}.nav a{text-align:center;padding:8px 5px;font-size:12px}.main{padding-top:28px}.alias-grid{grid-template-columns:1fr 1fr}.alias-grid .btn{grid-column:1/-1}}
        @media(max-width:560px){.nav{grid-template-columns:repeat(2,1fr)}.topline{display:block}.date-stamp{display:inline-block;margin-top:15px}.topline h1{font-size:28px}.stats{grid-template-columns:1fr}.stat{border-right:0;border-bottom:1px solid var(--line)}.stat:last-child{border-bottom:0}.form-grid{grid-template-columns:1fr}.panel{padding:17px}.alias-grid{grid-template-columns:1fr}.alias-grid .btn{grid-column:auto}.alias-table th:nth-child(2),.alias-table td:nth-child(2){display:none}.alias-table td:first-child{max-width:180px}}
    </style>
</head>
<body>
<div class="layout">
    <aside>
        <div class="brand"><div class="brand-mark">V</div><div><strong>Управление</strong><span>103vet · страницы</span></div></div>
        <div class="nav-label">Инструменты</div>
        <nav class="nav" aria-label="Разделы панели">
            <a href="#overview">Обзор</a><a href="#dates">Даты</a><a href="#phone">Телефон</a><a href="#import">Импорт</a><a href="#redirects">Редиректы</a>
        </nav>
        <div class="aside-bottom">Локальная панель · `127.0.0.1`</div>
    </aside>
    <main class="main">
        <header class="topline" id="overview">
            <div><p class="eyebrow">Панель сайта</p><h1>Управление страницами</h1><p>Контент, контакты и маршруты в одном месте.</p></div>
            <div class="date-stamp"><?= escapeHtml(date('d.m.Y')) ?></div>
        </header>
        <?php if ($flash): ?><div class="flash <?= $flash['kind'] === 'error' ? 'error' : '' ?>" role="status"><?= escapeHtml((string) $flash['message']) ?></div><?php endif; ?>

        <div class="stats" aria-label="Состояние сайта">
            <div class="stat"><strong><?= $pageCount ?></strong><span>HTML-страниц</span></div>
            <div class="stat"><strong><?= count($routes) ?></strong><span>canonical-маршрутов</span></div>
            <div class="stat"><strong><?= count($aliases) ?></strong><span>301-редиректов</span></div>
        </div>

        <section class="section" id="dates">
            <div class="section-head"><div><h2>Обновить даты</h2><p>Даты публикации, изменения и проверки в метаданных и Schema.org.</p></div><span class="section-index">01</span></div>
            <div class="panel">
                <form method="post" class="form-grid" onsubmit="return confirm('Обновить даты метаданных во всех HTML-страницах?')">
                    <input type="hidden" name="action" value="update_dates"><input type="hidden" name="csrf" value="<?= escapeHtml($csrf) ?>">
                    <div class="field"><label for="date">Новая дата</label><input id="date" name="date" type="date" value="<?= escapeHtml(date('Y-m-d')) ?>" required></div>
                    <button class="btn" type="submit">Обновить даты</button>
                </form>
                <p class="note">Текст статей, даты клинических исследований и другие даты вне полей публикации не меняются.</p>
            </div>
        </section>

        <section class="section" id="phone">
            <div class="section-head"><div><h2>Обновить телефон</h2><p>Заменить контактный номер в видимом тексте, Schema.org и ссылках звонка.</p></div><span class="section-index">02</span></div>
            <div class="panel">
                <form method="post" class="form-grid" onsubmit="return confirm('Заменить телефон во всех HTML-страницах?')">
                    <input type="hidden" name="action" value="update_phone"><input type="hidden" name="csrf" value="<?= escapeHtml($csrf) ?>">
                    <div class="field"><label for="phone-number">Номер в международном формате</label><input id="phone-number" name="phone" type="tel" inputmode="tel" placeholder="+375 (29) 390-03-03" pattern="\+[0-9 ().-]{8,}" required></div>
                    <button class="btn" type="submit">Обновить телефон</button>
                </form>
                <p class="note">Обновляются номера с международным префиксом и все ссылки `tel:`.</p>
            </div>
        </section>

        <section class="section" id="import">
            <div class="section-head"><div><h2>Импортировать страницы</h2><p>Выберите папку с устройства. В `pages/` попадут только HTML-файлы.</p></div><span class="section-index">03</span></div>
            <div class="panel">
                <form id="import-form">
                    <input type="hidden" name="csrf" value="<?= escapeHtml($csrf) ?>">
                    <input class="file-input" id="folder-input" type="file" webkitdirectory directory multiple accept=".html,text/html">
                    <div class="import-box"><button class="btn secondary" id="choose-folder" type="button">Выбрать папку</button><div class="import-meta"><strong id="folder-summary">Папка не выбрана</strong><span id="folder-detail">Файлы не перезаписываются · лимит 2 МБ на файл.</span></div><button class="btn" id="upload-folder" type="submit" disabled>Загрузить в pages</button></div>
                    <div class="progress-wrap" id="progress-wrap"><div class="progress-line"><span id="progress-label">Подготовка импорта</span><span id="progress-count">0%</span></div><div class="progress"><span id="progress-bar"></span></div><div class="import-result" id="import-result" aria-live="polite"></div></div>
                </form>
            </div>
        </section>

        <section class="section" id="redirects">
            <div class="section-head"><div><h2>Настройка редиректа</h2><p>301-переход с устаревшего URL на существующий canonical-маршрут.</p></div><span class="section-index">04</span></div>
            <div class="panel">
                <form method="post" class="alias-grid">
                    <input type="hidden" name="action" value="save_alias"><input type="hidden" name="csrf" value="<?= escapeHtml($csrf) ?>">
                    <div class="field"><label for="source">Старый путь</label><input id="source" name="source" placeholder="/staryy-adres" pattern="/[a-zA-Z0-9._~%/-]+" required></div>
                    <div class="field"><label for="target">Куда перенаправить</label><select id="target" name="target" required><option value="">Выберите страницу</option><?php foreach ($routes as $route => $file): ?><option value="<?= escapeHtml((string) $route) ?>"><?= escapeHtml((string) $route . ' · ' . $file) ?></option><?php endforeach; ?></select></div>
                    <button class="btn" type="submit">Сохранить редирект</button>
                </form>
                <p class="note">Путь назначения выбирается только из действующей карты. Источник не должен совпадать с canonical-URL.</p>
                <?php if (!$aliases): ?><p class="empty">Настроенных редиректов нет.</p><?php else: ?>
                <table class="alias-table"><thead><tr><th>Источник</th><th>Назначение</th><th></th></tr></thead><tbody>
                <?php foreach ($aliases as $source => $target): ?><tr><td><code><?= escapeHtml((string) $source) ?></code></td><td><code><?= escapeHtml((string) $target) ?></code></td><td><form method="post" onsubmit="return confirm('Удалить редирект <?= escapeHtml((string) $source) ?>?')"><input type="hidden" name="action" value="delete_alias"><input type="hidden" name="csrf" value="<?= escapeHtml($csrf) ?>"><input type="hidden" name="source" value="<?= escapeHtml((string) $source) ?>"><button class="btn danger" type="submit">Удалить</button></form></td></tr><?php endforeach; ?>
                </tbody></table><?php endif; ?>
            </div>
        </section>
        <footer class="footer-note">Административные изменения сразу записываются в HTML и JSON-файлы проекта.</footer>
    </main>
</div>
<script>
const folderInput=document.getElementById('folder-input');
const chooseButton=document.getElementById('choose-folder');
const uploadButton=document.getElementById('upload-folder');
const importForm=document.getElementById('import-form');
const progressWrap=document.getElementById('progress-wrap');
const progressBar=document.getElementById('progress-bar');
const progressLabel=document.getElementById('progress-label');
const progressCount=document.getElementById('progress-count');
const folderSummary=document.getElementById('folder-summary');
const folderDetail=document.getElementById('folder-detail');
const importResult=document.getElementById('import-result');
let selectedFiles=[];

chooseButton.addEventListener('click',()=>folderInput.click());
folderInput.addEventListener('change',()=>{
    selectedFiles=Array.from(folderInput.files).filter(file=>file.name.toLowerCase().endsWith('.html'));
    const totalBytes=selectedFiles.reduce((sum,file)=>sum+file.size,0);
    folderSummary.textContent=selectedFiles.length?`${selectedFiles.length} HTML-файлов выбрано`:'HTML-файлы не найдены';
    folderDetail.textContent=selectedFiles.length?`${(totalBytes/1024/1024).toFixed(1)} МБ · файлы без перезаписи`:'Выберите папку, содержащую .html';
    uploadButton.disabled=selectedFiles.length===0;
});

importForm.addEventListener('submit',async event=>{
    event.preventDefault();
    if(!selectedFiles.length)return;
    uploadButton.disabled=true;
    chooseButton.disabled=true;
    progressWrap.classList.add('active');
    importResult.textContent='';
    const maxBatchBytes=5*1024*1024;
    const maxBatchFiles=10;
    const batches=[];
    let batch=[];let batchBytes=0;
    for(const file of selectedFiles){
        if(batch.length&&(batch.length>=maxBatchFiles||batchBytes+file.size>maxBatchBytes)){batches.push(batch);batch=[];batchBytes=0;}
        batch.push(file);batchBytes+=file.size;
    }
    if(batch.length)batches.push(batch);
    const summary={imported:[],skipped:[],errors:[],buildOutput:''};
    let completed=0;
    try{
        for(let index=0;index<batches.length;index++){
            const formData=new FormData();
            formData.append('action','import_html');
            formData.append('csrf',importForm.querySelector('[name="csrf"]').value);
            formData.append('final_chunk',index===batches.length-1?'1':'0');
            for(const file of batches[index])formData.append('files[]',file,file.webkitRelativePath||file.name);
            progressLabel.textContent=`Загрузка части ${index+1} из ${batches.length}`;
            const response=await fetch('/admin.php',{method:'POST',headers:{Accept:'application/json'},body:formData,credentials:'same-origin'});
            const result=await response.json();
            if(!response.ok||(!result.ok&&result.error))throw new Error(result.error||'Ошибка загрузки.');
            summary.imported.push(...(result.imported||[]));
            summary.skipped.push(...(result.skipped||[]));
            summary.errors.push(...(result.errors||[]));
            if(result.routeBuild)summary.buildOutput=result.routeBuild.output;
            completed+=batches[index].length;
            const percent=Math.round(completed/selectedFiles.length*100);
            progressBar.style.width=`${percent}%`;
            progressCount.textContent=`${percent}%`;
        }
        progressLabel.textContent='Импорт завершён';
        const lines=[`Добавлено: ${summary.imported.length}`,`Пропущено: ${summary.skipped.length}`,`Ошибок: ${summary.errors.length}`];
        if(summary.skipped.length)lines.push(...summary.skipped.slice(0,8).map(item=>`Пропущен: ${item}`));
        if(summary.errors.length)lines.push(...summary.errors.slice(0,8));
        if(summary.buildOutput)lines.push('Карта маршрутов пересобрана.');
        importResult.textContent=lines.join('\n');
        if(summary.imported.length)window.location.reload();
    }catch(error){
        progressLabel.textContent='Импорт остановлен';
        importResult.textContent=error.message;
    }finally{
        uploadButton.disabled=selectedFiles.length===0;
        chooseButton.disabled=false;
    }
});
</script>
</body>
</html>