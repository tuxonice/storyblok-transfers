<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Content;

use GuzzleHttp\ClientInterface;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
use Tlab\StoryblokTransfers\Client\CachingContentClient;
use Tlab\StoryblokTransfers\Client\ContentClient;
use Tlab\StoryblokTransfers\Client\StoryblokContentClient;
use Tlab\StoryblokTransfers\Hydration\ComponentClassResolver;
use Tlab\StoryblokTransfers\Hydration\StoryblokHydrator;

/**
 * Wiring for the three content repositories, for consumers with no DI container.
 *
 * No logic beyond arrangement. usingClient() is the seam a caching decorator
 * goes through: wrap StoryblokContentClient in your own ContentClient and hand
 * it here.
 *
 * The repositories are built once and reused, because StoryblokHydrator and
 * PropertyTypeResolver cache their reflection work per instance.
 */
final class StoryblokContent
{
    private ?StoryRepository $stories = null;

    private ?LinkRepository $links = null;

    private ?DatasourceRepository $datasources = null;

    private function __construct(
        private readonly ContentClient $client,
        private readonly string $namespace,
        private readonly ContentOptions $defaults,
    ) {
    }

    /**
     * @param string $deliveryToken A Content Delivery API token, not the
     *                              Management API one.
     * @param string $namespace Namespace the generated transfers live in.
     * @param ContentOptions|null $defaults Applied to every read that does not
     *        pass its own options - listings included. Null means the library's
     *        own defaults, which are the published version and no language.
     * @param string|null $baseUri Region endpoint; null for the EU default.
     * @param CacheInterface|null $cache Caches published reads for five
     *        minutes and falls back to the last copy for a day when Storyblok
     *        fails; drafts are never cached. For other settings, a logger or a
     *        key prefix, build a CachingContentClient and use usingClient().
     */
    public static function create(
        string $deliveryToken,
        string $namespace,
        ?ContentOptions $defaults = null,
        ?string $baseUri = null,
        ?ClientInterface $httpClient = null,
        ?CacheInterface $cache = null,
    ): self {
        $client = new StoryblokContentClient(
            $deliveryToken,
            $httpClient,
            $baseUri ?? StoryblokContentClient::DEFAULT_BASE_URI,
        );

        return new self(
            $cache === null ? $client : new CachingContentClient($client, $cache),
            $namespace,
            $defaults ?? new ContentOptions(),
        );
    }

    /**
     * Wrap your own ContentClient - a caching decorator, say - and pass it here.
     */
    public static function usingClient(
        ContentClient $client,
        string $namespace,
        ?ContentOptions $defaults = null,
    ): self {
        return new self($client, $namespace, $defaults ?? new ContentOptions());
    }

    /**
     * Reads STORYBLOK_DELIVERY_TOKEN, STORYBLOK_NAMESPACE,
     * STORYBLOK_CONTENT_BASE_URI and STORYBLOK_DEFAULT_VERSION.
     *
     * This package ships no dotenv reader - getenv() is all it does, the same
     * as bin/generate. Load the file yourself, or let Docker Compose do it.
     *
     * @param CacheInterface|null $cache As for create().
     *
     * @throws RuntimeException When the delivery token is not set, or
     *         STORYBLOK_DEFAULT_VERSION is set to something other than
     *         published or draft (in any case).
     */
    public static function fromEnvironment(?CacheInterface $cache = null): self
    {
        $token = self::env('STORYBLOK_DELIVERY_TOKEN');

        if ($token === null) {
            throw new RuntimeException(
                'STORYBLOK_DELIVERY_TOKEN is not set. It is the Content Delivery API token - preview or '
                . 'public - and not the Management API token used for generation.'
            );
        }

        return self::create(
            $token,
            self::env('STORYBLOK_NAMESPACE') ?? 'App\\DataTransferObjects',
            new ContentOptions(self::versionFromEnvironment()),
            self::env('STORYBLOK_CONTENT_BASE_URI'),
            cache: $cache,
        );
    }

    public function stories(): StoryRepository
    {
        if ($this->stories === null) {
            // The repository and the relation-map factory share one mapper, and
            // through it one hydrator: PropertyTypeResolver caches its
            // reflection work per hydrator instance.
            $resolver = new ComponentClassResolver($this->namespace);
            $hydrator = new StoryblokHydrator($this->namespace, $resolver);

            $mapper = new StoryMapper($resolver, $hydrator);

            $this->stories = new StoryRepository(
                $this->client,
                $mapper,
                new RelationMapFactory($mapper),
                $this->defaults,
            );
        }

        return $this->stories;
    }

    public function links(): LinkRepository
    {
        return $this->links ??= new LinkRepository($this->client, $this->defaults);
    }

    public function datasources(): DatasourceRepository
    {
        return $this->datasources ??= new DatasourceRepository($this->client, $this->defaults);
    }

    /**
     * Empties the cache, so the next read of anything asks Storyblok. Call it
     * from a Storyblok webhook to show published changes at once.
     *
     * With no cache there is nothing to clear, and it returns true. A client
     * of your own passed to usingClient() is not looked into: clear its cache
     * yourself.
     *
     * @return bool False when the cache failed to clear.
     */
    public function clearCache(): bool
    {
        return $this->client instanceof CachingContentClient ? $this->client->clear() : true;
    }

    public function defaults(): ContentOptions
    {
        return $this->defaults;
    }

    /**
     * Unset means published. Set to anything unrecognised throws: falling back
     * would leave a preview environment configured with 'drafts' quietly
     * showing published content, with nothing pointing at the typo. Case and
     * surrounding whitespace are forgiven, since 'Draft' means draft.
     *
     * @throws RuntimeException
     */
    private static function versionFromEnvironment(): Version
    {
        $value = self::env('STORYBLOK_DEFAULT_VERSION');

        if ($value === null) {
            return Version::Published;
        }

        $version = Version::tryFrom(strtolower(trim($value)));

        if ($version === null) {
            throw new RuntimeException(sprintf(
                'STORYBLOK_DEFAULT_VERSION is "%s", which is not a version. Use "%s" or "%s", or leave it unset '
                . 'for published.',
                $value,
                Version::Published->value,
                Version::Draft->value
            ));
        }

        return $version;
    }

    private static function env(string $key): ?string
    {
        $value = getenv($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
