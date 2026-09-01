<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Client;

/**
 * The seam between the repositories and the network.
 *
 * One method, because a path plus a query map already describes every CDA
 * resource this library reads - and one method is the smallest thing an
 * application has to wrap to add caching. This library ships no cache: the
 * interface *is* the caching provision.
 */
interface ContentClient
{
    /**
     * @param string $path Path below the base URI, e.g. 'cdn/stories/home'.
     * @param array<string, string> $query Query parameters without the token.
     *
     * @throws ResourceNotFoundException When the resource does not exist.
     * @throws StoryblokApiException For every other failure.
     */
    public function get(string $path, array $query): ContentResponse;
}
