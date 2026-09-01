<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tlab\StoryblokTransfers\Content\RelationMapFactory;
use Tlab\StoryblokTransfers\Hydration\ComponentClassResolver;
use Tlab\StoryblokTransfers\Hydration\StoryblokHydrator;
use Tlab\StoryblokTransfers\Tests\Fixture\NestedFixtureTransfer;

final class RelationMapFactoryTest extends TestCase
{
    private const FIXTURE_NAMESPACE = 'Tlab\\StoryblokTransfers\\Tests\\Fixture';

    private RelationMapFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new RelationMapFactory(
            new ComponentClassResolver(self::FIXTURE_NAMESPACE),
            new StoryblokHydrator(self::FIXTURE_NAMESPACE),
        );
    }

    public function testBuildsAnEmptyMapFromNoRelations(): void
    {
        // The CDA always sends `rels`, empty when nothing was resolved.
        self::assertTrue($this->factory->fromRels([])->isEmpty());
    }

    public function testHydratesARelatedStoryAndKeysItByUuid(): void
    {
        $map = $this->factory->fromRels([
            [
                'uuid' => 'author-1',
                'full_slug' => 'authors/jane',
                'content' => ['component' => 'nested_fixture', 'headline' => 'Jane'],
            ],
        ]);

        $author = $map->get('author-1');
        self::assertInstanceOf(NestedFixtureTransfer::class, $author);
        self::assertSame('Jane', $author->getHeadline());
    }

    public function testKeepsEveryRelationInTheArray(): void
    {
        $map = $this->factory->fromRels([
            ['uuid' => 'r1', 'content' => ['component' => 'nested_fixture', 'headline' => 'One']],
            ['uuid' => 'r2', 'content' => ['component' => 'nested_fixture', 'headline' => 'Two']],
        ]);

        $first = $map->get('r1');
        $second = $map->get('r2');
        self::assertInstanceOf(NestedFixtureTransfer::class, $first);
        self::assertInstanceOf(NestedFixtureTransfer::class, $second);
        self::assertSame('One', $first->getHeadline());
        self::assertSame('Two', $second->getHeadline());
    }

    public function testKeepsARelationWhoseComponentHasNoGeneratedClassAsARawArray(): void
    {
        // Content drift must not break the page: the same degradation the
        // hydrator applies to an unknown nested blok.
        $related = [
            'uuid' => 'thing-1',
            'content' => ['component' => 'not_generated', 'whatever' => 1],
        ];

        self::assertSame($related, $this->factory->fromRels([$related])->get('thing-1'));
    }

    public function testKeepsARelationWithNoContentObjectAsARawArray(): void
    {
        $related = ['uuid' => 'folder-1', 'is_folder' => true];

        self::assertSame($related, $this->factory->fromRels([$related])->get('folder-1'));
    }

    public function testSkipsEntriesThatAreNotUsableRelations(): void
    {
        $map = $this->factory->fromRels([
            ['uuid' => 'keep', 'content' => ['component' => 'nested_fixture', 'headline' => 'Keep']],
            'not an array',
            ['content' => ['component' => 'nested_fixture']],
            ['uuid' => '', 'content' => ['component' => 'nested_fixture']],
        ]);

        self::assertInstanceOf(NestedFixtureTransfer::class, $map->get('keep'));
        self::assertNull($map->get(''));
    }

    public function testLeavesTheContentOfTheRelatedStoryHydratedNotRaw(): void
    {
        // Pins the distinction that matters: the relation's own content becomes
        // a transfer, while the story that pointed at it keeps a plain uuid in
        // its ?string property - which is why nothing has to touch `content`.
        $map = $this->factory->fromRels([
            [
                'uuid' => 'author-1',
                'content' => [
                    'component' => 'nested_fixture',
                    'headline' => 'Jane',
                    'image' => ['id' => 3, 'filename' => 'jane.jpg'],
                ],
            ],
        ]);

        $author = $map->get('author-1');
        self::assertInstanceOf(NestedFixtureTransfer::class, $author);
        self::assertNotNull($author->getImage());
        self::assertSame('jane.jpg', $author->getImage()->getFilename());
    }
}
