<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Tlab\StoryblokTransfers\Client\StoryblokApiException;
use Tlab\StoryblokTransfers\Client\StoryblokManagementClient;

final class StoryblokManagementClientTest extends TestCase
{
    /** @var list<RequestInterface> */
    private array $sentRequests = [];

    public function testRequestsTheComponentsEndpointForTheSpace(): void
    {
        $client = $this->clientReturning([new Response(200, [], '{"components":[]}')]);

        $client->getComponents('290156928914344');

        self::assertSame(
            'https://mapi.storyblok.com/v1/spaces/290156928914344/components/',
            (string) $this->sentRequests[0]->getUri()
        );
    }

    public function testSendsTheTokenInTheAuthorizationHeader(): void
    {
        $client = $this->clientReturning([new Response(200, [], '{"components":[]}')], 'my-token');

        $client->getComponents('1');

        self::assertSame(
            'my-token',
            $this->sentRequests[0]->getHeaderLine('Authorization')
        );
    }

    public function testReturnsTheComponentsPayload(): void
    {
        $components = [
            ['name' => 'hero', 'schema' => ['title' => ['type' => 'text']]],
            ['name' => 'product_core', 'schema' => []],
        ];

        $client = $this->clientReturning([
            new Response(200, [], (string) json_encode(['components' => $components])),
        ]);

        self::assertSame($components, $client->getComponents('1'));
    }

    public function testThrowsWhenTheApiRejectsTheToken(): void
    {
        $client = $this->clientReturning([new Response(401, [], '{"error":"Unauthorized"}')]);

        $this->expectException(StoryblokApiException::class);
        $this->expectExceptionMessageMatches('/401/');

        $client->getComponents('1');
    }

    public function testThrowsWhenTheResponseIsNotJson(): void
    {
        $client = $this->clientReturning([new Response(200, [], 'not json at all')]);

        $this->expectException(StoryblokApiException::class);
        $this->expectExceptionMessageMatches('/decode|JSON/i');

        $client->getComponents('1');
    }

    public function testThrowsWhenTheComponentsKeyIsMissing(): void
    {
        $client = $this->clientReturning([new Response(200, [], '{"unexpected":true}')]);

        $this->expectException(StoryblokApiException::class);
        $this->expectExceptionMessageMatches('/components/');

        $client->getComponents('1');
    }

    /**
     * @param list<Response> $responses
     */
    private function clientReturning(array $responses, string $token = 'test-token'): StoryblokManagementClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push($this->requestRecorder());

        return new StoryblokManagementClient($token, new Client(['handler' => $stack]));
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
