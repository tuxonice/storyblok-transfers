<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Content;

use RuntimeException;

/**
 * The caller declared a transfer class that the story's component does not
 * resolve to.
 *
 * Exists so a mismatch reports the component that actually arrived, instead of
 * surfacing as a TypeError from somewhere inside the hydrator.
 */
final class UnexpectedComponentException extends RuntimeException
{
}
