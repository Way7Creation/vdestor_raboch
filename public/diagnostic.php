<?php
/**
 * Диагностический скрипт для проверки дублирования
 * Разместите в public/diagnostic.php
 * Доступ: https://vdestor.ru/diagnostic.php?key=your_secret_key
 */

// Защита доступа
$secretKey = 'vde_diagnostic_2025';
if (($_GET['key'] ?? '') !== $secretKey) {
    http_response_code(403);
    die('Access denied');
}

require_once __DIR__ . '/../vendor/autoload.php';

// Отключаем вывод ошибок в браузер
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Начинаем диагностику
$diagnostics = [];
$startTime = microtime(true);

// Пробуем инициализировать систему если она еще не инициализирована
try {
    if (!class_exists('\App\Core\Bootstrap') || !\App\Core\Bootstrap::isInitialized()) {
        \App\Core\Bootstrap::init();
    }
} catch (\Exception $e) {
    $diagnostics['init_error'] = $e->getMessage();
}

// 1. Проверка Bootstrap
$diagnostics['bootstrap'] = [
    'initialized' => class_exists('\App\Core\Bootstrap') ? \App\Core\Bootstrap::isInitialized() : false,
    'components' => class_exists('\App\Core\Bootstrap') ? \App\Core\Bootstrap::getInitializedComponents() : []
];

// 2. Проверка сессии
$diagnostics['session'] = [
    'status' => session_status(),
    'status_text' => [
        PHP_SESSION_DISABLED => 'DISABLED',
        PHP_SESSION_NONE => 'NONE',
        PHP_SESSION_ACTIVE => 'ACTIVE'
    ][session_status()],
    'id' => session_id(),
    'save_handler' => ini_get('session.save_handler'),
    'gc_maxlifetime' => ini_get('session.gc_maxlifetime'),
    'data_count' => count($_SESSION ?? [])
];

// 3. Проверка кэша
$diagnostics['cache'] = \App\Core\Cache::getStats();

// 4. Проверка базы данных
try {
    $dbStats = \App\Core\Database::getStats();
    $diagnostics['database'] = [
        'connected' => true,
        'stats' => $dbStats
    ];
} catch (\Exception $e) {
    $diagnostics['database'] = [
        'connected' => false,
        'error' => $e->getMessage()
    ];
}

// 5. Проверка памяти
$diagnostics['memory'] = [
    'current' => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB',
    'peak' => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB',
    'limit' => ini_get('memory_limit')
];

// 6. Проверка загрузки
$diagnostics['load'] = [
    'average' => sys_getloadavg(),
    'execution_time' => round((microtime(true) - $startTime) * 1000, 2) . ' ms'
];

// 7. Проверка логов на дублирование
$logFile = '/var/www/www-root/data/logs/app.log';
if (file_exists($logFile)) {
    $lastLines = [];
    $handle = fopen($logFile, 'r');
    if ($handle) {
        // Читаем последние 100 строк
        $lines = [];
        while (!feof($handle)) {
            $line = fgets($handle);
            if ($line) {
                $lines[] = $line;
                if (count($lines) > 100) {
                    array_shift($lines);
                }
            }
        }
        fclose($handle);
        
        // Анализируем на дублирование
        $duplicates = [];
        $patterns = [
            'bootstrap_init' => 'Bootstrap::init()',
            'session_start' => 'session_start()',
            'multiple_fetch' => 'fetchProducts'
        ];
        
        foreach ($patterns as $key => $pattern) {
            $count = 0;
            foreach ($lines as $line) {
                if (stripos($line, $pattern) !== false) {
                    $count++;
                }
            }
            if ($count > 0) {
                $duplicates[$key] = $count;
            }
        }
        
        $diagnostics['logs'] = [
            'analyzed_lines' => count($lines),
            'duplicates' => $duplicates
        ];
    }
}

// 8. Проверка конфигурации
$diagnostics['config'] = [
    'path' => \App\Core\Config::getConfigPath(),
    'validation' => \App\Core\Config::validateSecurity()
];

// 9. Проверка прав доступа
$diagnostics['permissions'] = [];
$checkDirs = [
    '/etc/vdestor/config' => 'Config directory',
    '/tmp/vdestor_cache' => 'Cache directory',
    '/var/www/www-root/data/logs' => 'Logs directory',
    __DIR__ . '/../vendor' => 'Vendor directory'
];

foreach ($checkDirs as $dir => $name) {
    $diagnostics['permissions'][$name] = [
        'path' => $dir,
        'exists' => file_exists($dir),
        'readable' => is_readable($dir),
        'writable' => is_writable($dir),
        'permissions' => file_exists($dir) ? decoct(fileperms($dir) & 0777) : 'N/A'
    ];
}

// 10. Проверка процессов
$diagnostics['processes'] = [
    'php_version' => PHP_VERSION,
    'sapi' => PHP_SAPI,
    'user' => get_current_user(),
    'uid' => getmyuid(),
    'gid' => getmygid()
];

// Вывод результатов
header('Content-Type: application/json; charset=utf-8');
echo json_encode($diagnostics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

// Добавляем в конец инструкции
echo "\n\n";
echo "// Инструкции по исправлению:\n";
echo "// 1. Если bootstrap.initialized = false - проблема с инициализацией\n";
echo "// 2. Если session.status != ACTIVE - проблема с сессиями\n";
echo "// 3. Если logs.duplicates содержит большие числа - есть дублирование\n";
echo "// 4. Если database.stats.query_count слишком большой - много запросов\n";