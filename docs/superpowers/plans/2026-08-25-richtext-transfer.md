# RichtextTransfer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give a Storyblok `richtext` field its own transfer type, with the components embedded in the tree hydrated into their generated classes and reachable by key.

**Architecture:** `FieldTypeMapper` maps `richtext` to a bundled `RichtextTransfer` the same way it already maps `asset` to `AssetTransfer`, so the generated property becomes `?RichtextTransfer`. That transfer holds the document's own keys — `type`, `attrs`, `content` — plus a `bloks` map. `StoryblokHydrator` gains one branch that builds it and fills `bloks` from a new `RichtextBlokCollector`, which walks the node tree for `type: "blok"` nodes and hydrates each entry of their `attrs.body`. The node tree itself is never modified: it stays plain arrays so any renderer can walk it, and the hydrated components sit beside it keyed by the blok node's `attrs.id`.

**Tech Stack:** PHP 8.1+, `tuxonice/transfer-objects` ^1.2, PHPUnit 10.5, PHPStan level 8, PHP_CodeSniffer (PSR-12, 120-char lines). New dev dependency in Task 6: `storyblok/richtext-resolver`.

**Spec:** `docs/superpowers/specs/2026-08-25-richtext-transfer-design.md`

## Global Constraints

- **Every PHP command runs in Docker.** Never invoke local `php`, `phpunit` or `composer`. Use `make test`, `make cs`, `make stan`, or `docker compose run --rm php <cmd>`.
- **Library PHP floor is `^8.1`.** Any dependency added must resolve to a version installable on PHP 8.1, even though the container runs 8.3. Pin explicitly if Composer's pick requires more.
- `phpunit.xml` sets `failOnWarning="true"`, `failOnNotice="true"`, `failOnDeprecation="true"`. A PHP warning inside a test fails it — this is deliberate and is what guards the array-default decision in Task 1.
- **Array-typed transfer properties are never nullable.** Upstream `AbstractTransfer::toArray(true)` runs `foreach()` over every property whose declared type is `array`, so a null one emits `foreach() argument must be of type array|object, null given`.
- PHPStan runs over `src` and `tests` at level 8 with `treatPhpDocTypesAsCertain: false`. Generated classes do not exist at analysis time, which is why integration tests assert through `toArray()` — but `RichtextTransfer` is a real class, so its accessors may be called directly.
- phpcs covers `src`, `tests` and `bin/generate`. `tools/` is not linted.
- Commit after every task. Branch is `richtext-transfer`.

---

## File Structure

**Created:**
- `src/Transfers/RichtextTransfer.php` — the richtext field's transfer: `type`/`attrs`/`content`/`bloks` plus `toDocument()`.
- `src/Hydration/RichtextBlokCollector.php` — walks a node list, returns embedded components keyed by blok node id. Knows nothing about hydration; takes it as a callable.
- `tests/Unit/RichtextTransferTest.php`
- `tests/Unit/RichtextBlokCollectorTest.php`
- `tests/Fixture/RichtextFixtureTransfer.php` — a hand-written stand-in for what the generator now emits.
- `tests/Integration/RichtextResolverInteropTest.php`

**Modified:**
- `src/Schema/FieldTypeMapper.php` — one `match` arm plus one import.
- `src/Hydration/StoryblokHydrator.php` — one branch in `convert()`, one private method, one new collaborator.
- `tests/Unit/FieldTypeMapperTest.php:46` — the richtext expectation changes.
- `tests/Unit/StoryblokHydratorTest.php:54` — test renamed (it never covered richtext-as-generated; it covers a plain `array<mixed>` property, which is now the `table`/custom case) plus new richtext tests.
- `tests/Fixture/ScalarFixtureTransfer.php:9-12` — docblock no longer says "the richtext case".
- `tests/Integration/StoryblokHydratorIntegrationTest.php:183` — rewritten, plus four new tests.
- `tests/Integration/StoryblokTransferGeneratorTest.php:180-212` — its round trip uses a `richtext` field, which `fromArray()` can no longer assign; swapped for `table`.
- `composer.json` / `composer.lock` — Task 6.
- `README.md:176`, `README.md:230` — Task 7.

---

### Task 1: RichtextTransfer

**Files:**
- Create: `src/Transfers/RichtextTransfer.php`
- Test: `tests/Unit/RichtextTransferTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `Tlab\StoryblokTransfers\Transfers\RichtextTransfer` extending `AbstractTransfer`, with `getType(): ?string`, `setType(?string): self`, `getAttrs(): array`, `setAttrs(array): self`, `getContent(): array`, `setContent(array): self`, `getBloks(): array`, `setBloks(array): self`, `toDocument(): array`. `$attrs`, `$content` and `$bloks` default to `[]`; `$type` defaults to `null`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/RichtextTransferTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tlab\StoryblokTransfers\Transfers\RichtextTransfer;

final class RichtextTransferTest extends TestCase
{
    public function testDefaultsToAnEmptyDocument(): void
    {
        $richtext = new RichtextTransfer();

        self::assertNull($richtext->getType());
        self::assertSame([], $richtext->getAttrs());
        self::assertSame([], $richtext->getContent());
        self::assertSame([], $richtext->getBloks());
    }

    public function testHydratesFromADocumentPayload(): void
    {
        $richtext = RichtextTransfer::fromArray([
            'type' => 'doc',
            'attrs' => ['backgroundColor' => null],
            'content' => [['type' => 'paragraph']],
        ]);

        self::assertSame('doc', $richtext->getType());
        self::assertSame(['backgroundColor' => null], $richtext->getAttrs());
        self::assertSame([['type' => 'paragraph']], $richtext->getContent());
    }

    public function testToDocumentReproducesADocumentWithAttrs(): void
    {
        $document = [
            'type' => 'doc',
            'attrs' => ['backgroundColor' => null],
            'content' => [['type' => 'paragraph']],
        ];

        self::assertSame($document, RichtextTransfer::fromArray($document)->toDocument());
    }

    public function testToDocumentOmitsAttrsWhenTheDocumentHadNone(): void
    {
        $document = ['type' => 'doc', 'content' => [['type' => 'paragraph']]];

        self::assertSame($document, RichtextTransfer::fromArray($document)->toDocument());
    }

    public function testToDocumentOmitsTypeWhenTheDocumentHadNone(): void
    {
        $document = ['content' => [['type' => 'paragraph']]];

        self::assertSame($document, RichtextTransfer::fromArray($document)->toDocument());
    }

    /**
     * The array properties are non-nullable so that upstream's foreach over
     * every array-typed property cannot meet a null. phpunit.xml sets
     * failOnWarning, so this test fails if a nullable one is reintroduced.
     */
    public function testRecursiveToArrayEmitsNoWarningOnAnEmptyDocument(): void
    {
        $data = (new RichtextTransfer())->toArray(true);

        self::assertSame([], $data['content']);
        self::assertSame([], $data['bloks']);
    }

    public function testRecursiveToArrayKeepsTheNodeListIntact(): void
    {
        $nodes = [['type' => 'paragraph'], ['type' => 'horizontal_rule']];

        $data = (new RichtextTransfer())->setContent($nodes)->toArray(true);

        self::assertSame($nodes, $data['content']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
docker compose run --rm php vendor/bin/phpunit tests/Unit/RichtextTransferTest.php
```

