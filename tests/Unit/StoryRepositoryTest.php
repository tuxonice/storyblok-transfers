<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tlab\StoryblokTransfers\Client\ContentResponse;
use Tlab\StoryblokTransfers\Client\ResourceNotFoundException;
use Tlab\StoryblokTransfers\Client\StoryblokApiException;
use Tlab\StoryblokTransfers\Content\ContentOptions;
use Tlab\StoryblokTransfers\Content\RelationMapFactory;
use Tlab\StoryblokTransfers\Content\StoryMapper;
use Tlab\StoryblokTransfers\Content\StoryQuery;
use Tlab\StoryblokTransfers\Content\StoryRepository;
use Tlab\StoryblokTransfers\Content\StoryTransfer;
use Tlab\StoryblokTransfers\Content\Version;
use Tlab\StoryblokTransfers\Hydration\ComponentClassResolver;
use Tlab\StoryblokTransfers\Hydration\StoryblokHydrator;
use Tlab\StoryblokTransfers\Tests\Fixture\FakeContentClient;
use Tlab\StoryblokTransfers\Tests\Fixture\NestedFixtureTransfer;

final class StoryRepositoryTest extends TestCase
{
    private const FIXTURE_NAMESPACE = 'Tlab\\StoryblokTransfers\\Tests\\Fixture';

    public function testAsksForTheStoryAtTheSlug(): void
    {
        $client = FakeContentClient::returning($this->storyResponse());

        $this->repository($client)->bySlug('blog/hello-world');

        self::assertSame('cdn/stories/blog/hello-world', $client->requests[0]['path']);
    }

    public function testStripsSurroundingSlashesFromTheSlug(): void
    {
        $client = FakeContentClient::returning($this->storyResponse());

        $this->repository($client)->bySlug('/blog/hello-world/');

        self::assertSame('cdn/stories/blog/hello-world', $client->requests[0]['path']);
    }

    public function testEncodesEachSlugSegmentButKeepsTheSeparators(): void
    {
        $client = FakeContentClient::returning($this->storyResponse());

        $this->repository($client)->bySlug('blog/olá mundo');

        self::assertSame('cdn/stories/blog/ol%C3%A1%20mundo', $client->requests[0]['path']);
    }

    public function testSendsThePublishedVersionByDefault(): void
    {
        $client = FakeContentClient::returning($this->storyResponse());

        $this->repository($client)->bySlug('home');

        self::assertSame('published', $client->requests[0]['query']['version']);
    }

    public function testUsesTheRepositoryDefaultsWhenNoOptionsAreGiven(): void
    {
        $client = FakeContentClient::returning($this->storyResponse());
        $repository = $this->repository($client, new ContentOptions(Version::Draft));

        $repository->bySlug('home');

        self::assertSame('draft', $client->requests[0]['query']['version']);
    }

    public function testPerCallOptionsOverrideTheDefaults(): void
    {
        $client = FakeContentClient::returning($this->storyResponse());
        $repository = $this->repository($client, new ContentOptions(Version::Draft));

        $repository->bySlug('home', null, new ContentOptions(Version::Published, 'de'));

        self::assertSame('published', $client->requests[0]['query']['version']);
        self::assertSame('de', $client->requests[0]['query']['language']);
    }

    public function testLooksUpByUuidThroughTheFindByParameter(): void
    {
        $client = FakeContentClient::returning($this->storyResponse());

        $this->repository($client)->byUuid('story-uuid-1');

        self::assertSame('cdn/stories/story-uuid-1', $client->requests[0]['path']);
        self::assertSame('uuid', $client->requests[0]['query']['find_by']);
    }

    public function testReturnsTheHydratedStory(): void
    {
        $client = FakeContentClient::returning($this->storyResponse());

        $story = $this->repository($client)->bySlug('home');

        self::assertNotNull($story);
        self::assertSame('story-uuid-1', $story->getUuid());
        self::assertInstanceOf(NestedFixtureTransfer::class, $story->getContent());
        self::assertSame('A headline', $story->getContent()->getHeadline());
    }

