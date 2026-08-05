<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Hydration;

use RuntimeException;

/**
 * Thrown for programming errors only - a target class that does not exist or is
 * not a transfer. Content drift never throws: an unresolvable blok degrades to
 * a raw array instead.
 */
final class HydrationException extends RuntimeException
{
}
