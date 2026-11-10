<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Client;

use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * Caches published Content Delivery API responses in any PSR-16 cache.
 *
 * - A published read is answered from the cache while its copy is fresh
 *   ($freshFor seconds), then refreshed from Storyblok.
 * - If the refresh fails, the stale copy is served instead of the error, for up
 *   to $keepStaleFor seconds beyond the fresh window. A site keeps showing its
 *   last content through a Storyblok outage, and a logger, if given, hears
 *   about it.
 * - Draft reads are never cached: an editor previewing a change has to see it.
 * - "Not found" is remembered for the fresh window, so a bot probing random
 *   URLs does not reach Storyblok on every request, but it is never served
 *   stale: a failure while re-checking one is a failure.
 * - A cache that throws is skipped, and the read goes to Storyblok.
 *
 * What is stored is the response's plain data and the time it was fetched,
 * never hydrated transfers, so regenerating classes cannot leave old class
 * shapes in the cache.
 */
final class CachingContentClient implements ContentClient
{
    public const DEFAULT_FRESH_FOR = 300;

    public const DEFAULT_KEEP_STALE_FOR = 86400;

    /**
     * Bumped if the stored shape changes, so an old entry reads as a miss.
     */
    private const FORMAT = 1;

    /**
     * @param int $freshFor Seconds a stored copy is used without asking
     *        Storyblok. 0 always asks, but still falls back to the last copy.
     * @param int $keepStaleFor Seconds beyond the fresh window a copy is kept
     *        to fall back on when Storyblok fails.
     * @param string $keyPrefix Starts every cache key. Give each Storyblok
     *        space its own when they share one cache. Letters, digits, `_`,
     *        `.` and `-` only, so every PSR-16 cache accepts the key.
     *
     * @throws InvalidArgumentException When a setting is out of range.
     */
    public function __construct(
        private readonly ContentClient $inner,
        private readonly CacheInterface $cache,
        private readonly int $freshFor = self::DEFAULT_FRESH_FOR,
        private readonly int $keepStaleFor = self::DEFAULT_KEEP_STALE_FOR,
        private readonly string $keyPrefix = 'storyblok',
        private readonly ?LoggerInterface $logger = null,
        private readonly ?ClockInterface $clock = null,
    ) {
        if ($freshFor < 0 || $keepStaleFor < 0) {
            throw new InvalidArgumentException('freshFor and keepStaleFor cannot be negative.');
        }

        if (preg_match('/^[A-Za-z0-9_.-]+$/', $keyPrefix) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'The cache key prefix "%s" may only use letters, digits, "_", "." and "-".',
                $keyPrefix
            ));
        }
    }

    /**
     * @param array<string, string> $query
     *
     * @throws ResourceNotFoundException
     * @throws StoryblokApiException
     */
    public function get(string $path, array $query): ContentResponse
    {
        if (($query['version'] ?? null) === 'draft') {
            return $this->inner->get($path, $query);
        }

        $key = $this->key($path, $query);
        $now = $this->now();
        $entry = $this->read($key);
        $age = $entry === null ? null : $now - $entry['fetchedAt'];

        if ($entry !== null && $age < $this->freshFor) {
            return $this->answer($entry, $path);
        }

        try {
            $response = $this->inner->get($path, $query);
        } catch (ResourceNotFoundException $e) {
            $this->write($key, ['format' => self::FORMAT, 'fetchedAt' => $now, 'notFound' => true], $this->freshFor);

            throw $e;
        } catch (StoryblokApiException $e) {
            if (
                $entry !== null
                && !isset($entry['notFound'])
                && $age < $this->freshFor + $this->keepStaleFor
            ) {
                $this->logger?->warning(sprintf(
                    'Serving a stale Storyblok response for %s, fetched %d seconds ago, because the refresh '
                    . 'failed: %s',
                    $path,
                    $age,
                    $e->getMessage()
                ));

                return $this->answer($entry, $path);
            }

            throw $e;
        }

        $this->write($key, [
            'format' => self::FORMAT,
            'fetchedAt' => $now,
            'body' => $response->body,
            'total' => $response->total,
            'perPage' => $response->perPage,
        ], $this->freshFor + $this->keepStaleFor);

        return $response;
    }

    /**
     * @param array{fetchedAt: int, notFound?: true, body?: array<string, mixed>, total?: int|null,
     *              perPage?: int|null} $entry
     *
     * @throws ResourceNotFoundException
     */
    private function answer(array $entry, string $path): ContentResponse
    {
        if (isset($entry['notFound'])) {
            throw new ResourceNotFoundException('The Storyblok Content Delivery API has nothing at ' . $path);
        }

        return new ContentResponse($entry['body'] ?? [], $entry['total'] ?? null, $entry['perPage'] ?? null);
    }

    /**
     * @param array<string, string> $query
     */
    private function key(string $path, array $query): string
    {
        // Sorted, so the same read finds the same entry whatever order its
        // parameters were built in. Hashed, so the key is short and uses only
        // characters every PSR-16 cache accepts.
        ksort($query);

        return $this->keyPrefix . '.' . hash('xxh128', $path . '?' . http_build_query($query));
    }

    /**
     * Anything that is not an entry this class wrote reads as a miss.
     *
     * @return array{fetchedAt: int, notFound?: true, body?: array<string, mixed>, total?: int|null,
     *               perPage?: int|null}|null
     */
    private function read(string $key): ?array
    {
        try {
            $entry = $this->cache->get($key);
        } catch (Throwable) {
            return null;
        }

        if (
            !is_array($entry)
            || ($entry['format'] ?? null) !== self::FORMAT
            || !is_int($entry['fetchedAt'] ?? null)
        ) {
            return null;
        }

        if (($entry['notFound'] ?? null) === true) {
            return ['fetchedAt' => $entry['fetchedAt'], 'notFound' => true];
        }

        $body = $entry['body'] ?? null;
        $total = $entry['total'] ?? null;
        $perPage = $entry['perPage'] ?? null;

        if (!is_array($body) || !($total === null || is_int($total)) || !($perPage === null || is_int($perPage))) {
            return null;
        }

        /** @var array<string, mixed> $body */
        return ['fetchedAt' => $entry['fetchedAt'], 'body' => $body, 'total' => $total, 'perPage' => $perPage];
    }

    /**
     * A cache that throws is skipped: failing to remember a response must not
     * fail the read that produced it.
     *
     * @param array<string, mixed> $entry
     */
    private function write(string $key, array $entry, int $ttl): void
    {
        if ($ttl <= 0) {
            return;
        }

        try {
            $this->cache->set($key, $entry, $ttl);
        } catch (Throwable) {
        }
    }

    private function now(): int
    {
        return $this->clock?->now()->getTimestamp() ?? time();
    }
}
