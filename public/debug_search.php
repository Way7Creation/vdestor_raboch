<?php
/**
 * Диагностический скрипт для поиска проблем с API
 * Сохраните как /var/www/www-root/data/site/vdestor.ru/public/debug_search.php
 * Откройте: https://vdestor.ru/debug_search.php
 */

// Включаем отображение ошибок
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Загружаем автозагрузчик
require_once __DIR__ . '/../vendor/autoload.php';

echo "<h1>🔍 Диагностика поисковой системы</h1>";
echo "<style>
body { font-family: Arial; padding: 20px; background: #f5f5f5; }
.test { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
.success { color: green; }
.error { color: red; }
.warning { color: orange; }
.code { background: #f0f0f0; padding: 10px; border-radius: 4px; font-family: monospace; overflow-x: auto; }
pre { margin: 0; }
</style>";

// Тест 1: Проверка конфигурации
echo "<div class='test'>";
echo "<h2>1️⃣ Проверка конфигурации</h2>";
try {
    $configPath = \App\Core\Config::getConfigPath();
    echo "<p class='success'>✅ Config путь: $configPath</p>";
    
    $dbConfig = \App\Core\Config::get('database.mysql');
    echo "<p class='success'>✅ Конфигурация БД загружена</p>";
    echo "<div class='code'><pre>Host: {$dbConfig['host']}\nDatabase: {$dbConfig['database']}</pre></div>";
} catch (Exception $e) {
    echo "<p class='error'>❌ Ошибка конфигурации: " . $e->getMessage() . "</p>";
}
echo "</div>";

// Тест 2: Проверка базы данных
echo "<div class='test'>";
echo "<h2>2️⃣ Проверка подключения к БД</h2>";
try {
    $pdo = \App\Core\Database::getConnection();
    echo "<p class='success'>✅ Подключение к БД успешно</p>";
    
    // Проверяем важные таблицы
    $tables = ['products', 'cities', 'warehouses', 'stock_balances', 'prices'];
    foreach ($tables as $table) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM $table");
        $count = $stmt->fetchColumn();
        echo "<p>Таблица <strong>$table</strong>: $count записей</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ Ошибка БД: " . $e->getMessage() . "</p>";
}
echo "</div>";

// Тест 3: Проверка OpenSearch
echo "<div class='test'>";
echo "<h2>3️⃣ Проверка OpenSearch</h2>";
try {
    $client = \OpenSearch\ClientBuilder::create()
        ->setHosts(['localhost:9200'])
        ->build();
    
    // Проверка соединения
    $info = $client->info();
    echo "<p class='success'>✅ OpenSearch подключен, версия: " . $info['version']['number'] . "</p>";
    
    // Проверка индекса
    $indexExists = $client->indices()->exists(['index' => 'products_current']);
    if ($indexExists) {
        echo "<p class='success'>✅ Индекс products_current существует</p>";
        
        // Количество документов
        $count = $client->count(['index' => 'products_current']);
        echo "<p>Документов в индексе: " . number_format($count['count']) . "</p>";
        
        // Тестовый поиск
        $searchResult = $client->search([
            'index' => 'products_current',
            'body' => [
                'size' => 1,
                'query' => ['match_all' => new \stdClass()]
            ]
        ]);
        echo "<p class='success'>✅ Тестовый поиск работает</p>";
    } else {
        echo "<p class='error'>❌ Индекс products_current НЕ существует!</p>";
        
        // Показываем существующие индексы
        $indices = $client->cat()->indices(['format' => 'json']);
        echo "<p>Существующие индексы:</p>";
        echo "<div class='code'><pre>";
        foreach ($indices as $index) {
            if (strpos($index['index'], 'products') !== false) {
                echo $index['index'] . " - " . $index['docs.count'] . " документов\n";
            }
        }
        echo "</pre></div>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ Ошибка OpenSearch: " . $e->getMessage() . "</p>";
    echo "<p class='warning'>⚠️ Проверьте, что OpenSearch запущен: <code>systemctl status opensearch</code></p>";
}
echo "</div>";

// Тест 4: Проверка SearchService
echo "<div class='test'>";
echo "<h2>4️⃣ Проверка SearchService</h2>";
try {
    // Пробуем простой поиск
    $params = [
        'q' => '',
        'page' => 1,
        'limit' => 5,
        'city_id' => 1
    ];
    
    echo "<p>Тестируем поиск с параметрами:</p>";
    echo "<div class='code'><pre>" . json_encode($params, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre></div>";
    
    $result = \App\Services\SearchService::search($params);
    
    if (isset($result['success']) && $result['success'] === false) {
        echo "<p class='error'>❌ Поиск вернул ошибку: " . ($result['error'] ?? 'неизвестно') . "</p>";
    } else {
        echo "<p class='success'>✅ Поиск выполнен успешно</p>";
        echo "<p>Найдено товаров: " . ($result['total'] ?? 0) . "</p>";
        if (!empty($result['products'])) {
            echo "<p>Пример товара:</p>";
            echo "<div class='code'><pre>" . json_encode($result['products'][0], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre></div>";
        }
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ Ошибка SearchService: " . $e->getMessage() . "</p>";
    echo "<p>Trace:</p>";
    echo "<div class='code'><pre>" . $e->getTraceAsString() . "</pre></div>";
}
echo "</div>";

// Тест 5: Проверка DynamicProductDataService
echo "<div class='test'>";
echo "<h2>5️⃣ Проверка DynamicProductDataService</h2>";
try {
    // Получаем первый товар из БД для теста
    $pdo = \App\Core\Database::getConnection();
    $stmt = $pdo->query("SELECT product_id FROM products LIMIT 1");
    $productId = $stmt->fetchColumn();
    
    if ($productId) {
        echo "<p>Тестируем с product_id: $productId</p>";
        
        $dynamicService = new \App\Services\DynamicProductDataService();
        $dynamicData = $dynamicService->getProductsDynamicData([$productId], 1, null);
        
        echo "<p class='success'>✅ DynamicProductDataService работает</p>";
        echo "<div class='code'><pre>" . json_encode($dynamicData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre></div>";
    } else {
        echo "<p class='warning'>⚠️ Нет товаров в БД для теста</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ Ошибка DynamicProductDataService: " . $e->getMessage() . "</p>";
}
echo "</div>";

// Тест 6: Проверка города и складов
echo "<div class='test'>";
echo "<h2>6️⃣ Проверка городов и складов</h2>";
try {
    $pdo = \App\Core\Database::getConnection();
    
    // Проверяем города
    $stmt = $pdo->query("SELECT city_id, name FROM cities LIMIT 5");
    $cities = $stmt->fetchAll();
    echo "<p>Города в БД:</p>";
    echo "<div class='code'><pre>";
    foreach ($cities as $city) {
        echo "ID: {$city['city_id']}, Название: {$city['name']}\n";
    }
    echo "</pre></div>";
    
    // Проверяем связь город-склад
    $stmt = $pdo->query("
        SELECT c.city_id, c.name as city_name, COUNT(cwm.warehouse_id) as warehouse_count
        FROM cities c
        LEFT JOIN city_warehouse_mapping cwm ON c.city_id = cwm.city_id
        GROUP BY c.city_id
        LIMIT 5
    ");
    $cityWarehouses = $stmt->fetchAll();
    echo "<p>Связь городов со складами:</p>";
    echo "<div class='code'><pre>";
    foreach ($cityWarehouses as $cw) {
        echo "Город: {$cw['city_name']} (ID: {$cw['city_id']}), Складов: {$cw['warehouse_count']}\n";
    }
    echo "</pre></div>";
} catch (Exception $e) {
    echo "<p class='error'>❌ Ошибка при проверке городов: " . $e->getMessage() . "</p>";
}
echo "</div>";

// Тест 7: Прямой тест API endpoint
echo "<div class='test'>";
echo "<h2>7️⃣ Прямой тест API Controller</h2>";
try {
    $_GET = ['q' => 'ввг 3х2.5', 'limit' => 5, 'city_id' => 1];
    $controller = new \App\Controllers\ApiController();
    
    ob_start();
    $controller->searchAction();
    $output = ob_get_clean();
    
    $json = json_decode($output, true);
    if ($json) {
        echo "<p class='success'>✅ ApiController отработал</p>";
        echo "<div class='code'><pre>" . json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre></div>";
    } else {
        echo "<p class='error'>❌ ApiController вернул некорректный JSON</p>";
        echo "<div class='code'><pre>" . htmlspecialchars($output) . "</pre></div>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ Ошибка ApiController: " . $e->getMessage() . "</p>";
    echo "<div class='code'><pre>" . $e->getTraceAsString() . "</pre></div>";
}
echo "</div>";

// Проверка логов
echo "<div class='test'>";
echo "<h2>8️⃣ Последние ошибки из логов</h2>";
$logFiles = [
    '/var/log/php8.1-fpm.log',
    '/var/log/nginx/error.log',
    '/var/www/www-root/data/logs/app.log'
];

foreach ($logFiles as $logFile) {
    if (file_exists($logFile) && is_readable($logFile)) {
        echo "<p><strong>$logFile:</strong></p>";
        $lines = array_slice(file($logFile), -10);
        echo "<div class='code'><pre>" . htmlspecialchars(implode('', $lines)) . "</pre></div>";
    } else {
        echo "<p class='warning'>⚠️ Файл $logFile недоступен</p>";
    }
}
echo "</div>";

echo "<hr>";
echo "<p class='warning'>⚠️ <strong>Удалите этот файл после диагностики!</strong></p>";
?>