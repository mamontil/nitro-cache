<?php

declare(strict_types=1);

namespace NitroCache\Tests;

use PHPUnit\Framework\TestCase;
use NitroCache\Client as NitroCache;

class NitroCacheTest extends TestCase
{
    private NitroCache $cache;

    protected function setUp(): void
    {
        // Инициализация перед каждым тестом
        $this->cache = new NitroCache(64); // 64MB для тестов достаточно
        $this->cache->clear();
    }

    public function testSetAndGetSuccess(): void
    {
        $key = 'test_key';
        $value = 'Hello NitroCache!';

        $this->assertTrue($this->cache->set($key, $value));
        $this->assertEquals($value, $this->cache->get($key));
    }

    public function testGetNonExistentKey(): void
    {
        $this->assertNull($this->cache->get('non_existent_key'));
    }

    public function testKeyExpiration(): void
    {
        // Записываем ключ на 1 секунду
        $this->cache->set('expiring_key', 'value', 1);

        // Проверяем, что он есть
        $this->assertEquals('value', $this->cache->get('expiring_key'));

        // Ждем 2 секунды
        sleep(2);

        // Теперь должен быть null
        $this->assertNull($this->cache->get('expiring_key'));
    }

    public function testRemoveKey(): void
    {
        $this->cache->set('to_remove', 'data');
        $this->cache->remove('to_remove');

        $this->assertNull($this->cache->get('to_remove'));
    }

    public function testClearAll(): void
    {
        $this->cache->set('key1', 'val1');
        $this->cache->set('key2', 'val2');

        $this->cache->clear();

        $this->assertNull($this->cache->get('key1'));
        $this->assertNull($this->cache->get('key2'));
        $this->assertEquals(0, $this->cache->getStats()['usage_bytes']);
    }

    public function testEmptyInputs(): void
    {
        $this->assertFalse($this->cache->set('', 'value'));
        $this->assertNull($this->cache->get(''));
    }

    public function testMemoryStats(): void
    {
        $this->cache->set('stats_test', 'some data');
        $stats = $this->cache->getStats();

        $this->assertArrayHasKey('usage_mb', $stats);
        $this->assertArrayHasKey('usage_bytes', $stats);
        $this->assertGreaterThan(0, $stats['usage_bytes']);
    }
}