<?php

declare(strict_types=1);


/**
 * What printing a hydrated graph revealed about it.
 *
 * Deliberately shaped like the library's own GenerationResult: a run produces
 * counts, and the caller decides which of them matter.
 */
final class GraphSummary
{
    /**
     * @param int $transfers Transfer objects reached, the root included.
     * @param int $rawBloks Nested bloks left as raw arrays for want of a class.
     * @param int $uninitialized Typed properties with no value, which would
     *                           make toArray() throw.
     */
    public function __construct(
        public readonly int $transfers = 0,
        public readonly int $rawBloks = 0,
        public readonly int $uninitialized = 0,
    ) {
    }
}
