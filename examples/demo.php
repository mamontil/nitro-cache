<?php
require_once '../NitroCache.php';

$cache = new NitroCache(10); // Ограничим кэш всего 10 МБ для теста

echo "--- Starting NitroCache Class Test ---" . PHP_EOL;

// 2. Тест: Базовая запись и чтение
echo "Testing Set/Get... ";
$cache->set("user:100", json_encode(["name" => "Artem", "role" => "admin"]), 3600);
$data = $cache->get("user:100");

if ($data && str_contains($data, "Artem")) {
    echo "[OK]" . PHP_EOL;
} else {
    echo "[FAIL] Data mismatch" . PHP_EOL;
}

// 3. Тест: Время жизни (TTL)
echo "Testing TTL (2 seconds)... ";
$cache->set("short_lived", "I am fast", 2);
sleep(3);
if ($cache->get("short_lived") === null) {
    echo "[OK] Data expired correctly" . PHP_EOL;
} else {
    echo "[FAIL] Data still exists" . PHP_EOL;
}

// 4. Тест: Удаление
echo "Testing Manual Remove... ";
$cache->set("to_be_removed", "delete me", 100);
$cache->remove("to_be_removed");
if ($cache->get("to_be_removed") === null) {
    echo "[OK] Removed successfully" . PHP_EOL;
} else {
    echo "[FAIL] Remove failed" . PHP_EOL;
}

// 5. ТЕСТ "УБИЙЦА": Проверка LRU (Вытеснение старых)
echo "Testing LRU Eviction (Filling memory limit)..." . PHP_EOL;

// Установим крошечный лимит для теста - 1 КБ (1024 байта)
$smallCache = new NitroCache(0.001);

echo "Storing 'old_key'..." . PHP_EOL;
$smallCache->set("old_key", str_repeat("O", 400), 3600); // Занимаем ~400 байт

echo "Storing 'middle_key'..." . PHP_EOL;
$smallCache->set("middle_key", str_repeat("M", 400), 3600); // Еще ~400 байт

// Сейчас занято ~800 из 1024.
// Записываем новый ключ, который вытеснит 'old_key'
echo "Storing 'new_key' (this should trigger LRU)..." . PHP_EOL;
$smallCache->set("new_key", str_repeat("N", 500), 3600);

if ($smallCache->get("old_key") === null) {
    echo "[OK] LRU worked: 'old_key' was evicted to make room for 'new_key'" . PHP_EOL;
} else {
    echo "[FAIL] 'old_key' still in cache. Memory usage: " . $smallCache->getStats()['usage_bytes'] . " bytes" . PHP_EOL;
}

// 6. Финальная статистика
echo "--- Final Stats ---" . PHP_EOL;
print_r($cache->getStats());

echo "--- All Tests Completed ---" . PHP_EOL;