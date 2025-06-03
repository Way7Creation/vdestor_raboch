<?php
// test_redis.php
namespace App\Core;

use Redis;

echo "<pre>";

// 1) Проверим, что читаем конфиг
$configFilePath = '/var/www/www-root/data/config/config_app.ini';
echo "Config exists? " . (file_exists($configFilePath) ? 'yes' : 'NO') . "\n";

$appConfig = parse_ini_file($configFilePath, true, INI_SCANNER_TYPED);
echo "Full appConfig:\n";
var_dump($appConfig);

// 2) Вытащим секцию redis
$redisCfg = $appConfig['redis'] ?? null;
echo "redisCfg:\n";
var_dump($redisCfg);

// 3) Попробуем подключиться
try {
    $r = new Redis();
    $ok = $r->connect(
        $redisCfg['host'] ?? '127.0.0.1',
        $redisCfg['port'] ?? 6379
    );
    echo "connect returned: "; var_dump($ok);

    $pwd = $redisCfg['password'] ?? '';
    echo "authing with password: '{$pwd}'\n";
    $auth = $r->auth($pwd);
    echo "auth returned: "; var_dump($auth);

    $ping = $r->ping();
    echo "ping returned: "; var_dump($ping);

} catch (\Exception $e) {
    echo "Exception: " . $e->getMessage();
}

echo "</pre>";
