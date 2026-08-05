<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Tlab\StoryblokTransfers\Hydration\StoryblokHydrator;
use Tlab\StoryblokTransfers\StoryblokTransferGenerator;
use Tlab\StoryblokTransfers\Tests\TempDirectory;
use Tlab\StoryblokTransfers\Transfers\AssetTransfer;
use Tlab\StoryblokTransfers\Transfers\LinkTransfer;
use Tlab\TransferObjects\AbstractTransfer;

/**
 * Hydration against real generator output rather than hand-written fixtures, so
 * these fail if the generated shape ever changes.
 */
final class StoryblokHydratorIntegrationTest extends TestCase
{
    use TempDirectory;

    private string $namespace;

    private string $definitionsPath;

    private string $outputPath;

    private StoryblokHydrator $hydrator;

    protected function setUp(): void
    {
        // A namespace per test. require_once guards by file path, and every test
        // generates into a fresh temp directory, so a shared namespace would
        // make the second test fatally redeclare the first test's classes.
        $this->namespace = 'Hydrated\\Gen\\' . ucfirst($this->name());
        $this->definitionsPath = $this->makeTempDir('hyd-def');
        $this->outputPath = $this->makeTempDir('hyd-out');
        $this->hydrator = new StoryblokHydrator($this->namespace);
    }

    protected function tearDown(): void
    {
        $this->removeTempDirs();
    }

    public function testHydratesAnAssetFieldThatFromArrayCannotHandle(): void
    {
        $this->generate([
            ['name' => 'hero', 'schema' => ['image' => ['type' => 'asset']]],
        ]);

        $data = $this->hydrateToArray('HeroTransfer', [
            'image' => ['id' => 7, 'filename' => 'a.jpg', 'alt' => 'A'],
        ]);

        $image = $data['image'];
        self::assertInstanceOf(AssetTransfer::class, $image);
        self::assertSame(7, $image->getId());
        self::assertSame('a.jpg', $image->getFilename());
        self::assertSame('A', $image->getAlt());
    }

    public function testHydratesAMultilinkIncludingCachedUrl(): void
    {
        $this->generate([
            ['name' => 'hero', 'schema' => ['cta' => ['type' => 'multilink']]],
        ]);

        $data = $this->hydrateToArray('HeroTransfer', [
            'cta' => ['id' => 'x', 'url' => '/a', 'linktype' => 'story', 'cached_url' => 'a'],
        ]);

        $cta = $data['cta'];
        self::assertInstanceOf(LinkTransfer::class, $cta);
        self::assertSame('story', $cta->getLinktype());
        self::assertSame('a', $cta->getCachedUrl());
    }

    public function testHydratesMultiassetIntoAListOfAssetTransfers(): void
    {
        $this->generate([
            ['name' => 'hero', 'schema' => ['images' => ['type' => 'multiasset']]],
        ]);

        $data = $this->hydrateToArray('HeroTransfer', [
            'images' => [
                ['id' => 1, 'filename' => 'a.jpg'],
                ['id' => 2, 'filename' => 'b.jpg'],
            ],
        ]);

        $images = $data['images'];
        self::assertIsArray($images);
        self::assertCount(2, $images);

        $first = $images[0];
        $second = $images[1];
        self::assertInstanceOf(AssetTransfer::class, $first);
        self::assertInstanceOf(AssetTransfer::class, $second);
        self::assertSame(1, $first->getId());
        self::assertSame(2, $second->getId());
    }

    public function testHydratesBloksIntoTheirConcreteTransfers(): void
    {
        $this->generate([
            ['name' => 'page', 'schema' => ['body' => ['type' => 'bloks']]],
            ['name' => 'teaser', 'schema' => ['headline' => ['type' => 'text']]],
        ]);

        $data = $this->hydrateToArray('PageTransfer', [
            'body' => [
                ['component' => 'teaser', 'headline' => 'First'],
                ['component' => 'teaser', 'headline' => 'Second'],
            ],
        ]);

        $body = $data['body'];
        self::assertIsArray($body);
        self::assertCount(2, $body);

        $first = $body[0];
        self::assertInstanceOf(AbstractTransfer::class, $first);
        self::assertSame($this->namespace . '\\TeaserTransfer', $first::class);
        self::assertSame(['headline' => 'First'], $first->toArray());

        $second = $body[1];
        self::assertInstanceOf(AbstractTransfer::class, $second);
        self::assertSame(['headline' => 'Second'], $second->toArray());
    }

