# StoryblokHydrator — Design

**Date:** 2026-08-05
**Status:** Approved

## Problem

`AbstractTransfer::fromArray()` passes raw payload values straight to the
setter. A generated transfer with an asset field therefore cannot be hydrated
from a Storyblok payload at all:

```php
// TypeError: setImage(): Argument #1 must be of type ?AssetTransfer, array given
ArticleTransfer::fromArray(['featured_image' => ['id' => 1, 'filename' => 'a.jpg']]);
```

This is upstream behaviour in `tuxonice/transfer-objects`, pinned by
`StoryblokTransferGeneratorTest::testFromArrayCannotHydrateNestedTransferFields()`.
Today the README tells consumers to map those fields by hand, which is the
boilerplate the generated classes were supposed to remove.

`StoryblokHydrator` converts a Storyblok content array into a fully populated
transfer graph.

## Approach

Pre-convert values, then delegate assignment to `fromArray()`.

The hydrator walks the payload and replaces only the values that must become
objects, then hands the result to `$transferClass::fromArray()`. Upstream
already handles payload-key camel-casing, setter dispatch and skipping unknown
keys correctly; only value conversion is broken. Borrowing the working
machinery keeps this to roughly 40 lines of conversion logic and avoids drifting
from upstream's key-derivation rules.

Rejected alternatives:

- **Reimplement assignment** via reflected setters. Full control, but duplicates
  upstream's key derivation, which must stay byte-identical or properties
  silently stop hydrating.
- **Generate a hydrator per transfer class.** Fastest at runtime, but adds a
  second generated artefact to keep in sync for no measured need.

## Components

Under `Tlab\StoryblokTransfers\Hydration` in `src/Hydration/`, except
`ComponentNameFormatter`, which is shared with the generator.

### `ComponentNameFormatter`

`toTransferName(string $componentName): string` — `product_core` → `ProductCore`,
treating `_`, `-` and `.` as word separators.

This rule is currently duplicated in two private methods,
`DefinitionFileWriter::toPascalCase()` and
`StoryblokTransferGenerator::transferNameOf()`. The hydrator needs the same rule
to turn a blok's `component` into a class name, and a third copy is how the
generator and hydrator would silently disagree. Extract it and have all three
call it.

Lives in `src/Schema/`, since the generator side owns it.

### `HydrationException`

`RuntimeException` subclass in `src/Hydration/`, thrown only for programming
errors — see Error handling.

### `PropertyType`

Value object describing what a single property needs.

| Property | Meaning |
|---|---|
| `?string $transferClass` | Property holds one nested transfer; FQCN of it |
| `?string $elementTransferClass` | Property is an array of transfers; FQCN of the element |

Both null means the value passes through untouched.

### `PropertyTypeResolver`

`resolve(string $transferClass): array<string, PropertyType>`, keyed by property
name.

- Single nested transfers come from `ReflectionProperty::getType()`: a
  `ReflectionNamedType` naming a class that implements `TransferInterface`.
- Array element types come from the property's `@var array<Short>` docblock. The
  short name is resolved to an FQCN by scanning the declaring class's method
  parameters for a type whose short name matches — the generated
  `add{Singular}(BlokTransfer $x)` method. PHP resolved that type at compile
  time, so this avoids reimplementing `use`-statement resolution, which
  reflection does not expose.
- `array<string>`, `array<mixed>` and other non-class element types yield an
  empty `PropertyType`.
- Results are cached per class, so hydrating a list of bloks of the same
  component reflects once.

### `StoryblokHydrator`

```php
public function __construct(private readonly string $namespace) {}

public function hydrate(string $transferClass, array $content): AbstractTransfer;
```

`$namespace` is the namespace the generated classes live in, e.g.
`App\DataTransferObjects`.

Algorithm:

1. Guard that `$transferClass` exists and is a subclass of `AbstractTransfer`;
   throw `HydrationException` otherwise.
2. Resolve the property-type map for the class.
3. For each payload key, derive the property name with the existing
   `PropertyNameNormalizer` — already a byte-for-byte mirror of
   `AbstractTransfer::fromArray()`'s derivation — and look up its
   `PropertyType`. A key the normalizer rejects (`headline_2`) yields no type
   info, so it passes through and `fromArray()` then ignores it, which is
   exactly right: no such property was generated.
