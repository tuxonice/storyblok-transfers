<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tlab\StoryblokTransfers\Client\ContentResponse;
use Tlab\StoryblokTransfers\Client\StoryblokApiException;
use Tlab\StoryblokTransfers\Content\LinkEntry;
use Tlab\StoryblokTransfers\Content\ResponseRows;

final class ResponseRowsTest extends TestCase
{
    public function testReturnsTheRowsUnderTheKeyUnfiltered(): void
    {
        // Unfiltered on purpose: a paginated caller counts skipped rows too.
        $rows = ResponseRows::of(new ContentResponse(['items' => [['a' => 1], 'junk']]), 'items', 'cdn/x');

        self::assertSame([['a' => 1], 'junk'], $rows);
    }

    public function testThrowsNamingTheKeyAndPathWhenTheKeyIsMissing(): void
    {
        $this->expectException(StoryblokApiException::class);
        $this->expectExceptionMessage('No "items" key in the Storyblok response for cdn/x');

        ResponseRows::of(new ContentResponse(['other' => []]), 'items', 'cdn/x');
    }

    public function testThrowsWhenTheKeyIsNotACollection(): void
    {
        $this->expectException(StoryblokApiException::class);

        ResponseRows::of(new ContentResponse(['items' => 'nope']), 'items', 'cdn/x');
    }

    public function testMapsUsableRowsAndSkipsTheRest(): void
    {
        $entries = ResponseRows::map(
            [
                ['name' => 'Home', 'slug' => 'home', 'uuid' => 'u-1', 'is_folder' => false],
                'not an array',
                ['no' => 'usable fields'],
            ],
            LinkEntry::fromPayload(...)
        );

        self::assertCount(1, $entries);
        self::assertSame('home', $entries[0]->getSlug());
    }
}
