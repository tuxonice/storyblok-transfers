<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Tlab\StoryblokTransfers\Client\StoryblokManagementClient;
use Tlab\StoryblokTransfers\Schema\ComponentSchemaFetcher;

final class ComponentSchemaFetcherTest extends TestCase
{
    public function testReturnsComponentNameAndFields(): void
    {
        $fetcher = $this->fetcherFor([
            [
                'name' => 'hero',
                'schema' => [
                    'title' => ['type' => 'text'],
                    'image' => ['type' => 'asset'],
                ],
            ],
        ]);

        self::assertSame(
            [
                [
                    'name' => 'hero',
                    'fields' => [
                        'title' => ['type' => 'text'],
                        'image' => ['type' => 'asset'],
                    ],
                ],
            ],
            $fetcher->fetch('1')
        );
    }

    public function testPreservesFieldOrderFromTheApi(): void
    {
        $fetcher = $this->fetcherFor([
            [
                'name' => 'hero',
                'schema' => [
                    'zebra' => ['type' => 'text'],
                    'alpha' => ['type' => 'text'],
                    'middle' => ['type' => 'text'],
                ],
            ],
        ]);

        $fields = $fetcher->fetch('1')[0]['fields'];

        self::assertSame(['zebra', 'alpha', 'middle'], array_keys($fields));
    }

    public function testTreatsMissingSchemaAsNoFields(): void
    {
        $fetcher = $this->fetcherFor([['name' => 'empty_component']]);

        self::assertSame(
            [['name' => 'empty_component', 'fields' => []]],
            $fetcher->fetch('1')
        );
    }

    public function testSkipsComponentsWithoutAName(): void
    {
        $fetcher = $this->fetcherFor([
            ['schema' => ['title' => ['type' => 'text']]],
            ['name' => 'hero', 'schema' => []],
        ]);

        self::assertSame(
            [['name' => 'hero', 'fields' => []]],
            $fetcher->fetch('1')
        );
    }

    public function testReturnsEmptyListWhenSpaceHasNoComponents(): void
    {
        self::assertSame([], $this->fetcherFor([])->fetch('1'));
    }

    /**
     * @param list<array<string,mixed>> $components
     */
    private function fetcherFor(array $components): ComponentSchemaFetcher
    {
        $handler = new MockHandler([
            new Response(200, [], (string) json_encode(['components' => $components])),
        ]);

        $client = new StoryblokManagementClient(
            'token',
            new Client(['handler' => HandlerStack::create($handler)])
        );

        return new ComponentSchemaFetcher($client);
    }
}
