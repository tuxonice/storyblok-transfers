# Reading content

`StoryblokContent` reads stories from the Content Delivery API and hands them
back as the classes you [generated](generating-transfers.md).

## Setting it up

```php
use Tlab\StoryblokTransfers\Content\StoryblokContent;

$storyblok = StoryblokContent::fromEnvironment();
```

`fromEnvironment()` reads `STORYBLOK_DELIVERY_TOKEN`, `STORYBLOK_NAMESPACE`,
`STORYBLOK_CONTENT_BASE_URI` and `STORYBLOK_DEFAULT_VERSION`. See
[Configuration](reference/configuration.md).

To pass the settings yourself, for example from your framework's config:

```php
use Tlab\StoryblokTransfers\Content\ContentOptions;
use Tlab\StoryblokTransfers\Content\Version;

$storyblok = StoryblokContent::create(
    deliveryToken: $config['storyblok']['token'],
    namespace: 'App\\DataTransferObjects',
    defaults: new ContentOptions(Version::Draft),   // optional
    baseUri: 'https://api-us.storyblok.com/v2/',     // optional, EU by default
);
```

Build it once and reuse it. In a framework, register it as a shared service:
see [Laravel](laravel.md#registering-the-service) and
[Symfony](symfony.md#registering-the-service).

It gives you three repositories:

```php
$storyblok->stories();       // stories
$storyblok->links();         // the links tree
$storyblok->datasources();   // datasource entries
```

## One story

### By slug

```php
$story = $storyblok->stories()->bySlug('blog/hello-world');
```

Pass the full slug, the path you see in Storyblok. Slashes at the start or end
don't matter.

`bySlug()` returns `null` when there is no story at that slug, so a router can
turn that into a 404 page. An empty slug (`''` or `'/'`) also returns `null`,
without asking Storyblok. Deciding which story your root URL shows is up to your
router.

### By uuid

```php
$story = $storyblok->stories()->byUuid('3b7e4c1a-…');
```

Same behaviour: `null` when there is no such story.

### What you get back

A `StoryTransfer`: the story's details, plus its content as your generated class.

```php
$story->getContent();          // PageTransfer, HeroTransfer, … your class
$story->getUuid();
$story->getSlug();             // 'hello-world'
$story->getFullSlug();         // 'blog/hello-world'
$story->getName();
$story->getLang();
$story->getPublishedAt();      // ISO 8601 string
$story->getFirstPublishedAt();
$story->getCreatedAt();
$story->getParentId();
$story->getTagList();          // list of tag names
$story->getTranslatedSlugs();
```

### Typed reads

`bySlug()` picks the class from the story's component, so the content comes back
typed as `AbstractTransfer`. If you know what to expect, pass the class and get
that type back:

```php
$story = $storyblok->stories()->bySlug('home', PageTransfer::class);

$story?->getContent()->getTitle();   // PageTransfer, as far as your IDE and PHPStan know
```

If the story turns out to be a different component, you get an
`UnexpectedComponentException` naming the component that arrived, instead of a
confusing type error later.

## Listings

```php
use Tlab\StoryblokTransfers\Content\StoryQuery;

$list = $storyblok->stories()->findBy(new StoryQuery(
    startsWith: 'blog/',
    sortBy: 'first_published_at:desc',
    perPage: 10,
));

foreach ($list as $story) {
    echo $story->getFullSlug(), PHP_EOL;
}
```

A query that matches nothing returns an empty list, never `null`. Pass a class
as the second argument to `findBy()` to type every story in the list, as with
`bySlug()`.

### Everything `StoryQuery` takes

| Parameter | Storyblok parameter | Example |
|---|---|---|
| `startsWith` | `starts_with` | `'blog/'` |
| `sortBy` | `sort_by` | `'first_published_at:desc'`, `'content.title:asc'` |
| `filterQuery` | `filter_query` | see below |
| `byUuids` | `by_uuids` | `['uuid-1', 'uuid-2']` |
| `excludingFields` | `excluding_fields` | `['body']`, to make long listings lighter |
| `page` | `page` | `1` (default) |
| `perPage` | `per_page` | `25` (default), at most `100` |
| `options` | | a `ContentOptions` for this query only; see below |

`filterQuery` takes field ⇒ operation ⇒ value, using Storyblok's
[filter operations](https://www.storyblok.com/docs/api/content-delivery/v2/filter-queries):

```php
new StoryQuery(filterQuery: [
    'category' => ['in' => 'news'],
    'featured' => ['is' => 'true'],
]);
```

### Pages

`$list` is one page of results.

```php
$list->getStories();   // this page's stories
$list->getTotal();     // across every page
$list->getPage();
$list->getPerPage();
```

To fetch the next page, reuse the query with a different page number:

```php
$query = new StoryQuery(startsWith: 'blog/', perPage: 10);

$next = $storyblok->stories()->findBy($query->withPage(2));
```

### Stories that couldn't be read

A story whose component has no generated class is left out of the list rather
than breaking the whole page. It usually means someone added a component in
Storyblok and you haven't regenerated yet. The skipped stories are kept as raw
arrays, so you can log them:

```php
foreach ($list->getSkipped() as $raw) {
    $logger->warning('No transfer class for ' . $raw['full_slug']);
}
```

`getTotal()` still counts them, since that number comes from Storyblok.

Reading a single story works differently: if its component has no class,
`bySlug()` throws `UnresolvableComponentException`, because that story is the
whole thing you asked for.

## Drafts, languages and cache versions

Every read takes a `ContentOptions`:

```php
use Tlab\StoryblokTransfers\Content\ContentOptions;
use Tlab\StoryblokTransfers\Content\Version;

$options = (new ContentOptions(Version::Draft))->withLanguage('de');

$story = $storyblok->stories()->bySlug('home', null, $options);
```

| Option | Default | Notes |
|---|---|---|
| `version` | `Version::Published` | `Version::Draft` needs a preview token |
| `language` | none, which is the default language | a language code from your space, e.g. `'de'` |
| `resolveRelations` | none | see [Relations](relations.md) |
| `cv` | none | Storyblok's cache version; see [Caching](caching.md) |

Each option has a `with…()` method that returns a new copy, so a
`ContentOptions` can be shared safely.

### Defaults and overrides

The options you give `StoryblokContent` (through `defaults:` or
`STORYBLOK_DEFAULT_VERSION`) apply to every read, listings included. Options
passed to a single call replace them for that call:

```php
// Everywhere: drafts, because this is the preview environment
$storyblok = StoryblokContent::create($token, 'App\\DataTransferObjects', new ContentOptions(Version::Draft));

// This one listing: published only
$list = $storyblok->stories()->findBy(new StoryQuery(
    startsWith: 'blog/',
    options: new ContentOptions(Version::Published),
));
```

Per-call options replace the defaults as a whole; they are not merged field by
field.
