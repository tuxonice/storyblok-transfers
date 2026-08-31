# RichtextTransfer — Design

**Date:** 2026-08-25
**Status:** Proposed

## Problem

A `richtext` field is the one place where this library stops doing its job.

`FieldTypeMapper` has no `richtext` case, so the field falls into the `default`
arm it shares with `table`, custom plugins and unknown types, and generates a
bare `?array`:

```php
/** @var array<mixed>|null */
private ?array $body = null;
```

`PropertyTypeResolver` then finds no element class for `array<mixed>`, so the
property is omitted from the conversion map and `StoryblokHydrator` assigns the
payload untouched. Verified: `getBody() === $originalPayload` is `true`.

Two consequences follow.

**Embedded bloks are invisible.** Storyblok wraps an inserted component in a
node of its own:

```json
{"type": "blok", "attrs": {"id": "blok-123", "body": [{"_uid": "1", "component": "button", "title": "Click Me"}]}}
```

`attrs.body` holds ordinary component objects — the same shape as any `bloks`
field. Because the tree passes through whole, those components stay raw arrays.
The library's entire premise, that a blok arrives as its generated class, silently
does not hold inside richtext. The smoke test cannot see it either: its raw-blok
warning only fires on properties declared `array<BlokTransfer>`.

**The field has no identity.** A consumer holding a `?array` cannot tell richtext
from a table or from an unknown plugin blob, and has nothing to hang behaviour on.

The official JS package (`storyblok/monoblok`, `packages/richtext`) confirms this
is the intended division of labour rather than an oversight on their side. Its
generated render map lists `blok: null`, and the renderer logs
`"'blok' nodes require a custom renderer in renderRichText."` and returns `""`.
Upstream deliberately leaves embedded components to the consumer. On the PHP side
we are that consumer, and we already own component-to-class resolution.

## Approach

Give `richtext` its own transfer type, and collect the embedded bloks alongside
the node tree rather than inside it.

`RichtextTransfer` holds the payload's three keys — `type`, `attrs`, `content` —
plus a derived `bloks` map keyed by each blok node's `attrs.id`. The node tree
stays pure arrays, exactly as Storyblok sent it, so any renderer can still walk
it; the hydrated components sit beside it, reachable by the key a renderer has in
hand when it meets a `blok` node.

The alternative is hydrating bloks in place, inside the tree. It reads more
naturally and needs no correlation step, but it destroys renderer interop: every
PHP renderer walks arrays, and would meet an object where `['component' => …]` is
expected. Keeping the tree renderer-shaped is the whole point of the split.

Rejected alternatives:

- **Model the node and mark taxonomy as classes** (`ParagraphNode`, `LinkMark`,
  …). Upstream *generates* its 18 node types and 12 marks from the Tiptap DOM
  spec precisely because they drift — the node names are not even internally
  consistent (`bullet_list`, `list_item` against `tableRow`, `tableCell`). A
  hand-maintained copy here would be a standing liability, and hydration needs to
  recognise exactly one node type: `blok`.
- **Ship a renderer.** Rendering is a presentation concern and this library is
  codegen plus hydration. Storyblok already publishes
  `storyblok/richtext-resolver` for PHP.
- **Compute `bloks` lazily inside the transfer.** Transfers are plain data with a
  `final` no-argument constructor, so there is nowhere to inject the
  component-to-class resolver without a setter for a callable — which would break
  both `toArray()` and the "transfers are dumb data" property.
- **Leave the field as `?array` and hydrate bloks in place.** Non-breaking, but
  inherits the interop problem above and leaves richtext without an identity.

## Components

### `Transfers\RichtextTransfer`

Hand-written alongside `AssetTransfer`, `LinkTransfer` and `BlokTransfer`.

| property | declared type | holds |
|---|---|---|
| `$type` | `?string` | `"doc"` |
| `$attrs` | `array`, default `[]` | document-level attrs, e.g. `{"backgroundColor": null}` |
| `$content` | `array`, default `[]` | the node list, untouched |
| `$bloks` | `array`, default `[]` | `array<string, list<mixed>>`, keyed by blok node id |

The first three are named exactly for the payload's keys, which is what lets
`fromArray()` populate them with no new mapping code.

Every array property is non-nullable with an `[]` default on purpose. Upstream's
`toArray(true)` runs `foreach()` over every property whose declared type is
`array`, so a *null* one emits
`foreach() argument must be of type array|object, null given` — and `phpunit.xml`
sets `failOnWarning="true"`, so that warning fails the suite. This shape cannot
reach that state.

