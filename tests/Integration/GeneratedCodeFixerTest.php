<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tlab\StoryblokTransfers\Definition\GeneratedCodeFixer;
use Tlab\StoryblokTransfers\Tests\TempDirectory;
use Tlab\TransferObjects\DataTransferBuilder;

/**
 * Exercises the fixer against real transfer-objects output rather than a
 * hand-written fixture, so the test keeps failing if upstream changes shape.
 */
final class GeneratedCodeFixerTest extends TestCase
{
    use TempDirectory;

    private string $definitionsPath;

    private string $outputPath;

    protected function setUp(): void
    {
        $this->definitionsPath = $this->makeTempDir('fixer-def');
        $this->outputPath = $this->makeTempDir('fixer-out');
    }

    protected function tearDown(): void
    {
        $this->removeTempDirs();
    }

    public function testRawGeneratorOutputForNullableArrayIsInvalidPhp(): void
    {
        $this->generate([['name' => 'body', 'type' => 'array', 'nullable' => true]]);

        self::assertFalse(
            $this->lints($this->generatedFile()),
            'Upstream should still emit invalid PHP for nullable array - the fixer exists for this'
        );
    }

    public function testFixedNullableArrayIsValidPhp(): void
    {
        $this->generate([['name' => 'body', 'type' => 'array', 'nullable' => true]]);

        (new GeneratedCodeFixer())->fix($this->outputPath);

        self::assertTrue($this->lints($this->generatedFile()), $this->generatedSource());
    }

    public function testFixedNullableArrayPropertyDefaultsToNull(): void
    {
        $this->generate([['name' => 'body', 'type' => 'array', 'nullable' => true]]);

        (new GeneratedCodeFixer())->fix($this->outputPath);

        self::assertStringContainsString('private ?array $body = null;', $this->generatedSource());
        self::assertStringNotContainsString('= [] = null', $this->generatedSource());
    }

    public function testStripsBogusAddMethod(): void
    {
        $this->generate([['name' => 'body', 'type' => 'array', 'nullable' => true]]);

        (new GeneratedCodeFixer())->fix($this->outputPath);

        self::assertStringNotContainsString('public function add(', $this->generatedSource());
    }

    public function testRepairsEmptyArrayDocblocks(): void
    {
        $this->generate([['name' => 'body', 'type' => 'array', 'nullable' => true]]);

        (new GeneratedCodeFixer())->fix($this->outputPath);

        $source = $this->generatedSource();

        self::assertStringNotContainsString('array<>', $source);
        self::assertStringNotContainsString('array|null<>', $source);
        self::assertStringContainsString('@var array<mixed>|null', $source);
        self::assertStringContainsString('@return array<mixed>|null', $source);
    }

    public function testKeepsGetterAndSetterIntact(): void
    {
        $this->generate([['name' => 'body', 'type' => 'array', 'nullable' => true]]);

        (new GeneratedCodeFixer())->fix($this->outputPath);

        $source = $this->generatedSource();

        self::assertStringContainsString('public function getBody(): ?array', $source);
        self::assertStringContainsString('public function setBody(?array $body): self', $source);
    }

    public function testLeavesValidTypedArrayPropertiesUntouched(): void
    {
        $this->generate([['name' => 'tags', 'type' => 'string[]', 'singular' => 'tag']]);

        $before = $this->generatedSource();
        (new GeneratedCodeFixer())->fix($this->outputPath);

        self::assertSame($before, $this->generatedSource());
        self::assertStringContainsString('public function addTag(string $tag): self', $this->generatedSource());
    }

    public function testLeavesNullableScalarsUntouched(): void
    {
        $this->generate([['name' => 'headline', 'type' => 'string', 'nullable' => true]]);

        $before = $this->generatedSource();
        (new GeneratedCodeFixer())->fix($this->outputPath);

        self::assertSame($before, $this->generatedSource());
    }

    public function testReportsWhichFilesItRepaired(): void
    {
        $this->generate([['name' => 'body', 'type' => 'array', 'nullable' => true]]);

        $repaired = (new GeneratedCodeFixer())->fix($this->outputPath);

        self::assertSame([$this->generatedFile()], $repaired);
    }

    public function testReportsNothingWhenNoRepairNeeded(): void
    {
        $this->generate([['name' => 'headline', 'type' => 'string', 'nullable' => true]]);

        self::assertSame([], (new GeneratedCodeFixer())->fix($this->outputPath));
    }

    public function testFixedClassRoundTripsThroughFromArrayAndToArray(): void
    {
        $this->generate([['name' => 'body', 'type' => 'array', 'nullable' => true]]);
        (new GeneratedCodeFixer())->fix($this->outputPath);

        require $this->generatedFile();

        /** @var class-string<\Tlab\TransferObjects\AbstractTransfer> $class */
        $class = 'Fixture\\Gen\\ProbeTransfer';
        $nodes = [['type' => 'paragraph', 'content' => []]];

        $transfer = $class::fromArray(['body' => $nodes]);

        // Accessors exist only on the class generated above, so they are
        // invisible to static analysis.
        /** @phpstan-ignore-next-line */
        self::assertSame($nodes, $transfer->getBody());
        self::assertSame(['body' => $nodes], $transfer->toArray());
    }

    public function testFixedClassToArrayWorksWhenFieldAbsentFromPayload(): void
    {
        $this->generate([['name' => 'body', 'type' => 'array', 'nullable' => true]], 'Absent');
        (new GeneratedCodeFixer())->fix($this->outputPath);

        require $this->generatedFile('Absent');

        /** @var class-string<\Tlab\TransferObjects\AbstractTransfer> $class */
        $class = 'Fixture\\Gen\\AbsentTransfer';

        $transfer = $class::fromArray([]);

        self::assertSame(['body' => null], $transfer->toArray());
    }

    /**
     * @param list<array<string,mixed>> $properties
     */
    private function generate(array $properties, string $name = 'Probe'): void
    {
        file_put_contents(
            $this->definitionsPath . '/' . $name . '.json',
            (string) json_encode(
                ['transfers' => [['name' => $name, 'properties' => $properties]]],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            )
        );

        (new DataTransferBuilder($this->definitionsPath, $this->outputPath, 'Fixture\\Gen'))->build();
    }

    private function generatedFile(string $name = 'Probe'): string
    {
        return $this->outputPath . '/' . $name . 'Transfer.php';
    }

    private function generatedSource(string $name = 'Probe'): string
    {
        return (string) file_get_contents($this->generatedFile($name));
    }

    private function lints(string $file): bool
    {
        exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $exitCode);

        return $exitCode === 0;
    }
}
