<?php
require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\Bootstrap;
use OpenSearch\ClientBuilder;

Bootstrap::init();

$client = ClientBuilder::create()
    ->setHosts(['localhost:9200'])
    ->build();

$query = $_GET['q'] ?? 'выключатель';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Диагностика поиска OpenSearch</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .section { margin: 20px 0; padding: 20px; background: #f5f5f5; border-radius: 8px; }
        pre { background: white; padding: 10px; overflow: auto; }
        .highlight { background: yellow; font-weight: bold; }
    </style>
</head>
<body>
    <h1>🔍 Диагностика поиска OpenSearch</h1>
    
    <form method="get">
        <input type="text" name="q" value="<?= htmlspecialchars($query) ?>" size="50">
        <button type="submit">Искать</button>
    </form>
    
    <div class="section">
        <h2>1. Анализ токенизации запроса</h2>
        <?php
        try {
            // Проверяем как анализируется запрос
            $analyzers = ['text_analyzer', 'code_analyzer', 'search_analyzer'];
            
            foreach ($analyzers as $analyzer) {
                echo "<h3>Анализатор: $analyzer</h3>";
                
                $response = $client->indices()->analyze([
                    'index' => 'products_current',
                    'body' => [
                        'analyzer' => $analyzer,
                        'text' => $query
                    ]
                ]);
                
                echo "<p>Токены: ";
                $tokens = array_map(function($t) { return $t['token']; }, $response['tokens']);
                echo "<code>" . implode(', ', $tokens) . "</code></p>";
            }
            
        } catch (\Exception $e) {
            echo "<p style='color:red'>Ошибка: " . $e->getMessage() . "</p>";
        }
        ?>
    </div>
    
    <div class="section">
        <h2>2. Простой поиск match_all</h2>
        <?php
        try {
            // Сначала просто получаем любые 5 товаров
            $response = $client->search([
                'index' => 'products_current',
                'body' => [
                    'size' => 5,
                    'query' => ['match_all' => new \stdClass()]
                ]
            ]);
            
            echo "<p>Всего товаров в индексе: <strong>" . number_format($response['hits']['total']['value']) . "</strong></p>";
            echo "<h3>Примеры товаров:</h3>";
            
            foreach ($response['hits']['hits'] as $hit) {
                $product = $hit['_source'];
                echo "<div style='margin: 10px 0; padding: 10px; background: white;'>";
                echo "<strong>" . htmlspecialchars($product['name'] ?? '') . "</strong><br>";
                echo "Артикул: " . htmlspecialchars($product['external_id'] ?? '') . "<br>";
                echo "SKU: " . htmlspecialchars($product['sku'] ?? '') . "<br>";
                echo "Бренд: " . htmlspecialchars($product['brand_name'] ?? '') . "<br>";
                echo "</div>";
            }
            
        } catch (\Exception $e) {
            echo "<p style='color:red'>Ошибка: " . $e->getMessage() . "</p>";
        }
        ?>
    </div>
    
    <div class="section">
        <h2>3. Поиск по точному совпадению (term)</h2>
        <?php
        try {
            // Поиск по точному совпадению в разных полях
            $fields = ['external_id.keyword', 'sku.keyword', 'name.keyword'];
            
            foreach ($fields as $field) {
                echo "<h3>Поле: $field</h3>";
                
                $response = $client->search([
                    'index' => 'products_current',
                    'body' => [
                        'size' => 3,
                        'query' => [
                            'term' => [$field => $query]
                        ]
                    ]
                ]);
                
                if ($response['hits']['total']['value'] > 0) {
                    echo "<p>Найдено: " . $response['hits']['total']['value'] . "</p>";
                    foreach ($response['hits']['hits'] as $hit) {
                        $product = $hit['_source'];
                        echo "<div style='background: #e0ffe0; padding: 5px; margin: 5px 0;'>";
                        echo htmlspecialchars($product['name'] ?? '') . " (ID: " . $product['product_id'] . ")";
                        echo "</div>";
                    }
                } else {
                    echo "<p>Ничего не найдено</p>";
                }
            }
            
        } catch (\Exception $e) {
            echo "<p style='color:red'>Ошибка: " . $e->getMessage() . "</p>";
        }
        ?>
    </div>
    
    <div class="section">
        <h2>4. Поиск match по разным полям</h2>
        <?php
        try {
            $fields = ['name', 'external_id', 'sku', 'description'];
            
            foreach ($fields as $field) {
                echo "<h3>Поле: $field</h3>";
                
                $response = $client->search([
                    'index' => 'products_current',
                    'body' => [
                        'size' => 3,
                        'query' => [
                            'match' => [$field => $query]
                        ],
                        'highlight' => [
                            'fields' => [$field => new \stdClass()]
                        ]
                    ]
                ]);
                
                if ($response['hits']['total']['value'] > 0) {
                    echo "<p>Найдено: " . $response['hits']['total']['value'] . "</p>";
                    foreach ($response['hits']['hits'] as $hit) {
                        $product = $hit['_source'];
                        echo "<div style='background: #ffe0e0; padding: 5px; margin: 5px 0;'>";
                        echo "<strong>" . htmlspecialchars($product['name'] ?? '') . "</strong><br>";
                        echo "Артикул: " . htmlspecialchars($product['external_id'] ?? '') . "<br>";
                        
                        if (isset($hit['highlight'][$field])) {
                            echo "Подсветка: " . implode(' ... ', $hit['highlight'][$field]) . "<br>";
                        }
                        echo "</div>";
                    }
                } else {
                    echo "<p>Ничего не найдено</p>";
                }
            }
            
        } catch (\Exception $e) {
            echo "<p style='color:red'>Ошибка: " . $e->getMessage() . "</p>";
        }
        ?>
    </div>
    
    <div class="section">
        <h2>5. Multi-match поиск (как в основном коде)</h2>
        <?php
        try {
            $response = $client->search([
                'index' => 'products_current',
                'body' => [
                    'size' => 10,
                    'query' => [
                        'multi_match' => [
                            'query' => $query,
                            'fields' => [
                                'external_id^10',
                                'sku^8',
                                'name^5',
                                'brand_name^3',
                                'series_name^2',
                                'description'
                            ],
                            'type' => 'best_fields',
                            'fuzziness' => 'AUTO',
                            'prefix_length' => 2
                        ]
                    ],
                    '_source' => ['product_id', 'external_id', 'sku', 'name', 'brand_name'],
                    'explain' => true
                ]
            ]);
            
            echo "<p>Найдено: <strong>" . $response['hits']['total']['value'] . "</strong></p>";
            
            foreach ($response['hits']['hits'] as $hit) {
                $product = $hit['_source'];
                echo "<div style='background: #e0e0ff; padding: 10px; margin: 10px 0;'>";
                echo "<strong>" . htmlspecialchars($product['name'] ?? '') . "</strong><br>";
                echo "Артикул: " . htmlspecialchars($product['external_id'] ?? '') . "<br>";
                echo "SKU: " . htmlspecialchars($product['sku'] ?? '') . "<br>";
                echo "Бренд: " . htmlspecialchars($product['brand_name'] ?? '') . "<br>";
                echo "Score: " . $hit['_score'] . "<br>";
                
                // Показываем explain для первого результата
                if (isset($hit['_explanation'])) {
                    echo "<details>";
                    echo "<summary>Объяснение релевантности</summary>";
                    echo "<pre>" . json_encode($hit['_explanation'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
                    echo "</details>";
                }
                echo "</div>";
            }
            
        } catch (\Exception $e) {
            echo "<p style='color:red'>Ошибка: " . $e->getMessage() . "</p>";
        }
        ?>
    </div>
    
    <div class="section">
        <h2>6. Проверка маппинга полей</h2>
        <?php
        try {
            $mapping = $client->indices()->getMapping(['index' => 'products_current']);
            
            $properties = $mapping['products_current']['mappings']['properties'] ?? [];
            
            echo "<h3>Поля для поиска:</h3>";
            echo "<pre>";
            foreach (['name', 'external_id', 'sku', 'description'] as $field) {
                if (isset($properties[$field])) {
                    echo "\n$field:\n";
                    echo json_encode($properties[$field], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    echo "\n";
                }
            }
            echo "</pre>";
            
        } catch (\Exception $e) {
            echo "<p style='color:red'>Ошибка: " . $e->getMessage() . "</p>";
        }
        ?>
    </div>
</body>
</html>