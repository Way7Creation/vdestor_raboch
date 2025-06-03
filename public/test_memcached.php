<?php
$m = new Memcached();
$m->addServer('127.0.0.1', 11211);
$ok = $m->set('test_key', 'hello', 5);
$val = $m->get('test_key');
if ($ok && $val === 'hello') {
    echo "👍 Memcached работает, value={$val}";
} else {
    echo "❌ Проблема с Memcached: " . $m->getResultMessage();
}
