<?php
declare(strict_types=1);

$pagesDir = __DIR__ . '/pages';
$routesFile = __DIR__ . '/routes.json';
$aliasesFile = __DIR__ . '/route_aliases.json';

// 1. Загружаем карту маршрутов
if (!file_exists($routesFile)) {
    die('Ошибка: Файл routes.json не найден. Запустите build_map.php');
}
$routes = json_decode(file_get_contents($routesFile), true);
$aliases = json_decode(file_get_contents($aliasesFile), true);

// 2. Получаем запрошенный путь и очищаем его
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$route = rtrim($requestUri, '/');
if ($route === '') {
    $route = '/';
}

if ($route === '/admin.php') {
    require __DIR__ . '/admin.php';
    exit;
}

// 301-редиректим старые URL на страницы с действующим canonical.
if (isset($aliases[$route], $routes[$aliases[$route]])) {
    header('Location: ' . $aliases[$route], true, 301);
    exit;
}

// 3. Проверяем, есть ли такой маршрут в нашей карте
if (isset($routes[$route])) {
    $relativeFilePath = $routes[$route];
    $filePath = realpath($pagesDir . DIRECTORY_SEPARATOR . $relativeFilePath);

    // 4. БЕЗОПАСНОСТЬ: Проверяем, что файл реально существует и лежит внутри pages
    if ($filePath && str_starts_with($filePath, realpath($pagesDir)) && is_file($filePath)) {
        header('Content-Type: text/html; charset=utf-8');
        
        // Отдаем файл напрямую (максимальная производительность)
        readfile($filePath);
        exit;
    }
}

// 5. Если маршрут не найден — отдаем 404
http_response_code(404);
// Можно создать pages/404.html и отдавать его, или просто вывести текст:
echo '<!DOCTYPE html><html><head><title>404 Not Found</title></head><body><h1>Страница не найдена</h1><a href="/">На главную</a></body></html>';