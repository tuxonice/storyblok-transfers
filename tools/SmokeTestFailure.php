<?php

declare(strict_types=1);


/**
 * A check that did not pass.
 *
 * Carries the lines printed underneath the message, so whichever check failed
 * decides what context is worth showing rather than leaving the entrypoint to
 * guess at it.
 */
final class SmokeTestFailure extends RuntimeException
{
    /** @var list<string> */
    private readonly array $details;

    public function __construct(string $message, string ...$details)
    {
        parent::__construct($message);

        $this->details = array_values($details);
    }

    /**
     * @return list<string>
     */
    public function details(): array
    {
        return $this->details;
    }
}
