<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Fixture;

use Tlab\TransferObjects\AbstractTransfer;

/**
 * Mirrors an `array<mixed>` property - the richtext case. A plain array has no
 * element type, so the generator emits no add method for it.
 */
class ScalarFixtureTransfer extends AbstractTransfer
{
    /**
     * @var array<mixed>|null
     */
    private ?array $content = null;

    /**
     * @return array<mixed>|null
     */
    public function getContent(): ?array
    {
        return $this->content;
    }

    /**
     * @param array<mixed>|null $content
     */
    public function setContent(?array $content): self
    {
        $this->content = $content;

        return $this;
    }
}
