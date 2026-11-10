# Using it with Laravel

This page sets the package up in a Laravel app: a config file, a shared
service, an Artisan command to generate classes, a small blog, and a webhook
that clears the cache when content is published. It was written against
Laravel 13. Everything except the webhook's CSRF line works the same on 11
and 12.

```bash
composer require tuxonice/storyblok-transfers
```

## Configuration

Put your tokens in `.env` (see
[Getting started](getting-started.md#2-get-your-two-tokens) for which is
which):

```bash
STORYBLOK_SPACE_ID=290156928914344
STORYBLOK_MANAGEMENT_TOKEN=your-personal-access-token
STORYBLOK_DELIVERY_TOKEN=your-preview-or-public-token
```

and read them in a config file of their own:

```php
// config/storyblok.php
return [
    'space_id' => env('STORYBLOK_SPACE_ID'),
    'management_token' => env('STORYBLOK_MANAGEMENT_TOKEN'),
    'delivery_token' => env('STORYBLOK_DELIVERY_TOKEN'),

    // published, or draft for a preview environment
    'version' => env('STORYBLOK_DEFAULT_VERSION', 'published'),

    // Leave unset for an EU space; see Configuration → Regions.
    'content_base_uri' => env('STORYBLOK_CONTENT_BASE_URI'),
    'management_base_uri' => env('STORYBLOK_MANAGEMENT_BASE_URI', 'https://mapi.storyblok.com/v1/'),

    'namespace' => 'App\\Storyblok',
    'output_path' => app_path('Storyblok'),
    'definitions_path' => resource_path('storyblok'),

    'cache_store' => 'storyblok',
];
```

Don't use `StoryblokContent::fromEnvironment()` in Laravel. It reads with
`getenv()`, and once you run `php artisan config:cache` in production, Laravel
no longer loads `.env`, so the token isn't there. Reading from `config()`, as
below, works with and without the config cache.

The generated classes go in `app/Storyblok` under `App\Storyblok`. Laravel
already autoloads `App\` from `app/`, so there's nothing to add to
`composer.json`.

## Generating the classes

Add an Artisan command, so generation uses the same config as the app:

```php
// routes/console.php
use Illuminate\Support\Facades\Artisan;
use Tlab\StoryblokTransfers\StoryblokTransferGenerator;

Artisan::command('storyblok:generate', function () {
    $result = (new StoryblokTransferGenerator(
        spaceId: config('storyblok.space_id'),
        token: config('storyblok.management_token'),
        definitionsPath: config('storyblok.definitions_path'),
        outputPath: config('storyblok.output_path'),
        namespace: config('storyblok.namespace'),
        managementBaseUri: config('storyblok.management_base_uri'),
    ))->generate();

    foreach ($result->warnings as $warning) {
        $this->warn($warning);
    }

    $this->info(sprintf('Generated %d classes in %s.', count($result->componentNames), config('storyblok.output_path')));
})->purpose('Generate transfer classes from the Storyblok component schemas');
```

```bash
php artisan storyblok:generate
```

A `post` component becomes `App\Storyblok\PostTransfer`. Commit both
`app/Storyblok` and `resources/storyblok`; see
[Generating transfers](generating-transfers.md) for what they are and when to
regenerate.

## The cache store

Give Storyblok a cache store of its own, in `config/cache.php`:

```php
'stores' => [

    'storyblok' => [
        'driver' => 'file',
        'path' => storage_path('framework/cache/storyblok'),
    ],

    // … your other stores
],
```

It has to be a store of its own because clearing it, from the webhook below,
empties the whole store. A file store with its own path is the simplest choice.
With Redis, give it a connection with its own database number, because
clearing a Redis store flushes that entire database, whatever prefix you set.
A database store clears its whole table.

## Registering the service

In `app/Providers/AppServiceProvider.php`, register `StoryblokContent` once for
the whole app:

```php
use Illuminate\Support\Facades\Cache;
use Tlab\StoryblokTransfers\Content\ContentOptions;
use Tlab\StoryblokTransfers\Content\StoryblokContent;
use Tlab\StoryblokTransfers\Content\Version;

public function register(): void
{
    $this->app->singleton(StoryblokContent::class, fn () => StoryblokContent::create(
        deliveryToken: config('storyblok.delivery_token'),
        namespace: config('storyblok.namespace'),
        defaults: new ContentOptions(Version::from(config('storyblok.version'))),
        baseUri: config('storyblok.content_base_uri'),
        cache: Cache::store(config('storyblok.cache_store')),
    ));
}
```

Laravel's cache stores are PSR-16 caches already, so `Cache::store()` is passed
as it is. Published reads are now cached; see [Caching](caching.md) for what that
means. Leave `cache:` out to read straight from Storyblok every time.

Use a singleton rather than `bind()`. The package caches its reflection work per
instance, so building a new one for every injection would redo it.

## A blog

With a `post` component and stories under `blog/`:

```php
// routes/web.php
use App\Http\Controllers\BlogController;

Route::get('/blog', [BlogController::class, 'index']);
Route::get('/blog/{slug}', [BlogController::class, 'show']);
```

```php
// app/Http/Controllers/BlogController.php
namespace App\Http\Controllers;

use App\Storyblok\PostTransfer;
use Storyblok\RichtextRender\Resolver;
use Tlab\StoryblokTransfers\Content\StoryblokContent;
use Tlab\StoryblokTransfers\Content\StoryQuery;

class BlogController extends Controller
{
    public function __construct(private StoryblokContent $storyblok)
    {
    }

    public function index()
    {
        $posts = $this->storyblok->stories()->findBy(
            new StoryQuery(startsWith: 'blog/', sortBy: 'first_published_at:desc'),
            PostTransfer::class,
        );

        return view('blog.index', ['posts' => $posts]);
    }

    public function show(string $slug)
    {
        $post = $this->storyblok->stories()->bySlug('blog/' . $slug, PostTransfer::class);

        abort_if($post === null, 404);

        return view('blog.show', [
            'post' => $post->getContent(),
            'body' => (new Resolver())->render($post->getContent()->getBody()?->toDocument() ?? []),
        ]);
    }
}
```

```blade
{{-- resources/views/blog/index.blade.php --}}
<h1>Blog</h1>

@foreach ($posts as $story)
    <article>
        <h2><a href="/{{ $story->getFullSlug() }}">{{ $story->getContent()->getTitle() }}</a></h2>
        <p>{{ $story->getContent()->getExcerpt() }}</p>
    </article>
@endforeach
```

```blade
{{-- resources/views/blog/show.blade.php --}}
<article>
    <h1>{{ $post->getTitle() }}</h1>

    @if ($post->getImage()?->getFilename())
        <img src="{{ $post->getImage()->getFilename() }}" alt="{{ $post->getImage()->getAlt() }}">
    @endif

    {!! $body !!}
</article>
```

The body is a rich text field, rendered with Storyblok's renderer
(`composer require storyblok/richtext-resolver`). Its HTML is output unescaped
with `{!! !!}`, so only render rich text from your own space. Components that
editors inserted into the text need a little more; see
[Rich text](rich-text.md#rendering-them-with-the-rest-of-the-text).

Passing `PostTransfer::class` types the result, so your editor knows
`getContent()` is a `PostTransfer`. A story under `blog/` that isn't a post
throws an `UnexpectedComponentException`, so keep only posts in that folder.
See [Reading content](reading-content.md) for everything else `bySlug()` and
`findBy()` can do.

## Clearing the cache when content is published

A route that clears the cache, for Storyblok to call:

```php
// routes/web.php
use Tlab\StoryblokTransfers\Content\StoryblokContent;

Route::post('/storyblok/webhook', function (StoryblokContent $storyblok) {
    $cleared = $storyblok->clearCache();

    return response()->json(['cleared' => $cleared], $cleared ? 200 : 500);
});
```

Storyblok can't send a CSRF token, so exclude the route in
`bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->preventRequestForgery(except: ['storyblok/webhook']);
    // On Laravel 11 and 12: $middleware->validateCsrfTokens(except: ['storyblok/webhook']);
})
```

Then, in Storyblok, add a webhook to `https://your-app.example/storyblok/webhook`
for the story and datasource entry events. See
[Caching → Clearing the cache](caching.md#clearing-the-cache) for what happens
if it fails, and for the signature Storyblok can send.

## Previewing drafts

For a preview environment, use the preview token and set
`STORYBLOK_DEFAULT_VERSION=draft`. Every read then shows unpublished changes,
and drafts are never cached, so editors see their edits at once.
