<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Content;

/**
 * One key/value pair of a Storyblok datasource.
 *
 * $dimensionValue holds the translation for the requested dimension, and is
 * null when no dimension was asked for or none is set.
 */
final class DatasourceEntry
{
    public function __construct(
        private readonly string $name,
        private readonly string $value,
        private readonly ?int $id = null,
        private readonly ?string $dimensionValue = null,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return self|null Null when the payload carries no name to key it by.
     */
    public static function fromPayload(array $payload): ?self
    {
        $name = $payload['name'] ?? null;

        if (!is_string($name) || $name === '') {
            return null;
        }

        return new self(
            $name,
            is_string($payload['value'] ?? null) ? $payload['value'] : '',
            is_int($payload['id'] ?? null) ? $payload['id'] : null,
            is_string($payload['dimension_value'] ?? null) ? $payload['dimension_value'] : null,
        );
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDimensionValue(): ?string
    {
        return $this->dimensionValue;
    }
}
