<?php
// Создайте файл debug.php в корне проекта для диагностики
// Откройте в браузере: https://vdestor.ru/debug.php

echo "<h1>🔧 Диагностика API VDestor</h1>";
echo "<style>body{font-family:Arial;} .ok{color:green;} .error{color:red;} .info{color:blue;} pre{background:#f5f5f5;padding:10px;}</style>";

// 1. Проверка autoloader
echo "<h2>1. Autoloader</h2>";
try {
    require_once __DIR__ . '/vendor/autoload.php';
    echo "<p class='ok'>✅ Autoloader загружен</p>";
} catch (\Exception $e) {
    echo "<p class='error'>❌ Ошибка autoloader: " . $e->getMessage() . "</p>";
    exit;
}

// 2. Проверка Config
echo "<h2>2. Конфигурация</h2>";
try {
    $dbConfig = \App\Core\Config::get('database.mysql');
    if ($dbConfig) {
        echo "<p class='ok'>✅ Конфигурация базы данных загружена</p>";
        echo "<p class='info'>Host: " . ($dbConfig['host'] ?? 'не указан') . "</p>";
        echo "<p class='info'>Database: " . ($dbConfig['database'] ?? 'не указана') . "</p>";
    } else {
        echo "<p class='error'>❌ Конфигурация базы данных не найдена</p>";
    }
} catch (\Exception $e) {
    echo "<p class='error'>❌ Ошибка загрузки конфигурации: " . $e->getMessage() . "</p>";
}

// 3. Проверка подключения к БД
echo "<h2>3. База данных</h2>";
try {
    $pdo = \App\Core\Database::getConnection();
    echo "<p class='ok'>✅ Подключение к базе данных успешно</p>";
    
    // Проверяем таблицы
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "<p class='info'>Найдено таблиц: " . count($tables) . "</p>";
    
    // Проверяем товары
    $stmt = $pdo->query("SELECT COUNT(*) FROM products");
    $productCount = $stmt->fetchColumn();
    echo "<p class='info'>Товаров в БД: " . number_format($productCount) . "</p>";
    
} catch (\Exception $e) {
    echo "<p class='error'>❌ Ошибка БД: " . $e->getMessage() . "</p>";
}

// 4. Проверка OpenSearch
echo "<h2>4. OpenSearch</h2>";
try {
    $client = \OpenSearch\ClientBuilder::create()->setHosts(['localhost:9200'])->build();
    $info = $client->info();
    echo "<p class='ok'>✅ OpenSearch подключен</p>";
    echo "<p class='info'>Версия: " . $info['version']['number'] . "</p>";
    
    // Проверяем индекс
    try {
        $response = $client->count(['index' => 'products_current']);
        $docCount = $response['count'] ?? 0;
        echo "<p class='info'>Документов в индексе: " . number_format($docCount) . "</p>";
        
        if ($docCount == 0) {
            echo "<p class='error'>⚠️ Индекс пуст! Запустите индексацию.</p>";
        }
    } catch (\Exception $e) {
        echo "<p class='error'>❌ Индекс products_current не найден</p>";
    }
    
} catch (\Exception $e) {
    echo "<p class='error'>❌ Ошибка OpenSearch: " . $e->getMessage() . "</p>";
}

// 5. Проверка классов
echo "<h2>5. Классы</h2>";
$classes = [
    'App\\Controllers\\ApiController',
    'App\\Controllers\\BaseController', 
    'App\\Services\\SearchService',
    'App\\Core\\Router'
];

foreach ($classes as $class) {
    if (class_exists($class)) {
        echo "<p class='ok'>✅ {$class}</p>";
    } else {
        echo "<p class='error'>❌ {$class} не найден</p>";
    }
}

// 6. Тест SearchService напрямую
echo "<h2>6. Тест поиска</h2>";
try {
    $result = \App\Services\SearchService::search(['q' => 'test', 'limit' => 5]);
    
    if (isset($result['products'])) {
        echo "<p class='ok'>✅ SearchService работает</p>";
        echo "<p class='info'>Найдено: " . count($result['products']) . " товаров</p>";
        echo "<p class='info'>Общее количество: " . ($result['total'] ?? 0) . "</p>";
    } else {
        echo "<p class='error'>❌ SearchService вернул неожиданный результат</p>";
        echo "<pre>" . print_r($result, true) . "</pre>";
    }
} catch (\Exception $e) {
    echo "<p class='error'>❌ Ошибка SearchService: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

// 7. Тест роутера
echo "<h2>7. Роутинг</h2>";
echo "<p class='info'>Current URI: " . ($_SERVER['REQUEST_URI'] ?? 'не определен') . "</p>";
echo "<p class='info'>Request Method: " . ($_SERVER['REQUEST_METHOD'] ?? 'не определен') . "</p>";

// 8. Проверка логов
echo "<h2>8. Логи</h2>";
$logDir = '/var/www/www-root/data/logs';
if (is_dir($logDir)) {
    echo "<p class='ok'>✅ Директория логов существует</p>";
    $logFile = $logDir . '/app.log';
    if (file_exists($logFile)) {
        $lines = file($logFile);
        $recent = array_slice($lines, -10);
        echo "<p class='info'>Последние 10 строк лога:</p>";
        echo "<pre>" . implode('', $recent) . "</pre>";
    } else {
        echo "<p class='info'>Лог файл пуст или не создан</p>";
    }
} else {
    echo "<p class='error'>❌ Директория логов не найдена</p>";
}

echo "<hr><p style='color: orange;'>⚠️ <strong>Важно:</strong> Удалите этот файл после диагностики!</p>";
?>