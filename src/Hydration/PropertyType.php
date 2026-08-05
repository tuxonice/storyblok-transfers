<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Hydration;

/**
 * What a single transfer property needs doing to it before assignment.
 */
final class PropertyType
{
    /**
     * @param class-string|null $transferClass Set when the property holds one nested transfer.
     * @param class-string|null $elementTransferClass Set when the property is an array of transfers.
     */
    public function __construct(
        public readonly ?string $transferClass = null,
        public readonly ?string $elementTransferClass = null,
    ) {
    }

    public function needsConversion(): bool
    {
        return $this->transferClass !== null || $this->elementTransferClass !== null;
    }
}
