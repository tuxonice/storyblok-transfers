<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tlab\StoryblokTransfers\Client\ContentResponse;
use Tlab\StoryblokTransfers\Client\ResourceNotFoundException;
use Tlab\StoryblokTransfers\Client\StoryblokApiException;
use Tlab\StoryblokTransfers\Content\ContentOptions;
use Tlab\StoryblokTransfers\Content\RelationMapFactory;
use Tlab\StoryblokTransfers\Content\StoryMapper;
use Tlab\StoryblokTransfers\Content\StoryRepository;
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

    private function repository(FakeContentClient $client, ?ContentOptions $defaults = null): StoryRepository
    {
        $resolver = new ComponentClassResolver(self::FIXTURE_NAMESPACE);
        $hydrator = new StoryblokHydrator(self::FIXTURE_NAMESPACE);
        $mapper = new StoryMapper($resolver, $hydrator);
        $factory = new RelationMapFactory($resolver, $hydrator);

        return $defaults === null
            ? new StoryRepository($client, $mapper, $factory)
            : new StoryRepository($client, $mapper, $factory, $defaults);
    }

    private function storyResponse(): ContentResponse
    {
        return new ContentResponse([
            'story' => [
                'uuid' => 'story-uuid-1',
                'slug' => 'home',
                'full_slug' => 'en/home',
                'content' => ['component' => 'nested_fixture', 'headline' => 'A headline'],
            ],
        ]);
    }
}
