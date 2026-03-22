<?php

declare(strict_types=1);

namespace NitroCache;

use FFI;
use RuntimeException;

/**
 * Interface NitroCacheInterface
 * Defines the core contract for the NitroCache engine.
 */
interface NitroCacheInterface {
    public function set(string $key, string $value, int $ttl = 3600): bool;
    public function get(string $key): ?string;
    public function remove(string $key): void;
    public function clear(): void;
    public function getStats(): array;
}

/**
 * NitroCache Client
 * * High-performance PHP Cache using Rust shared memory via FFI.
 * Designed specifically for high-load Windows environments.
 */
class Client implements NitroCacheInterface {
    private static ?FFI $ffi = null;
    private static ?string $libPath = null;

    /**
     * @param int|float $maxMemoryMb Initial memory limit in Megabytes.
     * @param string|null $customLibPath Optional manual path to the .dll/.so binary.
     * @throws RuntimeException If FFI is disabled or the binary is missing.
     */
    public function __construct(float|int $maxMemoryMb = 128, ?string $customLibPath = null) {
        if (!extension_loaded('ffi')) {
            throw new RuntimeException("NitroCache requires the 'FFI' extension. Check your php.ini.");
        }

        if (self::$ffi === null) {
            $path = $customLibPath ?? $this->detectBinaryPath();

            if (!file_exists($path)) {
                throw new RuntimeException("NitroCache binary not found. Expected at: $path");
            }

            try {
                self::$ffi = FFI::cdef("
                    void init_persistent_storage();
                    void set_max_memory(size_t bytes);
                    bool cache_set(const char* key, const char* value, uint64_t ttl_sec);
                    char* cache_get(const char* key);
                    void cache_remove(const char* key);
                    void cache_clear();
                    void free_string(char* s);
                    size_t get_memory_usage();
                ", $path);

                self::$libPath = $path;
                self::$ffi->init_persistent_storage();

            } catch (FFI\Exception $e) {
                throw new RuntimeException("FFI Initialization Failed: " . $e->getMessage());
            }
        }

        $this->updateMemoryLimit((int)$maxMemoryMb);
    }

    /**
     * Store a value in the cache.
     * * @param string $key Unique identifier.
     * @param string $value Data to store.
     * @param int $ttl Time to live in seconds.
     * @return bool True on success, false if memory limit is exceeded.
     */
    public function set(string $key, string $value, int $ttl = 3600): bool {
        if ($key === '' || $value === '') {
            return false;
        }
        return (bool) self::$ffi->cache_set($key, $value, $ttl);
    }

    /**
     * Retrieve a value from the cache.
     * * @param string $key
     * @return string|null The stored value or null if not found/expired.
     */
    public function get(string $key): ?string {
        if ($key === '') {
            return null;
        }

        $ptr = self::$ffi->cache_get($key);
        if ($ptr === null) {
            return null;
        }

        try {
            return FFI::string($ptr);
        } finally {
            self::$ffi->free_string($ptr);
        }
    }

    /**
     * Remove a key from the cache.
     */
    public function remove(string $key): void {
        if ($key !== '') {
            self::$ffi->cache_remove($key);
        }
    }

    /**
     * Clear all cached data and free memory.
     */
    public function clear(): void {
        self::$ffi->cache_clear();
    }

    /**
     * Update the maximum memory usage at runtime.
     * * @param int $mb Limit in Megabytes.
     */
    public function updateMemoryLimit(int $mb): void {
        self::$ffi->set_max_memory($mb * 1024 * 1024);
    }

    /**
     * Get internal engine statistics.
     * * @return array{usage_bytes: int, usage_mb: float, usage_kb: float, lib_path: string, engine: string}
     */
    public function getStats(): array {
        $bytes = (int)self::$ffi->get_memory_usage();
        return [
            'usage_bytes' => $bytes,
            'usage_mb'    => round($bytes / 1024 / 1024, 4),
            'usage_kb'    => round($bytes / 1024, 2),
            'lib_path'    => self::$libPath,
            'engine'      => 'NitroCache Rust Core'
        ];
    }

    /**
     * Locate the compiled binary.
     */
    private function detectBinaryPath(): string {
        $baseDir = dirname(__DIR__);
        $extension = (PHP_OS_FAMILY === 'Windows') ? 'dll' : 'so';
        $prefix = (PHP_OS_FAMILY === 'Windows') ? '' : 'lib';
        $filename = "{$prefix}nitro_cache.{$extension}";

        $searchPaths = [
            "$baseDir/bin/$filename",
            "$baseDir/target/release/$filename",
            "$baseDir/$filename",
        ];

        foreach ($searchPaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return "$baseDir/bin/$filename";
    }
}