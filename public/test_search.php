<?php
/**
 * Минимальный тестовый endpoint для поиска
 * Сохраните как /var/www/www-root/data/site/vdestor.ru/public/test_search.php
 * Откройте: https://vdestor.ru/test_search.php?q=test
 */

// Включаем все ошибки
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Устанавливаем JSON заголовки
header('Content-Type: application/json; charset=utf-8');

try {
    // Загружаем только необходимое
    require_once __DIR__ . '/../vendor/autoload.php';
    
    // Получаем параметры
    $query = $_GET['q'] ?? '';
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $cityId = (int)($_GET['city_id'] ?? 1);
    
    // Логируем запрос
    error_log("TEST_SEARCH: q='$query', page=$page, limit=$limit, city_id=$cityId");
    
    // Шаг 1: Проверяем OpenSearch напрямую
    $client = \OpenSearch\ClientBuilder::create()
        ->setHosts(['localhost:9200'])
        ->setRetries(2)
        ->setConnectionParams([
            'timeout' => 10,
            'connect_timeout' => 5
        ])
        ->build();
    
    // Проверяем здоровье
    $health = $client->cluster()->health(['timeout' => '5s']);
    if (!in_array($health['status'], ['green', 'yellow'])) {
        throw new Exception("OpenSearch unhealthy: " . $health['status']);
    }
    
    // Простой поиск
    $body = [
        'size' => $limit,
        'from' => ($page - 1) * $limit,
        '_source' => ['product_id', 'external_id', 'name', 'brand_name', 'sku']
    ];
    
    if (!empty($query)) {
        $body['query'] = [
            'multi_match' => [
                'query' => $query,
                'fields' => ['name^3', 'external_id^5', 'sku^4', 'brand_name^2'],
                'type' => 'best_fields',
                'operator' => 'or'
            ]
        ];
    } else {
        $body['query'] = ['match_all' => new \stdClass()];
    }
    
    $response = $client->search([
        'index' => 'products_current',
        'body' => $body
    ]);
    
    // Обрабатываем результаты
    $products = [];
    foreach ($response['hits']['hits'] ?? [] as $hit) {
        $products[] = $hit['_source'];
    }
    
    $result = [
        'success' => true,
        'data' => [
            'products' => $products,
            'total' => $response['hits']['total']['value'] ?? 0,
            'page' => $page,
            'limit' => $limit
        ],
        'debug' => [
            'opensearch_status' => $health['status'],
            'query_time_ms' => $response['took'] ?? 0,
            'city_id' => $cityId
        ]
    ];
    
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    error_log("TEST_SEARCH ERROR: " . $e->getMessage());
    error_log("TEST_SEARCH TRACE: " . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => explode("\n", $e->getTraceAsString())
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
?>