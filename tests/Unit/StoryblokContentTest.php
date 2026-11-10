<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Tlab\StoryblokTransfers\Client\CachingContentClient;
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

    public function testCachesPublishedReadsWhenGivenACache(): void
    {
        $sent = 0;
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], (string) json_encode(['story' => $this->storyPayload()])),
        ]));
        $stack->push(Middleware::mapRequest(static function (RequestInterface $request) use (&$sent) {
            $sent++;

            return $request;
        }));

        $stories = StoryblokContent::create(
            'token',
            self::FIXTURE_NAMESPACE,
            httpClient: new Client(['handler' => $stack]),
            cache: new Psr16Cache(new ArrayAdapter()),
        )->stories();

        $first = $stories->bySlug('home');
        $second = $stories->bySlug('home');

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame(1, $sent);
    }

    public function testClearCacheMakesTheNextReadAskStoryblok(): void
    {
        $sent = 0;
        $content = StoryblokContent::create(
            'token',
            self::FIXTURE_NAMESPACE,
            httpClient: $this->countingHttpClient($sent, 2),
            cache: new Psr16Cache(new ArrayAdapter()),
        );

        $content->stories()->bySlug('home');
        $content->stories()->bySlug('home');

        self::assertTrue($content->clearCache());

        $content->stories()->bySlug('home');

        self::assertSame(2, $sent);
    }

    public function testClearCacheReachesACachingClientPassedToUsingClient(): void
    {
        $inner = FakeContentClient::returning(
            new ContentResponse(['story' => $this->storyPayload()]),
            new ContentResponse(['story' => $this->storyPayload()]),
        );
        $content = StoryblokContent::usingClient(
            new CachingContentClient($inner, new Psr16Cache(new ArrayAdapter())),
            self::FIXTURE_NAMESPACE,
        );

        $content->stories()->bySlug('home');
        self::assertTrue($content->clearCache());
        $content->stories()->bySlug('home');

        self::assertCount(2, $inner->requests);
    }

    public function testClearCacheWithoutACacheHasNothingToDo(): void
    {
        $content = StoryblokContent::usingClient(FakeContentClient::returning(), self::FIXTURE_NAMESPACE);

        self::assertTrue($content->clearCache());
    }

    public function testPassesTheCacheThroughFromTheEnvironment(): void
    {
        putenv('STORYBLOK_DELIVERY_TOKEN=env-token');

        $content = StoryblokContent::fromEnvironment(new Psr16Cache(new ArrayAdapter()));

        self::assertInstanceOf(
            CachingContentClient::class,
            (new ReflectionProperty(StoryblokContent::class, 'client'))->getValue($content)
        );
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

    public function testDefaultsToThePublishedVersionWhenTheVersionIsUnset(): void
    {
        putenv('STORYBLOK_DELIVERY_TOKEN=env-token');

        self::assertSame(Version::Published, StoryblokContent::fromEnvironment()->defaults()->version);
    }

    public function testReadsTheVersionRegardlessOfCaseAndSurroundingWhitespace(): void
    {
        putenv('STORYBLOK_DELIVERY_TOKEN=env-token');
        putenv('STORYBLOK_DEFAULT_VERSION= Draft ');

        self::assertSame(Version::Draft, StoryblokContent::fromEnvironment()->defaults()->version);
    }

    public function testThrowsForAnUnknownVersionValue(): void
    {
        // Falling back to published would leave a preview environment set to
        // 'drafts' showing no drafts, with nothing pointing at the typo.
        putenv('STORYBLOK_DELIVERY_TOKEN=env-token');
        putenv('STORYBLOK_DEFAULT_VERSION=drafts');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/STORYBLOK_DEFAULT_VERSION.*"drafts"/');

        StoryblokContent::fromEnvironment();
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

    /**
     * An HTTP client that answers $responses requests with the fixture story
     * and counts them in $sent.
     */
    private function countingHttpClient(int &$sent, int $responses): Client
    {
        $body = (string) json_encode(['story' => $this->storyPayload()]);
        $stack = HandlerStack::create(new MockHandler(array_fill(0, $responses, new Response(200, [], $body))));
        $stack->push(Middleware::mapRequest(static function (RequestInterface $request) use (&$sent) {
            $sent++;

            return $request;
        }));

        return new Client(['handler' => $stack]);
    }
}
