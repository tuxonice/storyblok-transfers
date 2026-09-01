<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use JsonException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Throwable;
use Tlab\StoryblokTransfers\Client\ResourceNotFoundException;
use Tlab\StoryblokTransfers\Client\StoryblokApiException;
use Tlab\StoryblokTransfers\Client\StoryblokContentClient;

final class StoryblokContentClientTest extends TestCase
{
    private const TOKEN = 'super-secret-delivery-token';

    /** @var list<RequestInterface> */
    private array $sentRequests = [];

    public function testBuildsTheUriFromTheBaseAndThePath(): void
    {
        $client = $this->clientReturning([new Response(200, [], '{"story":{}}')]);

        $client->get('cdn/stories/home', []);

        self::assertStringStartsWith(
            'https://api.storyblok.com/v2/cdn/stories/home?',
            (string) $this->sentRequests[0]->getUri()
        );
    }

    public function testSendsTheTokenInTheQueryStringAndNotInAHeader(): void
    {
        $client = $this->clientReturning([new Response(200, [], '{}')]);

        $client->get('cdn/stories/home', []);

        $uri = $this->sentRequests[0]->getUri();
        self::assertSame('token=' . self::TOKEN, $uri->getQuery());
        self::assertSame('', $this->sentRequests[0]->getHeaderLine('Authorization'));
    }

    public function testSerialisesQueryParametersSortedByKey(): void
    {
        $client = $this->clientReturning([new Response(200, [], '{}')]);

        // Deliberately unsorted, and not in the order the CDA documents them.
        $client->get('cdn/stories', [
            'version' => 'draft',
            'starts_with' => 'blog/',
            'per_page' => '25',
        ]);

        // Sorted: per_page, starts_with, token, version. This ordering is what
        // makes a caching decorator's key stable across call sites.
        self::assertSame(
            'per_page=25&starts_with=blog%2F&token=' . self::TOKEN . '&version=draft',
            $this->sentRequests[0]->getUri()->getQuery()
        );
    }

    public function testReturnsTheDecodedBody(): void
    {
        $client = $this->clientReturning([
            new Response(200, [], '{"story":{"uuid":"abc","content":{"component":"page"}}}'),
        ]);

        $response = $client->get('cdn/stories/home', []);

        self::assertSame(['uuid' => 'abc', 'content' => ['component' => 'page']], $response->body['story']);
    }

    public function testReadsTotalAndPerPageFromTheResponseHeaders(): void
    {
        $client = $this->clientReturning([
            new Response(200, ['Total' => '137', 'Per-Page' => '25'], '{"stories":[]}'),
        ]);

        $response = $client->get('cdn/stories', []);

        self::assertSame(137, $response->total);
        self::assertSame(25, $response->perPage);
    }

    public function testReadsTotalAndPerPageFromLowercaseResponseHeaders(): void
    {
        // The live CDA sends these headers lowercase ("total", "per-page"),
        // not capitalised as the Storyblok docs suggest. PSR-7 header lookup
        // is case-insensitive, so this pins behaviour that should already be
        // correct rather than fixing a bug.
        $client = $this->clientReturning([
            new Response(200, ['total' => '137', 'per-page' => '25'], '{"stories":[]}'),
        ]);

        $response = $client->get('cdn/stories', []);

        self::assertSame(137, $response->total);
        self::assertSame(25, $response->perPage);
    }

    public function testLeavesTotalAndPerPageNullWhenTheHeadersAreAbsent(): void
    {
        $client = $this->clientReturning([new Response(200, [], '{"story":{}}')]);

        $response = $client->get('cdn/stories/home', []);

        self::assertNull($response->total);
        self::assertNull($response->perPage);
    }

    public function testThrowsResourceNotFoundOnA404(): void
    {
        // Recorded from the live API: a JSON array with one string, not an
        // object with an "error" key.
        $client = $this->clientReturning([new Response(404, [], '["This record could not be found"]')]);

        $this->expectException(ResourceNotFoundException::class);

        $client->get('cdn/stories/nope', []);
    }

