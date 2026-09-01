# Storyblok Content Layer — Design

**Date:** 2026-09-01
**Status:** Proposed

## Problem

This library builds half a bridge.

It reads component schemas from the Management API, writes JSON definitions, and
generates transfer classes; `StoryblokHydrator` then turns a Storyblok content
array into a populated transfer graph. What it never does is **fetch content**.
There is no Content Delivery API code anywhere in `src/`.

So a consuming application still has to write, by hand, the layer that stands
between it and everything this library offers: a Guzzle call to the CDA, the
`version` switch between published and draft, `cv`, `resolve_relations`,
language and fallback language, pagination, and the mapping from a story's
`content.component` to the generated class it should hydrate into. Only then can
it hand `$story['content']` to the hydrator.

The consequences show up in three places.

**The story envelope is lost.** The hydrator takes a content array, so
everything wrapped around it — `uuid`, `slug`, `full_slug`, `lang`,
`published_at`, `translated_slugs`, `tag_list` — never reaches a typed object.
Routing, `hreflang` and publication dates are needed on nearly every page of a
real site, and all of them live in the envelope rather than the content.

**Relations are a latent type error.** `FieldTypeMapper` maps `story` to
`?string` and the README states that relations are never resolved
(`src/Schema/FieldTypeMapper.php`, `STRING_TYPES`). That holds only while nobody
calls the CDA with `resolve_relations`. The moment a content layer exists,
somebody will, the field will arrive as an object, and the generated `?string`
setter will fail. The limitation is stable today purely because the library
cannot make the request that breaks it.

**The library already has a shadow implementation.** `tools/StoryFetcher.php`
(132 lines) and `tools/Story.php` fetch story content for the smoke test. They
do it against the *Management API* — `mapi.storyblok.com/v1/spaces/{id}/stories/{id}`
with the token in an `Authorization` header (`tools/StoryFetcher.php:19`) —
because the library offered nothing to use. That is the wrong API for reading
content in production: it is rate limited, is not CDN backed, and its story
envelope differs from the CDA's. The smoke test therefore exercises a parallel
code path that ships to nobody.

## Approach

Add a content layer: one HTTP client for the Content Delivery API, and one
repository per resource, returning hydrated transfers.

Four CDA resources are in scope: a single story by slug or uuid, a story
listing, the links tree, and datasource entries. They share transport, auth,
configuration, error handling and the cache seam, which is what makes them one
piece of work rather than four.

Three decisions shape everything below.

**Relations travel beside the content, not inside it.** The generated property
stays `?string` holding the uuid; resolved stories live in a `RelationMap`
reachable from the envelope. This needs no generator change and breaks no
existing output. It also repeats a pattern this codebase already committed to:
`RichtextTransfer::getBloks()` keys hydrated components by node id precisely so
the tree itself can stay plain arrays. The same reasoning applies here — content
that a renderer or a `?string` setter expects to be a scalar must keep being one.

**The target class is inferred, with an optional declaration.** `bySlug($slug)`
reads `content.component` and resolves the class, returning
`StoryTransfer<AbstractTransfer>`; `bySlug($slug, PageTransfer::class)` asserts
the match and returns `StoryTransfer<PageTransfer>`. A router holds a slug and
cannot know the content type before it sees the response, so inference has to
exist; a controller that does know should not lose static typing for the
router's benefit.

**Caching is a seam, not a feature.** The client is defined by an interface so an
application can decorate it. No cache implementation, no invalidation policy, no
new dependency.

### Rejected alternatives

- **One client and one repository.** Smallest surface, but one class would hold
  three unrelated payload shapes: a story has an envelope wrapping content, the
  links tree is a flat map of link objects, and datasource entries are key/value
  pairs. Nothing is shared beyond transport.
- **A fluent façade** (`$storyblok->stories()->startsWith('blog/')->get()`).
  Pleasant to write, but substantially more machinery, harder to test
  exhaustively, and it hides where the HTTP request happens. A library whose
  README documents its limitations explicitly should not obscure its own
  boundaries.
