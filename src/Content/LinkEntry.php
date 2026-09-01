<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Content;

/**
 * One entry of the links tree - navigation and sitemap structure without
 * fetching any content.
 *
 * Its own type rather than a reuse of LinkTransfer: that models a link *field*
 * inside a component, which is a different payload with different keys.
 */
final class LinkEntry
{
    public function __construct(
        private readonly string $uuid,
        private readonly string $slug,
        private readonly string $name,
        private readonly bool $isFolder = false,
        private readonly ?int $id = null,
        private readonly ?int $parentId = null,
        private readonly bool $published = false,
        private readonly int $position = 0,
        private readonly ?string $realPath = null,
        private readonly bool $isStartpage = false,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return self|null Null when the payload is not a usable link, which the
     *                   repository skips rather than failing the whole tree on.
     */
    public static function fromPayload(array $payload): ?self
    {
        $uuid = $payload['uuid'] ?? null;

        if (!is_string($uuid) || $uuid === '') {
            return null;
        }

        return new self(
            $uuid,
            is_string($payload['slug'] ?? null) ? $payload['slug'] : '',
            is_string($payload['name'] ?? null) ? $payload['name'] : '',
            ($payload['is_folder'] ?? false) === true,
            is_int($payload['id'] ?? null) ? $payload['id'] : null,
            is_int($payload['parent_id'] ?? null) ? $payload['parent_id'] : null,
            ($payload['published'] ?? false) === true,
            is_int($payload['position'] ?? null) ? $payload['position'] : 0,
            is_string($payload['real_path'] ?? null) ? $payload['real_path'] : null,
            ($payload['is_startpage'] ?? false) === true,
        );
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getParentId(): ?int
    {
        return $this->parentId;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getRealPath(): ?string
    {
        return $this->realPath;
    }

    public function isFolder(): bool
    {
        return $this->isFolder;
    }

    public function isPublished(): bool
    {
        return $this->published;
    }

    public function isStartpage(): bool
    {
        return $this->isStartpage;
    }
}
