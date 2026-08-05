<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Schema;

use Tlab\StoryblokTransfers\Client\StoryblokApiException;
use Tlab\StoryblokTransfers\Client\StoryblokManagementClient;

/**
 * Reduces the Management API component payload to the parts the generator needs.
 *
 * Field order is whatever the API returned, which keeps the generated
 * definition files stable and diffable between runs.
 */
final class ComponentSchemaFetcher
{
    public function __construct(
        private readonly StoryblokManagementClient $client,
    ) {
    }

    /**
     * @return list<array{name: string, fields: array<string,array<string,mixed>>}>
     *
     * @throws StoryblokApiException
     */
    public function fetch(string $spaceId): array
    {
        $components = [];

        foreach ($this->client->getComponents($spaceId) as $component) {
            $name = $component['name'] ?? null;

            if (!is_string($name) || $name === '') {
                continue;
            }

            $schema = $component['schema'] ?? [];

            $components[] = [
                'name' => $name,
                'fields' => is_array($schema) ? $this->normalizeFields($schema) : [],
            ];
        }

        return $components;
    }

    /**
     * @param array<mixed> $schema
     *
     * @return array<string,array<string,mixed>>
     */
    private function normalizeFields(array $schema): array
    {
        $fields = [];

        foreach ($schema as $fieldKey => $field) {
            if (!is_string($fieldKey) || !is_array($field)) {
                continue;
            }

            $fields[$fieldKey] = $field;
        }

        return $fields;
    }
}