- **A dedicated reference type** (`StoryReferenceTransfer` holding uuid plus the
  optionally resolved story). Reads better than a side map, but it is a breaking
  change to the generator: every previously generated class becomes stale and
  the documented `story` → `?string` mapping stops being true.
- **Extending `StoryblokManagementClient`.** The CDA passes its token as a query
  parameter rather than an `Authorization` header, uses a different base host,
  and takes a different token entirely. There is no shared mechanism to inherit.
- **Reading content through the Management API**, as the smoke test does today.
  It works and needs no new token, but it is rate limited, uncached, and not the
  API Storyblok intends for content delivery.
- **Generating PHP enums from datasource entries.** A genuine typing win for
  `option` / `options` fields, but it changes the generator and is independent of
  reading content. It gets its own cycle. This design only *reads* datasources.

## Verification prerequisite

Two things must be checked against a real space **before implementation**,
because the answers change the code rather than just confirming it.

1. **The shape of a `resolve_relations` response.** Whether the resolved story
   replaces the uuid inline in `content`, appears in a `rels` key at the response
   root, or both. This determines how much work the de-inlining step in
   `StoryRepository` does.
2. **The semantics of `cv`.** What omitting it returns versus passing a stale
   one, which decides whether "expose, do not manage" is sufficient.

Neither can be answered by a mock, and neither is answered by the existing smoke
test, which does not touch the CDA. A CDA probe against the real space is the
first task of the implementation plan, and its findings are recorded here before
anything else is written.

### Answers

Probed 2026-09-01 against the smoke-test space (id `340703`) with a throwaway
`tools/cda-probe.php`, deleted after this task; the raw output is preserved in
`.superpowers/sdd/2026-09-01-storyblok-content-layer/task-1-report.md`.

**1. Where a resolved relation lands — answered.** A field was added to the
space after the first probe: `test.author`, schema type `option` with
`source: internal_stories` — see the schema-type note below for what that
means for this codebase. Probed the `test` story, whose body nests a `test`
blok with `author` set to the uuid of the `home` story
(`f56179ea-a3ef-469b-a367-71000fdd1e79`), once without `resolve_relations`
and once with `resolve_relations=test.author`, so the diff between the two
responses is the evidence:

- **Without** `resolve_relations`: `rels: []`; the nested blok's `author`
  field is the plain uuid string `"f56179ea-a3ef-469b-a367-71000fdd1e79"`.
- **With** `resolve_relations=test.author`: the nested blok's `author` field
  is **unchanged** — still the same uuid string, byte-for-byte, at the same
  path in `content`. The root `rels` array is now populated with one element:
  the full envelope of the `home` story (`uuid`, `name`, `content`, `slug`,
  `full_slug`, and the rest of the envelope keys recorded below), keyed by
  matching that envelope's `uuid` against the string still sitting in
  `content`.

So the answer is **`rels` only, never inline substitution**. `content` is not
mutated by `resolve_relations` at all; the relation surfaces exclusively as an
addressable-by-uuid array at the response root. This makes the de-inlining
step in `StoryRepository` a lookup, not a tree walk: build a uuid-keyed map
from `rels` once per response, and every `?string` property already holding a
uuid can be resolved against it without touching `content`. The design's
worst-case assumption (`StoryRepository` must be ready to restore a uuid
"whatever the response shape") turns out not to be exercised in practice —
`content` never needs restoring, because the CDA never touched it.

