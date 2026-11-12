<?php
declare(strict_types=1);

$pagesDir = __DIR__ . '/pages';
$outputFile = __DIR__ . '/routes.json';
$routes = [];
$skipped = [];
$routeSources = [];
$routeSourceHasHeading = [];
$routeCollisions = [];

// Рекурсивно ищем все файлы в папке pages
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($pagesDir, RecursiveDirectoryIterator::SKIP_DOTS)
);
$files = iterator_to_array($iterator, false);
usort($files, static fn (SplFileInfo $a, SplFileInfo $b): int => strcmp($a->getPathname(), $b->getPathname()));

foreach ($files as $file) {
    $filename = $file->getFilename();
    $pathname = $file->getPathname();
    
    // 1. Проверяем расширение (регистронезависимо)
    if (strtolower($file->getExtension()) !== 'html') {
        $skipped[] = "❌ Пропущено (не .html): $filename";
        continue;
    }

    // 2. Читаем первые 8 КБ файла (достаточно для любого <head> с JSON-LD)
    $pageContent = file_get_contents($pathname);
    $headContent = substr($pageContent, 0, 8192);
    
    // 3. Умный поиск: сначала находим весь тег <link ... rel="canonical" ... >, независимо от порядка атрибутов
    if (preg_match('~<link\s+[^>]*rel=["\']canonical["\'][^>]*>~i', $headContent, $linkTag)) {
        // 4. Из найденного тега вытаскиваем значение href
        if (preg_match('~href=["\']([^"\']+)["\']~i', $linkTag[0], $hrefMatch)) {
            $canonicalUrl = $hrefMatch[1];
            
            $parsedUrl = parse_url($canonicalUrl);
            $path = rtrim($parsedUrl['path'] ?? '/', '/');
            if ($path === '') {
                $path = '/';
            }

            $relativeFilePath = str_replace($pagesDir . DIRECTORY_SEPARATOR, '', $pathname);
            $hasMainHeading = preg_match('~<h1\b~i', $pageContent) === 1;
            if (isset($routeSources[$path])) {
                $routeCollisions[$path] ??= [$routeSources[$path]];
                $routeCollisions[$path][] = $relativeFilePath;
                if ($hasMainHeading || !$routeSourceHasHeading[$path]) {
                    $routeSources[$path] = $relativeFilePath;
                    $routeSourceHasHeading[$path] = $hasMainHeading;
                    $routes[$path] = $relativeFilePath;
                }
                continue;
            }
            $routeSources[$path] = $relativeFilePath;
            $routeSourceHasHeading[$path] = $hasMainHeading;
            $routes[$path] = $relativeFilePath;
            continue; // Успех, переходим к следующему файлу
        }
    }
    
    // Если мы здесь, значит canonical не найден. Сохраняем начало файла для отладки
    $preview = str_replace(["\n", "\r"], ' ', substr(strip_tags($headContent), 0, 100));
    $skipped[] = "⚠️ Нет canonical в: $filename (Начало: $preview...)";
}

// Сохраняем успешные маршруты
file_put_contents($outputFile, json_encode($routes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

// === ВЫВОД ОТЧЕТА ===
echo "=============================================\n";
echo "✅ УСПЕШНО ДОБАВЛЕНО: " . count($routes) . " файлов\n";
echo "=============================================\n";

if (count($skipped) > 0) {
    echo "\n⚠️ ПРОПУЩЕНО: " . count($skipped) . " файлов\n";
    echo "Причины:\n";
    foreach ($skipped as $reason) {
        echo " - $reason\n";
    }
} else {
    echo "\n🎉 Все HTML-файлы в папке успешно обработаны!\n";
}

if (count($routeCollisions) > 0) {
    echo "\n⚠️ КОЛЛИЗИИ CANONICAL: " . count($routeCollisions) . " маршрутов\n";
    foreach ($routeCollisions as $path => $sources) {
        echo " - $path (выбран " . $routes[$path] . "; кандидаты: " . implode(', ', $sources) . ")\n";
    }
}

echo "\n📁 Карта сохранена в: $outputFile\n";