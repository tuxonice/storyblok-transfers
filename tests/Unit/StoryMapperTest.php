<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tlab\StoryblokTransfers\Client\StoryblokApiException;
use Tlab\StoryblokTransfers\Content\RelationMap;
use Tlab\StoryblokTransfers\Content\StoryMapper;
use Tlab\StoryblokTransfers\Content\UnexpectedComponentException;
use Tlab\StoryblokTransfers\Content\UnresolvableComponentException;
use Tlab\StoryblokTransfers\Hydration\ComponentClassResolver;
use Tlab\StoryblokTransfers\Hydration\StoryblokHydrator;
use Tlab\StoryblokTransfers\Tests\Fixture\NestedFixtureTransfer;
use Tlab\StoryblokTransfers\Tests\Fixture\ScalarFixtureTransfer;

final class StoryMapperTest extends TestCase
{
    private const FIXTURE_NAMESPACE = 'Tlab\\StoryblokTransfers\\Tests\\Fixture';

    private StoryMapper $mapper;

    protected function setUp(): void
    {
        $resolver = new ComponentClassResolver(self::FIXTURE_NAMESPACE);
        $hydrator = new StoryblokHydrator(self::FIXTURE_NAMESPACE);

        $this->mapper = new StoryMapper($resolver, $hydrator);
    }

    public function testInfersTheTransferClassFromTheRootComponent(): void
    {
        $story = $this->mapper->mapOne($this->storyPayload(), new RelationMap());

        self::assertInstanceOf(NestedFixtureTransfer::class, $story->getContent());
        self::assertSame('A headline', $story->getContent()->getHeadline());
    }

    public function testFillsTheEnvelope(): void
    {
        $story = $this->mapper->mapOne($this->storyPayload(), new RelationMap());

        self::assertSame('story-uuid-1', $story->getUuid());
        self::assertSame('home', $story->getSlug());
        self::assertSame('en/home', $story->getFullSlug());
        self::assertSame('Home', $story->getName());
        self::assertSame('en', $story->getLang());
        self::assertSame('2026-08-01 10:00:00', $story->getPublishedAt());
        self::assertSame('2026-07-01 09:00:00', $story->getFirstPublishedAt());
        self::assertSame('2026-06-01 08:00:00', $story->getCreatedAt());
        self::assertSame(7, $story->getParentId());
        self::assertSame(['featured'], $story->getTagList());
        self::assertSame(['de' => ['path' => 'de/start']], $story->getTranslatedSlugs());
    }

    public function testAcceptsAStoryWithOnlyTheGuaranteedKeys(): void
    {
        $story = $this->mapper->mapOne([
            'uuid' => 'u',
            'slug' => 's',
            'full_slug' => 'f/s',
            'content' => ['component' => 'nested_fixture'],
        ], new RelationMap());

        self::assertNull($story->getName());
        self::assertNull($story->getParentId());
        self::assertSame([], $story->getTagList());
    }

    public function testReturnsTheDeclaredClassWhenItMatches(): void
    {
        $story = $this->mapper->mapOne($this->storyPayload(), new RelationMap(), NestedFixtureTransfer::class);

        self::assertInstanceOf(NestedFixtureTransfer::class, $story->getContent());
    }

    public function testThrowsWhenTheDeclaredClassDoesNotMatchTheComponent(): void
    {
        $this->expectException(UnexpectedComponentException::class);
        $this->expectExceptionMessageMatches('/nested_fixture/');

        $this->mapper->mapOne($this->storyPayload(), new RelationMap(), ScalarFixtureTransfer::class);
    }

    public function testThrowsWhenTheRootComponentHasNoGeneratedClass(): void
    {
        // Deliberately asymmetric with the hydrator, which leaves an unknown
        // nested blok as a raw array. The root content is the object the caller
        // asked for, and StoryTransfer::$content cannot be null.
        $this->expectException(UnresolvableComponentException::class);
        $this->expectExceptionMessageMatches('/no_such_component/');

        $this->mapper->mapOne([
            'uuid' => 'u',
            'slug' => 's',
            'full_slug' => 'f',
            'content' => ['component' => 'no_such_component'],
        ], new RelationMap());
    }

    public function testThrowsWhenTheStoryCarriesNoContentObject(): void
    {
        // Not UnresolvableComponentException: that one means the root component
        // has no generated class. A payload with no content object is not a
        // story at all, which is what a missing uuid means too.
        $this->expectException(StoryblokApiException::class);
        $this->expectExceptionMessageMatches('/not a story/');

        $this->mapper->mapOne(['uuid' => 'u', 'slug' => 's', 'full_slug' => 'f'], new RelationMap());
    }

    public function testThrowsWhenAGuaranteedEnvelopeKeyIsMissing(): void
    {
        // A response without a uuid is not a story. Better to say so than to
        // hand back an envelope with an empty string in it.
        $this->expectException(StoryblokApiException::class);
        $this->expectExceptionMessageMatches('/uuid/');

        $this->mapper->mapOne([
            'slug' => 's',
            'full_slug' => 'f',
            'content' => ['component' => 'nested_fixture'],
        ], new RelationMap());
    }

    public function testMakesTheGivenRelationMapReachableThroughTheEnvelope(): void
    {
        // The content keeps the plain uuid the CDA left there; the resolved
        // story arrives in the map the caller built from the response root.
        $author = new NestedFixtureTransfer();
        $author->setHeadline('Jane');

        $story = $this->mapper->mapOne([
            'uuid' => 'u',
            'slug' => 's',
            'full_slug' => 'f',
            'content' => [
                'component' => 'nested_fixture',
                'headline' => 'A post',
                'author' => 'author-1',
            ],
        ], new RelationMap(['author-1' => $author]));

        self::assertSame($author, $story->getRelation('author-1'));
        self::assertSame('author-1', $story->getContent()->toArray()['author']);
    }

    /**
     * @return array<string, mixed>
     */
    private function storyPayload(): array
    {
        return [
            'uuid' => 'story-uuid-1',
            'slug' => 'home',
            'full_slug' => 'en/home',
            'name' => 'Home',
            'lang' => 'en',
            'published_at' => '2026-08-01 10:00:00',
            'first_published_at' => '2026-07-01 09:00:00',
            'created_at' => '2026-06-01 08:00:00',
            'parent_id' => 7,
            'tag_list' => ['featured'],
            'translated_slugs' => ['de' => ['path' => 'de/start']],
            'content' => ['component' => 'nested_fixture', 'headline' => 'A headline'],
        ];
    }
}
