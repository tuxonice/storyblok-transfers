<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Content;

use Tlab\StoryblokTransfers\Client\ContentClient;
use Tlab\StoryblokTransfers\Client\ContentResponse;
use Tlab\StoryblokTransfers\Client\ResourceNotFoundException;
use Tlab\StoryblokTransfers\Client\StoryblokApiException;
use Tlab\TransferObjects\AbstractTransfer;

/**
 * Reads stories from the Content Delivery API and returns them hydrated.
 *
 * The target transfer class is inferred from the story's own component, so a
 * router that holds only a slug can still get a typed graph; a caller that
 * knows the type passes it and gets it asserted and echoed back through the
 * generic.
 */
final class StoryRepository
{
    public function __construct(
        private readonly ContentClient $client,
        private readonly StoryMapper $mapper,
        private readonly RelationMapFactory $relationMapFactory,
        private readonly ContentOptions $defaults = new ContentOptions(),
    ) {
    }

    /**
     * @template T of AbstractTransfer = AbstractTransfer
     *
     * @param string $slug A full slug, with or without surrounding slashes.
     * @param class-string<T>|null $expected Asserted against the component.
     *
     * @return StoryTransfer<T>|null Null when no story has that slug.
     *
     * @throws StoryblokApiException
     * @throws UnresolvableComponentException
     * @throws UnexpectedComponentException
     */
    public function bySlug(string $slug, ?string $expected = null, ?ContentOptions $options = null): ?StoryTransfer
    {
        /** @var StoryTransfer<T>|null $story */
        $story = $this->fetch('cdn/stories/' . $this->encodeSlug($slug), [], $expected, $options);

        return $story;
    }

    /**
     * @template T of AbstractTransfer = AbstractTransfer
     *
     * @param class-string<T>|null $expected
     *
     * @return StoryTransfer<T>|null
     *
     * @throws StoryblokApiException
     * @throws UnresolvableComponentException
     * @throws UnexpectedComponentException
     */
    public function byUuid(string $uuid, ?string $expected = null, ?ContentOptions $options = null): ?StoryTransfer
    {
        /** @var StoryTransfer<T>|null $story */
        $story = $this->fetch(
            'cdn/stories/' . rawurlencode($uuid),
            ['find_by' => 'uuid'],
            $expected,
            $options
        );

        return $story;
    }

    /**
     * A query that matches nothing returns an empty list with a total of zero.
     * Only a single-story lookup has a "does not exist" case.
     *
     * The template carries a default for the same reason bySlug() does: without
     * one, PHPStan cannot bind T at the call sites that omit $expected - which
     * is the default usage.
     *
     * @template T of AbstractTransfer = AbstractTransfer
     *
     * @param class-string<T>|null $expected
     *
     * @return StoryList<T>
     *
     * @throws StoryblokApiException
     * @throws UnresolvableComponentException
     * @throws UnexpectedComponentException
     */
    public function findBy(StoryQuery $query, ?string $expected = null): StoryList
    {
        $response = $this->client->get('cdn/stories', $query->toQuery($this->defaults));
        $stories = $response->body['stories'] ?? null;

        if (!is_array($stories)) {
            throw new StoryblokApiException('No "stories" key in the Storyblok response for cdn/stories');
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = array_values(array_filter($stories, 'is_array'));

        /** @var StoryList<T> $list */
        $list = $this->mapper->mapList(
            $rows,
            $this->relationMap($response),
            $response->total ?? count($rows),
            $query->page,
            $response->perPage ?? $query->perPage,
            $expected
        );

        return $list;
    }

    /**
     * @param array<string, string> $extraQuery
     * @param class-string<AbstractTransfer>|null $expected
     *
     * @return StoryTransfer<AbstractTransfer>|null
     *
     * @throws StoryblokApiException
     */
    private function fetch(
        string $path,
        array $extraQuery,
        ?string $expected,
        ?ContentOptions $options
    ): ?StoryTransfer {
        // array_merge, not `+`: the endpoint parameters win a collision, the
        // same way LinkRepository's starts_with and DatasourceRepository's
        // datasource do. They are what makes the call mean what its method name
        // says - `find_by=uuid` turned off would silently make byUuid() look up
        // a slug - so no cross-cutting option may override them. Nothing in
        // ContentOptions collides today; this fixes which way it would go.
        $query = array_merge(($options ?? $this->defaults)->toQuery(), $extraQuery);

        try {
            $response = $this->client->get($path, $query);
        } catch (ResourceNotFoundException) {
            // "No such story" is an answer the caller can act on.
            return null;
        }

        $story = $response->body['story'] ?? null;

        if (!is_array($story)) {
            throw new StoryblokApiException('No "story" key in the Storyblok response for ' . $path);
        }

        /** @var array<string, mixed> $story */
        return $this->mapper->mapOne($story, $this->relationMap($response), $expected);
    }

    /**
     * The resolved relations belong to the response, not to any one story in
     * it: resolve_relations leaves `content` untouched and returns what it
     * resolved in a `rels` array at the root. Building the map here once and
     * handing the same instance to every story is what makes a page's shared
     * map structurally true rather than something a merge step maintains.
     */
    private function relationMap(ContentResponse $response): RelationMap
    {
        $rels = $response->body['rels'] ?? [];

        /** @var array<mixed> $rels */
        return $this->relationMapFactory->fromRels(is_array($rels) ? $rels : []);
    }

    /**
     * Each segment is encoded, the separators are not: a full slug is a path,
     * so its slashes have to survive.
     */
    private function encodeSlug(string $slug): string
    {
        $segments = explode('/', trim($slug, '/'));

        return implode('/', array_map('rawurlencode', $segments));
    }
}
