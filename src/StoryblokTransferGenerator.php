<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers;

use GuzzleHttp\ClientInterface;
use RuntimeException;
use Tlab\StoryblokTransfers\Client\StoryblokManagementClient;
use Tlab\StoryblokTransfers\Definition\DefinitionFileWriter;
use Tlab\StoryblokTransfers\Schema\ComponentNameFormatter;
use Tlab\StoryblokTransfers\Schema\ComponentSchemaFetcher;
use Tlab\StoryblokTransfers\Schema\FieldTypeMapper;
use Tlab\TransferObjects\DataTransferBuilder;

/**
 * Fetches a space's component schemas and turns them into PHP transfer objects.
 *
 * Storyblok Management API -> JSON definition files -> PHP transfer classes
 */
final readonly class StoryblokTransferGenerator
{
    private ComponentSchemaFetcher $fetcher;

    private FieldTypeMapper $mapper;

    private DefinitionFileWriter $definitionWriter;

    private ComponentNameFormatter $nameFormatter;

    /**
     * @param string $authorizationScheme 'Bearer' for OAuth-issued tokens;
     *                                    personal access tokens go bare.
     * @param string $managementBaseUri The Management API endpoint for the
     *        region the space is in. EU by default; US, CA, AP and CN spaces
     *        each have their own.
     */
    public function __construct(
        private string $spaceId,
        string $token,
        private string $definitionsPath,
        private string $outputPath,
        private string $namespace,
        ?ClientInterface $httpClient = null,
        string $authorizationScheme = '',
        string $managementBaseUri = StoryblokManagementClient::DEFAULT_BASE_URI,
    ) {
        $this->fetcher = new ComponentSchemaFetcher(
            new StoryblokManagementClient(
                $token,
                $httpClient,
                $managementBaseUri,
                $authorizationScheme,
            )
        );
        $this->mapper = new FieldTypeMapper();
        $this->definitionWriter = new DefinitionFileWriter();
        $this->nameFormatter = new ComponentNameFormatter();
    }

    public function generate(): GenerationResult
    {
        $this->ensureDirectory($this->definitionsPath);
        $this->ensureDirectory($this->outputPath);

        $componentNames = [];
        $warnings = [];

        foreach ($this->fetcher->fetch($this->spaceId) as $component) {
            $properties = [];

            foreach ($component['fields'] as $fieldKey => $field) {
                $property = $this->mapper->map($fieldKey, $field);

                if ($property !== null) {
                    $properties[] = $property;

                    continue;
                }

                if (!$this->mapper->isPseudoField($field)) {
                    $warnings[] = $this->describeSkippedField($component['name'], $fieldKey);
                }
            }

            // A transfer with no properties would be dead weight.
            if ($properties === []) {
                continue;
            }

            $this->definitionWriter->write($this->definitionsPath, $component['name'], $properties);
            $componentNames[] = $this->nameFormatter->toTransferName($component['name']);
        }

        if ($componentNames === []) {
            return new GenerationResult([], $warnings);
        }

        (new DataTransferBuilder($this->definitionsPath, $this->outputPath, $this->namespace))->build();

        return new GenerationResult($componentNames, $warnings);
    }

    /**
     * A field key only survives the round trip if AbstractTransfer::fromArray()
     * would rebuild the exact property name we generated, so anything else is
     * dropped rather than silently left empty at runtime.
     */
    private function describeSkippedField(string $componentName, string $fieldKey): string
    {
        return sprintf(
            'Skipped field "%s" of component "%s": the name cannot be expressed as a property that '
            . 'Storyblok payloads would still hydrate (digits and consecutive capitals are not supported).',
            $fieldKey,
            $componentName
        );
    }

    private function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('Could not create directory ' . $path);
        }
    }
}
