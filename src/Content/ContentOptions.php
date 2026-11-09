<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Content;

/**
 * The parameters every CDA resource accepts.
 *
 * These are not listing concerns - a links tree is also fetched per version and
 * per language - so they live here rather than inside StoryQuery, which
 * composes this.
 *
 * Immutable through withers rather than `readonly class`, which is PHP 8.2 while
 * this library supports 8.1.
 */
final class ContentOptions
{
    /**
     * @param list<string> $resolveRelations Fields to resolve, in Storyblok's
     *                                       'component.field' form.
     * @param string|null $cv Cache version. Set by the caller; this library
     *                        neither tracks nor refreshes it, because deciding
     *                        when it changes is the invalidation policy.
     */
    public function __construct(
        public readonly Version $version = Version::Published,
        public readonly ?string $language = null,
        public readonly array $resolveRelations = [],
        public readonly ?string $cv = null,
    ) {
    }

    public function withVersion(Version $version): self
    {
        return new self($version, $this->language, $this->resolveRelations, $this->cv);
    }

    public function withLanguage(?string $language): self
    {
        return new self($this->version, $language, $this->resolveRelations, $this->cv);
    }

    /**
     * @param list<string> $resolveRelations
     */
    public function withResolveRelations(array $resolveRelations): self
    {
        return new self($this->version, $this->language, $resolveRelations, $this->cv);
    }

    public function withCv(?string $cv): self
    {
        return new self($this->version, $this->language, $this->resolveRelations, $cv);
    }

    /**
     * Query parameters, without the token - the client adds that.
     *
     * Only set values are emitted: an absent parameter and an empty one are not
     * the same thing to the CDA.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        $query = ['version' => $this->version->value];

        if ($this->language !== null) {
            $query['language'] = $this->language;
        }

        if ($this->resolveRelations !== []) {
            $query['resolve_relations'] = implode(',', $this->resolveRelations);
        }

        if ($this->cv !== null) {
            $query['cv'] = $this->cv;
        }

        return $query;
    }
}
