<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Fixture;

use Tlab\TransferObjects\AbstractTransfer;

/**
 * A component whose own uid field is a number and editable field a boolean,
 * so Storyblok's string metadata must never reach those setters.
 */
class TypedCollidingFixtureTransfer extends AbstractTransfer
{
    private ?float $uid = null;

    private ?bool $editable = null;

    public function getUid(): ?float
    {
        return $this->uid;
    }

    public function setUid(?float $uid): self
    {
        $this->uid = $uid;

        return $this;
    }

    public function getEditable(): ?bool
    {
        return $this->editable;
    }

    public function setEditable(?bool $editable): self
    {
        $this->editable = $editable;

        return $this;
    }
}
