<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Fixture;

use Tlab\StoryblokTransfers\Transfers\AssetTransfer;
use Tlab\StoryblokTransfers\Transfers\BlokTransfer;
use Tlab\TransferObjects\AbstractTransfer;

/**
 * Mirrors the shape the generator emits: a nested transfer, a transfer array
 * with its add method, a scalar array, and a plain scalar.
 */
class NestedFixtureTransfer extends AbstractTransfer
{
    /**
     * @var AssetTransfer|null
     */
    private ?AssetTransfer $image = null;

    /**
     * @var array<BlokTransfer>
     */
    private array $body = [];

    /**
     * @var array<string>
     */
    private array $tags = [];

    /**
     * @var string|null
     */
    private ?string $headline = null;

    public function getImage(): ?AssetTransfer
    {
        return $this->image;
    }

    public function setImage(?AssetTransfer $image): self
    {
        $this->image = $image;

        return $this;
    }

    /**
     * @return array<BlokTransfer>
     */
    public function getBody(): array
    {
        return $this->body;
    }

    /**
     * @param array<BlokTransfer> $body
     */
    public function setBody(array $body): self
    {
        $this->body = $body;

        return $this;
    }

    public function addBodyItem(BlokTransfer $bodyItem): self
    {
        $this->body[] = $bodyItem;

        return $this;
    }

    /**
     * @return array<string>
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * @param array<string> $tags
     */
    public function setTags(array $tags): self
    {
        $this->tags = $tags;

        return $this;
    }

    public function addTag(string $tag): self
    {
        $this->tags[] = $tag;

        return $this;
    }

    public function getHeadline(): ?string
    {
        return $this->headline;
    }

    public function setHeadline(?string $headline): self
    {
        $this->headline = $headline;

        return $this;
    }
}
