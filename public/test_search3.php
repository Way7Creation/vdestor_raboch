<?php
/**
 * Быстрая проверка работы поиска после исправлений
 * Файл: public/test_search.php
 */

require_once __DIR__ . '/../vendor/autoload.php';
use App\Services\SearchService;

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Тест поиска</title>
    <style>
        body { font-family: Arial; padding: 20px; }
        .test { margin: 20px 0; padding: 15px; background: #f5f5f5; border-radius: 5px; }
        .success { color: green; }
        .error { color: red; }
        .result { background: white; padding: 10px; margin: 10px 0; border: 1px solid #ddd; }
        pre { background: #eee; padding: 10px; overflow: auto; }
    </style>
</head>
<body>
<h1>Тест поиска после исправлений</h1>

<?php
$tests = [
    'выключатель' => 'Обычный поиск',
    'dsrpfntkm' => 'Неправильная раскладка (выключатель)',
    'ва47-29' => 'Артикул с дефисом',
    '16а' => 'Число с единицей',
    'iek' => 'Бренд'
];

foreach ($tests as $query => $description) {
    echo '<div class="test">';
    echo '<h3>' . $description . ': "' . htmlspecialchars($query) . '"</h3>';
    
    try {
        $result = SearchService::search([
            'q' => $query,
            'page' => 1,
            'limit' => 5,
            'city_id' => 1
        ]);
        
        if ($result['success']) {
            $data = $result['data'];
            echo '<p class="success">✅ Успешно! Найдено: ' . $data['total'] . ' товаров</p>';
            echo '<p>Источник: ' . ($data['source'] ?? 'unknown') . '</p>';
            
            if (!empty($data['search_variants'])) {
                echo '<p>Варианты поиска: ' . implode(', ', $data['search_variants']) . '</p>';
            }
            
            if (!empty($data['products'])) {
                echo '<div class="result">';
                foreach (array_slice($data['products'], 0, 3) as $i => $product) {
                    echo ($i + 1) . '. [' . $product['external_id'] . '] ' . $product['name'];
                    if (!empty($product['brand_name'])) {
                        echo ' (' . $product['brand_name'] . ')';
                    }
                    echo '<br>';
                }
                echo '</div>';
            }
        } else {
            echo '<p class="error">❌ Ошибка: ' . ($result['error'] ?? 'Unknown error') . '</p>';
        }
        
    } catch (Exception $e) {
        echo '<p class="error">❌ Exception: ' . htmlspecialchars($e->getMessage()) . '</p>';
    }
    
    echo '</div>';
}
?>

<h2>Прямой тест API</h2>
<div class="test">
<?php
$apiUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/api/search?q=test&limit=1';
echo '<p>URL: ' . $apiUrl . '</p>';

$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200) {
    echo '<p class="success">✅ API работает! HTTP ' . $httpCode . '</p>';
    $data = json_decode($response, true);
    echo '<pre>' . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . '</pre>';
} else {
    echo '<p class="error">❌ API вернул HTTP ' . $httpCode . '</p>';
    echo '<pre>' . htmlspecialchars(substr($response, 0, 500)) . '</pre>';
}
?>
</div>

</body>
</html>