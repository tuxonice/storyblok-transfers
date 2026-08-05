<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tlab\StoryblokTransfers\Hydration\HydrationException;
use Tlab\StoryblokTransfers\Hydration\StoryblokHydrator;
use Tlab\StoryblokTransfers\Tests\Fixture\NestedFixtureTransfer;
use Tlab\StoryblokTransfers\Tests\Fixture\ScalarFixtureTransfer;
use Tlab\StoryblokTransfers\Transfers\AssetTransfer;

final class StoryblokHydratorTest extends TestCase
{
    private StoryblokHydrator $hydrator;

    protected function setUp(): void
    {
        // Fixture transfers double as the "generated" namespace here.
        $this->hydrator = new StoryblokHydrator('Tlab\\StoryblokTransfers\\Tests\\Fixture');
    }

    public function testHydratesANestedAssetIntoATransfer(): void
    {
        $transfer = $this->hydrator->hydrate(NestedFixtureTransfer::class, [
            'image' => ['id' => 42, 'filename' => 'hero.jpg', 'alt' => 'Hero'],
        ]);

        self::assertInstanceOf(NestedFixtureTransfer::class, $transfer);
        $image = $transfer->getImage();
        self::assertInstanceOf(AssetTransfer::class, $image);
        self::assertSame(42, $image->getId());
        self::assertSame('hero.jpg', $image->getFilename());
        self::assertSame('Hero', $image->getAlt());
    }

    public function testStillAssignsPlainScalars(): void
    {
        $transfer = $this->hydrator->hydrate(NestedFixtureTransfer::class, ['headline' => 'Hello']);

        self::assertInstanceOf(NestedFixtureTransfer::class, $transfer);
        self::assertSame('Hello', $transfer->getHeadline());
    }

    public function testPassesScalarArraysThroughUntouched(): void
    {
        $transfer = $this->hydrator->hydrate(NestedFixtureTransfer::class, ['tags' => ['a', 'b']]);

        self::assertInstanceOf(NestedFixtureTransfer::class, $transfer);
        self::assertSame(['a', 'b'], $transfer->getTags());
    }

    public function testPassesRichtextStructuresThroughUntouched(): void
    {
        $nodes = ['type' => 'doc', 'content' => [['type' => 'paragraph']]];

        $transfer = $this->hydrator->hydrate(ScalarFixtureTransfer::class, ['content' => $nodes]);

        self::assertInstanceOf(ScalarFixtureTransfer::class, $transfer);
        self::assertSame($nodes, $transfer->getContent());
    }

    public function testTurnsAnEmptyStringAssetIntoNullRatherThanThrowing(): void
    {
        $transfer = $this->hydrator->hydrate(NestedFixtureTransfer::class, ['image' => '']);

        self::assertInstanceOf(NestedFixtureTransfer::class, $transfer);
        self::assertNull($transfer->getImage());
    }

    public function testTurnsANullAssetIntoNull(): void
    {
        $transfer = $this->hydrator->hydrate(NestedFixtureTransfer::class, ['image' => null]);

        self::assertInstanceOf(NestedFixtureTransfer::class, $transfer);
        self::assertNull($transfer->getImage());
    }

    public function testIgnoresKeysThatMatchNoProperty(): void
    {
        $transfer = $this->hydrator->hydrate(NestedFixtureTransfer::class, [
            'headline' => 'Hello',
            'headline_2' => 'ignored',
            '_uid' => 'abc',
        ]);

        self::assertInstanceOf(NestedFixtureTransfer::class, $transfer);
        self::assertSame('Hello', $transfer->getHeadline());
    }

    public function testRejectsAClassThatDoesNotExist(): void
    {
        $this->expectException(HydrationException::class);
        $this->expectExceptionMessageMatches('/does not exist/');

        // Deliberate misuse - this is precisely the call the guard defends
        // against, so the class-string violation is the point of the test.
        /** @phpstan-ignore argument.type */
        $this->hydrator->hydrate('App\\Nope\\MissingTransfer', []);
    }

    public function testRejectsAClassThatIsNotATransfer(): void
    {
        $this->expectException(HydrationException::class);
        $this->expectExceptionMessageMatches('/not a transfer/');

        $this->hydrator->hydrate(self::class, []);
    }
}
