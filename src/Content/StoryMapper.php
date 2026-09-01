<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Content;

use Tlab\StoryblokTransfers\Client\StoryblokApiException;
use Tlab\StoryblokTransfers\Hydration\ComponentClassResolver;
use Tlab\StoryblokTransfers\Hydration\StoryblokHydrator;
use Tlab\TransferObjects\AbstractTransfer;

/**
 * Turns a raw CDA story object into an envelope with hydrated content.
 *
 * Separate from StoryRepository because both the single-story and the listing
 * path need the same mapping, and neither should have to know how a response is
 * unwrapped to get it. The RelationMap arrives ready-made: it belongs to the
 * response, not to any one story in it.
 */
final class StoryMapper
{
    public function __construct(
        private readonly ComponentClassResolver $resolver,
        private readonly StoryblokHydrator $hydrator,
    ) {
    }

    /**
     * @param array<string, mixed> $story The "story" object from the response.
     * @param RelationMap $relations Built once per response by the caller, and
     *        shared by every story in it.
     * @param class-string<AbstractTransfer>|null $expected Asserted against the
     *        class the component resolves to, when given.
     *
     * @return StoryTransfer<AbstractTransfer>
     *
     * @throws UnresolvableComponentException
     * @throws UnexpectedComponentException
     * @throws StoryblokApiException When the payload is not a story.
     */
    public function mapOne(array $story, RelationMap $relations, ?string $expected = null): StoryTransfer
    {
        return $this->envelope($story, $this->hydrateContent($this->contentOf($story), $expected), $relations);
    }

    /**
     * Every story on the page gets the same RelationMap instance, which is why
     * this is a plain loop: the relations arrive already resolved in the
     * response's `rels` array, so there is nothing to collect across stories
     * first. The shared map is the caller's, not built here.
     *
     * @param list<array<string, mixed>> $stories
     * @param RelationMap $relations Built once from the response root.
     * @param class-string<AbstractTransfer>|null $expected
     *
     * @return StoryList<AbstractTransfer>
     *
     * @throws UnresolvableComponentException
     * @throws UnexpectedComponentException
     * @throws StoryblokApiException
     */
    public function mapList(
        array $stories,
        RelationMap $relations,
        int $total,
        int $page,
        int $perPage,
        ?string $expected = null
    ): StoryList {
        $envelopes = [];

        foreach ($stories as $story) {
            $envelopes[] = $this->mapOne($story, $relations, $expected);
        }

        return new StoryList($envelopes, $total, $page, $perPage, $relations);
    }

    /**
     * A payload with no content object is not a story, which is the same thing
     * a missing uuid means - so it gets the same exception. It is emphatically
     * not "the root component has no generated class", which is what
     * UnresolvableComponentException means everywhere else.
     *
     * @param array<string, mixed> $story
     *
     * @return array<string, mixed>
     *
     * @throws StoryblokApiException
     */
    private function contentOf(array $story): array
    {
        $content = $story['content'] ?? null;

        if (!is_array($content)) {
            throw new StoryblokApiException(
                'The Storyblok response contains a story with no content object, so it is not a story.'
            );
        }

        /** @var array<string, mixed> $content */
        return $content;
    }

    /**
     * @param array<string, mixed> $content
     * @param class-string<AbstractTransfer>|null $expected
     *
     * @throws UnresolvableComponentException
     * @throws UnexpectedComponentException
     */
    private function hydrateContent(array $content, ?string $expected = null): AbstractTransfer
    {
        return $this->hydrator->hydrate($this->targetClass($content, $expected), $content);
    }

    /**
     * @param array<string, mixed> $story
     *
     * @return StoryTransfer<AbstractTransfer>
     *
     * @throws StoryblokApiException
     */
    private function envelope(array $story, AbstractTransfer $content, RelationMap $relations): StoryTransfer
    {
        return new StoryTransfer(
            $this->required($story, 'uuid'),
            $this->required($story, 'slug'),
            $this->required($story, 'full_slug'),
            $content,
            $relations,
            $this->optional($story, 'name'),
            $this->optional($story, 'lang'),
            $this->optional($story, 'published_at'),
            $this->optional($story, 'first_published_at'),
            $this->optional($story, 'created_at'),
            is_int($story['parent_id'] ?? null) ? $story['parent_id'] : null,
            $this->stringList($story, 'tag_list'),
            is_array($story['translated_slugs'] ?? null) ? $story['translated_slugs'] : [],
        );
    }

    /**
     * @param array<string, mixed> $content
     * @param class-string<AbstractTransfer>|null $expected
     *
     * @return class-string<AbstractTransfer>
     *
     * @throws UnresolvableComponentException
     * @throws UnexpectedComponentException
     */
    private function targetClass(array $content, ?string $expected): string
    {
        $component = is_string($content['component'] ?? null) ? $content['component'] : '';
        $resolved = $this->resolver->resolveFromContent($content);

        if ($resolved === null) {
            throw new UnresolvableComponentException(sprintf(
                'No generated transfer class for the root component "%s". Regenerate, or check that the '
                . 'configured namespace matches where the classes were written.',
                $component
            ));
        }

        if ($expected !== null && $resolved !== $expected) {
            throw new UnexpectedComponentException(sprintf(
                'Expected %s, but the story\'s component "%s" resolves to %s.',
                $expected,
                $component,
                $resolved
            ));
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed> $story
     *
     * @throws StoryblokApiException
     */
    private function required(array $story, string $key): string
    {
        $value = $story[$key] ?? null;

        if (!is_string($value) || $value === '') {
            throw new StoryblokApiException(sprintf(
                'The Storyblok response contains a story with no usable "%s", so it is not a story.',
                $key
            ));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $story
     */
    private function optional(array $story, string $key): ?string
    {
        $value = $story[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $story
     *
     * @return list<string>
     */
    private function stringList(array $story, string $key): array
    {
        $value = $story[$key] ?? null;

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }
}