**Schema-type note, recorded but not acted on.** The field that made this
answerable is typed `option` with `source: internal_stories`, not `"type":
"story"`. A space-wide scan (every component: `config`, `hero`, `nav_item`,
`page`, `post`, `richtext_section`, `test`) found no field literally typed
`story` anywhere, and exactly one relation-capable field in total:
`test.author`. `src/Schema/FieldTypeMapper.php` (`STRING_TYPES`, line ~32) and
the README's field-mapping table both document a `story` → `?string` row;
this space's evidence suggests that row may describe a Storyblok field type
that does not exist, at least not under that schema name. Behaviour is
unaffected either way: `option` is already in `FieldTypeMapper::STRING_TYPES`
alongside `story`, so a single reference field maps to `?string` regardless of
which of the two type names is the real one, and a multi-reference field
(schema type `options`) already maps to `string[]` via the separate `options`
case in `FieldTypeMapper::map()`. This space has no `options`-typed field
(the scan above covered `option`, `options` and `story` together and found
only the one `option` field), so whether a multi-reference field is really
`options` + `source: internal_stories` is **unverified**, not confirmed — it
is Storyblok's documented convention, not something this probe observed.
This is pre-existing library code, outside this plan's scope; flagged here for
the final whole-branch review to triage, not fixed as part of this task.

**2. The semantics of `cv` — answered.** `cv` is an integer
(`1787844259`, matching a Unix timestamp for the current date) present at the
root of every response, alongside `rels` and `links` — both always present,
empty when nothing resolves. Root keys of a listing response: `stories, cv,
rels, links`. Root keys of a single-story response: `story, cv, rels, links`.
Passing no `cv`, a stale `cv` (`1`), a future/bogus `cv` (`9999999999`), and the
real value produced identical status (`200`), identical body content, and the
same server-computed `cv` echoed back every time — Storyblok does not read the
client-supplied value for filtering or version-pinning. The only observable
difference was at the CDN edge: a `cv` value not seen before returned
`X-Cache: Miss from cloudfront`; a previously-seen value (including no `cv` at
all) returned `X-Cache: RefreshHit from cloudfront`. So `cv` is a client-side
CDN cache-busting key, not a server-enforced content version. "Expose, do not
manage" is confirmed sufficient: there is nothing to validate or reconcile,
because Storyblok itself does neither.

**Story envelope keys** (the keys of `story` inside a single-story response,
distinct from the response root keys above): `name, created_at, published_at,
updated_at, id, uuid, content, slug, full_slug, sort_by_date, position,
tag_list, is_startpage, parent_id, meta_data, group_id, first_published_at,
release_id, lang, path, alternates, default_full_slug, translated_slugs`.
`StoryTransfer`'s constructor (see below) models `uuid`, `slug`, `fullSlug`,
`content`, `name`, `lang`, `publishedAt`, `firstPublishedAt`, `createdAt`,
`parentId`, `tagList` and `translatedSlugs`; it deliberately leaves
`updated_at`, `id`, `sort_by_date`, `position`, `is_startpage`, `meta_data`,
`group_id`, `release_id`, `path`, `alternates` and `default_full_slug`
unmodeled — a later reader weighing whether to add one of those should treat
this list, not a re-probe, as the source of truth for what the CDA offers.

Two more findings came out of the same probe, useful to later stages though
outside the two questions above:

- **Pagination headers arrive lowercase on the wire**: `total` and
  `per-page`, not `Total` and `Per-Page`. (Confirmed the hard way — the probe
  script's own header lookup used the capitalised names against Guzzle's
  `getHeaders()`, whose returned array keys are case-sensitive, and printed
  `null`; a raw header dump showed `total: 6` and `per-page: 2` for a
  `per_page=2` request against 6 stories.) This needs no workaround: PSR-7's
  `getHeaderLine()` and `getHeader()` are case-insensitive by spec, and that is
  what `ContentResponse` reads through — only a raw `getHeaders()` array would
  need the lowercase name.
- **404** on a missing slug: status `404`, body `["This record could not be
  found"]` — a JSON array containing one string, not an object with an
  `error` key.
- **`filter_query[...]` with percent-encoded brackets is accepted**:
  `filter_query[component][in]=page` returned `200` with the 2 stories whose
  `content.component` is `page`.

