<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers;

/**
 * What a generation run produced.
 */
final class GenerationResult
{
    /**
     * @param list<string> $componentNames PascalCased names of generated transfers.
     * @param list<string> $warnings Fields that had to be left out, and why.
     * @param list<string> $repairedFiles Generated files the fixer had to patch.
     */
    public function __construct(
        public readonly array $componentNames = [],
        public readonly array $warnings = [],
        public readonly array $repairedFiles = [],
    ) {
    }
}
