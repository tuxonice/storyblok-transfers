<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tlab\StoryblokTransfers\Client\ContentResponse;
use Tlab\StoryblokTransfers\Client\StoryblokApiException;
use Tlab\StoryblokTransfers\Content\RelationMapFactory;
use Tlab\StoryblokTransfers\Content\StoryMapper;
use Tlab\StoryblokTransfers\Content\StoryQuery;
use Tlab\StoryblokTransfers\Content\StoryRepository;
use Tlab\StoryblokTransfers\Hydration\ComponentClassResolver;
use Tlab\StoryblokTransfers\Hydration\StoryblokHydrator;
use Tlab\StoryblokTransfers\Tests\Fixture\FakeContentClient;
use Tlab\StoryblokTransfers\Tests\Fixture\NestedFixtureTransfer;

final class StoryListingTest extends TestCase
{
    private const FIXTURE_NAMESPACE = 'Tlab\\StoryblokTransfers\\Tests\\Fixture';

    public function testAsksTheStoriesEndpointWithTheQuery(): void
    {
        $client = FakeContentClient::returning($this->listResponse());

        $this->repository($client)->findBy(new StoryQuery(startsWith: 'blog/'));

        self::assertSame('cdn/stories', $client->requests[0]['path']);
        self::assertSame('blog/', $client->requests[0]['query']['starts_with']);
    }

    public function testReturnsTheHydratedStories(): void
    {
        $client = FakeContentClient::returning($this->listResponse());

        $list = $this->repository($client)->findBy(new StoryQuery());

        self::assertCount(2, $list->getStories());
        self::assertSame('uuid-1', $list->getStories()[0]->getUuid());
        self::assertInstanceOf(NestedFixtureTransfer::class, $list->getStories()[1]->getContent());
        self::assertSame('Second', $list->getStories()[1]->getContent()->getHeadline());
    }

    public function testIsIterable(): void
    {
        $client = FakeContentClient::returning($this->listResponse());

        $headlines = [];

        foreach ($this->repository($client)->findBy(new StoryQuery()) as $story) {
            $content = $story->getContent();
            self::assertInstanceOf(NestedFixtureTransfer::class, $content);
            $headlines[] = $content->getHeadline();
        }

        self::assertSame(['First', 'Second'], $headlines);
    }

    public function testTakesTheTotalsFromTheResponseHeaders(): void
    {
        $client = FakeContentClient::returning($this->listResponse(total: 137, perPage: 25));

        $list = $this->repository($client)->findBy(new StoryQuery(page: 3, perPage: 25));

        self::assertSame(137, $list->getTotal());
        self::assertSame(25, $list->getPerPage());
        self::assertSame(3, $list->getPage());
    }

    public function testFallsBackToTheReturnedCountWhenNoTotalHeaderArrived(): void
    {
        $client = FakeContentClient::returning($this->listResponse(total: null, perPage: null));

        $list = $this->repository($client)->findBy(new StoryQuery(perPage: 50));

        self::assertSame(2, $list->getTotal());
        self::assertSame(50, $list->getPerPage());
    }

    public function testReturnsAnEmptyListRatherThanNullWhenNothingMatches(): void
    {
        $client = FakeContentClient::returning(new ContentResponse(['stories' => []], 0, 25));

        $list = $this->repository($client)->findBy(new StoryQuery());

        self::assertSame([], $list->getStories());
        self::assertSame(0, $list->getTotal());
    }

    public function testSharesOneRelationMapAcrossEveryStoryOnThePage(): void
    {
        $client = FakeContentClient::returning(new ContentResponse([
            'stories' => [
                $this->storyWithAuthor('uuid-1', 'First', 'author-1'),
                $this->storyWithAuthor('uuid-2', 'Second', 'author-2'),
            ],
            'rels' => [
                $this->rel('author-1', 'Jane'),
                $this->rel('author-2', 'Ravi'),
            ],
        ], 2, 25));

        $list = $this->repository($client)->findBy(new StoryQuery());
        [$first, $second] = $list->getStories();

        // The same instance, not an equal copy: a relation resolved once for the
        // page is held once.
        self::assertSame($first->getRelations(), $second->getRelations());
        self::assertSame($list->getRelations(), $first->getRelations());

        // And every story can reach every relation on the page.
        $ravi = $first->getRelation('author-2');
        self::assertInstanceOf(NestedFixtureTransfer::class, $ravi);
        self::assertSame('Ravi', $ravi->getHeadline());
    }

    public function testThrowsWhenTheResponseHasNoStoriesKey(): void
    {
        $client = FakeContentClient::returning(new ContentResponse(['unexpected' => true]));

        $this->expectException(StoryblokApiException::class);
        $this->expectExceptionMessageMatches('/stories/');

        $this->repository($client)->findBy(new StoryQuery());
    }

    private function repository(FakeContentClient $client): StoryRepository
    {
        $resolver = new ComponentClassResolver(self::FIXTURE_NAMESPACE);
        $hydrator = new StoryblokHydrator(self::FIXTURE_NAMESPACE);

        return new StoryRepository(
            $client,
            new StoryMapper($resolver, $hydrator),
            new RelationMapFactory($resolver, $hydrator),
        );
    }

    private function listResponse(?int $total = 2, ?int $perPage = 25): ContentResponse
    {
        return new ContentResponse([
            'stories' => [
                [
                    'uuid' => 'uuid-1',
                    'slug' => 'first',
                    'full_slug' => 'blog/first',
                    'content' => ['component' => 'nested_fixture', 'headline' => 'First'],
                ],
                [
                    'uuid' => 'uuid-2',
                    'slug' => 'second',
                    'full_slug' => 'blog/second',
                    'content' => ['component' => 'nested_fixture', 'headline' => 'Second'],
                ],
            ],
        ], $total, $perPage);
    }

    /**
     * The story keeps the plain uuid the CDA leaves in `content`. The resolved
     * story goes in the response's `rels`, which is where resolve_relations
     * actually puts it.
     *
     * @return array<string, mixed>
     */
    private function storyWithAuthor(string $uuid, string $headline, string $authorUuid): array
    {
        return [
            'uuid' => $uuid,
            'slug' => $headline,
            'full_slug' => 'blog/' . $headline,
            'content' => [
                'component' => 'nested_fixture',
                'headline' => $headline,
                'author' => $authorUuid,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rel(string $uuid, string $headline): array
    {
        return [
            'uuid' => $uuid,
            'content' => ['component' => 'nested_fixture', 'headline' => $headline],
        ];
    }
}
