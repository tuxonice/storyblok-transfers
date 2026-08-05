<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Schema;

use Tlab\StoryblokTransfers\Transfers\AssetTransfer;
use Tlab\StoryblokTransfers\Transfers\BlokTransfer;
use Tlab\StoryblokTransfers\Transfers\LinkTransfer;

/**
 * Maps a Storyblok schema field onto a transfer-objects property definition.
 */
final class FieldTypeMapper
{
    /**
     * Storyblok field types that carry no data - they only group the editor UI.
     */
    private const PSEUDO_FIELD_TYPES = ['tab', 'section'];

    /**
     * Storyblok types that become a plain nullable string.
     */
    private const STRING_TYPES = [
        'text',
        'textarea',
        'markdown',
        'option',
        'uid',
        'datetime',
        // Relations resolve to a UUID only; resolution is the consumer's job.
        'story',
    ];

    public function __construct(
        private readonly PropertyNameNormalizer $nameNormalizer = new PropertyNameNormalizer(),
    ) {
    }

    /**
     * Whether the field only groups the editor UI and carries no data, in which
     * case leaving it out of the generated class is expected rather than a loss.
     *
     * @param array<string,mixed> $field
     */
    public function isPseudoField(array $field): bool
    {
        return in_array($field['type'] ?? null, self::PSEUDO_FIELD_TYPES, true);
    }

    /**
     * @param array<string,mixed> $field The Storyblok field definition.
     *
     * @return array<string,mixed>|null Null when the field cannot be represented.
     */
    public function map(string $fieldKey, array $field): ?array
    {
        $storyblokType = is_string($field['type'] ?? null) ? $field['type'] : '';

        if ($this->isPseudoField($field)) {
            return null;
        }

        $name = $this->nameNormalizer->normalize($fieldKey);

        if ($name === null) {
            return null;
        }

        return match ($storyblokType) {
            'number' => $this->scalar($name, 'float'),
            'boolean' => $this->scalar($name, 'bool'),
            'bloks' => $this->transferList($name, 'BlokTransfer', BlokTransfer::class),
            'multiasset' => $this->transferList($name, 'AssetTransfer', AssetTransfer::class),
            'asset' => $this->transfer($name, 'AssetTransfer', AssetTransfer::class),
            'link', 'multilink' => $this->transfer($name, 'LinkTransfer', LinkTransfer::class),
            'options' => $this->scalarList($name, 'string'),
            default => in_array($storyblokType, self::STRING_TYPES, true)
                ? $this->scalar($name, 'string')
                // richtext, table, custom plugins and anything unknown.
                : $this->scalar($name, 'array'),
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function scalar(string $name, string $type): array
    {
        return ['name' => $name, 'type' => $type, 'nullable' => true];
    }

    /**
     * Array types must never be nullable - transfer-objects rejects that
     * combination outright.
     *
     * @return array<string,mixed>
     */
    private function scalarList(string $name, string $type): array
    {
        return [
            'name' => $name,
            'type' => $type . '[]',
            'singular' => $this->singularize($name),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function transfer(string $name, string $type, string $fqcn): array
    {
        return [
            'name' => $name,
            'type' => $type,
            'nullable' => true,
            'namespace' => $fqcn,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function transferList(string $name, string $type, string $fqcn): array
    {
        return [
            'name' => $name,
            'type' => $type . '[]',
            'singular' => $this->singularize($name),
            'namespace' => $fqcn,
        ];
    }

    /**
     * The singular names the "add" method on array properties, so it has to be
     * derived from the property name - a per-type constant would collide as soon
     * as one component held two fields of the same type.
     *
     * The result must also match the schema's singular pattern, which only
     * allows a trailing capital followed by lowercase letters.
     */
    private function singularize(string $name): string
    {
        if (str_ends_with($name, 'ies') && strlen($name) > 3) {
            return substr($name, 0, -3) . 'y';
        }

        if (str_ends_with($name, 'ses') || str_ends_with($name, 'xes')) {
            return substr($name, 0, -2);
        }

        if (str_ends_with($name, 's') && !str_ends_with($name, 'ss')) {
            return substr($name, 0, -1);
        }

        return $name . 'Item';
    }
}
