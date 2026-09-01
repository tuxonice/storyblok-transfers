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

    private function entriesResponse(): ContentResponse
    {
        return new ContentResponse([
            'datasource_entries' => [
                ['id' => 1, 'name' => 'News', 'value' => 'news', 'dimension_value' => null],
                ['id' => 2, 'name' => 'Events', 'value' => 'events', 'dimension_value' => 'Nachrichten'],
            ],
        ]);
    }
}
