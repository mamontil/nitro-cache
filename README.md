# 🚀 NitroCache for PHP (Windows Native)

**NitroCache** NitroCache is an ultra-fast caching engine built specifically for Windows environments using Rust. By leveraging Shared Memory and FFI, it bypasses the TCP/IP stack entirely, eliminating network overhead and delivering performance that standard socket-based stores (like Redis) cannot match on a local machine.

[![Tests](https://img.shields.io/badge/tests-passed-brightgreen)](#)
[![PHP](https://img.shields.io/badge/php-%3E%3D8.1-blue)](#)
[![OS](https://img.shields.io/badge/os-Windows-blue)](#)
[![License](https://img.shields.io/badge/license-MIT-green)](#)

## 🔥 Features

* **Zero Latency:** Access data in ~16-20μs. Substantially faster than Redis for local development.
* **High Throughput:** Stable performance at 60,000+ ops/sec.
* **Zero-Config:** No Docker, no Redis server, no complex setup. Just a single DLL and a lightweight background process.
* **Persistent:** Data stays in memory even when the PHP process ends.
* **Memory Managed:** Strict RAM limits (e.g., 512MB) enforced by the Rust core.

## 📊 Бенчмарки (500,000 ключей)

Тестирование проводилось на PHP 8.4 (Windows 10, SSD, 16GB RAM).

| Операция | Скорость (ops/s) | Время (500k ключей) | Latency (1 ключ) |
| :--- | :--- | :--- | :--- |
| **SET** (Запись) | **~61,500** | 8.11 сек | **~16.2 μs** |
| **GET** (Чтение) | **~57,400** | 8.70 сек | **~17.4 μs** |

## 🛠 Требования и Установка

1. Убедитесь, что расширение **FFI** включено в вашем `php.ini` (`ffi.enable=on`).
2. Добавьте этот репозиторий в ваш `composer.json` (используйте тип `vcs` и укажите ссылку на ваш GitHub).
```json
"repositories": [
    {
        "type": "vcs",
        "url": "[https://github.com/mamontil/nitro-cache](https://github.com/mamontil/nitro-cache)"
    }
],
"require": {
    "mamontil/nitro-cache": "dev-main"
}
```
4. Выполните команду `composer update`.
5. Скачайте бинарные файлы из раздела **Releases**:
    * Поместите `nitro_cache.dll` в папку `bin/` вашего проекта.
    * Запустите `nitro_cache_server.exe` (он должен работать в фоне).

## 🚀 Быстрый старт

```php
<?php
require_once 'vendor/autoload.php';

use NitroCache\Client as NitroCache;

// Инициализация с лимитом 512MB
$cache = new NitroCache(maxMemoryMb: 512);

// Запись данных (Ключ, Значение, TTL в секундах)
$cache->set('user:123', '{"id":123, "name":"Ilya", "role":"admin"}', 3600);

// Мгновенное чтение
$userData = $cache->get('user:123');

if ($userData) {
    echo "Данные из NitroCache: " . $userData;
}

// Получение статистики
$stats = $cache->getStats();
echo "Memory usage: " . $stats['usage_mb'] . " MB";
```

## 🧪 Тестирование
Библиотека поставляется с полным набором Unit-тестов (PHPUnit). Для запуска:

```bash
vendor/bin/phpunit --bootstrap vendor/autoload.php tests
```
## 🦀 Сборка из исходников (Rust)
* Если вы хотите собрать ядро самостоятельно:

1. Установите Rust (Cargo).
2. Выполните сборку в режиме release:
```bash
cargo build --release
```
3. Файлы появятся в директории target/release/:

* nitro_cache.dll — библиотека для PHP.

* nitro_cache_server.exe — серверный процесс для хранения данных.

## 📂 Структура проекта
* src/ — Исходный код PHP-клиента (PSR-4).

* bin/ — Рекомендуемое место для бинарных файлов (DLL/EXE).

* rust_src/ — Исходный код ядра на Rust.

* tests/ — Юнит-тесты PHPUnit.

## 📜 Лицензия
Проект распространяется под лицензией MIT. Свободно для коммерческого и личного использования.