    public function testThrowsTheApiExceptionOnOtherFailureStatuses(): void
    {
        $client = $this->clientReturning([new Response(401, [], '{"error":"Unauthorized"}')]);

        $this->expectException(StoryblokApiException::class);
        $this->expectExceptionMessageMatches('/401/');

        $client->get('cdn/stories/home', []);
    }

    public function testThrowsTheApiExceptionOnARateLimitResponse(): void
    {
        // 429 is pinned separately from 401 and 5xx because it is the failure a
        // consuming application is most likely to have to handle: the CDA rate
        // limits per token, and a caching decorator's whole job is avoiding it.
        $client = $this->clientReturning([new Response(429, [], '{"error":"Too many requests"}')]);

        $this->expectException(StoryblokApiException::class);
        $this->expectExceptionMessageMatches('/429/');

        $client->get('cdn/stories/home', []);
    }

    public function testThrowsWhenTheResponseIsNotJson(): void
    {
        $client = $this->clientReturning([new Response(200, [], 'not json at all')]);

        $this->expectException(StoryblokApiException::class);
        $this->expectExceptionMessageMatches('/decode|JSON/i');

        $client->get('cdn/stories/home', []);
    }

    public function testThrowsWhenTheTopLevelJsonIsAList(): void
    {
        // json_decode(..., true) turns a JSON array into a PHP list, which
        // does not satisfy the array<string, mixed> contract ContentResponse
        // promises its consumers. The probe recorded exactly this shape from
        // a live 404, but any endpoint could in principle return one.
        $client = $this->clientReturning([new Response(200, [], '["a","b"]')]);

        $this->expectException(StoryblokApiException::class);

        $client->get('cdn/stories/home', []);
    }

    public function testSucceedsWithAnEmptyJsonObjectBody(): void
    {
        // json_decode("{}", true) and json_decode("[]", true) both produce
        // PHP's [], and array_is_list([]) is true either way, so an empty
        // top level is genuinely ambiguous and must stay accepted rather than
        // rejected as if it were a list.
        $client = $this->clientReturning([new Response(200, [], '{}')]);

        $response = $client->get('cdn/stories/home', []);

        self::assertSame([], $response->body);
    }

    public function testNeverPutsTheTokenInAFailureMessage(): void
    {
        $client = $this->clientReturning([new Response(500, [], 'upstream exploded')]);

        try {
            $client->get('cdn/stories/home', []);
            self::fail('Expected the client to throw.');
        } catch (StoryblokApiException $e) {
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
            self::assertStringContainsString('[redacted]', $e->getMessage());
        }
    }

