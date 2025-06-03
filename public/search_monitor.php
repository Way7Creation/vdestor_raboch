<?php
/**
 * Мониторинг состояния поисковой системы
 * Запускать: php search_monitor.php [check|stats|test]
 */

require __DIR__ . '/vendor/autoload.php';

use OpenSearch\ClientBuilder;

class SearchMonitor {
    private $client;
    private $pdo;
    
    public function __construct() {
        $this->client = ClientBuilder::create()
            ->setHosts(['localhost:9200'])
            ->build();
            
        $config = \App\Core\Config::get('database.mysql');
        $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4";
        $this->pdo = new PDO($dsn, $config['user'], $config['password']);
    }
    
    public function check(): void {
        echo "=== Проверка поисковой системы ===\n\n";
        
        // 1. OpenSearch доступность
        $this->checkOpenSearchHealth();
        
        // 2. Состояние индекса
        $this->checkIndexStatus();
        
        // 3. Синхронизация данных
        $this->checkDataSync();
        
        // 4. Производительность
        $this->checkPerformance();
    }
    
    private function checkOpenSearchHealth(): void {
        echo "OpenSearch кластер:\n";
        
        try {
            $health = $this->client->cluster()->health();
            $status = $health['status'];
            $statusIcon = match($status) {
                'green' => '✅',
                'yellow' => '⚠️',
                'red' => '❌',
                default => '❓'
            };
            
            echo "- Статус: $statusIcon $status\n";
            echo "- Нод: {$health['number_of_nodes']}\n";
            echo "- Шардов: {$health['active_shards']} активно / {$health['unassigned_shards']} не назначено\n";
            
        } catch (\Exception $e) {
            echo "❌ OpenSearch недоступен: " . $e->getMessage() . "\n";
        }
        echo "\n";
    }
    
    private function checkIndexStatus(): void {
        echo "Индекс products_current:\n";
        
        try {
            $stats = $this->client->indices()->stats(['index' => 'products_current']);
            $index = $stats['indices']['products_current'] ?? null;
            
            if ($index) {
                $docs = $index['primaries']['docs']['count'];
                $size = round($index['primaries']['store']['size_in_bytes'] / 1024 / 1024, 2);
                
                echo "- Документов: " . number_format($docs) . "\n";
                echo "- Размер: {$size} MB\n";
                
                // Проверка mapping
                $mapping = $this->client->indices()->getMapping(['index' => 'products_current']);
                $fields = array_keys($mapping['products_current']['mappings']['properties'] ?? []);
                echo "- Полей в mapping: " . count($fields) . "\n";
            } else {
                echo "❌ Индекс не найден\n";
            }
            
        } catch (\Exception $e) {
            echo "❌ Ошибка получения статистики: " . $e->getMessage() . "\n";
        }
        echo "\n";
    }
    
