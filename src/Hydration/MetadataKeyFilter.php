<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Hydration;

use ReflectionClass;
use ReflectionNamedType;
use Tlab\StoryblokTransfers\Schema\PropertyNameNormalizer;

/**
 * Keeps Storyblok's `_uid` and `_editable` away from a schema field of the same
 * name.
 *
 * AbstractTransfer::fromArray() maps `_uid` to the property `uid`, which is
 * where the generator puts the metadata - unless the component has a field of
 * its own named uid, in which case the field owns the property and the
 * generator leaves the metadata out. The two cases look alike by name, so this
 * drops the underscored key when either of these says the property is a field:
 *
 * - another key in the payload maps to the same property, however it is
 *   spelled (`uid`, `uid_`, `Uid`). Otherwise the payload's key order would
 *   decide which value fromArray() assigns last.
 * - the property is not a nullable string, the only type the generator gives
 *   the metadata. A number or boolean field would otherwise receive the
 *   metadata string - a TypeError, or a silent true.
 *
 * What neither can catch is a nullable string field that is absent from the
 * payload, as a field added after the content was last saved is: it receives
 * the metadata. That is documented in limitations.md.
 */
final class MetadataKeyFilter
{
    /**
     * The property names fromArray() maps `_uid` and `_editable` onto.
     */
    private const METADATA_PROPERTIES = ['uid', 'editable'];

    /**
     * Per transfer class: the metadata property names whose property is not a
     * nullable string. Reflection is done once per class.
     *
     * @var array<class-string, list<string>>
     */
    private array $fieldTyped = [];

    public function __construct(
        private readonly PropertyNameNormalizer $nameNormalizer = new PropertyNameNormalizer(),
    ) {
    }

    /**
     * @param class-string $transferClass
     * @param array<array-key, mixed> $content
     *
     * @return array<array-key, mixed>
     */
    public function filter(string $transferClass, array $content): array
    {
        $fieldTyped = $this->fieldTypedProperties($transferClass);

        foreach (self::METADATA_PROPERTIES as $property) {
            $metadataKey = '_' . $property;

            if (!array_key_exists($metadataKey, $content)) {
                continue;
            }

            if (in_array($property, $fieldTyped, true) || $this->hasFieldKey($content, $metadataKey, $property)) {
                unset($content[$metadataKey]);
            }
        }

        return $content;
    }

    /**
     * @param array<array-key, mixed> $content
     */
    private function hasFieldKey(array $content, string $metadataKey, string $property): bool
    {
        foreach (array_keys($content) as $key) {
            if ($key !== $metadataKey && $this->nameNormalizer->normalize((string) $key) === $property) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param class-string $transferClass
     *
     * @return list<string>
     */
    private function fieldTypedProperties(string $transferClass): array
    {
        if (isset($this->fieldTyped[$transferClass])) {
            return $this->fieldTyped[$transferClass];
        }

        $reflection = new ReflectionClass($transferClass);
        $names = [];

        foreach (self::METADATA_PROPERTIES as $property) {
            if (!$reflection->hasProperty($property)) {
                continue;
            }

            $type = $reflection->getProperty($property)->getType();

            if (!$type instanceof ReflectionNamedType || $type->getName() !== 'string' || !$type->allowsNull()) {
                $names[] = $property;
            }
        }

        return $this->fieldTyped[$transferClass] = $names;
    }
}
