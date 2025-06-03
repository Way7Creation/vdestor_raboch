<?php
/**
 * Скрипт для тестирования API
 * Запустите: php test_api.php
 */

echo "🔍 ТЕСТИРОВАНИЕ API VDESTOR\n";
echo "=" . str_repeat("=", 50) . "\n\n";

// Конфигурация
$baseUrl = 'https://vdestor.ru'; // Используем HTTPS!
$tests = [
    'Тест API' => '/api/test',
    'Поиск: выключатель' => '/api/search?q=выключатель&limit=5',
    'Поиск: ва47-29' => '/api/search?q=ва47-29&limit=5',
    'Поиск: 16а' => '/api/search?q=16а&limit=5',
    'Поиск: schneider' => '/api/search?q=schneider&limit=5',
    'Автодополнение' => '/api/autocomplete?q=авт&limit=5',
    'Наличие товаров' => '/api/availability?product_ids=1,2,3&city_id=1'
];

// Функция для красивого вывода JSON
function prettyJson($data) {
    return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

// Тестирование каждого endpoint
foreach ($tests as $name => $endpoint) {
    echo "📌 {$name}\n";
    echo "URL: {$baseUrl}{$endpoint}\n";
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $baseUrl . $endpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'X-Requested-With: XMLHttpRequest'
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    
    $header = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    
    if (curl_errno($ch)) {
        echo "❌ CURL Error: " . curl_error($ch) . "\n";
    } else {
        echo "HTTP Code: {$httpCode}\n";
        
        if ($httpCode == 200) {
            $json = json_decode($body, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                echo "✅ Успешно\n";
                
                if (isset($json['success'])) {
                    echo "Success: " . ($json['success'] ? 'true' : 'false') . "\n";
                }
                
                if (isset($json['data']['products'])) {
                    echo "Найдено товаров: " . count($json['data']['products']) . "\n";
                    echo "Всего: " . ($json['data']['total'] ?? 0) . "\n";
                    
                    // Показываем первые 3 товара
                    foreach (array_slice($json['data']['products'], 0, 3) as $i => $product) {
                        echo sprintf(
                            "  %d. [%s] %s\n", 
                            $i + 1,
                            $product['external_id'] ?? 'NO_ID',
                            $product['name'] ?? 'NO_NAME'
                        );
                    }
                }
                
                if (isset($json['data']['suggestions'])) {
                    echo "Предложений: " . count($json['data']['suggestions']) . "\n";
                    foreach ($json['data']['suggestions'] as $suggestion) {
                        echo "  - " . ($suggestion['text'] ?? $suggestion) . "\n";
                    }
                }
                
            } else {
                echo "❌ Ошибка парсинга JSON\n";
                echo "Body: " . substr($body, 0, 200) . "...\n";
            }
        } else {
            echo "❌ HTTP Error {$httpCode}\n";
            echo "Headers:\n{$header}\n";
            echo "Body: " . substr($body, 0, 500) . "\n";
        }
    }
    
    curl_close($ch);
    echo "\n" . str_repeat("-", 50) . "\n\n";
}

// Дополнительная проверка OpenSearch напрямую
echo "📊 ПРОВЕРКА OPENSEARCH НАПРЯМУЮ\n";
echo "=" . str_repeat("=", 50) . "\n\n";

$osUrl = 'https://localhost:9200/products_current/_search';
$osQuery = [
    'size' => 5,
    'query' => [
        'match' => [
            'name' => 'выключатель'
        ]
    ]
];

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $osUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($osQuery),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json']
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($httpCode == 200) {
    $data = json_decode($response, true);
    echo "✅ OpenSearch работает\n";
    echo "Найдено: " . ($data['hits']['total']['value'] ?? 0) . " товаров\n";
} else {
    echo "❌ OpenSearch недоступен (HTTP {$httpCode})\n";
}

curl_close($ch);

echo "\n✅ Тестирование завершено\n";