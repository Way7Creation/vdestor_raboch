<?php
/**
 * Детальная диагностика проблемы с SearchService
 * Сохраните как /var/www/www-root/data/site/vdestor.ru/public/debug_search_detailed.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../vendor/autoload.php';

echo "<h1>Детальная диагностика SearchService</h1>";
echo "<style>
.test { background: #f8f9fa; padding: 15px; margin: 10px 0; border-radius: 5px; }
.error { color: red; }
.success { color: green; }
.code { background: #f0f0f0; padding: 10px; font-family: monospace; white-space: pre-wrap; }
</style>";

// Отключаем буферизацию
ob_implicit_flush(true);
ob_end_flush();

// Тест 1: Прямой вызов SearchService::search
echo "<div class='test'>";
echo "<h3>1. Прямой вызов SearchService::search</h3>";
try {
    $params = [
        'q' => '',
        'page' => 1,
        'limit' => 5,
        'city_id' => 1
    ];
    
    echo "Параметры: " . json_encode($params) . "<br>";
    
    // Временно включаем вывод всех ошибок в SearchService
    $originalErrorReporting = error_reporting();
    error_reporting(E_ALL);
    
    $result = \App\Services\SearchService::search($params);
    
    error_reporting($originalErrorReporting);
    
    echo "<div class='code'>" . json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</div>";
    
    if (isset($result['success']) && $result['success'] === false) {
        echo "<p class='error'>❌ SearchService вернул success=false</p>";
        echo "<p>Error: " . ($result['error'] ?? 'неизвестно') . "</p>";
        echo "<p>Error code: " . ($result['error_code'] ?? 'неизвестно') . "</p>";
    }
    
} catch (Exception $e) {
    echo "<p class='error'>Exception: " . $e->getMessage() . "</p>";
    echo "<div class='code'>" . $e->getTraceAsString() . "</div>";
}
echo "</div>";

// Тест 2: Проверка isOpenSearchAvailable
echo "<div class='test'>";
echo "<h3>2. Проверка SearchService::isOpenSearchAvailable()</h3>";
try {
    // Используем рефлексию для вызова приватного метода
    $reflection = new ReflectionClass('\App\Services\SearchService');
    $method = $reflection->getMethod('isOpenSearchAvailable');
    $method->setAccessible(true);
    
    $isAvailable = $method->invoke(null);
    
    if ($isAvailable) {
        echo "<p class='success'>✅ OpenSearch доступен</p>";
    } else {
        echo "<p class='error'>❌ OpenSearch НЕдоступен</p>";
    }
    
} catch (Exception $e) {
    echo "<p class='error'>Ошибка: " . $e->getMessage() . "</p>";
}
echo "</div>";

// Тест 3: Проверка системных ресурсов
echo "<div class='test'>";
echo "<h3>3. Проверка системных ресурсов</h3>";
try {
    $reflection = new ReflectionClass('\App\Services\SearchService');
    $method = $reflection->getMethod('checkSystemResources');
    $method->setAccessible(true);
    
    $resourcesOk = $method->invoke(null);
    
    if ($resourcesOk) {
        echo "<p class='success'>✅ Системные ресурсы в порядке</p>";
    } else {
        echo "<p class='error'>❌ Проблема с системными ресурсами</p>";
    }
    
    // Показываем текущие ресурсы
    $memoryUsage = memory_get_usage(true);
    $memoryLimit = ini_get('memory_limit');
    $load = sys_getloadavg();
    
    echo "<p>Память: " . round($memoryUsage / 1024 / 1024, 2) . "MB / $memoryLimit</p>";
    echo "<p>Нагрузка: " . implode(', ', array_map(fn($l) => round($l, 2), $load)) . "</p>";
    
} catch (Exception $e) {
    echo "<p class='error'>Ошибка: " . $e->getMessage() . "</p>";
}
echo "</div>";

// Тест 4: Прямое обращение к OpenSearch
echo "<div class='test'>";
echo "<h3>4. Прямое обращение к OpenSearch</h3>";
try {
    $client = \OpenSearch\ClientBuilder::create()
        ->setHosts(['localhost:9200'])
        ->setRetries(2)
        ->setConnectionParams([
            'timeout' => 10,
            'connect_timeout' => 5
        ])
        ->build();
    
    // Проверяем здоровье кластера
    $startTime = microtime(true);
    $health = $client->cluster()->health(['timeout' => '5s']);
    $responseTime = (microtime(true) - $startTime) * 1000;
    
    echo "<p>Статус кластера: <strong>{$health['status']}</strong></p>";
    echo "<p>Время ответа: " . round($responseTime, 2) . "ms</p>";
    
    // Простой поиск
    $searchStart = microtime(true);
    $searchResult = $client->search([
        'index' => 'products_current',
        'body' => [
            'size' => 5,
            'query' => ['match_all' => new \stdClass()],
            'timeout' => '5s'
        ]
    ]);
    $searchTime = (microtime(true) - $searchStart) * 1000;
    
    echo "<p>Поиск выполнен за: " . round($searchTime, 2) . "ms</p>";
    echo "<p>Найдено документов: " . ($searchResult['hits']['total']['value'] ?? 0) . "</p>";
    
    if ($responseTime > 5000 || $searchTime > 5000) {
        echo "<p class='error'>⚠️ OpenSearch отвечает слишком медленно!</p>";
    }
    
} catch (Exception $e) {
    echo "<p class='error'>Ошибка OpenSearch: " . $e->getMessage() . "</p>";
}
echo "</div>";

// Тест 5: Проверка таймаутов
echo "<div class='test'>";
echo "<h3>5. Проверка настроек и таймаутов</h3>";
echo "<p>max_execution_time: " . ini_get('max_execution_time') . "s</p>";
echo "<p>memory_limit: " . ini_get('memory_limit') . "</p>";
echo "<p>Текущее использование памяти: " . round(memory_get_usage(true) / 1024 / 1024, 2) . "MB</p>";
echo "<p>Пиковое использование памяти: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . "MB</p>";
echo "</div>";

// Тест 6: Логи последних ошибок
echo "<div class='test'>";
echo "<h3>6. Последние записи в логах</h3>";
$logFile = '/var/www/www-root/data/logs/app.log';
if (file_exists($logFile)) {
    $lines = array_slice(file($logFile), -20);
    $relevantLines = array_filter($lines, function($line) {
        return stripos($line, 'search') !== false || 
               stripos($line, 'error') !== false ||
               stripos($line, 'opensearch') !== false;
    });
    
    if (!empty($relevantLines)) {
        echo "<div class='code'>" . htmlspecialchars(implode('', $relevantLines)) . "</div>";
    } else {
        echo "<p>Нет релевантных записей в логах</p>";
    }
} else {
    echo "<p>Лог файл не найден: $logFile</p>";
}
echo "</div>";

// Тест 7: Проверка конкретной проблемы с городами
echo "<div class='test'>";
echo "<h3>7. Проверка DynamicProductDataService с городом 1</h3>";
try {
    $service = new \App\Services\DynamicProductDataService();
    
    // Проверяем существование города 1
    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('cityExists');
    $method->setAccessible(true);
    
    $cityExists = $method->invoke($service, 1);
    
    if ($cityExists) {
        echo "<p class='success'>✅ Город с ID=1 существует</p>";
    } else {
        echo "<p class='error'>❌ Город с ID=1 НЕ существует!</p>";
        
        // Показываем какие города есть
        $pdo = \App\Core\Database::getConnection();
        $cities = $pdo->query("SELECT city_id, name FROM cities")->fetchAll();
        echo "<p>Доступные города:</p>";
        foreach ($cities as $city) {
            echo "<p>- ID: {$city['city_id']}, Название: {$city['name']}</p>";
        }
    }
    
} catch (Exception $e) {
    echo "<p class='error'>Ошибка: " . $e->getMessage() . "</p>";
}
echo "</div>";

echo "<hr>";
echo "<p><strong>Вывод:</strong> Основываясь на этой диагностике, мы сможем точно определить причину проблемы.</p>";
?>