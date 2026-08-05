<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Client;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Psr\Http\Message\ResponseInterface;

/**
 * Thin wrapper over the Storyblok Management API.
 */
final class StoryblokManagementClient
{
    public const DEFAULT_BASE_URI = 'https://mapi.storyblok.com/v1/';

    private readonly ClientInterface $httpClient;

    /**
     * @param string $token A Storyblok personal access token.
     * @param string $authorizationScheme Prefix for the Authorization header.
     *                                    Personal access tokens are sent bare,
     *                                    which is what the Management API
     *                                    documents; pass 'Bearer' for tokens
     *                                    issued through the OAuth flow.
     */
    public function __construct(
        private readonly string $token,
        ?ClientInterface $httpClient = null,
        private readonly string $baseUri = self::DEFAULT_BASE_URI,
        private readonly string $authorizationScheme = '',
    ) {
        $this->httpClient = $httpClient ?? new Client();
    }

    /**
     * @return list<array<string,mixed>> The raw component definitions.
     *
     * @throws StoryblokApiException
     */
    public function getComponents(string $spaceId): array
    {
        $uri = rtrim($this->baseUri, '/') . '/spaces/' . rawurlencode($spaceId) . '/components/';

        $payload = $this->get($uri);

        if (!isset($payload['components']) || !is_array($payload['components'])) {
            throw new StoryblokApiException(
                'Unexpected Management API response: no "components" key in the payload from ' . $uri
            );
        }

        return array_values($payload['components']);
    }

    /**
     * @return array<string,mixed>
     *
     * @throws StoryblokApiException
     */
    private function get(string $uri): array
    {
        try {
            $response = $this->httpClient->request('GET', $uri, [
                'headers' => [
                    'Authorization' => $this->authorizationHeader(),
                    'Accept' => 'application/json',
                ],
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            throw new StoryblokApiException(
                sprintf('Request to %s failed: %s', $uri, $e->getMessage()),
                0,
                $e
            );
        }

        $this->assertSuccessful($response, $uri);

        try {
            /** @var array<string,mixed> $decoded */
            $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new StoryblokApiException(
                sprintf('Could not decode the JSON response from %s: %s', $uri, $e->getMessage()),
                0,
                $e
            );
        }

        if (!is_array($decoded)) {
            throw new StoryblokApiException('Could not decode the JSON response from ' . $uri);
        }

        return $decoded;
    }

    private function authorizationHeader(): string
    {
        return $this->authorizationScheme === ''
            ? $this->token
            : $this->authorizationScheme . ' ' . $this->token;
    }

    /**
     * @throws StoryblokApiException
     */
    private function assertSuccessful(ResponseInterface $response, string $uri): void
    {
        $status = $response->getStatusCode();

        if ($status >= 200 && $status < 300) {
            return;
        }

        throw new StoryblokApiException(
            sprintf(
                'Storyblok Management API returned HTTP %d for %s: %s',
                $status,
                $uri,
                trim((string) $response->getBody())
            )
        );
    }
}
