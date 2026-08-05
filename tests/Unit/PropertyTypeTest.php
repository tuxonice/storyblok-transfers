<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tlab\StoryblokTransfers\Hydration\PropertyType;
use Tlab\StoryblokTransfers\Transfers\AssetTransfer;

final class PropertyTypeTest extends TestCase
{
    public function testAPlainPropertyNeedsNoConversion(): void
    {
        self::assertFalse((new PropertyType())->needsConversion());
    }

    public function testANestedTransferNeedsConversion(): void
    {
        $type = new PropertyType(transferClass: AssetTransfer::class);

        self::assertTrue($type->needsConversion());
        self::assertSame(AssetTransfer::class, $type->transferClass);
        self::assertNull($type->elementTransferClass);
    }

    public function testATransferArrayNeedsConversion(): void
    {
        $type = new PropertyType(elementTransferClass: AssetTransfer::class);

        self::assertTrue($type->needsConversion());
        self::assertSame(AssetTransfer::class, $type->elementTransferClass);
        self::assertNull($type->transferClass);
    }
}
