<?php
/**
 * ПОЛНАЯ СИСТЕМА ДИАГНОСТИКИ VDestor B2B
 * Файл: src/views/admin/diagnost.php
 * 
 * Комплексная диагностика всех компонентов системы
 * с визуализацией и экспортом результатов
 */

// Защита от прямого доступа
if (!isset($this) && !defined('LAYOUT_RENDERING')) {
    http_response_code(403);
    die('Direct access denied');
}

// Проверка прав администратора
use App\Services\AuthService;
if (!AuthService::checkRole('admin')) {
    header('Location: /login');
    exit;
}

// Начинаем сбор диагностики
$diagnosticStartTime = microtime(true);
$diagnostics = [];
$alerts = [];
$recommendations = [];

try {
    // 1. СИСТЕМНАЯ ИНФОРМАЦИЯ
    $diagnostics['system'] = [
        'title' => '🖥️ Системная информация',
        'status' => 'info',
        'data' => [
            'Hostname' => gethostname(),
            'Server IP' => $_SERVER['SERVER_ADDR'] ?? 'Unknown',
            'Client IP' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
            'Server Software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'Server Time' => date('Y-m-d H:i:s'),
            'Timezone' => date_default_timezone_get(),
            'OS' => PHP_OS . ' ' . php_uname('r'),
            'Architecture' => php_uname('m'),
            'Server Load' => implode(', ', sys_getloadavg()),
            'Uptime' => shell_exec('uptime -p') ?: 'Unknown'
        ]
    ];

    // 2. PHP КОНФИГУРАЦИЯ
    $phpVersion = PHP_VERSION;
    $phpVersionParts = explode('.', $phpVersion);
    $phpStatus = (version_compare($phpVersion, '7.4.0', '>=')) ? 'success' : 'error';
    
    $diagnostics['php'] = [
        'title' => '🐘 PHP Конфигурация',
        'status' => $phpStatus,
        'data' => [
            'Version' => $phpVersion . ($phpStatus === 'success' ? ' ✅' : ' ❌ (Требуется 7.4+)'),
            'SAPI' => PHP_SAPI,
            'Memory Limit' => ini_get('memory_limit'),
            'Max Execution Time' => ini_get('max_execution_time') . 's',
            'Post Max Size' => ini_get('post_max_size'),
            'Upload Max Filesize' => ini_get('upload_max_filesize'),
            'Max File Uploads' => ini_get('max_file_uploads'),
            'Current Memory Usage' => $this->formatBytes(memory_get_usage(true)),
            'Peak Memory Usage' => $this->formatBytes(memory_get_peak_usage(true)),
            'OPcache Enabled' => ini_get('opcache.enable') ? 'Yes ✅' : 'No ❌',
            'Error Reporting' => error_reporting(),
            'Display Errors' => ini_get('display_errors') ? 'On ⚠️' : 'Off ✅'
        ],
        'extensions' => [
            'PDO' => extension_loaded('pdo'),
            'PDO MySQL' => extension_loaded('pdo_mysql'),
            'JSON' => extension_loaded('json'),
            'cURL' => extension_loaded('curl'),
            'Mbstring' => extension_loaded('mbstring'),
            'OpenSSL' => extension_loaded('openssl'),
            'GD' => extension_loaded('gd'),
            'ZIP' => extension_loaded('zip'),
            'XML' => extension_loaded('xml'),
            'Session' => extension_loaded('session'),
            'Filter' => extension_loaded('filter'),
            'Hash' => extension_loaded('hash')
        ]
    ];

    // Проверка критических расширений
    $criticalExtensions = ['pdo', 'pdo_mysql', 'json', 'mbstring', 'session'];
    foreach ($criticalExtensions as $ext) {
        if (!extension_loaded($ext)) {
            $alerts[] = ['type' => 'error', 'message' => "Критическое расширение PHP не установлено: {$ext}"];
        }
    }

    // 3. БАЗА ДАННЫХ
    try {
        $pdo = \App\Core\Database::getConnection();
        $dbVersion = $pdo->query("SELECT VERSION()")->fetchColumn();
        
        // Детальная статистика БД
        $dbStats = [];
        $statsQuery = $pdo->query("SHOW STATUS");
        while ($row = $statsQuery->fetch(\PDO::FETCH_NUM)) {
            $dbStats[$row[0]] = $row[1];
        }
        
        // Размер базы данных
        $dbSize = $pdo->query("
            SELECT 
                SUM(data_length + index_length) as size,
                COUNT(*) as tables_count
            FROM information_schema.tables 
            WHERE table_schema = DATABASE()
        ")->fetch();
        
        // Проверка важных таблиц
        $requiredTables = [
            'products', 'users', 'carts', 'prices', 'stock_balances',
            'categories', 'brands', 'series', 'cities', 'warehouses',
            'sessions', 'audit_logs', 'application_logs', 'metrics'
        ];
        
        $existingTables = [];
        $tablesQuery = $pdo->query("SHOW TABLES");
        while ($row = $tablesQuery->fetch(\PDO::FETCH_NUM)) {
            $existingTables[] = $row[0];
        }
        
        $missingTables = array_diff($requiredTables, $existingTables);
        
        // Статистика по таблицам
        $tableStats = [];
        foreach ($existingTables as $table) {
            try {
                $count = $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
                $tableStats[$table] = $count;
            } catch (\Exception $e) {
                $tableStats[$table] = 'Error';
            }
        }
        
        $diagnostics['database'] = [
            'title' => '🗄️ База данных MySQL',
            'status' => empty($missingTables) ? 'success' : 'warning',
            'data' => [
                'Status' => '✅ Connected',
                'Version' => $dbVersion,
                'Database' => $pdo->query("SELECT DATABASE()")->fetchColumn(),
                'Charset' => $pdo->query("SHOW VARIABLES LIKE 'character_set_database'")->fetch()['Value'],
                'Collation' => $pdo->query("SHOW VARIABLES LIKE 'collation_database'")->fetch()['Value'],
                'Size' => $this->formatBytes($dbSize['size'] ?? 0),
                'Tables Count' => $dbSize['tables_count'] ?? 0,
                'Active Connections' => $dbStats['Threads_connected'] ?? 0,
                'Max Connections' => $dbStats['Max_used_connections'] ?? 0,
                'Uptime' => $this->formatUptime($dbStats['Uptime'] ?? 0),
                'Queries' => number_format($dbStats['Questions'] ?? 0),
                'Slow Queries' => $dbStats['Slow_queries'] ?? 0
            ],
            'tables' => $tableStats,
            'missing_tables' => $missingTables
        ];
        
        if (!empty($missingTables)) {
            $alerts[] = ['type' => 'warning', 'message' => 'Отсутствуют таблицы: ' . implode(', ', $missingTables)];
        }
        
    } catch (\Exception $e) {
        $diagnostics['database'] = [
            'title' => '🗄️ База данных MySQL',
            'status' => 'error',
            'error' => $e->getMessage()
        ];
        $alerts[] = ['type' => 'error', 'message' => 'База данных недоступна: ' . $e->getMessage()];
    }

    // 4. OPENSEARCH
    try {
        $client = \OpenSearch\ClientBuilder::create()
            ->setHosts(['localhost:9200'])
            ->setConnectionParams(['timeout' => 5, 'connect_timeout' => 3])
            ->build();
        
        $health = $client->cluster()->health();
        $stats = $client->cluster()->stats();
        $indices = $client->indices()->stats(['index' => 'products*']);
        
        // Проверка алиаса
        $aliases = [];
        try {
            $aliasInfo = $client->indices()->getAlias(['name' => 'products_current']);
            $aliases = array_keys($aliasInfo);
        } catch (\Exception $e) {
            // Алиас может не существовать
        }
        
        $diagnostics['opensearch'] = [
            'title' => '🔍 OpenSearch',
            'status' => $health['status'] === 'green' ? 'success' : ($health['status'] === 'yellow' ? 'warning' : 'error'),
            'data' => [
                'Status' => $health['status'] . ' ' . ($health['status'] === 'green' ? '✅' : ($health['status'] === 'yellow' ? '⚠️' : '❌')),
                'Cluster Name' => $health['cluster_name'],
                'Nodes' => $health['number_of_nodes'],
                'Data Nodes' => $health['number_of_data_nodes'],
                'Active Shards' => $health['active_shards'],
                'Documents' => number_format($stats['indices']['docs']['count'] ?? 0),
                'Index Size' => $this->formatBytes($stats['indices']['store']['size_in_bytes'] ?? 0),
                'Current Alias' => !empty($aliases) ? implode(', ', $aliases) : 'Not configured ⚠️',
                'JVM Heap Used' => round(($stats['nodes']['jvm']['mem']['heap_used_in_bytes'] ?? 0) / ($stats['nodes']['jvm']['mem']['heap_max_in_bytes'] ?? 1) * 100, 2) . '%'
            ]
        ];
        
        if ($health['status'] !== 'green') {
            $alerts[] = ['type' => 'warning', 'message' => "OpenSearch cluster status: {$health['status']}"];
        }
        
    } catch (\Exception $e) {
        $diagnostics['opensearch'] = [
            'title' => '🔍 OpenSearch',
            'status' => 'error',
            'error' => 'OpenSearch недоступен: ' . $e->getMessage()
        ];
        $alerts[] = ['type' => 'warning', 'message' => 'OpenSearch недоступен, используется MySQL fallback'];
    }

    // 5. ФАЙЛОВАЯ СИСТЕМА
    $paths = [
        'Document Root' => $_SERVER['DOCUMENT_ROOT'],
        'Project Root' => dirname($_SERVER['DOCUMENT_ROOT']),
        'Config Directory' => '/var/www/config/vdestor',
        'Log Directory' => '/var/log/vdestor',
        'Cache Directory' => '/tmp/vdestor_cache',
        'Sessions Directory' => session_save_path() ?: '/tmp',
        'Upload Directory' => $_SERVER['DOCUMENT_ROOT'] . '/uploads',
        'Assets Directory' => $_SERVER['DOCUMENT_ROOT'] . '/assets/dist'
    ];
    
    $fileSystemChecks = [];
    foreach ($paths as $name => $path) {
        $exists = file_exists($path);
        $writable = $exists && is_writable($path);
        $readable = $exists && is_readable($path);
        
        $fileSystemChecks[$name] = [
            'path' => $path,
            'exists' => $exists,
            'readable' => $readable,
            'writable' => $writable,
            'size' => $exists && is_dir($path) ? $this->getDirectorySize($path) : 0,
            'files' => $exists && is_dir($path) ? $this->countFiles($path) : 0
        ];
        
        if (!$exists && in_array($name, ['Log Directory', 'Cache Directory', 'Upload Directory'])) {
            $alerts[] = ['type' => 'warning', 'message' => "Директория не существует: {$name} ({$path})"];
        }
    }
    
    // Дисковое пространство
    $diskFree = disk_free_space('/');
    $diskTotal = disk_total_space('/');
    $diskUsedPercent = round((($diskTotal - $diskFree) / $diskTotal) * 100, 2);
    
    $diagnostics['filesystem'] = [
        'title' => '📁 Файловая система',
        'status' => $diskUsedPercent > 90 ? 'error' : ($diskUsedPercent > 80 ? 'warning' : 'success'),
        'data' => [
            'Disk Total' => $this->formatBytes($diskTotal),
            'Disk Free' => $this->formatBytes($diskFree),
            'Disk Used' => $diskUsedPercent . '%',
            'Inodes Free' => shell_exec("df -i / | awk 'NR==2 {print $4}'") ?: 'Unknown'
        ],
        'paths' => $fileSystemChecks
    ];
    
    if ($diskUsedPercent > 90) {
        $alerts[] = ['type' => 'error', 'message' => "Критически мало места на диске: {$diskUsedPercent}%"];
    } elseif ($diskUsedPercent > 80) {
        $alerts[] = ['type' => 'warning', 'message' => "Мало места на диске: {$diskUsedPercent}%"];
    }

    // 6. СЕССИИ
    $sessionHandler = ini_get('session.save_handler');
    $sessionPath = session_save_path();
    
    // Статистика сессий из БД
    $sessionStats = [];
    if ($sessionHandler === 'user' || $sessionHandler === 'db') {
        try {
            $activeSessions = $pdo->query("
                SELECT COUNT(*) as total,
                       SUM(expires_at > NOW()) as active,
                       SUM(expires_at <= NOW()) as expired
                FROM sessions
            ")->fetch();
            
            $sessionStats = [
                'Total Sessions' => $activeSessions['total'] ?? 0,
                'Active Sessions' => $activeSessions['active'] ?? 0,
                'Expired Sessions' => $activeSessions['expired'] ?? 0
            ];
        } catch (\Exception $e) {
            $sessionStats = ['Error' => $e->getMessage()];
        }
    }
    
    $diagnostics['sessions'] = [
        'title' => '🔐 Сессии',
        'status' => session_status() === PHP_SESSION_ACTIVE ? 'success' : 'error',
        'data' => [
            'Status' => session_status() === PHP_SESSION_ACTIVE ? '✅ Active' : '❌ Inactive',
            'Handler' => $sessionHandler,
            'Save Path' => $sessionPath,
            'Session ID' => session_id(),
            'Session Name' => session_name(),
            'Cookie Lifetime' => ini_get('session.cookie_lifetime') . 's',
            'GC Maxlifetime' => ini_get('session.gc_maxlifetime') . 's',
            'Use Cookies' => ini_get('session.use_cookies') ? 'Yes' : 'No',
            'Use Only Cookies' => ini_get('session.use_only_cookies') ? 'Yes' : 'No',
            'Cookie Secure' => ini_get('session.cookie_secure') ? 'Yes ✅' : 'No ⚠️',
            'Cookie HttpOnly' => ini_get('session.cookie_httponly') ? 'Yes ✅' : 'No ⚠️'
        ],
        'stats' => $sessionStats
    ];

    // 7. CACHE СИСТЕМА
    try {
        $cacheStats = \App\Core\Cache::getStats();
        $cacheTestKey = 'diagnostic_test_' . time();
        $cacheTestValue = 'test_value_' . uniqid();
        
        $writeTest = \App\Core\Cache::set($cacheTestKey, $cacheTestValue, 60);
        $readTest = \App\Core\Cache::get($cacheTestKey);
        $deleteTest = \App\Core\Cache::delete($cacheTestKey);
        
        $diagnostics['cache'] = [
            'title' => '💾 Система кеширования',
            'status' => ($writeTest && $readTest === $cacheTestValue) ? 'success' : 'error',
            'data' => [
                'Enabled' => $cacheStats['enabled'] ? 'Yes ✅' : 'No ❌',
                'Cache Directory' => $cacheStats['cache_dir'] ?? 'Unknown',
                'Total Files' => $cacheStats['total_files'] ?? 0,
                'Valid Files' => $cacheStats['valid_files'] ?? 0,
                'Total Size' => $this->formatBytes($cacheStats['total_size'] ?? 0),
                'Memory Items' => $cacheStats['memory_items'] ?? 0,
                'Write Test' => $writeTest ? 'Passed ✅' : 'Failed ❌',
                'Read Test' => ($readTest === $cacheTestValue) ? 'Passed ✅' : 'Failed ❌',
                'Delete Test' => $deleteTest ? 'Passed ✅' : 'Failed ❌'
            ]
        ];
        
    } catch (\Exception $e) {
        $diagnostics['cache'] = [
            'title' => '💾 Система кеширования',
            'status' => 'error',
            'error' => $e->getMessage()
        ];
    }

    // 8. SECURITY ПРОВЕРКИ
    $headers = getallheaders();
    $securityHeaders = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'X-XSS-Protection' => '1; mode=block',
        'Strict-Transport-Security' => null, // Любое значение подходит
        'Content-Security-Policy' => null,
        'Referrer-Policy' => null
    ];
    
    $headerChecks = [];
    foreach ($securityHeaders as $header => $expectedValue) {
        $headerLower = strtolower($header);
        $found = false;
        $actualValue = null;
        
        foreach ($headers as $key => $value) {
            if (strtolower($key) === $headerLower) {
                $found = true;
                $actualValue = $value;
                break;
            }
        }
        
        $headerChecks[$header] = [
            'present' => $found,
            'value' => $actualValue,
            'expected' => $expectedValue,
            'valid' => $found && ($expectedValue === null || $actualValue === $expectedValue)
        ];
    }
    
    // Проверка HTTPS
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443;
    
    // Проверка конфигурации
    $configIssues = \App\Core\Config::validateSecurity();
    
    $diagnostics['security'] = [
        'title' => '🔒 Безопасность',
        'status' => ($isHttps && empty($configIssues)) ? 'success' : 'warning',
        'data' => [
            'HTTPS' => $isHttps ? 'Enabled ✅' : 'Disabled ❌',
            'Session Secure' => ini_get('session.cookie_secure') ? 'Yes ✅' : 'No ⚠️',
            'Session HttpOnly' => ini_get('session.cookie_httponly') ? 'Yes ✅' : 'No ⚠️',
            'Display Errors' => ini_get('display_errors') ? 'On ⚠️' : 'Off ✅',
            'Error Log' => ini_get('error_log') ?: 'Not configured ⚠️',
            'Allow URL Fopen' => ini_get('allow_url_fopen') ? 'On ⚠️' : 'Off ✅',
            'Allow URL Include' => ini_get('allow_url_include') ? 'On ❌' : 'Off ✅'
        ],
        'headers' => $headerChecks,
        'config_issues' => $configIssues
    ];
    
    if (!$isHttps) {
        $alerts[] = ['type' => 'warning', 'message' => 'HTTPS не включен'];
    }

    // 9. API ПРОВЕРКИ
    $apiEndpoints = [
        '/api/test' => 'Test API',
        '/api/search?q=test&limit=1' => 'Search API',
        '/api/availability?product_ids=1&city_id=1' => 'Availability API'
    ];
    
    $apiChecks = [];
    foreach ($apiEndpoints as $endpoint => $name) {
        $startTime = microtime(true);
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'http://localhost' . $endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Requested-With: XMLHttpRequest']);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);
            curl_close($ch);
            
            $data = json_decode($response, true);
            
            $apiChecks[$name] = [
                'status' => $httpCode === 200 ? 'success' : 'error',
                'http_code' => $httpCode,
                'response_time' => $responseTime . 'ms',
                'success' => $data['success'] ?? false
            ];
            
        } catch (\Exception $e) {
            $apiChecks[$name] = [
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }
    }
    
    $diagnostics['api'] = [
        'title' => '🌐 API Endpoints',
        'status' => 'info',
        'endpoints' => $apiChecks
    ];

    // 10. ПРОИЗВОДИТЕЛЬНОСТЬ
    try {
        // Статистика из Database класса
        $dbStats = \App\Core\Database::getStats();
        
        // Метрики из базы
        $metricsQuery = $pdo->query("
            SELECT 
                metric_type,
                COUNT(*) as count,
                AVG(value) as avg_value,
                MAX(value) as max_value
            FROM metrics
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            GROUP BY metric_type
        ");
        
        $metrics = [];
        while ($row = $metricsQuery->fetch()) {
            $metrics[$row['metric_type']] = [
                'count' => $row['count'],
                'avg' => round($row['avg_value'], 3),
                'max' => round($row['max_value'], 3)
            ];
        }
        
        $diagnostics['performance'] = [
            'title' => '⚡ Производительность',
            'status' => 'info',
            'data' => [
                'DB Query Count' => $dbStats['query_count'] ?? 0,
                'DB Total Time' => round($dbStats['total_time'] ?? 0, 3) . 's',
                'DB Avg Query Time' => round($dbStats['average_time'] ?? 0, 3) . 's',
                'Page Load Time' => round(microtime(true) - $diagnosticStartTime, 3) . 's',
                'Memory Usage' => $this->formatBytes(memory_get_usage(true)),
                'Peak Memory' => $this->formatBytes(memory_get_peak_usage(true))
            ],
            'metrics' => $metrics
        ];
        
    } catch (\Exception $e) {
        $diagnostics['performance'] = [
            'title' => '⚡ Производительность',
            'status' => 'error',
            'error' => $e->getMessage()
        ];
    }

    // 11. ОЧЕРЕДИ И ЗАДАЧИ
    try {
        $queueStats = \App\Services\QueueService::getStats();
        
        $diagnostics['queues'] = [
            'title' => '📋 Очереди задач',
            'status' => 'info',
            'data' => [
                'Queue Length' => $queueStats['queue_length'] ?? 0,
                'Pending Jobs' => $queueStats['by_status']['pending']['count'] ?? 0,
                'Processing Jobs' => $queueStats['by_status']['processing']['count'] ?? 0,
                'Completed Jobs' => $queueStats['by_status']['completed']['count'] ?? 0,
                'Failed Jobs' => $queueStats['by_status']['failed']['count'] ?? 0
            ],
            'by_type' => $queueStats['by_type'] ?? []
        ];
        
        if (($queueStats['by_status']['failed']['count'] ?? 0) > 100) {
            $alerts[] = ['type' => 'warning', 'message' => 'Много неудачных задач в очереди'];
        }
        
    } catch (\Exception $e) {
        $diagnostics['queues'] = [
            'title' => '📋 Очереди задач',
            'status' => 'error',
            'error' => $e->getMessage()
        ];
    }

    // 12. ЛОГИ И ОШИБКИ
    try {
        // Последние ошибки из логов БД
        $recentErrors = $pdo->query("
            SELECT level, message, created_at
            FROM application_logs
            WHERE level IN ('error', 'critical', 'emergency')
            AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ORDER BY created_at DESC
            LIMIT 10
        ")->fetchAll();
        
        // Статистика логов
        $logStats = $pdo->query("
            SELECT 
                level,
                COUNT(*) as count
            FROM application_logs
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            GROUP BY level
        ")->fetchAll(\PDO::FETCH_KEY_PAIR);
        
        $diagnostics['logs'] = [
            'title' => '📝 Логи и ошибки',
            'status' => (!empty($recentErrors) ? 'warning' : 'success'),
            'stats' => $logStats,
            'recent_errors' => $recentErrors
        ];
        
    } catch (\Exception $e) {
        $diagnostics['logs'] = [
            'title' => '📝 Логи и ошибки',
            'status' => 'error',
            'error' => $e->getMessage()
        ];
    }

    // 13. ПРОВЕРКА ДАННЫХ
    try {
        $dataChecks = [];
        
        // Товары без цен
        $productsWithoutPrices = $pdo->query("
            SELECT COUNT(*) FROM products p
            LEFT JOIN prices pr ON p.product_id = pr.product_id AND pr.is_base = 1
            WHERE pr.price_id IS NULL
        ")->fetchColumn();
        
        // Товары без остатков
        $productsWithoutStock = $pdo->query("
            SELECT COUNT(DISTINCT p.product_id) FROM products p
            LEFT JOIN stock_balances sb ON p.product_id = sb.product_id
            WHERE sb.product_id IS NULL
        ")->fetchColumn();
        
        // Дубликаты артикулов
        $duplicateExternalIds = $pdo->query("
            SELECT COUNT(*) FROM (
                SELECT external_id, COUNT(*) as cnt 
                FROM products 
                GROUP BY external_id 
                HAVING cnt > 1
            ) as duplicates
        ")->fetchColumn();
        
        $dataChecks = [
            'Total Products' => $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn(),
            'Products Without Prices' => $productsWithoutPrices,
            'Products Without Stock' => $productsWithoutStock,
            'Duplicate External IDs' => $duplicateExternalIds,
            'Total Users' => $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
            'Active Sessions' => $pdo->query("SELECT COUNT(*) FROM sessions WHERE expires_at > NOW()")->fetchColumn()
        ];
        
        $diagnostics['data_integrity'] = [
            'title' => '✅ Целостность данных',
            'status' => ($productsWithoutPrices > 0 || $duplicateExternalIds > 0) ? 'warning' : 'success',
            'checks' => $dataChecks
        ];
        
        if ($productsWithoutPrices > 0) {
            $alerts[] = ['type' => 'warning', 'message' => "Найдено {$productsWithoutPrices} товаров без цен"];
        }
        if ($duplicateExternalIds > 0) {
            $alerts[] = ['type' => 'error', 'message' => "Найдено {$duplicateExternalIds} дубликатов артикулов"];
        }
        
    } catch (\Exception $e) {
        $diagnostics['data_integrity'] = [
            'title' => '✅ Целостность данных',
            'status' => 'error',
            'error' => $e->getMessage()
        ];
    }

} catch (\Exception $e) {
    $alerts[] = ['type' => 'error', 'message' => 'Критическая ошибка диагностики: ' . $e->getMessage()];
}

// Вычисляем общий статус системы
$overallStatus = 'success';
$criticalErrors = 0;
$warnings = 0;

foreach ($diagnostics as $component) {
    if ($component['status'] === 'error') {
        $criticalErrors++;
        $overallStatus = 'error';
    } elseif ($component['status'] === 'warning' && $overallStatus !== 'error') {
        $warnings++;
        $overallStatus = 'warning';
    }
}

// Вычисляем Health Score
$totalComponents = count($diagnostics);
$healthyComponents = 0;
foreach ($diagnostics as $component) {
    if ($component['status'] === 'success' || $component['status'] === 'info') {
        $healthyComponents++;
    } elseif ($component['status'] === 'warning') {
        $healthyComponents += 0.5; // Предупреждения считаем как половину
    }
}
$healthScore = round(($healthyComponents / $totalComponents) * 100);

// Время выполнения диагностики
$executionTime = microtime(true) - $diagnosticStartTime;

// Рекомендации на основе результатов
if ($diskUsedPercent > 80) {
    $recommendations[] = "Освободите место на диске или увеличьте дисковое пространство";
}
if (!empty($missingTables)) {
    $recommendations[] = "Выполните миграции БД для создания недостающих таблиц";
}
if (!$isHttps) {
    $recommendations[] = "Включите HTTPS для безопасности";
}
if ($productsWithoutPrices > 0) {
    $recommendations[] = "Загрузите цены для товаров без цен";
}

// Вспомогательные функции
if (!function_exists('formatBytes')) {
    function formatBytes($bytes) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}

if (!function_exists('formatUptime')) {
    function formatUptime($seconds) {
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        return "{$days}d {$hours}h {$minutes}m";
    }
}

if (!function_exists('getDirectorySize')) {
    function getDirectorySize($dir) {
        $size = 0;
        $files = 0;
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
                RecursiveIteratorIterator::CATCH_GET_CHILD
            );
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                    $files++;
                    if ($files > 1000) break; // Ограничиваем для больших директорий
                }
            }
        } catch (\Exception $e) {
            // Игнорируем ошибки доступа
        }
        return $size;
    }
}

if (!function_exists('countFiles')) {
    function countFiles($dir) {
        try {
            $fi = new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS);
            return iterator_count($fi);
        } catch (\Exception $e) {
            return 0;
        }
    }
}

// Обёртки для функций в контексте класса
$this->formatBytes = function($bytes) { return formatBytes($bytes); };
$this->formatUptime = function($seconds) { return formatUptime($seconds); };
$this->getDirectorySize = function($dir) { return getDirectorySize($dir); };
$this->countFiles = function($dir) { return countFiles($dir); };
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Полная диагностика системы - VDestor Admin</title>
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #0a0e27;
            color: #e4e6eb;
            line-height: 1.6;
            min-height: 100vh;
        }
        
        /* Градиентный фон */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, #0a0e27 0%, #1a1d3a 50%, #0f172a 100%);
            z-index: -2;
        }
        
        /* Анимированная сетка на фоне */
        body::after {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-image: 
                linear-gradient(rgba(99, 102, 241, 0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(99, 102, 241, 0.03) 1px, transparent 1px);
            background-size: 50px 50px;
            z-index: -1;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        /* Заголовок */
        .header {
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            border-radius: 20px;
            padding: 40px;
            margin-bottom: 30px;
            box-shadow: 0 20px 40px rgba(99, 102, 241, 0.3);
            position: relative;
            overflow: hidden;
        }
        
        .header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: pulse 4s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(0.8); opacity: 0.5; }
            50% { transform: scale(1.2); opacity: 0.8; }
        }
        
        .header h1 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 10px;
            position: relative;
            z-index: 1;
        }
        
        .header-info {
            display: flex;
            gap: 30px;
            flex-wrap: wrap;
            position: relative;
            z-index: 1;
        }
        
        .header-stat {
            display: flex;
            flex-direction: column;
        }
        
        .header-stat-label {
            font-size: 0.875rem;
            opacity: 0.9;
        }
        
        .header-stat-value {
            font-size: 1.5rem;
            font-weight: 600;
        }
        
        /* Health Score */
        .health-score {
            position: absolute;
            top: 40px;
            right: 40px;
            width: 120px;
            height: 120px;
            z-index: 1;
        }
        
        .health-score svg {
            width: 100%;
            height: 100%;
            transform: rotate(-90deg);
        }
        
        .health-score-bg {
            fill: none;
            stroke: rgba(255,255,255,0.2);
            stroke-width: 8;
        }
        
        .health-score-progress {
            fill: none;
            stroke: white;
            stroke-width: 8;
            stroke-linecap: round;
            transition: stroke-dashoffset 1s ease-in-out;
        }
        
        .health-score-text {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
        }
        
        .health-score-value {
            font-size: 2rem;
            font-weight: 700;
        }
        
        .health-score-label {
            font-size: 0.75rem;
            opacity: 0.9;
        }
        
        /* Alerts */
        .alerts-container {
            margin-bottom: 30px;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
            animation: slideIn 0.3s ease-out;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
        }
        
        .alert-warning {
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid rgba(245, 158, 11, 0.3);
            color: #fcd34d;
        }
        
        .alert-info {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.3);
            color: #93bbfc;
        }
        
        .alert-icon {
            font-size: 1.5rem;
        }
        
        /* Component Cards */
        .component-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .component-card {
            background: rgba(30, 35, 60, 0.5);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(99, 102, 241, 0.2);
            border-radius: 16px;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        .component-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            border-color: rgba(99, 102, 241, 0.4);
        }
        
        .component-header {
            padding: 20px;
            background: rgba(99, 102, 241, 0.1);
            border-bottom: 1px solid rgba(99, 102, 241, 0.2);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .component-title {
            font-size: 1.25rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .component-status {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            animation: pulse-status 2s ease-in-out infinite;
        }
        
        @keyframes pulse-status {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.7; transform: scale(1.2); }
        }
        
        .status-success { background: #10b981; }
        .status-warning { background: #f59e0b; }
        .status-error { background: #ef4444; }
        .status-info { background: #3b82f6; }
        
        .component-body {
            padding: 20px;
        }
        
        .component-error {
            color: #fca5a5;
            padding: 15px;
            background: rgba(239, 68, 68, 0.1);
            border-radius: 8px;
            margin-bottom: 15px;
        }
        
        /* Data Tables */
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table td {
            padding: 8px 0;
            border-bottom: 1px solid rgba(99, 102, 241, 0.1);
        }
        
        .data-table td:first-child {
            color: #9ca3af;
            width: 40%;
        }
        
        .data-table td:last-child {
            font-family: 'Monaco', 'Consolas', monospace;
            color: #c3c8d9;
        }
        
        .data-table tr:last-child td {
            border-bottom: none;
        }
        
        /* Extensions Grid */
        .extensions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 10px;
            margin-top: 15px;
        }
        
        .extension-item {
            padding: 10px 15px;
            background: rgba(99, 102, 241, 0.1);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: all 0.2s ease;
        }
        
        .extension-item:hover {
            background: rgba(99, 102, 241, 0.2);
        }
        
        .extension-name {
            font-weight: 500;
        }
        
        .extension-status {
            font-size: 1.2rem;
        }
        
        /* File System */
        .filesystem-grid {
            display: grid;
            gap: 15px;
            margin-top: 15px;
        }
        
        .filesystem-item {
            background: rgba(30, 35, 60, 0.3);
            border: 1px solid rgba(99, 102, 241, 0.1);
            border-radius: 12px;
            padding: 15px;
            transition: all 0.2s ease;
        }
        
        .filesystem-item:hover {
            background: rgba(30, 35, 60, 0.5);
            border-color: rgba(99, 102, 241, 0.3);
        }
        
        .filesystem-name {
            font-weight: 600;
            margin-bottom: 5px;
            color: #e4e6eb;
        }
        
        .filesystem-path {
            font-size: 0.875rem;
            color: #6b7280;
            font-family: 'Monaco', 'Consolas', monospace;
            margin-bottom: 10px;
            word-break: break-all;
        }
        
        .filesystem-stats {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .filesystem-stat {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.875rem;
        }
        
        .filesystem-stat-icon {
            font-size: 1rem;
        }
        
        /* Tables Info */
        .tables-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 10px;
            margin-top: 15px;
            max-height: 300px;
            overflow-y: auto;
        }
    }
}
        
       