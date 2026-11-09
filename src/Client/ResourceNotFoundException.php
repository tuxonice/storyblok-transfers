<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Client;

use RuntimeException;

/**
 * The CDA has no resource at that path - HTTP 404.
 *
 * Deliberately not a subclass of StoryblokApiException. That class is final,
 * and the distinction is wanted anyway: a missing story is an answer, not a
 * fault, and StoryRepository turns this into null rather than letting it out.
 */
final class ResourceNotFoundException extends RuntimeException
{
}
