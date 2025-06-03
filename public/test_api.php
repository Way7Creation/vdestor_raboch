<?php
// Создайте файл test_api.php в корне проекта
// Откройте: https://vdestor.ru/test_api.php

echo "<h1>🧪 Простой тест API</h1>";
echo "<style>body{font-family:Arial;padding:20px;} .btn{background:#007bff;color:white;padding:10px 20px;text-decoration:none;border-radius:4px;margin:5px;display:inline-block;} .result{background:#f8f9fa;padding:15px;margin:10px 0;border-radius:4px;}</style>";

echo "<h2>Тестируем API endpoints:</h2>";

$tests = [
    '/api/test' => 'Тест API (должен работать)',
    '/api/search?q=test&limit=5' => 'Поиск товаров',
    '/api/search?q=&page=1&limit=10&city_id=3' => 'Пустой поиск (как на главной)'
];

foreach ($tests as $endpoint => $description) {
    echo "<div class='result'>";
    echo "<h3>{$description}</h3>";
    echo "<p><strong>URL:</strong> <a href='https://vdestor.ru{$endpoint}' target='_blank'>https://vdestor.ru{$endpoint}</a></p>";
    
    // Тестируем через curl
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://vdestor.ru{$endpoint}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Для тестирования
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        echo "<p style='color:red;'>❌ Ошибка: {$error}</p>";
    } else {
        if ($httpCode == 200) {
            echo "<p style='color:green;'>✅ HTTP {$httpCode} - OK</p>";
            
            // Пробуем декодировать JSON
            $json = json_decode($response, true);
            if ($json) {
                if (isset($json['success']) && $json['success']) {
                    echo "<p style='color:green;'>✅ JSON корректный, success = true</p>";
                    if (isset($json['data']['products'])) {
                        echo "<p style='color:blue;'>📊 Найдено товаров: " . count($json['data']['products']) . "</p>";
                    }
                } else {
                    echo "<p style='color:orange;'>⚠️ JSON корректный, но success = false</p>";
                    echo "<p>Сообщение: " . ($json['message'] ?? 'не указано') . "</p>";
                }
            } else {
                echo "<p style='color:red;'>❌ Ответ не является корректным JSON</p>";
                echo "<pre>" . htmlspecialchars(substr($response, 0, 500)) . "</pre>";
            }
        } else {
            echo "<p style='color:red;'>❌ HTTP {$httpCode}</p>";
            if ($httpCode == 404) {
                echo "<p style='color:orange;'>💡 404 означает что nginx не направляет запросы в PHP роутер</p>";
            }
        }
    }
    echo "</div>";
}

echo "<hr>";
echo "<h2>🔧 Что делать если тесты не работают:</h2>";
echo "<ol>";
echo "<li><strong>Если все 404:</strong> Проблема в nginx конфигурации - исправьте location /api/</li>";
echo "<li><strong>Если 500:</strong> Проблема в PHP коде - проверьте логи</li>";
echo "<li><strong>Если success=false:</strong> Проблема в SearchService или OpenSearch</li>";
echo "</ol>";

echo "<p style='color:orange;'>⚠️ <strong>Удалите этот файл после тестирования!</strong></p>";
?>