The design below no longer needs to assume the worst case for (1): the probe
confirmed `resolve_relations` never rewrites `content`, so the de-inlining step
in `StoryRepository` is a uuid-keyed lookup against `rels`, not a tree walk
that has to detect and undo an inline substitution. Task 6 can build directly
against this shape without a further re-probe.

## Implementation order

The scope is wide enough that a single undifferentiated plan would be hard to
review, so it has a spine. Each stage is independently verifiable.

1. **CDA probe.** Answer both questions under "Verification prerequisite" and
   record the findings in this document. Nothing else starts before it.
2. **Extract `ComponentClassResolver`.** Behaviour neutral; the gate is the
   existing hydrator tests passing unedited.
3. **Transport.** `ContentClient`, `StoryblokContentClient`, `ContentResponse`,
   `ContentOptions`, `Version` — including token redaction and sorted query
   serialisation.
4. **Single story.** `StoryTransfer`, `RelationMap`, `RelationMapFactory`,
   `StoryRepository::bySlug()` and `byUuid()`. This is the core; stages 5 and 6
   are additive once it stands. The probe in stage 1 removed the de-inlining
   step this stage originally carried: `resolve_relations` never rewrites
   `content`, so resolving a relation is a uuid-keyed lookup against the
   response root's `rels`, and no tree walk is built.
5. **Listing.** `StoryQuery`, `StoryList`, header-based pagination.
6. **Links and datasources.** Two small repositories and their entry types.
7. **Factory, smoke test, docs.** `StoryblokContent`; delete
   `tools/StoryFetcher.php` and `tools/Story.php` and rewire the smoke test;
   README and `.env.example`.

Stage 1 may invalidate part of stage 4. That is the point of doing it first.

## Components

New namespaces: `Client\` gains the content client, and `Content\` holds
everything else. Content-layer types do not live in `Transfers\`, which is
reserved for transfers referenced by generated code.

### `Client\ContentClient`

The interface the repositories depend on, and the cache seam. A single method —
`get(string $path, array $query): ContentResponse` — because a path plus a query
map already describes every one of the four resources, and one method is the
smallest thing a decorator has to wrap.

Defining this interface is the whole of the caching provision. An application
writes a decorator; the library ships none.

### `Client\StoryblokContentClient`

Transport for the CDA. Sibling of `StoryblokManagementClient`, not a subclass.

- Base URI defaults to `https://api.storyblok.com/v2/`, configurable for the US,
  AP, CA and CN regions.
- The token goes in the query string, not a header.
- No space id: the delivery token identifies the space.
- Query parameters are serialised **sorted by key**. This is what makes a
  decorator's cache key deterministic, and it is why no query object needs a
  `cacheKey()` method of its own.
- Reuses `StoryblokApiException`.

**The token must be redacted from every exception message.** This is the one
place the new client cannot copy the existing one.
`StoryblokManagementClient::assertSuccessful()` interpolates the URI into the
message (`src/Client/StoryblokManagementClient.php:122`), which is harmless when
the token is in a header. Here the URI *contains* the delivery token, so the same
code would write credentials into error messages, logs and crash reports. Every
message that mentions a URI passes through redaction first.

### `Client\ContentResponse`

The decoded body plus the response metadata the body does not carry.

The listing endpoint returns its total and page size in the `Total` and
`Per-Page` **HTTP headers**, so a client returning only the decoded body cannot
support pagination and would push header handling out to the repositories. This
type keeps that inside the transport boundary without exposing a raw
`ResponseInterface`.

### `Content\Version`

`enum Version: string` with `Published = 'published'` and `Draft = 'draft'`, so
the cases carry the query-parameter values. Draft access is not
optional in a CMS integration; making it an enum keeps the string out of call
sites.

### `Content\ContentOptions`

Immutable, with withers. Holds what every resource accepts: `version`,
`language`, `resolveRelations`, `cv`.