Plus one derived method:

```php
public function toDocument(): array
```

Rebuilds `['type' => …, 'attrs' => …, 'content' => …]`, omitting `type` when null
and `attrs` when empty, so every real payload shape round-trips exactly. This is
the renderer entry point: `Resolver::render()` reads `$data['content']`, so it
needs the document node, not the bare list.

### `Hydration\RichtextBlokCollector`

```php
/**
 * @param array<mixed> $content
 * @param callable(array<string,mixed>): mixed $hydrate
 * @return array<string, list<mixed>>
 */
public function collect(array $content, callable $hydrate): array
```

Walks the node list; for every node whose `type` is `blok`, maps each entry of
`attrs.body` through `$hydrate` and files the results under `attrs.id`. Descends
through every node's `content`, which covers list items and table cells without
naming them.

`$hydrate` receives one component array and returns its hydrated transfer, or
that array unchanged when its component has no generated class.

Hydration arrives as a callable rather than as a `StoryblokHydrator` dependency:
it keeps component-to-class resolution in one place and avoids a cycle. The
hydrator passes its existing `convertElement(BlokTransfer::class, …)`, so no new
resolution logic is written.

The collector deliberately does **not** descend into `attrs.body`. Those
components go through `hydrate()`, so a richtext field inside an embedded blok
becomes its own `RichtextTransfer` with its own `$bloks`. Each document owns only
the bloks written into it.

### `Schema\FieldTypeMapper`

One explicit case, ahead of the `default` arm:

```php
'richtext' => $this->transfer($name, 'RichtextTransfer', RichtextTransfer::class),
```

`table`, custom plugins and unknown types stay in `default` as `?array`. This is
the same path `asset` already takes, so the definition JSON gains a `namespace`
entry and the generated property becomes `private ?RichtextTransfer $body = null;`.

### `Hydration\StoryblokHydrator`

One branch in `convert()`:

```php
if ($nestedClass === RichtextTransfer::class) {
    return is_array($value) ? $this->hydrateRichtext($value) : null;
}
```

`hydrateRichtext()` calls `RichtextTransfer::fromArray($tree)` directly rather
than `$this->hydrate()`: `RichtextTransfer` has no transfer-typed property, so
there is nothing to pre-convert, and `fromArray()` returns `static` — the precise
type, with no narrowing needed for PHPStan level 8. If `RichtextTransfer` ever
gains a transfer-typed property this must move back to `hydrate()`.

It then sets the collected bloks and returns.

## Collection rules

| Situation | Behaviour |
|---|---|
| `attrs.body` is `[]` | nothing collected |
| `attrs.body` is `null` | nothing collected |
| several components in one blok node | one list under that id, in payload order |
| blok nested deep in `content` | collected — recursion follows every `content` |
| duplicate `attrs.id` | lists merged, in document order |
| `attrs.id` missing or not a non-empty string | skipped |
| component has no generated class | raw array kept in the list |
| node is not an array, or has no `type` | skipped |
| the field value is not an array (`""`, `null`) | property is `null`, as today |

The empty and null `body` cases and the several-components case are taken from
upstream's own test fixtures.

Skipping a node with no usable `attrs.id` needs justifying, since it means
components present in the tree are absent from `getBloks()`. A resolver looks
bloks up *by* that id, so a node without one has no reachable key; inventing a
positional key would be worse than the honest omission. Storyblok always emits
it, so the rule is defensive only.

Keeping an unresolvable component as a raw array matches the rule the hydrator
already follows: an editor adding a component must not break the page.

That is also why `$bloks` is typed `array<string, list<mixed>>` and not
`list<AbstractTransfer>`: a list may hold transfers, raw arrays, or both, and
the hydration callable is only contracted to return `mixed`. Consumers guard
with `instanceof`, exactly as the hydrator design already requires of `bloks`
arrays — see "Consequence for the declared type" in
`2026-08-05-storyblok-hydrator-design.md`.

## Renderer interop

`storyblok/richtext-resolver` 2.2.1 (`php >= 7.3.0`, last released 2023-08-07)
takes the document node and dispatches per node type through closures:

```php
$resolver = new Resolver();
$html = $resolver->render($page->getBody()->toDocument());
```

