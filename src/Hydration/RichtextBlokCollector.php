<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Hydration;

/**
 * Finds the components embedded in a richtext tree.
 *
 * Storyblok wraps an inserted component in a node of its own:
 *
 *     {"type": "blok", "attrs": {"id": "...", "body": [{"component": "hero", ...}]}}
 *
 * `attrs.body` holds ordinary component objects, so each hydrates exactly like
 * an entry of a bloks field. Storyblok's own renderers decline to render these -
 * the official package maps the blok node to null and asks the consumer for a
 * resolver - which is why finding them is left to a library like this one.
 *
 * They are returned keyed by the node's `attrs.id`, the key a renderer holds
 * when it meets the node, rather than written back into the tree: the tree has
 * to stay plain arrays for a renderer to be able to walk it at all.
 */
final class RichtextBlokCollector
{
    /**
     * @param array<mixed> $content A document's node list.
     * @param callable(array<string, mixed>): mixed $hydrate Receives one
     *        component array; returns its transfer, or the array unchanged when
     *        its component has no generated class.
     *
     * @return array<array-key, list<mixed>>
     */
    public function collect(array $content, callable $hydrate): array
    {
        $bloks = [];

        foreach ($content as $node) {
            if (!is_array($node)) {
                continue;
            }

            if (($node['type'] ?? null) === 'blok') {
                $this->collectNode($node, $hydrate, $bloks);

                continue;
            }

            $children = $node['content'] ?? null;

            if (!is_array($children)) {
                continue;
            }

            foreach ($this->collect($children, $hydrate) as $id => $components) {
                $bloks[$id] = array_merge($bloks[$id] ?? [], $components);
            }
        }

        return $bloks;
    }

    /**
     * @param array<mixed> $node
     * @param callable(array<string, mixed>): mixed $hydrate
     * @param array<array-key, list<mixed>> $bloks
     */
    private function collectNode(array $node, callable $hydrate, array &$bloks): void
    {
        $attrs = $node['attrs'] ?? null;

        if (!is_array($attrs)) {
            return;
        }

        $id = $attrs['id'] ?? null;
        $body = $attrs['body'] ?? null;

        // Without an id there is no key to look these up by, and a positional
        // key of our own invention would be worse than the honest omission.
        if (!is_string($id) || $id === '' || !is_array($body)) {
            return;
        }

        foreach ($body as $component) {
            if (is_array($component)) {
                $bloks[$id][] = $hydrate($component);
            }
        }
    }
}
