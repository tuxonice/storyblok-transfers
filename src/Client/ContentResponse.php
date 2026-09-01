<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Client;

/**
 * A CDA response, reduced to what the repositories need.
 *
 * The listing endpoint reports its total and page size in the Total and
 * Per-Page HTTP headers rather than in the body, so returning only the decoded
 * body would push header handling out past the transport boundary. Both are
 * null for the endpoints that do not paginate.
 */
final class ContentResponse
{
    /**
     * @param array<string, mixed> $body The decoded JSON body.
     */
    public function __construct(
        public readonly array $body,
        public readonly ?int $total = null,
        public readonly ?int $perPage = null,
    ) {
    }
}