    private function checkDataSync(): void {
        echo "Синхронизация данных:\n";
        
        // Товары в БД
        $dbCount = $this->pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
        echo "- Товаров в БД: " . number_format($dbCount) . "\n";
        
        // Товары в индексе
        try {
            $count = $this->client->count(['index' => 'products_current']);
            $indexCount = $count['count'];
            echo "- Товаров в индексе: " . number_format($indexCount) . "\n";
            
            $diff = abs($dbCount - $indexCount);
            $diffPercent = $dbCount > 0 ? round(($diff / $dbCount) * 100, 2) : 0;
            
            if ($diffPercent > 1) {
                echo "⚠️ Расхождение: $diff товаров ($diffPercent%)\n";
            } else {
                echo "✅ Синхронизация в норме\n";
            }
            
        } catch (\Exception $e) {
            echo "❌ Ошибка подсчета: " . $e->getMessage() . "\n";
        }
        
        // Последние изменения
        $lastUpdate = $this->pdo->query("
            SELECT MAX(updated_at) FROM products
        ")->fetchColumn();
        echo "- Последнее обновление БД: $lastUpdate\n";
        
        echo "\n";
    }
    
    private function checkPerformance(): void {
        echo "Производительность поиска:\n";
        
        $testQueries = [
            'автомат' => 'популярный запрос',
            'дкс' => 'бренд',
            'А3144' => 'артикул',
            'розетка двойная' => 'фразовый поиск',
            'выключател' => 'с опечаткой'
        ];
        
        foreach ($testQueries as $query => $description) {
            $start = microtime(true);
            
            try {
                $response = $this->client->search([
                    'index' => 'products_current',
                    'body' => [
                        'size' => 10,
                        'query' => [
                            'multi_match' => [
                                'query' => $query,
                                'fields' => ['name^3', 'search_all^2', 'external_id^4']
                            ]
                        ]
                    ]
                ]);
                
                $time = round((microtime(true) - $start) * 1000, 2);
                $hits = $response['hits']['total']['value'];
                
                echo "- '$query' ($description): {$time}ms, найдено $hits\n";
                
            } catch (\Exception $e) {
                echo "- '$query': ❌ Ошибка\n";
            }
        }
        echo "\n";
    }
    
    public function stats(): void {
        echo "=== Статистика использования ===\n\n";
        
        // Популярные запросы
        echo "Топ-10 поисковых запросов (последние 7 дней):\n";
        $stmt = $this->pdo->query("
            SELECT query, COUNT(*) as cnt, AVG(results_count) as avg_results
            FROM search_logs
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY query
            ORDER BY cnt DESC
            LIMIT 10
        ");
        
        while ($row = $stmt->fetch()) {
            echo "- '{$row['query']}': {$row['cnt']} раз, ~" . round($row['avg_results']) . " результатов\n";
        }
        
        echo "\n";
        
        // Метрики популярности
        echo "Распределение популярности товаров:\n";
        $ranges = $this->pdo->query("
            SELECT 
                CASE 
                    WHEN popularity_score >= 80 THEN '80-100 (горячие)'
                    WHEN popularity_score >= 50 THEN '50-79 (популярные)'
                    WHEN popularity_score >= 20 THEN '20-49 (средние)'
                    WHEN popularity_score > 0 THEN '1-19 (редкие)'
                    ELSE '0 (без активности)'
                END as range_name,
                COUNT(*) as cnt
            FROM product_metrics
            GROUP BY range_name
            ORDER BY MIN(popularity_score) DESC
        ")->fetchAll();
        
        foreach ($ranges as $range) {
            echo "- {$range['range_name']}: " . number_format($range['cnt']) . " товаров\n";
        }
    }
    
    public function test(string $query): void {
        echo "=== Тест поиска: '$query' ===\n\n";
        
        $start = microtime(true);
        
        $response = $this->client->search([
            'index' => 'products_current',
            'body' => [
                'size' => 5,
                'query' => [
                    'multi_match' => [
                        'query' => $query,
                        'fields' => ['name^3', 'search_all^2', 'external_id^4'],
                        'type' => 'best_fields',
                        'fuzziness' => 'AUTO'
                    ]
                ],
                'highlight' => [
                    'fields' => [
                        'name' => ['number_of_fragments' => 0],
                        'external_id' => ['number_of_fragments' => 0]
                    ]
                ],
                'explain' => true
            ]
        ]);
        
        $time = round((microtime(true) - $start) * 1000, 2);
        
        echo "Время поиска: {$time}ms\n";
        echo "Найдено: {$response['hits']['total']['value']} результатов\n\n";
        
        foreach ($response['hits']['hits'] as $i => $hit) {
            echo ($i + 1) . ". [{$hit['_score']}] ";
            echo $hit['_source']['external_id'] . " - ";
            
            if (isset($hit['highlight']['name'])) {
                echo strip_tags($hit['highlight']['name'][0]);
            } else {
                echo $hit['_source']['name'];
            }
            
            echo "\n";
            
            // Объяснение релевантности
            if (isset($hit['_explanation'])) {
                echo "   Релевантность: " . $this->explainScore($hit['_explanation']) . "\n";
            }
            echo "\n";
        }
    }
    
    private function explainScore(array $explanation): string {
        $value = round($explanation['value'], 2);
        $desc = $explanation['description'];
        
        // Упрощенное объяснение
        if (strpos($desc, 'weight(name') !== false) {
            return "совпадение в названии ($value)";
        } elseif (strpos($desc, 'weight(external_id') !== false) {
            return "совпадение в артикуле ($value)";
        } elseif (strpos($desc, 'weight(search_all') !== false) {
            return "общее совпадение ($value)";
        }
        
        return "базовая релевантность ($value)";
    }
}

// CLI интерфейс
if (php_sapi_name() === 'cli') {
    $command = $argv[1] ?? 'check';
    $monitor = new SearchMonitor();
    
    switch ($command) {
        case 'check':
            $monitor->check();
            break;
            
        case 'stats':
            $monitor->stats();
            break;
            
        case 'test':
            $query = $argv[2] ?? 'автомат';
            $monitor->test($query);
            break;
            
        default:
            echo "Использование: php search_monitor.php [check|stats|test <query>]\n";
    }
}