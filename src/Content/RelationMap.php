<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Content;

use Closure;
use Tlab\TransferObjects\AbstractTransfer;

/**
 * Resolved relations, keyed by the uuid that stands in for them in the content.
 *
 * This is the whole point of the relation design: the generated property keeps
 * its ?string uuid and the resolved story sits beside the tree rather than
 * inside it - the same split RichtextTransfer makes for embedded bloks, and for
 * the same reason. A value the setter expects to be a scalar must stay one.
 *
 * One instance is shared by every story in a listing, so identity is meaningful
 * and assertable.
 */
final class RelationMap
{
    /**
     * Not readonly only so fromStories() can hand the map to the stories it is
     * still filling. Nothing writes it after construction.
     *
     * @var array<string, StoryTransfer<AbstractTransfer>|array<mixed>>
     */
    private array $relations;

    /**
     * @param array<string, StoryTransfer<AbstractTransfer>|array<mixed>> $relations
     *        A raw array is a related story that could not be mapped - its
     *        component has no generated class.
     */
    public function __construct(array $relations = [])
    {
        $this->relations = $relations;
    }

    /**
     * Builds a map whose entries hold the map itself: a related story is a
     * StoryTransfer, and a StoryTransfer carries the RelationMap of the
     * response it came from - which is this one. The map is complete before
     * anything outside can read it.
     *
     * @param array<string, array<string, mixed>> $stories Raw stories by uuid.
     * @param Closure(array<string, mixed>, RelationMap): (StoryTransfer<AbstractTransfer>|array<mixed>) $map
     */
    public static function fromStories(array $stories, Closure $map): self
    {
        $instance = new self();

        foreach ($stories as $uuid => $story) {
            $instance->relations[$uuid] = $map($story, $instance);
        }

        return $instance;
    }

    /**
     * @param string|null $uuid Nullable because the generated property it comes
     *                          from is nullable.
     *
     * @return StoryTransfer<AbstractTransfer>|array<mixed>|null The related
     *         story, envelope and all. A raw array when it could not be
     *         mapped. Null when the uuid was never resolved, which is the
     *         normal case for an unrequested field.
     */
    public function get(?string $uuid): StoryTransfer|array|null
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }

        return $this->relations[$uuid] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->relations === [];
    }
}
