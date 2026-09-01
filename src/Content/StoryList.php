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
     */
    public function __construct(
        private readonly array $stories,
        private readonly int $total,
        private readonly int $page,
        private readonly int $perPage,
        private readonly RelationMap $relations,
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
     * Across every page, not just this one.
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
     * @return Traversable<int, StoryTransfer<T>>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->stories);
    }
}
