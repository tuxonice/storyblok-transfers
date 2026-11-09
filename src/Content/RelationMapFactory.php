<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Content;

use Tlab\StoryblokTransfers\Client\StoryblokApiException;
use Tlab\TransferObjects\AbstractTransfer;

/**
 * Turns a CDA response's `rels` array into a uuid-keyed RelationMap.
 *
 * With resolve_relations, Storyblok leaves `content` exactly as it was - the
 * relation field keeps the plain uuid string it always held - and puts the
 * resolved stories in a `rels` array at the response root. So resolving a
 * relation is a lookup, not a tree walk, and the generated `?string` property
 * needs no repair: it was never rewritten.
 *
 * Built once per response, which is what makes one RelationMap shared by every
 * story on a page structurally true rather than something a merge step has to
 * maintain.
 *
 * Each relation is mapped to a whole StoryTransfer, envelope included, by the
 * same StoryMapper the top-level stories go through. Hydrating the content
 * alone would drop full_slug, and a related story is almost always rendered as
 * a link to it.
 */
final class RelationMapFactory
{
    public function __construct(
        private readonly StoryMapper $mapper,
    ) {
    }

    /**
     * @param array<mixed> $rels The `rels` array from a response root. Always
     *                           present, empty when nothing was resolved.
     */
    public function fromRels(array $rels): RelationMap
    {
        $stories = [];

        foreach ($rels as $related) {
            if (!is_array($related)) {
                continue;
            }

            $uuid = $related['uuid'] ?? null;

            if (!is_string($uuid) || $uuid === '') {
                continue;
            }

            /** @var array<string, mixed> $related */
            $stories[$uuid] = $related;
        }

        return RelationMap::fromStories(
            $stories,
            fn (array $story, RelationMap $map): StoryTransfer|array => $this->map($story, $map)
        );
    }

    /**
     * A relation whose component has no generated class - or which is not a
     * whole story, as a folder with no content is not - keeps its raw story
     * array. That is the same degradation the hydrator applies to an unknown
     * nested blok: content drift must not break the page. The raw array still
     * carries full_slug, so a link can be built either way.
     *
     * Each related story gets the shared map as its own, so its relations
     * resolve too wherever the response carried them.
     *
     * @param array<string, mixed> $related
     *
     * @return StoryTransfer<AbstractTransfer>|array<mixed>
     */
    private function map(array $related, RelationMap $map): StoryTransfer|array
    {
        try {
            return $this->mapper->mapOne($related, $map);
        } catch (UnresolvableComponentException | StoryblokApiException) {
            return $related;
        }
    }
}
