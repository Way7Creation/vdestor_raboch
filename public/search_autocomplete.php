<?php
/**
 * API для автодополнения поиска
 * Использует OpenSearch Completion Suggester
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require __DIR__ . '/../vendor/autoload.php';
use OpenSearch\ClientBuilder;

$client = ClientBuilder::create()
    ->setHosts(['localhost:9200'])
    ->build();

$query = trim($_GET['q'] ?? '');
if (strlen($query) < 2) {
    echo json_encode(['suggestions' => []]);
    exit;
}

// Основной запрос для автодополнения
$body = [
    'suggest' => [
        'product-suggest' => [
            'prefix' => $query,
            'completion' => [
                'field' => 'suggest',
                'size' => 15,
                'skip_duplicates' => true,
                'fuzzy' => [
                    'fuzziness' => 'AUTO',
                    'prefix_length' => 1
                ]
            ]
        ]
    ],
    '_source' => false
];

// Дополнительный поиск для товаров
$searchBody = [
    'size' => 10,
    '_source' => ['name', 'external_id', 'sku', 'brand_name'],
    'query' => [
        'bool' => [
            'should' => [
                [
                    'prefix' => [
                        'name.autocomplete' => [
                            'value' => $query,
                            'boost' => 10
                        ]
                    ]
                ],
                [
                    'prefix' => [
                        'external_id.prefix' => [
                            'value' => $query,
                            'boost' => 8
                        ]
                    ]
                ],
                [
                    'match' => [
                        'name' => [
                            'query' => $query,
                            'operator' => 'and',
                            'boost' => 5
                        ]
                    ]
                ],
                [
                    'match' => [
                        'brand_name' => [
                            'query' => $query,
                            'boost' => 3
                        ]
                    ]
                ]
            ]
        ]
    ]
];

try {
    // Получаем предложения
    $suggestResponse = $client->search([
        'index' => 'products_current',
        'body' => $body
    ]);
    
    // Получаем товары
    $searchResponse = $client->search([
        'index' => 'products_current',
        'body' => $searchBody
    ]);
    
    $suggestions = [];
    $addedTexts = [];
    
    // Обрабатываем suggestions
    if (isset($suggestResponse['suggest']['product-suggest'][0]['options'])) {
        foreach ($suggestResponse['suggest']['product-suggest'][0]['options'] as $option) {
            $text = $option['text'];
            if (!in_array($text, $addedTexts)) {
                $suggestions[] = [
                    'text' => $text,
                    'type' => $this->detectSuggestionType($text),
                    'score' => $option['_score']
                ];
                $addedTexts[] = $text;
            }
        }
    }
    
    // Добавляем товары из поиска
    if (isset($searchResponse['hits']['hits'])) {
        foreach ($searchResponse['hits']['hits'] as $hit) {
            $product = $hit['_source'];
            
            // Добавляем название товара
            if (!empty($product['name']) && !in_array($product['name'], $addedTexts)) {
                $suggestions[] = [
                    'text' => $product['name'],
                    'type' => 'product',
                    'id' => $hit['_id']
                ];
                $addedTexts[] = $product['name'];
            }
            
            // Добавляем код товара
            if (!empty($product['external_id']) && 
                stripos($product['external_id'], $query) === 0 &&
                !in_array($product['external_id'], $addedTexts)) {
                $suggestions[] = [
                    'text' => $product['external_id'],
                    'type' => 'code',
                    'id' => $hit['_id']
                ];
                $addedTexts[] = $product['external_id'];
            }
        }
    }
    
    // Сортируем по релевантности
    usort($suggestions, function($a, $b) {
        $scoreA = $a['score'] ?? 0;
        $scoreB = $b['score'] ?? 0;
        return $scoreB <=> $scoreA;
    });
    
    // Ограничиваем количество
    $suggestions = array_slice($suggestions, 0, 10);
    
    echo json_encode([
        'suggestions' => $suggestions,
        'query' => $query
    ], JSON_UNESCAPED_UNICODE);
    
} catch (\Exception $e) {
    error_log('Autocomplete error: ' . $e->getMessage());
    echo json_encode(['suggestions' => []]);
}

/**
 * Определяет тип предложения
 */
function detectSuggestionType($text) {
    // Код товара
    if (preg_match('/^[A-Za-z0-9\-\.\/\_]+$/', $text) && strlen($text) <= 30) {
        return 'code';
    }
    
    // Бренд
    $brands = ['schneider', 'legrand', 'abb', 'iek', 'ekf'];
    $textLower = mb_strtolower($text);
    foreach ($brands as $brand) {
        if (strpos($textLower, $brand) !== false) {
            return 'brand';
        }
    }
    
    // Категория
    $categories = ['выключатель', 'розетка', 'кабель', 'лампа'];
    foreach ($categories as $category) {
        if (strpos($textLower, $category) !== false) {
            return 'category';
        }
    }
    
    return 'product';
}