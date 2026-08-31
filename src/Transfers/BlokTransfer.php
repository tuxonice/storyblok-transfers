<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Transfers;

use Tlab\TransferObjects\AbstractTransfer;

/**
 * Base type for entries of a Storyblok `bloks` field.
 *
 * Deliberately minimal - just enough to type the nested-blocks array. Callers
 * that need the concrete component read getComponent() and map it themselves.
 *
 * See AssetTransfer for why the property is nullable with a null default.
 */
final class BlokTransfer extends AbstractTransfer
{
    private ?string $component = null;

    public function getComponent(): ?string
    {
        return $this->component;
    }

    public function setComponent(?string $component): self
    {
        $this->component = $component;

        return $this;
    }
}
