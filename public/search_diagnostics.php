<?php
/**
 * 🔍 ПОЛНАЯ ДИАГНОСТИКА СИСТЕМЫ ПОИСКА
 * Файл: search_diagnostics.php
 * Поместите в корень сайта и откройте в браузере
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
use OpenSearch\ClientBuilder;

// Отключаем кеширование
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Начинаем вывод
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>🔍 Диагностика системы поиска</title>
    <style>
        body { font-family: monospace; background: #1a1a1a; color: #fff; padding: 20px; line-height: 1.6; }
        .section { background: #2a2a2a; padding: 20px; margin: 20px 0; border-radius: 8px; }
        .success { color: #4CAF50; font-weight: bold; }
        .error { color: #f44336; font-weight: bold; }
        .warning { color: #FFC107; font-weight: bold; }
        .info { color: #2196F3; }
        .code { background: #000; padding: 10px; border-radius: 4px; overflow-x: auto; }
        h2 { color: #FFC107; border-bottom: 2px solid #FFC107; padding-bottom: 10px; }
        pre { margin: 0; white-space: pre-wrap; }
        .test-result { margin: 10px 0; padding: 10px; background: #333; border-radius: 4px; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { padding: 8px; text-align: left; border: 1px solid #555; }
        th { background: #333; }
        .query-test { background: #1a1a1a; padding: 15px; margin: 10px 0; border: 1px solid #555; }
    </style>
</head>
<body>
<h1>🔍 ПОЛНАЯ ДИАГНОСТИКА СИСТЕМЫ ПОИСКА</h1>
<p>Дата проверки: <?= date('Y-m-d H:i:s') ?></p>

<?php
// 1. ПРОВЕРКА OPENSEARCH
echo '<div class="section">';
echo '<h2>1. OpenSearch Status</h2>';

$opensearchAvailable = false;
$opensearchClient = null;

try {
    $opensearchClient = ClientBuilder::create()
        ->setHosts(['localhost:9200'])
        ->setRetries(1)
        ->setConnectionParams([
            'timeout' => 5,
            'connect_timeout' => 2
        ])
        ->build();
    
    // Ping
    $pingResult = $opensearchClient->ping();
    if ($pingResult) {
        echo '<p class="success">✅ OpenSearch PING: OK</p>';
        
        // Информация о кластере
        $info = $opensearchClient->info();
        echo '<div class="code"><pre>';
        echo "Version: " . ($info['version']['number'] ?? 'Unknown') . "\n";
        echo "Cluster Name: " . ($info['cluster_name'] ?? 'Unknown') . "\n";
        echo "Cluster UUID: " . ($info['cluster_uuid'] ?? 'Unknown') . "\n";
        echo '</pre></div>';
        
        // Здоровье кластера
        $health = $opensearchClient->cluster()->health();
        $statusClass = $health['status'] === 'green' ? 'success' : ($health['status'] === 'yellow' ? 'warning' : 'error');
        echo "<p class='$statusClass'>Cluster Status: " . strtoupper($health['status']) . "</p>";
        echo '<div class="code"><pre>';
        echo "Active Shards: " . ($health['active_shards'] ?? 0) . "\n";
        echo "Active Primary Shards: " . ($health['active_primary_shards'] ?? 0) . "\n";
        echo "Number of Nodes: " . ($health['number_of_nodes'] ?? 0) . "\n";
        echo '</pre></div>';
        
        $opensearchAvailable = true;
    } else {
        echo '<p class="error">❌ OpenSearch PING: FAILED</p>';
    }
} catch (Exception $e) {
    echo '<p class="error">❌ OpenSearch Connection Error:</p>';
    echo '<div class="code"><pre>' . htmlspecialchars($e->getMessage()) . '</pre></div>';
}
echo '</div>';

// 2. ПРОВЕРКА ИНДЕКСОВ
if ($opensearchAvailable) {
    echo '<div class="section">';
    echo '<h2>2. Индексы OpenSearch</h2>';
    
    try {
        // Получаем все индексы products_*
        $indices = $opensearchClient->cat()->indices(['index' => 'products_*', 'v' => true]);
        
        if (empty($indices)) {
            echo '<p class="error">❌ Нет индексов products_*</p>';
        } else {
            echo '<table>';
            echo '<tr><th>Index</th><th>Docs Count</th><th>Size</th><th>Status</th></tr>';
            
            foreach ($indices as $index) {
                $status = $index['health'] === 'green' ? 'success' : ($index['health'] === 'yellow' ? 'warning' : 'error');
                echo '<tr>';
                echo '<td>' . htmlspecialchars($index['index']) . '</td>';
                echo '<td>' . htmlspecialchars($index['docs.count'] ?? '0') . '</td>';
                echo '<td>' . htmlspecialchars($index['store.size'] ?? '-') . '</td>';
                echo '<td class="' . $status . '">' . strtoupper($index['health']) . '</td>';
                echo '</tr>';
            }
            echo '</table>';
        }
        
        // Проверяем алиас products_current
        echo '<h3>Алиас products_current:</h3>';
        try {
            $aliases = $opensearchClient->indices()->getAlias(['name' => 'products_current']);
            if (!empty($aliases)) {
                echo '<p class="success">✅ Алиас найден:</p>';
                echo '<div class="code"><pre>' . json_encode(array_keys($aliases), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . '</pre></div>';
            } else {
                echo '<p class="error">❌ Алиас products_current НЕ найден!</p>';
            }
        } catch (Exception $e) {
            echo '<p class="error">❌ Ошибка проверки алиаса: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
        
        // Проверяем маппинг
        echo '<h3>Маппинг индекса:</h3>';
        try {
            $mapping = $opensearchClient->indices()->getMapping(['index' => 'products_current']);
            $indexName = array_key_first($mapping);
            if ($indexName && isset($mapping[$indexName]['mappings']['properties'])) {
                $properties = array_keys($mapping[$indexName]['mappings']['properties']);
                echo '<p class="success">✅ Найдено полей: ' . count($properties) . '</p>';
                echo '<div class="code"><pre>Поля: ' . implode(', ', array_slice($properties, 0, 10)) . '...</pre></div>';
            }
        } catch (Exception $e) {
            echo '<p class="error">❌ Не могу получить маппинг: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
        
    } catch (Exception $e) {
        echo '<p class="error">❌ Ошибка при работе с индексами: ' . htmlspecialchars($e->getMessage()) . '</p>';
    }
    echo '</div>';
}

// 3. ПРОВЕРКА БАЗЫ ДАННЫХ
echo '<div class="section">';
echo '<h2>3. База данных MySQL</h2>';

try {
    $pdo = Database::getConnection();
    echo '<p class="success">✅ Подключение к БД: OK</p>';
    
    // Статистика по таблицам
    $tables = [
        'products' => 'Товары',
        'brands' => 'Бренды', 
        'series' => 'Серии',
        'product_images' => 'Изображения',
        'product_attributes' => 'Атрибуты',
        'stock_balances' => 'Остатки'
    ];
    
    echo '<table>';
    echo '<tr><th>Таблица</th><th>Записей</th><th>Статус</th></tr>';
    
    foreach ($tables as $table => $name) {
        try {
            $count = $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
            echo '<tr>';
            echo '<td>' . $name . ' (' . $table . ')</td>';
            echo '<td>' . number_format($count) . '</td>';
            echo '<td class="success">OK</td>';
            echo '</tr>';
        } catch (Exception $e) {
            echo '<tr>';
            echo '<td>' . $name . ' (' . $table . ')</td>';
            echo '<td>-</td>';
            echo '<td class="error">ERROR</td>';
            echo '</tr>';
        }
    }
    echo '</table>';
    
    // Проверка полнотекстовых индексов
    echo '<h3>Полнотекстовые индексы:</h3>';
    $stmt = $pdo->query("SHOW INDEX FROM products WHERE Index_type = 'FULLTEXT'");
    $ftIndexes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($ftIndexes)) {
        echo '<p class="warning">⚠️ Нет FULLTEXT индексов на таблице products</p>';
    } else {
        echo '<p class="success">✅ Найдено FULLTEXT индексов: ' . count($ftIndexes) . '</p>';
        foreach ($ftIndexes as $idx) {
            echo '<div class="code">Index: ' . $idx['Key_name'] . ' on column: ' . $idx['Column_name'] . '</div>';
        }
    }
    
} catch (Exception $e) {
    echo '<p class="error">❌ Ошибка БД: ' . htmlspecialchars($e->getMessage()) . '</p>';
}
echo '</div>';

// 4. ПРОВЕРКА API ENDPOINTS
echo '<div class="section">';
echo '<h2>4. API Endpoints</h2>';

$apiTests = [
    '/api/test' => 'Тестовый endpoint',
    '/api/search?q=test&limit=1' => 'Поиск',
    '/api/availability?product_ids=1&city_id=1' => 'Наличие товаров'
];

foreach ($apiTests as $endpoint => $name) {
    echo '<div class="test-result">';
    echo '<strong>' . $name . ':</strong> ' . $endpoint . '<br>';
    
    try {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://' . $_SERVER['HTTP_HOST'] . $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
        
        $startTime = microtime(true);
        $response = curl_exec($ch);
        $duration = round((microtime(true) - $startTime) * 1000, 2);
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            echo '<p class="success">✅ HTTP ' . $httpCode . ' (' . $duration . 'ms)</p>';
            
            $data = json_decode($response, true);
            if ($data) {
                echo '<div class="code"><pre>' . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . '</pre></div>';
            }
        } else {
            echo '<p class="error">❌ HTTP ' . $httpCode . '</p>';
            echo '<div class="code"><pre>' . htmlspecialchars(substr($response, 0, 500)) . '</pre></div>';
        }
        
    } catch (Exception $e) {
        echo '<p class="error">❌ Ошибка: ' . htmlspecialchars($e->getMessage()) . '</p>';
    }
    echo '</div>';
}
echo '</div>';

// 5. ТЕСТОВЫЕ ПОИСКОВЫЕ ЗАПРОСЫ
if ($opensearchAvailable) {
    echo '<div class="section">';
    echo '<h2>5. Тестовые поисковые запросы в OpenSearch</h2>';
    
    $testQueries = [
        'выключатель' => 'Обычное слово',
        'ва47-29' => 'Артикул с дефисом',
        '16а' => 'Число с единицей измерения',
        'schneider' => 'Бренд латиницей',
        'iek' => 'Бренд (короткий)',
        'dsrpfntkm' => 'Неправильная раскладка (выключатель)'
    ];
    
    foreach ($testQueries as $query => $description) {
        echo '<div class="query-test">';
        echo '<strong>' . $description . ':</strong> "' . htmlspecialchars($query) . '"<br>';
        
        try {
            $searchBody = [
                'size' => 3,
                'query' => [
                    'multi_match' => [
                        'query' => $query,
                        'fields' => ['name^3', 'external_id^5', 'brand_name^2', 'description'],
                        'type' => 'best_fields',
                        'fuzziness' => 'AUTO'
                    ]
                ],
                '_source' => ['product_id', 'name', 'external_id', 'brand_name']
            ];
            
            $response = $opensearchClient->search([
                'index' => 'products_current',
                'body' => $searchBody
            ]);
            
            $total = $response['hits']['total']['value'] ?? 0;
            echo '<p class="info">Найдено: ' . $total . ' товаров</p>';
            
            if ($total > 0) {
                echo '<div class="code"><pre>';
                foreach ($response['hits']['hits'] as $i => $hit) {
                    $product = $hit['_source'];
                    echo ($i + 1) . '. [' . $product['external_id'] . '] ' . $product['name'];
                    if (!empty($product['brand_name'])) {
                        echo ' (' . $product['brand_name'] . ')';
                    }
                    echo ' - Score: ' . round($hit['_score'], 2) . "\n";
                }
                echo '</pre></div>';
            }
            
        } catch (Exception $e) {
            echo '<p class="error">❌ Ошибка поиска: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
        echo '</div>';
    }
    echo '</div>';
}

// 6. ПРОВЕРКА ЛОГОВ
echo '<div class="section">';
echo '<h2>6. Последние ошибки из логов</h2>';

// Проверяем логи приложения
try {
    $stmt = $pdo->query("
        SELECT * FROM application_logs 
        WHERE level IN ('error', 'critical') 
        AND message LIKE '%search%'
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($logs)) {
        echo '<p class="success">✅ Нет критических ошибок поиска в логах</p>';
    } else {
        echo '<p class="warning">⚠️ Найдены ошибки:</p>';
        foreach ($logs as $log) {
            echo '<div class="test-result">';
            echo '<strong>' . $log['created_at'] . '</strong> [' . strtoupper($log['level']) . ']<br>';
            echo htmlspecialchars($log['message']) . '<br>';
            if ($log['context']) {
                $context = json_decode($log['context'], true);
                echo '<div class="code"><pre>' . json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . '</pre></div>';
            }
            echo '</div>';
        }
    }
} catch (Exception $e) {
    echo '<p class="warning">⚠️ Не могу прочитать логи: ' . htmlspecialchars($e->getMessage()) . '</p>';
}

// Проверяем системные логи PHP
$errorLog = ini_get('error_log');
if ($errorLog && file_exists($errorLog)) {
    echo '<h3>PHP Error Log (' . $errorLog . '):</h3>';
    $lines = array_slice(file($errorLog), -20);
    $searchErrors = array_filter($lines, function($line) {
        return stripos($line, 'search') !== false || stripos($line, 'opensearch') !== false;
    });
    
    if (empty($searchErrors)) {
        echo '<p class="info">Нет ошибок связанных с поиском</p>';
    } else {
        echo '<div class="code"><pre>' . htmlspecialchars(implode('', array_slice($searchErrors, -5))) . '</pre></div>';
    }
}
echo '</div>';

// 7. РЕКОМЕНДАЦИИ
echo '<div class="section">';
echo '<h2>7. Автоматические рекомендации</h2>';

$recommendations = [];

if (!$opensearchAvailable) {
    $recommendations[] = [
        'level' => 'error',
        'text' => 'OpenSearch недоступен! Проверьте, что сервис запущен: sudo systemctl status opensearch'
    ];
}

if ($opensearchAvailable) {
    try {
        $aliases = $opensearchClient->indices()->getAlias(['name' => 'products_current']);
        if (empty($aliases)) {
            $recommendations[] = [
                'level' => 'error', 
                'text' => 'Алиас products_current не настроен! Запустите индексацию: php index_opensearch_v4.php'
            ];
        }
    } catch (Exception $e) {
        $recommendations[] = [
            'level' => 'error',
            'text' => 'Не могу проверить алиасы. Возможно, нужно переиндексировать данные.'
        ];
    }
}

// Выводим рекомендации
if (empty($recommendations)) {
    echo '<p class="success">✅ Критических проблем не обнаружено</p>';
} else {
    foreach ($recommendations as $rec) {
        $class = $rec['level'] === 'error' ? 'error' : ($rec['level'] === 'warning' ? 'warning' : 'info');
        echo '<p class="' . $class . '">• ' . $rec['text'] . '</p>';
    }
}
echo '</div>';
?>

<div class="section">
    <h2>Что делать дальше?</h2>
    <ol>
        <li>Скопируйте весь вывод этой страницы</li>
        <li>Отправьте мне результаты</li>
        <li>Я проанализирую и дам точные инструкции по исправлению</li>
    </ol>
</div>

</body>
</html>