<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Transfers;

use Tlab\TransferObjects\AbstractTransfer;

/**
 * Storyblok link / multilink field.
 *
 * See AssetTransfer for why every property is nullable with a null default.
 *
 * The payload key `cached_url` maps to $cachedUrl: AbstractTransfer::fromArray()
 * camel-cases incoming keys, so the snake_case key hydrates this property.
 */
class LinkTransfer extends AbstractTransfer
{
    private ?string $id = null;

    private ?string $url = null;

    private ?string $linktype = null;

    private ?string $fieldtype = null;

    private ?string $cachedUrl = null;

    public function getId(): ?string
    {
        return $this->id;
    }

    public function setId(?string $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): self
    {
        $this->url = $url;

        return $this;
    }

    public function getLinktype(): ?string
    {
        return $this->linktype;
    }

    public function setLinktype(?string $linktype): self
    {
        $this->linktype = $linktype;

        return $this;
    }

    public function getFieldtype(): ?string
    {
        return $this->fieldtype;
    }

    public function setFieldtype(?string $fieldtype): self
    {
        $this->fieldtype = $fieldtype;

        return $this;
    }

    public function getCachedUrl(): ?string
    {
        return $this->cachedUrl;
    }

    public function setCachedUrl(?string $cachedUrl): self
    {
        $this->cachedUrl = $cachedUrl;

        return $this;
    }
}
