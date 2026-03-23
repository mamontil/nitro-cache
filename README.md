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

## 📊 Benchmarks (500,000 Keys)

*Test Environment: PHP 8.4, Windows 10, SSD, 16GB RAM.*

| Operation | Throughput (ops/s) | Total Time (500k keys) | Latency (per key) |
| :--- | :--- | :--- | :--- |
| **SET** (Write) | **~61,500** | 8.11s | **~16.2 μs** |
| **GET** (Read) | **~57,400** | 8.70s | **~17.4 μs** |

## 📦 Installation (Stable Method)

1.  **Add the Repository** Run this command to update your `composer.json` with the custom repository link:
    ```bash
    composer config repositories.nitro-cache vcs [https://github.com/mamontil/nitro-cache](https://github.com/mamontil/nitro-cache)
    ```

2.  **Configure Stability Settings** Since the package is currently in development (`dev-main`), allow Composer to install dev versions:
    ```bash
    composer config minimum-stability dev
    composer config prefer-stable true
    ```

3.  **Install the Package** ```bash
    composer require mamontil/nitro-cache:dev-main
    ```

4.  **Setup Binaries & Server**
   * **Enable FFI:** Ensure the FFI extension is enabled in your `php.ini` (`ffi.enable=on` and `extension=ffi`).
   * **Locate Binaries:** Find the core files in `vendor/mamontil/nitro-cache/bin/`.
   * **Run the Engine:** Execute `nitro_server.exe`. It must remain running in the background to manage the shared memory segment.

## 🚀 Quick Start

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use NitroCache\Client as NitroCache;

try {
    // Initialize with a 512MB memory limit
    $cache = new NitroCache(maxMemoryMb: 512);

    // Set Data (Key, Value, TTL in seconds)
    $cache->set('user:123', '{"id":123, "name":"Ilya", "role":"admin"}', 3600);

    // Instant Retrieval
    $userData = $cache->get('user:123');

    if ($userData) {
        echo "Data from NitroCache: " . $userData;
    }

    // Monitor Resource Usage
    $stats = $cache->getStats();
    echo "Current Memory Usage: " . $stats['usage_mb'] . " MB";

} catch (\Throwable $e) {
    echo "Connection Error: " . $e->getMessage();
}
```

## 🧪 Testing
* The library includes a comprehensive PHPUnit test suite. To run the tests:

```bash
vendor/bin/phpunit --bootstrap vendor/autoload.php tests
```
## 🦀 Building from Source (Rust Core)
* If you wish to compile the core engine manually:

1. Install Rust (Cargo).
2. Build in release mode:
```bash
cargo build --release
```
3. The binaries will be generated in target/release/:

* nitro_cache.dll — The shared library for PHP FFI.

* nitro_cache_server.exe — The background process for memory persistence.

## 📂 Структура проекта
* src/ — PSR-4 compliant PHP client source code.

* bin/ — Pre-compiled binaries (DLL/EXE) for Windows.

* rust_src/ — Rust core source code (Shared Memory logic).

* tests/ — PHPUnit integration tests.

## 📜 Лицензия
* This project is licensed under the MIT License. It is free for both personal and commercial use.

---

## 💖 Support the Project

If **NitroCache** helps you save server resources and speed up your applications, consider supporting its development. Every donation helps me spend more time improving the engine, adding Linux support, and building new features.

### Donate via USDT (TRC20)

**[📲 Click here to open in Trust Wallet](https://link.trustwallet.com/send?coin=195&address=TE1LGAzYWNSBdxB5JVck8JuvevUdpz7vG7)**

Or scan the QR code below:

<img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=TE1LGAzYWNSBdxB5JVck8JuvevUdpz7vG7" width="200" height="200" alt="USDT TRC20 QR Code" />

**Wallet Address:** `TE1LGAzYWNSBdxB5JVck8JuvevUdpz7vG7`

---