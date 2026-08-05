<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Definition;

use JsonException;
use RuntimeException;
use Tlab\StoryblokTransfers\Schema\ComponentNameFormatter;

/**
 * Writes one JSON definition file per Storyblok component.
 *
 * These files are the intermediate, version-controllable artefact: they are
 * meant to be readable and diffable, which is why they are pretty printed and
 * keep their slashes and backslashes unescaped.
 */
final class DefinitionFileWriter
{
    public function __construct(
        private readonly ComponentNameFormatter $nameFormatter = new ComponentNameFormatter(),
    ) {
    }

    /**
     * @param list<array<string,mixed>> $properties
     *
     * @return string The path of the written file.
     */
    public function write(string $definitionsPath, string $componentName, array $properties): string
    {
        $transferName = $this->nameFormatter->toTransferName($componentName);
        $filename = rtrim($definitionsPath, '/') . '/' . $transferName . '.json';

        $definition = [
            'transfers' => [
                [
                    'name' => $transferName,
                    'properties' => $properties,
                ],
            ],
        ];

        try {
            $json = json_encode(
                $definition,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException $e) {
            throw new RuntimeException(
                sprintf('Could not encode definition for component "%s": %s', $componentName, $e->getMessage()),
                0,
                $e
            );
        }

        if (file_put_contents($filename, $json . "\n") === false) {
            throw new RuntimeException('Could not write definition file ' . $filename);
        }

        return $filename;
    }
}