    public function testReturnsNullWhenTheStoryDoesNotExist(): void
    {
        // 404 is data, not a fault: a router asking for an unknown slug is the
        // hottest path in a consuming application, and exceptions there would be
        // control flow.
        $client = FakeContentClient::returning(new ResourceNotFoundException('nothing there'));

        self::assertNull($this->repository($client)->bySlug('no-such-page'));
    }

    public function testLetsOtherApiFailuresOut(): void
    {
        $client = FakeContentClient::returning(new StoryblokApiException('HTTP 429'));

        $this->expectException(StoryblokApiException::class);

        $this->repository($client)->bySlug('home');
    }

    public function testThrowsWhenTheResponseHasNoStoryKey(): void
    {
        $client = FakeContentClient::returning(new ContentResponse(['unexpected' => true]));

        $this->expectException(StoryblokApiException::class);
        $this->expectExceptionMessageMatches('/story/');

        $this->repository($client)->bySlug('home');
    }

    public function testPassesTheDeclaredClassThroughToTheMapper(): void
    {
        $client = FakeContentClient::returning($this->storyResponse());

        $story = $this->repository($client)->bySlug('home', NestedFixtureTransfer::class);

        self::assertNotNull($story);
        self::assertInstanceOf(NestedFixtureTransfer::class, $story->getContent());
    }

