<?php
// public/test_session.php
declare(strict_types=1);
session_start();

// Выводим текущее имя сессии, путь и handler
echo '<pre>';
echo 'session.save_handler = ' . ini_get('session.save_handler') . PHP_EOL;
echo 'session.save_path    = ' . ini_get('session.save_path') . PHP_EOL;
echo 'session.name         = ' . session_name() . PHP_EOL;
echo 'session_id()         = ' . session_id() . PHP_EOL;
echo '</pre>';
