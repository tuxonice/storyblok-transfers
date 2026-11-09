<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tlab\StoryblokTransfers\Content\RelationMapFactory;
use Tlab\StoryblokTransfers\Content\StoryMapper;
use Tlab\StoryblokTransfers\Content\StoryTransfer;
use Tlab\StoryblokTransfers\Hydration\ComponentClassResolver;
use Tlab\StoryblokTransfers\Hydration\StoryblokHydrator;
use Tlab\StoryblokTransfers\Tests\Fixture\NestedFixtureTransfer;

final class RelationMapFactoryTest extends TestCase
{
    private const FIXTURE_NAMESPACE = 'Tlab\\StoryblokTransfers\\Tests\\Fixture';

    private RelationMapFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new RelationMapFactory(new StoryMapper(
            new ComponentClassResolver(self::FIXTURE_NAMESPACE),
            new StoryblokHydrator(self::FIXTURE_NAMESPACE),
        ));
    }

    public function testBuildsAnEmptyMapFromNoRelations(): void
    {
        // The CDA always sends `rels`, empty when nothing was resolved.
        self::assertTrue($this->factory->fromRels([])->isEmpty());
    }

    public function testMapsARelatedStoryWithItsEnvelopeAndKeysItByUuid(): void
    {
        $map = $this->factory->fromRels([
            [
                'uuid' => 'author-1',
                'slug' => 'jane',
                'full_slug' => 'authors/jane',
                'name' => 'Jane Doe',
                'content' => ['component' => 'nested_fixture', 'headline' => 'Jane'],
            ],
        ]);

        $author = $map->get('author-1');
        self::assertInstanceOf(StoryTransfer::class, $author);
        self::assertInstanceOf(NestedFixtureTransfer::class, $author->getContent());
        self::assertSame('Jane', $author->getContent()->getHeadline());

        // The envelope is what a link to the related story needs.
        self::assertSame('author-1', $author->getUuid());
        self::assertSame('jane', $author->getSlug());
        self::assertSame('authors/jane', $author->getFullSlug());
        self::assertSame('Jane Doe', $author->getName());
    }

    public function testGivesEachRelatedStoryTheSharedMapAsItsOwn(): void
    {
        // So a related story's own relations resolve wherever the response
        // carried them - and identity holds across the whole response.
        $map = $this->factory->fromRels([$this->related('r1', 'One'), $this->related('r2', 'Two')]);

        $first = $map->get('r1');
        self::assertInstanceOf(StoryTransfer::class, $first);
        self::assertSame($map, $first->getRelations());
        self::assertInstanceOf(StoryTransfer::class, $first->getRelation('r2'));
    }

    public function testKeepsEveryRelationInTheArray(): void
    {
        $map = $this->factory->fromRels([$this->related('r1', 'One'), $this->related('r2', 'Two')]);

        $first = $map->get('r1');
        $second = $map->get('r2');
        self::assertInstanceOf(StoryTransfer::class, $first);
        self::assertInstanceOf(StoryTransfer::class, $second);
        self::assertInstanceOf(NestedFixtureTransfer::class, $first->getContent());
        self::assertInstanceOf(NestedFixtureTransfer::class, $second->getContent());
        self::assertSame('One', $first->getContent()->getHeadline());
        self::assertSame('Two', $second->getContent()->getHeadline());
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
            $this->related('keep', 'Keep'),
            'not an array',
            ['content' => ['component' => 'nested_fixture']],
            ['uuid' => '', 'content' => ['component' => 'nested_fixture']],
        ]);

        self::assertInstanceOf(StoryTransfer::class, $map->get('keep'));
        self::assertNull($map->get(''));
    }

    public function testKeepsARelationMissingItsEnvelopeAsARawArray(): void
    {
        // Not a whole story, so not a StoryTransfer - but not a reason to
        // break the page either.
        $related = ['uuid' => 'odd-1', 'content' => ['component' => 'nested_fixture']];

        self::assertSame($related, $this->factory->fromRels([$related])->get('odd-1'));
    }

    public function testLeavesTheContentOfTheRelatedStoryHydratedNotRaw(): void
    {
        // Pins the distinction that matters: the relation's own content becomes
        // a transfer, while the story that pointed at it keeps a plain uuid in
        // its ?string property - which is why nothing has to touch `content`.
        $related = $this->related('author-1', 'Jane');
        $related['content']['image'] = ['id' => 3, 'filename' => 'jane.jpg'];

        $author = $this->factory->fromRels([$related])->get('author-1');
        self::assertInstanceOf(StoryTransfer::class, $author);
        $content = $author->getContent();
        self::assertInstanceOf(NestedFixtureTransfer::class, $content);
        self::assertNotNull($content->getImage());
        self::assertSame('jane.jpg', $content->getImage()->getFilename());
    }

    /**
     * @return array<string, mixed>
     */
    private function related(string $uuid, string $headline): array
    {
        return [
            'uuid' => $uuid,
            'slug' => $uuid,
            'full_slug' => 'authors/' . $uuid,
            'content' => ['component' => 'nested_fixture', 'headline' => $headline],
        ];
    }
}
