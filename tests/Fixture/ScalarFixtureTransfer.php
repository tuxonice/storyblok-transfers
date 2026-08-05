<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Fixture;

use Tlab\TransferObjects\AbstractTransfer;

/**
 * Mirrors a post-processed `array<mixed>` property - the richtext case. It has
 * no add method because GeneratedCodeFixer removes the broken one.
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