    public function testTypesADeclaredReadWithoutNarrowingIt(): void
    {
        // Deliberately no assertInstanceOf between the read and the getter. The
        // chain only analyses under PHPStan level 8 because @return
        // StoryTransfer<T> echoes the declared class back through the generic;
        // every other test narrows at runtime first, which lets static analysis
        // off the hook, so without this one the echo-back could be replaced by
        // a plain AbstractTransfer and nothing would go red. (assertNotNull is
        // for the ?StoryTransfer nullability only - it says nothing about the
        // content type.)
        $client = FakeContentClient::returning($this->storyResponse());

        $story = $this->repository($client)->bySlug('home', NestedFixtureTransfer::class);

        self::assertNotNull($story);
        self::assertSame('A headline', $story->getContent()->getHeadline());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function emptySlugs(): array
    {
        return ['empty' => [''], 'root path' => ['/'], 'only slashes' => ['//']];
    }

    /**
     * cdn/stories/ is the listing endpoint and answers 200, so asking would
     * surface as a "no story key" fault instead of "not found".
     */
    #[DataProvider('emptySlugs')]
    public function testReturnsNullForAnEmptySlugWithoutAsking(string $slug): void
    {
        $client = FakeContentClient::returning();

        self::assertNull($this->repository($client)->bySlug($slug));
        self::assertSame([], $client->requests);
    }

    public function testReturnsNullForAnEmptyUuidWithoutAsking(): void
    {
        $client = FakeContentClient::returning();

        self::assertNull($this->repository($client)->byUuid(''));
        self::assertSame([], $client->requests);
    }

    public function testFetchesRelationsTheResponseOnlyListedInRelUuids(): void
    {
        // Past 50 resolved relations the CDA stops inlining them in `rels` and
        // lists the uuids in `rel_uuids` instead. Without a follow-up request
        // every one of them would read as null - "not requested".
        $client = FakeContentClient::returning(
            $this->storyResponseWithRelations(
                [$this->related('r1', 'One')],
                ['r1', 'r2']
            ),
            new ContentResponse(['stories' => [$this->related('r2', 'Two')]]),
        );

        $story = $this->repository($client)->bySlug('home');

        self::assertNotNull($story);
        $inlined = $story->getRelation('r1');
        $fetched = $story->getRelation('r2');
        self::assertInstanceOf(StoryTransfer::class, $inlined);
        self::assertInstanceOf(StoryTransfer::class, $fetched);
        self::assertInstanceOf(NestedFixtureTransfer::class, $inlined->getContent());
        self::assertInstanceOf(NestedFixtureTransfer::class, $fetched->getContent());
        self::assertSame('One', $inlined->getContent()->getHeadline());
        self::assertSame('Two', $fetched->getContent()->getHeadline());

        self::assertCount(2, $client->requests);
        self::assertSame('cdn/stories', $client->requests[1]['path']);
        self::assertSame('r2', $client->requests[1]['query']['by_uuids']);
    }

    public function testAsksForLeftoverRelationsInTheSameVersionWithoutResolvingTheirOwnRelations(): void
    {
        $client = FakeContentClient::returning(
            $this->storyResponseWithRelations([], ['r1']),
            new ContentResponse(['stories' => []]),
        );

        $this->repository($client)->bySlug(
            'home',
            null,
            new ContentOptions(Version::Draft, 'de', ['nested_fixture.author'], '123')
        );

        $followUp = $client->requests[1]['query'];
        self::assertSame('draft', $followUp['version']);
        self::assertSame('de', $followUp['language']);
        self::assertSame('123', $followUp['cv']);
        self::assertArrayNotHasKey('resolve_relations', $followUp);
    }

    public function testAsksForLeftoverRelationsFiftyAtATime(): void
    {
        $uuids = array_map(static fn (int $i): string => 'r' . $i, range(1, 120));
        $client = FakeContentClient::returning(
            $this->storyResponseWithRelations([], $uuids),
            new ContentResponse(['stories' => []]),
            new ContentResponse(['stories' => []]),
            new ContentResponse(['stories' => []]),
        );

        $this->repository($client)->bySlug('home');

        self::assertCount(4, $client->requests);
        self::assertSame(implode(',', array_slice($uuids, 0, 50)), $client->requests[1]['query']['by_uuids']);
        self::assertSame(implode(',', array_slice($uuids, 100)), $client->requests[3]['query']['by_uuids']);
    }

    public function testMakesNoFollowUpRequestWhenRelsCarriesEveryReferencedUuid(): void
    {
        $client = FakeContentClient::returning($this->storyResponseWithRelations(
            [$this->related('r1', 'One')],
            ['r1']
        ));

        $this->repository($client)->bySlug('home');

        self::assertCount(1, $client->requests);
    }

    public function testFetchesLeftoverRelationsForAListingToo(): void
    {
        $client = FakeContentClient::returning(
            new ContentResponse([
                'stories' => [$this->storyPayload()],
                'rels' => [],
                'rel_uuids' => ['r1'],
            ]),
            new ContentResponse(['stories' => [$this->related('r1', 'One')]]),
        );

        $list = $this->repository($client)->findBy(new StoryQuery());

        self::assertInstanceOf(StoryTransfer::class, $list->getRelations()->get('r1'));
    }

    private function repository(FakeContentClient $client, ?ContentOptions $defaults = null): StoryRepository
    {
        $resolver = new ComponentClassResolver(self::FIXTURE_NAMESPACE);
        $hydrator = new StoryblokHydrator(self::FIXTURE_NAMESPACE);
        $mapper = new StoryMapper($resolver, $hydrator);
        $factory = new RelationMapFactory($mapper);

        return $defaults === null
            ? new StoryRepository($client, $mapper, $factory)
            : new StoryRepository($client, $mapper, $factory, $defaults);
    }

    private function storyResponse(): ContentResponse
    {
        return new ContentResponse(['story' => $this->storyPayload()]);
    }

    /**
     * @param list<array<string, mixed>> $rels
     * @param list<string> $relUuids
     */
    private function storyResponseWithRelations(array $rels, array $relUuids): ContentResponse
    {
        return new ContentResponse(['story' => $this->storyPayload(), 'rels' => $rels, 'rel_uuids' => $relUuids]);
    }

    /**
     * A related story as the CDA returns it in `rels`: a whole story.
     *
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

    /**
     * @return array<string, mixed>
     */
    private function storyPayload(): array
    {
        return [
            'uuid' => 'story-uuid-1',
            'slug' => 'home',
            'full_slug' => 'en/home',
            'content' => ['component' => 'nested_fixture', 'headline' => 'A headline'],
        ];
    }
}