Expected: FAIL — `Error: Class "Tlab\StoryblokTransfers\Transfers\RichtextTransfer" not found`.

- [ ] **Step 3: Write the implementation**

Create `src/Transfers/RichtextTransfer.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Transfers;

use Tlab\TransferObjects\AbstractTransfer;

/**
 * Storyblok richtext field.
 *
 * Holds the document's own keys - type, attrs, content - plus the components
 * embedded in the tree, hydrated and keyed by the id of the blok node that
 * carries them.
 *
 * The node list stays plain arrays exactly as Storyblok sent it, so it can be
 * handed to a renderer; the hydrated components sit beside it rather than inside
 * it, reachable by the key a renderer holds when it meets a blok node. Writing
 * them into the tree would leave objects where every PHP renderer expects
 * ['component' => ...].
 *
 * The array properties are non-nullable with [] defaults on purpose:
 * AbstractTransfer::toArray(true) runs foreach() over every property whose
 * declared type is array, so a null one emits a warning. See AssetTransfer for
 * why the scalar is nullable instead.
 */
class RichtextTransfer extends AbstractTransfer
{
    private ?string $type = null;

    /**
     * @var array<string, mixed>
     */
    private array $attrs = [];

    /**
     * @var array<mixed>
     */
    private array $content = [];

    /**
     * Keyed by the blok node's attrs.id. A list may hold transfers, raw arrays
     * for components with no generated class, or both.
     *
     * @var array<string, list<mixed>>
     */
    private array $bloks = [];

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAttrs(): array
    {
        return $this->attrs;
    }

    /**
     * @param array<string, mixed> $attrs
     */
    public function setAttrs(array $attrs): self
    {
        $this->attrs = $attrs;

        return $this;
    }

    /**
     * @return array<mixed>
     */
    public function getContent(): array
    {
        return $this->content;
    }

    /**
     * @param array<mixed> $content
     */
    public function setContent(array $content): self
    {
        $this->content = $content;

        return $this;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function getBloks(): array
    {
        return $this->bloks;
    }

    /**
     * @param array<string, list<mixed>> $bloks
     */
    public function setBloks(array $bloks): self
    {
        $this->bloks = $bloks;

        return $this;
    }

    /**
     * The document as Storyblok sent it, for handing to a renderer.
     *
     * Renderers take the document node rather than the bare node list -
     * storyblok/richtext-resolver reads $data['content'] - so getContent() is
     * not what they want. Absent keys stay absent, so the common payload shapes
     * come back out exactly as they went in.
     *
     * @return array<string, mixed>
     */
    public function toDocument(): array
    {
        $document = [];

        if ($this->type !== null) {
            $document['type'] = $this->type;
        }

        if ($this->attrs !== []) {
            $document['attrs'] = $this->attrs;
        }

        $document['content'] = $this->content;

        return $document;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
docker compose run --rm php vendor/bin/phpunit tests/Unit/RichtextTransferTest.php
```

Expected: PASS, 7 tests.

- [ ] **Step 5: Lint and analyse**

```bash
make cs
make stan
```

Expected: no errors from either.

- [ ] **Step 6: Commit**

```bash
git add src/Transfers/RichtextTransfer.php tests/Unit/RichtextTransferTest.php
git commit -m "Add RichtextTransfer for the richtext field"
```

---

### Task 2: RichtextBlokCollector

**Files:**
- Create: `src/Hydration/RichtextBlokCollector.php`
- Test: `tests/Unit/RichtextBlokCollectorTest.php`

