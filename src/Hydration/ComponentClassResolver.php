<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Hydration;

use Tlab\StoryblokTransfers\Schema\ComponentNameFormatter;
use Tlab\TransferObjects\AbstractTransfer;

/**
 * Maps a Storyblok component name onto the transfer class the generator wrote
 * for it.
 *
 * Extracted from StoryblokHydrator, which needs it for nested bloks, so the
 * content layer can use the same mapping for a story's root component. Both
 * must agree, or one of them looks up classes the other never writes.
 */
final readonly class ComponentClassResolver
{
    private ComponentNameFormatter $nameFormatter;

    /**
     * @param string $namespace Namespace the generated transfers live in,
     *                          e.g. 'App\DataTransferObjects'.
     */
    public function __construct(
        private string $namespace,
    ) {
        $this->nameFormatter = new ComponentNameFormatter();
    }

    /**
     * Without a trailing backslash, so two spellings of one namespace compare
     * equal.
     */
    public function getNamespace(): string
    {
        return rtrim($this->namespace, '\\');
    }

    /**
     * @param array<string, mixed> $content A Storyblok content array.
     *
     * @return class-string<AbstractTransfer>|null
     */
    public function resolveFromContent(array $content): ?string
    {
        $component = $content['component'] ?? null;

        return is_string($component) ? $this->resolve($component) : null;
    }

    /**
     * @return class-string<AbstractTransfer>|null Null when no generated class
     *                                             matches, which is never an
     *                                             error at this level.
     */
    public function resolve(string $componentName): ?string
    {
        if ($componentName === '') {
            return null;
        }

        $candidate = rtrim($this->namespace, '\\') . '\\'
            . $this->nameFormatter->toTransferName($componentName) . 'Transfer';

        if (!class_exists($candidate) || !is_subclass_of($candidate, AbstractTransfer::class)) {
            return null;
        }

        /** @var class-string<AbstractTransfer> $candidate */
        return $candidate;
    }
}
