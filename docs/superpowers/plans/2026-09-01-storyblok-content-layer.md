# Storyblok Content Layer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the library the ability to read content from the Storyblok Content Delivery API and return hydrated transfer graphs, so a consuming application no longer hand-writes the fetch layer.

**Architecture:** One HTTP client for the CDA behind a `ContentClient` interface (the cache seam), and one repository per resource — stories, links, datasources. Resolved relations travel in a `RelationMap` beside the content rather than inside it, keeping the generated `story` property a `?string` uuid. The target transfer class is inferred from `content.component`, with an optional declared class that asserts the match.

**Tech Stack:** PHP 8.1, Guzzle 7, PHPUnit 10.5, PHPStan level 8, PHP_CodeSniffer (PSR-12), Docker Compose.

**Spec:** `docs/superpowers/specs/2026-09-01-storyblok-content-layer-design.md`

## Global Constraints

- **PHP 8.1 floor.** `composer.json` requires `^8.1`. `readonly class` is 8.2 — **never use it**. Readonly *properties* and promoted `private readonly` constructor parameters are 8.1 and are the idiom for every new immutable type here. `new` in parameter default values is 8.1 and is already used in `FieldTypeMapper`.
- **Every PHP command runs in Docker.** Never invoke a local `php`. Use `make test`, `make stan`, `make cs`, or `docker compose run --rm php <cmd>`.
- **PHPStan level 8** over `src` and `tests`, with `treatPhpDocTypesAsCertain: false`. Every array needs a value-typed docblock.
- **PSR-12, 120-character soft line limit**, 140 absolute (`phpcs.xml`).
- **`phpunit.xml` sets `failOnWarning`, `failOnNotice` and `failOnDeprecation` to `true`.** Any PHP notice raised during a test fails the suite. Type guards are not optional.
- **The delivery token must never appear in an exception message, ever.** The CDA carries it in the query string.
- **Test style:** `final class XxxTest extends TestCase`, methods named `testSomeBehaviour(): void`, assertions via `self::assertSame(...)`. No PHPUnit attributes — the existing suite uses none.
- **Namespaces:** `Tlab\StoryblokTransfers\` maps to `src/`, `Tlab\StoryblokTransfers\Tests\` to `tests/`.
- **Nothing in this plan changes the generator, the definition JSON format, or any generated output.** The single change to existing library code is Task 2.

## File Structure

**New — `src/Client/` (transport):**

| File | Responsibility |
|---|---|
| `ContentClient.php` | Interface with one method. The cache seam an application decorates |
| `StoryblokContentClient.php` | CDA transport: sorted query, token in query string, token redaction, header extraction |
| `ContentResponse.php` | Decoded body plus `Total` / `Per-Page` header values |
| `ResourceNotFoundException.php` | HTTP 404. Its own type, because `StoryblokApiException` is `final` |

**New — `src/Content/` (resources):**

| File | Responsibility |
|---|---|
| `Version.php` | `enum Version: string` — `published` / `draft` |
| `ContentOptions.php` | Parameters every resource accepts, plus `toQuery()` |
| `StoryQuery.php` | Listing parameters, composes `ContentOptions` |
| `RelationMap.php` | uuid → hydrated relation, shared across a listing |
| `StoryTransfer.php` | The story envelope, generic over its content type |
| `StoryList.php` | A page of stories plus totals and the shared `RelationMap` |
| `RelationMapFactory.php` | Turns a response root's `rels` array into a `RelationMap` |
| `StoryMapper.php` | Raw story arrays → envelopes, owning the shared-map merge |
| `StoryRepository.php` | `bySlug()`, `byUuid()`, `findBy()` |
| `LinkEntry.php` | One entry of the links tree |
| `LinkRepository.php` | `GET /cdn/links` |
| `DatasourceEntry.php` | One datasource entry |
| `DatasourceRepository.php` | `GET /cdn/datasource_entries` |
| `StoryblokContent.php` | Factory wiring the three repositories |
| `UnresolvableComponentException.php` | Root component has no generated class |
| `UnexpectedComponentException.php` | Declared class does not match the component |

One decomposition here is the plan's, not the spec's: the spec has
`StoryRepository` doing the whole per-call sequence itself, while this plan pulls
the raw-story-to-envelope half out into `StoryMapper`. The reason is Task 9 — a
listing maps many stories against one shared `RelationMap`, and keeping that
mapping separate from the repository's fetch-and-unwrap work lets both the
single-story and listing paths use it without duplication.

**New — `src/Hydration/`:**

| File | Responsibility |
|---|---|
| `ComponentClassResolver.php` | Component name → transfer FQCN. Extracted from `StoryblokHydrator` |

**Modified:**

| File | Change |
|---|---|
| `src/Hydration/StoryblokHydrator.php` | Delegates component resolution to the new resolver |
| `tools/smoke-test.php`, `tools/SmokeTest.php` | Use `StoryRepository` instead of the tools' own fetcher |
| `README.md`, `.env.example` | Document the content layer |

**Deleted:**

| File | Why |
|---|---|
| `tools/StoryFetcher.php` | Reads content through the Management API; superseded |
| `tools/Story.php` | Its envelope is replaced by `StoryTransfer` |

---

### Task 1: CDA probe — answer the two empirical questions

This task writes **no library code**. It answers questions whose answers change Task 6, then records them in the spec. Nothing else starts before it.

**Files:**
- Create: `tools/cda-probe.php` (throwaway; deleted at the end of this task)
- Modify: `docs/superpowers/specs/2026-09-01-storyblok-content-layer-design.md` — the "Verification prerequisite" section

**Interfaces:**
- Consumes: nothing
- Produces: recorded findings in the spec. Task 6 reads them

**Prerequisite:** a CDA delivery token for the smoke-test space. `.env` currently holds only `STORYBLOK_MANAGEMENT_TOKEN`; the probe needs a **preview** token from Storyblok's Settings → Access Tokens. Add it to `.env` as `STORYBLOK_DELIVERY_TOKEN` (`.env` is git-ignored). If no such token can be obtained, **stop and report** — the rest of the plan is guesswork without it.

- [ ] **Step 1: Write the probe script**

```php
<?php

declare(strict_types=1);

// Throwaway probe. Deleted at the end of Task 1; its findings live in the spec.

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/DotEnvFile.php';

$env = DotEnvFile::parse(__DIR__ . '/../.env');
$token = getenv('STORYBLOK_DELIVERY_TOKEN') ?: ($env['STORYBLOK_DELIVERY_TOKEN'] ?? '');

if ($token === '') {
    fwrite(STDERR, "STORYBLOK_DELIVERY_TOKEN is required in .env or the environment.\n");
    exit(1);
}

$http = new GuzzleHttp\Client();

$get = static function (string $path, array $query) use ($http, $token): array {
    $query['token'] = $token;
    $response = $http->request('GET', 'https://api.storyblok.com/v2/' . $path, [
        'query' => $query,
        'http_errors' => false,
    ]);

    return [
        'status' => $response->getStatusCode(),
        'headers' => $response->getHeaders(),
        'body' => json_decode((string) $response->getBody(), true),
    ];
};

// 1. A listing, to find a story and to see the pagination headers.
$list = $get('cdn/stories', ['version' => 'draft', 'per_page' => 2]);
echo "=== LISTING ===\n";
echo 'status: ', $list['status'], "\n";
echo 'Total header: ', json_encode($list['headers']['Total'] ?? null), "\n";
echo 'Per-Page header: ', json_encode($list['headers']['Per-Page'] ?? null), "\n";
echo 'root keys: ', implode(', ', array_keys((array) $list['body'])), "\n";
echo 'cv: ', json_encode($list['body']['cv'] ?? null), "\n\n";

// 2. One story, unresolved, to record the envelope keys.
$slug = $list['body']['stories'][0]['full_slug'] ?? null;
echo "=== ONE STORY ($slug) ===\n";
$one = $get('cdn/stories/' . $slug, ['version' => 'draft']);
echo 'envelope keys: ', implode(', ', array_keys((array) ($one['body']['story'] ?? []))), "\n\n";

// 3. The same story WITH resolve_relations, to see where the relation lands.
//    Replace the value below with a real "component.field" of a story field.
$relation = getenv('PROBE_RELATION') ?: '';
echo "=== RESOLVE_RELATIONS ($relation) ===\n";
$resolved = $get('cdn/stories/' . $slug, [
    'version' => 'draft',
    'resolve_relations' => $relation,
]);
echo 'root keys: ', implode(', ', array_keys((array) $resolved['body'])), "\n";
echo 'has rels key: ', isset($resolved['body']['rels']) ? 'YES' : 'no', "\n";
echo "content (look for a uuid replaced by an object):\n";
echo json_encode($resolved['body']['story']['content'] ?? null, JSON_PRETTY_PRINT), "\n\n";

// 4. A missing story, to confirm the 404 status and body.
echo "=== MISSING STORY ===\n";
$missing = $get('cdn/stories/definitely-not-a-real-slug-' . bin2hex(random_bytes(4)), []);
echo 'status: ', $missing['status'], "\n";
echo 'body: ', json_encode($missing['body']), "\n\n";

// 5. filter_query bracket encoding, to confirm Storyblok accepts %5B.
echo "=== FILTER_QUERY ===\n";
$filtered = $get('cdn/stories', [
    'version' => 'draft',
    'filter_query[component][in]' => 'page',
]);
echo 'status: ', $filtered['status'], "\n";
echo 'stories returned: ', count((array) ($filtered['body']['stories'] ?? [])), "\n";
```

- [ ] **Step 2: Run the probe**

Run: `docker compose run --rm php php tools/cda-probe.php`

If the space has no `story`-type field, set `PROBE_RELATION` to a real `component.field` pair first — inspect `tools/.output/definitions/*.json` for a property the generator mapped to a nullable string, and check that field's type in Storyblok. If the space genuinely has no relation field, create one on a test component and point a story at another story; without it, question 1 cannot be answered.

Expected: sections 1, 2, 4 and 5 print. Record what section 3 shows.

- [ ] **Step 3: Record the findings in the spec**

Replace the "Verification prerequisite" section's closing paragraph with the actual answers. Write down, verbatim from the probe output:

1. Whether the resolved story appeared **inline** in `content` (the uuid replaced by an object), in a root **`rels`** key, or both.
2. The exact `cv` value shape, and the root keys of a listing response.
3. The pagination header names and casing as they actually arrived.
4. The 404 status code and body.
5. Whether `filter_query[...]` with percent-encoded brackets was accepted.

Keep the two numbered questions and add an **Answers** subsection beneath them. Do not delete the questions — the record of what was uncertain is part of the document.

- [ ] **Step 4: Delete the probe**

```bash
rm tools/cda-probe.php
```

The probe is throwaway. Task 14 adds a permanent CDA path to the smoke test; this script is not it.

- [ ] **Step 5: Commit**

```bash
git add docs/superpowers/specs/2026-09-01-storyblok-content-layer-design.md
git commit -m "Record what the CDA actually returns for relations and pagination"
```

---

### Task 2: Extract ComponentClassResolver

**Files:**
- Create: `src/Hydration/ComponentClassResolver.php`
- Create: `tests/Unit/ComponentClassResolverTest.php`
- Modify: `src/Hydration/StoryblokHydrator.php:26-37` (constructor and fields), `:95` (call site), `:108-125` (the method being removed)

**Interfaces:**
- Consumes: `Tlab\StoryblokTransfers\Schema\ComponentNameFormatter` (existing, `toTransferName('product_core')` returns `'ProductCore'` with **no** `Transfer` suffix)
- Produces:
  - `ComponentClassResolver::__construct(string $namespace)`
  - `ComponentClassResolver::resolve(string $componentName): ?string` — returns a `class-string<AbstractTransfer>` or null
  - `ComponentClassResolver::resolveFromContent(array $content): ?string` — reads `$content['component']`
  - `StoryblokHydrator::__construct(string $namespace, ?ComponentClassResolver $resolver = null)`

**The gate for this task:** every existing test passes **without a single edit**. If any existing test needs touching, the extraction was not behaviour-neutral and must be redone.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/ComponentClassResolverTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tlab\StoryblokTransfers\Hydration\ComponentClassResolver;
use Tlab\StoryblokTransfers\Tests\Fixture\NestedFixtureTransfer;

final class ComponentClassResolverTest extends TestCase
{
    private const FIXTURE_NAMESPACE = 'Tlab\\StoryblokTransfers\\Tests\\Fixture';

    public function testResolvesAComponentNameToItsTransferClass(): void
    {
        $resolver = new ComponentClassResolver(self::FIXTURE_NAMESPACE);

        self::assertSame(NestedFixtureTransfer::class, $resolver->resolve('nested_fixture'));
    }

    public function testAppliesTheSharedNameFormatting(): void
    {
        $resolver = new ComponentClassResolver(self::FIXTURE_NAMESPACE);

        // The formatter turns separators into PascalCase, so all three spellings
        // reach the same class the generator would have written.
        self::assertSame(NestedFixtureTransfer::class, $resolver->resolve('nested-fixture'));
        self::assertSame(NestedFixtureTransfer::class, $resolver->resolve('nested.fixture'));
    }

    public function testToleratesATrailingSeparatorOnTheNamespace(): void
    {
        $resolver = new ComponentClassResolver(self::FIXTURE_NAMESPACE . '\\');

        self::assertSame(NestedFixtureTransfer::class, $resolver->resolve('nested_fixture'));
    }

    public function testReturnsNullForAComponentWithNoGeneratedClass(): void
    {
        $resolver = new ComponentClassResolver(self::FIXTURE_NAMESPACE);

        self::assertNull($resolver->resolve('no_such_component'));
    }

    public function testReturnsNullForAClassThatIsNotATransfer(): void
    {
        // TempDirectory is a real class in the Tests namespace but not a transfer.
        $resolver = new ComponentClassResolver('Tlab\\StoryblokTransfers\\Tests');

        self::assertNull($resolver->resolve('temp_directory'));
    }

    public function testReadsTheComponentKeyOutOfAContentArray(): void
    {
        $resolver = new ComponentClassResolver(self::FIXTURE_NAMESPACE);

        self::assertSame(
            NestedFixtureTransfer::class,
            $resolver->resolveFromContent(['component' => 'nested_fixture', 'headline' => 'x'])
        );
    }

    public function testReturnsNullWhenTheContentCarriesNoComponent(): void
    {
        $resolver = new ComponentClassResolver(self::FIXTURE_NAMESPACE);

        self::assertNull($resolver->resolveFromContent(['headline' => 'x']));
        self::assertNull($resolver->resolveFromContent(['component' => '']));
        self::assertNull($resolver->resolveFromContent(['component' => 42]));
    }
}
```

Note: `testReturnsNullForAClassThatIsNotATransfer` relies on `Tlab\StoryblokTransfers\Tests\TempDirectory` being a trait, not a class — `class_exists()` returns `false` for a trait, so this case exercises the `class_exists` guard. That is fine: the assertion is that a non-transfer name resolves to null, and both guards produce that.

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose run --rm php vendor/bin/phpunit --filter ComponentClassResolverTest`
Expected: FAIL — `Class "Tlab\StoryblokTransfers\Hydration\ComponentClassResolver" not found`

- [ ] **Step 3: Write the resolver**

Create `src/Hydration/ComponentClassResolver.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Hydration;

use Tlab\StoryblokTransfers\Schema\ComponentNameFormatter;
use Tlab\TransferObjects\AbstractTransfer;

/**
 * Maps a Storyblok component name onto the transfer class the generator wrote
 * for it.
 *
 * Extracted from StoryblokHydrator, which needs it for nested bloks, so the
 * content layer can use the same mapping for a story's root component. Both
 * must agree, or one of them looks up classes the other never writes.
 */
final class ComponentClassResolver
{
    private readonly ComponentNameFormatter $nameFormatter;

    /**
     * @param string $namespace Namespace the generated transfers live in,
     *                          e.g. 'App\DataTransferObjects'.
     */
    public function __construct(
        private readonly string $namespace,
    ) {
        $this->nameFormatter = new ComponentNameFormatter();
    }

    /**
     * @param array<string, mixed> $content A Storyblok content array.
     *
     * @return class-string<AbstractTransfer>|null
     */
    public function resolveFromContent(array $content): ?string
    {
        $component = $content['component'] ?? null;

        return is_string($component) ? $this->resolve($component) : null;
    }

