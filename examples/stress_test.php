<?php
ini_set('memory_limit', '4G');
set_time_limit(0);

require_once '../NitroCache.php';

$cache = new NitroCache(2048);
$count = 5_000_000;
$chunkSize = 500_000;

echo "--- Начинаем стресс-тест 5 000 000 записей ---" . PHP_EOL;

// --- ЗАПИСЬ ---
echo "Запись...";
$startSet = microtime(true);

for ($i = 0; $i < $count; $i++) {
    $val = "payload_" . $i . "_data_string_for_testing_performance";
    $cache->set("u:$i", $val, 3600);

    if ($i % $chunkSize === 0 && $i > 0) echo ".";
}

$endSet = microtime(true);
$timeSet = $endSet - $startSet;
echo " [OK]" . PHP_EOL;

// --- ЧТЕНИЕ ---
echo "Чтение...";
$startGet = microtime(true);

for ($i = 0; $i < $count; $i++) {
    $data = $cache->get("u:$i");

    // Небольшая проверка, что данные на месте (чтобы PHP не оптимизировал цикл в пустоту)
    if ($data === null) {
        echo "Ошибка: ключ u:$i не найден!" . PHP_EOL;
        break;
    }

    if ($i % $chunkSize === 0 && $i > 0) echo ".";
}

$endGet = microtime(true);
$timeGet = $endGet - $startGet;
echo " [OK]" . PHP_EOL;

// --- ИТОГИ ---
echo PHP_EOL . "--- РЕЗУЛЬТАТЫ ---" . PHP_EOL;
echo "Запись: " . round($timeSet, 2) . " сек. (" . round($count / $timeSet) . " оп/сек)" . PHP_EOL;
echo "Чтение: " . round($timeGet, 2) . " сек. (" . round($count / $timeGet) . " оп/сек)" . PHP_EOL;

$stats = $cache->getStats();
echo "Память в Rust: " . $stats['usage_mb'] . " MB" . PHP_EOL;