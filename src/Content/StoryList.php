<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Content;

use ArrayIterator;
use IteratorAggregate;
use Tlab\TransferObjects\AbstractTransfer;
use Traversable;

/**
 * One page of stories, plus the totals the CDA reports in its headers.
 *
 * Holds the RelationMap that every story on the page shares, so a relation
 * resolved once is not duplicated per story.
 *
 * @template T of AbstractTransfer
 * @implements IteratorAggregate<int, StoryTransfer<T>>
 */
final class StoryList implements IteratorAggregate
{
    /**
     * @param list<StoryTransfer<T>> $stories
     * @param list<array<string, mixed>> $skipped Raw stories whose root
     *        component has no generated class.
     */
    public function __construct(
        private readonly array $stories,
        private readonly int $total,
        private readonly int $page,
        private readonly int $perPage,
        private readonly RelationMap $relations,
        private readonly array $skipped = [],
    ) {
    }

    /**
     * @return list<StoryTransfer<T>>
     */
    public function getStories(): array
    {
        return $this->stories;
    }

    /**
     * Across every page, not just this one. As the CDA counts it, so skipped
     * stories are included.
     */
    public function getTotal(): int
    {
        return $this->total;
    }

    public function getPage(): int
    {
        return $this->page;
    }

    public function getPerPage(): int
    {
        return $this->perPage;
    }

    /**
     * The same instance every story on this page holds.
     */
    public function getRelations(): RelationMap
    {
        return $this->relations;
    }

    /**
     * Stories on this page that were left out because their root component
     * has no generated class, as the raw CDA story objects - so the caller can
     * log the full_slug and component, or render them some other way.
     * Usually a sign the transfers need regenerating.
     *
     * @return list<array<string, mixed>>
     */
    public function getSkipped(): array
    {
        return $this->skipped;
    }

    /**
     * @return Traversable<int, StoryTransfer<T>>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->stories);
    }
}
