# Caching

Without a cache, every read is a request to Storyblok. Pass any
[PSR-16](https://www.php-fig.org/psr/psr-16/) cache, and published content is
served from it instead:

```php
use Tlab\StoryblokTransfers\Content\StoryblokContent;

$storyblok = StoryblokContent::fromEnvironment($cache);
// or StoryblokContent::create($token, 'App\\DataTransferObjects', cache: $cache);
```

## What it does

- **Published reads are cached for 5 minutes.** Within that time the same read
  (a story, a listing, the links tree, datasource entries) is answered from the
  cache. After that, the next read asks Storyblok again. A change published in
  Storyblok therefore shows up within about 5 minutes.
- **Drafts are never cached.** Previews always go straight to Storyblok, so
  editors see their changes at once.
- **Storyblok being down doesn't take your site down.** If a refresh fails
  (Storyblok unreachable, an error, rate limiting), the last copy is served
  instead, for up to a day. After that, the error goes through.
- **"Not found" is remembered too**, for the same 5 minutes, so requests for
  URLs that don't exist don't reach Storyblok every time. It's never served from
  an old copy: once the 5 minutes are up, Storyblok is asked again.
- **A broken cache is skipped.** If the cache throws, for example because Redis
  is unreachable, reads go to Storyblok as if there were no cache.

Only the plain response from Storyblok is stored, never your hydrated classes,
so regenerating your classes can't leave stale class shapes in the cache.

## With Symfony

Symfony's `Psr16Cache` turns any cache pool into a PSR-16 cache. In a Symfony
app, give Storyblok a pool of its own:

```yaml
# config/packages/cache.yaml
framework:
    cache:
        pools:
            cache.storyblok:
                adapter: cache.adapter.redis   # or cache.adapter.filesystem
```

```yaml
# config/services.yaml
services:
    Tlab\StoryblokTransfers\Content\StoryblokContent:
        factory: ['Tlab\StoryblokTransfers\Content\StoryblokContent', 'fromEnvironment']
        arguments:
            - !service
                class: Symfony\Component\Cache\Psr16Cache
                arguments: ['@cache.storyblok']
```

Outside the framework, `symfony/cache` works on its own:

```php
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;

$cache = new Psr16Cache(new FilesystemAdapter('storyblok', 0, __DIR__ . '/var/cache'));

$storyblok = StoryblokContent::fromEnvironment($cache);
```

Laravel's cache stores implement PSR-16 already, so `Cache::store()` can be
passed as it is.

## Changing the settings

To change the timings, log when old copies are served, or share one cache
between several Storyblok spaces, build the caching client yourself and hand it
to `usingClient()`:

```php
use Tlab\StoryblokTransfers\Client\CachingContentClient;
use Tlab\StoryblokTransfers\Client\StoryblokContentClient;
use Tlab\StoryblokTransfers\Content\StoryblokContent;

$client = new CachingContentClient(
    new StoryblokContentClient(getenv('STORYBLOK_DELIVERY_TOKEN')),
    $cache,
    freshFor: 120,          // seconds a copy is used without asking Storyblok
    keepStaleFor: 3600,     // seconds beyond that it's kept for outages
    keyPrefix: 'storyblok-shop',
    logger: $logger,        // any PSR-3 logger
);

$storyblok = StoryblokContent::usingClient($client, 'App\\DataTransferObjects');
```

| Setting | Default | |
|---|---|---|
| `freshFor` | `300` | `0` always asks Storyblok, but still falls back to the last copy in an outage |
| `keepStaleFor` | `86400` | `0` turns the outage fallback off |
| `keyPrefix` | `storyblok` | Letters, digits, `_`, `.` and `-`. Give each space its own when they share a cache |
| `logger` | none | Gets a warning each time an old copy is served, so outages show up in your logs |
| `clock` | the system clock | Any PSR-20 clock; useful in tests |

`usingClient()` takes optional `ContentOptions` defaults as its third argument,
like `create()`. For a non-EU space, pass the region's base URI as
`StoryblokContentClient`'s third argument (see
[Configuration](reference/configuration.md#regions)).

## Clearing the cache

To show a change immediately rather than within 5 minutes, clear the cache:

```php
$storyblok->clearCache();
```

The next read of anything asks Storyblok again. `clearCache()` returns `false`
if the cache couldn't be cleared (Redis unreachable, say), and the logger, if
you gave one, gets a warning. Without a cache it has nothing to do and returns
`true`.

It clears the whole cache it was given, which is why Storyblok should have a
pool of its own, as in the Symfony example. Several spaces sharing one pool are
cleared together.

### From a Storyblok webhook

In Storyblok, under **Settings → Webhooks**, add a webhook pointing at a route
in your app and tick the story events (published, unpublished, deleted, moved)
and datasource entry events. The route only has to clear the cache and answer
with a 2xx JSON response, which is what Storyblok expects:

```php
// public/storyblok-webhook.php
use Tlab\StoryblokTransfers\Content\StoryblokContent;

$storyblok = StoryblokContent::fromEnvironment($cache);

$cleared = $storyblok->clearCache();

http_response_code($cleared ? 200 : 500);
header('Content-Type: application/json');
echo json_encode(['cleared' => $cleared]);
```

Storyblok doesn't retry a failed webhook, so a cache that couldn't be cleared
catches up on its own once the 5 minutes are up.

The package doesn't check where the request came from. On paid plans Storyblok
signs each webhook with a secret you set, in a `webhook-signature` header (a
hex HMAC-SHA1 of the raw body). Verify that in your route if you want to stop
others from clearing your cache. Anyone who can call the route can only make
your next reads go to Storyblok, never change what they return.

If you passed `usingClient()` a client of your own rather than a
`CachingContentClient`, `clearCache()` can't see inside it. Clear your own
cache directly.

Storyblok's own cache version, `cv`, can also be passed on every read:

```php
$options = (new ContentOptions())->withCv((string) $cacheVersion);
```

A different `cv` is a different read, so it gets its own cache entry. The
package never reads or updates `cv` for you; where the value comes from is up to
your app.

## Writing your own

Every request goes through one small interface, `ContentClient`, with a single
`get()` method. `CachingContentClient` is one implementation of it. To cache
differently, write your own and pass it to `usingClient()`. Keep to the same
rules: sort the query before building a key, store the `ContentResponse` rather
than hydrated objects, and leave the token out of the key. The token is added
after your wrapper has run, so it isn't in `$query`.
