<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Content;

use RuntimeException;

/**
 * A story's root component has no generated transfer class.
 *
 * Deliberately asymmetric with the hydrator, which leaves an unknown nested
 * blok as a raw array so that an editor adding a component cannot break a page.
 * An unknown blok is one part of a page and degrading is right; the root content
 * is the object the caller asked for, and StoryTransfer::$content is not
 * nullable. Regenerate, or check the configured namespace.
 */
final class UnresolvableComponentException extends RuntimeException
{
}
