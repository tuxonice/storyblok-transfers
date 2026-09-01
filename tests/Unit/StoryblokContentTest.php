<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tlab\StoryblokTransfers\Client\ContentResponse;
use Tlab\StoryblokTransfers\Content\ContentOptions;
use Tlab\StoryblokTransfers\Content\StoryblokContent;
use Tlab\StoryblokTransfers\Content\StoryQuery;
use Tlab\StoryblokTransfers\Content\Version;
use Tlab\StoryblokTransfers\Tests\Fixture\FakeContentClient;
use Tlab\StoryblokTransfers\Tests\Fixture\NestedFixtureTransfer;

final class StoryblokContentTest extends TestCase
{
    private const FIXTURE_NAMESPACE = 'Tlab\\StoryblokTransfers\\Tests\\Fixture';

    /**
     * The variables fromEnvironment() reads. Cleared before and after every
     * test, because a test that asserts one of them is missing must not depend
     * on another test having happened to clear it - which is what made
     * testThrowsWhenTheDeliveryTokenIsMissing pass as part of the class and
     * fail on its own, on the documented developer setup where Compose forwards
     * STORYBLOK_DELIVERY_TOKEN out of .env.
     */
    private const ENVIRONMENT = [
        'STORYBLOK_DELIVERY_TOKEN',
        'STORYBLOK_NAMESPACE',
        'STORYBLOK_CONTENT_BASE_URI',
        'STORYBLOK_DEFAULT_VERSION',
    ];

    /**
     * The developer's own environment, so clearing it for the tests does not
     * destroy it for the rest of the process.
     *
     * @var array<string, string|false>
     */
    private static array $ambientEnvironment = [];

    public static function setUpBeforeClass(): void
    {
        foreach (self::ENVIRONMENT as $key) {
            self::$ambientEnvironment[$key] = getenv($key);
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$ambientEnvironment as $key => $value) {
            $value === false ? putenv($key) : putenv($key . '=' . $value);
        }

        self::$ambientEnvironment = [];
    }

    protected function setUp(): void
    {
        self::clearEnvironment();
    }

    protected function tearDown(): void
    {
        self::clearEnvironment();
    }

    public function testWiresAWorkingStoryRepository(): void
    {
        $client = FakeContentClient::returning(new ContentResponse(['story' => $this->storyPayload()]));

        $story = StoryblokContent::usingClient($client, self::FIXTURE_NAMESPACE)
            ->stories()
            ->bySlug('home');

        self::assertNotNull($story);
        self::assertInstanceOf(NestedFixtureTransfer::class, $story->getContent());
        self::assertSame('Wired', $story->getContent()->getHeadline());
    }

    public function testReturnsTheSameRepositoryInstanceEachTime(): void
    {
        $content = StoryblokContent::usingClient(new FakeContentClient(), self::FIXTURE_NAMESPACE);

        self::assertSame($content->stories(), $content->stories());
        self::assertSame($content->links(), $content->links());
        self::assertSame($content->datasources(), $content->datasources());
    }

    public function testPassesTheDefaultOptionsToEveryRepository(): void
    {
        $client = FakeContentClient::returning(
            new ContentResponse(['story' => $this->storyPayload()]),
            new ContentResponse(['stories' => []]),
            new ContentResponse(['links' => []]),
            new ContentResponse(['datasource_entries' => []]),
        );

        $content = StoryblokContent::usingClient(
            $client,
            self::FIXTURE_NAMESPACE,
            new ContentOptions(Version::Draft),
        );

        $content->stories()->bySlug('home');
        // The listing is the path that used to ignore the defaults: StoryQuery
        // owned its own published-by-default options, so a preview deployment
        // showed drafts on detail pages and published content on listings.
        $content->stories()->findBy(new StoryQuery(startsWith: 'blog/'));
        $content->links()->all();
        $content->datasources()->entries('categories');

        self::assertSame('cdn/stories', $client->requests[1]['path']);
        self::assertSame(
            ['draft', 'draft', 'draft', 'draft'],
            array_map(
                static fn (array $request): string => $request['query']['version'],
                $client->requests
            )
        );
    }

    public function testReadsItsConfigurationFromTheEnvironment(): void
    {
        putenv('STORYBLOK_DELIVERY_TOKEN=env-token');
        putenv('STORYBLOK_NAMESPACE=' . self::FIXTURE_NAMESPACE);
        putenv('STORYBLOK_DEFAULT_VERSION=draft');

        $content = StoryblokContent::fromEnvironment();

        // Nothing is sent, so this only proves the wiring did not throw and that
        // the version default took.
        self::assertSame(Version::Draft, $content->defaults()->version);
    }

    public function testFallsBackToThePublishedVersionForAnUnknownVersionValue(): void
    {
        putenv('STORYBLOK_DELIVERY_TOKEN=env-token');
        putenv('STORYBLOK_DEFAULT_VERSION=nonsense');

        self::assertSame(Version::Published, StoryblokContent::fromEnvironment()->defaults()->version);
    }

    public function testThrowsWhenTheDeliveryTokenIsMissing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/STORYBLOK_DELIVERY_TOKEN/');

        StoryblokContent::fromEnvironment();
    }

    private static function clearEnvironment(): void
    {
        foreach (self::ENVIRONMENT as $key) {
            putenv($key);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function storyPayload(): array
    {
        return [
            'uuid' => 'u',
            'slug' => 'home',
            'full_slug' => 'home',
            'content' => ['component' => 'nested_fixture', 'headline' => 'Wired'],
        ];
    }
}
