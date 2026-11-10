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
final readonly class DatasourceRepository
{
    /**
     * The endpoint's maximum page size, so a typical datasource is one request.
     */
    private const PER_PAGE = 1000;

    public function __construct(
        private ContentClient $client,
        private ContentOptions $defaults = new ContentOptions(),
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

        $entries = [];
        $page = 1;

        // The endpoint is paginated (25 per page unless asked otherwise), so a
        // single request silently drops everything past the first page. Walk
        // the pages at the endpoint's maximum size until a short one comes
        // back - or until the Total header says everything is in, which saves
        // the trailing request when the count is an exact multiple.
        do {
            $query['page'] = (string) $page;
            $query['per_page'] = (string) self::PER_PAGE;

            $response = $this->client->get('cdn/datasource_entries', $query);
            $rows = ResponseRows::of($response, 'datasource_entries', 'cdn/datasource_entries');
            $entries = array_merge($entries, ResponseRows::map($rows, DatasourceEntry::fromPayload(...)));

            $fetched = ($page - 1) * self::PER_PAGE + count($rows);
            $page++;
        } while (
            count($rows) >= self::PER_PAGE
            && ($response->total === null || $fetched < $response->total)
        );

        return $entries;
    }
}