    /**
     * @return class-string<AbstractTransfer>|null Null when no generated class
     *                                             matches, which is never an
     *                                             error at this level.
     */
    public function resolve(string $componentName): ?string
    {
        if ($componentName === '') {
            return null;
        }

        $candidate = rtrim($this->namespace, '\\') . '\\'
            . $this->nameFormatter->toTransferName($componentName) . 'Transfer';

        if (!class_exists($candidate) || !is_subclass_of($candidate, AbstractTransfer::class)) {
            return null;
        }

        /** @var class-string<AbstractTransfer> $candidate */
        return $candidate;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `docker compose run --rm php vendor/bin/phpunit --filter ComponentClassResolverTest`
Expected: PASS

- [ ] **Step 5: Delegate from the hydrator**

In `src/Hydration/StoryblokHydrator.php`, remove the `ComponentNameFormatter` field and import, drop the `$namespace` promotion, accept an optional resolver, and replace the private method with a delegation.

Replace the field declarations and constructor:

```php
    private readonly PropertyTypeResolver $typeResolver;

    private readonly PropertyNameNormalizer $nameNormalizer;

    private readonly ComponentClassResolver $componentClassResolver;

    /**
     * @param string $namespace Namespace the generated transfers live in,
     *                          e.g. 'App\DataTransferObjects'.
     */
    public function __construct(
        string $namespace,
        ?ComponentClassResolver $componentClassResolver = null,
    ) {
        $this->typeResolver = new PropertyTypeResolver();
        $this->nameNormalizer = new PropertyNameNormalizer();
        $this->componentClassResolver = $componentClassResolver
            ?? new ComponentClassResolver($namespace);
    }
```

Replace the whole `resolveComponentClass()` method body with a delegation:

```php
    /**
     * @param array<string, mixed> $blok
     *
     * @return class-string|null
     */
    private function resolveComponentClass(array $blok): ?string
    {
        return $this->componentClassResolver->resolveFromContent($blok);
    }
```

Then remove the now-unused `use Tlab\StoryblokTransfers\Schema\ComponentNameFormatter;` import.

- [ ] **Step 6: Run the whole suite to prove the extraction is neutral**

Run: `make test`
Expected: PASS, with **no test file edited**. Confirm with `git status --short tests/` — it must list only the new `ComponentClassResolverTest.php`.

- [ ] **Step 7: Run the linters**

Run: `make stan && make cs`
Expected: no errors.

- [ ] **Step 8: Commit**

```bash
git add src/Hydration/ComponentClassResolver.php src/Hydration/StoryblokHydrator.php tests/Unit/ComponentClassResolverTest.php
git commit -m "Extract component-to-class resolution out of the hydrator"
```

---

### Task 3: ContentResponse, Version and ContentOptions

Three value objects with no I/O. They exist before the client because the client's signature names them.

**Files:**
- Create: `src/Client/ContentResponse.php`, `src/Content/Version.php`, `src/Content/ContentOptions.php`
- Create: `tests/Unit/ContentOptionsTest.php`

**Interfaces:**
- Consumes: nothing
- Produces:
  - `ContentResponse::__construct(array $body, ?int $total = null, ?int $perPage = null)` with public readonly `$body`, `$total`, `$perPage`
  - `enum Version: string { Published = 'published'; Draft = 'draft'; }`
  - `ContentOptions::__construct(Version $version = Version::Published, ?string $language = null, array $resolveRelations = [], ?string $cv = null)`, public readonly properties, `withVersion()`, `withLanguage()`, `withResolveRelations()`, `withCv()`, and `toQuery(): array<string, string>`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/ContentOptionsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tlab\StoryblokTransfers\Content\ContentOptions;
use Tlab\StoryblokTransfers\Content\Version;

final class ContentOptionsTest extends TestCase
{
    public function testDefaultsToThePublishedVersionAndNothingElse(): void
    {
        self::assertSame(['version' => 'published'], (new ContentOptions())->toQuery());
    }

    public function testEmitsTheDraftVersion(): void
    {
        $options = new ContentOptions(Version::Draft);

        self::assertSame(['version' => 'draft'], $options->toQuery());
    }

    public function testEmitsTheLanguageWhenSet(): void
    {
        $options = (new ContentOptions())->withLanguage('de');

        self::assertSame(['version' => 'published', 'language' => 'de'], $options->toQuery());
    }

    public function testJoinsResolveRelationsWithCommas(): void
    {
        $options = (new ContentOptions())->withResolveRelations(['page.author', 'page.related']);

        self::assertSame('page.author,page.related', $options->toQuery()['resolve_relations']);
    }

    public function testOmitsResolveRelationsWhenEmpty(): void
    {
        self::assertArrayNotHasKey('resolve_relations', (new ContentOptions())->toQuery());
    }

    public function testEmitsTheCacheVersionWhenSet(): void
    {
        $options = (new ContentOptions())->withCv('1699999999');

        self::assertSame('1699999999', $options->toQuery()['cv']);
    }

    public function testWithersReturnANewInstanceAndLeaveTheOriginalAlone(): void
    {
        $original = new ContentOptions();
        $changed = $original->withVersion(Version::Draft)->withLanguage('pt');

        self::assertNotSame($original, $changed);
        self::assertSame(Version::Published, $original->version);
        self::assertNull($original->language);
        self::assertSame(Version::Draft, $changed->version);
        self::assertSame('pt', $changed->language);
    }

    public function testEachWitherPreservesTheOtherFields(): void
    {
        $options = (new ContentOptions(Version::Draft, 'de', ['page.author'], '123'))
            ->withLanguage('fr');

        self::assertSame([
            'version' => 'draft',
            'language' => 'fr',
            'resolve_relations' => 'page.author',
            'cv' => '123',
        ], $options->toQuery());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose run --rm php vendor/bin/phpunit --filter ContentOptionsTest`
Expected: FAIL — `Class "Tlab\StoryblokTransfers\Content\ContentOptions" not found`

- [ ] **Step 3: Write the three value objects**

Create `src/Content/Version.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Content;

/**
 * Which version of a story the CDA should return.
 *
 * A backed enum because the case values are the query-parameter values, so no
 * call site has to spell 'draft' as a string.
 */
enum Version: string
{
    case Published = 'published';
    case Draft = 'draft';
}
```

Create `src/Content/ContentOptions.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Content;

/**
 * The parameters every CDA resource accepts.
 *
 * These are not listing concerns - a links tree is also fetched per version and
 * per language - so they live here rather than inside StoryQuery, which
 * composes this.
 *
 * Immutable through withers rather than `readonly class`, which is PHP 8.2 while
 * this library supports 8.1.
 */
final class ContentOptions
{
    /**
     * @param list<string> $resolveRelations Fields to resolve, in Storyblok's
     *                                       'component.field' form.
     * @param string|null $cv Cache version. Set by the caller; this library
     *                        neither tracks nor refreshes it, because deciding
     *                        when it changes is the invalidation policy.
     */
    public function __construct(
        public readonly Version $version = Version::Published,
        public readonly ?string $language = null,
        public readonly array $resolveRelations = [],
        public readonly ?string $cv = null,
    ) {
    }

    public function withVersion(Version $version): self
    {
        return new self($version, $this->language, $this->resolveRelations, $this->cv);
    }

    public function withLanguage(?string $language): self
    {
        return new self($this->version, $language, $this->resolveRelations, $this->cv);
    }

    /**
     * @param list<string> $resolveRelations
     */
    public function withResolveRelations(array $resolveRelations): self
    {
        return new self($this->version, $this->language, $resolveRelations, $this->cv);
    }

    public function withCv(?string $cv): self
    {
        return new self($this->version, $this->language, $this->resolveRelations, $cv);
    }

    /**
     * Query parameters, without the token - the client adds that.
     *
     * Only set values are emitted: an absent parameter and an empty one are not
     * the same thing to the CDA.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        $query = ['version' => $this->version->value];

        if ($this->language !== null) {
            $query['language'] = $this->language;
        }

        if ($this->resolveRelations !== []) {
            $query['resolve_relations'] = implode(',', $this->resolveRelations);
        }

        if ($this->cv !== null) {
            $query['cv'] = $this->cv;
        }

        return $query;
    }
}
```

Create `src/Client/ContentResponse.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Client;

/**
 * A CDA response, reduced to what the repositories need.
 *
 * The listing endpoint reports its total and page size in the Total and
 * Per-Page HTTP headers rather than in the body, so returning only the decoded
 * body would push header handling out past the transport boundary. Both are
 * null for the endpoints that do not paginate.
 */
final class ContentResponse
{
    /**
     * @param array<string, mixed> $body The decoded JSON body.
     */
    public function __construct(
        public readonly array $body,
        public readonly ?int $total = null,
        public readonly ?int $perPage = null,
    ) {
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `docker compose run --rm php vendor/bin/phpunit --filter ContentOptionsTest`
Expected: PASS

- [ ] **Step 5: Run the linters**

Run: `make stan && make cs`
Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add src/Content/Version.php src/Content/ContentOptions.php src/Client/ContentResponse.php tests/Unit/ContentOptionsTest.php
git commit -m "Add the value objects the content client is defined in terms of"
```

---

### Task 4: The CDA transport

**Files:**
- Create: `src/Client/ContentClient.php`, `src/Client/StoryblokContentClient.php`, `src/Client/ResourceNotFoundException.php`
- Create: `tests/Unit/StoryblokContentClientTest.php`

**Interfaces:**
- Consumes: `ContentResponse` from Task 3
- Produces:
  - `interface ContentClient { public function get(string $path, array $query): ContentResponse; }`
  - `StoryblokContentClient::__construct(string $token, ?ClientInterface $httpClient = null, string $baseUri = self::DEFAULT_BASE_URI)`
  - `StoryblokContentClient::DEFAULT_BASE_URI = 'https://api.storyblok.com/v2/'`
  - `ResourceNotFoundException extends RuntimeException` — thrown on HTTP 404

**Why `ResourceNotFoundException` is not a `StoryblokApiException`:** the existing `StoryblokApiException` is declared `final` (`src/Client/StoryblokApiException.php`), so it cannot be subclassed. That constraint lines up with the design anyway — a 404 is data, not a fault, and callers that catch `StoryblokApiException` should not swallow it. `StoryRepository` converts it to `null` in Task 8.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/StoryblokContentClientTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Tlab\StoryblokTransfers\Client\ResourceNotFoundException;
use Tlab\StoryblokTransfers\Client\StoryblokApiException;
use Tlab\StoryblokTransfers\Client\StoryblokContentClient;

final class StoryblokContentClientTest extends TestCase
{
    private const TOKEN = 'super-secret-delivery-token';

    /** @var list<RequestInterface> */
    private array $sentRequests = [];

    public function testBuildsTheUriFromTheBaseAndThePath(): void
    {
        $client = $this->clientReturning([new Response(200, [], '{"story":{}}')]);

        $client->get('cdn/stories/home', []);

        self::assertStringStartsWith(
            'https://api.storyblok.com/v2/cdn/stories/home?',
            (string) $this->sentRequests[0]->getUri()
        );
    }

    public function testSendsTheTokenInTheQueryStringAndNotInAHeader(): void
    {
        $client = $this->clientReturning([new Response(200, [], '{}')]);

        $client->get('cdn/stories/home', []);

        $uri = $this->sentRequests[0]->getUri();
        self::assertSame('token=' . self::TOKEN, $uri->getQuery());
        self::assertSame('', $this->sentRequests[0]->getHeaderLine('Authorization'));
    }

    public function testSerialisesQueryParametersSortedByKey(): void
    {
        $client = $this->clientReturning([new Response(200, [], '{}')]);

        // Deliberately unsorted, and not in the order the CDA documents them.
        $client->get('cdn/stories', [
            'version' => 'draft',
            'starts_with' => 'blog/',
            'per_page' => '25',
        ]);

        // Sorted: per_page, starts_with, token, version. This ordering is what
        // makes a caching decorator's key stable across call sites.
        self::assertSame(
            'per_page=25&starts_with=blog%2F&token=' . self::TOKEN . '&version=draft',
            $this->sentRequests[0]->getUri()->getQuery()
        );
    }

    public function testReturnsTheDecodedBody(): void
    {
        $client = $this->clientReturning([
            new Response(200, [], '{"story":{"uuid":"abc","content":{"component":"page"}}}'),
        ]);

        $response = $client->get('cdn/stories/home', []);

        self::assertSame(['uuid' => 'abc', 'content' => ['component' => 'page']], $response->body['story']);
    }

    public function testReadsTotalAndPerPageFromTheResponseHeaders(): void
    {
        $client = $this->clientReturning([
            new Response(200, ['Total' => '137', 'Per-Page' => '25'], '{"stories":[]}'),
        ]);

        $response = $client->get('cdn/stories', []);

        self::assertSame(137, $response->total);
        self::assertSame(25, $response->perPage);
    }

    public function testLeavesTotalAndPerPageNullWhenTheHeadersAreAbsent(): void
    {
        $client = $this->clientReturning([new Response(200, [], '{"story":{}}')]);

        $response = $client->get('cdn/stories/home', []);

        self::assertNull($response->total);
        self::assertNull($response->perPage);
    }

    public function testThrowsResourceNotFoundOnA404(): void
    {
        $client = $this->clientReturning([new Response(404, [], '{"error":"Not Found"}')]);

        $this->expectException(ResourceNotFoundException::class);

        $client->get('cdn/stories/nope', []);
    }

    public function testThrowsTheApiExceptionOnOtherFailureStatuses(): void
    {
        $client = $this->clientReturning([new Response(401, [], '{"error":"Unauthorized"}')]);

        $this->expectException(StoryblokApiException::class);
        $this->expectExceptionMessageMatches('/401/');

        $client->get('cdn/stories/home', []);
    }

    public function testThrowsWhenTheResponseIsNotJson(): void
    {
        $client = $this->clientReturning([new Response(200, [], 'not json at all')]);

        $this->expectException(StoryblokApiException::class);
        $this->expectExceptionMessageMatches('/decode|JSON/i');

        $client->get('cdn/stories/home', []);
    }

    public function testNeverPutsTheTokenInAFailureMessage(): void
    {
        $client = $this->clientReturning([new Response(500, [], 'upstream exploded')]);

        try {
            $client->get('cdn/stories/home', []);
            self::fail('Expected the client to throw.');
        } catch (StoryblokApiException $e) {
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
            self::assertStringContainsString('[redacted]', $e->getMessage());
        }
    }

    public function testNeverPutsTheTokenInANotFoundMessage(): void
    {
        $client = $this->clientReturning([new Response(404, [], '')]);

        try {
            $client->get('cdn/stories/nope', []);
            self::fail('Expected the client to throw.');
        } catch (ResourceNotFoundException $e) {
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }

    public function testNeverPutsTheTokenInAMessageEchoedBackByTheApi(): void
    {
        // Storyblok echoing the request back would otherwise leak the token
        // through the response body rather than through the URI.
        $client = $this->clientReturning([
            new Response(422, [], 'bad request: token=' . self::TOKEN),
        ]);

        try {
            $client->get('cdn/stories/home', []);
            self::fail('Expected the client to throw.');
        } catch (StoryblokApiException $e) {
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }

    public function testHonoursACustomBaseUriForOtherRegions(): void
    {
        $client = $this->clientReturning(
            [new Response(200, [], '{}')],
            'https://api-us.storyblok.com/v2/'
        );

        $client->get('cdn/stories/home', []);

        self::assertStringStartsWith(
            'https://api-us.storyblok.com/v2/cdn/stories/home',
            (string) $this->sentRequests[0]->getUri()
        );
    }

    /**
     * @param list<Response> $responses
     */
    private function clientReturning(
        array $responses,
        string $baseUri = StoryblokContentClient::DEFAULT_BASE_URI
    ): StoryblokContentClient {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push($this->requestRecorder());

        return new StoryblokContentClient(self::TOKEN, new Client(['handler' => $stack]), $baseUri);
    }

    /**
     * Records outgoing requests so the tests can assert on what was actually
     * put on the wire.
     *
     * @return callable(callable): callable
     */
    private function requestRecorder(): callable
    {
        return function (callable $handler): callable {
            return function (RequestInterface $request, array $options) use ($handler) {
                $this->sentRequests[] = $request;

                return $handler($request, $options);
            };
        };
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose run --rm php vendor/bin/phpunit --filter StoryblokContentClientTest`
Expected: FAIL — `Class "Tlab\StoryblokTransfers\Client\StoryblokContentClient" not found`

- [ ] **Step 3: Write the interface and the exception**

Create `src/Client/ContentClient.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Client;

/**
 * The seam between the repositories and the network.
 *
 * One method, because a path plus a query map already describes every CDA
 * resource this library reads - and one method is the smallest thing an
 * application has to wrap to add caching. This library ships no cache: the
 * interface *is* the caching provision.
 */
interface ContentClient
{
    /**
     * @param string $path Path below the base URI, e.g. 'cdn/stories/home'.
     * @param array<string, string> $query Query parameters without the token.
     *
     * @throws ResourceNotFoundException When the resource does not exist.
     * @throws StoryblokApiException For every other failure.
     */
    public function get(string $path, array $query): ContentResponse;
}
```

Create `src/Client/ResourceNotFoundException.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Client;

use RuntimeException;

/**
 * The CDA has no resource at that path - HTTP 404.
 *
 * Deliberately not a subclass of StoryblokApiException. That class is final,
 * and the distinction is wanted anyway: a missing story is an answer, not a
 * fault, and StoryRepository turns this into null rather than letting it out.
 */
final class ResourceNotFoundException extends RuntimeException
{
}
```

- [ ] **Step 4: Write the client**

Create `src/Client/StoryblokContentClient.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Client;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Psr\Http\Message\ResponseInterface;

/**
 * Transport for the Storyblok Content Delivery API.
 *
 * A sibling of StoryblokManagementClient rather than a subclass: the CDA carries
 * its token in the query string instead of an Authorization header, lives on a
 * different host, and takes a different token entirely, so there is no shared
 * mechanism to inherit.
 *
 * Because the token is in the URI, every message this class produces passes
 * through redact() first. StoryblokManagementClient interpolates the URI into
 * its messages freely, which is harmless when the credential is in a header;
 * copying that here would write the delivery token into logs and crash reports.
 */
final class StoryblokContentClient implements ContentClient
{
    public const DEFAULT_BASE_URI = 'https://api.storyblok.com/v2/';

    private const REDACTION = '[redacted]';

    private readonly ClientInterface $httpClient;

    /**
     * @param string $token A Content Delivery API token, preview or public.
     *                      Not the Management API token.
     * @param string $baseUri Region endpoint. The default is the EU one; the US,
     *                        AP, CA and CN spaces each have their own host.
     */
    public function __construct(
        private readonly string $token,
        ?ClientInterface $httpClient = null,
        private readonly string $baseUri = self::DEFAULT_BASE_URI,
    ) {
        $this->httpClient = $httpClient ?? new Client();
    }

    /**
     * @param array<string, string> $query
     *
     * @throws ResourceNotFoundException
     * @throws StoryblokApiException
     */
    public function get(string $path, array $query): ContentResponse
    {
        $url = $this->url($path, $query);

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => ['Accept' => 'application/json'],
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            throw new StoryblokApiException(
                sprintf('Request to %s failed: %s', $this->redact($url), $this->redact($e->getMessage())),
                0,
                $e
            );
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status === 404) {
            throw new ResourceNotFoundException(
                'The Storyblok Content Delivery API has nothing at ' . $this->redact($url)
            );
        }

        if ($status < 200 || $status >= 300) {
            throw new StoryblokApiException(sprintf(
                'Storyblok Content Delivery API returned HTTP %d for %s: %s',
                $status,
                $this->redact($url),
                $this->redact(trim($body))
            ));
        }

        return new ContentResponse(
            $this->decode($body, $url),
            $this->intHeader($response, 'Total'),
            $this->intHeader($response, 'Per-Page'),
        );
    }

    /**
     * The query is built here rather than handed to Guzzle's `query` option so
     * that the URI in an error message is byte-for-byte the one that went out,
     * and so the sort order is this class's guarantee rather than Guzzle's.
     *
     * @param array<string, string> $query
     */
    private function url(string $path, array $query): string
    {
        $query['token'] = $this->token;

        // Sorted, so a caching decorator keyed on the URI gets the same key for
        // the same request whatever order the caller built the parameters in.
        ksort($query);

        return rtrim($this->baseUri, '/') . '/' . ltrim($path, '/')
            . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws StoryblokApiException
     */
    private function decode(string $body, string $url): array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new StoryblokApiException(
                sprintf('Could not decode the JSON response from %s: %s', $this->redact($url), $e->getMessage()),
                0,
                $e
            );
        }

        if (!is_array($decoded)) {
            throw new StoryblokApiException('Could not decode the JSON response from ' . $this->redact($url));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Absent for every endpoint but the listing, and a non-numeric value is
     * treated as absent rather than cast to 0.
     */
    private function intHeader(ResponseInterface $response, string $name): ?int
    {
        $value = $response->getHeaderLine($name);

        return $value !== '' && ctype_digit($value) ? (int) $value : null;
    }

    /**
     * The token appears in the URI, and Storyblok may echo the request back in
     * an error body, so both are scrubbed. The encoded form is replaced too,
     * because the URI carries the token percent-encoded.
     */
    private function redact(string $text): string
    {
        return str_replace(
            [$this->token, rawurlencode($this->token)],
            self::REDACTION,
            $text
        );
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `docker compose run --rm php vendor/bin/phpunit --filter StoryblokContentClientTest`
Expected: PASS. If `testSerialisesQueryParametersSortedByKey` fails on the encoding of `blog/`, read the actual query off the failure message and correct the expectation — `PHP_QUERY_RFC3986` encodes `/` as `%2F`, which is what the assertion assumes.

- [ ] **Step 6: Run the linters**

Run: `make stan && make cs`
Expected: no errors.

- [ ] **Step 7: Commit**

```bash
git add src/Client/ContentClient.php src/Client/StoryblokContentClient.php src/Client/ResourceNotFoundException.php tests/Unit/StoryblokContentClientTest.php
git commit -m "Add a Content Delivery API client that keeps its token out of messages"
```

---

### Task 5: The story envelope

**Files:**
- Create: `src/Content/RelationMap.php`, `src/Content/StoryTransfer.php`
- Create: `tests/Unit/StoryTransferTest.php`

**Interfaces:**
- Consumes: `Tlab\TransferObjects\AbstractTransfer` (upstream)
- Produces:
  - `RelationMap::__construct(array $relations = [])`, `get(?string $uuid): AbstractTransfer|array|null`, `isEmpty(): bool`
  - `StoryTransfer::__construct(string $uuid, string $slug, string $fullSlug, AbstractTransfer $content, RelationMap $relations, ?string $name = null, ?string $lang = null, ?string $publishedAt = null, ?string $firstPublishedAt = null, ?string $createdAt = null, ?int $parentId = null, array $tagList = [], array $translatedSlugs = [])`
  - getters: `getUuid()`, `getSlug()`, `getFullSlug()`, `getContent()`, `getName()`, `getLang()`, `getPublishedAt()`, `getFirstPublishedAt()`, `getCreatedAt()`, `getParentId()`, `getTagList()`, `getTranslatedSlugs()`, `getRelations()`, `getRelation(?string $uuid)`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/StoryTransferTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tlab\StoryblokTransfers\Content\RelationMap;
use Tlab\StoryblokTransfers\Content\StoryTransfer;
use Tlab\StoryblokTransfers\Tests\Fixture\ScalarFixtureTransfer;

final class StoryTransferTest extends TestCase
{
    public function testExposesTheEnvelopeAndTheContent(): void
    {
        $content = new ScalarFixtureTransfer();
        $story = new StoryTransfer(
            'f1e2d3c4',
            'home',
            'en/home',
            $content,
            new RelationMap(),
            'Home',
            'en',
            '2026-08-01 10:00',
            '2026-07-01 09:00',
            '2026-06-01 08:00',
            42,
            ['featured'],
            ['de' => ['path' => 'de/start']],
        );

        self::assertSame('f1e2d3c4', $story->getUuid());
        self::assertSame('home', $story->getSlug());
        self::assertSame('en/home', $story->getFullSlug());
        self::assertSame($content, $story->getContent());
        self::assertSame('Home', $story->getName());
        self::assertSame('en', $story->getLang());
        self::assertSame('2026-08-01 10:00', $story->getPublishedAt());
        self::assertSame('2026-07-01 09:00', $story->getFirstPublishedAt());
        self::assertSame('2026-06-01 08:00', $story->getCreatedAt());
        self::assertSame(42, $story->getParentId());
        self::assertSame(['featured'], $story->getTagList());
        self::assertSame(['de' => ['path' => 'de/start']], $story->getTranslatedSlugs());
    }

    public function testDefaultsTheOptionalEnvelopeFields(): void
    {
        $story = new StoryTransfer('u', 's', 'f', new ScalarFixtureTransfer(), new RelationMap());

        self::assertNull($story->getName());
        self::assertNull($story->getLang());
        self::assertNull($story->getPublishedAt());
        self::assertNull($story->getParentId());
        self::assertSame([], $story->getTagList());
        self::assertSame([], $story->getTranslatedSlugs());
    }

    public function testReachesARelationThroughTheMap(): void
    {
        $author = new ScalarFixtureTransfer();
        $story = new StoryTransfer(
            'u',
            's',
            'f',
            new ScalarFixtureTransfer(),
            new RelationMap(['author-uuid' => $author]),
        );

        self::assertSame($author, $story->getRelation('author-uuid'));
    }

    public function testReturnsNullForAUuidThatWasNeverResolved(): void
    {
        $story = new StoryTransfer('u', 's', 'f', new ScalarFixtureTransfer(), new RelationMap());

        self::assertNull($story->getRelation('nothing-here'));
    }

    public function testAcceptsANullUuidBecauseTheGeneratedPropertyIsNullable(): void
    {
        // $page->getAuthor() is ?string, so this is the ordinary call shape when
        // the editor left the relation empty.
        $story = new StoryTransfer('u', 's', 'f', new ScalarFixtureTransfer(), new RelationMap());

        self::assertNull($story->getRelation(null));
    }

    public function testKeepsARelationWithNoGeneratedClassAsARawArray(): void
    {
        $raw = ['uuid' => 'x', 'content' => ['component' => 'unknown_thing']];
        $story = new StoryTransfer(
            'u',
            's',
            'f',
            new ScalarFixtureTransfer(),
            new RelationMap(['x' => $raw]),
        );

        self::assertSame($raw, $story->getRelation('x'));
    }

    public function testExposesTheMapItselfSoItCanBeSharedAndCompared(): void
    {
        $map = new RelationMap(['a' => new ScalarFixtureTransfer()]);
        $story = new StoryTransfer('u', 's', 'f', new ScalarFixtureTransfer(), $map);

        self::assertSame($map, $story->getRelations());
        self::assertFalse($map->isEmpty());
        self::assertTrue((new RelationMap())->isEmpty());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose run --rm php vendor/bin/phpunit --filter StoryTransferTest`
Expected: FAIL — `Class "Tlab\StoryblokTransfers\Content\RelationMap" not found`

- [ ] **Step 3: Write RelationMap**

Create `src/Content/RelationMap.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Content;

use Tlab\TransferObjects\AbstractTransfer;

/**
 * Resolved relations, keyed by the uuid that stands in for them in the content.
 *
 * This is the whole point of the relation design: the generated property keeps
 * its ?string uuid and the resolved story sits beside the tree rather than
 * inside it - the same split RichtextTransfer makes for embedded bloks, and for
 * the same reason. A value the setter expects to be a scalar must stay one.
 *
 * One instance is shared by every story in a listing, so identity is meaningful
 * and assertable.
 */
final class RelationMap
{
    /**
     * @param array<string, AbstractTransfer|array<mixed>> $relations A raw array
     *        is a related story whose component has no generated class.
     */
    public function __construct(
        private readonly array $relations = [],
    ) {
    }

    /**
     * @param string|null $uuid Nullable because the generated property it comes
     *                          from is nullable.
     *
     * @return AbstractTransfer|array<mixed>|null Null when the uuid was never
     *                                            resolved, which is the normal
     *                                            case for an unrequested field.
     */
    public function get(?string $uuid): AbstractTransfer|array|null
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }

        return $this->relations[$uuid] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->relations === [];
    }
}
```

- [ ] **Step 4: Write StoryTransfer**

Create `src/Content/StoryTransfer.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Content;

use Tlab\TransferObjects\AbstractTransfer;

/**
 * A story: the envelope the CDA wraps around the content, plus the hydrated
 * content itself.
 *
 * Deliberately not an AbstractTransfer. The bundled transfers extend it because
 * generated code references them as property types and because fromArray()
 * hydrates them; this is neither - it is never a property of a generated class,
 * and it is constructed here from a response shape that is fixed and known. So
 * the rule that forces every AbstractTransfer property to be nullable with a
 * default does not apply, and uuid, slug, fullSlug and content can be what the
 * CDA guarantees they are: present.
 *
 * `final class` with promoted private readonly properties rather than
 * `readonly class`, which is PHP 8.2 while this library supports 8.1.
 *
 * Getters rather than public properties: every other transfer in the package
 * reads that way, and `@return T` on a method is the generic form PHPStan
 * handles most reliably.
 *
 * @template T of AbstractTransfer
 */
final class StoryTransfer
{
    /**
     * @param T $content
     * @param list<string> $tagList
     * @param array<string, mixed> $translatedSlugs
     */
    public function __construct(
        private readonly string $uuid,
        private readonly string $slug,
        private readonly string $fullSlug,
        private readonly AbstractTransfer $content,
        private readonly RelationMap $relations,
        private readonly ?string $name = null,
        private readonly ?string $lang = null,
        private readonly ?string $publishedAt = null,
        private readonly ?string $firstPublishedAt = null,
        private readonly ?string $createdAt = null,
        private readonly ?int $parentId = null,
        private readonly array $tagList = [],
        private readonly array $translatedSlugs = [],
    ) {
    }

    /**
     * @return T
     */
    public function getContent(): AbstractTransfer
    {
        return $this->content;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getFullSlug(): string
    {
        return $this->fullSlug;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getLang(): ?string
    {
        return $this->lang;
    }

    public function getPublishedAt(): ?string
    {
        return $this->publishedAt;
    }

    public function getFirstPublishedAt(): ?string
    {
        return $this->firstPublishedAt;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getParentId(): ?int
    {
        return $this->parentId;
    }

    /**
     * @return list<string>
     */
    public function getTagList(): array
    {
        return $this->tagList;
    }

    /**
     * @return array<string, mixed>
     */
    public function getTranslatedSlugs(): array
    {
        return $this->translatedSlugs;
    }

    /**
     * Shared with every other story from the same response, so this can be
     * compared by identity.
     */
    public function getRelations(): RelationMap
    {
        return $this->relations;
    }

    /**
     * Fed straight from a generated ?string relation property.
     *
     * @return AbstractTransfer|array<mixed>|null
     */
    public function getRelation(?string $uuid): AbstractTransfer|array|null
    {
        return $this->relations->get($uuid);
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `docker compose run --rm php vendor/bin/phpunit --filter StoryTransferTest`
Expected: PASS

- [ ] **Step 6: Run the linters**

Run: `make stan && make cs`
Expected: no errors. PHPStan will accept `@return T` on `getContent()` because the class carries `@template T of AbstractTransfer` and the constructor annotates `@param T $content`.

- [ ] **Step 7: Commit**

```bash
git add src/Content/RelationMap.php src/Content/StoryTransfer.php tests/Unit/StoryTransferTest.php
git commit -m "Add the story envelope, with relations beside the content"
```

---

### Task 6: Build the relation map from the response root

**This task was rewritten after Task 1's probe.** The plan originally specified a `RelationDeInliner` that walked the whole content tree, detected story objects that had replaced uuids, moved them into a map and restored the uuid. The probe proved the CDA never does that substitution: `resolve_relations` leaves `content` byte-for-byte untouched — verified against a uuid on a *nested* blok — and delivers the resolved stories in a `rels` array at the response root, keyed by uuid. So there is nothing to de-inline, and this task is a lookup builder instead of a tree walk. `DeInlinedContent` and `RelationDeInliner` are not built at all.

The original worst-case assumption was modelled on Storyblok's JavaScript SDK, which performs the inline substitution *client*-side after fetching. The API does not.

**Files:**
- Create: `src/Content/RelationMapFactory.php`
- Create: `tests/Unit/RelationMapFactoryTest.php`

**Interfaces:**
- Consumes: `ComponentClassResolver` (Task 2), `StoryblokHydrator` (existing), `RelationMap` (Task 5)
- Produces:
  - `RelationMapFactory::__construct(ComponentClassResolver $resolver, StoryblokHydrator $hydrator)`
  - `RelationMapFactory::fromRels(array $rels): RelationMap`

**Accepted risk, recorded rather than defended against:** if a future Storyblok version or some other endpoint ever did substitute inline, an array would reach a generated `?string` setter and raise a `TypeError` in weak mode. This is deliberately not guarded — the API demonstrably does not do it, and building the guard means building the tree walk this task exists to avoid. The failure would be loud, and the spec records why the decision was made.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/RelationMapFactoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tlab\StoryblokTransfers\Content\RelationMapFactory;
use Tlab\StoryblokTransfers\Hydration\ComponentClassResolver;
use Tlab\StoryblokTransfers\Hydration\StoryblokHydrator;
use Tlab\StoryblokTransfers\Tests\Fixture\NestedFixtureTransfer;

final class RelationMapFactoryTest extends TestCase
{
    private const FIXTURE_NAMESPACE = 'Tlab\\StoryblokTransfers\\Tests\\Fixture';

    private RelationMapFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new RelationMapFactory(
            new ComponentClassResolver(self::FIXTURE_NAMESPACE),
            new StoryblokHydrator(self::FIXTURE_NAMESPACE),
        );
    }

    public function testBuildsAnEmptyMapFromNoRelations(): void
    {
        // The CDA always sends `rels`, empty when nothing was resolved.
        self::assertTrue($this->factory->fromRels([])->isEmpty());
    }

    public function testHydratesARelatedStoryAndKeysItByUuid(): void
    {
        $map = $this->factory->fromRels([
            [
                'uuid' => 'author-1',
                'full_slug' => 'authors/jane',
                'content' => ['component' => 'nested_fixture', 'headline' => 'Jane'],
            ],
        ]);

        $author = $map->get('author-1');
        self::assertInstanceOf(NestedFixtureTransfer::class, $author);
        self::assertSame('Jane', $author->getHeadline());
    }

    public function testKeepsEveryRelationInTheArray(): void
    {
        $map = $this->factory->fromRels([
            ['uuid' => 'r1', 'content' => ['component' => 'nested_fixture', 'headline' => 'One']],
            ['uuid' => 'r2', 'content' => ['component' => 'nested_fixture', 'headline' => 'Two']],
        ]);

        $first = $map->get('r1');
        $second = $map->get('r2');
        self::assertInstanceOf(NestedFixtureTransfer::class, $first);
        self::assertInstanceOf(NestedFixtureTransfer::class, $second);
        self::assertSame('One', $first->getHeadline());
        self::assertSame('Two', $second->getHeadline());
    }

    public function testKeepsARelationWhoseComponentHasNoGeneratedClassAsARawArray(): void
    {
        // Content drift must not break the page: the same degradation the
        // hydrator applies to an unknown nested blok.
        $related = [
            'uuid' => 'thing-1',
            'content' => ['component' => 'not_generated', 'whatever' => 1],
        ];

        self::assertSame($related, $this->factory->fromRels([$related])->get('thing-1'));
    }

    public function testKeepsARelationWithNoContentObjectAsARawArray(): void
    {
        $related = ['uuid' => 'folder-1', 'is_folder' => true];

        self::assertSame($related, $this->factory->fromRels([$related])->get('folder-1'));
    }

    public function testSkipsEntriesThatAreNotUsableRelations(): void
    {
        $map = $this->factory->fromRels([
            ['uuid' => 'keep', 'content' => ['component' => 'nested_fixture', 'headline' => 'Keep']],
            'not an array',
            ['content' => ['component' => 'nested_fixture']],
            ['uuid' => '', 'content' => ['component' => 'nested_fixture']],
        ]);

        self::assertInstanceOf(NestedFixtureTransfer::class, $map->get('keep'));
        self::assertNull($map->get(''));
    }

    public function testLeavesTheContentOfTheRelatedStoryHydratedNotRaw(): void
    {
        // Pins the distinction that matters: the relation's own content becomes
        // a transfer, while the story that pointed at it keeps a plain uuid in
        // its ?string property - which is why nothing has to touch `content`.
        $map = $this->factory->fromRels([
            [
                'uuid' => 'author-1',
                'content' => [
                    'component' => 'nested_fixture',
                    'headline' => 'Jane',
                    'image' => ['id' => 3, 'filename' => 'jane.jpg'],
                ],
            ],
        ]);

        $author = $map->get('author-1');
        self::assertInstanceOf(NestedFixtureTransfer::class, $author);
        self::assertNotNull($author->getImage());
        self::assertSame('jane.jpg', $author->getImage()->getFilename());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose run --rm php vendor/bin/phpunit --filter RelationMapFactoryTest`
Expected: FAIL — `Class "Tlab\StoryblokTransfers\Content\RelationMapFactory" not found`

- [ ] **Step 3: Write RelationMapFactory**

Create `src/Content/RelationMapFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Content;

use Tlab\StoryblokTransfers\Hydration\ComponentClassResolver;
use Tlab\StoryblokTransfers\Hydration\StoryblokHydrator;
use Tlab\TransferObjects\AbstractTransfer;

/**
 * Turns a CDA response's `rels` array into a uuid-keyed RelationMap.
 *
 * With resolve_relations, Storyblok leaves `content` exactly as it was - the
 * relation field keeps the plain uuid string it always held - and puts the
 * resolved stories in a `rels` array at the response root. So resolving a
 * relation is a lookup, not a tree walk, and the generated `?string` property
 * needs no repair: it was never rewritten.
 *
 * Built once per response, which is what makes one RelationMap shared by every
 * story on a page structurally true rather than something a merge step has to
 * maintain.
 */
final class RelationMapFactory
{
    public function __construct(
        private readonly ComponentClassResolver $resolver,
        private readonly StoryblokHydrator $hydrator,
    ) {
    }

    /**
     * @param array<mixed> $rels The `rels` array from a response root. Always
     *                           present, empty when nothing was resolved.
     */
    public function fromRels(array $rels): RelationMap
    {
        $relations = [];

        foreach ($rels as $related) {
            if (!is_array($related)) {
                continue;
            }

            $uuid = $related['uuid'] ?? null;

            if (!is_string($uuid) || $uuid === '') {
                continue;
            }

            /** @var array<string, mixed> $related */
            $relations[$uuid] = $this->hydrate($related);
        }

        return new RelationMap($relations);
    }

    /**
     * A relation whose component has no generated class - or which carries no
     * content at all, as a folder does - keeps its whole raw story array. That
     * is the same degradation the hydrator applies to an unknown nested blok:
     * content drift must not break the page.
     *
     * @param array<string, mixed> $related
     *
     * @return AbstractTransfer|array<mixed>
     */
    private function hydrate(array $related): AbstractTransfer|array
    {
        $content = $related['content'] ?? null;

        if (!is_array($content)) {
            return $related;
        }

        /** @var array<string, mixed> $content */
        $class = $this->resolver->resolveFromContent($content);

        return $class === null
            ? $related
            : $this->hydrator->hydrate($class, $content);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `docker compose run --rm php vendor/bin/phpunit --filter RelationMapFactoryTest`
Expected: PASS

- [ ] **Step 5: Run the linters**

Run: `make stan && make cs`
Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add src/Content/RelationMapFactory.php tests/Unit/RelationMapFactoryTest.php
git commit -m "Build the relation map from the response root, where the CDA puts it"
```

### Task 7: StoryMapper and the two content exceptions

**Files:**
- Create: `src/Content/UnresolvableComponentException.php`, `src/Content/UnexpectedComponentException.php`, `src/Content/StoryMapper.php`
- Create: `tests/Unit/StoryMapperTest.php`
- Modify: `tests/Fixture/NestedFixtureTransfer.php` — add a `?string $author` property with getter and setter

**Why the fixture needs a relation field.** `testMakesTheGivenRelationMapReachableThroughTheEnvelope` below asserts `$story->getContent()->toArray()['author']` to prove the uuid survives untouched in the content. `AbstractTransfer::fromArray()` and `toArray()` enumerate only *declared* properties by reflection, so without an `author` property the key is silently dropped and the array access raises `Undefined array key` — fatal under `phpunit.xml`'s `failOnWarning`. Add it in the same shape as the existing `headline`, and extend the fixture's class docblock to say why it is there: an `option` / `internal_stories` field stays a plain `?string` uuid, because `resolve_relations` never rewrites content.

The addition is additive and safe: no other test that uses this fixture asserts an exhaustive property or key set.

**Interfaces:**
- Consumes: `ComponentClassResolver` (Task 2), `StoryblokHydrator` (existing), `StoryTransfer` and `RelationMap` (Task 5)
- Produces:
  - `UnresolvableComponentException extends RuntimeException`
  - `UnexpectedComponentException extends RuntimeException`
  - `StoryMapper::__construct(ComponentClassResolver $resolver, StoryblokHydrator $hydrator)`
  - `StoryMapper::mapOne(array $story, RelationMap $relations, ?string $expected = null): StoryTransfer`

  `mapOne()` is the only public method this task adds; Task 9 adds `mapList()` beside it. Everything else — reading the content out of the story, resolving the target class, building the envelope — is private, because `mapList()` calls `mapOne()` per story rather than interleaving its steps.

  **The `RelationMap` is passed in, not built here.** Task 1's probe established that `resolve_relations` never rewrites `content`; the resolved stories arrive in a `rels` array at the response root. So the map belongs to the *response*, not to any one story, and the caller — `StoryRepository` — builds it once with `RelationMapFactory` and hands the same instance to every story it maps. That is what makes a page's shared map structurally true instead of something a merge step has to maintain.

**Cross-check with Task 1:** the envelope keys read below are `uuid`, `slug`, `full_slug`, `name`, `lang`, `published_at`, `first_published_at`, `created_at`, `parent_id`, `tag_list`, `translated_slugs`. Compare against the envelope keys the probe printed. If a key is named differently, fix it here rather than compensating later.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/StoryMapperTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tlab\StoryblokTransfers\Client\StoryblokApiException;
use Tlab\StoryblokTransfers\Content\RelationMap;
use Tlab\StoryblokTransfers\Content\StoryMapper;
use Tlab\StoryblokTransfers\Content\UnexpectedComponentException;
use Tlab\StoryblokTransfers\Content\UnresolvableComponentException;
use Tlab\StoryblokTransfers\Hydration\ComponentClassResolver;
use Tlab\StoryblokTransfers\Hydration\StoryblokHydrator;
use Tlab\StoryblokTransfers\Tests\Fixture\NestedFixtureTransfer;
use Tlab\StoryblokTransfers\Tests\Fixture\ScalarFixtureTransfer;

final class StoryMapperTest extends TestCase
{
    private const FIXTURE_NAMESPACE = 'Tlab\\StoryblokTransfers\\Tests\\Fixture';

    private StoryMapper $mapper;

    protected function setUp(): void
    {
        $resolver = new ComponentClassResolver(self::FIXTURE_NAMESPACE);
        $hydrator = new StoryblokHydrator(self::FIXTURE_NAMESPACE);

        $this->mapper = new StoryMapper($resolver, $hydrator);
    }

    public function testInfersTheTransferClassFromTheRootComponent(): void
    {
        $story = $this->mapper->mapOne($this->storyPayload(), new RelationMap());

        self::assertInstanceOf(NestedFixtureTransfer::class, $story->getContent());
        self::assertSame('A headline', $story->getContent()->getHeadline());
    }

    public function testFillsTheEnvelope(): void
    {
        $story = $this->mapper->mapOne($this->storyPayload(), new RelationMap());

        self::assertSame('story-uuid-1', $story->getUuid());
        self::assertSame('home', $story->getSlug());
        self::assertSame('en/home', $story->getFullSlug());
        self::assertSame('Home', $story->getName());
        self::assertSame('en', $story->getLang());
        self::assertSame('2026-08-01 10:00:00', $story->getPublishedAt());
        self::assertSame('2026-07-01 09:00:00', $story->getFirstPublishedAt());
        self::assertSame('2026-06-01 08:00:00', $story->getCreatedAt());
        self::assertSame(7, $story->getParentId());
        self::assertSame(['featured'], $story->getTagList());
        self::assertSame(['de' => ['path' => 'de/start']], $story->getTranslatedSlugs());
    }

    public function testAcceptsAStoryWithOnlyTheGuaranteedKeys(): void
    {
        $story = $this->mapper->mapOne([
            'uuid' => 'u',
            'slug' => 's',
            'full_slug' => 'f/s',
            'content' => ['component' => 'nested_fixture'],
        ], new RelationMap());

        self::assertNull($story->getName());
        self::assertNull($story->getParentId());
        self::assertSame([], $story->getTagList());
    }

    public function testReturnsTheDeclaredClassWhenItMatches(): void
    {
        $story = $this->mapper->mapOne($this->storyPayload(), new RelationMap(), NestedFixtureTransfer::class);

        self::assertInstanceOf(NestedFixtureTransfer::class, $story->getContent());
    }

    public function testThrowsWhenTheDeclaredClassDoesNotMatchTheComponent(): void
    {
        $this->expectException(UnexpectedComponentException::class);
        $this->expectExceptionMessageMatches('/nested_fixture/');

        $this->mapper->mapOne($this->storyPayload(), new RelationMap(), ScalarFixtureTransfer::class);
    }

    public function testThrowsWhenTheRootComponentHasNoGeneratedClass(): void
    {
        // Deliberately asymmetric with the hydrator, which leaves an unknown
        // nested blok as a raw array. The root content is the object the caller
        // asked for, and StoryTransfer::$content cannot be null.
        $this->expectException(UnresolvableComponentException::class);
        $this->expectExceptionMessageMatches('/no_such_component/');

        $this->mapper->mapOne([
            'uuid' => 'u',
            'slug' => 's',
            'full_slug' => 'f',
            'content' => ['component' => 'no_such_component'],
        ], new RelationMap());
    }

    public function testThrowsWhenTheStoryCarriesNoContentObject(): void
    {
        $this->expectException(UnresolvableComponentException::class);

        $this->mapper->mapOne(['uuid' => 'u', 'slug' => 's', 'full_slug' => 'f'], new RelationMap());
    }

    public function testThrowsWhenAGuaranteedEnvelopeKeyIsMissing(): void
    {
        // A response without a uuid is not a story. Better to say so than to
        // hand back an envelope with an empty string in it.
        $this->expectException(StoryblokApiException::class);
        $this->expectExceptionMessageMatches('/uuid/');

        $this->mapper->mapOne([
            'slug' => 's',
            'full_slug' => 'f',
            'content' => ['component' => 'nested_fixture'],
        ], new RelationMap());
    }

    public function testMakesTheGivenRelationMapReachableThroughTheEnvelope(): void
    {
        // The content keeps the plain uuid the CDA left there; the resolved
        // story arrives in the map the caller built from the response root.
        $author = new NestedFixtureTransfer();
        $author->setHeadline('Jane');

        $story = $this->mapper->mapOne([
            'uuid' => 'u',
            'slug' => 's',
            'full_slug' => 'f',
            'content' => [
                'component' => 'nested_fixture',
                'headline' => 'A post',
                'author' => 'author-1',
            ],
        ], new RelationMap(['author-1' => $author]));

        self::assertSame($author, $story->getRelation('author-1'));
        self::assertSame('author-1', $story->getContent()->toArray()['author']);
    }

    /**
     * @return array<string, mixed>
     */
    private function storyPayload(): array
    {
        return [
            'uuid' => 'story-uuid-1',
            'slug' => 'home',
            'full_slug' => 'en/home',
            'name' => 'Home',
            'lang' => 'en',
            'published_at' => '2026-08-01 10:00:00',
            'first_published_at' => '2026-07-01 09:00:00',
            'created_at' => '2026-06-01 08:00:00',
            'parent_id' => 7,
            'tag_list' => ['featured'],
            'translated_slugs' => ['de' => ['path' => 'de/start']],
            'content' => ['component' => 'nested_fixture', 'headline' => 'A headline'],
        ];
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose run --rm php vendor/bin/phpunit --filter StoryMapperTest`
Expected: FAIL — `Class "Tlab\StoryblokTransfers\Content\StoryMapper" not found`

- [ ] **Step 3: Write the two exceptions**

Create `src/Content/UnresolvableComponentException.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Content;

use RuntimeException;

/**
 * A story's root component has no generated transfer class.
 *
 * Deliberately asymmetric with the hydrator, which leaves an unknown nested
 * blok as a raw array so that an editor adding a component cannot break a page.
 * An unknown blok is one part of a page and degrading is right; the root content
 * is the object the caller asked for, and StoryTransfer::$content is not
 * nullable. Regenerate, or check the configured namespace.
 */
final class UnresolvableComponentException extends RuntimeException
{
}
```

Create `src/Content/UnexpectedComponentException.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Content;

use RuntimeException;

/**
 * The caller declared a transfer class that the story's component does not
 * resolve to.
 *
 * Exists so a mismatch reports the component that actually arrived, instead of
 * surfacing as a TypeError from somewhere inside the hydrator.
 */
final class UnexpectedComponentException extends RuntimeException
{
}
```

- [ ] **Step 4: Write StoryMapper**

Create `src/Content/StoryMapper.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Content;

use Tlab\StoryblokTransfers\Client\StoryblokApiException;
use Tlab\StoryblokTransfers\Hydration\ComponentClassResolver;
use Tlab\StoryblokTransfers\Hydration\StoryblokHydrator;
use Tlab\TransferObjects\AbstractTransfer;

/**
 * Turns a raw CDA story object into an envelope with hydrated content.
 *
 * Separate from StoryRepository because both the single-story and the listing
 * path need the same mapping, and neither should have to know how a response is
 * unwrapped to get it. The RelationMap arrives ready-made: it belongs to the
 * response, not to any one story in it.
 */
final class StoryMapper
{
    public function __construct(
        private readonly ComponentClassResolver $resolver,
        private readonly StoryblokHydrator $hydrator,
    ) {
    }

    /**
     * @param array<string, mixed> $story The "story" object from the response.
     * @param RelationMap $relations Built once per response by the caller, and
     *        shared by every story in it.
     * @param class-string<AbstractTransfer>|null $expected Asserted against the
     *        class the component resolves to, when given.
     *
     * @return StoryTransfer<AbstractTransfer>
     *
     * @throws UnresolvableComponentException
     * @throws UnexpectedComponentException
     * @throws StoryblokApiException When the payload is not a story.
     */
    public function mapOne(array $story, RelationMap $relations, ?string $expected = null): StoryTransfer
    {
        return $this->envelope($story, $this->hydrateContent($this->contentOf($story), $expected), $relations);
    }

    /**
     * @param array<string, mixed> $story
     *
     * @return array<string, mixed>
     *
     * @throws UnresolvableComponentException
     */
    private function contentOf(array $story): array
    {
        $content = $story['content'] ?? null;

        if (!is_array($content)) {
            throw new UnresolvableComponentException(
                'The story carries no content object, so there is nothing to hydrate.'
            );
        }

        /** @var array<string, mixed> $content */
        return $content;
    }

    /**
     * @param array<string, mixed> $content
     * @param class-string<AbstractTransfer>|null $expected
     *
     * @throws UnresolvableComponentException
     * @throws UnexpectedComponentException
     */
    private function hydrateContent(array $content, ?string $expected = null): AbstractTransfer
    {
        return $this->hydrator->hydrate($this->targetClass($content, $expected), $content);
    }

    /**
     * @param array<string, mixed> $story
     *
     * @return StoryTransfer<AbstractTransfer>
     *
     * @throws StoryblokApiException
     */
    private function envelope(array $story, AbstractTransfer $content, RelationMap $relations): StoryTransfer
    {
        return new StoryTransfer(
            $this->required($story, 'uuid'),
            $this->required($story, 'slug'),
            $this->required($story, 'full_slug'),
            $content,
            $relations,
            $this->optional($story, 'name'),
            $this->optional($story, 'lang'),
            $this->optional($story, 'published_at'),
            $this->optional($story, 'first_published_at'),
            $this->optional($story, 'created_at'),
            is_int($story['parent_id'] ?? null) ? $story['parent_id'] : null,
            $this->stringList($story, 'tag_list'),
            is_array($story['translated_slugs'] ?? null) ? $story['translated_slugs'] : [],
        );
    }

    /**
     * @param array<string, mixed> $content
     * @param class-string<AbstractTransfer>|null $expected
     *
     * @return class-string<AbstractTransfer>
     *
     * @throws UnresolvableComponentException
     * @throws UnexpectedComponentException
     */
    private function targetClass(array $content, ?string $expected): string
    {
        $component = is_string($content['component'] ?? null) ? $content['component'] : '';
        $resolved = $this->resolver->resolveFromContent($content);

        if ($resolved === null) {
            throw new UnresolvableComponentException(sprintf(
                'No generated transfer class for the root component "%s". Regenerate, or check that the '
                . 'configured namespace matches where the classes were written.',
                $component
            ));
        }

        if ($expected !== null && $resolved !== $expected) {
            throw new UnexpectedComponentException(sprintf(
                'Expected %s, but the story\'s component "%s" resolves to %s.',
                $expected,
                $component,
                $resolved
            ));
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed> $story
     *
     * @throws StoryblokApiException
     */
    private function required(array $story, string $key): string
    {
        $value = $story[$key] ?? null;

        if (!is_string($value) || $value === '') {
            throw new StoryblokApiException(sprintf(
                'The Storyblok response contains a story with no usable "%s", so it is not a story.',
                $key
            ));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $story
     */
    private function optional(array $story, string $key): ?string
    {
        $value = $story[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $story
     *
     * @return list<string>
     */
    private function stringList(array $story, string $key): array
    {
        $value = $story[$key] ?? null;

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `docker compose run --rm php vendor/bin/phpunit --filter StoryMapperTest`
Expected: PASS

- [ ] **Step 6: Run the linters**

Run: `make stan && make cs`
Expected: no errors.

- [ ] **Step 7: Commit**

```bash
git add src/Content/UnresolvableComponentException.php src/Content/UnexpectedComponentException.php src/Content/StoryMapper.php tests/Unit/StoryMapperTest.php
git commit -m "Map a raw CDA story onto an envelope with hydrated content"
```

---

### Task 8: StoryRepository — a single story

**Files:**
- Create: `src/Content/StoryRepository.php`
- Create: `tests/Fixture/FakeContentClient.php`
- Create: `tests/Unit/StoryRepositoryTest.php`

**Interfaces:**
- Consumes: `ContentClient`, `ContentResponse`, `ResourceNotFoundException` (Task 4), `StoryMapper` (Task 7), `RelationMapFactory` (Task 6), `ContentOptions` (Task 3)
- Produces:
  - `StoryRepository::__construct(ContentClient $client, StoryMapper $mapper, RelationMapFactory $relationMapFactory, ContentOptions $defaults = new ContentOptions())`
  - `StoryRepository::bySlug(string $slug, ?string $expected = null, ?ContentOptions $options = null): ?StoryTransfer`
  - `StoryRepository::byUuid(string $uuid, ?string $expected = null, ?ContentOptions $options = null): ?StoryTransfer`
  - `FakeContentClient::returning(ContentResponse|Throwable ...$responses): self`, public `$requests` as `list<array{path: string, query: array<string, string>}>`

- [ ] **Step 1: Write the fake client**

Create `tests/Fixture/FakeContentClient.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Fixture;

use RuntimeException;
use Throwable;
use Tlab\StoryblokTransfers\Client\ContentClient;
use Tlab\StoryblokTransfers\Client\ContentResponse;

/**
 * A ContentClient that answers from a queue and records what it was asked.
 *
 * The transport is already covered against MockHandler in
 * StoryblokContentClientTest, so the repository tests assert on the path and
 * query they produced rather than on HTTP.
 */
final class FakeContentClient implements ContentClient
{
    /** @var list<array{path: string, query: array<string, string>}> */
    public array $requests = [];

    /** @var list<ContentResponse|Throwable> */
    private array $queue;

    public function __construct(ContentResponse|Throwable ...$responses)
    {
        $this->queue = array_values($responses);
    }

    public static function returning(ContentResponse|Throwable ...$responses): self
    {
        return new self(...$responses);
    }

    /**
     * @param array<string, string> $query
     */
    public function get(string $path, array $query): ContentResponse
    {
        $this->requests[] = ['path' => $path, 'query' => $query];

        $next = array_shift($this->queue);

        if ($next === null) {
            throw new RuntimeException('FakeContentClient was asked for more responses than it holds.');
        }

        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }
}
```

- [ ] **Step 2: Write the failing test**

Create `tests/Unit/StoryRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tlab\StoryblokTransfers\Client\ContentResponse;
use Tlab\StoryblokTransfers\Client\ResourceNotFoundException;
use Tlab\StoryblokTransfers\Client\StoryblokApiException;
use Tlab\StoryblokTransfers\Content\ContentOptions;
use Tlab\StoryblokTransfers\Content\RelationMapFactory;
use Tlab\StoryblokTransfers\Content\StoryMapper;
use Tlab\StoryblokTransfers\Content\StoryRepository;
use Tlab\StoryblokTransfers\Content\Version;
use Tlab\StoryblokTransfers\Hydration\ComponentClassResolver;
use Tlab\StoryblokTransfers\Hydration\StoryblokHydrator;
use Tlab\StoryblokTransfers\Tests\Fixture\FakeContentClient;
use Tlab\StoryblokTransfers\Tests\Fixture\NestedFixtureTransfer;

final class StoryRepositoryTest extends TestCase
{
    private const FIXTURE_NAMESPACE = 'Tlab\\StoryblokTransfers\\Tests\\Fixture';

    public function testAsksForTheStoryAtTheSlug(): void
    {
        $client = FakeContentClient::returning($this->storyResponse());

        $this->repository($client)->bySlug('blog/hello-world');

        self::assertSame('cdn/stories/blog/hello-world', $client->requests[0]['path']);
    }

    public function testStripsSurroundingSlashesFromTheSlug(): void
    {
        $client = FakeContentClient::returning($this->storyResponse());

        $this->repository($client)->bySlug('/blog/hello-world/');

        self::assertSame('cdn/stories/blog/hello-world', $client->requests[0]['path']);
    }

    public function testEncodesEachSlugSegmentButKeepsTheSeparators(): void
    {
        $client = FakeContentClient::returning($this->storyResponse());

        $this->repository($client)->bySlug('blog/olá mundo');

        self::assertSame('cdn/stories/blog/ol%C3%A1%20mundo', $client->requests[0]['path']);
    }

    public function testSendsThePublishedVersionByDefault(): void
    {
        $client = FakeContentClient::returning($this->storyResponse());

        $this->repository($client)->bySlug('home');

        self::assertSame('published', $client->requests[0]['query']['version']);
    }

    public function testUsesTheRepositoryDefaultsWhenNoOptionsAreGiven(): void
    {
        $client = FakeContentClient::returning($this->storyResponse());
        $repository = $this->repository($client, new ContentOptions(Version::Draft));

        $repository->bySlug('home');

        self::assertSame('draft', $client->requests[0]['query']['version']);
    }

    public function testPerCallOptionsOverrideTheDefaults(): void
    {
        $client = FakeContentClient::returning($this->storyResponse());
        $repository = $this->repository($client, new ContentOptions(Version::Draft));

        $repository->bySlug('home', null, new ContentOptions(Version::Published, 'de'));

        self::assertSame('published', $client->requests[0]['query']['version']);
        self::assertSame('de', $client->requests[0]['query']['language']);
    }

    public function testLooksUpByUuidWithFindBy(): void
    {
        $client = FakeContentClient::returning($this->storyResponse());

        $this->repository($client)->byUuid('story-uuid-1');

        self::assertSame('cdn/stories/story-uuid-1', $client->requests[0]['path']);
        self::assertSame('uuid', $client->requests[0]['query']['find_by']);
    }

    public function testReturnsTheHydratedStory(): void
    {
        $client = FakeContentClient::returning($this->storyResponse());

        $story = $this->repository($client)->bySlug('home');

        self::assertNotNull($story);
        self::assertSame('story-uuid-1', $story->getUuid());
        self::assertInstanceOf(NestedFixtureTransfer::class, $story->getContent());
        self::assertSame('A headline', $story->getContent()->getHeadline());
    }

    public function testReturnsNullWhenTheStoryDoesNotExist(): void
    {
        // 404 is data, not a fault: a router asking for an unknown slug is the
        // hottest path in a consuming application, and exceptions there would be
        // control flow.
        $client = FakeContentClient::returning(new ResourceNotFoundException('nothing there'));

        self::assertNull($this->repository($client)->bySlug('no-such-page'));
    }

    public function testLetsOtherApiFailuresOut(): void
    {
        $client = FakeContentClient::returning(new StoryblokApiException('HTTP 429'));

        $this->expectException(StoryblokApiException::class);

        $this->repository($client)->bySlug('home');
    }

    public function testThrowsWhenTheResponseHasNoStoryKey(): void
    {
        $client = FakeContentClient::returning(new ContentResponse(['unexpected' => true]));

        $this->expectException(StoryblokApiException::class);
        $this->expectExceptionMessageMatches('/story/');

        $this->repository($client)->bySlug('home');
    }

    public function testPassesTheDeclaredClassThroughToTheMapper(): void
    {
        $client = FakeContentClient::returning($this->storyResponse());

        $story = $this->repository($client)->bySlug('home', NestedFixtureTransfer::class);

        self::assertNotNull($story);
        self::assertInstanceOf(NestedFixtureTransfer::class, $story->getContent());
    }

    private function repository(FakeContentClient $client, ?ContentOptions $defaults = null): StoryRepository
    {
        $resolver = new ComponentClassResolver(self::FIXTURE_NAMESPACE);
        $hydrator = new StoryblokHydrator(self::FIXTURE_NAMESPACE);
        $mapper = new StoryMapper($resolver, $hydrator);
        $factory = new RelationMapFactory($resolver, $hydrator);

        return $defaults === null
            ? new StoryRepository($client, $mapper, $factory)
            : new StoryRepository($client, $mapper, $factory, $defaults);
    }

    private function storyResponse(): ContentResponse
    {
        return new ContentResponse([
            'story' => [
                'uuid' => 'story-uuid-1',
                'slug' => 'home',
                'full_slug' => 'en/home',
                'content' => ['component' => 'nested_fixture', 'headline' => 'A headline'],
            ],
        ]);
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `docker compose run --rm php vendor/bin/phpunit --filter StoryRepositoryTest`
Expected: FAIL — `Class "Tlab\StoryblokTransfers\Content\StoryRepository" not found`

- [ ] **Step 4: Write the repository**

Create `src/Content/StoryRepository.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Content;

use Tlab\StoryblokTransfers\Client\ContentClient;
use Tlab\StoryblokTransfers\Client\ContentResponse;
use Tlab\StoryblokTransfers\Client\ResourceNotFoundException;
use Tlab\StoryblokTransfers\Client\StoryblokApiException;
use Tlab\TransferObjects\AbstractTransfer;

/**
 * Reads stories from the Content Delivery API and returns them hydrated.
 *
 * The target transfer class is inferred from the story's own component, so a
 * router that holds only a slug can still get a typed graph; a caller that
 * knows the type passes it and gets it asserted and echoed back through the
 * generic.
 */
final class StoryRepository
{
    public function __construct(
        private readonly ContentClient $client,
        private readonly StoryMapper $mapper,
        private readonly RelationMapFactory $relationMapFactory,
        private readonly ContentOptions $defaults = new ContentOptions(),
    ) {
    }

    /**
     * @template T of AbstractTransfer
     *
     * @param string $slug A full slug, with or without surrounding slashes.
     * @param class-string<T>|null $expected Asserted against the component.
     *
     * @return StoryTransfer<T>|null Null when no story has that slug.
     *
     * @throws StoryblokApiException
     * @throws UnresolvableComponentException
     * @throws UnexpectedComponentException
     */
    public function bySlug(string $slug, ?string $expected = null, ?ContentOptions $options = null): ?StoryTransfer
    {
        /** @var StoryTransfer<T>|null $story */
        $story = $this->fetch('cdn/stories/' . $this->encodeSlug($slug), [], $expected, $options);

        return $story;
    }

    /**
     * @template T of AbstractTransfer
     *
     * @param class-string<T>|null $expected
     *
     * @return StoryTransfer<T>|null
     *
     * @throws StoryblokApiException
     * @throws UnresolvableComponentException
     * @throws UnexpectedComponentException
     */
    public function byUuid(string $uuid, ?string $expected = null, ?ContentOptions $options = null): ?StoryTransfer
    {
        /** @var StoryTransfer<T>|null $story */
        $story = $this->fetch(
            'cdn/stories/' . rawurlencode($uuid),
            ['find_by' => 'uuid'],
            $expected,
            $options
        );

        return $story;
    }

    /**
     * @param array<string, string> $extraQuery
     * @param class-string<AbstractTransfer>|null $expected
     *
     * @return StoryTransfer<AbstractTransfer>|null
     *
     * @throws StoryblokApiException
     */
    private function fetch(
        string $path,
        array $extraQuery,
        ?string $expected,
        ?ContentOptions $options
    ): ?StoryTransfer {
        $query = ($options ?? $this->defaults)->toQuery() + $extraQuery;

        try {
            $response = $this->client->get($path, $query);
        } catch (ResourceNotFoundException) {
            // "No such story" is an answer the caller can act on.
            return null;
        }

        $story = $response->body['story'] ?? null;

        if (!is_array($story)) {
            throw new StoryblokApiException('No "story" key in the Storyblok response for ' . $path);
        }

        /** @var array<string, mixed> $story */
        return $this->mapper->mapOne($story, $this->relationMap($response), $expected);
    }

    /**
     * The resolved relations belong to the response, not to any one story in
     * it: resolve_relations leaves `content` untouched and returns what it
     * resolved in a `rels` array at the root. Building the map here once and
     * handing the same instance to every story is what makes a page's shared
     * map structurally true rather than something a merge step maintains.
     */
    private function relationMap(ContentResponse $response): RelationMap
    {
        $rels = $response->body['rels'] ?? [];

        /** @var array<mixed> $rels */
        return $this->relationMapFactory->fromRels(is_array($rels) ? $rels : []);
    }

    /**
     * Each segment is encoded, the separators are not: a full slug is a path,
     * so its slashes have to survive.
     */
    private function encodeSlug(string $slug): string
    {
        $segments = explode('/', trim($slug, '/'));

        return implode('/', array_map('rawurlencode', $segments));
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `docker compose run --rm php vendor/bin/phpunit --filter StoryRepositoryTest`
Expected: PASS

- [ ] **Step 6: Run the linters**

Run: `make stan && make cs`
Expected: no errors. If PHPStan objects to the `@return StoryTransfer<T>|null` narrowing on the private `fetch()` result, keep the local `/** @var */` annotations shown above — they are what bridge the untemplated private helper to the templated public methods.

- [ ] **Step 7: Commit**

```bash
git add src/Content/StoryRepository.php tests/Fixture/FakeContentClient.php tests/Unit/StoryRepositoryTest.php
git commit -m "Read a single story, with a missing one answering null"
```

---

### Task 9: The listing

**Files:**
- Create: `src/Content/StoryQuery.php`, `src/Content/StoryList.php`
- Create: `tests/Unit/StoryQueryTest.php`, `tests/Unit/StoryListingTest.php`
- Modify: `src/Content/StoryMapper.php` (add `mapList()`), `src/Content/StoryRepository.php` (add `findBy()`)

**Interfaces:**
- Consumes: everything from Tasks 3, 5, 7, 8
- Produces:
  - `StoryQuery::__construct(ContentOptions $options = new ContentOptions(), ?string $startsWith = null, array $filterQuery = [], ?string $sortBy = null, array $byUuids = [], array $excludingFields = [], int $page = 1, int $perPage = 25)`, `withPage(int $page): self`, `toQuery(): array<string, string>`
  - `StoryList::__construct(array $stories, int $total, int $page, int $perPage, RelationMap $relations)` implementing `IteratorAggregate`, with `getStories()`, `getTotal()`, `getPage()`, `getPerPage()`, `getRelations()`
  - `StoryMapper::mapList(array $stories, RelationMap $relations, int $total, int $page, int $perPage, ?string $expected = null): StoryList`
  - `StoryRepository::findBy(StoryQuery $query, ?string $expected = null): StoryList` — templated `@template T of AbstractTransfer = AbstractTransfer`, returning `StoryList<T>`, so a caller that declares its expected class gets it back typed exactly as `bySlug()` does

- [ ] **Step 1: Write the failing query test**

Create `tests/Unit/StoryQueryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tlab\StoryblokTransfers\Content\ContentOptions;
use Tlab\StoryblokTransfers\Content\StoryQuery;
use Tlab\StoryblokTransfers\Content\Version;

final class StoryQueryTest extends TestCase
{
    public function testCarriesTheSharedOptionsThrough(): void
    {
        $query = new StoryQuery(new ContentOptions(Version::Draft, 'de'));

        $params = $query->toQuery();

        self::assertSame('draft', $params['version']);
        self::assertSame('de', $params['language']);
    }

    public function testAlwaysEmitsPageAndPerPage(): void
    {
        $params = (new StoryQuery())->toQuery();

        self::assertSame('1', $params['page']);
        self::assertSame('25', $params['per_page']);
    }

    public function testEmitsStartsWithAndSortBy(): void
    {
        $query = new StoryQuery(startsWith: 'blog/', sortBy: 'published_at:desc');

        $params = $query->toQuery();

        self::assertSame('blog/', $params['starts_with']);
        self::assertSame('published_at:desc', $params['sort_by']);
    }

    public function testJoinsUuidListsAndExcludedFieldsWithCommas(): void
    {
        $query = new StoryQuery(byUuids: ['a', 'b'], excludingFields: ['body', 'seo']);

        $params = $query->toQuery();

        self::assertSame('a,b', $params['by_uuids']);
        self::assertSame('body,seo', $params['excluding_fields']);
    }

    public function testOmitsEverythingThatWasNotSet(): void
    {
        $params = (new StoryQuery())->toQuery();

        self::assertArrayNotHasKey('starts_with', $params);
        self::assertArrayNotHasKey('sort_by', $params);
        self::assertArrayNotHasKey('by_uuids', $params);
        self::assertArrayNotHasKey('excluding_fields', $params);
    }

    public function testFlattensTheFilterQueryIntoBracketKeys(): void
    {
        $query = new StoryQuery(filterQuery: [
            'component' => ['in' => 'page'],
            'headline' => ['like' => '*news*'],
        ]);

        $params = $query->toQuery();

        self::assertSame('page', $params['filter_query[component][in]']);
        self::assertSame('*news*', $params['filter_query[headline][like]']);
    }

    public function testWithPageReturnsANewQueryAndKeepsEverythingElse(): void
    {
        $original = new StoryQuery(startsWith: 'blog/', perPage: 10);
        $second = $original->withPage(2);

        self::assertNotSame($original, $second);
        self::assertSame('1', $original->toQuery()['page']);
        self::assertSame('2', $second->toQuery()['page']);
        self::assertSame('blog/', $second->toQuery()['starts_with']);
        self::assertSame('10', $second->toQuery()['per_page']);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `docker compose run --rm php vendor/bin/phpunit --filter StoryQueryTest`
Expected: FAIL — `Class "Tlab\StoryblokTransfers\Content\StoryQuery" not found`

- [ ] **Step 3: Write StoryQuery and StoryList**

Create `src/Content/StoryQuery.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Content;

/**
 * The parameters of a story listing.
 *
 * A value object rather than a dozen optional method arguments, which would be
 * unreadable and unextendable - and it turns the parameters into a map the
 * client can sort, which is what makes a caching decorator's key stable.
 *
 * Composes ContentOptions rather than repeating its fields, because version,
 * language and relation resolution are not listing concerns.
 */
final class StoryQuery
{
    /**
     * @param array<string, array<string, string>> $filterQuery Field =>
     *        operation => value, flattened into Storyblok's bracket parameters.
     * @param list<string> $byUuids
     * @param list<string> $excludingFields
     */
    public function __construct(
        public readonly ContentOptions $options = new ContentOptions(),
        public readonly ?string $startsWith = null,
        public readonly array $filterQuery = [],
        public readonly ?string $sortBy = null,
        public readonly array $byUuids = [],
        public readonly array $excludingFields = [],
        public readonly int $page = 1,
        public readonly int $perPage = 25,
    ) {
    }

    /**
     * The one wither worth having: walking pages is the only thing that changes
     * a query after it is built.
     */
    public function withPage(int $page): self
    {
        return new self(
            $this->options,
            $this->startsWith,
            $this->filterQuery,
            $this->sortBy,
            $this->byUuids,
            $this->excludingFields,
            $page,
            $this->perPage,
        );
    }

    /**
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        $query = $this->options->toQuery();
        $query['page'] = (string) $this->page;
        $query['per_page'] = (string) $this->perPage;

        if ($this->startsWith !== null) {
            $query['starts_with'] = $this->startsWith;
        }

        if ($this->sortBy !== null) {
            $query['sort_by'] = $this->sortBy;
        }

        if ($this->byUuids !== []) {
            $query['by_uuids'] = implode(',', $this->byUuids);
        }

        if ($this->excludingFields !== []) {
            $query['excluding_fields'] = implode(',', $this->excludingFields);
        }

        foreach ($this->filterQuery as $field => $operations) {
            foreach ($operations as $operation => $value) {
                $query[sprintf('filter_query[%s][%s]', $field, $operation)] = $value;
            }
        }

        return $query;
    }
}
```

Create `src/Content/StoryList.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Content;

use ArrayIterator;
use IteratorAggregate;
use Tlab\TransferObjects\AbstractTransfer;
use Traversable;

/**
 * One page of stories, plus the totals the CDA reports in its headers.
 *
 * Holds the RelationMap that every story on the page shares, so a relation
 * resolved once is not duplicated per story.
 *
 * @template T of AbstractTransfer
 * @implements IteratorAggregate<int, StoryTransfer<T>>
 */
final class StoryList implements IteratorAggregate
{
    /**
     * @param list<StoryTransfer<T>> $stories
     */
    public function __construct(
        private readonly array $stories,
        private readonly int $total,
        private readonly int $page,
        private readonly int $perPage,
        private readonly RelationMap $relations,
    ) {
    }

    /**
     * @return list<StoryTransfer<T>>
     */
    public function getStories(): array
    {
        return $this->stories;
    }

    /**
     * Across every page, not just this one.
     */
    public function getTotal(): int
    {
        return $this->total;
    }

    public function getPage(): int
    {
        return $this->page;
    }

    public function getPerPage(): int
    {
        return $this->perPage;
    }

    /**
     * The same instance every story on this page holds.
     */
    public function getRelations(): RelationMap
    {
        return $this->relations;
    }

    /**
     * @return Traversable<int, StoryTransfer<T>>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->stories);
    }
}
```

- [ ] **Step 4: Run the query test to verify it passes**

Run: `docker compose run --rm php vendor/bin/phpunit --filter StoryQueryTest`
Expected: PASS

- [ ] **Step 5: Write the failing listing test**

Create `tests/Unit/StoryListingTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tlab\StoryblokTransfers\Client\ContentResponse;
use Tlab\StoryblokTransfers\Client\StoryblokApiException;
use Tlab\StoryblokTransfers\Content\RelationMapFactory;
use Tlab\StoryblokTransfers\Content\StoryMapper;
use Tlab\StoryblokTransfers\Content\StoryQuery;
use Tlab\StoryblokTransfers\Content\StoryRepository;
use Tlab\StoryblokTransfers\Hydration\ComponentClassResolver;
use Tlab\StoryblokTransfers\Hydration\StoryblokHydrator;
use Tlab\StoryblokTransfers\Tests\Fixture\FakeContentClient;
use Tlab\StoryblokTransfers\Tests\Fixture\NestedFixtureTransfer;

final class StoryListingTest extends TestCase
{
    private const FIXTURE_NAMESPACE = 'Tlab\\StoryblokTransfers\\Tests\\Fixture';

    public function testAsksTheStoriesEndpointWithTheQuery(): void
    {
        $client = FakeContentClient::returning($this->listResponse());

        $this->repository($client)->findBy(new StoryQuery(startsWith: 'blog/'));

        self::assertSame('cdn/stories', $client->requests[0]['path']);
        self::assertSame('blog/', $client->requests[0]['query']['starts_with']);
    }

    public function testReturnsTheHydratedStories(): void
    {
        $client = FakeContentClient::returning($this->listResponse());

        $list = $this->repository($client)->findBy(new StoryQuery());

        self::assertCount(2, $list->getStories());
        self::assertSame('uuid-1', $list->getStories()[0]->getUuid());
        self::assertInstanceOf(NestedFixtureTransfer::class, $list->getStories()[1]->getContent());
        self::assertSame('Second', $list->getStories()[1]->getContent()->getHeadline());
    }

    public function testIsIterable(): void
    {
        $client = FakeContentClient::returning($this->listResponse());

        $headlines = [];

        foreach ($this->repository($client)->findBy(new StoryQuery()) as $story) {
            $content = $story->getContent();
            self::assertInstanceOf(NestedFixtureTransfer::class, $content);
            $headlines[] = $content->getHeadline();
        }

        self::assertSame(['First', 'Second'], $headlines);
    }

    public function testTakesTheTotalsFromTheResponseHeaders(): void
    {
        $client = FakeContentClient::returning($this->listResponse(total: 137, perPage: 25));

        $list = $this->repository($client)->findBy(new StoryQuery(page: 3, perPage: 25));

        self::assertSame(137, $list->getTotal());
        self::assertSame(25, $list->getPerPage());
        self::assertSame(3, $list->getPage());
    }

    public function testFallsBackToTheReturnedCountWhenNoTotalHeaderArrived(): void
    {
        $client = FakeContentClient::returning($this->listResponse(total: null, perPage: null));

        $list = $this->repository($client)->findBy(new StoryQuery(perPage: 50));

        self::assertSame(2, $list->getTotal());
        self::assertSame(50, $list->getPerPage());
    }

    public function testReturnsAnEmptyListRatherThanNullWhenNothingMatches(): void
    {
        $client = FakeContentClient::returning(new ContentResponse(['stories' => []], 0, 25));

        $list = $this->repository($client)->findBy(new StoryQuery());

        self::assertSame([], $list->getStories());
        self::assertSame(0, $list->getTotal());
    }

    public function testSharesOneRelationMapAcrossEveryStoryOnThePage(): void
    {
        $client = FakeContentClient::returning(new ContentResponse([
            'stories' => [
                $this->storyWithAuthor('uuid-1', 'First', 'author-1'),
                $this->storyWithAuthor('uuid-2', 'Second', 'author-2'),
            ],
            'rels' => [
                $this->rel('author-1', 'Jane'),
                $this->rel('author-2', 'Ravi'),
            ],
        ], 2, 25));

        $list = $this->repository($client)->findBy(new StoryQuery());
        [$first, $second] = $list->getStories();

        // The same instance, not an equal copy: a relation resolved once for the
        // page is held once.
        self::assertSame($first->getRelations(), $second->getRelations());
        self::assertSame($list->getRelations(), $first->getRelations());

        // And every story can reach every relation on the page.
        $ravi = $first->getRelation('author-2');
        self::assertInstanceOf(NestedFixtureTransfer::class, $ravi);
        self::assertSame('Ravi', $ravi->getHeadline());
    }

    public function testThrowsWhenTheResponseHasNoStoriesKey(): void
    {
        $client = FakeContentClient::returning(new ContentResponse(['unexpected' => true]));

        $this->expectException(StoryblokApiException::class);
        $this->expectExceptionMessageMatches('/stories/');

        $this->repository($client)->findBy(new StoryQuery());
    }

    private function repository(FakeContentClient $client): StoryRepository
    {
        $resolver = new ComponentClassResolver(self::FIXTURE_NAMESPACE);
        $hydrator = new StoryblokHydrator(self::FIXTURE_NAMESPACE);

        return new StoryRepository(
            $client,
            new StoryMapper($resolver, $hydrator),
            new RelationMapFactory($resolver, $hydrator),
        );
    }

    private function listResponse(?int $total = 2, ?int $perPage = 25): ContentResponse
    {
        return new ContentResponse([
            'stories' => [
                [
                    'uuid' => 'uuid-1',
                    'slug' => 'first',
                    'full_slug' => 'blog/first',
                    'content' => ['component' => 'nested_fixture', 'headline' => 'First'],
                ],
                [
                    'uuid' => 'uuid-2',
                    'slug' => 'second',
                    'full_slug' => 'blog/second',
                    'content' => ['component' => 'nested_fixture', 'headline' => 'Second'],
                ],
            ],
        ], $total, $perPage);
    }

    /**
     * The story keeps the plain uuid the CDA leaves in `content`. The resolved
     * story goes in the response's `rels`, which is where resolve_relations
     * actually puts it.
     *
     * @return array<string, mixed>
     */
    private function storyWithAuthor(string $uuid, string $headline, string $authorUuid): array
    {
        return [
            'uuid' => $uuid,
            'slug' => $headline,
            'full_slug' => 'blog/' . $headline,
            'content' => [
                'component' => 'nested_fixture',
                'headline' => $headline,
                'author' => $authorUuid,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rel(string $uuid, string $headline): array
    {
        return [
            'uuid' => $uuid,
            'content' => ['component' => 'nested_fixture', 'headline' => $headline],
        ];
    }
}
```

- [ ] **Step 6: Run it to verify it fails**

Run: `docker compose run --rm php vendor/bin/phpunit --filter StoryListingTest`
Expected: FAIL — `Call to undefined method ...StoryRepository::findBy()`

- [ ] **Step 7: Add mapList() to StoryMapper**

Append to `src/Content/StoryMapper.php`, after `mapOne()`:

```php
    /**
     * Every story on the page gets the same RelationMap instance, which is why
     * this is a plain loop: the relations arrive already resolved in the
     * response's `rels` array, so there is nothing to collect across stories
     * first. The shared map is the caller's, not built here.
     *
     * @param list<array<string, mixed>> $stories
     * @param RelationMap $relations Built once from the response root.
     * @param class-string<AbstractTransfer>|null $expected
     *
     * @return StoryList<AbstractTransfer>
     *
     * @throws UnresolvableComponentException
     * @throws UnexpectedComponentException
     * @throws StoryblokApiException
     */
    public function mapList(
        array $stories,
        RelationMap $relations,
        int $total,
        int $page,
        int $perPage,
        ?string $expected = null
    ): StoryList {
        $envelopes = [];

        foreach ($stories as $story) {
            $envelopes[] = $this->mapOne($story, $relations, $expected);
        }

        return new StoryList($envelopes, $total, $page, $perPage, $relations);
    }
```

- [ ] **Step 8: Add findBy() to StoryRepository**

Append to `src/Content/StoryRepository.php`, after `byUuid()`:

```php
    /**
     * A query that matches nothing returns an empty list with a total of zero.
     * Only a single-story lookup has a "does not exist" case.
     *
     * The template carries a default for the same reason bySlug() does: without
     * one, PHPStan cannot bind T at the call sites that omit $expected - which
     * is the default usage.
     *
     * @template T of AbstractTransfer = AbstractTransfer
     *
     * @param class-string<T>|null $expected
     *
     * @return StoryList<T>
     *
     * @throws StoryblokApiException
     * @throws UnresolvableComponentException
     * @throws UnexpectedComponentException
     */
    public function findBy(StoryQuery $query, ?string $expected = null): StoryList
    {
        $response = $this->client->get('cdn/stories', $query->toQuery());
        $stories = $response->body['stories'] ?? null;

        if (!is_array($stories)) {
            throw new StoryblokApiException('No "stories" key in the Storyblok response for cdn/stories');
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = array_values(array_filter($stories, 'is_array'));

        /** @var StoryList<T> $list */
        $list = $this->mapper->mapList(
            $rows,
            $this->relationMap($response),
            $response->total ?? count($rows),
            $query->page,
            $response->perPage ?? $query->perPage,
            $expected
        );

        return $list;
    }
```

- [ ] **Step 9: Run the listing test to verify it passes**

Run: `docker compose run --rm php vendor/bin/phpunit --filter StoryListingTest`
Expected: PASS

- [ ] **Step 10: Run the whole suite and the linters**

Run: `make test && make stan && make cs`
Expected: all green.

- [ ] **Step 11: Commit**

```bash
git add src/Content/StoryQuery.php src/Content/StoryList.php src/Content/StoryMapper.php src/Content/StoryRepository.php tests/Unit/StoryQueryTest.php tests/Unit/StoryListingTest.php
git commit -m "Read a page of stories, sharing one relation map across it"
```

---

### Task 10: The links tree

**Files:**
- Create: `src/Content/LinkEntry.php`, `src/Content/LinkRepository.php`
- Create: `tests/Unit/LinkRepositoryTest.php`

**Interfaces:**
- Consumes: `ContentClient`, `ContentResponse` (Task 4), `ContentOptions` (Task 3)
- Produces:
  - `LinkEntry::fromPayload(array $payload): ?self`, getters `getUuid()`, `getSlug()`, `getName()`, `getId()`, `getParentId()`, `getPosition()`, `getRealPath()`, `isFolder()`, `isPublished()`, `isStartpage()`
  - `LinkRepository::__construct(ContentClient $client, ContentOptions $defaults = new ContentOptions())`
  - `LinkRepository::all(?string $startsWith = null, ?ContentOptions $options = null): array` returning `list<LinkEntry>`

**The shape that matters:** `GET /cdn/links` returns `links` as an **object keyed by uuid**, not a list. The repository returns the values in the order the API sent them.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/LinkRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tlab\StoryblokTransfers\Client\ContentResponse;
use Tlab\StoryblokTransfers\Client\StoryblokApiException;
use Tlab\StoryblokTransfers\Content\ContentOptions;
use Tlab\StoryblokTransfers\Content\LinkRepository;
use Tlab\StoryblokTransfers\Content\Version;
use Tlab\StoryblokTransfers\Tests\Fixture\FakeContentClient;

final class LinkRepositoryTest extends TestCase
{
    public function testAsksTheLinksEndpoint(): void
    {
        $client = FakeContentClient::returning($this->linksResponse());

        (new LinkRepository($client))->all();

        self::assertSame('cdn/links', $client->requests[0]['path']);
        self::assertSame('published', $client->requests[0]['query']['version']);
    }

    public function testPassesStartsWithWhenGiven(): void
    {
        $client = FakeContentClient::returning($this->linksResponse());

        (new LinkRepository($client))->all('blog/');

        self::assertSame('blog/', $client->requests[0]['query']['starts_with']);
    }

    public function testOmitsStartsWithWhenNotGiven(): void
    {
        $client = FakeContentClient::returning($this->linksResponse());

        (new LinkRepository($client))->all();

        self::assertArrayNotHasKey('starts_with', $client->requests[0]['query']);
    }

    public function testUsesPerCallOptionsOverTheDefaults(): void
    {
        $client = FakeContentClient::returning($this->linksResponse());
        $repository = new LinkRepository($client, new ContentOptions(Version::Draft));

        $repository->all(null, new ContentOptions(Version::Published, 'de'));

        self::assertSame('published', $client->requests[0]['query']['version']);
        self::assertSame('de', $client->requests[0]['query']['language']);
    }

    public function testTurnsTheUuidKeyedObjectIntoAListInApiOrder(): void
    {
        $entries = (new LinkRepository(FakeContentClient::returning($this->linksResponse())))->all();

        self::assertCount(3, $entries);
        self::assertSame(['u-home', 'u-blog', 'u-post'], array_map(
            static fn ($entry): string => $entry->getUuid(),
            $entries
        ));
    }

    public function testMapsEveryFieldOfAnEntry(): void
    {
        $entries = (new LinkRepository(FakeContentClient::returning($this->linksResponse())))->all();
        $blog = $entries[1];

        self::assertSame('u-blog', $blog->getUuid());
        self::assertSame('blog', $blog->getSlug());
        self::assertSame('Blog', $blog->getName());
        self::assertSame(2, $blog->getId());
        self::assertSame(1, $blog->getParentId());
        self::assertSame(10, $blog->getPosition());
        self::assertSame('/blog', $blog->getRealPath());
        self::assertTrue($blog->isFolder());
        self::assertFalse($blog->isPublished());
        self::assertFalse($blog->isStartpage());
    }

    public function testDefaultsTheOptionalFieldsOfASparseEntry(): void
    {
        $client = FakeContentClient::returning(new ContentResponse([
            'links' => ['u-1' => ['uuid' => 'u-1', 'slug' => 'x', 'name' => 'X']],
        ]));

        $entry = (new LinkRepository($client))->all()[0];

        self::assertNull($entry->getId());
        self::assertNull($entry->getParentId());
        self::assertNull($entry->getRealPath());
        self::assertFalse($entry->isFolder());
        self::assertSame(0, $entry->getPosition());
    }

    public function testSkipsEntriesThatAreNotUsableLinks(): void
    {
        $client = FakeContentClient::returning(new ContentResponse([
            'links' => [
                'u-1' => ['uuid' => 'u-1', 'slug' => 'a', 'name' => 'A'],
                'broken' => 'not an array',
                'u-2' => ['slug' => 'no-uuid'],
            ],
        ]));

        $entries = (new LinkRepository($client))->all();

        self::assertCount(1, $entries);
        self::assertSame('u-1', $entries[0]->getUuid());
    }

    public function testThrowsWhenTheResponseHasNoLinksKey(): void
    {
        $client = FakeContentClient::returning(new ContentResponse(['unexpected' => true]));

        $this->expectException(StoryblokApiException::class);
        $this->expectExceptionMessageMatches('/links/');

        (new LinkRepository($client))->all();
    }

    private function linksResponse(): ContentResponse
    {
        return new ContentResponse([
            'links' => [
                'u-home' => [
                    'id' => 1,
                    'uuid' => 'u-home',
                    'slug' => 'home',
                    'name' => 'Home',
                    'is_folder' => false,
                    'parent_id' => null,
                    'published' => true,
                    'position' => 0,
                    'real_path' => '/home',
                    'is_startpage' => true,
                ],
                'u-blog' => [
                    'id' => 2,
                    'uuid' => 'u-blog',
                    'slug' => 'blog',
                    'name' => 'Blog',
                    'is_folder' => true,
                    'parent_id' => 1,
                    'published' => false,
                    'position' => 10,
                    'real_path' => '/blog',
                    'is_startpage' => false,
                ],
                'u-post' => [
                    'id' => 3,
                    'uuid' => 'u-post',
                    'slug' => 'blog/first',
                    'name' => 'First',
                    'is_folder' => false,
                    'parent_id' => 2,
                    'published' => true,
                    'position' => 20,
                    'real_path' => '/blog/first',
                    'is_startpage' => false,
                ],
            ],
        ]);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `docker compose run --rm php vendor/bin/phpunit --filter LinkRepositoryTest`
Expected: FAIL — `Class "Tlab\StoryblokTransfers\Content\LinkRepository" not found`

- [ ] **Step 3: Write LinkEntry**

Create `src/Content/LinkEntry.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Content;

/**
 * One entry of the links tree - navigation and sitemap structure without
 * fetching any content.
 *
 * Its own type rather than a reuse of LinkTransfer: that models a link *field*
 * inside a component, which is a different payload with different keys.
 */
final class LinkEntry
{
    public function __construct(
        private readonly string $uuid,
        private readonly string $slug,
        private readonly string $name,
        private readonly bool $isFolder = false,
        private readonly ?int $id = null,
        private readonly ?int $parentId = null,
        private readonly bool $published = false,
        private readonly int $position = 0,
        private readonly ?string $realPath = null,
        private readonly bool $isStartpage = false,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return self|null Null when the payload is not a usable link, which the
     *                   repository skips rather than failing the whole tree on.
     */
    public static function fromPayload(array $payload): ?self
    {
        $uuid = $payload['uuid'] ?? null;

        if (!is_string($uuid) || $uuid === '') {
            return null;
        }

        return new self(
            $uuid,
            is_string($payload['slug'] ?? null) ? $payload['slug'] : '',
            is_string($payload['name'] ?? null) ? $payload['name'] : '',
            ($payload['is_folder'] ?? false) === true,
            is_int($payload['id'] ?? null) ? $payload['id'] : null,
            is_int($payload['parent_id'] ?? null) ? $payload['parent_id'] : null,
            ($payload['published'] ?? false) === true,
            is_int($payload['position'] ?? null) ? $payload['position'] : 0,
            is_string($payload['real_path'] ?? null) ? $payload['real_path'] : null,
            ($payload['is_startpage'] ?? false) === true,
        );
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getParentId(): ?int
    {
        return $this->parentId;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getRealPath(): ?string
    {
        return $this->realPath;
    }

    public function isFolder(): bool
    {
        return $this->isFolder;
    }

    public function isPublished(): bool
    {
        return $this->published;
    }

    public function isStartpage(): bool
    {
        return $this->isStartpage;
    }
}
```

- [ ] **Step 4: Write LinkRepository**

Create `src/Content/LinkRepository.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Content;

use Tlab\StoryblokTransfers\Client\ContentClient;
use Tlab\StoryblokTransfers\Client\StoryblokApiException;

/**
 * Reads the links tree: the structure of a space without its content.
 */
final class LinkRepository
{
    public function __construct(
        private readonly ContentClient $client,
        private readonly ContentOptions $defaults = new ContentOptions(),
    ) {
    }

    /**
     * The CDA returns `links` as an object keyed by uuid, not as a list. The
     * values come back in the order they arrived, and an entry that is not a
     * usable link is skipped rather than failing the whole tree.
     *
     * @param string|null $startsWith Restrict to a subtree, e.g. 'blog/'.
     *
     * @return list<LinkEntry>
     *
     * @throws StoryblokApiException
     */
    public function all(?string $startsWith = null, ?ContentOptions $options = null): array
    {
        $query = ($options ?? $this->defaults)->toQuery();

        if ($startsWith !== null) {
            $query['starts_with'] = $startsWith;
        }

        $response = $this->client->get('cdn/links', $query);
        $links = $response->body['links'] ?? null;

        if (!is_array($links)) {
            throw new StoryblokApiException('No "links" key in the Storyblok response for cdn/links');
        }

        $entries = [];

        foreach ($links as $link) {
            if (!is_array($link)) {
                continue;
            }

            /** @var array<string, mixed> $link */
            $entry = LinkEntry::fromPayload($link);

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `docker compose run --rm php vendor/bin/phpunit --filter LinkRepositoryTest`
Expected: PASS

- [ ] **Step 6: Run the linters, then commit**

Run: `make stan && make cs`

```bash
git add src/Content/LinkEntry.php src/Content/LinkRepository.php tests/Unit/LinkRepositoryTest.php
git commit -m "Read the links tree"
```

---

### Task 11: Datasource entries

**Files:**
- Create: `src/Content/DatasourceEntry.php`, `src/Content/DatasourceRepository.php`
- Create: `tests/Unit/DatasourceRepositoryTest.php`

**Interfaces:**
- Consumes: `ContentClient`, `ContentResponse` (Task 4), `ContentOptions` (Task 3)
- Produces:
  - `DatasourceEntry::fromPayload(array $payload): ?self`, getters `getName()`, `getValue()`, `getId()`, `getDimensionValue()`
  - `DatasourceRepository::__construct(ContentClient $client, ContentOptions $defaults = new ContentOptions())`
  - `DatasourceRepository::entries(string $datasource, ?string $dimension = null, ?ContentOptions $options = null): array` returning `list<DatasourceEntry>`

**Scope reminder:** this task only *reads* datasources. Generating PHP enums from them is explicitly out of scope and gets its own cycle.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/DatasourceRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tlab\StoryblokTransfers\Client\ContentResponse;
use Tlab\StoryblokTransfers\Client\StoryblokApiException;
use Tlab\StoryblokTransfers\Content\DatasourceRepository;
use Tlab\StoryblokTransfers\Tests\Fixture\FakeContentClient;

final class DatasourceRepositoryTest extends TestCase
{
    public function testAsksTheDatasourceEntriesEndpointForTheDatasource(): void
    {
        $client = FakeContentClient::returning($this->entriesResponse());

        (new DatasourceRepository($client))->entries('categories');

        self::assertSame('cdn/datasource_entries', $client->requests[0]['path']);
        self::assertSame('categories', $client->requests[0]['query']['datasource']);
    }

    public function testPassesTheDimensionWhenGiven(): void
    {
        $client = FakeContentClient::returning($this->entriesResponse());

        (new DatasourceRepository($client))->entries('categories', 'de');

        self::assertSame('de', $client->requests[0]['query']['dimension']);
    }

    public function testOmitsTheDimensionWhenNotGiven(): void
    {
        $client = FakeContentClient::returning($this->entriesResponse());

        (new DatasourceRepository($client))->entries('categories');

        self::assertArrayNotHasKey('dimension', $client->requests[0]['query']);
    }

    public function testMapsTheEntries(): void
    {
        $entries = (new DatasourceRepository(FakeContentClient::returning($this->entriesResponse())))
            ->entries('categories');

        self::assertCount(2, $entries);
        self::assertSame('News', $entries[0]->getName());
        self::assertSame('news', $entries[0]->getValue());
        self::assertSame(1, $entries[0]->getId());
        self::assertNull($entries[0]->getDimensionValue());
        self::assertSame('Nachrichten', $entries[1]->getDimensionValue());
    }

    public function testSkipsEntriesWithNoName(): void
    {
        $client = FakeContentClient::returning(new ContentResponse([
            'datasource_entries' => [
                ['name' => 'Keep', 'value' => 'keep'],
                ['value' => 'nameless'],
                'not an array',
            ],
        ]));

        $entries = (new DatasourceRepository($client))->entries('categories');

        self::assertCount(1, $entries);
        self::assertSame('Keep', $entries[0]->getName());
    }

    public function testReturnsAnEmptyListForADatasourceWithNoEntries(): void
    {
        $client = FakeContentClient::returning(new ContentResponse(['datasource_entries' => []]));

        self::assertSame([], (new DatasourceRepository($client))->entries('empty'));
    }

    public function testThrowsWhenTheResponseHasNoEntriesKey(): void
    {
        $client = FakeContentClient::returning(new ContentResponse(['unexpected' => true]));

        $this->expectException(StoryblokApiException::class);
        $this->expectExceptionMessageMatches('/datasource_entries/');

        (new DatasourceRepository($client))->entries('categories');
    }

    private function entriesResponse(): ContentResponse
    {
        return new ContentResponse([
            'datasource_entries' => [
                ['id' => 1, 'name' => 'News', 'value' => 'news', 'dimension_value' => null],
                ['id' => 2, 'name' => 'Events', 'value' => 'events', 'dimension_value' => 'Nachrichten'],
            ],
        ]);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `docker compose run --rm php vendor/bin/phpunit --filter DatasourceRepositoryTest`
Expected: FAIL — `Class "Tlab\StoryblokTransfers\Content\DatasourceRepository" not found`

- [ ] **Step 3: Write DatasourceEntry**

Create `src/Content/DatasourceEntry.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Content;

/**
 * One key/value pair of a Storyblok datasource.
 *
 * $dimensionValue holds the translation for the requested dimension, and is
 * null when no dimension was asked for or none is set.
 */
final class DatasourceEntry
{
    public function __construct(
        private readonly string $name,
        private readonly string $value,
        private readonly ?int $id = null,
        private readonly ?string $dimensionValue = null,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return self|null Null when the payload carries no name to key it by.
     */
    public static function fromPayload(array $payload): ?self
    {
        $name = $payload['name'] ?? null;

        if (!is_string($name) || $name === '') {
            return null;
        }

        return new self(
            $name,
            is_string($payload['value'] ?? null) ? $payload['value'] : '',
            is_int($payload['id'] ?? null) ? $payload['id'] : null,
            is_string($payload['dimension_value'] ?? null) ? $payload['dimension_value'] : null,
        );
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDimensionValue(): ?string
    {
        return $this->dimensionValue;
    }
}
```

- [ ] **Step 4: Write DatasourceRepository**

Create `src/Content/DatasourceRepository.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Content;

use Tlab\StoryblokTransfers\Client\ContentClient;
use Tlab\StoryblokTransfers\Client\StoryblokApiException;

/**
 * Reads datasource entries.
 *
 * Read only. Turning them into generated PHP enums would change the generator
 * and is a separate piece of work.
 *
 * The shared ContentOptions are passed through, though this endpoint has no use
 * for version or language - it uses dimensions for translation - so in practice
 * only cv matters here.
 */
final class DatasourceRepository
{
    public function __construct(
        private readonly ContentClient $client,
        private readonly ContentOptions $defaults = new ContentOptions(),
    ) {
    }

    /**
     * @param string $datasource The datasource slug.
     * @param string|null $dimension A dimension name, for translated values.
     *
     * @return list<DatasourceEntry>
     *
     * @throws StoryblokApiException
     */
    public function entries(string $datasource, ?string $dimension = null, ?ContentOptions $options = null): array
    {
        $query = ($options ?? $this->defaults)->toQuery();
        $query['datasource'] = $datasource;

        if ($dimension !== null) {
            $query['dimension'] = $dimension;
        }

        $response = $this->client->get('cdn/datasource_entries', $query);
        $rows = $response->body['datasource_entries'] ?? null;

        if (!is_array($rows)) {
            throw new StoryblokApiException(
                'No "datasource_entries" key in the Storyblok response for cdn/datasource_entries'
            );
        }

        $entries = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            /** @var array<string, mixed> $row */
            $entry = DatasourceEntry::fromPayload($row);

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `docker compose run --rm php vendor/bin/phpunit --filter DatasourceRepositoryTest`
Expected: PASS

- [ ] **Step 6: Run the linters, then commit**

Run: `make stan && make cs`

```bash
git add src/Content/DatasourceEntry.php src/Content/DatasourceRepository.php tests/Unit/DatasourceRepositoryTest.php
git commit -m "Read datasource entries"
```

---

### Task 12: The factory

**Files:**
- Create: `src/Content/StoryblokContent.php`
- Create: `tests/Unit/StoryblokContentTest.php`

**Interfaces:**
- Consumes: everything from Tasks 3–11
- Produces:
  - `StoryblokContent::create(string $deliveryToken, string $namespace, ?ContentOptions $defaults = null, ?string $baseUri = null, ?ClientInterface $httpClient = null): self`
  - `StoryblokContent::fromEnvironment(): self`
  - `StoryblokContent::usingClient(ContentClient $client, string $namespace, ?ContentOptions $defaults = null): self` — the hook a caching decorator goes through
  - `StoryblokContent::stories(): StoryRepository`, `links(): LinkRepository`, `datasources(): DatasourceRepository`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/StoryblokContentTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tlab\StoryblokTransfers\Client\ContentResponse;
use Tlab\StoryblokTransfers\Content\ContentOptions;
use Tlab\StoryblokTransfers\Content\StoryblokContent;
use Tlab\StoryblokTransfers\Content\Version;
use Tlab\StoryblokTransfers\Tests\Fixture\FakeContentClient;
use Tlab\StoryblokTransfers\Tests\Fixture\NestedFixtureTransfer;

final class StoryblokContentTest extends TestCase
{
    private const FIXTURE_NAMESPACE = 'Tlab\\StoryblokTransfers\\Tests\\Fixture';

    protected function tearDown(): void
    {
        foreach (
            [
            'STORYBLOK_DELIVERY_TOKEN',
            'STORYBLOK_NAMESPACE',
            'STORYBLOK_CONTENT_BASE_URI',
            'STORYBLOK_DEFAULT_VERSION',
            ] as $key
        ) {
            putenv($key);
        }
    }

    public function testWiresAWorkingStoryRepository(): void
    {
        $client = FakeContentClient::returning(new ContentResponse([
            'story' => [
                'uuid' => 'u',
                'slug' => 'home',
                'full_slug' => 'home',
                'content' => ['component' => 'nested_fixture', 'headline' => 'Wired'],
            ],
        ]));

        $story = StoryblokContent::usingClient($client, self::FIXTURE_NAMESPACE)
            ->stories()
            ->bySlug('home');

        self::assertNotNull($story);
        self::assertInstanceOf(NestedFixtureTransfer::class, $story->getContent());
        self::assertSame('Wired', $story->getContent()->getHeadline());
    }

    public function testReturnsTheSameRepositoryInstanceEachTime(): void
    {
        $content = StoryblokContent::usingClient(new FakeContentClient(), self::FIXTURE_NAMESPACE);

        self::assertSame($content->stories(), $content->stories());
        self::assertSame($content->links(), $content->links());
        self::assertSame($content->datasources(), $content->datasources());
    }

    public function testPassesTheDefaultOptionsToEveryRepository(): void
    {
        $client = FakeContentClient::returning(
            new ContentResponse(['links' => []]),
            new ContentResponse(['datasource_entries' => []]),
        );

        $content = StoryblokContent::usingClient(
            $client,
            self::FIXTURE_NAMESPACE,
            new ContentOptions(Version::Draft),
        );

        $content->links()->all();
        $content->datasources()->entries('categories');

        self::assertSame('draft', $client->requests[0]['query']['version']);
        self::assertSame('draft', $client->requests[1]['query']['version']);
    }

    public function testReadsItsConfigurationFromTheEnvironment(): void
    {
        putenv('STORYBLOK_DELIVERY_TOKEN=env-token');
        putenv('STORYBLOK_NAMESPACE=' . self::FIXTURE_NAMESPACE);
        putenv('STORYBLOK_DEFAULT_VERSION=draft');

        $content = StoryblokContent::fromEnvironment();

        // Nothing is sent, so this only proves the wiring did not throw and that
        // the version default took.
        self::assertSame(Version::Draft, $content->defaults()->version);
    }

    public function testFallsBackToThePublishedVersionForAnUnknownVersionValue(): void
    {
        putenv('STORYBLOK_DELIVERY_TOKEN=env-token');
        putenv('STORYBLOK_DEFAULT_VERSION=nonsense');

        self::assertSame(Version::Published, StoryblokContent::fromEnvironment()->defaults()->version);
    }

    public function testThrowsWhenTheDeliveryTokenIsMissing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/STORYBLOK_DELIVERY_TOKEN/');

        StoryblokContent::fromEnvironment();
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `docker compose run --rm php vendor/bin/phpunit --filter StoryblokContentTest`
Expected: FAIL — `Class "Tlab\StoryblokTransfers\Content\StoryblokContent" not found`

- [ ] **Step 3: Write the factory**

Create `src/Content/StoryblokContent.php`:

```php
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
     *                                      pass its own; null means published,
     *                                      no language, no relations resolved.
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
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `docker compose run --rm php vendor/bin/phpunit --filter StoryblokContentTest`
Expected: PASS

- [ ] **Step 5: Run the whole suite and the linters**

Run: `make test && make stan && make cs`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add src/Content/StoryblokContent.php tests/Unit/StoryblokContentTest.php
git commit -m "Wire the content repositories for consumers without a container"
```

---

### Task 13: End-to-end integration test against generated classes

**Files:**
- Create: `tests/Integration/StoryRepositoryIntegrationTest.php`

**Interfaces:**
- Consumes: `StoryblokTransferGenerator` (existing), `StoryblokContent` (Task 12), `FakeContentClient` (Task 8), `TempDirectory` trait (existing)
- Produces: nothing new — this is the test that proves the chain works against real generator output rather than hand-written fixtures

**Why this exists:** every unit test above uses the hand-written fixture transfers. This one generates classes from a schema and drives the repository against them, so it fails if the *generated* shape ever drifts from what the content layer assumes.

- [ ] **Step 1: Write the test**

Create `tests/Integration/StoryRepositoryIntegrationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Tlab\StoryblokTransfers\Client\ContentResponse;
use Tlab\StoryblokTransfers\Content\StoryblokContent;
use Tlab\StoryblokTransfers\Content\StoryQuery;
use Tlab\StoryblokTransfers\Content\StoryRepository;
use Tlab\StoryblokTransfers\Content\UnresolvableComponentException;
use Tlab\StoryblokTransfers\StoryblokTransferGenerator;
use Tlab\StoryblokTransfers\Tests\Fixture\FakeContentClient;
use Tlab\StoryblokTransfers\Tests\TempDirectory;
use Tlab\StoryblokTransfers\Transfers\AssetTransfer;
use Tlab\TransferObjects\AbstractTransfer;

/**
 * The content layer against real generator output.
 *
 * The unit tests all run against the hand-written fixture transfers, which
 * cannot tell us whether the generated shape still matches what the repository
 * assumes. This generates classes from a schema and reads stories into them.
 */
final class StoryRepositoryIntegrationTest extends TestCase
{
    use TempDirectory;

    private string $namespace;

    private string $definitionsPath;

    private string $outputPath;

    protected function setUp(): void
    {
        // A namespace per test: require_once guards by file path, and every test
        // generates into a fresh temp directory, so a shared namespace would make
        // the second test fatally redeclare the first test's classes.
        $this->namespace = 'ContentGen\\' . ucfirst($this->name());
        $this->definitionsPath = $this->makeTempDir('content-def');
        $this->outputPath = $this->makeTempDir('content-out');
    }

    protected function tearDown(): void
    {
        $this->removeTempDirs();
    }

    public function testReadsAStoryIntoItsGeneratedClass(): void
    {
        $this->generate([
            ['name' => 'page', 'schema' => ['headline' => ['type' => 'text']]],
        ]);

        $story = $this->stories(new ContentResponse([
            'story' => [
                'uuid' => 'u-1',
                'slug' => 'home',
                'full_slug' => 'en/home',
                'name' => 'Home',
                'lang' => 'en',
                'published_at' => '2026-08-01 10:00:00',
                'content' => ['component' => 'page', 'headline' => 'Hello'],
            ],
        ]))->bySlug('home');

        self::assertNotNull($story);
        self::assertSame($this->namespace . '\\PageTransfer', $story->getContent()::class);
        self::assertSame('Hello', $story->getContent()->toArray()['headline']);
        self::assertSame('en/home', $story->getFullSlug());
        self::assertSame('en', $story->getLang());
        self::assertSame('2026-08-01 10:00:00', $story->getPublishedAt());
    }

    public function testHydratesABundledTransferInsideAGeneratedClass(): void
    {
        $this->generate([
            ['name' => 'page', 'schema' => ['image' => ['type' => 'asset']]],
        ]);

        $story = $this->stories(new ContentResponse([
            'story' => [
                'uuid' => 'u-1',
                'slug' => 'home',
                'full_slug' => 'home',
                'content' => [
                    'component' => 'page',
                    'image' => ['id' => 9, 'filename' => 'a.jpg', 'alt' => 'A'],
                ],
            ],
        ]))->bySlug('home');

        self::assertNotNull($story);
        $image = $story->getContent()->toArray()['image'];
        self::assertInstanceOf(AssetTransfer::class, $image);
        self::assertSame('a.jpg', $image->getFilename());
    }

    public function testTurnsANestedBlokIntoItsConcreteGeneratedClass(): void
    {
        $this->generate([
            ['name' => 'page', 'schema' => ['body' => ['type' => 'bloks']]],
            ['name' => 'teaser', 'schema' => ['headline' => ['type' => 'text']]],
        ]);

        $story = $this->stories(new ContentResponse([
            'story' => [
                'uuid' => 'u-1',
                'slug' => 'home',
                'full_slug' => 'home',
                'content' => [
                    'component' => 'page',
                    'body' => [['component' => 'teaser', 'headline' => 'Deep']],
                ],
            ],
        ]))->bySlug('home');

        self::assertNotNull($story);
        $body = $story->getContent()->toArray()['body'];
        self::assertIsArray($body);
        self::assertInstanceOf(AbstractTransfer::class, $body[0]);
        self::assertSame($this->namespace . '\\TeaserTransfer', $body[0]::class);
    }

    public function testMakesAResolvedRelationReachableWithoutBreakingTheUuidProperty(): void
    {
        $this->generate([
            ['name' => 'page', 'schema' => ['author' => ['type' => 'option', 'source' => 'internal_stories']]],
            ['name' => 'author', 'schema' => ['headline' => ['type' => 'text']]],
        ]);

        $story = $this->stories(new ContentResponse([
            'story' => [
                'uuid' => 'u-1',
                'slug' => 'home',
                'full_slug' => 'home',
                // The uuid the CDA leaves in place, untouched by resolve_relations.
                'content' => ['component' => 'page', 'author' => 'author-1'],
            ],
            // Where the resolved story actually arrives.
            'rels' => [
                [
                    'uuid' => 'author-1',
                    'full_slug' => 'authors/jane',
                    'content' => ['component' => 'author', 'headline' => 'Jane'],
                ],
            ],
        ]))->bySlug('home');

        self::assertNotNull($story);

        // The generated property is a ?string and still holds the uuid, which is
        // the whole point of keeping relations beside the content.
        self::assertSame('author-1', $story->getContent()->toArray()['author']);

        // assertInstanceOf rather than assertNotNull: getRelation() returns
        // AbstractTransfer|array|null, and only the instance assertion both
        // narrows the type for static analysis and proves the thing this test
        // is actually about - that the relation was hydrated rather than left
        // as a raw array.
        $author = $story->getRelation('author-1');
        self::assertInstanceOf(AbstractTransfer::class, $author);
        self::assertSame($this->namespace . '\\AuthorTransfer', $author::class);
        self::assertSame('Jane', $author->toArray()['headline']);
    }

    public function testSurvivesTheReflectionRoundTripThatCatchesUninitializedProperties(): void
    {
        // toArray(true) walks every property by reflection, which is where a
        // defaultless typed property on a generated class would blow up.
        $this->generate([
            [
                'name' => 'page',
                'schema' => [
                    'headline' => ['type' => 'text'],
                    'image' => ['type' => 'asset'],
                    'body' => ['type' => 'bloks'],
                    'tags' => ['type' => 'options'],
                ],
            ],
        ]);

        $story = $this->stories(new ContentResponse([
            'story' => [
                'uuid' => 'u-1',
                'slug' => 'home',
                'full_slug' => 'home',
                // Deliberately sparse: Storyblok omits untouched fields.
                'content' => ['component' => 'page'],
            ],
        ]))->bySlug('home');

        self::assertNotNull($story);
        self::assertNotSame([], $story->getContent()->toArray(true));
    }

    public function testReadsAListingIntoGeneratedClassesWithOneSharedRelationMap(): void
    {
        $this->generate([
            ['name' => 'post', 'schema' => ['author' => ['type' => 'option', 'source' => 'internal_stories']]],
            ['name' => 'author', 'schema' => ['headline' => ['type' => 'text']]],
        ]);

        $list = $this->stories(new ContentResponse([
            'stories' => [
                [
                    'uuid' => 'p-1',
                    'slug' => 'one',
                    'full_slug' => 'blog/one',
                    'content' => ['component' => 'post', 'author' => 'a-1'],
                ],
                [
                    'uuid' => 'p-2',
                    'slug' => 'two',
                    'full_slug' => 'blog/two',
                    'content' => ['component' => 'post', 'author' => 'a-1'],
                ],
            ],
            // One rels array for the whole page, which is why the shared map is
            // structural here rather than merged together per story.
            'rels' => [
                ['uuid' => 'a-1', 'content' => ['component' => 'author', 'headline' => 'Jane']],
            ],
        ], 2, 25))->findBy(new StoryQuery(startsWith: 'blog/'));

        self::assertCount(2, $list->getStories());
        self::assertSame(2, $list->getTotal());

        [$first, $second] = $list->getStories();
        self::assertSame($first->getRelations(), $second->getRelations());

        // Both stories point at the same uuid and both reach the one resolved
        // author, from the single map built off the response root.
        $author = $second->getRelation('a-1');
        self::assertInstanceOf(AbstractTransfer::class, $author);
        self::assertSame('Jane', $author->toArray()['headline']);
    }

    public function testThrowsWhenTheRootComponentWasNeverGenerated(): void
    {
        $this->generate([
            ['name' => 'page', 'schema' => ['headline' => ['type' => 'text']]],
        ]);

        $this->expectException(UnresolvableComponentException::class);
        $this->expectExceptionMessageMatches('/never_generated/');

        $this->stories(new ContentResponse([
            'story' => [
                'uuid' => 'u-1',
                'slug' => 'home',
                'full_slug' => 'home',
                'content' => ['component' => 'never_generated'],
            ],
        ]))->bySlug('home');
    }

    private function stories(ContentResponse $response): StoryRepository
    {
        return StoryblokContent::usingClient(
            FakeContentClient::returning($response),
            $this->namespace,
        )->stories();
    }

    /**
     * @param list<array<string, mixed>> $components
     */
    private function generate(array $components): void
    {
        $handler = new MockHandler([
            new Response(200, [], (string) json_encode(['components' => $components])),
        ]);

        (new StoryblokTransferGenerator(
            spaceId: '1',
            token: 'token',
            definitionsPath: $this->definitionsPath,
            outputPath: $this->outputPath,
            namespace: $this->namespace,
            httpClient: new Client(['handler' => HandlerStack::create($handler)]),
        ))->generate();

        foreach ((array) glob($this->outputPath . '/*.php') as $file) {
            require_once (string) $file;
        }
    }
}
```

- [ ] **Step 2: Run the test**

Run: `docker compose run --rm php vendor/bin/phpunit --filter StoryRepositoryIntegrationTest`
Expected: PASS. If `testTurnsANestedBlokIntoItsConcreteGeneratedClass` fails on the class name, check the singularisation the generator applied to `body` — the assertion is on the blok's class, not on the property, so it should be unaffected.

- [ ] **Step 3: Add a PHPStan exclusion if needed**

The generated classes do not exist at static-analysis time. `phpstan.neon` already carries an `ignoreErrors` entry of identifier `varTag.nativeType` for `tests/Integration/StoryblokTransferGeneratorTest.php`. If PHPStan reports errors for this new file, add its path to that same entry rather than inventing a new one — and only for the identifier it actually reports.

- [ ] **Step 4: Run the whole suite and the linters**

Run: `make test && make stan && make cs`
Expected: all green.

- [ ] **Step 5: Commit**

```bash
git add tests/Integration/StoryRepositoryIntegrationTest.php phpstan.neon
git commit -m "Prove the content layer against real generator output"
```

---

### Task 14: Rewire the smoke test and delete its shadow fetcher

**Files:**
- Modify: `tools/Configuration.php`, `tools/SmokeTest.php`, `tools/smoke-test.php`
- Delete: `tools/StoryFetcher.php`, `tools/Story.php`

**Interfaces:**
- Consumes: `StoryblokContent` (Task 12), `StoryRepository`, `StoryQuery`, `StoryTransfer`, `ContentOptions`, `Version`
- Produces: a smoke test that exercises the shipped library instead of a parallel implementation

**Why:** `tools/StoryFetcher.php` reads content through the *Management API* because the library had nothing to offer. Now it does, and keeping a second implementation against the wrong API is a liability. This also makes the smoke test the permanent home of the CDA coverage Task 1 probed by hand.

- [ ] **Step 1: Add the delivery token to Configuration**

In `tools/Configuration.php`, add a `deliveryToken` property and require it. Replace the constructor and the tail of `fromEnvironment()`:

```php
    public function __construct(
        public readonly string $spaceId,
        public readonly string $token,
        public readonly string $deliveryToken,
        public readonly string $authorizationScheme = '',
    ) {
    }
```

and in `fromEnvironment()`, after the existing `$token` line:

```php
        $spaceId = $value('STORYBLOK_SPACE_ID');
        $token = $value('STORYBLOK_MANAGEMENT_TOKEN');
        $deliveryToken = $value('STORYBLOK_DELIVERY_TOKEN');

        if ($spaceId === '' || $token === '') {
            throw new SmokeTestFailure(
                'STORYBLOK_SPACE_ID and STORYBLOK_MANAGEMENT_TOKEN are required.',
                'Copy .env.example to .env and fill them in, or export them in your shell.'
            );
        }

        if ($deliveryToken === '') {
            throw new SmokeTestFailure(
                'STORYBLOK_DELIVERY_TOKEN is required: the run reads content through the Content '
                . 'Delivery API now, which does not accept the Management API token.',
                'Storyblok > Settings > Access Tokens gives you a preview token.'
            );
        }

        return new self($spaceId, $token, $deliveryToken, $value('STORYBLOK_AUTH_SCHEME'));
```

- [ ] **Step 2: Replace the fetcher with the repository in SmokeTest**

In `tools/SmokeTest.php`:

Change the imports — remove `ComponentNameFormatter`, `StoryblokHydrator` and `HydrationException`; add:

```php
use Tlab\StoryblokTransfers\Content\StoryQuery;
use Tlab\StoryblokTransfers\Content\StoryRepository;
use Tlab\StoryblokTransfers\Content\StoryTransfer;
use Tlab\StoryblokTransfers\Content\UnexpectedComponentException;
use Tlab\StoryblokTransfers\Content\UnresolvableComponentException;
```

Change the constructor parameter:

```php
    public function __construct(
        private readonly Console $console,
        private readonly Configuration $configuration,
        private readonly StoryRepository $stories,
        private readonly TransferGraphPrinter $printer,
        private readonly string $outputRoot,
        private readonly string $namespace,
    ) {
    }
```

Replace `run()`:

```php
    /**
     * @throws SmokeTestFailure When any step fails.
     */
    public function run(?string $storySlugOrId): void
    {
        $this->console->title('Storyblok transfers smoke test');
        $this->console->info('space ' . $this->configuration->spaceId . '  →  ' . $this->outputRoot);

        $generation = $this->generate();
        $this->registerGeneratedClassAutoloader();

        $slug = $storySlugOrId ?? $this->firstSlug();
        $story = $this->fetchStory($slug, $generation);
        $summary = $this->inspect($story);

        $this->report($generation, $summary);
    }
```

Change the four step headings from `[1/3]`, `[2/3]`, `[3/3]` to `[1/4]` in `generate()`, `[2/4]` in `firstSlug()`, `[3/4]` in `fetchStory()` and `[4/4]` in `inspect()`.

Add `firstSlug()`, which also exercises the listing and its pagination headers:

```php
    /**
     * Also the only place the listing and its header-borne totals get exercised
     * against the real API.
     *
     * @throws SmokeTestFailure
     */
    private function firstSlug(): string
    {
        $this->console->heading('[2/4] Listing stories');

        try {
            $list = $this->stories->findBy(new StoryQuery(perPage: 1));
        } catch (StoryblokApiException $e) {
            throw new SmokeTestFailure('Could not list stories: ' . $e->getMessage());
        } catch (UnresolvableComponentException $e) {
            throw new SmokeTestFailure(
                'The first story in the space has no generated class: ' . $e->getMessage()
            );
        }

        $this->console->ok(sprintf(
            '%d story/stories in the space, %d per page',
            $list->getTotal(),
            $list->getPerPage()
        ));

        $first = $list->getStories()[0] ?? null;

        if ($first === null) {
            throw new SmokeTestFailure('The space contains no stories to hydrate.');
        }

        $this->console->info('first story: ' . $first->getFullSlug());

        return $first->getFullSlug();
    }
```

Replace `fetchStory()` entirely:

```php
    /**
     * @throws SmokeTestFailure
     */
    private function fetchStory(string $slug, GenerationResult $generation): StoryTransfer
    {
        $this->console->heading('[3/4] Fetching and hydrating one story');

        $relations = $this->relationsToResolve();

        if ($relations !== []) {
            $this->console->info('resolving relations: ' . implode(', ', $relations));
        }

        $options = (new ContentOptions(Version::Draft))->withResolveRelations($relations);

        try {
            $story = $this->stories->bySlug($slug, null, $options);
        } catch (UnresolvableComponentException $e) {
            throw new SmokeTestFailure(
                'No generated class for the story\'s root component: ' . $e->getMessage(),
                'Generated: ' . implode(', ', $generation->componentNames),
                'A component whose every field is a tab or section generates nothing '
                . '- that may be the cause.'
            );
        } catch (UnexpectedComponentException | StoryblokApiException $e) {
            throw new SmokeTestFailure('Could not read the story: ' . $e->getMessage());
        } catch (Throwable $e) {
            throw new SmokeTestFailure('Reading the story threw ' . $e::class . ': ' . $e->getMessage());
        }

        if ($story === null) {
            throw new SmokeTestFailure(sprintf('No story at slug "%s".', $slug));
        }

        $this->console->ok(sprintf(
            'story "%s" (uuid %s) hydrated into %s',
            $story->getFullSlug(),
            $story->getUuid(),
            $story->getContent()::class
        ));

        if ($relations !== []) {
            $this->console->info(
                $story->getRelations()->isEmpty()
                    ? 'no relations came back resolved'
                    : 'relations resolved and reachable through getRelation()'
            );
        }

        return $story;
    }

    /**
     * @return list<string>
     */
    private function relationsToResolve(): array
    {
        $configured = getenv('STORYBLOK_SMOKE_RELATIONS');

        if (!is_string($configured) || $configured === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $configured))));
    }
```

Replace `hydrate()` with `inspect()`, which no longer hydrates — the repository did that:

```php
    /**
     * @throws SmokeTestFailure
     */
    private function inspect(StoryTransfer $story): GraphSummary
    {
        $this->console->heading('[4/4] Walking the graph');

        $transfer = $story->getContent();

        $this->console->line();
        $summary = $this->printer->print($transfer);
        $this->console->line();

        // The reflection-driven round trip is where a defaultless typed property
        // on a generated class would surface, so exercise it explicitly.
        try {
            $roundTripped = $transfer->toArray(true);
        } catch (Throwable $e) {
            throw new SmokeTestFailure('toArray(true) threw ' . $e::class . ': ' . $e->getMessage());
        }

        $this->console->ok(sprintf('toArray(true) returned %d keys', count($roundTripped)));

        return $summary;
    }
```

Add the two imports `inspect()` and `fetchStory()` need that are not yet there:

```php
use Tlab\StoryblokTransfers\Content\ContentOptions;
use Tlab\StoryblokTransfers\Content\Version;
```

Update `USAGE` so it names the new variables:

```php
    public const USAGE = <<<'USAGE'
        Usage: php tools/smoke-test.php [story-slug]

        Generates transfer classes from the Storyblok space in your .env, lists the
        space, then reads one story through the library's own StoryRepository and
        prints the resulting graph.

        Without an argument the first story the listing returns is used.

        Reads STORYBLOK_SPACE_ID, STORYBLOK_MANAGEMENT_TOKEN and STORYBLOK_AUTH_SCHEME
        for generation, and STORYBLOK_DELIVERY_TOKEN for reading content. Set
        STORYBLOK_SMOKE_RELATIONS to a comma-separated list of component.field pairs
        to exercise resolve_relations.

        Generated output goes to tools/.output and is git-ignored. The space is only
        ever read from.

        USAGE;
```

- [ ] **Step 3: Rewire the entrypoint**

In `tools/smoke-test.php`, drop the two `require_once` lines for `Story.php` and `StoryFetcher.php`, drop the now-unused `use GuzzleHttp\Client;`, and add:

```php
use Tlab\StoryblokTransfers\Content\ContentOptions;
use Tlab\StoryblokTransfers\Content\StoryblokContent;
use Tlab\StoryblokTransfers\Content\Version;
```

Replace the `fetcher:` argument with the repository:

```php
    $smokeTest = new SmokeTest(
        console: $console,
        configuration: $configuration,
        stories: StoryblokContent::create(
            deliveryToken: $configuration->deliveryToken,
            namespace: 'SmokeTest\\DataTransferObjects',
            defaults: new ContentOptions(Version::Draft),
        )->stories(),
        printer: new TransferGraphPrinter($console, new PropertyTypeResolver()),
        // Never src/: the generator clears every *Transfer.php in its output
        // directory on each run.
        outputRoot: __DIR__ . '/.output',
        // Deliberately not the library's default namespace - these classes are
        // throwaway diagnostics, and the name should say so in every line of
        // output.
        namespace: 'SmokeTest\\DataTransferObjects',
    );
```

Draft is the default here on purpose: a smoke test that only sees published content cannot check a space whose stories are all drafts.

- [ ] **Step 4: Delete the shadow implementation**

```bash
git rm tools/StoryFetcher.php tools/Story.php
```

- [ ] **Step 5: Run the smoke test against the real space**

Run: `docker compose run --rm php php tools/smoke-test.php`
Expected: four steps pass and exit 0. This needs `STORYBLOK_DELIVERY_TOKEN` in `.env` from Task 1.

If the space has a relation field, run it again with the relation named, to exercise the uuid-keyed lookup against the real API:

Run: `docker compose run --rm -e STORYBLOK_SMOKE_RELATIONS=test.author php php tools/smoke-test.php`
Expected: `relations resolved and reachable through getRelation()`.

- [ ] **Step 6: Confirm nothing else referenced the deleted files**

Run: `grep -rn "StoryFetcher\|new Story(\|Story::fromPayload" tools/ src/ tests/`
Expected: no matches.

- [ ] **Step 7: Run the linters**

Run: `make stan && make cs`
Expected: no errors. `phpcs` covers `src`, `tests` and `bin/generate` but not `tools/`, so the tools changes are checked by PHPStan only.

- [ ] **Step 8: Commit**

```bash
git add tools/ && git commit -m "Read the smoke test's story through the library instead of past it"
```

---

### Task 15: Documentation

**Files:**
- Modify: `README.md`, `.env.example`, `docker-compose.yml`

**Interfaces:**
- Consumes: the whole public surface built in Tasks 3–12
- Produces: nothing in code

**Why `docker-compose.yml` is in this list.** Its `environment:` block currently forwards only `XDEBUG_MODE`, `STORYBLOK_SPACE_ID` and `STORYBLOK_MANAGEMENT_TOKEN`. `StoryblokContent::fromEnvironment()` reads `getenv()` and nothing else — the package ships no dotenv reader, by design — so inside the container it would see none of the three new variables even with them set in `.env`. Documenting them without wiring them through would leave the documented entry point unusable in the project's own development environment.

- [ ] **Step 1: Add the three variables to the configuration table**

In `README.md`, the Configuration table currently runs from `STORYBLOK_SPACE_ID` to `DOCKER_GID`. Add three rows after `STORYBLOK_AUTH_SCHEME`:

```markdown
| `STORYBLOK_DELIVERY_TOKEN` | for reading | — | Content Delivery API token, preview or public (Settings → Access Tokens) |
| `STORYBLOK_CONTENT_BASE_URI` | no | `https://api.storyblok.com/v2/` | Region endpoint: US, AP, CA, CN spaces each have their own |
| `STORYBLOK_DEFAULT_VERSION` | no | `published` | `draft` in preview environments |
```

Immediately after the table, extend the existing warning that the Management token is not the delivery token, noting that generation uses the first and reading content uses the second, so a project doing both needs both.

- [ ] **Step 2: Add a "Reading content" section**

Insert a new `## Reading content` section in `README.md` after the existing `## Hydrating content` section. It must cover:

````markdown
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
whose query parameters are always serialised sorted by key, so a decorator can
key on the request and get a stable key:

```php
final class CachingContentClient implements ContentClient
{
    public function __construct(
        private readonly ContentClient $inner,
        private readonly YourCache $cache,
    ) {
    }

    public function get(string $path, array $query): ContentResponse
    {
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

`cv` is exposed on `ContentOptions` but never managed for you — deciding when it
changes is the invalidation policy, and that belongs to your application.
````

- [ ] **Step 3: Add the two new limitations**

In `README.md`'s `## Limitations` section, after the existing entries, add two subsections in the same voice as the others — each stating the constraint, why it exists, and what to do about it:

```markdown
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
```

- [ ] **Step 4: Add the variables to .env.example**

In `.env.example`, after the `STORYBLOK_AUTH_SCHEME` block, add a commented section in the same style as the existing entries:

```
# Content Delivery API token - Storyblok > Settings > Access Tokens. A preview
# token reads drafts, a public token reads published content only. This is NOT
# the Management API token above: generation uses that one, reading content uses
# this one, and a project doing both needs both.
STORYBLOK_DELIVERY_TOKEN=

# Region endpoint for the Content Delivery API. Leave empty for the EU default;
# US, AP, CA and CN spaces each have their own host.
STORYBLOK_CONTENT_BASE_URI=

# Which version the content repositories read by default: published or draft.
# Set draft in preview environments.
STORYBLOK_DEFAULT_VERSION=
```

- [ ] **Step 5: Forward the new variables through Compose**

In `docker-compose.yml`, add three entries to the `php` service's `environment:` block, in the same `${VAR:-}` form the existing ones use:

```yaml
      STORYBLOK_DELIVERY_TOKEN: ${STORYBLOK_DELIVERY_TOKEN:-}
      STORYBLOK_CONTENT_BASE_URI: ${STORYBLOK_CONTENT_BASE_URI:-}
      STORYBLOK_DEFAULT_VERSION: ${STORYBLOK_DEFAULT_VERSION:-}
```

Verify it took, without printing any secret:

Run: `docker compose run --rm php sh -c 'printenv | grep -c "^STORYBLOK_DELIVERY_TOKEN="'`
Expected: `1`

- [ ] **Step 6: Check the documented code actually runs**

Every snippet above names real methods. Verify each one exists with the signature shown:

Run: `grep -nE "public (static )?function (bySlug|byUuid|findBy|all|entries|withResolveRelations|withLanguage|create|usingClient|fromEnvironment|getRelation)\b" src/Content/*.php`

The `(static )?` is load-bearing: `create()`, `usingClient()` and `fromEnvironment()` are all `public static function`, so a pattern anchored on `public function` silently matches none of the three named constructors — and a verification step that quietly checks nothing is worse than no step at all.

Expected: every method in the README appears. Fix the README, not the code, for any mismatch.

- [ ] **Step 7: Run the full suite one last time**

Run: `make test && make stan && make cs`
Expected: all green.

- [ ] **Step 8: Commit**

```bash
git add README.md .env.example docker-compose.yml
git commit -m "Document the content layer, its two new limitations and the cache seam"
```

---

## Done

The library reads content. Seven stages, fifteen tasks, and the generator is exactly as it was.

What is deliberately still missing, per the spec's "Out of scope": any cache implementation, enums generated from datasources, multi-level relation resolution, the Storyblok Bridge, framework bundles, writing to Storyblok, pruning stale definitions, and the CI schema-drift check.
