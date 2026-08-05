<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Tlab\StoryblokTransfers\StoryblokTransferGenerator;
use Tlab\StoryblokTransfers\Tests\TempDirectory;

/**
 * End-to-end: a stubbed Management API response in, real definition files and
 * real generated PHP classes out.
 */
final class StoryblokTransferGeneratorTest extends TestCase
{
    use TempDirectory;

    private string $definitionsPath;

    private string $outputPath;

    protected function setUp(): void
    {
        $this->definitionsPath = $this->makeTempDir('gen-def');
        $this->outputPath = $this->makeTempDir('gen-out');
    }

    protected function tearDown(): void
    {
        $this->removeTempDirs();
    }

    public function testWritesADefinitionFilePerComponent(): void
    {
        $this->generatorFor([
            ['name' => 'hero', 'schema' => ['title' => ['type' => 'text']]],
            ['name' => 'product_core', 'schema' => ['code' => ['type' => 'text']]],
        ])->generate();

        self::assertFileExists($this->definitionsPath . '/Hero.json');
        self::assertFileExists($this->definitionsPath . '/ProductCore.json');
    }

    public function testGeneratesATransferClassPerComponent(): void
    {
        $this->generatorFor([
            ['name' => 'hero', 'schema' => ['title' => ['type' => 'text']]],
            ['name' => 'product_core', 'schema' => ['code' => ['type' => 'text']]],
        ])->generate();

        self::assertFileExists($this->outputPath . '/HeroTransfer.php');
        self::assertFileExists($this->outputPath . '/ProductCoreTransfer.php');
    }

    public function testEveryGeneratedClassIsValidPhp(): void
    {
        $this->generatorFor([
            [
                'name' => 'kitchen_sink',
                'schema' => [
                    'headline' => ['type' => 'text'],
                    'body' => ['type' => 'richtext'],
                    'specs' => ['type' => 'table'],
                    'widget' => ['type' => 'some_custom_plugin'],
                    'price' => ['type' => 'number'],
                    'active' => ['type' => 'boolean'],
                    'image' => ['type' => 'asset'],
                    'images' => ['type' => 'multiasset'],
                    'cta' => ['type' => 'multilink'],
                    'tags' => ['type' => 'options'],
                    'related' => ['type' => 'story'],
                    'bloks' => ['type' => 'bloks'],
                ],
            ],
        ])->generate();

        $file = $this->outputPath . '/KitchenSinkTransfer.php';

        exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $exitCode);