4. Convert the value per the rules below.
5. Return `$transferClass::fromArray($converted)`.

## Conversion rules

| Property shape | Payload value | Result |
|---|---|---|
| nested transfer | array | recursively hydrated instance |
| nested transfer | anything else (`""`, `null`, scalar) | `null` |
| element type is `BlokTransfer` | array of arrays | one concrete transfer per item, resolved from its `component` key |
| element type is `BlokTransfer` | item's component unresolvable | that item stays a raw array |
| element type is a concrete transfer | array of arrays | each item hydrated to that class |
| no type info | anything | untouched |

Nested bloks recurse through the same path, so bloks inside bloks hydrate to
arbitrary depth. No depth cap: Storyblok content is a tree.

Converting a non-array value on a transfer-typed property to `null` is what
prevents the `TypeError`. Storyblok sends empty assets and links as `""` or as
an all-null object, and every generated transfer property is nullable, so `null`
is always assignable.

## Component resolution

A blok's `component` maps to
`{$namespace}\{ComponentNameFormatter::toTransferName($component)}Transfer`.
Reusing the extracted formatter is what stops the generator and hydrator from
disagreeing about how a component name becomes a class name.

When that class does not exist — an editor added a component before anyone
regenerated — the raw array is left in place. Nothing is lost, the page still
renders, and the hydrator stays stateless.

## Consequence for the declared type

`bloks` properties are declared `@var array<BlokTransfer>` but will hold
concrete transfers (and possibly raw arrays). PHP enforces only `array`, so this
is safe at runtime, but the docblock misleads static analysis of consumer code.

This is an accepted trade: the alternative that keeps the docblock honest is
post-processing every generated class to `extends BlokTransfer`, which was
judged too invasive for the benefit.

Consumers iterating a `bloks` array must therefore guard on type:

```php
foreach ($page->getBody() as $blok) {
    if (!$blok instanceof TeaserTransfer) {
        continue;
    }

    echo $blok->getHeadline();
}
```

The README must state this explicitly.

## Error handling

| Situation | Behaviour |
|---|---|
| `$transferClass` missing or not an `AbstractTransfer` | throw `HydrationException` |
| blok component has no generated class | raw array passthrough |
| payload key matches no property | ignored, as `fromArray()` already does |
| transfer-typed property receives a non-array | `null` |

Only the first is a programming error, so only the first throws. Content drift
degrades gracefully.

## Testing

Unit tests for `ComponentNameFormatter` covering each separator, plus the
existing `DefinitionFileWriter` and generator tests, which already assert the
PascalCase behaviour and must stay green through the extraction.

Unit tests for `PropertyTypeResolver` against fixture transfer classes:
discovers a nested transfer, resolves an array element type through the
`add*` method scan, returns empty for `array<string>` and `array<mixed>`, and
caches per class.

Integration tests for `StoryblokHydrator` that generate real classes into a temp
directory and hydrate real payload shapes — matching the existing suite, so the
tests break if the generator's output shape changes:

- asset field hydrates to a populated `AssetTransfer`
- `multilink` hydrates to `LinkTransfer`, including `cached_url` → `cachedUrl`
- `multiasset` hydrates to `array<AssetTransfer>`
- `bloks` hydrate to concrete transfers selected by `component`
- a blok nested inside a blok hydrates
- unknown component leaves the raw array untouched and does not throw
- `richtext`, `table` and custom fields pass through unchanged
- `options` pass through as strings
- `""` and `null` on an asset field yield `null` rather than a `TypeError`
- the exact payload that `fromArray()` rejects now hydrates

`testFromArrayCannotHydrateNestedTransferFields()` stays as-is: it documents why
this component exists.

## Documentation

The README's "Nested transfers are not hydrated by `fromArray()`" section
currently prescribes manual mapping. Replace it with hydrator usage, and add the
blok-guard note from above.

## Out of scope

- `hydrateStory()` convenience wrapper — callers pass `$story['content']`
- component override maps
- recursion depth limits
- any change to the generator or to generated output
