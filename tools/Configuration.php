<?php

declare(strict_types=1);


/**
 * The credentials and space the run works against.
 */
final class Configuration
{
    public function __construct(
        public readonly string $spaceId,
        public readonly string $token,
        public readonly string $authorizationScheme = '',
    ) {
    }

    /**
     * Anything already exported wins over the file, which is the precedence
     * Docker Compose gives it - so a one-off `STORYBLOK_SPACE_ID=x` in front of
     * the command behaves the way it looks like it should.
     *
     * @throws SmokeTestFailure When the required credentials are missing.
     */
    public static function fromEnvironment(string $envFile): self
    {
        $fromFile = DotEnvFile::parse($envFile);

        $value = static function (string $key) use ($fromFile): string {
            $exported = getenv($key);

            if (is_string($exported) && $exported !== '') {
                return $exported;
            }

            return $fromFile[$key] ?? '';
        };

        $spaceId = $value('STORYBLOK_SPACE_ID');
        $token = $value('STORYBLOK_MANAGEMENT_TOKEN');

        if ($spaceId === '' || $token === '') {
            throw new SmokeTestFailure(
                'STORYBLOK_SPACE_ID and STORYBLOK_MANAGEMENT_TOKEN are required.',
                'Copy .env.example to .env and fill them in, or export them in your shell.'
            );
        }

        return new self($spaceId, $token, $value('STORYBLOK_AUTH_SCHEME'));
    }

    /**
     * A personal access token goes in bare, which is what the Management API
     * documents; an OAuth-issued token needs its scheme.
     */
    public function authorizationHeader(): string
    {
        return $this->authorizationScheme === ''
            ? $this->token
            : $this->authorizationScheme . ' ' . $this->token;
    }
}
