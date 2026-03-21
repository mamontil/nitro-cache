<?php

class NitroCache {
    private FFI $ffi;

    /**
     * @param float|int $maxMemoryMb Лимит оперативной памяти в мегабайтах (поддерживает дробные значения)
     * @param string|null $libPath Путь к скомпилированному файлу библиотеки (.so или .dll)
     * @throws Exception Если файл библиотеки не найден или FFI не инициализирован
     */
    public function __construct(float|int $maxMemoryMb = 128, ?string $libPath = null) {
        // 1. Проверяем наличие расширения FFI
        if (!extension_loaded('ffi')) {
            throw new RuntimeException(
                "NitroCache requires the 'FFI' extension. " .
                "Please enable it in your php.ini (set ffi.enable=1 or ffi.enable=on)."
            );
        }

        // 2. Проверяем настройки ffi.enable (важный нюанс для веб-серверов)
        if (ini_get('ffi.enable') === '0' || strtolower(ini_get('ffi.enable')) === 'off') {
            throw new RuntimeException(
                "FFI is installed but disabled via 'ffi.enable' in php.ini."
            );
        }

        // 1. Автоматический поиск бинарника, если путь не указан
        if ($libPath === null) {
            $libPath = $this->detectBinaryPath();
        }

        if (!file_exists($libPath)) {
            throw new Exception("NitroCache binary not found at: $libPath. Please run 'cargo build --release'.");
        }

        $systemMemoryMb = $this->getSystemMemoryMb();
        if ($maxMemoryMb > $systemMemoryMb * 0.9) { // Не даем занять более 90% всей памяти
            throw new InvalidArgumentException(
                "Too much memory requested for NitroCache ($maxMemoryMb MB). " .
                "Your system only has $systemMemoryMb MB available."
            );
        }

        try {
            $this->ffi = FFI::cdef("
                void set_max_memory(size_t bytes);
                void cache_set(const char* key, const char* value, uint64_t ttl_sec);
                char* cache_get(const char* key);
                void cache_remove(const char* key);
                void free_string(char* s);
                size_t get_memory_usage();
            ", $libPath);

            $this->ffi->set_max_memory((int)($maxMemoryMb * 1024 * 1024));
        } catch (FFI\Exception $e) {
            throw new Exception("Failed to initialize NitroCache FFI: " . $e->getMessage());
        }
    }

    private function getSystemMemoryMb(): float {
        try {
            if (PHP_OS_FAMILY === 'Windows') {
                $mem = @shell_exec('wmic computersystem get TotalPhysicalMemory');
                if ($mem) {
                    $bytes = (float)preg_replace('/[^0-9]/', '', $mem);
                    return round($bytes / 1024 / 1024);
                }
            } else {
                if (is_readable('/proc/meminfo')) {
                    $mem = file_get_contents('/proc/meminfo');
                    if (preg_match('/MemTotal:\s+(\d+)\skB/', $mem, $matches)) {
                        return round($matches[1] / 1024);
                    }
                }
            }
        } catch (\Throwable $e) {
            // Если что-то пошло не так (права доступа и т.д.), просто возвращаем дефолт
        }
        return 1024; // Безопасный дефолт (1 ГБ)
    }

    /**
     * Определяет путь к бинарнику в зависимости от ОС и структуры папок
     */
    private function detectBinaryPath(): string {
        $baseDir = __DIR__;

        // Определяем расширение в зависимости от ОС
        $extension = (PHP_OS_FAMILY === 'Windows') ? 'dll' : 'so';
        $prefix = (PHP_OS_FAMILY === 'Windows') ? '' : 'lib';
        $filename = "{$prefix}nitro_cache.{$extension}";

        // Список мест, где мы можем искать бинарник (по приоритету)
        $searchPaths = [
            "$baseDir/bin/$filename",                       // В папке bin (для скачанных бинарников)
            "$baseDir/target/release/$filename",             // В папке компиляции Rust
            "$baseDir/$filename",                            // В корне проекта
        ];

        foreach ($searchPaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return "$baseDir/target/release/$filename"; // Возвращаем дефолт, если ничего не нашли
    }

    /**
     * Записать данные в кэш
     */
    public function set(string $key, string $value, int $ttl = 3600): void {
        // Проверка на пустые строки, чтобы Rust не получил пустой указатель
        if ($key === '' || $value === '') {
            return;
        }
        $this->ffi->cache_set($key, $value, $ttl);
    }

    /**
     * Получить данные из кэша
     * @return string|null
     */
    public function get(string $key): ?string {
        if ($key === '') {
            return null;
        }

        $ptr = $this->ffi->cache_get($key);
        if ($ptr === null) {
            return null;
        }

        try {
            $val = FFI::string($ptr);
            return $val;
        } finally {
            // Блок finally гарантирует, что мы освободим память в Rust,
            // даже если FFI::string() почему-то даст сбой
            $this->ffi->free_string($ptr);
        }
    }

    /**
     * Удалить ключ принудительно
     */
    public function remove(string $key): void {
        if ($key !== '') {
            $this->ffi->cache_remove($key);
        }
    }

    /**
     * Получить статистику использования памяти
     */
    public function getStats(): array {
        $bytes = (int)$this->ffi->get_memory_usage();
        return [
            'usage_bytes' => $bytes,
            'usage_mb'    => round($bytes / 1024 / 1024, 4),
            'usage_kb'    => round($bytes / 1024, 2),
        ];
    }
}