**Interfaces:**
- Consumes: nothing from Task 1 — the collector deals in arrays and never touches `RichtextTransfer`.
- Produces: `Tlab\StoryblokTransfers\Hydration\RichtextBlokCollector` with `collect(array $content, callable $hydrate): array`. `$content` is a node list (a document's `content`), `$hydrate` receives one component array and returns whatever it hydrates to, and the return is `array<string, list<mixed>>` keyed by blok node id.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/RichtextBlokCollectorTest.php`. The `$hydrate` double returns a marker array so the tests assert on routing rather than on hydration:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tlab\StoryblokTransfers\Hydration\RichtextBlokCollector;

final class RichtextBlokCollectorTest extends TestCase
{
    private RichtextBlokCollector $collector;

    /**
     * @var callable(array<string, mixed>): mixed
     */
    private $hydrate;

    protected function setUp(): void
    {
        $this->collector = new RichtextBlokCollector();
        // Stands in for hydration: names the component it was handed, so the
        // assertions are about which components reached it, and in what order.
        $this->hydrate = static fn (array $component): string => 'hydrated:'
            . (is_string($component['component'] ?? null) ? $component['component'] : '?');
    }

    public function testCollectsABlokAtTheTopLevel(): void
    {
        $bloks = $this->collector->collect([
            ['type' => 'paragraph'],
            ['type' => 'blok', 'attrs' => ['id' => 'blok-1', 'body' => [
                ['component' => 'button'],
            ]]],
        ], $this->hydrate);

        self::assertSame(['blok-1' => ['hydrated:button']], $bloks);
    }

    public function testCollectsEveryComponentOfOneBlokNodeInPayloadOrder(): void
    {
        $bloks = $this->collector->collect([
            ['type' => 'blok', 'attrs' => ['id' => 'blok-1', 'body' => [
                ['component' => 'first'],
                ['component' => 'second'],
            ]]],
        ], $this->hydrate);

        self::assertSame(['blok-1' => ['hydrated:first', 'hydrated:second']], $bloks);
    }

    public function testFindsABlokNestedDeepInTheTree(): void
    {
        $bloks = $this->collector->collect([
            ['type' => 'bullet_list', 'content' => [
                ['type' => 'list_item', 'content' => [
                    ['type' => 'blok', 'attrs' => ['id' => 'deep', 'body' => [
                        ['component' => 'button'],
                    ]]],
                ]],
            ]],
        ], $this->hydrate);

        self::assertSame(['deep' => ['hydrated:button']], $bloks);
    }

    public function testFindsABlokInsideATableCell(): void
    {
        $bloks = $this->collector->collect([
            ['type' => 'table', 'content' => [
                ['type' => 'tableRow', 'content' => [
                    ['type' => 'tableCell', 'content' => [
                        ['type' => 'blok', 'attrs' => ['id' => 'in-cell', 'body' => [
                            ['component' => 'button'],
                        ]]],
                    ]],
                ]],
            ]],
        ], $this->hydrate);

        self::assertSame(['in-cell' => ['hydrated:button']], $bloks);
    }

    public function testIgnoresAnEmptyBody(): void
    {
        $bloks = $this->collector->collect([
            ['type' => 'blok', 'attrs' => ['id' => 'blok-1', 'body' => []]],
        ], $this->hydrate);

        self::assertSame([], $bloks);
    }

    public function testIgnoresANullBody(): void
    {
        $bloks = $this->collector->collect([
            ['type' => 'blok', 'attrs' => ['id' => 'blok-1', 'body' => null]],
        ], $this->hydrate);

        self::assertSame([], $bloks);
    }

    public function testMergesDuplicateIdsInDocumentOrder(): void
    {
        $bloks = $this->collector->collect([
            ['type' => 'blok', 'attrs' => ['id' => 'same', 'body' => [['component' => 'first']]]],
            ['type' => 'paragraph', 'content' => [
                ['type' => 'blok', 'attrs' => ['id' => 'same', 'body' => [['component' => 'second']]]],
            ]],
        ], $this->hydrate);

        self::assertSame(['same' => ['hydrated:first', 'hydrated:second']], $bloks);
    }

    public function testSkipsABlokNodeWithNoId(): void
    {
        $bloks = $this->collector->collect([
            ['type' => 'blok', 'attrs' => ['body' => [['component' => 'button']]]],
            ['type' => 'blok', 'attrs' => ['id' => '', 'body' => [['component' => 'button']]]],
            ['type' => 'blok', 'attrs' => ['id' => 42, 'body' => [['component' => 'button']]]],
        ], $this->hydrate);

        self::assertSame([], $bloks);
    }

    public function testSkipsMalformedNodes(): void
    {
        $bloks = $this->collector->collect([
            'not a node',
            ['no type key' => true],
            ['type' => 'blok'],
            ['type' => 'blok', 'attrs' => 'not an array'],
            ['type' => 'blok', 'attrs' => ['id' => 'blok-1', 'body' => ['not a component']]],
        ], $this->hydrate);

        self::assertSame([], $bloks);
    }

    public function testReturnsNothingForADocumentWithNoBloks(): void
    {
        $bloks = $this->collector->collect([
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello']]],
        ], $this->hydrate);

        self::assertSame([], $bloks);
    }

    /**
     * A component embedded in a blok may itself hold a richtext field with more
     * bloks. Those belong to that component's own RichtextTransfer, hydrated
     * when the component is, so the collector must not reach into attrs.body.
     */
    public function testDoesNotDescendIntoTheComponentsItCollects(): void
    {
        $bloks = $this->collector->collect([
            ['type' => 'blok', 'attrs' => ['id' => 'outer', 'body' => [
                [
                    'component' => 'wrapper',
                    'body' => ['type' => 'doc', 'content' => [
                        ['type' => 'blok', 'attrs' => ['id' => 'inner', 'body' => [
                            ['component' => 'button'],
                        ]]],
                    ]],
                ],
            ]]],
        ], $this->hydrate);

        self::assertSame(['outer' => ['hydrated:wrapper']], $bloks);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
docker compose run --rm php vendor/bin/phpunit tests/Unit/RichtextBlokCollectorTest.php
```

Expected: FAIL — `Error: Class "Tlab\StoryblokTransfers\Hydration\RichtextBlokCollector" not found`.

- [ ] **Step 3: Write the implementation**

Create `src/Hydration/RichtextBlokCollector.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Hydration;

/**
 * Finds the components embedded in a richtext tree.
 *
 * Storyblok wraps an inserted component in a node of its own:
 *
 *     {"type": "blok", "attrs": {"id": "...", "body": [{"component": "hero", ...}]}}
 *
 * `attrs.body` holds ordinary component objects, so each hydrates exactly like
 * an entry of a bloks field. Storyblok's own renderers decline to render these -
 * the official package maps the blok node to null and asks the consumer for a
 * resolver - which is why finding them is left to a library like this one.
 *
 * They are returned keyed by the node's `attrs.id`, the key a renderer holds
 * when it meets the node, rather than written back into the tree: the tree has
 * to stay plain arrays for a renderer to be able to walk it at all.
 */
final class RichtextBlokCollector
{
    /**
     * @param array<mixed> $content A document's node list.
     * @param callable(array<string, mixed>): mixed $hydrate Receives one
     *        component array; returns its transfer, or the array unchanged when
     *        its component has no generated class.
     *
     * @return array<string, list<mixed>>
     */
    public function collect(array $content, callable $hydrate): array
    {
        $bloks = [];

        foreach ($content as $node) {
            if (!is_array($node)) {
                continue;
            }

            if (($node['type'] ?? null) === 'blok') {
                $this->collectNode($node, $hydrate, $bloks);

                continue;
            }

            $children = $node['content'] ?? null;

            if (!is_array($children)) {
                continue;
            }

            foreach ($this->collect($children, $hydrate) as $id => $components) {
                $bloks[$id] = array_merge($bloks[$id] ?? [], $components);
            }
        }

        return $bloks;
    }

    /**
     * @param array<mixed> $node
     * @param callable(array<string, mixed>): mixed $hydrate
     * @param array<string, list<mixed>> $bloks
     */
    private function collectNode(array $node, callable $hydrate, array &$bloks): void
    {
        $attrs = $node['attrs'] ?? null;

        if (!is_array($attrs)) {
            return;
        }

        $id = $attrs['id'] ?? null;
        $body = $attrs['body'] ?? null;

        // Without an id there is no key to look these up by, and a positional
        // key of our own invention would be worse than the honest omission.
        if (!is_string($id) || $id === '' || !is_array($body)) {
            return;
        }

        foreach ($body as $component) {
            if (is_array($component)) {
                $bloks[$id][] = $hydrate($component);
            }
        }
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
docker compose run --rm php vendor/bin/phpunit tests/Unit/RichtextBlokCollectorTest.php
```

Expected: PASS, 11 tests.

- [ ] **Step 5: Lint and analyse**

```bash
make cs
make stan
```

Expected: no errors. If PHPStan rejects `array_merge($bloks[$id] ?? [], $components)` as returning `array<int, mixed>` where `list<mixed>` is declared, replace that line with a spread, which it infers as a list:

```php
$bloks[$id] = [...($bloks[$id] ?? []), ...$components];
```

- [ ] **Step 6: Commit**

```bash
git add src/Hydration/RichtextBlokCollector.php tests/Unit/RichtextBlokCollectorTest.php
git commit -m "Add RichtextBlokCollector to find bloks embedded in richtext"
```

---

### Task 3: Map richtext to RichtextTransfer

**Files:**
- Modify: `src/Schema/FieldTypeMapper.php:70-82`
- Modify: `tests/Unit/FieldTypeMapperTest.php:46-51`
- Modify: `tests/Integration/StoryblokTransferGeneratorTest.php:180-212`

**Interfaces:**
- Consumes: `RichtextTransfer` from Task 1.
- Produces: `FieldTypeMapper::map($key, ['type' => 'richtext'])` returns `['name' => …, 'type' => 'RichtextTransfer', 'nullable' => true, 'namespace' => RichtextTransfer::class]`, so the generator emits `private ?RichtextTransfer $body = null;`.

- [ ] **Step 1: Update the failing test**

In `tests/Unit/FieldTypeMapperTest.php`, replace `testMapsRichtextToNullableArray` (line 46) with:

```php
    public function testMapsRichtextToARichtextTransfer(): void
    {
        self::assertSame(
            [
                'name' => 'body',
                'type' => 'RichtextTransfer',
                'nullable' => true,
                'namespace' => RichtextTransfer::class,
            ],
            $this->mapper->map('body', ['type' => 'richtext'])
        );
    }
```

Add the import beside the existing transfer imports at the top of the file:

```php
use Tlab\StoryblokTransfers\Transfers\RichtextTransfer;
```

`testMapsUnknownTypeToNullableArrayFallback` (line 53) stays exactly as it is: it now carries the whole `default` arm, which is the point.

Add a test directly after it, pinning that `table` did *not* move:

```php
    public function testStillMapsTableToNullableArray(): void
    {
        self::assertSame(
            ['name' => 'specs', 'type' => 'array', 'nullable' => true],
            $this->mapper->map('specs', ['type' => 'table'])
        );
    }
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
docker compose run --rm php vendor/bin/phpunit --filter 'Richtext|Table' tests/Unit/FieldTypeMapperTest.php
```

Expected: `testMapsRichtextToARichtextTransfer` FAILS, comparing `'type' => 'array'` against `'type' => 'RichtextTransfer'`. `testStillMapsTableToNullableArray` passes already.

- [ ] **Step 3: Write the implementation**

In `src/Schema/FieldTypeMapper.php`, add the import:

```php
use Tlab\StoryblokTransfers\Transfers\RichtextTransfer;
```

Then add one arm to the `match`, directly above `'options'`:

```php
            'richtext' => $this->transfer($name, 'RichtextTransfer', RichtextTransfer::class),
```

Update the `default` arm's comment, which currently names richtext:

```php
                // table, custom plugins and anything unknown.
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
docker compose run --rm php vendor/bin/phpunit tests/Unit/FieldTypeMapperTest.php
```

Expected: PASS.

- [ ] **Step 5: Fix the generator round-trip test this breaks**

`StoryblokTransferGeneratorTest::testRoundTripsAStoryPayloadThroughTheGeneratedClass` (line 180) calls `fromArray()` with a richtext array. `setBody()` now demands `?RichtextTransfer`, so that raw array is a `TypeError` — the very limitation `testFromArrayCannotHydrateNestedTransferFields` already documents. Keep the round trip about a field `fromArray()` *can* assign by moving it to `table`, which stays `?array`:

In the schema at line 186, replace `'body' => ['type' => 'richtext'],` with:

```php
                    'specs' => ['type' => 'table'],
```

In the payload at line 200, replace the `body` key with:

```php
            'specs' => ['type' => 'doc', 'content' => []],
```

And the assertion at line 209:

```php
        /** @phpstan-ignore-next-line */
        self::assertSame(['type' => 'doc', 'content' => []], $transfer->getSpecs());
```

`testEveryGeneratedClassIsValidPhp` (line 59) needs no change — it lints the generated file, and `?RichtextTransfer` with its import is valid PHP. It is the check that the generator emits a usable import for the new namespace, so watch it pass rather than skipping it.

- [ ] **Step 6: Run the whole suite**

```bash
make test
```

Expected: PASS. Two hydration tests still assert the old pass-through behaviour and are addressed in Tasks 4 and 5 — if either fails here, note it and continue; they are `StoryblokHydratorTest::testPassesRichtextStructuresThroughUntouched` (unaffected, it uses a fixture) and `StoryblokHydratorIntegrationTest::testPassesRichtextThroughUntouched` (expected to fail now: `$data['body']` becomes a `RichtextTransfer` and `assertSame` against the array fails).

- [ ] **Step 7: Lint, analyse, commit**

```bash
make cs
make stan
git add src/Schema/FieldTypeMapper.php tests/Unit/FieldTypeMapperTest.php tests/Integration/StoryblokTransferGeneratorTest.php
git commit -m "Generate ?RichtextTransfer for richtext fields"
```

---

### Task 4: Hydrate richtext and collect its bloks

**Files:**
- Modify: `src/Hydration/StoryblokHydrator.php:22-38,63-83`
- Create: `tests/Fixture/RichtextFixtureTransfer.php`
- Modify: `tests/Fixture/ScalarFixtureTransfer.php:9-12`
- Modify: `tests/Unit/StoryblokHydratorTest.php:54-62`

**Interfaces:**
- Consumes: `RichtextTransfer` (Task 1), `RichtextBlokCollector::collect()` (Task 2), `FieldTypeMapper`'s new mapping (Task 3).
- Produces: `StoryblokHydrator::hydrate()` returns transfers whose richtext properties hold a `RichtextTransfer` with `getBloks()` populated. No public signature changes.

- [ ] **Step 1: Write the failing test**

Create `tests/Fixture/RichtextFixtureTransfer.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Fixture;

use Tlab\StoryblokTransfers\Transfers\RichtextTransfer;
use Tlab\TransferObjects\AbstractTransfer;

/**
 * Mirrors what the generator emits for a richtext field: a nested transfer, so
 * fromArray() alone cannot assign the payload.
 */
class RichtextFixtureTransfer extends AbstractTransfer
{
    /**
     * @var RichtextTransfer|null
     */
    private ?RichtextTransfer $body = null;

    public function getBody(): ?RichtextTransfer
    {
        return $this->body;
    }

    public function setBody(?RichtextTransfer $body): self
    {
        $this->body = $body;

        return $this;
    }
}
```

In `tests/Unit/StoryblokHydratorTest.php`, rename the misleading test at line 54. It uses `ScalarFixtureTransfer`, whose property is a plain `array<mixed>` — that was the richtext case before this change and is the `table`/custom-plugin case now. The behaviour it covers is still real, so only its name and comment change:

```php
    public function testPassesStructuredArraysThroughUntouched(): void
    {
        // The table and custom-plugin case: a plain array<mixed> property.
        $nodes = ['type' => 'doc', 'content' => [['type' => 'paragraph']]];

        $transfer = $this->hydrator->hydrate(ScalarFixtureTransfer::class, ['content' => $nodes]);

        self::assertInstanceOf(ScalarFixtureTransfer::class, $transfer);
        self::assertSame($nodes, $transfer->getContent());
    }
```

Then add the richtext tests after it:

```php
    public function testHydratesRichtextIntoARichtextTransfer(): void
    {
        $document = [
            'type' => 'doc',
            'attrs' => ['backgroundColor' => null],
            'content' => [['type' => 'paragraph']],
        ];

        $transfer = $this->hydrator->hydrate(RichtextFixtureTransfer::class, ['body' => $document]);

        self::assertInstanceOf(RichtextFixtureTransfer::class, $transfer);
        $body = $transfer->getBody();
        self::assertInstanceOf(RichtextTransfer::class, $body);
        self::assertSame('doc', $body->getType());
        self::assertSame([['type' => 'paragraph']], $body->getContent());
        self::assertSame($document, $body->toDocument());
    }

    public function testCollectsAComponentEmbeddedInRichtext(): void
    {
        // 'nested_fixture' resolves to NestedFixtureTransfer in the fixture
        // namespace, the same lookup a generated class gets.
        $transfer = $this->hydrator->hydrate(RichtextFixtureTransfer::class, [
            'body' => [
                'type' => 'doc',
                'content' => [
                    ['type' => 'paragraph'],
                    ['type' => 'blok', 'attrs' => ['id' => 'blok-1', 'body' => [
                        ['component' => 'nested_fixture', 'headline' => 'Embedded'],
                    ]]],
                ],
            ],
        ]);

        self::assertInstanceOf(RichtextFixtureTransfer::class, $transfer);
        $body = $transfer->getBody();
        self::assertInstanceOf(RichtextTransfer::class, $body);

        $bloks = $body->getBloks();
        self::assertArrayHasKey('blok-1', $bloks);
        $embedded = $bloks['blok-1'][0];
        self::assertInstanceOf(NestedFixtureTransfer::class, $embedded);
        self::assertSame('Embedded', $embedded->getHeadline());
    }

    public function testLeavesTheNodeTreeAsPlainArrays(): void
    {
        $node = ['component' => 'nested_fixture', 'headline' => 'Embedded'];

        $transfer = $this->hydrator->hydrate(RichtextFixtureTransfer::class, [
            'body' => [
                'type' => 'doc',
                'content' => [
                    ['type' => 'blok', 'attrs' => ['id' => 'blok-1', 'body' => [$node]]],
                ],
            ],
        ]);

        self::assertInstanceOf(RichtextFixtureTransfer::class, $transfer);
        $body = $transfer->getBody();
        self::assertInstanceOf(RichtextTransfer::class, $body);

        $content = $body->getContent();
        self::assertIsArray($content[0]);
        self::assertSame(['id' => 'blok-1', 'body' => [$node]], $content[0]['attrs']);
    }

    public function testKeepsAnEmbeddedComponentWithNoClassAsARawArray(): void
    {
        $unknown = ['component' => 'not_generated', 'title' => 'Subscribe'];

        $transfer = $this->hydrator->hydrate(RichtextFixtureTransfer::class, [
            'body' => [
                'type' => 'doc',
                'content' => [
                    ['type' => 'blok', 'attrs' => ['id' => 'blok-1', 'body' => [$unknown]]],
                ],
            ],
        ]);

        self::assertInstanceOf(RichtextFixtureTransfer::class, $transfer);
        $body = $transfer->getBody();
        self::assertInstanceOf(RichtextTransfer::class, $body);
        self::assertSame(['blok-1' => [$unknown]], $body->getBloks());
    }

    public function testTurnsAnEmptyStringRichtextIntoNull(): void
    {
        $transfer = $this->hydrator->hydrate(RichtextFixtureTransfer::class, ['body' => '']);

        self::assertInstanceOf(RichtextFixtureTransfer::class, $transfer);
        self::assertNull($transfer->getBody());
    }
```

Add the imports these need to the top of the test file:

```php
use Tlab\StoryblokTransfers\Tests\Fixture\RichtextFixtureTransfer;
use Tlab\StoryblokTransfers\Transfers\RichtextTransfer;
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
docker compose run --rm php vendor/bin/phpunit --filter 'Richtext|Embedded|PlainArrays' tests/Unit/StoryblokHydratorTest.php
```

Expected: FAIL — `TypeError: setBody(): Argument #1 must be of type ?RichtextTransfer, array given`. The hydrator does not know about `RichtextTransfer` yet, so it passes the payload through.

- [ ] **Step 3: Write the implementation**

In `src/Hydration/StoryblokHydrator.php`, add the import:

```php
use Tlab\StoryblokTransfers\Transfers\RichtextTransfer;
```

Add the collaborator beside the existing ones (after `$componentNameFormatter`, line 26):

```php
    private readonly RichtextBlokCollector $blokCollector;
```

and initialise it in the constructor beside the others:

```php
        $this->blokCollector = new RichtextBlokCollector();
```

Then add the branch at the top of `convert()`, before the existing `$nestedClass !== null` check:

```php
        if ($nestedClass === RichtextTransfer::class) {
            return is_array($value) ? $this->hydrateRichtext($value) : null;
        }
```

And add the method after `convert()`:

```php
    /**
     * Richtext needs no pre-conversion of its own - RichtextTransfer has no
     * transfer-typed property - so fromArray() is called directly, which also
     * returns the precise type. Move this back to hydrate() if that changes.
     *
     * The bloks are collected from the tree rather than written into it: see
     * RichtextTransfer for why the tree has to stay plain arrays.
     *
     * @param array<string, mixed> $document
     */
    private function hydrateRichtext(array $document): RichtextTransfer
    {
        $richtext = RichtextTransfer::fromArray($document);

        return $richtext->setBloks($this->blokCollector->collect(
            $richtext->getContent(),
            fn (array $component): mixed => $this->convertElement(BlokTransfer::class, $component),
        ));
    }
```

Finally, update the class docblock, which currently describes only the asset case, adding a sentence:

```php
 * A richtext field takes the same route: its document becomes a
 * RichtextTransfer, and the components embedded in its tree are hydrated and
 * hung off it by key.
```

Also update `tests/Fixture/ScalarFixtureTransfer.php`'s docblock, which still calls itself the richtext case:

```php
/**
 * Mirrors an `array<mixed>` property - the table and custom-plugin case. A
 * plain array has no element type, so the generator emits no add method for it.
 */
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
docker compose run --rm php vendor/bin/phpunit tests/Unit/StoryblokHydratorTest.php
```

Expected: PASS, including the renamed pass-through test.

- [ ] **Step 5: Lint, analyse, commit**

```bash
make cs
make stan
git add src/Hydration/StoryblokHydrator.php tests/Fixture tests/Unit/StoryblokHydratorTest.php
git commit -m "Hydrate richtext into RichtextTransfer and collect embedded bloks"
```

---

### Task 5: Integration coverage against generated classes

**Files:**
- Modify: `tests/Integration/StoryblokHydratorIntegrationTest.php:183-193`

**Interfaces:**
- Consumes: everything from Tasks 1–4.
- Produces: nothing new — this task only proves the chain works against real generator output.

- [ ] **Step 1: Rewrite the pass-through test and add the new ones**

Replace `testPassesRichtextThroughUntouched` (line 183) with the following. Note `hydrateToArray()` returns `toArray()` non-recursively, so `$data['body']` is the `RichtextTransfer` object itself — and unlike generated accessors, its methods are visible to PHPStan:

```php
    public function testHydratesARichtextFieldIntoARichtextTransfer(): void
    {
        $this->generate([
            ['name' => 'hero', 'schema' => ['body' => ['type' => 'richtext']]],
        ]);

        $document = ['type' => 'doc', 'content' => [['type' => 'paragraph']]];

        $body = $this->hydrateToArray('HeroTransfer', ['body' => $document])['body'];

        self::assertInstanceOf(RichtextTransfer::class, $body);
        self::assertSame($document['content'], $body->getContent());
        self::assertSame($document, $body->toDocument());
    }

    public function testHydratesAComponentEmbeddedInARichtextField(): void
    {
        $this->generate([
            ['name' => 'page', 'schema' => ['body' => ['type' => 'richtext']]],
            ['name' => 'teaser', 'schema' => ['headline' => ['type' => 'text']]],
        ]);

        $body = $this->hydrateToArray('PageTransfer', [
            'body' => [
                'type' => 'doc',
                'content' => [
                    ['type' => 'blok', 'attrs' => ['id' => 'blok-1', 'body' => [
                        ['component' => 'teaser', 'headline' => 'Embedded'],
                    ]]],
                ],
            ],
        ])['body'];

        self::assertInstanceOf(RichtextTransfer::class, $body);

        $embedded = $body->getBloks()['blok-1'][0];
        self::assertInstanceOf(AbstractTransfer::class, $embedded);
        self::assertSame(
            ['headline' => 'Embedded'],
            array_intersect_key($embedded->toArray(), ['headline' => null])
        );
    }

    public function testKeepsAnEmbeddedComponentWithNoGeneratedClassAsARawArray(): void
    {
        $this->generate([
            ['name' => 'page', 'schema' => ['body' => ['type' => 'richtext']]],
        ]);

        $raw = ['component' => 'newsletter_signup', 'title' => 'Subscribe'];

        $body = $this->hydrateToArray('PageTransfer', [
            'body' => [
                'type' => 'doc',
                'content' => [
                    ['type' => 'blok', 'attrs' => ['id' => 'blok-1', 'body' => [$raw]]],
                ],
            ],
        ])['body'];

        self::assertInstanceOf(RichtextTransfer::class, $body);
        self::assertSame(['blok-1' => [$raw]], $body->getBloks());
    }

    public function testARichtextFieldInsideAnEmbeddedComponentBecomesItsOwnTransfer(): void
    {
        $this->generate([
            ['name' => 'page', 'schema' => ['body' => ['type' => 'richtext']]],
            ['name' => 'quote', 'schema' => ['body' => ['type' => 'richtext']]],
        ]);

        $inner = [
            'type' => 'doc',
            'content' => [
                ['type' => 'blok', 'attrs' => ['id' => 'inner-1', 'body' => [
                    ['component' => 'newsletter_signup'],
                ]]],
            ],
        ];

        $outer = $this->hydrateToArray('PageTransfer', [
            'body' => [
                'type' => 'doc',
                'content' => [
                    ['type' => 'blok', 'attrs' => ['id' => 'outer-1', 'body' => [
                        ['component' => 'quote', 'body' => $inner],
                    ]]],
                ],
            ],
        ])['body'];

        self::assertInstanceOf(RichtextTransfer::class, $outer);

        // The outer document owns only the blok written into it.
        self::assertSame(['outer-1'], array_keys($outer->getBloks()));

        $quote = $outer->getBloks()['outer-1'][0];
        self::assertInstanceOf(AbstractTransfer::class, $quote);

        $quoteBody = $quote->toArray()['body'];
        self::assertInstanceOf(RichtextTransfer::class, $quoteBody);
        self::assertSame(['inner-1'], array_keys($quoteBody->getBloks()));
    }
```

Add the import beside the other transfer imports at the top of the file:

```php
use Tlab\StoryblokTransfers\Transfers\RichtextTransfer;
```

- [ ] **Step 2: Run the tests**

```bash
docker compose run --rm php vendor/bin/phpunit tests/Integration/StoryblokHydratorIntegrationTest.php
```

Expected: PASS, all tests in the file.

If `testHydratesAComponentEmbeddedInARichtextField` is awkward about the generated accessor, note that `$embedded->toArray()` also carries a `component` key — hence `array_intersect_key` rather than a whole-array `assertSame`.

- [ ] **Step 3: Run the whole suite**

```bash
make test
```

Expected: PASS. Everything from Task 3's known-failing list is now addressed.

- [ ] **Step 4: Lint, analyse, commit**

```bash
make cs
make stan
git add tests/Integration/StoryblokHydratorIntegrationTest.php
git commit -m "Cover richtext hydration against generated classes"
```

---

### Task 6: Prove renderer interop

**Files:**
- Modify: `composer.json`, `composer.lock`
- Create: `tests/Integration/RichtextResolverInteropTest.php`

**Interfaces:**
- Consumes: everything from Tasks 1–4.
- Produces: nothing consumed later. This is the test that pins the design's premise — that `toDocument()` stays walkable by a real renderer, and that `getBloks()` fills the hole that renderer leaves.

- [ ] **Step 1: Add the dependency**

```bash
docker compose run --rm php composer require --dev "storyblok/richtext-resolver:^2.2"
```

Expected: locks 2.2.1. It declares only `php >= 7.3.0`, so it installs on both the container's 8.3 and the library's 8.1 floor — no pin needed beyond `^2.2`. Confirm the lock did not move the PHP floor:

```bash
docker compose run --rm php composer why-not php 8.1
```

- [ ] **Step 2: Write the failing test**

Create `tests/Integration/RichtextResolverInteropTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Storyblok\RichtextRender\Resolver;
use Storyblok\RichtextRender\Schema;
use Tlab\StoryblokTransfers\Hydration\StoryblokHydrator;
use Tlab\StoryblokTransfers\Tests\Fixture\NestedFixtureTransfer;
use Tlab\StoryblokTransfers\Tests\Fixture\RichtextFixtureTransfer;
use Tlab\StoryblokTransfers\Transfers\RichtextTransfer;

/**
 * Pins the reason the node tree is kept as plain arrays: it has to stay
 * walkable by a renderer nobody here wrote.
 *
 * storyblok/richtext-resolver's default schema has no blok node, so it drops
 * embedded components silently. getBloks() is what lets a consumer put them
 * back, which is the whole shape of this design.
 */
final class RichtextResolverInteropTest extends TestCase
{
    private RichtextTransfer $richtext;

    protected function setUp(): void
    {
        $hydrator = new StoryblokHydrator('Tlab\\StoryblokTransfers\\Tests\\Fixture');

        $transfer = $hydrator->hydrate(RichtextFixtureTransfer::class, [
            'body' => [
                'type' => 'doc',
                'content' => [
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Before']]],
                    ['type' => 'blok', 'attrs' => ['id' => 'blok-1', 'body' => [
                        ['component' => 'nested_fixture', 'headline' => 'Click me'],
                    ]]],
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'After']]],
                ],
            ],
        ]);

        self::assertInstanceOf(RichtextFixtureTransfer::class, $transfer);
        $body = $transfer->getBody();
        self::assertInstanceOf(RichtextTransfer::class, $body);

        $this->richtext = $body;
    }

    public function testTheStockResolverWalksTheTreeAndDropsEmbeddedBloks(): void
    {
        $html = (new Resolver())->render($this->richtext->toDocument());

        self::assertSame('<p>Before</p><p>After</p>', $html);
    }

    public function testABlokNodeResolverRendersEmbeddedComponentsThroughGetBloks(): void
    {
        $richtext = $this->richtext;
        $schema = new Schema();

        // Passing nodes REPLACES the default schema rather than extending it,
        // so the defaults have to be merged back in - otherwise paragraphs and
        // text stop rendering too.
        $resolver = new Resolver([
            'marks' => $schema->getMarks(),
            'nodes' => $schema->getNodes() + [
                'blok' => static function (array $node) use ($richtext): array {
                    $id = $node['attrs']['id'];
                    $html = '';

                    foreach ($richtext->getBloks()[$id] ?? [] as $blok) {
                        if ($blok instanceof NestedFixtureTransfer) {
                            $html .= '<button>' . $blok->getHeadline() . '</button>';
                        }
                    }

                    return ['html' => $html];
                },
            ],
        ]);

        self::assertSame(
            '<p>Before</p><button>Click me</button><p>After</p>',
            $resolver->render($richtext->toDocument())
        );
    }
}
```

- [ ] **Step 3: Run the test**

```bash
docker compose run --rm php vendor/bin/phpunit tests/Integration/RichtextResolverInteropTest.php
```

Expected: PASS, 2 tests. Both assertions are about a third-party package's real output, so if the HTML differs (whitespace, self-closing style), adjust the *expected string* to what the resolver actually emits — do not adjust `toDocument()` to suit it. Record any surprise in the test's docblock.

- [ ] **Step 4: Lint, analyse, commit**

```bash
make cs
make stan
git add composer.json composer.lock tests/Integration/RichtextResolverInteropTest.php
git commit -m "Prove richtext interop with storyblok/richtext-resolver"
```

---

### Task 7: Documentation and end-to-end check

**Files:**
- Modify: `README.md:176`, `README.md:230`, and the hydration section that follows line 230
- Run: `tools/smoke-test.php`

**Interfaces:**
- Consumes: everything above.
- Produces: the documented contract.

- [ ] **Step 1: Update the field-type mapping table**

In `README.md`, the row at line 176 currently reads:

```markdown
| `richtext`, `table` | `?array` | Storyblok's own node/table structure |
```

Split it, keeping the rows in their existing order relative to the bundled types:

```markdown
| `table` | `?array` | Storyblok's own table structure |
| `richtext` | `?RichtextTransfer` | Bundled — node tree plus the bloks embedded in it |
```

- [ ] **Step 2: Update the hydration table**

The row at line 230 currently reads:

```markdown
| `richtext`, `table`, custom, `options` | passed through untouched |
```

Replace with:

```markdown
| `richtext` | `RichtextTransfer`, with embedded bloks hydrated and keyed |
| `table`, custom, `options` | passed through untouched |
```

- [ ] **Step 3: Document the contract and the renderer handoff**

After the hydration table's existing prose about raw arrays, add:

```markdown
### Richtext

A richtext field hydrates into a `RichtextTransfer`, which keeps the document's
own keys and the components embedded in its tree:

```php
$body = $page->getBody();

$body->getType();     // "doc"
$body->getContent();  // the node list, plain arrays exactly as Storyblok sent it
$body->getBloks();    // ['<blok node id>' => [TeaserTransfer, ...]]
```

The node tree is never modified. Storyblok wraps an inserted component in a
`blok` node whose `attrs.body` holds the component objects; those are hydrated
and hung off `getBloks()` under the node's `attrs.id`, rather than written back
into the tree. That keeps the tree walkable by a renderer, which is what every
PHP richtext renderer expects — objects sitting where `['component' => ...]`
belongs would break them.

A blok node with no `attrs.id` is not collected: a resolver looks these up *by*
that id, so there would be no way to reach it. As with `bloks` fields, a
component with no generated class stays a raw array, so guard on type when you
iterate:

```php
foreach ($body->getBloks()['blok-1'] ?? [] as $blok) {
    if ($blok instanceof TeaserTransfer) {
        echo $blok->getHeadline();
    }
}
```

To render, hand `toDocument()` to a renderer — it returns the document node,
which is what they take — and resolve `blok` nodes through `getBloks()`. With
[`storyblok/richtext-resolver`](https://github.com/storyblok/storyblok-php-richtext-renderer):

```php
$schema = new Schema();

$resolver = new Resolver([
    'marks' => $schema->getMarks(),
    // Passing `nodes` replaces the default schema rather than extending it.
    'nodes' => $schema->getNodes() + [
        'blok' => fn (array $node): array => [
            'html' => $twig->render('bloks.html.twig', [
                'bloks' => $body->getBloks()[$node['attrs']['id']] ?? [],
            ]),
        ],
    ],
]);

echo $resolver->render($body->toDocument());
```

That resolver's default schema has no `blok` node at all, so embedded components
render as nothing until you add one.

`getBloks()` is derived from the tree rather than sent by Storyblok, so it does
not survive `fromArray(toArray())` — rehydrate from the payload instead.
```

- [ ] **Step 4: Verify end to end against a real space**

```bash
docker compose run --rm php php tools/smoke-test.php home
```

Expected: PASS, and the dump now shows `RichtextTransfer` where it previously
showed `array:3` for the `richtext_section` body. Confirm the transfer count rose
accordingly and that no `✗ … uninitialized` line appears.

- [ ] **Step 5: Full verification**

```bash
make test
make cs
make stan
```

Expected: all three clean.

- [ ] **Step 6: Commit**

```bash
git add README.md
git commit -m "Document the richtext contract and renderer handoff"
```

---

## Self-Review

**Spec coverage.** Every section of the spec maps to a task: `RichtextTransfer` and `toDocument()` → Task 1; `RichtextBlokCollector` and all nine "Collection rules" rows → Task 2; the `FieldTypeMapper` case → Task 3; the `convert()` branch and `hydrateRichtext()` → Task 4; the five "Testing" integration bullets → Task 5; "Renderer interop" and the `require-dev` entry → Task 6; "Documentation" → Task 7. The two spec items with no task are deliberate: fixing `processArrayType()` upstream is listed out of scope, and the smoke-test expectation is covered by Task 7's Step 4 rather than by a code change.

**One correction to the spec.** The spec's component table gives `$attrs` as `?array`, which contradicts the spec's own reasoning two paragraphs later — a nullable array property is exactly what makes `toArray(true)` emit `foreach() argument must be of type array|object, null given`, and `phpunit.xml` has `failOnWarning="true"`, so it would fail the suite. This plan makes `$attrs` non-nullable with an `[]` default, and `toDocument()` omits it when empty. The spec needs the same edit.

**Placeholder scan.** No TBDs; every code step carries the actual code, and every test step carries the actual assertions and the exact command with its expected output.

**Type consistency.** `getBloks()`/`setBloks()` use `array<string, list<mixed>>` in Task 1, and `RichtextBlokCollector::collect()` returns the same type in Task 2, so the Task 4 assignment type-checks. `collect()` is called with `$richtext->getContent()` — a node list, matching its `@param array<mixed> $content`. `hydrateRichtext()` declares `@param array<string, mixed>`, mirroring `hydrate()`'s existing annotation so the narrowed `array` from `convert()` reaches it the same way it already does today.
