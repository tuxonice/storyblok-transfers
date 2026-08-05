<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Schema;

/**
 * Turns a Storyblok component name into the name of its transfer.
 *
 * Shared by the definition writer, the generator and the hydrator: all three
 * have to agree on how `product_core` becomes `ProductCore`, or the hydrator
 * looks up classes the generator never wrote.
 */
final class ComponentNameFormatter
{
    public function toTransferName(string $componentName): string
    {
        $spaced = str_replace(['_', '-', '.'], ' ', $componentName);

        return str_replace(' ', '', ucwords($spaced));
    }
}
