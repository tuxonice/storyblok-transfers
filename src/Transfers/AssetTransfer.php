<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Transfers;

use Tlab\TransferObjects\AbstractTransfer;

/**
 * Storyblok asset field.
 *
 * Every property is nullable with a null default on purpose: AbstractTransfer
 * only assigns keys present in the payload, and toArray() reads every property
 * by reflection. A defaultless typed property would therefore make toArray()
 * throw whenever Storyblok omits the field.
 */
class AssetTransfer extends AbstractTransfer
{
    private ?int $id = null;

    private ?string $filename = null;

    private ?string $alt = null;

    private ?string $title = null;

    private ?string $copyright = null;

    private ?string $fieldtype = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getFilename(): ?string
    {
        return $this->filename;
    }

    public function setFilename(?string $filename): self
    {
        $this->filename = $filename;

        return $this;
    }

    public function getAlt(): ?string
    {
        return $this->alt;
    }

    public function setAlt(?string $alt): self
    {
        $this->alt = $alt;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getCopyright(): ?string
    {
        return $this->copyright;
    }

    public function setCopyright(?string $copyright): self
    {
        $this->copyright = $copyright;

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
}
