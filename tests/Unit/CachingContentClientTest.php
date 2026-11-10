<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Clock\MockClock;
use Tlab\StoryblokTransfers\Client\CachingContentClient;
use Tlab\StoryblokTransfers\Client\ContentResponse;
use Tlab\StoryblokTransfers\Client\ResourceNotFoundException;
use Tlab\StoryblokTransfers\Client\StoryblokApiException;
use Tlab\StoryblokTransfers\Tests\Fixture\BrokenCache;
use Tlab\StoryblokTransfers\Tests\Fixture\FakeContentClient;

final class CachingContentClientTest extends TestCase
{
    private const PUBLISHED = ['version' => 'published'];

    private Psr16Cache $cache;

    private MockClock $clock;

    /** @var list<string> */
    private array $warnings = [];

    protected function setUp(): void
    {
        $this->cache = new Psr16Cache(new ArrayAdapter());
        $this->clock = new MockClock('2026-11-10 12:00:00');
    }

    public function testAsksStoryblokOnTheFirstRead(): void
    {
        $inner = FakeContentClient::returning($this->response('first'));

        $response = $this->client($inner)->get('cdn/stories/home', self::PUBLISHED);

        self::assertSame(['story' => 'first'], $response->body);
        self::assertCount(1, $inner->requests);
    }

    public function testAnswersARepeatedReadFromTheCacheWhileFresh(): void
    {
        $inner = FakeContentClient::returning(new ContentResponse(['stories' => []], 7, 25));
        $client = $this->client($inner);

        $client->get('cdn/stories', self::PUBLISHED);
        $this->clock->sleep(299);
        $second = $client->get('cdn/stories', self::PUBLISHED);

        self::assertCount(1, $inner->requests);
        self::assertSame(['stories' => []], $second->body);
        self::assertSame(7, $second->total);
        self::assertSame(25, $second->perPage);
    }

    public function testRefreshesFromStoryblokOnceTheCopyIsStale(): void
    {
        $inner = FakeContentClient::returning($this->response('old'), $this->response('new'));
        $client = $this->client($inner);

        $client->get('cdn/stories/home', self::PUBLISHED);
        $this->clock->sleep(300);
        $refreshed = $client->get('cdn/stories/home', self::PUBLISHED);
        $afterwards = $client->get('cdn/stories/home', self::PUBLISHED);

        self::assertSame(['story' => 'new'], $refreshed->body);
        self::assertSame(['story' => 'new'], $afterwards->body);
        self::assertCount(2, $inner->requests);
    }

    public function testServesTheStaleCopyWhenTheRefreshFails(): void
    {
        $inner = FakeContentClient::returning(
            $this->response('old'),
            new StoryblokApiException('Storyblok Content Delivery API returned HTTP 503'),
        );
        $client = $this->client($inner);

        $client->get('cdn/stories/home', self::PUBLISHED);
        $this->clock->sleep(3600);
        $response = $client->get('cdn/stories/home', self::PUBLISHED);

        self::assertSame(['story' => 'old'], $response->body);
    }

    public function testLogsAWarningWhenItServesAStaleCopy(): void
    {
        $inner = FakeContentClient::returning(
            $this->response('old'),
            new StoryblokApiException('Storyblok Content Delivery API returned HTTP 503'),
        );
        $client = $this->client($inner);

        $client->get('cdn/stories/home', self::PUBLISHED);
        $this->clock->sleep(600);
        $client->get('cdn/stories/home', self::PUBLISHED);

        self::assertCount(1, $this->warnings);
        self::assertStringContainsString('cdn/stories/home', $this->warnings[0]);
        self::assertStringContainsString('HTTP 503', $this->warnings[0]);
    }

    public function testLetsTheFailureThroughOnceTheStaleCopyIsTooOld(): void
    {
        $inner = FakeContentClient::returning(
            $this->response('old'),
            new StoryblokApiException('down'),
        );
        $client = $this->client($inner);

        $client->get('cdn/stories/home', self::PUBLISHED);
        $this->clock->sleep(300 + 86400);

        $this->expectException(StoryblokApiException::class);

        $client->get('cdn/stories/home', self::PUBLISHED);
    }

