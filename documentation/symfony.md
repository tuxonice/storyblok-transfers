# Using it with Symfony

This page sets the package up in a Symfony app: environment variables, a
shared service with its own cache pool, a console command to generate
classes, a small blog, and a webhook that clears the cache when content is
published. It was written against Symfony 7.4.

```bash
composer require tuxonice/storyblok-transfers symfony/cache
```

`symfony/cache` is already installed in most Symfony apps; it provides the
cache pool.

## Configuration

Declare the variables in `.env`, and put the real tokens in `.env.local`,
which isn't committed (see
[Getting started](getting-started.md#2-get-your-two-tokens) for which token is
which):

```bash
# .env
STORYBLOK_SPACE_ID=
STORYBLOK_MANAGEMENT_TOKEN=
STORYBLOK_DELIVERY_TOKEN=
STORYBLOK_DEFAULT_VERSION=published
```

Don't use `StoryblokContent::fromEnvironment()` in Symfony. It reads with
`getenv()`, and Symfony loads `.env` files into `$_ENV` and `$_SERVER` only, so
it wouldn't find the token. Pass the values in with `%env()%`, as below.

## The cache pool

Give Storyblok a pool of its own, in `config/packages/cache.yaml`:

```yaml
framework:
    cache:
        pools:
            cache.storyblok:
                adapter: cache.adapter.filesystem   # or cache.adapter.redis
```

Clearing a Symfony pool removes only that pool's entries, even when several
pools share one Redis server, so the webhook below leaves the rest of your
cache alone.

## Registering the service

In `config/services.yaml`, keep the generated classes out of the service
scan, and register `StoryblokContent`:

```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true

    App\:
        resource: '../src/'
        exclude:
            - '../src/Kernel.php'
            - '../src/Storyblok/'

    Tlab\StoryblokTransfers\Content\StoryblokContent:
        factory: ['Tlab\StoryblokTransfers\Content\StoryblokContent', 'create']
        arguments:
            $deliveryToken: '%env(STORYBLOK_DELIVERY_TOKEN)%'
            $namespace: 'App\Storyblok'
            $defaults: !service
                class: Tlab\StoryblokTransfers\Content\ContentOptions
                arguments:
                    $version: '%env(enum:Tlab\StoryblokTransfers\Content\Version:STORYBLOK_DEFAULT_VERSION)%'
            $baseUri: '%env(default::STORYBLOK_CONTENT_BASE_URI)%'
            $cache: !service
                class: Symfony\Component\Cache\Psr16Cache
                arguments: ['@cache.storyblok']
```

- **`exclude: '../src/Storyblok/'`.** The generated classes are data, not
  services. Leave them out of the scan so Symfony doesn't try to register them.
- **`$version`.** The `enum:` processor turns `published` or `draft` into the
  package's `Version`. Anything else fails as soon as the service is built.
