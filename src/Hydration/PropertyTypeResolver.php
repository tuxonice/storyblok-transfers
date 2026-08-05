<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Hydration;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use Tlab\TransferObjects\TransferInterface;

/**
 * Works out which properties of a transfer class hold nested transfers.
 *
 * Single nested transfers come straight from the property's declared type.
 * Array element types do not: the declared type is just `array`, and the
 * element only appears as a short name in the `@var array<Short>` docblock.
 * Reflection cannot read `use` statements, so rather than reimplement PHP's
 * name resolution we look for a method parameter whose type has the same short
 * name - the generated `add{Singular}()` method - and take its FQCN, which PHP
 * resolved at compile time.
 */
final class PropertyTypeResolver
{
    /** @var array<class-string, array<string, PropertyType>> */
    private array $cache = [];

    /**
     * @param class-string $transferClass
     *
     * @return array<string, PropertyType> Keyed by property name; properties
     *                                     needing no conversion are omitted.
     */
    public function resolve(string $transferClass): array
    {
        if (isset($this->cache[$transferClass])) {
            return $this->cache[$transferClass];
        }

        $reflection = new ReflectionClass($transferClass);
        $shortNameToFqcn = $this->classNamesFromMethodParameters($reflection);

        $map = [];

        foreach ($reflection->getProperties(ReflectionProperty::IS_PRIVATE) as $property) {
            $type = $this->propertyType($property, $shortNameToFqcn);

            if ($type->needsConversion()) {
                $map[$property->getName()] = $type;
            }
        }

        return $this->cache[$transferClass] = $map;
    }

    /**
     * @param ReflectionClass<object> $reflection
     *
     * @return array<string, class-string> Short class name => FQCN.
     */
    private function classNamesFromMethodParameters(ReflectionClass $reflection): array
    {
        $names = [];

        foreach ($reflection->getMethods() as $method) {
            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();

                if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                    continue;
                }

                /** @var class-string $fqcn */
                $fqcn = $type->getName();
                $shortName = substr((string) strrchr('\\' . $fqcn, '\\'), 1);
                $names[$shortName] = $fqcn;
            }
        }

        return $names;
    }

    /**
     * @param array<string, class-string> $shortNameToFqcn
     */
    private function propertyType(ReflectionProperty $property, array $shortNameToFqcn): PropertyType
    {
        $type = $property->getType();

        if (!$type instanceof ReflectionNamedType) {
            return new PropertyType();
        }

        if (!$type->isBuiltin()) {
            /** @var class-string $fqcn */
            $fqcn = $type->getName();

            return is_a($fqcn, TransferInterface::class, true)
                ? new PropertyType(transferClass: $fqcn)
                : new PropertyType();
        }

        if ($type->getName() !== 'array') {
            return new PropertyType();
        }

        $elementClass = $this->elementClass($property, $shortNameToFqcn);

        return $elementClass === null
            ? new PropertyType()
            : new PropertyType(elementTransferClass: $elementClass);
    }

    /**
     * @param array<string, class-string> $shortNameToFqcn
     *
     * @return class-string|null
     */
    private function elementClass(ReflectionProperty $property, array $shortNameToFqcn): ?string
    {
        $docComment = $property->getDocComment();

        if ($docComment === false) {
            return null;
        }

        if (preg_match('/@var\s+array<\s*([A-Za-z_][A-Za-z0-9_]*)\s*>/', $docComment, $matches) !== 1) {
            return null;
        }

        $fqcn = $shortNameToFqcn[$matches[1]] ?? null;

        if ($fqcn === null || !is_a($fqcn, TransferInterface::class, true)) {
            return null;
        }

        return $fqcn;
    }
}
