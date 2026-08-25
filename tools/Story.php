<?php

declare(strict_types=1);


/**
 * One story from the Management API, reduced to the parts the run uses.
 *
 * The payload guards live in fromPayload() so the rest of the code can treat
 * these as the strings they are.
 */
final class Story
{
    /**
     * @param array<string, mixed> $content
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly array $content,
    ) {
    }

    /**
     * @param array<string, mixed> $payload The "story" object of the response.
     *
     * @throws SmokeTestFailure When the story carries no content to hydrate.
     */
    public static function fromPayload(array $payload): self
    {
        $id = self::text($payload, 'id');
        $content = $payload['content'] ?? null;

        if (!is_array($content)) {
            throw new SmokeTestFailure(sprintf('Story %s has no content object.', $id));
        }

        /** @var array<string, mixed> $content */
        return new self(
            $id,
            self::text($payload, 'name'),
            self::text($payload, 'full_slug'),
            $content,
        );
    }

    /**
     * The component the content is an instance of, which is what decides the
     * transfer class to hydrate into.
     */
    public function component(): ?string
    {
        $component = $this->content['component'] ?? null;

        return is_string($component) && $component !== '' ? $component : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function text(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return is_int($value) ? (string) $value : '?';
    }
}
