<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tlab\StoryblokTransfers\Transfers\AssetTransfer;
use Tlab\StoryblokTransfers\Transfers\BlokTransfer;
use Tlab\StoryblokTransfers\Transfers\LinkTransfer;
use Tlab\StoryblokTransfers\Transfers\RichtextTransfer;

/**
 * Rules that hold for every transfer this package ships, as opposed to the
 * per-class behaviour covered alongside each one.
 */
final class BundledTransfersTest extends TestCase
{
    /**
     * AbstractTransfer::toArray() reflects only the IS_PRIVATE properties of the
     * concrete class, and a subclass cannot see its parent's private ones - so a
     * subclass serialises to [] with no error at all, while toDocument() and the
     * accessors keep working. Nothing here is released, so final closes the trap
     * before anyone can fall into it.
     */
    public function testEveryBundledTransferIsFinal(): void
    {
        $transfers = [
            AssetTransfer::class,
            BlokTransfer::class,
            LinkTransfer::class,
            RichtextTransfer::class,
        ];

        foreach ($transfers as $transferClass) {
            self::assertTrue(
                (new ReflectionClass($transferClass))->isFinal(),
                $transferClass . ' must be final: a subclass would silently serialise to [].'
            );
        }
    }
}
