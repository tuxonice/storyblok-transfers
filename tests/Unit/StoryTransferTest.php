<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tlab\StoryblokTransfers\Content\RelationMap;
use Tlab\StoryblokTransfers\Content\StoryTransfer;
use Tlab\StoryblokTransfers\Tests\Fixture\ScalarFixtureTransfer;

final class StoryTransferTest extends TestCase
{
    public function testExposesTheEnvelopeAndTheContent(): void
    {
        $content = new ScalarFixtureTransfer();
        $story = new StoryTransfer(
            'f1e2d3c4',
            'home',
            'en/home',
            $content,
            new RelationMap(),
            'Home',
            'en',
            '2026-08-01 10:00',
            '2026-07-01 09:00',
            '2026-06-01 08:00',
            42,
            ['featured'],
            ['de' => ['path' => 'de/start']],
        );

        self::assertSame('f1e2d3c4', $story->getUuid());
        self::assertSame('home', $story->getSlug());
        self::assertSame('en/home', $story->getFullSlug());
        self::assertSame($content, $story->getContent());
        self::assertSame('Home', $story->getName());
        self::assertSame('en', $story->getLang());
        self::assertSame('2026-08-01 10:00', $story->getPublishedAt());
        self::assertSame('2026-07-01 09:00', $story->getFirstPublishedAt());
        self::assertSame('2026-06-01 08:00', $story->getCreatedAt());
        self::assertSame(42, $story->getParentId());
        self::assertSame(['featured'], $story->getTagList());
        self::assertSame(['de' => ['path' => 'de/start']], $story->getTranslatedSlugs());
    }

    public function testDefaultsTheOptionalEnvelopeFields(): void
    {
        $story = new StoryTransfer('u', 's', 'f', new ScalarFixtureTransfer(), new RelationMap());

        self::assertNull($story->getName());
        self::assertNull($story->getLang());
        self::assertNull($story->getPublishedAt());
        self::assertNull($story->getParentId());
        self::assertSame([], $story->getTagList());
        self::assertSame([], $story->getTranslatedSlugs());
    }

    public function testReachesARelationThroughTheMap(): void
    {
        $author = new ScalarFixtureTransfer();
        $story = new StoryTransfer(
            'u',
            's',
            'f',
            new ScalarFixtureTransfer(),
            new RelationMap(['author-uuid' => $author]),
        );

        self::assertSame($author, $story->getRelation('author-uuid'));
    }

    public function testReturnsNullForAUuidThatWasNeverResolved(): void
    {
        $story = new StoryTransfer('u', 's', 'f', new ScalarFixtureTransfer(), new RelationMap());

        self::assertNull($story->getRelation('nothing-here'));
    }

    public function testAcceptsANullUuidBecauseTheGeneratedPropertyIsNullable(): void
    {
        // $page->getAuthor() is ?string, so this is the ordinary call shape when
        // the editor left the relation empty.
        $story = new StoryTransfer('u', 's', 'f', new ScalarFixtureTransfer(), new RelationMap());

        self::assertNull($story->getRelation(null));
    }

    public function testKeepsARelationWithNoGeneratedClassAsARawArray(): void
    {
        $raw = ['uuid' => 'x', 'content' => ['component' => 'unknown_thing']];
        $story = new StoryTransfer(
            'u',
            's',
            'f',
            new ScalarFixtureTransfer(),
            new RelationMap(['x' => $raw]),
        );

        self::assertSame($raw, $story->getRelation('x'));
    }

    public function testExposesTheMapItselfSoItCanBeSharedAndCompared(): void
    {
        $map = new RelationMap(['a' => new ScalarFixtureTransfer()]);
        $story = new StoryTransfer('u', 's', 'f', new ScalarFixtureTransfer(), $map);

        self::assertSame($map, $story->getRelations());
        self::assertFalse($map->isEmpty());
        self::assertTrue((new RelationMap())->isEmpty());
    }
}
