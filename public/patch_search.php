<?php
/**
 * Временный патч для SearchService
 * Сохраните как /var/www/www-root/data/site/vdestor.ru/patch_search.php
 * Запустите: php patch_search.php
 */

$file = __DIR__ . '/src/Services/SearchService.php';
$backup = $file . '.backup_' . date('Y-m-d_H-i-s');

// Создаем резервную копию
copy($file, $backup);
echo "Резервная копия создана: $backup\n";

// Читаем файл
$content = file_get_contents($file);

// Патч 1: Увеличиваем таймауты
$content = str_replace(
    "'timeout' => 20,               // HTTP timeout",
    "'timeout' => 60,               // HTTP timeout - INCREASED",
    $content
);

$content = str_replace(
    "'connect_timeout' => 5,        // Connection timeout",
    "'connect_timeout' => 10,       // Connection timeout - INCREASED",
    $content
);

$content = str_replace(
    "'client_timeout' => 25         // Общий timeout клиента",
    "'client_timeout' => 65         // Общий timeout клиента - INCREASED",
    $content
);

// Патч 2: Упрощаем проверку isOpenSearchAvailable
$content = str_replace(
    '$checkInterval = min(300, 30 + ($consecutiveFailures * 10)); // От 30 до 300 сек',
    '$checkInterval = 5; // Проверяем каждые 5 секунд - TEMPORARY FIX',
    $content
);

// Патч 3: Отключаем проверку системных ресурсов временно
$content = str_replace(
    'if (!self::checkSystemResources()) {',
    'if (false && !self::checkSystemResources()) { // DISABLED TEMPORARILY',
    $content
);

// Патч 4: Упрощаем логику валидации в performOpenSearchWithTimeout
$content = str_replace(
    'set_time_limit(30); // Максимум 30 секунд',
    'set_time_limit(60); // Увеличено до 60 секунд - TEMPORARY',
    $content
);

// Сохраняем изменения
file_put_contents($file, $content);
echo "✅ Патч применен!\n\n";

echo "Изменения:\n";
echo "1. Увеличены таймауты OpenSearch (20->60, 5->10, 25->65)\n";
echo "2. Упрощена проверка доступности OpenSearch\n";
echo "3. Временно отключена проверка системных ресурсов\n";
echo "4. Увеличен лимит времени выполнения (30->60)\n\n";

echo "Для отката используйте:\n";
echo "cp $backup $file\n";
?>