    public function testHydratesABlokNestedInsideABlok(): void
    {
        $this->generate([
            ['name' => 'page', 'schema' => ['body' => ['type' => 'bloks']]],
            ['name' => 'grid', 'schema' => ['columns' => ['type' => 'bloks']]],
            ['name' => 'teaser', 'schema' => ['headline' => ['type' => 'text']]],
        ]);

        $data = $this->hydrateToArray('PageTransfer', [
            'body' => [
                [
                    'component' => 'grid',
                    'columns' => [['component' => 'teaser', 'headline' => 'Deep']],
                ],
            ],
        ]);

        $body = $data['body'];
        self::assertIsArray($body);

        $grid = $body[0];
        self::assertInstanceOf(AbstractTransfer::class, $grid);
        self::assertSame($this->namespace . '\\GridTransfer', $grid::class);

        $columns = $grid->toArray()['columns'];
        self::assertIsArray($columns);

        $teaser = $columns[0];
        self::assertInstanceOf(AbstractTransfer::class, $teaser);
        self::assertSame($this->namespace . '\\TeaserTransfer', $teaser::class);
        self::assertSame(['headline' => 'Deep'], $teaser->toArray());
    }

    public function testLeavesAnUnknownComponentAsARawArray(): void
    {
        $this->generate([
            ['name' => 'page', 'schema' => ['body' => ['type' => 'bloks']]],
        ]);

        $raw = ['component' => 'newsletter_signup', 'title' => 'Subscribe'];

        $data = $this->hydrateToArray('PageTransfer', ['body' => [$raw]]);

        self::assertSame([$raw], $data['body']);
    }

    public function testPassesRichtextThroughUntouched(): void
    {
        $this->generate([
            ['name' => 'hero', 'schema' => ['body' => ['type' => 'richtext']]],
        ]);

        $nodes = ['type' => 'doc', 'content' => [['type' => 'paragraph']]];

        $data = $this->hydrateToArray('HeroTransfer', ['body' => $nodes]);

        self::assertSame($nodes, $data['body']);
    }

    public function testPassesOptionsThroughAsStrings(): void
    {
        $this->generate([
            ['name' => 'hero', 'schema' => ['tags' => ['type' => 'options']]],
        ]);

        $data = $this->hydrateToArray('HeroTransfer', ['tags' => ['a', 'b']]);

        self::assertSame(['a', 'b'], $data['tags']);
    }

    public function testTurnsAnEmptyAssetStringIntoNull(): void
    {
        $this->generate([
            ['name' => 'hero', 'schema' => ['image' => ['type' => 'asset']]],
        ]);

        $data = $this->hydrateToArray('HeroTransfer', ['image' => '']);

        self::assertNull($data['image']);
    }

    /**
     * Asserts run against toArray() because the generated accessors do not
     * exist at static-analysis time; toArray() is declared on AbstractTransfer.
     *
     * @param array<string, mixed> $content
     *
     * @return array<string, mixed>
     */
    private function hydrateToArray(string $transferName, array $content): array
    {
        /** @var class-string $class */
        $class = $this->namespace . '\\' . $transferName;

        return $this->hydrator->hydrate($class, $content)->toArray();
    }

    /**
     * @param list<array<string,mixed>> $components
     */
    private function generate(array $components): void
    {
        $handler = new MockHandler([
            new Response(200, [], (string) json_encode(['components' => $components])),
        ]);

        (new StoryblokTransferGenerator(
            spaceId: '1',
            token: 'token',
            definitionsPath: $this->definitionsPath,
            outputPath: $this->outputPath,
            namespace: $this->namespace,
            httpClient: new Client(['handler' => HandlerStack::create($handler)]),
        ))->generate();

        foreach ((array) glob($this->outputPath . '/*.php') as $file) {
            require_once (string) $file;
        }
    }
}
