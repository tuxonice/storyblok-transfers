<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Fixture;

use Tlab\StoryblokTransfers\Transfers\RichtextTransfer;
use Tlab\TransferObjects\AbstractTransfer;

/**
 * Mirrors what the generator emits for a richtext field: a nested transfer, so
 * fromArray() alone cannot assign the payload.
 */
class RichtextFixtureTransfer extends AbstractTransfer
{
    /**
     * @var RichtextTransfer|null
     */
    private ?RichtextTransfer $body = null;

    public function getBody(): ?RichtextTransfer
    {
        return $this->body;
    }

    public function setBody(?RichtextTransfer $body): self
    {
        $this->body = $body;

        return $this;
    }
}