These are not listing concerns — a links tree is also fetched per version and per
language — so they live in their own type rather than inside `StoryQuery`.

`cv` is a settable field, populated by the caller. The client neither tracks it
across responses nor calls the space endpoint to refresh it: deciding when `cv`
changes *is* the invalidation policy, which is out of scope. Exposing it without
managing it is correct under either answer from the verification probe.

### `Content\StoryQuery`

Immutable, composes `ContentOptions`, and adds the listing parameters:
`startsWith`, `filterQuery`, `sortBy`, `byUuids`, `excludingFields`, `page`,
`perPage`.

The listing endpoint takes a dozen-odd parameters. A method with a dozen optional
arguments is unusable and unextendable; a value object also turns the parameters
into a sortable map, which is what the client's deterministic serialisation
needs.

### `Content\StoryTransfer`

`final class` with promoted `private readonly` properties, generic over its
content type. **It deliberately does not extend `AbstractTransfer`.**

Not `readonly class`: that is PHP 8.2, and `composer.json` requires `^8.1`.
Readonly *properties* are 8.1, so immutability costs nothing here — but the
class-level modifier would silently drop the library's minimum PHP version, and
the same constraint applies to every new type in this design.

The bundled transfers extend it for two concrete reasons: generated code
references them as property types, and they are hydrated by `fromArray()`. The
envelope is neither. It is never a property of a generated class, and it is
*constructed* by the repository from a fixed, known response shape. The
constraint that forces the others to inherit does not apply.

Not inheriting buys two things. `uuid`, `slug`, `fullSlug` and `content` become
**non-nullable**, because the CDA guarantees them — where any `AbstractTransfer`
must make every property nullable with a default, for the reason documented on
`AssetTransfer`. And `@template T of AbstractTransfer` works cleanly, giving the
`getContent(): T` the optional-declaration decision requires; with inheritance
the generic would have to thread through a static, inherited `fromArray()`.

```php
/** @template T of AbstractTransfer */
final class StoryTransfer
{
    /**
     * @param T $content
     * @param list<string> $tagList
     * @param array<string, mixed> $translatedSlugs
     */
    public function __construct(
        private string $uuid,
        private string $slug,
        private string $fullSlug,
        private AbstractTransfer $content,
        private ?string $name,
        private ?string $lang,
        private ?string $publishedAt,
        private ?string $firstPublishedAt,
        private ?string $createdAt,
        private ?int $parentId,
        private array $tagList,
        private array $translatedSlugs,
        private RelationMap $relations,
    ) {
    }

    /** @return T */
    public function getContent(): AbstractTransfer;

    public function getUuid(): string;
    public function getSlug(): string;
    public function getFullSlug(): string;
    // … one getter per remaining property.

    /** @return AbstractTransfer|array<mixed>|null */
    public function getRelation(?string $uuid): AbstractTransfer|array|null;
}
```

Getters rather than public readonly properties, for two reasons: every other
transfer in the package exposes getters, and `@return T` on a method is the
generic form PHPStan handles most reliably.

`getRelation()` takes a nullable uuid, because the property it is fed from is
`?string`, and returns `null` for a uuid that was never resolved. It returns a
raw array for a related story whose component has no generated class, mirroring
how the hydrator and `RichtextTransfer::getBloks()` already degrade rather than
throw.

### `Content\RelationMap`

Immutable map from story uuid to the hydrated relation, with `private readonly`
properties as above.

**Shared, not copied.** In a listing the resolved relations are common to every
story in the response, so `StoryList` holds one `RelationMap` and passes the
*same instance* to each `StoryTransfer`. Being readonly there is no divergence
risk, and identity is assertable in a test.

### `Content\StoryList`

The listing result: `list<StoryTransfer>`, plus `total`, `page` and `perPage`
read from the response headers, and the shared `RelationMap`.

### `Content\StoryRepository`

