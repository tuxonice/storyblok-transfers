<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Content;

use Tlab\StoryblokTransfers\Client\ContentResponse;
use Tlab\StoryblokTransfers\Client\StoryblokApiException;

/**
 * The row handling every flat CDA collection shares: the key must be there,
 * and a row that is not usable is skipped rather than failing the whole read.
 *
 * Two steps rather than one, because a paginated caller needs the raw row
 * count - skipped rows included - to know whether another page follows.
 *
 * @internal
 */
final class ResponseRows
{
    /**
     * @return array<mixed> The rows under $key, unfiltered.
     *
     * @throws StoryblokApiException When $key is missing or not a collection.
     */
    public static function of(ContentResponse $response, string $key, string $path): array
    {
        $rows = $response->body[$key] ?? null;

        if (!is_array($rows)) {
            throw new StoryblokApiException(
                sprintf('No "%s" key in the Storyblok response for %s', $key, $path)
            );
        }

        return $rows;
    }

    /**
     * @template T of object
     *
     * @param array<mixed> $rows
     * @param callable(array<string, mixed>): (T|null) $fromPayload Returns null
     *        for a row that is not usable.
     *
     * @return list<T>
     */
    public static function map(array $rows, callable $fromPayload): array
    {
        $mapped = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            /** @var array<string, mixed> $row */
            $entry = $fromPayload($row);

            if ($entry !== null) {
                $mapped[] = $entry;
            }
        }

        return $mapped;
    }
}
