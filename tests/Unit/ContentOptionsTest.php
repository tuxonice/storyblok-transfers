<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tlab\StoryblokTransfers\Content\ContentOptions;
use Tlab\StoryblokTransfers\Content\Version;

final class ContentOptionsTest extends TestCase
{
    public function testDefaultsToThePublishedVersionAndNothingElse(): void
    {
        self::assertSame(['version' => 'published'], (new ContentOptions())->toQuery());
    }

    public function testEmitsTheDraftVersion(): void
    {
        $options = new ContentOptions(Version::Draft);

        self::assertSame(['version' => 'draft'], $options->toQuery());
    }

    public function testEmitsTheLanguageWhenSet(): void
    {
        $options = (new ContentOptions())->withLanguage('de');

        self::assertSame(['version' => 'published', 'language' => 'de'], $options->toQuery());
    }

    public function testJoinsResolveRelationsWithCommas(): void
    {
        $options = (new ContentOptions())->withResolveRelations(['page.author', 'page.related']);

        self::assertSame('page.author,page.related', $options->toQuery()['resolve_relations']);
    }

    public function testOmitsResolveRelationsWhenEmpty(): void
    {
        self::assertArrayNotHasKey('resolve_relations', (new ContentOptions())->toQuery());
    }

    public function testEmitsTheCacheVersionWhenSet(): void
    {
        $options = (new ContentOptions())->withCv('1699999999');

        self::assertSame('1699999999', $options->toQuery()['cv']);
    }

    public function testWithersReturnANewInstanceAndLeaveTheOriginalAlone(): void
    {
        $original = new ContentOptions();
        $changed = $original->withVersion(Version::Draft)->withLanguage('pt');

        self::assertNotSame($original, $changed);
        self::assertSame(Version::Published, $original->version);
        self::assertNull($original->language);
        self::assertSame(Version::Draft, $changed->version);
        self::assertSame('pt', $changed->language);
    }

    public function testEachWitherPreservesTheOtherFields(): void
    {
        $options = (new ContentOptions(Version::Draft, 'de', ['page.author'], '123'))
            ->withLanguage('fr');

        self::assertSame([
            'version' => 'draft',
            'language' => 'fr',
            'resolve_relations' => 'page.author',
            'cv' => '123',
        ], $options->toQuery());
    }
}
