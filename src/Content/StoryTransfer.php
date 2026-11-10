<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Content;

use Tlab\TransferObjects\AbstractTransfer;

/**
 * A story: the envelope the CDA wraps around the content, plus the hydrated
 * content itself.
 *
 * Deliberately not an AbstractTransfer. The bundled transfers extend it because
 * generated code references them as property types and because fromArray()
 * hydrates them; this is neither - it is never a property of a generated class,
 * and it is constructed here from a response shape that is fixed and known. So
 * the rule that forces every AbstractTransfer property to be nullable with a
 * default does not apply, and uuid, slug, fullSlug and content can be what the
 * CDA guarantees they are: present.
 *
 * Getters rather than public properties: every other transfer in the package
 * reads that way, and `@return T` on a method is the generic form PHPStan
 * handles most reliably.
 *
 * Covariant in T, which immutability makes sound: a StoryTransfer<PageTransfer>
 * is usable wherever a StoryTransfer<AbstractTransfer> is expected - such as
 * in a RelationMap, which holds related stories of any type.
 *
 * @template-covariant T of AbstractTransfer
 */
final readonly class StoryTransfer
{
    /**
     * @param T $content
     * @param list<string> $tagList
     * @param array<string, mixed> $translatedSlugs
     */
    public function __construct(
        private string $uuid,
        private string $slug,
        private string $fullSlug,
        private AbstractTransfer $content,
        private RelationMap $relations,
        private ?string $name = null,
        private ?string $lang = null,
        private ?string $publishedAt = null,
        private ?string $firstPublishedAt = null,
        private ?string $createdAt = null,
        private ?int $parentId = null,
        private array $tagList = [],
        private array $translatedSlugs = [],
    ) {
    }

    /**
     * @return T
     */
    public function getContent(): AbstractTransfer
    {
        return $this->content;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getFullSlug(): string
    {
        return $this->fullSlug;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getLang(): ?string
    {
        return $this->lang;
    }

    public function getPublishedAt(): ?string
    {
        return $this->publishedAt;
    }

    public function getFirstPublishedAt(): ?string
    {
        return $this->firstPublishedAt;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getParentId(): ?int
    {
        return $this->parentId;
    }

    /**
     * @return list<string>
     */
    public function getTagList(): array
    {
        return $this->tagList;
    }

    /**
     * @return array<string, mixed>
     */
    public function getTranslatedSlugs(): array
    {
        return $this->translatedSlugs;
    }

    /**
     * Shared with every other story from the same response, so this can be
     * compared by identity.
     */
    public function getRelations(): RelationMap
    {
        return $this->relations;
    }

    /**
     * Fed straight from a generated ?string relation property. Returns the
     * related story with its envelope, so getFullSlug() is there to link to
     * it; its content is getContent().
     *
     * @return StoryTransfer<AbstractTransfer>|array<mixed>|null
     */
    public function getRelation(?string $uuid): StoryTransfer|array|null
    {
        return $this->relations->get($uuid);
    }
}
