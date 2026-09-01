<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Content;

use Tlab\StoryblokTransfers\Hydration\ComponentClassResolver;
use Tlab\StoryblokTransfers\Hydration\StoryblokHydrator;
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
 */
final class RelationMapFactory
{
    public function __construct(
        private readonly ComponentClassResolver $resolver,
        private readonly StoryblokHydrator $hydrator,
    ) {
    }

    /**
     * @param array<mixed> $rels The `rels` array from a response root. Always
     *                           present, empty when nothing was resolved.
     */
    public function fromRels(array $rels): RelationMap
    {
        $relations = [];

        foreach ($rels as $related) {
            if (!is_array($related)) {
                continue;
            }

            $uuid = $related['uuid'] ?? null;

            if (!is_string($uuid) || $uuid === '') {
                continue;
            }

            /** @var array<string, mixed> $related */
            $relations[$uuid] = $this->hydrate($related);
        }

        return new RelationMap($relations);
    }

    /**
     * A relation whose component has no generated class - or which carries no
     * content at all, as a folder does - keeps its whole raw story array. That
     * is the same degradation the hydrator applies to an unknown nested blok:
     * content drift must not break the page.
     *
     * @param array<string, mixed> $related
     *
     * @return AbstractTransfer|array<mixed>
     */
    private function hydrate(array $related): AbstractTransfer|array
    {
        $content = $related['content'] ?? null;

        if (!is_array($content)) {
            return $related;
        }

        /** @var array<string, mixed> $content */
        $class = $this->resolver->resolveFromContent($content);

        return $class === null
            ? $related
            : $this->hydrator->hydrate($class, $content);
    }
}