    public function testNeverPutsTheTokenInAJsonDecodeFailureMessage(): void
    {
        // The body must genuinely fail to parse - a token embedded in
        // malformed JSON, not valid JSON that merely happens to hold it.
        $client = $this->clientReturning([new Response(200, [], 'not json: ' . self::TOKEN)]);

        try {
            $client->get('cdn/stories/home', []);
            self::fail('Expected the client to throw.');
        } catch (StoryblokApiException $e) {
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }

    public function testNeverPutsTheTokenInANotFoundMessage(): void
    {
        $client = $this->clientReturning([new Response(404, [], '["This record could not be found"]')]);

        try {
            $client->get('cdn/stories/nope', []);
            self::fail('Expected the client to throw.');
        } catch (ResourceNotFoundException $e) {
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }

    public function testNeverPutsTheTokenInAMessageEchoedBackByTheApi(): void
    {
        // Storyblok echoing the request back would otherwise leak the token
        // through the response body rather than through the URI.
        $client = $this->clientReturning([
            new Response(422, [], 'bad request: token=' . self::TOKEN),
        ]);

        try {
            $client->get('cdn/stories/home', []);
            self::fail('Expected the client to throw.');
        } catch (StoryblokApiException $e) {
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }

    public function testKeepsTheTokenOutOfTheStringFormOfATransportFailure(): void
    {
        // getMessage() is not what gets logged. Exception::__toString() renders
        // the whole cause chain, and that is what Monolog, Sentry, Symfony's
        // error handler and PHP's own uncaught-exception handler all emit. A
        // Guzzle ConnectException's message embeds the full effective URI, so
        // chaining one unmodified would leak the token through every one of
        // them while getMessage() stayed clean.
        $url = 'https://api.storyblok.com/v2/cdn/stories/home?token=' . self::TOKEN;
        $client = $this->clientReturning([
            new ConnectException(
                'cURL error 6: Could not resolve host: api.storyblok.com for ' . $url,
                new Request('GET', $url)
            ),
        ]);

        try {
            $client->get('cdn/stories/home', []);
            self::fail('Expected the client to throw.');
        } catch (StoryblokApiException $e) {
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
            self::assertStringNotContainsString(self::TOKEN, (string) $e);
            self::assertStringContainsString('[redacted]', $e->getMessage());
        }
    }

    public function testStillReportsWhatTheTransportSaidWhenItRedactsTheCause(): void
    {
        // Dropping the cause must not drop the diagnosis: the upstream text is
        // carried in the redacted message instead, so "could not resolve host"
        // is still distinguishable from a timeout.
        $client = $this->clientReturning([
            new ConnectException(
                'cURL error 6: Could not resolve host: api.storyblok.com',
                new Request('GET', 'https://api.storyblok.com/v2/cdn/stories/home')
            ),
        ]);

        try {
            $client->get('cdn/stories/home', []);
            self::fail('Expected the client to throw.');
        } catch (StoryblokApiException $e) {
            self::assertStringContainsString('Could not resolve host', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }

    public function testKeepsTheTokenOutOfTheStringFormOfAJsonDecodeFailure(): void
    {
        // The decode branch chains a JsonException, which is safe where a
        // GuzzleException is not: json_last_error_msg() returns a fixed parser
        // diagnostic ("Syntax error") and never echoes the input back, so the
        // cause is worth keeping there. This pins that, because it is the
        // reason for the asymmetry between the two branches.
        $client = $this->clientReturning([new Response(200, [], 'not json: ' . self::TOKEN)]);

        try {
            $client->get('cdn/stories/home', []);
            self::fail('Expected the client to throw.');
        } catch (StoryblokApiException $e) {
            self::assertStringNotContainsString(self::TOKEN, (string) $e);
            self::assertInstanceOf(JsonException::class, $e->getPrevious());
            self::assertStringNotContainsString(self::TOKEN, $e->getPrevious()->getMessage());
        }
    }

    public function testHonoursACustomBaseUriForOtherRegions(): void
    {
        $client = $this->clientReturning(
            [new Response(200, [], '{}')],
            'https://api-us.storyblok.com/v2/'
        );

        $client->get('cdn/stories/home', []);

        self::assertStringStartsWith(
            'https://api-us.storyblok.com/v2/cdn/stories/home',
            (string) $this->sentRequests[0]->getUri()
        );
    }

    /**
     * @param list<Response|Throwable> $responses A queued Throwable is how
     *        MockHandler simulates a transport failure rather than a reply.
     */
    private function clientReturning(
        array $responses,
        string $baseUri = StoryblokContentClient::DEFAULT_BASE_URI
    ): StoryblokContentClient {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push($this->requestRecorder());

        return new StoryblokContentClient(self::TOKEN, new Client(['handler' => $stack]), $baseUri);
    }

    /**
     * Records outgoing requests so the tests can assert on what was actually
     * put on the wire.
     *
     * @return callable(callable): callable
     */
    private function requestRecorder(): callable
    {
        return function (callable $handler): callable {
            return function (RequestInterface $request, array $options) use ($handler) {
                $this->sentRequests[] = $request;

                return $handler($request, $options);
            };
        };
    }
}