        self::assertSame(0, $exitCode, implode("\n", $output) . "\n\n" . (string) file_get_contents($file));
    }

    public function testCreatesMissingDirectories(): void
    {
        $definitions = $this->definitionsPath . '/nested/definitions';
        $output = $this->outputPath . '/nested/dtos';

        $this->generatorFor(
            [['name' => 'hero', 'schema' => ['title' => ['type' => 'text']]]],
            $definitions,
            $output
        )->generate();

        self::assertDirectoryExists($definitions);
        self::assertFileExists($output . '/HeroTransfer.php');
    }

    public function testGeneratedClassUsesTheConfiguredNamespace(): void
    {
        $this->generatorFor([['name' => 'hero', 'schema' => ['title' => ['type' => 'text']]]])->generate();

        self::assertStringContainsString(
            'namespace App\\DataTransferObjects;',
            (string) file_get_contents($this->outputPath . '/HeroTransfer.php')
        );
    }

    public function testGeneratedClassImportsBundledTransfers(): void
    {
        $this->generatorFor([['name' => 'hero', 'schema' => ['image' => ['type' => 'asset']]]])->generate();

        self::assertStringContainsString(
            'use Tlab\\StoryblokTransfers\\Transfers\\AssetTransfer;',
            (string) file_get_contents($this->outputPath . '/HeroTransfer.php')
        );
    }

    public function testReportsSkippedFieldsAsWarnings(): void
    {
        $result = $this->generatorFor([
            [
                'name' => 'hero',
                'schema' => [
                    'title' => ['type' => 'text'],
                    'headline_2' => ['type' => 'text'],
                ],
            ],
        ])->generate();

        self::assertCount(1, $result->warnings);
        self::assertStringContainsString('headline_2', $result->warnings[0]);
        self::assertStringContainsString('hero', $result->warnings[0]);
    }

    public function testSkippedFieldIsAbsentFromTheGeneratedClass(): void
    {
        $this->generatorFor([
            [
                'name' => 'hero',
                'schema' => [
                    'title' => ['type' => 'text'],
                    'headline_2' => ['type' => 'text'],
                ],
            ],
        ])->generate();

        $source = (string) file_get_contents($this->outputPath . '/HeroTransfer.php');

        self::assertStringContainsString('$title', $source);
        self::assertStringNotContainsString('headline', $source);
    }

    public function testReportsWhatItGenerated(): void
    {
        $result = $this->generatorFor([
            ['name' => 'hero', 'schema' => ['title' => ['type' => 'text']]],
            ['name' => 'teaser', 'schema' => ['title' => ['type' => 'text']]],
        ])->generate();

        self::assertSame(['Hero', 'Teaser'], $result->componentNames);
        self::assertSame([], $result->warnings);
    }

    public function testSkipsComponentsThatEndUpWithNoUsableFields(): void
    {
        $result = $this->generatorFor([
            ['name' => 'tabs_only', 'schema' => ['tab-1' => ['type' => 'tab']]],
        ])->generate();

        self::assertSame([], $result->componentNames);
        self::assertFileDoesNotExist($this->outputPath . '/TabsOnlyTransfer.php');
    }

    public function testRoundTripsAStoryPayloadThroughTheGeneratedClass(): void
    {
        $this->generatorFor([
            [
                'name' => 'round_trip',
                'schema' => [
                    'headline' => ['type' => 'text'],
                    'body' => ['type' => 'richtext'],
                    'tags' => ['type' => 'options'],
                ],
            ],
        ])->generate();

        require $this->outputPath . '/RoundTripTransfer.php';

        /** @var class-string<\Tlab\TransferObjects\AbstractTransfer> $class */
        $class = 'App\\DataTransferObjects\\RoundTripTransfer';

        $transfer = $class::fromArray([
            'headline' => 'Hello',
            'body' => ['type' => 'doc', 'content' => []],
            'tags' => ['a', 'b'],
        ]);

        // Accessors exist only on the class generated above, so they are
        // invisible to static analysis.
        /** @phpstan-ignore-next-line */
        self::assertSame('Hello', $transfer->getHeadline());
        /** @phpstan-ignore-next-line */
        self::assertSame(['type' => 'doc', 'content' => []], $transfer->getBody());
        /** @phpstan-ignore-next-line */
        self::assertSame(['a', 'b'], $transfer->getTags());
    }

    /**
     * Characterises a limitation of transfer-objects rather than of this
     * package: fromArray() passes raw payload values straight to the setter, so
     * a nested asset/link/blok arrives as an array where the setter demands a
     * transfer instance. Consumers have to map those fields themselves.
     *
     * If upstream ever hydrates nested transfers, this test fails and the
     * README section on hydration should be revisited.
     */
    public function testFromArrayCannotHydrateNestedTransferFields(): void
    {
        $this->generatorFor([
            ['name' => 'nested', 'schema' => ['image' => ['type' => 'asset']]],
        ])->generate();

        require $this->outputPath . '/NestedTransfer.php';

        /** @var class-string<\Tlab\TransferObjects\AbstractTransfer> $class */
        $class = 'App\\DataTransferObjects\\NestedTransfer';

        $this->expectException(\TypeError::class);

        $class::fromArray(['image' => ['id' => 1, 'filename' => 'a.jpg']]);
    }

    /**
     * @param list<array<string,mixed>> $components
     */
    private function generatorFor(
        array $components,
        ?string $definitionsPath = null,
        ?string $outputPath = null,
    ): StoryblokTransferGenerator {
        $handler = new MockHandler([
            new Response(200, [], (string) json_encode(['components' => $components])),
        ]);

        return new StoryblokTransferGenerator(
            spaceId: '1',
            token: 'token',
            definitionsPath: $definitionsPath ?? $this->definitionsPath,
            outputPath: $outputPath ?? $this->outputPath,
            namespace: 'App\\DataTransferObjects',
            httpClient: new Client(['handler' => HandlerStack::create($handler)]),
        );
    }
}
