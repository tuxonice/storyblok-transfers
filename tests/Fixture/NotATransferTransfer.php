<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Fixture;

/**
 * Exists only so ComponentClassResolverTest has a class the naming convention
 * finds (class_exists passes) that is not a transfer (is_subclass_of rejects
 * it) - the fixture that exercises the is_subclass_of guard specifically.
 */
class NotATransferTransfer
{
}
