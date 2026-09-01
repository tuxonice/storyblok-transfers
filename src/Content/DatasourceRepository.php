<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Content;

use Tlab\StoryblokTransfers\Client\ContentClient;
use Tlab\StoryblokTransfers\Client\StoryblokApiException;

/**
 * Reads datasource entries.
 *
 * Read only. Turning them into generated PHP enums would change the generator
 * and is a separate piece of work.
 *
 * The shared ContentOptions are passed through, though this endpoint has no use
 * for version or language - it uses dimensions for translation - so in practice
 * only cv matters here.
 */
final class DatasourceRepository
{
    public function __construct(
        private readonly ContentClient $client,
        private readonly ContentOptions $defaults = new ContentOptions(),
    ) {
    }

    /**
     * @param string $datasource The datasource slug.
     * @param string|null $dimension A dimension name, for translated values.
     *
     * @return list<DatasourceEntry>
     *
     * @throws StoryblokApiException
     */
    public function entries(string $datasource, ?string $dimension = null, ?ContentOptions $options = null): array
    {
        $query = ($options ?? $this->defaults)->toQuery();
        $query['datasource'] = $datasource;

        if ($dimension !== null) {
            $query['dimension'] = $dimension;
        }

        $response = $this->client->get('cdn/datasource_entries', $query);
        $rows = $response->body['datasource_entries'] ?? null;

        if (!is_array($rows)) {
            throw new StoryblokApiException(
                'No "datasource_entries" key in the Storyblok response for cdn/datasource_entries'
            );
        }

        $entries = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            /** @var array<string, mixed> $row */
            $entry = DatasourceEntry::fromPayload($row);

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }
}