- **`$baseUri`.** `default::` passes `null` when `STORYBLOK_CONTENT_BASE_URI` is
  unset or empty, which means the EU region. For other regions, see
  [Configuration → Regions](reference/configuration.md#regions).
- **`$cache`.** `Psr16Cache` turns the pool into the PSR-16 cache the package
  takes. Published reads are now cached; see [Caching](caching.md) for what that
  means. Leave `$cache` out to read straight from Storyblok every time.

The service is shared, as Symfony services are by default, which is what the
package wants: it caches its reflection work per instance.

`bin/console lint:container` checks the wiring.

## Generating the classes

A console command, so generation reads the same environment as the app:

```php
// src/Command/GenerateStoryblokTransfersCommand.php
namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Tlab\StoryblokTransfers\Client\StoryblokManagementClient;
use Tlab\StoryblokTransfers\StoryblokTransferGenerator;

#[AsCommand('storyblok:generate', 'Generate transfer classes from the Storyblok component schemas')]
final class GenerateStoryblokTransfersCommand
{
    public function __construct(
        #[Autowire(env: 'STORYBLOK_SPACE_ID')] private string $spaceId,
        #[Autowire(env: 'STORYBLOK_MANAGEMENT_TOKEN')] private string $token,
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $result = (new StoryblokTransferGenerator(
            spaceId: $this->spaceId,
            token: $this->token,
            definitionsPath: $this->projectDir . '/config/storyblok',
            outputPath: $this->projectDir . '/src/Storyblok',
            namespace: 'App\\Storyblok',
            managementBaseUri: StoryblokManagementClient::DEFAULT_BASE_URI,
        ))->generate();

        foreach ($result->warnings as $warning) {
            $io->warning($warning);
        }

        $io->success(sprintf('Generated %d classes in src/Storyblok.', count($result->componentNames)));

        return Command::SUCCESS;
    }
}
```

```bash
bin/console storyblok:generate
```

A `post` component becomes `App\Storyblok\PostTransfer`. Symfony already
autoloads `App\` from `src/`, so there's nothing to add to `composer.json`.
Commit both `src/Storyblok` and `config/storyblok`; see
[Generating transfers](generating-transfers.md) for what they are and when to
regenerate. For a space outside the EU, pass your region's Management API
address as `managementBaseUri`.

## A blog

With a `post` component and stories under `blog/`, and Twig installed
(`composer require twig`):

```php
// src/Controller/BlogController.php
namespace App\Controller;

use App\Storyblok\PostTransfer;
use Storyblok\RichtextRender\Resolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Tlab\StoryblokTransfers\Content\StoryblokContent;
use Tlab\StoryblokTransfers\Content\StoryQuery;

final class BlogController extends AbstractController
{
    public function __construct(private StoryblokContent $storyblok)
    {
    }

    #[Route('/blog', methods: ['GET'])]
    public function index(): Response
    {
        $posts = $this->storyblok->stories()->findBy(
            new StoryQuery(startsWith: 'blog/', sortBy: 'first_published_at:desc'),
            PostTransfer::class,
        );

        return $this->render('blog/index.html.twig', ['posts' => $posts]);
    }

    #[Route('/blog/{slug}', methods: ['GET'])]
    public function show(string $slug): Response
    {
        $post = $this->storyblok->stories()->bySlug('blog/' . $slug, PostTransfer::class)
            ?? throw $this->createNotFoundException();

        return $this->render('blog/show.html.twig', [
            'post' => $post->getContent(),
            'body' => (new Resolver())->render($post->getContent()->getBody()?->toDocument() ?? []),
        ]);
    }
}
```

```twig
{# templates/blog/index.html.twig #}
<h1>Blog</h1>

{% for story in posts %}
    <article>
        <h2><a href="/{{ story.fullSlug }}">{{ story.content.title }}</a></h2>
        <p>{{ story.content.excerpt }}</p>
    </article>
{% endfor %}
```

```twig
{# templates/blog/show.html.twig #}
<article>
    <h1>{{ post.title }}</h1>

    {% if post.image and post.image.filename %}
        <img src="{{ post.image.filename }}" alt="{{ post.image.alt }}">
    {% endif %}

    {{ body|raw }}
</article>
```

Twig calls the getters for you, so `post.title` is `getTitle()`.

The body is a rich text field, rendered with Storyblok's renderer
(`composer require storyblok/richtext-resolver`). Its HTML is output with
`|raw`, so only render rich text from your own space. Components that editors
inserted into the text need a little more; see
[Rich text](rich-text.md#rendering-them-with-the-rest-of-the-text).

Passing `PostTransfer::class` types the result, so your editor knows
`getContent()` is a `PostTransfer`. A story under `blog/` that isn't a post
throws an `UnexpectedComponentException`, so keep only posts in that folder.
See [Reading content](reading-content.md) for everything else `bySlug()` and
`findBy()` can do.

## Clearing the cache when content is published

A controller for Storyblok to call:

```php
// src/Controller/StoryblokWebhookController.php
namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Tlab\StoryblokTransfers\Content\StoryblokContent;

final class StoryblokWebhookController
{
    #[Route('/storyblok/webhook', methods: ['POST'])]
    public function __invoke(StoryblokContent $storyblok): JsonResponse
    {
        $cleared = $storyblok->clearCache();

        return new JsonResponse(['cleared' => $cleared], $cleared ? 200 : 500);
    }
}
```

Then, in Storyblok, add a webhook to `https://your-app.example/storyblok/webhook`
for the story and datasource entry events. See
[Caching → Clearing the cache](caching.md#clearing-the-cache) for what happens
if it fails, and for the signature Storyblok can send.

## Previewing drafts

For a preview environment, use the preview token and set
`STORYBLOK_DEFAULT_VERSION=draft`. Every read then shows unpublished changes,
and drafts are never cached, so editors see their edits at once.