```php
/**
 * @template T of AbstractTransfer
 * @param class-string<T>|null $expected
 * @return StoryTransfer<T>|null
 */
bySlug(string $slug, ?string $expected = null, ?ContentOptions $options = null): ?StoryTransfer
byUuid(string $uuid, ?string $expected = null, ?ContentOptions $options = null): ?StoryTransfer

findBy(StoryQuery $query): StoryList
```

`findBy()` never returns `null`: a query that matches nothing returns an empty
`StoryList` with `total` of zero. Only a single-story lookup has a "does not
exist" case.

Per call: build options, ask the client, build the `RelationMap` from the
response's `rels` array, read `content.component`, resolve the class through
`ComponentClassResolver`, hydrate through `StoryblokHydrator`, construct the
envelope. The map is built once per *response* and shared by every story in it,
because that is where `rels` lives.

### `Content\LinkRepository` and `Content\LinkEntry`

`GET /cdn/links`. Returns `list<LinkEntry>` — navigation and sitemap structure
without fetching content. Its payload is neither a story nor a link *field*, so
it gets its own immutable type rather than reusing `LinkTransfer`.

### `Content\DatasourceRepository` and `Content\DatasourceEntry`

`GET /cdn/datasource_entries`, by datasource slug and optional dimension.
Returns `list<DatasourceEntry>` of name/value pairs. Read only; no codegen.

### `Content\StoryblokContent`

The factory, for consumers without a DI container. A named constructor reading
the environment, another taking explicit arguments, and `stories()`, `links()`
and `datasources()`. No logic beyond wiring.

### `Hydration\ComponentClassResolver`

Extracted from `StoryblokHydrator::resolveComponentClass()`
(`src/Hydration/StoryblokHydrator.php:108`), which is private and is exactly what
`StoryRepository` needs to infer the target class. Injected into both, with a
constructor default, following how `FieldTypeMapper` takes
`PropertyNameNormalizer`.

This is the only change to existing code, and it must be behaviourally neutral.

## Relation resolution

`resolveRelations` on `ContentOptions` names the fields to resolve, in
Storyblok's `component.field` form.

The probe recorded in the Answers section settled how the CDA delivers them, and
it is not what this design first assumed: `resolve_relations` leaves `content`
untouched — the field keeps the plain uuid string it always held — and returns
what it resolved in a `rels` array at the response root, addressable by that
same uuid.

So resolution is a lookup, not a tree walk, and nothing needs restoring.
`RelationMapFactory` turns `rels` into a uuid-keyed `RelationMap` once per
response, and `StoryRepository` hands the same instance to every story in it.
Each related story's content is hydrated through the same
`ComponentClassResolver`, and stays a raw array when its component has no
generated class — as does a relation with no `content` at all, such as a folder.

Two limitations follow, and both belong in the README beside the existing ones:

- **Resolution is one level deep.** Relations of relations are not resolved.
  Storyblok documents this, and the probe confirms it rather than taking it on
  trust, since it goes into the README as our own limitation.
- **Reaching the map needs the envelope.** A blok deep in the tree holds a uuid
  but has no path back to the `RelationMap`, so the caller must keep the envelope
  in hand. This is the same roughness `RichtextTransfer` already has, and it is
  the price of keeping the property a `?string`.

## Configuration

The CDA takes no space id, so none of the existing variables carry over.

| Variable | Required | Default | Purpose |
|---|---|---|---|
| `STORYBLOK_DELIVERY_TOKEN` | yes | — | Preview or public token. Not the management token |
| `STORYBLOK_CONTENT_BASE_URI` | no | `https://api.storyblok.com/v2/` | Region: US, AP, CA, CN |
| `STORYBLOK_DEFAULT_VERSION` | no | `published` | `draft` in preview environments |

`.env.example` already warns that the management token is not the delivery token;
that note now points at a variable that exists.

## Error handling

