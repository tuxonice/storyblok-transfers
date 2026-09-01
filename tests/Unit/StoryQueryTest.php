<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tlab\StoryblokTransfers\Content\ContentOptions;
use Tlab\StoryblokTransfers\Content\StoryQuery;
use Tlab\StoryblokTransfers\Content\Version;

final class StoryQueryTest extends TestCase
{
    public function testCarriesItsOwnOptionsThrough(): void
    {
        $query = new StoryQuery(new ContentOptions(Version::Draft, 'de'));

        $params = $query->toQuery(self::defaults());

        self::assertSame('draft', $params['version']);
        self::assertSame('de', $params['language']);
    }

    public function testItsOwnOptionsBeatThePassedDefaults(): void
    {
        // The query holds an override, so the defaults it is handed lose.
        $query = new StoryQuery(new ContentOptions(Version::Published));

        self::assertSame('published', $query->toQuery(new ContentOptions(Version::Draft))['version']);
    }

    public function testInheritsThePassedDefaultsWhenItCarriesNoOptions(): void
    {
        // The other half of the precedence, and the half that was broken: a
        // query with no options of its own must not silently substitute
        // version=published for the repository's configured default.
        $query = new StoryQuery(startsWith: 'blog/');

        $params = $query->toQuery(new ContentOptions(Version::Draft, 'de'));

        self::assertSame('draft', $params['version']);
        self::assertSame('de', $params['language']);
    }

    public function testKeepsItsOptionsReadableByTheCaller(): void
    {
        $options = new ContentOptions(Version::Draft);

        self::assertSame($options, (new StoryQuery($options))->options);
        self::assertNull((new StoryQuery())->options);
    }

    public function testAlwaysEmitsPageAndPerPage(): void
    {
        $params = (new StoryQuery())->toQuery(self::defaults());

        self::assertSame('1', $params['page']);
        self::assertSame('25', $params['per_page']);
    }

    public function testEmitsStartsWithAndSortBy(): void
    {
        $query = new StoryQuery(startsWith: 'blog/', sortBy: 'published_at:desc');

        $params = $query->toQuery(self::defaults());

        self::assertSame('blog/', $params['starts_with']);
        self::assertSame('published_at:desc', $params['sort_by']);
    }

    public function testJoinsUuidListsAndExcludedFieldsWithCommas(): void
    {
        $query = new StoryQuery(byUuids: ['a', 'b'], excludingFields: ['body', 'seo']);

        $params = $query->toQuery(self::defaults());

        self::assertSame('a,b', $params['by_uuids']);
        self::assertSame('body,seo', $params['excluding_fields']);
    }

    public function testOmitsEverythingThatWasNotSet(): void
    {
        $params = (new StoryQuery())->toQuery(self::defaults());

        self::assertArrayNotHasKey('starts_with', $params);
        self::assertArrayNotHasKey('sort_by', $params);
        self::assertArrayNotHasKey('by_uuids', $params);
        self::assertArrayNotHasKey('excluding_fields', $params);
    }

    public function testFlattensTheFilterQueryIntoBracketKeys(): void
    {
        $query = new StoryQuery(filterQuery: [
            'component' => ['in' => 'page'],
            'headline' => ['like' => '*news*'],
        ]);

        $params = $query->toQuery(self::defaults());

        self::assertSame('page', $params['filter_query[component][in]']);
        self::assertSame('*news*', $params['filter_query[headline][like]']);
    }

    public function testWithPageReturnsANewQueryAndKeepsEverythingElse(): void
    {
        $original = new StoryQuery(new ContentOptions(Version::Draft), 'blog/', perPage: 10);
        $second = $original->withPage(2);

        self::assertNotSame($original, $second);
        self::assertSame('1', $original->toQuery(self::defaults())['page']);
        self::assertSame('2', $second->toQuery(self::defaults())['page']);
        self::assertSame('blog/', $second->toQuery(self::defaults())['starts_with']);
        self::assertSame('10', $second->toQuery(self::defaults())['per_page']);
        // Including the options override, which withPage() must carry over or
        // paging through a draft listing would flip to published on page two.
        self::assertSame('draft', $second->toQuery(self::defaults())['version']);
    }

    /**
     * Stands in for a repository's configured defaults. Published, so a test
     * that means to prove something about draft cannot pass by accident.
     */
    private static function defaults(): ContentOptions
    {
        return new ContentOptions();
    }
}
