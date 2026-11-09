<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tlab\StoryblokTransfers\Client\ContentResponse;
use Tlab\StoryblokTransfers\Client\StoryblokApiException;
use Tlab\StoryblokTransfers\Content\ContentOptions;
use Tlab\StoryblokTransfers\Content\DatasourceRepository;
use Tlab\StoryblokTransfers\Content\Version;
use Tlab\StoryblokTransfers\Tests\Fixture\FakeContentClient;

final class DatasourceRepositoryTest extends TestCase
{
    public function testAsksTheDatasourceEntriesEndpointForTheDatasource(): void
    {
        $client = FakeContentClient::returning($this->entriesResponse());

        (new DatasourceRepository($client))->entries('categories');

        self::assertSame('cdn/datasource_entries', $client->requests[0]['path']);
        self::assertSame('categories', $client->requests[0]['query']['datasource']);
    }

    public function testPassesTheDimensionWhenGiven(): void
    {
        $client = FakeContentClient::returning($this->entriesResponse());

        (new DatasourceRepository($client))->entries('categories', 'de');

        self::assertSame('de', $client->requests[0]['query']['dimension']);
    }

    public function testOmitsTheDimensionWhenNotGiven(): void
    {
        $client = FakeContentClient::returning($this->entriesResponse());

        (new DatasourceRepository($client))->entries('categories');

        self::assertArrayNotHasKey('dimension', $client->requests[0]['query']);
    }

    public function testUsesPerCallOptionsOverTheDefaults(): void
    {
        // This endpoint has no use for version or language, but the options are
        // threaded through it exactly as LinkRepository threads them, and only
        // one of the two had that pinned.
        $client = FakeContentClient::returning($this->entriesResponse());
        $repository = new DatasourceRepository($client, new ContentOptions(Version::Draft));

        $repository->entries('categories', null, new ContentOptions(Version::Published, 'de'));

        self::assertSame('published', $client->requests[0]['query']['version']);
        self::assertSame('de', $client->requests[0]['query']['language']);
    }

    public function testUsesTheConfiguredDefaultsWhenNoOptionsAreGiven(): void
    {
        $client = FakeContentClient::returning($this->entriesResponse());

        (new DatasourceRepository($client, new ContentOptions(Version::Draft)))->entries('categories');

        self::assertSame('draft', $client->requests[0]['query']['version']);
    }

    public function testMapsTheEntries(): void
    {
        $entries = (new DatasourceRepository(FakeContentClient::returning($this->entriesResponse())))
            ->entries('categories');

        self::assertCount(2, $entries);
        self::assertSame('News', $entries[0]->getName());
        self::assertSame('news', $entries[0]->getValue());
        self::assertSame(1, $entries[0]->getId());
        self::assertNull($entries[0]->getDimensionValue());
        self::assertSame('Nachrichten', $entries[1]->getDimensionValue());
    }

    public function testSkipsEntriesWithNoName(): void
    {
        $client = FakeContentClient::returning(new ContentResponse([
            'datasource_entries' => [
                ['name' => 'Keep', 'value' => 'keep'],
                ['value' => 'nameless'],
                'not an array',
            ],
        ]));

        $entries = (new DatasourceRepository($client))->entries('categories');

        self::assertCount(1, $entries);
        self::assertSame('Keep', $entries[0]->getName());
    }

    public function testReturnsAnEmptyListForADatasourceWithNoEntries(): void
    {
        $client = FakeContentClient::returning(new ContentResponse(['datasource_entries' => []]));

        self::assertSame([], (new DatasourceRepository($client))->entries('empty'));
    }

    public function testThrowsWhenTheResponseHasNoEntriesKey(): void
    {
        $client = FakeContentClient::returning(new ContentResponse(['unexpected' => true]));

        $this->expectException(StoryblokApiException::class);
        $this->expectExceptionMessageMatches('/datasource_entries/');

        (new DatasourceRepository($client))->entries('categories');
    }

    public function testAsksForTheFirstPageAtTheMaximumPageSize(): void
    {
        $client = FakeContentClient::returning($this->entriesResponse());

        (new DatasourceRepository($client))->entries('categories');

        self::assertSame('1', $client->requests[0]['query']['page']);
        self::assertSame('1000', $client->requests[0]['query']['per_page']);
    }

    public function testWalksEveryPageUntilAShortOneComesBack(): void
    {
        // The endpoint is paginated; one request would silently drop
        // everything past the first page.
        $client = FakeContentClient::returning(
            new ContentResponse(['datasource_entries' => $this->rows(1000, 0)]),
            new ContentResponse(['datasource_entries' => $this->rows(3, 1000)]),
        );

        $entries = (new DatasourceRepository($client))->entries('countries');

        self::assertCount(1003, $entries);
        self::assertSame('entry-1002', $entries[1002]->getName());
        self::assertCount(2, $client->requests);
        self::assertSame('2', $client->requests[1]['query']['page']);
        self::assertSame('countries', $client->requests[1]['query']['datasource']);
    }

    public function testStopsWhenTheTotalHeaderSaysEverythingIsIn(): void
    {
        // An exact multiple of the page size: the Total header saves the
        // trailing request for an empty page.
        $client = FakeContentClient::returning(
            new ContentResponse(['datasource_entries' => $this->rows(1000, 0)], 1000, 1000),
        );

        self::assertCount(1000, (new DatasourceRepository($client))->entries('countries'));
        self::assertCount(1, $client->requests);
    }

    public function testStopsOnAnEmptyPageWhenThereIsNoTotalHeader(): void
    {
        $client = FakeContentClient::returning(
            new ContentResponse(['datasource_entries' => $this->rows(1000, 0)]),
            new ContentResponse(['datasource_entries' => []]),
        );

        self::assertCount(1000, (new DatasourceRepository($client))->entries('countries'));
        self::assertCount(2, $client->requests);
    }

    private function entriesResponse(): ContentResponse
    {
        return new ContentResponse([
            'datasource_entries' => [
                ['id' => 1, 'name' => 'News', 'value' => 'news', 'dimension_value' => null],
                ['id' => 2, 'name' => 'Events', 'value' => 'events', 'dimension_value' => 'Nachrichten'],
            ],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(int $count, int $offset): array
    {
        return array_map(
            static fn (int $i): array => ['id' => $i, 'name' => 'entry-' . $i, 'value' => 'v' . $i],
            $count === 0 ? [] : range($offset, $offset + $count - 1)
        );
    }
}
