<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Hydration;

use Tlab\StoryblokTransfers\Schema\ComponentNameFormatter;
use Tlab\StoryblokTransfers\Schema\PropertyNameNormalizer;
use Tlab\StoryblokTransfers\Transfers\BlokTransfer;
use Tlab\TransferObjects\AbstractTransfer;

/**
 * Turns a Storyblok content array into a populated transfer graph.
 *
 * AbstractTransfer::fromArray() hands raw payload values straight to the setter,
 * so a nested asset arrives as an array where an AssetTransfer is required. This
 * converts those values first and then delegates to fromArray(), which already
 * gets key camel-casing, setter dispatch and unknown-key skipping right.
 */
final class StoryblokHydrator
{
    private readonly PropertyTypeResolver $typeResolver;

    private readonly PropertyNameNormalizer $nameNormalizer;

    private readonly ComponentNameFormatter $componentNameFormatter;

    /**
     * @param string $namespace Namespace the generated transfers live in,
     *                          e.g. 'App\DataTransferObjects'.
     */
    public function __construct(
        private readonly string $namespace,
    ) {
        $this->typeResolver = new PropertyTypeResolver();
        $this->nameNormalizer = new PropertyNameNormalizer();
        $this->componentNameFormatter = new ComponentNameFormatter();
    }

    /**
     * @param class-string $transferClass
     * @param array<string, mixed> $content A Storyblok content array.
     *
     * @throws HydrationException When $transferClass is not a usable transfer.
     */
    public function hydrate(string $transferClass, array $content): AbstractTransfer
    {
        $this->assertHydratable($transferClass);

        $types = $this->typeResolver->resolve($transferClass);
        $converted = [];

        foreach ($content as $key => $value) {
            $propertyName = $this->nameNormalizer->normalize((string) $key);
            $type = $propertyName === null ? null : ($types[$propertyName] ?? null);

            $converted[$key] = $type === null ? $value : $this->convert($type, $value);
        }

        return $transferClass::fromArray($converted);
    }

    private function convert(PropertyType $type, mixed $value): mixed
    {
        $nestedClass = $type->transferClass;

        if ($nestedClass !== null) {
            // A missing asset or link arrives as "" or null, never as an array.
            // Every generated property is nullable, so null always assigns.
            return is_array($value) ? $this->hydrate($nestedClass, $value) : null;
        }

        $elementClass = $type->elementTransferClass;

        if ($elementClass === null || !is_array($value)) {
            return $value;
        }

        return array_map(
            fn (mixed $item): mixed => $this->convertElement($elementClass, $item),
            $value
        );
    }

    /**
     * @param class-string $elementTransferClass
     */
    private function convertElement(string $elementTransferClass, mixed $item): mixed
    {
        if (!is_array($item)) {
            return $item;
        }

        $target = $elementTransferClass === BlokTransfer::class
            ? $this->resolveComponentClass($item)
            : $elementTransferClass;

        // An unresolvable component keeps its raw array: an editor adding a
        // component must not break the page.
        return $target === null ? $item : $this->hydrate($target, $item);
    }

    /**
     * @param array<string, mixed> $blok
     *
     * @return class-string|null
     */
    private function resolveComponentClass(array $blok): ?string
    {
        $component = $blok['component'] ?? null;

        if (!is_string($component) || $component === '') {
            return null;
        }

        $candidate = rtrim($this->namespace, '\\') . '\\'
            . $this->componentNameFormatter->toTransferName($component) . 'Transfer';

        if (!class_exists($candidate) || !is_subclass_of($candidate, AbstractTransfer::class)) {
            return null;
        }

        return $candidate;
    }

    /**
     * @throws HydrationException
     */
    private function assertHydratable(string $transferClass): void
    {
        if (!class_exists($transferClass)) {
            throw new HydrationException('Transfer class does not exist: ' . $transferClass);
        }

        if (!is_subclass_of($transferClass, AbstractTransfer::class)) {
            throw new HydrationException(
                sprintf('%s is not a transfer: it must extend %s', $transferClass, AbstractTransfer::class)
            );
        }
    }
}
