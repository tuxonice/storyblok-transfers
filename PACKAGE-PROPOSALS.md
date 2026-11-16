# Proposed changes for tuxonice/storyblok-transfers

These proposals come from building a Laravel blog on the package (v0.4.0), in
[tuxonice/storyblok-laravel-blog](https://github.com/tuxonice/storyblok-laravel-blog): home, posts, categories,
authors, RSS, a sitemap, caching with webhook invalidation, and draft mode for the Visual Editor. Each item names the
problem, the workaround the blog needed, and the change that would remove the workaround. The `#n` numbers point to
the matching entries in the blog's `FRICTION.md`.

Each item has been checked against the package source at v0.4.0 and against the blog's code. Some items are about
features the package already has but the blog didn't find. Those point to gaps in the docs as much as in the API, and
they say so.

Priority is how much glue code or risk the item caused in practice.

## High priority

### 1. Keep `_uid` and `_editable` on transfers (#17)

- **Problem:** draft reads from the API carry `_editable` (the `<!--#storyblok#{...}-->` comment the bridge uses to
  draw block outlines) and `_uid` on every component. Hydration drops both, and the docs don't mention them. The story
  envelope (`StoryTransfer`) doesn't keep the numeric story `id` either: `StoryMapper::envelope()` never reads it.
- **Workaround:** the Visual Editor bridge only reloads the page on `change` and `published`. Editors can't click a
  block in the preview to jump to its field.
- **Proposal:** add `getUid(): ?string` and `getEditable(): ?string` to every generated transfer, or to a shared base.
  Add `getId(): ?int` to `StoryTransfer`.
- **Implementation note:** generated classes are written by `Tlab\TransferObjects\DataTransferBuilder`, and hydration
  goes through `AbstractTransfer::fromArray()`, which camel-cases keys. Check that a leading-underscore key such as
  `_uid` maps to a property at all. If it doesn't, the hydrator has to lift these two keys out itself before calling
  `fromArray()`.

### 2. Per-call options replace the defaults (#5, #18)

- **Problem:** a per-call `ContentOptions` replaces the client's defaults instead of merging into them
  (`$options ??= $this->defaults` in `StoryRepository`, and the same in `StoryQuery::toQuery()`). A client configured
  with `Version::Draft` silently reads the published version on any call that passes
  `(new ContentOptions())->with...()`, which is usually the call that resolves relations.
- **Workaround:** `Blog::options()` builds `new ContentOptions($this->draft->version())` for every call.
- **What already exists:** `StoryblokContent::defaults()` returns the configured defaults, and `ContentOptions` is
  immutable. So `$storyblok->defaults()->withResolveRelations([...])` already extends the defaults without losing the
  version. The blog passes the right version as its defaults (`AppServiceProvider`), so `Blog::options()` could become
  `$this->storyblok->defaults()`. The blog didn't find this because the docs show only `new ContentOptions()` for
  per-call options.
- **Proposal:**
  - **Docs (do first):** in `reading-content.md` and `relations.md`, show per-call options built from
    `$storyblok->defaults()->with...()`, and warn that `new ContentOptions()` resets the version to published.
  - **Merging (optional, bigger):** treating a per-call option as a patch rather than a replacement goes against the
    current design (`StoryQuery` documents its options as "an override, not a default"). It also can't be built as
    things stand. `ContentOptions::$version` defaults to `Version::Published` and can't be null, so "not set" and
    "explicitly published" look the same. Merging would need `version` and `language` to become nullable, with the
    repository filling in the gaps.

### 3. Make draft mode one call (#18)

- **Problem:** `Version::Draft` only sets `version=draft`. Draft reads also need the preview token, which is fixed when
  the client is built, so the app has to build a second `StoryblokContent`. The blog registers it as a scoped
  (per-request) service that picks the token from `DraftMode::active()`.
- **Proposal:** `StoryblokContent::forDrafts(string $previewToken): self`, which returns a sibling instance with the
  preview token and `Version::Draft` as its defaults. The sibling can reuse the same caching layer, because
  `CachingContentClient` never caches draft reads.
- **Design constraint:** this only works for instances built by `create()` or `fromEnvironment()`. An instance from
  `usingClient()` wraps a `ContentClient` the package can't look into, so it can't swap the token. The blog uses
  `usingClient()` because of #11, so #11 has to land first for this to help it. Otherwise the package needs a
  `ContentClient` that picks the token by version.

### 4. Relation helpers (#7)

- **Problem:** `StoryTransfer::getRelation()` (which calls `RelationMap::get()`) takes a single uuid and returns
  `StoryTransfer|array|null`. Every multi-reference field needs a loop with an `instanceof StoryTransfer` check, and
  `getContent()` stays typed as the generic `AbstractTransfer`.
- **Workaround:** one hand-written `post()` mapper in `App\Blog\Blog` that loops over each field and narrows the types.
- **Proposal**, on both `StoryTransfer` and `RelationMap`:
  - `getRelations(array $uuids): list<StoryTransfer>`, which skips unresolved uuids
  - a typed lookup such as `getRelation(string $uuid, AuthorTransfer::class)` that returns the narrowed story or
    `null`
  - optionally, rename or alias `RelationMap::get()` to `getRelation()`, so both types share one name

### 5. Per-story cache invalidation (#14)

- **Problem:** `clearCache()` empties the whole cache. A typo fix in one post throws away every cached read, and the
  next visitors trigger a burst of requests. On a large space that can run into the Delivery API's 50 requests per
  second limit after every publish.
- **Workaround:** a dedicated cache store, cleared entirely by the webhook.
- **Proposal:** `forget(string $fullSlug)`, which drops the story plus the listings and links tree that depend on it.
  An alternative is to tag cache entries by story uuid, so a webhook payload can invalidate exactly what changed.
- **Implementation note:** this is harder than it looks. Cache keys are `xxh128(path?sorted-query)`, and PSR-16 has
  no tags, so nothing can find the listings, links trees or relation lookups that contain a given story. A precise
  `forget()` needs an index from story to keys stored next to the entries. A cheaper first step is a generation
  counter in the key prefix. That invalidates everything without calling `clear()` on a shared pool, so the cache no
  longer needs a store of its own, though it doesn't prevent the burst of re-fetches.

## Medium priority

### 6. Richtext helpers: headings and plain text (#11)

- **Problem:** the package returns the raw node tree, so collecting headings, building and de-duplicating anchor ids,
  and counting words (for reading time) all need a custom renderer (`App\Blog\RichtextRenderer`).
- **Proposal:** add these to `RichtextTransfer`:
  - `getHeadings(int $level = 2): list<array{text: string, id: string}>`, with stable, de-duplicated ids that a
    renderer can reuse for the anchors
  - `getText(): string`, which returns plain text for reading time and excerpts

### 7. Walk helper for embedded blocks (#10)

- **Problem:** a richtext `blok` node carries an id, and `getBloks()[$id]` is a list of components, so every renderer
  looks up the id and loops.
- **Proposal:** a walk helper that yields `[node, components]` pairs, or a renderer hook that receives the resolved
  components directly.

### 8. `LinkTransfer::getHref()` (#9)

- **Problem:** a multilink can be `story`, `url`, `email` or `asset`, and each type keeps its target in a different
  field, so every app writes the same `match` on `getLinktype()` (`Blog::href()`).
- **Prerequisite:** `LinkTransfer` only hydrates `id`, `url`, `linktype`, `fieldtype` and `cachedUrl`. Storyblok's
  multilink payload keeps an email address under `email`, and the anchor and target under `anchor` and `target`, so
  none of them survive hydration today. The blog reads email links from `getUrl()`, which is likely empty for a real
  email link. This wasn't caught because every multilink in the blog's content is a `url` link. The blog also has no
  case for `asset` links, which currently come out as `null`.
- **Proposal:** add `email`, `anchor` and `target` to `LinkTransfer`. Then add `getHref(): ?string`, which returns
  story links as root-relative paths (from `cached_url`), emails as `mailto:` links, URLs and assets as they are, and
  appends `#anchor` when one is set.

### 9. Typed reference filters (#20)

- **Problem:** filtering by a single reference needs the `in` operator, while a multi-reference needs `any_in_array`.
  The wrong operator isn't an error: it silently returns 0 results, the same as a uuid that matches nothing. The blog
  confirmed this against its live space: 5 posts with `in`, 0 with `any_in_array`, on the same author field.
- **Proposal:** helpers on `StoryQuery` that pick the operator, such as `whereReference('author', $uuid)` and
  `whereAnyReference('categories', $uuid)`, alongside the raw `filterQuery` array. At minimum, document the difference
  in `reading-content.md`, which today shows only `in`.

### 10. In-memory fake for application tests (#8, #19)

- **Problem:** `ContentClient` is a clean seam, but feature-testing routes without the network took a fake of about
  260 lines (`tests/Support/FakeContentClient.php`): story by slug, story by uuid, listings, `resolve_relations`,
  the links tree, datasources answering 404 like the real API, and filter operators that behave like Storyblok's.
  Testing the real client and cache on top of it needed another Guzzle fake (`tests/Support/FakeGuzzle.php`).
- **Proposal:** ship an `ArrayContentClient` or a `StoryblokContent::fake()` that is seeded with stories and
  implements the same filtering and relation resolution. The package's own `tests/Fixture/FakeContentClient.php`
  only replays queued responses, so it doesn't cover this. The blog's fake is a better starting point.

### 11. Cache timings and logger in `create()` (#15)

- **Problem:** `create(..., cache:)` uses fixed TTLs and no logger. To configure the TTLs, or to log when a stale copy
  is served, the blog builds `StoryblokContentClient` and `CachingContentClient` by hand in `AppServiceProvider` and
  passes them to `usingClient()`.
- **Proposal:** let `create()` and `fromEnvironment()` accept `freshFor`, `keepStaleFor`, `keyPrefix` and `logger`,
  or a single `CacheOptions` value. `CachingContentClient` already takes all of these, so this only passes them
  through. It also unblocks #3 for apps like the blog.

## Low priority

### 12. Dates from the links endpoint (#12)

- **Problem:** the links endpoint returns `published_at`, `created_at` and `updated_at` when called with
  `include_dates=1`. `LinkRepository::all()` sends only `ContentOptions::toQuery()` and `starts_with`, and `LinkEntry`
  has no getters for the dates. A sitemap with `<lastmod>` therefore needs a second story listing.
- **Proposal:** `ContentOptions::withDates()`, or an argument on `links()->all()`, plus `LinkEntry::getPublishedAt()`,
  `getCreatedAt()` and `getUpdatedAt()`.

### 13. Pagination in `links()->all()` (#13)

- **Problem:** `all()` makes a single request and sends none of `paginated`, `page` or `per_page`. It is unverified
  at what space size an unpaginated response is truncated.
- **Proposal:** have `all()` walk every page, the way `DatasourceRepository::entries()` already does.

### 14. Datasource map, and a consistent result when a datasource is missing (#21)

- **Problem:** `entries()` returns a flat list, so every consumer builds the same value-to-name map
  (`Blog::tagNames()`). A missing datasource makes the API answer 404, which `StoryblokContentClient` turns into a
  `ResourceNotFoundException` that `entries()` doesn't catch. The other reads return `null` or an empty list instead.
- **Proposal:** `datasources()->map(string $slug): array<string, string>`, which maps values to names and returns an
  empty array for a missing datasource. At minimum, make a missing datasource return an empty result.

### 15. Webhook signature verifier (#16)

- **Problem:** `caching.md` describes the scheme (a hex HMAC-SHA1 of the raw body, sent in `webhook-signature`), but
  each app writes its own comparison (the blog uses `hash_hmac` and `hash_equals`) and can get the constant-time check
  wrong.
- **Proposal:** `WebhookVerifier::isValid(string $rawBody, ?string $signature, string $secret): bool`. Confirm the
  scheme against a real delivery from Storyblok first: the blog couldn't test it.

### 16. PSR-18 instead of Guzzle (#19)

- **Problem:** `create(httpClient:)` takes `GuzzleHttp\ClientInterface`, while the cache argument is a PSR-16
  interface. Test fakes have to be real Guzzle clients with a custom handler, and apps depend on Guzzle directly
  (`guzzlehttp/guzzle` is a hard requirement in `composer.json`).
- **Proposal:** accept `Psr\Http\Client\ClientInterface` (PSR-18) with a PSR-17 request factory. That also makes
  Guzzle optional. It changes the `create()` and `StoryblokContentClient` signatures, so it needs a changelog entry.

### 17. Generated code that passes Pint (#1)

- **Problem:** all generated `*Transfer.php` files fail Laravel Pint (`class_attributes_separation`, `phpdoc_*`,
  `no_superfluous_phpdoc_tags`, `no_empty_phpdoc`), so the blog excludes `app/Storyblok/Transfers` in `pint.json`.
- **Proposal:** document the `pint.json` exclusion in `laravel.md`, which this package can do on its own. Emitting
  code that passes `@PSR12` and Pint's Laravel preset belongs upstream, since the code is written by
  `Tlab\TransferObjects\DataTransferBuilder`.

### 18. Prune stale component definitions (#2)

- **Problem:** deleting a component in Storyblok leaves its definition JSON behind, so its class keeps being generated
  (five stale classes in the blog). This is documented in `limitations.md` and `generating-transfers.md`, but the blog
  still ran into it.
- **Proposal:** a `--prune` option that removes definition files for components that no longer exist in the space,
  and/or `--only=post,author,...`. Pruning is cheap because the generator already fetches the full component list:
  delete any definition file whose component isn't in it. Also mention the limitation in `laravel.md` and
  `symfony.md`, where framework users will look.

## Suggested order

1. **Docs-only fixes:** the `defaults()->with...()` pattern (#2), the `in`/`any_in_array` difference (#9), the
   `pint.json` exclusion (#17) and stale definitions (#18) in the framework guides. These cost little, and the blog
   shows they're already causing problems.
2. **One release about draft mode:** items 1, 11 and 3, in that order, since #3 only helps apps that build their client
   by hand once #11 lands. Together they make the Visual Editor work fully, with click-to-edit outlines and no lost
   draft reads.
3. **Helpers that remove app glue:** items 4, 6, 7, 8 (with its `LinkTransfer` fields) and 9. They are small and
   additive.
4. **Production setup:** items 5 and 10.
5. **Everything else**, as time allows. Merging per-call options (#2) only if the docs fix isn't enough.
