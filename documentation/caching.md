# Caching

The package has no cache of its own. Every read is a request to Storyblok, which
you'll want to avoid on most page views.

Instead, every request goes through one small interface, `ContentClient`, with a
single method. Wrap it with a cache and hand the wrapper to `StoryblokContent`.

```php
use Tlab\StoryblokTransfers\Client\ContentClient;
use Tlab\StoryblokTransfers\Client\ContentResponse;
use Tlab\StoryblokTransfers\Client\StoryblokContentClient;
use Tlab\StoryblokTransfers\Content\StoryblokContent;

final class CachingContentClient implements ContentClient
{
    public function __construct(
        private readonly ContentClient $inner,
        private readonly YourCache $cache,
    ) {
    }

    public function get(string $path, array $query): ContentResponse
    {
        ksort($query);

        $key = 'storyblok-' . hash('xxh3', $path . '?' . http_build_query($query));

        return $this->cache->remember($key, 300, fn () => $this->inner->get($path, $query));
    }
}

$storyblok = StoryblokContent::usingClient(
    new CachingContentClient(
        new StoryblokContentClient(getenv('STORYBLOK_DELIVERY_TOKEN')),
        $cache,
    ),
    'App\\DataTransferObjects',
);
```

`YourCache` stands for whatever your framework provides: a PSR-16 cache, Laravel's
`Cache`, Symfony's cache contracts.

`usingClient()` takes optional `ContentOptions` defaults as its third argument,
like `create()`. For a non-EU space, pass the region's base URI as
`StoryblokContentClient`'s third argument (see
[Configuration](reference/configuration.md#regions)).

## Things to get right

**Sort the query before building the key.** The same request can arrive with
its parameters in a different order, and without sorting you'd store it twice.

**Cache the response, not the hydrated objects.** `ContentResponse` is plain
data. Hydrated transfers are instances of your generated classes, and a cache
holding them keeps the old class shape after you regenerate, which fails in ways
that are hard to trace.

**Leave the token out of the key.** The client adds it after your wrapper has
run, so it isn't in `$query`. Keep it that way.

## Invalidation and `cv`

Deciding when cached content is stale is up to your app. A common approach is a
short lifetime, plus clearing the cache from a Storyblok webhook when content is
published.

Storyblok's own cache version, `cv`, can be passed on every read:

```php
$options = (new ContentOptions())->withCv((string) $cacheVersion);
```

The package never reads or updates `cv` for you. Where the value comes from,
and when it changes, is your invalidation policy.
