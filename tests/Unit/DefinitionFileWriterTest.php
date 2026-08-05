<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tlab\StoryblokTransfers\Definition\DefinitionFileWriter;
use Tlab\StoryblokTransfers\Tests\TempDirectory;

final class DefinitionFileWriterTest extends TestCase
{
    use TempDirectory;

    private DefinitionFileWriter $writer;

    private string $path;

    protected function setUp(): void
    {
        $this->path = $this->makeTempDir('definitions');
        $this->writer = new DefinitionFileWriter();
    }

    protected function tearDown(): void
    {
        $this->removeTempDirs();
    }

    public function testWritesFileNamedAfterPascalCasedComponent(): void
    {
        $this->writer->write($this->path, 'product_core', []);

        self::assertFileExists($this->path . '/ProductCore.json');
    }

    public function testWrapsPropertiesInTransfersEnvelope(): void
    {
        $properties = [['name' => 'code', 'type' => 'string', 'nullable' => true]];

        $this->writer->write($this->path, 'product_core', $properties);

        $decoded = json_decode((string) file_get_contents($this->path . '/ProductCore.json'), true);

        self::assertSame(
            [
                'transfers' => [
                    [
                        'name' => 'ProductCore',
                        'properties' => $properties,
                    ],
                ],
            ],
            $decoded
        );
    }

    public function testWritesHumanReadableJson(): void
    {
        $this->writer->write($this->path, 'hero', [['name' => 'title', 'type' => 'string', 'nullable' => true]]);

        $contents = (string) file_get_contents($this->path . '/Hero.json');

        self::assertStringContainsString("\n", $contents, 'definition should be pretty printed');
        self::assertStringNotContainsString('\/', $contents, 'slashes should not be escaped');
    }

    public function testDoesNotEscapeNamespaceBackslashes(): void
    {
        $this->writer->write($this->path, 'hero', [
            [
                'name' => 'image',
                'type' => 'AssetTransfer',
                'nullable' => true,
                'namespace' => 'Tlab\\StoryblokTransfers\\Transfers\\AssetTransfer',
            ],
        ]);

        $decoded = json_decode((string) file_get_contents($this->path . '/Hero.json'), true);

        self::assertSame(
            'Tlab\\StoryblokTransfers\\Transfers\\AssetTransfer',
            $decoded['transfers'][0]['properties'][0]['namespace']
        );
    }

    public function testPascalCasesSingleWordComponent(): void
    {
        $this->writer->write($this->path, 'hero', []);

        self::assertFileExists($this->path . '/Hero.json');
    }

    public function testPascalCasesHyphenatedComponent(): void
    {
        $this->writer->write($this->path, 'product-detail-page', []);

        self::assertFileExists($this->path . '/ProductDetailPage.json');
    }

    public function testReturnsWrittenFilePath(): void
    {
        $result = $this->writer->write($this->path, 'hero', []);

        self::assertSame($this->path . '/Hero.json', $result);
    }
}
