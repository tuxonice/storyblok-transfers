<?php

declare(strict_types=1);


use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Tlab\StoryblokTransfers\Client\StoryblokApiException;

/**
 * Fetches story content from the Management API.
 *
 * The library itself only reads component schemas, so the run makes its own call
 * for content. Same API and same token, which is why it reuses
 * StoryblokApiException rather than inventing a parallel error type.
 */
final class StoryFetcher
{
    public const DEFAULT_BASE_URI = 'https://mapi.storyblok.com/v1/';

    /** Enough to look past a run of folders without paginating. */
    private const LISTING_PAGE_SIZE = 25;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $authorization,
        private readonly string $baseUri = self::DEFAULT_BASE_URI,
    ) {
    }

    /**
     * @param string|null $slugOrId A story id, a slug, or null for the first
     *                              story in the space.
     *
     * @throws StoryblokApiException
     * @throws SmokeTestFailure
     */
    public function fetch(string $spaceId, ?string $slugOrId): Story
    {
        $storiesUri = rtrim($this->baseUri, '/') . '/spaces/' . rawurlencode($spaceId) . '/stories/';
        $id = $slugOrId !== null && ctype_digit($slugOrId)
            ? $slugOrId
            : $this->resolveId($storiesUri, $slugOrId);

        // The listing omits content, so the id it yields is looked up again.
        $payload = $this->get($storiesUri . rawurlencode($id), []);
        $story = $payload['story'] ?? null;

        if (!is_array($story)) {
            throw new StoryblokApiException(sprintf('No "story" key in the response for story %s.', $id));
        }

        /** @var array<string, mixed> $story */
        return Story::fromPayload($story);
    }

    /**
     * @throws StoryblokApiException
     */
    private function resolveId(string $storiesUri, ?string $slug): string
    {
        $query = ['per_page' => self::LISTING_PAGE_SIZE];

        if ($slug !== null) {
            $query['with_slug'] = $slug;
        }

        $payload = $this->get($storiesUri, $query);
        $stories = $payload['stories'] ?? null;

        // Folders carry no content, so they can never be hydrated.
        $candidates = array_values(array_filter(
            is_array($stories) ? $stories : [],
            static fn (mixed $story): bool => is_array($story) && ($story['is_folder'] ?? false) !== true
        ));

        if ($candidates === []) {
            throw new StoryblokApiException(
                $slug === null
                    ? 'The space contains no stories, only folders or nothing at all.'
                    : sprintf('No story found with slug "%s".', $slug)
            );
        }

        $id = $candidates[0]['id'] ?? null;

        if (!is_int($id) && !is_string($id)) {
            throw new StoryblokApiException('The stories listing returned an entry without an id.');
        }

        return (string) $id;
    }

    /**
     * @param array<string, string|int> $query
     *
     * @return array<string, mixed>
     *
     * @throws StoryblokApiException
     */
    private function get(string $uri, array $query): array
    {
        try {
            $response = $this->http->request('GET', $uri, [
                'headers' => [
                    'Authorization' => $this->authorization,
                    'Accept' => 'application/json',
                ],
                'query' => $query,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            throw new StoryblokApiException(sprintf('Request to %s failed: %s', $uri, $e->getMessage()), 0, $e);
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status < 200 || $status >= 300) {
            throw new StoryblokApiException(sprintf('HTTP %d for %s: %s', $status, $uri, trim($body)));
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new StoryblokApiException(sprintf('Could not decode the response from %s.', $uri), 0, $e);
        }

        /** @var array<string, mixed> $decoded */
        return is_array($decoded) ? $decoded : [];
    }
}
