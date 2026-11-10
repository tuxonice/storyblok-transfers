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
final readonly class StoryRepository
{
    /**
     * How many relations the CDA resolves in one request, and so how many
     * leftover uuids one follow-up asks for - well inside the stories
     * endpoint's per_page maximum of 100.
     */
    private const RELATIONS_PER_REQUEST = 50;

    public function __construct(
        private ContentClient $client,
        private StoryMapper $mapper,
        private RelationMapFactory $relationMapFactory,
        private ContentOptions $defaults = new ContentOptions(),
    ) {
    }

    /**
     * @template T of AbstractTransfer = AbstractTransfer
     *
     * @param string $slug A full slug, with or without surrounding slashes.
     * @param class-string<T>|null $expected Asserted against the component.
     *
     * @return StoryTransfer<T>|null Null when no story has that slug - which
     *         includes an empty one, such as a router's root path '/'.
     *
     * @throws StoryblokApiException
     * @throws UnresolvableComponentException
     * @throws UnexpectedComponentException
     */
    public function bySlug(string $slug, ?string $expected = null, ?ContentOptions $options = null): ?StoryTransfer
    {
        // No story has an empty slug, and asking anyway would hit the listing
        // endpoint: cdn/stories/ answers 200 with {stories: [...]}, which
        // would surface as a "no story key" fault instead of "not found".
        // Mapping '/' to a home story is the caller's routing policy.
        if (trim($slug, '/') === '') {
            return null;
        }

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
        // An empty uuid would also hit the listing endpoint, which rejects
        // find_by with a 422.
        if ($uuid === '') {
            return null;
        }

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
     * A story whose root component has no generated class does not fail the
     * listing; it is left out and reported by StoryList::getSkipped().
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
            $this->relationMap($response, $query->options ?? $this->defaults),
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
        $options ??= $this->defaults;
        $query = array_merge($options->toQuery(), $extraQuery);

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
        return $this->mapper->mapOne($story, $this->relationMap($response, $options), $expected);
    }

    /**
     * The resolved relations belong to the response, not to any one story in
     * it: resolve_relations leaves `content` untouched and returns what it
     * resolved in a `rels` array at the root. Building the map here once and
     * handing the same instance to every story is what makes a page's shared
     * map structurally true rather than something a merge step maintains.
     *
     * One request resolves at most 50 relations. Past that the CDA lists the
     * referenced uuids in `rel_uuids` and leaves fetching them to the caller -
     * ignoring it would make every relation on a large listing come back null,
     * indistinguishable from "not requested". Only the uuids `rels` did not
     * already carry are fetched, so the follow-ups cost nothing in the usual
     * case where `rel_uuids` is absent.
     *
     * @throws StoryblokApiException
     */
    private function relationMap(ContentResponse $response, ContentOptions $options): RelationMap
    {
        $rels = $response->body['rels'] ?? [];
        $rels = is_array($rels) ? array_values($rels) : [];

        foreach (array_chunk($this->unresolvedUuids($response, $rels), self::RELATIONS_PER_REQUEST) as $chunk) {
            $rels = array_merge($rels, $this->fetchRelations($chunk, $options));
        }

        return $this->relationMapFactory->fromRels($rels);
    }

    /**
     * @param list<mixed> $rels
     *
     * @return list<string>
     */
    private function unresolvedUuids(ContentResponse $response, array $rels): array
    {
        $referenced = $response->body['rel_uuids'] ?? null;

        if (!is_array($referenced) || $referenced === []) {
            return [];
        }

        $resolved = [];

        foreach ($rels as $related) {
            if (is_array($related) && is_string($related['uuid'] ?? null)) {
                $resolved[$related['uuid']] = true;
            }
        }

        $missing = [];

        foreach ($referenced as $uuid) {
            if (is_string($uuid) && $uuid !== '' && !isset($resolved[$uuid])) {
                $missing[$uuid] = true;
            }
        }

        return array_keys($missing);
    }

    /**
     * Same version, language and cv as the request that referenced them, so a
     * draft page gets draft relations. resolve_relations is dropped: these are
     * the related stories themselves, and resolving their own relations would
     * be a second level the original request never asked for.
     *
     * @param list<string> $uuids At most RELATIONS_PER_REQUEST of them.
     *
     * @return list<mixed>
     *
     * @throws StoryblokApiException
     */
    private function fetchRelations(array $uuids, ContentOptions $options): array
    {
        $query = $options->withResolveRelations([])->toQuery();
        $query['by_uuids'] = implode(',', $uuids);
        $query['per_page'] = (string) self::RELATIONS_PER_REQUEST;

        $response = $this->client->get('cdn/stories', $query);
        $stories = $response->body['stories'] ?? null;

        if (!is_array($stories)) {
            throw new StoryblokApiException(
                'No "stories" key in the Storyblok response for the cdn/stories relation lookup'
            );
        }

        return array_values($stories);
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
