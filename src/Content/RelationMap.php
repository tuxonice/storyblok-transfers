<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Content;

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
     * @param array<string, AbstractTransfer|array<mixed>> $relations A raw array
     *        is a related story whose component has no generated class.
     */
    public function __construct(
        private readonly array $relations = [],
    ) {
    }

    /**
     * @param string|null $uuid Nullable because the generated property it comes
     *                          from is nullable.
     *
     * @return AbstractTransfer|array<mixed>|null Null when the uuid was never
     *                                            resolved, which is the normal
     *                                            case for an unrequested field.
     */
    public function get(?string $uuid): AbstractTransfer|array|null
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
