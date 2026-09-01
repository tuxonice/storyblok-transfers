<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Content;

use Tlab\StoryblokTransfers\Client\ContentClient;
use Tlab\StoryblokTransfers\Client\StoryblokApiException;

/**
 * Reads the links tree: the structure of a space without its content.
 */
final class LinkRepository
{
    public function __construct(
        private readonly ContentClient $client,
        private readonly ContentOptions $defaults = new ContentOptions(),
    ) {
    }

    /**
     * The CDA returns `links` as an object keyed by uuid, not as a list. The
     * values come back in the order they arrived, and an entry that is not a
     * usable link is skipped rather than failing the whole tree.
     *
     * @param string|null $startsWith Restrict to a subtree, e.g. 'blog/'.
     *
     * @return list<LinkEntry>
     *
     * @throws StoryblokApiException
     */
    public function all(?string $startsWith = null, ?ContentOptions $options = null): array
    {
        $query = ($options ?? $this->defaults)->toQuery();

        if ($startsWith !== null) {
            $query['starts_with'] = $startsWith;
        }

        $response = $this->client->get('cdn/links', $query);
        $links = $response->body['links'] ?? null;

        if (!is_array($links)) {
            throw new StoryblokApiException('No "links" key in the Storyblok response for cdn/links');
        }

        $entries = [];

        foreach ($links as $link) {
            if (!is_array($link)) {
                continue;
            }

            /** @var array<string, mixed> $link */
            $entry = LinkEntry::fromPayload($link);

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }
}
