<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Content;

use GuzzleHttp\ClientInterface;
use RuntimeException;
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
     */
    public static function create(
        string $deliveryToken,
        string $namespace,
        ?ContentOptions $defaults = null,
        ?string $baseUri = null,
        ?ClientInterface $httpClient = null,
    ): self {
        return new self(
            new StoryblokContentClient(
                $deliveryToken,
                $httpClient,
                $baseUri ?? StoryblokContentClient::DEFAULT_BASE_URI,
            ),
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
     * @throws RuntimeException When the delivery token is not set.
     */
    public static function fromEnvironment(): self
    {
        $token = self::env('STORYBLOK_DELIVERY_TOKEN');

        if ($token === null) {
            throw new RuntimeException(
                'STORYBLOK_DELIVERY_TOKEN is not set. It is the Content Delivery API token - preview or '
                . 'public - and not the Management API token used for generation.'
            );
        }

        $version = Version::tryFrom(self::env('STORYBLOK_DEFAULT_VERSION') ?? '') ?? Version::Published;

        return self::create(
            $token,
            self::env('STORYBLOK_NAMESPACE') ?? 'App\\DataTransferObjects',
            new ContentOptions($version),
            self::env('STORYBLOK_CONTENT_BASE_URI'),
        );
    }

    public function stories(): StoryRepository
    {
        if ($this->stories === null) {
            // The mapper and the relation-map factory need the same resolver and
            // hydrator, and PropertyTypeResolver caches its reflection work per
            // hydrator instance - so build each once and share them.
            $resolver = new ComponentClassResolver($this->namespace);
            $hydrator = new StoryblokHydrator($this->namespace, $resolver);

            $this->stories = new StoryRepository(
                $this->client,
                new StoryMapper($resolver, $hydrator),
                new RelationMapFactory($resolver, $hydrator),
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

    public function defaults(): ContentOptions
    {
        return $this->defaults;
    }

    private static function env(string $key): ?string
    {
        $value = getenv($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
