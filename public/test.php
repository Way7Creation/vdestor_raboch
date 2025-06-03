<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$checks = [
    'PHP Version' => phpversion() >= '8.1',
    'Composer Autoload' => file_exists(__DIR__ . '/../vendor/autoload.php'),
    'Config Directory' => is_dir('/etc/vdestor/config'),
    'Database Config' => file_exists('/etc/vdestor/config/database.ini'),
    'PDO MySQL' => extension_loaded('pdo_mysql'),
    'OpenSSL' => extension_loaded('openssl'),
    'JSON' => extension_loaded('json'),
    'Session' => extension_loaded('session')
];

echo "<h3>System Check</h3><pre>";
foreach ($checks as $name => $status) {
    printf("%-20s: %s\n", $name, $status ? '✓' : '✗');
}

// Проверка БД
if ($checks['Database Config']) {
    $config = parse_ini_file('/etc/vdestor/config/database.ini', true);
    try {
        $pdo = new PDO(
            "mysql:host={$config['mysql']['host']};dbname={$config['mysql']['database']}",
            $config['mysql']['user'],
            $config['mysql']['password']
        );
        echo "\nDatabase Connection : ✓";
    } catch (Exception $e) {
        echo "\nDatabase Error      : " . $e->getMessage();
    }
}
echo "</pre>";