<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Fixture;

use RuntimeException;
use Throwable;
use Tlab\StoryblokTransfers\Client\ContentClient;
use Tlab\StoryblokTransfers\Client\ContentResponse;

/**
 * A ContentClient that answers from a queue and records what it was asked.
 *
 * The transport is already covered against MockHandler in
 * StoryblokContentClientTest, so the repository tests assert on the path and
 * query they produced rather than on HTTP.
 */
final class FakeContentClient implements ContentClient
{
    /** @var list<array{path: string, query: array<string, string>}> */
    public array $requests = [];

    /** @var list<ContentResponse|Throwable> */
    private array $queue;

    public function __construct(ContentResponse|Throwable ...$responses)
    {
        $this->queue = array_values($responses);
    }

    public static function returning(ContentResponse|Throwable ...$responses): self
    {
        return new self(...$responses);
    }

    /**
     * @param array<string, string> $query
     */
    public function get(string $path, array $query): ContentResponse
    {
        $this->requests[] = ['path' => $path, 'query' => $query];

        $next = array_shift($this->queue);

        if ($next === null) {
            throw new RuntimeException('FakeContentClient was asked for more responses than it holds.');
        }

        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }
}
