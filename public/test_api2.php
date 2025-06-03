<?php
require_once __DIR__ . '/../vendor/autoload.php';

// Инициализация
\App\Core\Bootstrap::init();

echo "<h1>API Test</h1>";

// Тест прямого вызова API
$apiController = new \App\Controllers\ApiController();

// Имитируем параметры запроса
$_GET = [
    'page' => 1,
    'limit' => 5,
    'sort' => 'name',
    'city_id' => 3
];

echo "<h2>Direct API Call Test:</h2>";
echo "<pre>";

// Перехватываем вывод
ob_start();
$apiController->searchAction();
$output = ob_get_clean();

$data = json_decode($output, true);
print_r($data);
echo "</pre>";

// Проверяем наличие товаров в БД
echo "<h2>Products in Database:</h2>";
$pdo = \App\Core\Database::getConnection();
$stmt = $pdo->query("SELECT COUNT(*) as total FROM products");
$count = $stmt->fetchColumn();
echo "Total products in DB: $count<br>";

// Проверяем первые 5 товаров
echo "<h3>First 5 products:</h3>";
$stmt = $pdo->query("SELECT product_id, external_id, name FROM products LIMIT 5");
echo "<pre>";
print_r($stmt->fetchAll());
echo "</pre>";

// Проверяем OpenSearch
echo "<h2>OpenSearch Status:</h2>";
try {
    $client = \OpenSearch\ClientBuilder::create()
        ->setHosts(['localhost:9200'])
        ->build();
    
    $health = $client->cluster()->health();
    echo "Cluster status: " . $health['status'] . "<br>";
    
    // Проверяем индекс
    $indexInfo = $client->indices()->stats(['index' => 'products_current']);
    $docCount = $indexInfo['indices']['products_current']['primaries']['docs']['count'] ?? 0;
    echo "Documents in index: $docCount<br>";
    
} catch (Exception $e) {
    echo "OpenSearch error: " . $e->getMessage() . "<br>";
}