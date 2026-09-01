# Storyblok Transfer Generator

Framework-agnostic PHP package that reads component schemas from the Storyblok
Management API and generates PHP transfer objects using
[`tuxonice/transfer-objects`](https://github.com/tuxonice/data-transfer-object).

```
Storyblok Management API → JSON definition files → PHP Transfer classes
```

The JSON definition files are an intentional intermediate step: they are
human-readable, diffable and belong in version control, so a schema change in
Storyblok shows up as a reviewable diff before any PHP is regenerated.

## Requirements

- PHP 8.1+
- A Storyblok **personal access token** with access to the space

## Installation

```bash
composer require tuxonice/storyblok-transfers
```

### Where things go in your project

Following the `tuxonice/transfer-objects` convention, two directories in your
project hold the two artefacts:

```
src/dto-definitions/      # JSON definitions - generated, but committed
src/DataTransferObjects/  # PHP transfer classes - generated, not committed
```

These are the defaults, so you do not need to create them by hand — unlike the
bare `tuxonice/transfer-objects` workflow, this generator creates both
(recursively) if they are missing.

Commit `src/dto-definitions/`. It is the reviewable record of your Storyblok
schema: when a content type changes, the diff shows up there before any PHP is
regenerated. The generated classes under `src/DataTransferObjects/` are
reproducible from it, so they can be gitignored if you prefer to regenerate on
deploy.

Autoload the generated classes by pointing PSR-4 at them:

```json
{
    "autoload": {
        "psr-4": {
            "App\\DataTransferObjects\\": "src/DataTransferObjects/"
        }
    }
}
```

## Configuration

Copy the example file and fill in your space:

```bash
cp .env.example .env
```

| Variable | Required | Default | Purpose |
|---|---|---|---|
| `STORYBLOK_SPACE_ID` | yes | — | Space ID (Settings → General) |
| `STORYBLOK_MANAGEMENT_TOKEN` | yes | — | Personal access token (My Account → Personal access tokens) |
| `STORYBLOK_DEFINITIONS_PATH` | no | `./src/dto-definitions` | Where the JSON definitions go |
| `STORYBLOK_OUTPUT_PATH` | no | `./src/DataTransferObjects` | Where the generated classes go |
| `STORYBLOK_NAMESPACE` | no | `App\DataTransferObjects` | Namespace of the generated classes |
| `STORYBLOK_AUTH_SCHEME` | no | *empty* | `Bearer` for OAuth-issued tokens |
| `STORYBLOK_DELIVERY_TOKEN` | for reading | — | Content Delivery API token, preview or public (Settings → Access Tokens) |
| `STORYBLOK_CONTENT_BASE_URI` | no | `https://api.storyblok.com/v2/` | Region endpoint: US, AP, CA, CN spaces each have their own |
| `STORYBLOK_DEFAULT_VERSION` | no | `published` | `draft` in preview environments |
| `XDEBUG_MODE` | no | `off` | `off`, `coverage`, `debug`, `develop`, `trace` |
| `DOCKER_UID` | no | `1001` | Owner of files the container writes |
| `DOCKER_GID` | no | `1001` | Group of files the container writes |

Every one of these can also be passed as a positional CLI argument, but the
arguments are positional in a fixed order — so setting a custom path on the
command line would drag the token onto it too. Using the variables keeps the
token out of your shell history and out of the process list.

The token is the **Management API** token, not the preview/public Content
Delivery token — the two are not interchangeable. Generating transfer classes
uses the Management token; reading content back with `StoryblokContent` uses
the Content Delivery token above. A project that does both needs both.

Docker Compose reads `.env` automatically, so `make` targets and
`docker compose run` pick these up; anything already exported in your shell wins
over the file. `.env` is git-ignored.

The package ships no dotenv reader of its own — `bin/generate` calls `getenv()`,
and Compose is what injects the values into the container. Running the CLI
outside Docker means loading them yourself:

```bash
set -a && . ./.env && set +a
vendor/bin/generate
```

`DOCKER_UID` / `DOCKER_GID` only matter when you invoke `docker compose`
directly. The Makefile derives them from `id -u` / `id -g`, so `make` targets
already get this right.

## Usage

### PHP

```php
use Tlab\StoryblokTransfers\StoryblokTransferGenerator;

$generator = new StoryblokTransferGenerator(
    spaceId: '290156928914344',
    token: getenv('STORYBLOK_MANAGEMENT_TOKEN'),
    definitionsPath: __DIR__ . '/src/dto-definitions',
    outputPath: __DIR__ . '/src/DataTransferObjects',
    namespace: 'App\\DataTransferObjects',
);

$result = $generator->generate();

foreach ($result->warnings as $warning) {
    echo $warning, PHP_EOL;
}
```

`generate()` returns a `GenerationResult` with:

| Property | Meaning |
|---|---|
| `componentNames` | PascalCased names of the transfers that were generated |
| `warnings` | Fields that had to be left out, with the reason |

### CLI

```bash
export STORYBLOK_SPACE_ID=290156928914344
export STORYBLOK_MANAGEMENT_TOKEN=your-token

vendor/bin/generate
```

With everything configured through the environment (see
[Configuration](#configuration)), that is all it needs — including custom output
paths.

Positional arguments are also accepted:

```bash
vendor/bin/generate <space-id> <token> [definitions-path] [output-path] [namespace]
```

Prefer the environment variables. The arguments are positional, so reaching
`[output-path]` means passing the token too, which puts it in your shell history
and in the process list.

### Authorization header

The Management API expects a personal access token **bare** in the
`Authorization` header, which is the default. If you authenticate with a token
issued through the OAuth flow, pass the scheme explicitly:

```php
new StoryblokTransferGenerator(
    // ...
    authorizationScheme: 'Bearer',
);
```

## Field type mapping

| Storyblok type | Generated PHP | Notes |
|---|---|---|
| `text`, `textarea`, `markdown`, `option`, `uid`, `datetime` | `?string` | `datetime` stays an ISO 8601 string |
| `story` | `?string` | UUID only — resolve with `resolve_relations` and read the story off the envelope (see *Reading content → Relations*) |
| `number` | `?float` | |
| `boolean` | `?bool` | |
| `richtext`, `table` | `?array` | Storyblok's own node/table structure |
| `options` | `array<string>` | Multi-select |
| `asset` | `?AssetTransfer` | Bundled |
| `multiasset` | `array<AssetTransfer>` | Bundled |
| `link`, `multilink` | `?LinkTransfer` | Bundled |
| `bloks` | `array<BlokTransfer>` | Bundled, non-polymorphic |
| `tab`, `section` | *skipped* | Editor grouping only, carries no data |
| anything else | `?array` | Safe fallback for custom plugins |

Array-typed properties are never nullable — they default to `[]`, because
`tuxonice/transfer-objects` rejects nullable arrays outright. Everything else is
nullable by default, since Storyblok rarely guarantees a field is present.

### Bundled base transfers

`AssetTransfer`, `LinkTransfer` and `BlokTransfer` ship with the package under
`Tlab\StoryblokTransfers\Transfers` and are referenced from the
generated classes by fully-qualified name.

Every property on them is nullable with a `null` default. That is deliberate:
`AbstractTransfer::toArray()` reads every property by reflection, so a
defaultless typed property would throw
`Error: must not be accessed before initialization` whenever Storyblok omits the
field.

`BlokTransfer` exposes only `component` — just enough to type the nested-blocks
array. The hydrator replaces it with the concrete transfer per blok; see below.

## Hydrating content

Do not call `fromArray()` directly on a Storyblok payload. It passes raw values
straight to the setter, so a nested asset arrives as an `array` where the setter
demands an `AssetTransfer`:

```php
// Throws TypeError
ProductTransfer::fromArray(['image' => ['id' => 1, 'filename' => 'a.jpg']]);
```

`StoryblokHydrator` converts the whole graph instead:

```php
use Tlab\StoryblokTransfers\Hydration\StoryblokHydrator;

$hydrator = new StoryblokHydrator('App\\DataTransferObjects');

$page = $hydrator->hydrate(PageTransfer::class, $story['content']);
```

| Field | Hydrated into |
|---|---|
| `asset`, `link`, `multilink` | `AssetTransfer` / `LinkTransfer` |
| `multiasset` | `array<AssetTransfer>` |
| `bloks` | the concrete transfer for each blok, matched on its `component` |
| `richtext`, `table`, custom, `options` | passed through untouched |

Nesting resolves to any depth, so bloks inside bloks work. An empty asset or
link — Storyblok sends `""` or an all-null object — becomes `null` rather than a
`TypeError`.

A blok whose `component` has no generated class stays a **raw array** rather than
throwing, so an editor adding a component in Storyblok cannot break the page.
Regenerate to pick it up. Because of this and the concrete-transfer behaviour
above, guard when iterating a `bloks` array — see
[`bloks` hold more than their declared type says](#bloks-hold-more-than-their-declared-type-says).

The hydrator only throws `HydrationException`, and only for programming errors: a
target class that does not exist or is not a transfer.

## Reading content

`StoryblokHydrator` takes a content array; getting that array from Storyblok is
what this layer does.

```php
use Tlab\StoryblokTransfers\Content\StoryblokContent;

$storyblok = StoryblokContent::fromEnvironment();

$story = $storyblok->stories()->bySlug('blog/hello-world');

if ($story === null) {
    // No story at that slug. A 404 is an answer, not an exception.
}

echo $story->getFullSlug(), ' ', $story->getPublishedAt();
$content = $story->getContent();
```

`bySlug()` infers the transfer class from the story's own `component`, which is
what a router needs — it holds a slug and cannot know the content type before it
sees the response. A caller that does know passes it and gets it back typed:

```php
$story = $storyblok->stories()->bySlug('home', PageTransfer::class);
$story->getContent()->getTitle();   // PageTransfer, statically
```

A declared class that does not match the story's component throws
`UnexpectedComponentException`, naming the component that arrived.

### Listings

```php
use Tlab\StoryblokTransfers\Content\StoryQuery;

$list = $storyblok->stories()->findBy(new StoryQuery(
    startsWith: 'blog/',
    sortBy: 'published_at:desc',
    perPage: 10,
));

echo $list->getTotal();          // across every page

foreach ($list as $story) {
    echo $story->getFullSlug();
}
```

A query that matches nothing returns an empty list, never null.

### Draft content and options

```php
use Tlab\StoryblokTransfers\Content\ContentOptions;
use Tlab\StoryblokTransfers\Content\Version;

$options = (new ContentOptions(Version::Draft))->withLanguage('de');

$story = $storyblok->stories()->bySlug('home', null, $options);
```

### Relations

Pass the fields to resolve, then reach the resolved story through the envelope.
The generated property keeps its uuid:

```php
$options = (new ContentOptions())->withResolveRelations(['page.author']);
$story = $storyblok->stories()->bySlug('home', null, $options);

$author = $story->getRelation($story->getContent()->getAuthor());
```

### Links and datasources

```php
foreach ($storyblok->links()->all('blog/') as $link) {
    echo $link->getRealPath(), $link->isFolder() ? ' (folder)' : '';
}

foreach ($storyblok->datasources()->entries('categories', 'de') as $entry) {
    echo $entry->getName(), ' => ', $entry->getValue();
}
```

### Caching

The package ships no cache. `StoryblokContentClient` implements `ContentClient`,
so a decorator wraps that one method. The query maps the repositories build are
deterministic — `ContentOptions::toQuery()` and `StoryQuery::toQuery()` emit
their keys in a fixed order — so a decorator gets a stable key. Sort the map
yourself anyway: your decorator sees the query before the client does, and a
hand-built query, or a future reordering inside those value objects, would
otherwise double your cache entries.

```php
use Tlab\StoryblokTransfers\Client\ContentClient;
use Tlab\StoryblokTransfers\Client\ContentResponse;

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

        $key = 'sb-' . hash('xxh3', $path . '?' . http_build_query($query));

        return $this->cache->remember($key, fn () => $this->inner->get($path, $query));
    }
}

$storyblok = StoryblokContent::usingClient(
    new CachingContentClient($inner, $cache),
    'App\\DataTransferObjects',
);
```

Cache the raw response rather than hydrated transfers: a cache holding transfers
keeps the class shape from before the last regeneration, and fails in ways that
are hard to trace.

The client sorts the query too, but one layer further down — after your
decorator has already computed its key. That sort is for whatever keys on the
URI rather than on the call: a CDN, a forward proxy, an HTTP cache.

`cv` is exposed on `ContentOptions` but never managed for you — deciding when it
changes is the invalidation policy, and that belongs to your application.

## Limitations

These are real constraints, not oversights. Each is pinned by a test.

### `bloks` hold more than their declared type says

`bloks` properties are declared `@var array<BlokTransfer>`, but the hydrator
fills them with the **concrete** transfer for each blok, which is what preserves
the nested content. PHP enforces only `array`, so this is safe at runtime, but
the docblock is narrower than reality and will mislead static analysis of your
code. Iterate with a type guard:

```php
foreach ($page->getBody() as $blok) {
    if (!$blok instanceof TeaserTransfer) {
        continue;
    }

    echo $blok->getHeadline();
}
```

Keeping the docblock honest would mean post-processing every generated class to
`extends BlokTransfer`, which was judged too invasive for the benefit.

### Fields whose names cannot round-trip are skipped

`fromArray()` rebuilds the property name from the payload key by camel-casing it,
and the definition schema only accepts `^([a-z])+([A-Z][a-z]+)*$`. A Storyblok
key like `headline_2` camel-cases to `headline2`, which the schema rejects — and
renaming it to `headlineTwo` would validate but then never hydrate, silently
staying null forever.

Such fields are therefore **left out and reported in `warnings`**. Affected
patterns:

- digits anywhere in the key (`headline_2`, `image_1`)
- consecutive capitals in the key itself (`CTA`)

Rename the field in Storyblok (`headline_two`) to have it generated.

### The output directory is cleared on every run

`tuxonice/transfer-objects` deletes **all** `*Transfer.php` and
`*TransferImmutable.php` files in the output directory before writing. Point
`outputPath` at a directory that holds nothing but generated classes.

### Stale definitions are not pruned

Deleting a component in Storyblok does not remove its definition file, so its
class keeps being generated. Delete the definition JSON by hand when a component
goes away.

### Relation resolution is one level deep

`resolve_relations` brings back the stories a field points at, but not the
stories *those* point at. Ask for the second level with a second call.

### Reaching a relation needs the envelope

`getRelation()` lives on `StoryTransfer`, but the uuid that keys it sits on a
blok that may be deep inside the content. A component rendering itself in
isolation therefore cannot resolve its own relations — pass the envelope, or
resolve before you descend.

This is the same roughness `RichtextTransfer` has with its embedded bloks, and
it is the price of keeping the generated property a plain `?string`: the
alternative changes every generated class.

## Development

The repository ships a Docker environment; no local PHP is required.

```bash
make build      # build the image
make install    # composer install
make test       # phpunit
make stan       # phpstan (level 8)
make cs         # phpcs (PSR-12)
make shell      # shell in the container
```

`make test-coverage` runs the suite with Xdebug coverage enabled.

## License

MIT
