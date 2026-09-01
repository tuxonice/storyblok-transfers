<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tlab\StoryblokTransfers\Client\ContentResponse;
use Tlab\StoryblokTransfers\Client\StoryblokApiException;
use Tlab\StoryblokTransfers\Content\ContentOptions;
use Tlab\StoryblokTransfers\Content\LinkRepository;
use Tlab\StoryblokTransfers\Content\Version;
use Tlab\StoryblokTransfers\Tests\Fixture\FakeContentClient;

final class LinkRepositoryTest extends TestCase
{
    public function testAsksTheLinksEndpoint(): void
    {
        $client = FakeContentClient::returning($this->linksResponse());

        (new LinkRepository($client))->all();

        self::assertSame('cdn/links', $client->requests[0]['path']);
        self::assertSame('published', $client->requests[0]['query']['version']);
    }

    public function testPassesStartsWithWhenGiven(): void
    {
        $client = FakeContentClient::returning($this->linksResponse());

        (new LinkRepository($client))->all('blog/');

        self::assertSame('blog/', $client->requests[0]['query']['starts_with']);
    }

    public function testOmitsStartsWithWhenNotGiven(): void
    {
        $client = FakeContentClient::returning($this->linksResponse());

        (new LinkRepository($client))->all();

        self::assertArrayNotHasKey('starts_with', $client->requests[0]['query']);
    }

    public function testUsesPerCallOptionsOverTheDefaults(): void
    {
        $client = FakeContentClient::returning($this->linksResponse());
        $repository = new LinkRepository($client, new ContentOptions(Version::Draft));

        $repository->all(null, new ContentOptions(Version::Published, 'de'));

        self::assertSame('published', $client->requests[0]['query']['version']);
        self::assertSame('de', $client->requests[0]['query']['language']);
    }

    public function testTurnsTheUuidKeyedObjectIntoAListInApiOrder(): void
    {
        $entries = (new LinkRepository(FakeContentClient::returning($this->linksResponse())))->all();

        self::assertCount(3, $entries);
        self::assertSame(['u-home', 'u-blog', 'u-post'], array_map(
            static fn ($entry): string => $entry->getUuid(),
            $entries
        ));
    }

    public function testMapsEveryFieldOfAnEntry(): void
    {
        $entries = (new LinkRepository(FakeContentClient::returning($this->linksResponse())))->all();
        $blog = $entries[1];

        self::assertSame('u-blog', $blog->getUuid());
        self::assertSame('blog', $blog->getSlug());
        self::assertSame('Blog', $blog->getName());
        self::assertSame(2, $blog->getId());
        self::assertSame(1, $blog->getParentId());
        self::assertSame(10, $blog->getPosition());
        self::assertSame('/blog', $blog->getRealPath());
        self::assertTrue($blog->isFolder());
        self::assertFalse($blog->isPublished());
        self::assertFalse($blog->isStartpage());
    }

    public function testDefaultsTheOptionalFieldsOfASparseEntry(): void
    {
        $client = FakeContentClient::returning(new ContentResponse([
            'links' => ['u-1' => ['uuid' => 'u-1', 'slug' => 'x', 'name' => 'X']],
        ]));

        $entry = (new LinkRepository($client))->all()[0];

        self::assertNull($entry->getId());
        self::assertNull($entry->getParentId());
        self::assertNull($entry->getRealPath());
        self::assertFalse($entry->isFolder());
        self::assertSame(0, $entry->getPosition());
    }

    public function testSkipsEntriesThatAreNotUsableLinks(): void
    {
        $client = FakeContentClient::returning(new ContentResponse([
            'links' => [
                'u-1' => ['uuid' => 'u-1', 'slug' => 'a', 'name' => 'A'],
                'broken' => 'not an array',
                'u-2' => ['slug' => 'no-uuid'],
            ],
        ]));

        $entries = (new LinkRepository($client))->all();

        self::assertCount(1, $entries);
        self::assertSame('u-1', $entries[0]->getUuid());
    }

    public function testThrowsWhenTheResponseHasNoLinksKey(): void
    {
        $client = FakeContentClient::returning(new ContentResponse(['unexpected' => true]));

        $this->expectException(StoryblokApiException::class);
        $this->expectExceptionMessageMatches('/links/');

        (new LinkRepository($client))->all();
    }

    private function linksResponse(): ContentResponse
    {
        return new ContentResponse([
            'links' => [
                'u-home' => [
                    'id' => 1,
                    'uuid' => 'u-home',
                    'slug' => 'home',
                    'name' => 'Home',
                    'is_folder' => false,
                    'parent_id' => null,
                    'published' => true,
                    'position' => 0,
                    'real_path' => '/home',
                    'is_startpage' => true,
                ],
                'u-blog' => [
                    'id' => 2,
                    'uuid' => 'u-blog',
                    'slug' => 'blog',
                    'name' => 'Blog',
                    'is_folder' => true,
                    'parent_id' => 1,
                    'published' => false,
                    'position' => 10,
                    'real_path' => '/blog',
                    'is_startpage' => false,
                ],
                'u-post' => [
                    'id' => 3,
                    'uuid' => 'u-post',
                    'slug' => 'blog/first',
                    'name' => 'First',
                    'is_folder' => false,
                    'parent_id' => 2,
                    'published' => true,
                    'position' => 20,
                    'real_path' => '/blog/first',
                    'is_startpage' => false,
                ],
            ],
        ]);
    }
}