Its default schema has no `blok` node — `getNodes()` covers `blockquote`,
`bullet_list`, `list_item`, `ordered_list`, `paragraph`, `horizontal_rule`,
`hard_break`, `image`, `code_block`, `heading`, `emoji` — so today it drops
embedded bloks silently. `$bloks` fills exactly that hole, because a node closure
may return `['html' => …]`:

```php
$schema = new Schema();
$resolver = new Resolver([
    'marks' => $schema->getMarks(),
    'nodes' => $schema->getNodes() + [
        'blok' => static fn (array $node): array => [
            'html' => $view->render($richtext->getBloks()[$node['attrs']['id']]),
        ],
    ],
]);
```

Two warts to encode rather than rediscover: passing `nodes` **replaces** the
default schema instead of extending it, hence the merge; and the resolver reads
`$data['content']` without guarding, so it needs `toDocument()`, not
`getContent()`.

## Consequences

**Breaking change to generated code.** A richtext property's type changes from
`?array` to `?RichtextTransfer`, so consumer getters change type. Nothing is
released — no tags, no `version` in `composer.json` — so no migration path is
designed for.

**`$bloks` is derived, not payload.** `fromArray(toArray())` will not reconstruct
it, and `toArray(true)` drops its keys through the upstream re-indexing described
below. It stays rebuildable from `$content`, and the smoke test's `toArray(true)`
step gets a documented expectation rather than an assumed one.

**Most of the `toArray(true)` bug stops mattering.** `AbstractTransfer::processArrayType()`
appends with `$data[]`, so an `array` property's top-level string keys are lost:
today a whole richtext document degrades from
`['type' => 'doc', 'content' => […]]` to `[0 => 'doc', 1 => […]]`. Splitting the
document across three properties puts a *list* in `$content`, where re-indexing is
a no-op. Only `$attrs` is still flattened. The bug lives in
`tuxonice/transfer-objects`, shares this vendor prefix, and is better fixed there;
this design merely stops depending on the fix.

## Error handling

| Situation | Behaviour |
|---|---|
| malformed node, missing `attrs`, non-array `body` | skipped |
| blok component has no generated class | raw array in the list |
| richtext field value is not an array | property is `null` |

No new exception type. The collector is defensive throughout because richtext is
editor-controlled content, and `HydrationException` keeps its single existing
meaning: the *transfer class* is not usable.

## Testing

Written test-first.

`RichtextBlokCollectorTest` — one case per row of "Collection rules", plus a
document with no bloks at all, and a blok inside a table cell.

Three existing tests assert the behaviour being changed. They are rewritten, not
deleted, because the pass-through guarantee survives in a sharper form —
`getContent()` identical to the payload's `content`:

- `FieldTypeMapperTest::testMapsRichtextToNullableArray` (`tests/Unit/FieldTypeMapperTest.php:46`)
- `StoryblokHydratorTest::testPassesRichtextStructuresThroughUntouched` (`tests/Unit/StoryblokHydratorTest.php:54`)
- `StoryblokHydratorIntegrationTest::testPassesRichtextThroughUntouched` (`tests/Integration/StoryblokHydratorIntegrationTest.php:183`)

New integration coverage, generating real classes into a temp directory as the
existing suite does:

- a richtext field hydrates to `RichtextTransfer` with `getContent()` identical
  to the payload's `content`
- an embedded blok is reachable as its generated class through `getBloks()`
- an embedded blok whose component has no class stays a raw array
- a richtext field inside an embedded blok becomes its own `RichtextTransfer`
- `toDocument()` reproduces the payload for both shapes, with and without `attrs`

`RichtextResolverInteropTest`, with `storyblok/richtext-resolver` in
`require-dev` — the test that pins the design's premise:

- `render(toDocument())` renders the surrounding paragraphs and drops the blok,
  documenting stock behaviour
- with a `blok` closure reading `getBloks()`, the embedded component renders

## Documentation

The README's two field-type tables (lines 176 and 230) currently document
richtext as `?array` and "passed through untouched". Both change, and the
consumer-facing section gains the resolver handoff from "Renderer interop",
including the schema-replacement wart.

## Out of scope

- rendering richtext in this library
- modelling `table`, custom plugins or unknown field types
- a node or mark taxonomy
- fixing `processArrayType()` in `tuxonice/transfer-objects` — tracked
  separately; this design does not depend on it
- resolving `story` links or optimising image nodes inside the tree