| Situation | Result |
|---|---|
| Story does not exist (404) | `null` |
| Root component has no generated class | `Content\UnresolvableComponentException` |
| Declared class does not match the component | `Content\UnexpectedComponentException` |
| Payload is not a story (no `content`, no `uuid`) | `StoryblokApiException` |
| 401, 429, 5xx, network, invalid JSON | `StoryblokApiException`, URI redacted |
| Nested blok with no generated class | Raw array, unchanged from today |

**404 is data, not a fault.** `bySlug()` returns `null`. "No such story" is a
legitimate answer and the router asking the question is the hottest path in a
consuming application; using exceptions for control flow there is the wrong
trade. Every other status is a defect and throws.

**A missing class at the root throws, and that is deliberately asymmetric** with
the hydrator, which leaves unknown nested bloks as raw arrays so an editor cannot
break a page. The asymmetry is justified: an unknown blok is one part of a page
and degrading is right, whereas the root content *is* the object the caller asked
for, and `StoryTransfer::$content` is non-nullable by design.

`UnexpectedComponentException` names the component that actually arrived, so a
mismatch reports itself instead of surfacing as a `TypeError` from inside the
hydrator.

## Consequences

- The generator, the definition files and all generated output are untouched.
- `StoryblokHydrator` keeps its behaviour; only its component resolution moves.
- `tools/StoryFetcher.php` and `tools/Story.php` are deleted and the smoke test
  rewired to `StoryRepository`. The smoke test stops maintaining a parallel
  implementation against the wrong API, and starts exercising shipped code.
- The README gains a content section and two more documented limitations.

## Testing

Written test-first.

Unit tests use Guzzle's `MockHandler`, as `StoryblokManagementClientTest` and the
existing integration test already do. Pinned:

- query parameters serialise sorted by key
- the delivery token never appears in any exception message
- 404 yields `null`; 401, 429 and 5xx throw `StoryblokApiException`
- `Total` and `Per-Page` are read from headers, not the body
- a root component with no class throws `UnresolvableComponentException`
- a declared class that does not match throws `UnexpectedComponentException`,
  naming the component received
- the resolved story is reachable through the map by the uuid the property still
  holds, which `content` never had rewritten
- a related story with no generated class stays a raw array
- `assertSame()` on the `RelationMap` of two stories from one listing
- `LinkEntry` and `DatasourceEntry` mapping, including an absent dimension

Integration tests follow the established pattern: generate real classes into a
temp directory, then drive the repository against them with `MockHandler`, so the
whole chain — response, class resolution, hydration, envelope — runs against real
generator output rather than hand-written fixtures.

**The existing hydrator tests must pass without a single edit** after
`ComponentClassResolver` is extracted. If any of them needs touching, the
extraction was not neutral and the design is wrong.

`phpunit.xml` sets `failOnWarning`, `failOnNotice` and `failOnDeprecation` to
`true`, so any PHP notice raised while hydrating fails the suite. The type guards
in the new constructors are not optional.

The CDA probe from "Verification prerequisite" becomes a permanent path in
`tools/smoke-test.php`, covering a real fetch, a real `resolve_relations`
response, and a listing with pagination.

## Documentation

- A README section on reading content: the factory, the three repositories,
  `ContentOptions`, `StoryQuery`, and the inferred-versus-declared target class.
- A caching decorator example, in the README only — no cache code in the package.
- Two new entries under "Limitations": one-level relation resolution, and needing
  the envelope to reach the `RelationMap`.
- The configuration table gains the three new variables, and `.env.example` the
  matching keys.

## Out of scope

- any cache implementation, invalidation policy or webhook handling
- generating PHP enums from datasource entries — its own cycle
- multi-level relation resolution
- the Storyblok Bridge and visual-editor JavaScript
- framework bundles: Symfony, Laravel
- writing to Storyblok; every call here is a read
- rendering richtext, unchanged from the richtext design
- pruning stale definitions and the CI schema-drift check, both still open
- pagination of the Management API components listing, unverified and unrelated