    public function testLetsTheFailureThroughWhenNothingIsCached(): void
    {
        $inner = FakeContentClient::returning(new StoryblokApiException('down'));

        $this->expectException(StoryblokApiException::class);

        $this->client($inner)->get('cdn/stories/home', self::PUBLISHED);
    }

    public function testNeverCachesDrafts(): void
    {
        $inner = FakeContentClient::returning($this->response('one'), $this->response('two'));
        $client = $this->client($inner);

        $client->get('cdn/stories/home', ['version' => 'draft']);
        $second = $client->get('cdn/stories/home', ['version' => 'draft']);

        self::assertSame(['story' => 'two'], $second->body);
        self::assertCount(2, $inner->requests);
    }

    public function testNeverServesAStaleDraft(): void
    {
        // A draft failing is an error for the editor to see, not something to
        // paper over with an old copy - and there is no old copy to use.
        $inner = FakeContentClient::returning($this->response('one'), new StoryblokApiException('down'));
        $client = $this->client($inner);

        $client->get('cdn/stories/home', ['version' => 'draft']);

        $this->expectException(StoryblokApiException::class);

        $client->get('cdn/stories/home', ['version' => 'draft']);
    }

    public function testFindsTheSameEntryWhateverOrderTheQueryIsIn(): void
    {
        $inner = FakeContentClient::returning($this->response('only'));
        $client = $this->client($inner);

        $client->get('cdn/stories', ['version' => 'published', 'starts_with' => 'blog/', 'page' => '1']);
        $client->get('cdn/stories', ['page' => '1', 'starts_with' => 'blog/', 'version' => 'published']);

        self::assertCount(1, $inner->requests);
    }

    public function testKeepsDifferentReadsApart(): void
    {
        $inner = FakeContentClient::returning(
            $this->response('home'),
            $this->response('about'),
            $this->response('home in german'),
        );
        $client = $this->client($inner);

        $client->get('cdn/stories/home', self::PUBLISHED);
        $about = $client->get('cdn/stories/about', self::PUBLISHED);
        $german = $client->get('cdn/stories/home', ['version' => 'published', 'language' => 'de']);

        self::assertSame(['story' => 'about'], $about->body);
        self::assertSame(['story' => 'home in german'], $german->body);
        self::assertCount(3, $inner->requests);
    }

    public function testKeepsDifferentPrefixesApartInOneSharedCache(): void
    {
        $first = FakeContentClient::returning($this->response('space one'));
        $second = FakeContentClient::returning($this->response('space two'));

        $this->client($first, 'space-one')->get('cdn/stories/home', self::PUBLISHED);
        $response = $this->client($second, 'space-two')->get('cdn/stories/home', self::PUBLISHED);

        self::assertSame(['story' => 'space two'], $response->body);
    }

    public function testRemembersANotFoundWhileFresh(): void
    {
        // A router asking for a slug that doesn't exist - a bot probing URLs,
        // say - should not reach Storyblok on every request.
        $inner = FakeContentClient::returning(new ResourceNotFoundException('nothing there'));
        $client = $this->client($inner);

        foreach ([1, 2] as $attempt) {
            try {
                $client->get('cdn/stories/nope', self::PUBLISHED);
                self::fail('Expected a not-found on attempt ' . $attempt);
            } catch (ResourceNotFoundException) {
            }
        }

        self::assertCount(1, $inner->requests);
    }

    public function testAsksAgainAboutANotFoundOnceItIsStale(): void
    {
        $inner = FakeContentClient::returning(
            new ResourceNotFoundException('nothing there'),
            $this->response('published since'),
        );
        $client = $this->client($inner);

        try {
            $client->get('cdn/stories/new', self::PUBLISHED);
        } catch (ResourceNotFoundException) {
        }

        $this->clock->sleep(300);

        self::assertSame(['story' => 'published since'], $client->get('cdn/stories/new', self::PUBLISHED)->body);
    }

