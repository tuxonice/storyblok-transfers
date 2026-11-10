<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Fixture;

use DateInterval;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;

/**
 * A cache whose backend is down: every call throws, the way a PSR-16 adapter
 * over an unreachable Redis can.
 */
final class BrokenCache implements CacheInterface
{
    public function get(string $key, mixed $default = null): mixed
    {
        throw new RuntimeException('cache backend unavailable');
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        throw new RuntimeException('cache backend unavailable');
    }

    public function delete(string $key): bool
    {
        throw new RuntimeException('cache backend unavailable');
    }

    public function clear(): bool
    {
        throw new RuntimeException('cache backend unavailable');
    }

    /**
     * @param iterable<string> $keys
     *
     * @return iterable<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        throw new RuntimeException('cache backend unavailable');
    }

    /**
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        throw new RuntimeException('cache backend unavailable');
    }

    /**
     * @param iterable<string> $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        throw new RuntimeException('cache backend unavailable');
    }

    public function has(string $key): bool
    {
        throw new RuntimeException('cache backend unavailable');
    }
}
