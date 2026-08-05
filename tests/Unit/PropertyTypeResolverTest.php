<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tlab\StoryblokTransfers\Hydration\PropertyTypeResolver;
use Tlab\StoryblokTransfers\Tests\Fixture\NestedFixtureTransfer;
use Tlab\StoryblokTransfers\Tests\Fixture\ScalarFixtureTransfer;
use Tlab\StoryblokTransfers\Transfers\AssetTransfer;
use Tlab\StoryblokTransfers\Transfers\BlokTransfer;

final class PropertyTypeResolverTest extends TestCase
{
    private PropertyTypeResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new PropertyTypeResolver();
    }

    public function testFindsANestedTransferProperty(): void
    {
        $map = $this->resolver->resolve(NestedFixtureTransfer::class);

        self::assertArrayHasKey('image', $map);
        self::assertSame(AssetTransfer::class, $map['image']->transferClass);
        self::assertNull($map['image']->elementTransferClass);
    }

    public function testResolvesArrayElementTypeThroughTheAddMethod(): void
    {
        $map = $this->resolver->resolve(NestedFixtureTransfer::class);

        self::assertArrayHasKey('body', $map);
        self::assertSame(BlokTransfer::class, $map['body']->elementTransferClass);
        self::assertNull($map['body']->transferClass);
    }

    public function testOmitsScalarArrayProperties(): void
    {
        self::assertArrayNotHasKey('tags', $this->resolver->resolve(NestedFixtureTransfer::class));
    }

    public function testOmitsPlainScalarProperties(): void
    {
        self::assertArrayNotHasKey('headline', $this->resolver->resolve(NestedFixtureTransfer::class));
    }

    public function testOmitsMixedArrayProperties(): void
    {
        self::assertSame([], $this->resolver->resolve(ScalarFixtureTransfer::class));
    }

    public function testReturnsTheSameMapOnRepeatedCalls(): void
    {
        self::assertEquals(
            $this->resolver->resolve(NestedFixtureTransfer::class),
            $this->resolver->resolve(NestedFixtureTransfer::class)
        );
    }
}
