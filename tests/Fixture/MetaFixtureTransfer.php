<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Fixture;

use Tlab\TransferObjects\AbstractTransfer;

/**
 * The shape every generated class now has: schema fields, then Storyblok's
 * `_uid` and `_editable` as uid and editable.
 */
class MetaFixtureTransfer extends AbstractTransfer
{
    private ?string $title = null;

    private ?string $uid = null;

    private ?string $editable = null;

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getUid(): ?string
    {
        return $this->uid;
    }

    public function setUid(?string $uid): self
    {
        $this->uid = $uid;

        return $this;
    }

    public function getEditable(): ?string
    {
        return $this->editable;
    }

    public function setEditable(?string $editable): self
    {
        $this->editable = $editable;

        return $this;
    }
}
