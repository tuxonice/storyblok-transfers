<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Schema;

/**
 * Turns a raw Storyblok field key into a property name the transfer-objects
 * schema accepts.
 *
 * The derivation deliberately mirrors AbstractTransfer::fromArray() exactly.
 * That method rebuilds the property name from the payload key, so a property
 * named anything else would validate but never hydrate. When the derived name
 * is not schema-valid we therefore return null rather than inventing a
 * substitute that would silently stay empty.
 */
final class PropertyNameNormalizer
{
    /**
     * The pattern the transfer-objects JSON schema enforces for property names.
     * Anchored, so digits and consecutive capitals are both rejected.
     */
    private const SCHEMA_PATTERN = '/^([a-z])+([A-Z][a-z]+)*$/';

    public function normalize(string $fieldKey): ?string
    {
        $camelCase = $this->deriveLikeFromArray($fieldKey);

        if (preg_match(self::SCHEMA_PATTERN, $camelCase) !== 1) {
            return null;
        }

        return $camelCase;
    }

    private function deriveLikeFromArray(string $key): string
    {
        return lcfirst(
            str_replace(
                ' ',
                '',
                ucwords(
                    str_replace('_', ' ', $key)
                )
            )
        );
    }
}