    public function testNeverServesAStaleNotFound(): void
    {
        $inner = FakeContentClient::returning(
            new ResourceNotFoundException('nothing there'),
            new StoryblokApiException('down'),
        );
        $client = $this->client($inner);

        try {
            $client->get('cdn/stories/new', self::PUBLISHED);
        } catch (ResourceNotFoundException) {
        }

        $this->clock->sleep(300);

        $this->expectException(StoryblokApiException::class);

        $client->get('cdn/stories/new', self::PUBLISHED);
    }

    public function testSkipsABrokenCacheAndAsksStoryblok(): void
    {
        $inner = FakeContentClient::returning($this->response('one'), $this->response('two'));
        $client = new CachingContentClient($inner, new BrokenCache(), clock: $this->clock);

        self::assertSame(['story' => 'one'], $client->get('cdn/stories/home', self::PUBLISHED)->body);
        self::assertSame(['story' => 'two'], $client->get('cdn/stories/home', self::PUBLISHED)->body);
    }

    public function testTreatsAnEntryItDidNotWriteAsAMiss(): void
    {
        $inner = FakeContentClient::returning($this->response('first'), $this->response('asked again'));
        $client = $this->client($inner);

        $client->get('cdn/stories/home', self::PUBLISHED);

        // Something else wrote junk over the entry.
        foreach ($this->keysIn() as $key) {
            $this->cache->set($key, 'not an entry');
        }

        self::assertSame(['story' => 'asked again'], $client->get('cdn/stories/home', self::PUBLISHED)->body);
        self::assertCount(2, $inner->requests);
    }

    public function testAcceptsAZeroFreshWindow(): void
    {
        // Always refresh, but still fall back to the last copy in an outage.
        $inner = FakeContentClient::returning($this->response('old'), new StoryblokApiException('down'));
        $client = new CachingContentClient($inner, $this->cache, freshFor: 0, clock: $this->clock);

        $client->get('cdn/stories/home', self::PUBLISHED);

        self::assertSame(['story' => 'old'], $client->get('cdn/stories/home', self::PUBLISHED)->body);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidSettings(): array
    {
        return [
            'negative fresh window' => [['freshFor' => -1]],
            'negative stale window' => [['keepStaleFor' => -1]],
            'empty prefix' => [['keyPrefix' => '']],
            'reserved characters in prefix' => [['keyPrefix' => 'story:blok']],
        ];
    }

    /**
     * @param array<string, mixed> $settings
     */
    #[DataProvider('invalidSettings')]
    public function testRejectsInvalidSettings(array $settings): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CachingContentClient(FakeContentClient::returning(), $this->cache, ...$settings);
    }

    private function client(FakeContentClient $inner, string $prefix = 'storyblok'): CachingContentClient
    {
        $test = $this;

        return new CachingContentClient(
            $inner,
            $this->cache,
            keyPrefix: $prefix,
            logger: new class ($test) extends AbstractLogger {
                public function __construct(private readonly CachingContentClientTest $test)
                {
                }

                /**
                 * $message is left untyped on purpose: psr/log 1.x declares it
                 * untyped and 3.x as string|Stringable, and only an untyped
                 * parameter is compatible with both.
                 *
                 * @param string|Stringable $message
                 * @param mixed[] $context
                 */
                public function log($level, $message, array $context = []): void
                {
                    if ($level === 'warning') {
                        $this->test->recordWarning((string) $message);
                    }
                }
            },
            clock: $this->clock,
        );
    }

    public function recordWarning(string $message): void
    {
        $this->warnings[] = $message;
    }

    private function response(string $story): ContentResponse
    {
        return new ContentResponse(['story' => $story]);
    }

    /**
     * @return list<string>
     */
    private function keysIn(): array
    {
        $pool = (new \ReflectionProperty(Psr16Cache::class, 'pool'))->getValue($this->cache);
        self::assertInstanceOf(ArrayAdapter::class, $pool);

        return array_keys($pool->getValues());
    }
}
