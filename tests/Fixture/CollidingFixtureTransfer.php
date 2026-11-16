<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Fixture;

use Tlab\TransferObjects\AbstractTransfer;

/**
 * A component whose own schema fields are named uid and editable, so the
 * generator left Storyblok's metadata out of it.
 */
class CollidingFixtureTransfer extends AbstractTransfer
{
    private ?string $uid = null;

    private ?string $editable = null;

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
