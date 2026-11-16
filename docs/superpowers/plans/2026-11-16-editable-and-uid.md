# Keep `_uid`, `_editable` and the Story Id Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every generated transfer exposes `getUid()` and `getEditable()` from Storyblok's `_uid` and `_editable`, and `StoryTransfer` exposes the numeric story `getId()`.

**Architecture:** The generator appends `uid` and `editable` (`?string`) to every component definition, after the schema fields. Upstream `AbstractTransfer::fromArray()` already maps `_uid` → `uid` and `_editable` → `editable`. When a schema field already owns one of those names, the field wins: the generator warns, and the hydrator drops the underscored key before `fromArray()`. `StoryMapper` reads the story `id` into a new trailing `StoryTransfer` constructor parameter.

**Tech Stack:** PHP 8.3, PHPUnit, PHPStan, PHPCS, `tuxonice/transfer-objects` v1.2.0. Every PHP command runs in Docker.

**Spec:** `docs/superpowers/specs/2026-11-16-editable-and-uid-design.md`

## Global Constraints

- Run every PHP command through Docker: `make test`, `make cs`, `make stan`, or `docker compose run --rm php vendor/bin/phpunit --filter <Name>`. Never run local `php`.
- Work on a branch `feat/editable-and-uid`, not `main`.
- Commit messages: Conventional Commits (`feat:`, `docs:`, `test:`). **No `Co-Authored-By` trailer.**
- No changes to `vendor/` or to `tuxonice/transfer-objects`.
- The two properties are named exactly `uid` and `editable`, typed `string`, nullable, and appended **after** the schema fields.
- `StoryTransfer`'s new parameter is `?int $id = null`, placed **last** in the constructor.

## Review Focus

1. A component with a schema field named `uid` or `editable`. The field keeps its getter, generation still succeeds (no duplicate property), and one warning names the component and the field. Test: Task 2, Step 1.
2. A payload that has both `editable` and `_editable`, in either key order. `getEditable()` returns the field's value both times. Test: Task 3, Step 1.
3. A published read with no `_uid`/`_editable`. Both getters return `null` and nothing throws. Test: Task 3, Step 1.
4. A component whose schema fields are all unusable. It must still be skipped: the two appended properties must not make it count as non-empty. The existing `testSkipsComponentsThatEndUpWithNoUsableFields` covers this; it must still pass in Task 2.
5. Nested `bloks` and components embedded in richtext. Their `_editable` must survive too, not only the root's. Test: Task 3, Step 1.

---

### Task 0: Branch and commit the spec and plan

- [ ] **Step 1:** `git checkout -b feat/editable-and-uid`
- [ ] **Step 2:** Commit both docs.

```bash
git add docs/superpowers/specs/2026-11-16-editable-and-uid-design.md docs/superpowers/plans/2026-11-16-editable-and-uid.md
git commit -m "docs: design and plan for keeping _uid, _editable and the story id"
```

### Task 1: Story id on the envelope

**Files:**
- Modify: `src/Content/StoryTransfer.php` (constructor, new getter)
- Modify: `src/Content/StoryMapper.php` (`envelope()`)
- Test: `tests/Unit/StoryMapperTest.php`

**Interfaces:**
- Produces: `StoryTransfer::__construct(..., array $translatedSlugs = [], ?int $id = null)` and `StoryTransfer::getId(): ?int`

- [ ] **Step 1: Write the failing tests**

In `StoryMapperTest`, add `'id' => 42` to `storyPayload()`. Then:
- in `testFillsTheEnvelope`: `self::assertSame(42, $story->getId());`
- in `testAcceptsAStoryWithOnlyTheGuaranteedKeys`: `self::assertNull($story->getId());`
- new `testIgnoresANonIntegerStoryId`: map a payload with `'id' => '42'` (a string) and `assertNull($story->getId())`. This matches how `parent_id` is read.

- [ ] **Step 2: Run them and check that they fail**

Run: `docker compose run --rm php vendor/bin/phpunit --filter StoryMapperTest`
Expected: FAIL, `Call to undefined method ...StoryTransfer::getId()`

- [ ] **Step 3: Implement the change**

Add `private ?int $id = null` as the last promoted constructor parameter of `StoryTransfer`, plus `getId(): ?int`. In `StoryMapper::envelope()`, pass `is_int($story['id'] ?? null) ? $story['id'] : null` as the last argument.

- [ ] **Step 4: Run them and check that they pass**

Run: `docker compose run --rm php vendor/bin/phpunit --filter StoryMapperTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Content/StoryTransfer.php src/Content/StoryMapper.php tests/Unit/StoryMapperTest.php
git commit -m "feat: keep the numeric story id on StoryTransfer"
```

### Task 2: Generator appends `uid` and `editable`

**Files:**
- Modify: `src/StoryblokTransferGenerator.php` (`generate()`, new private helper)
- Test: `tests/Integration/StoryblokTransferGeneratorTest.php`

**Interfaces:**
- Produces: every generated class has `getUid(): ?string`, `setUid(?string)`, `getEditable(): ?string` and `setEditable(?string)`. Each definition JSON ends with `{"name": "uid", "type": "string", "nullable": true}` and `{"name": "editable", "type": "string", "nullable": true}`, the same shape `FieldTypeMapper::scalar()` emits.

- [ ] **Step 1: Write the failing tests**

Use the existing `generatorFor()` helper.
- `testAppendsUidAndEditableToEveryDefinition`: generate `hero` with schema `['title' => ['type' => 'text']]`. Decode `Hero.json` and assert that the last two entries of `transfers[0].properties` are exactly the two arrays above, in that order.
- `testGeneratedClassHydratesUidAndEditable`: generate `round_trip`, `require` it as `testRoundTripsAStoryPayloadThroughTheGeneratedClass` does, then call `fromArray(['_uid' => 'abc-123', '_editable' => '<!--#storyblok#{"id":1}-->', 'headline' => 'Hi'])`. Assert that `getUid()` is `'abc-123'` and `getEditable()` is the comment string. Use the same `@phpstan-ignore-next-line` comments as that test.
- `testASchemaFieldNamedUidWinsAndIsReported`: generate `hero` with schema `['uid' => ['type' => 'text'], 'editable' => ['type' => 'boolean'], 'title' => ['type' => 'text']]`. Assert:
  - `Hero.json` has exactly one property named `uid`, and it is the schema field (`type` `string`). It also has exactly one named `editable`, and it is the schema field (`type` `bool`).
  - `$result->warnings` has 2 entries. Each contains `hero`, and they contain `uid` and `editable` respectively.
  - The generated file is valid PHP. Reuse the `php -l` check from `testEveryGeneratedClassIsValidPhp`, or `require` it.

- [ ] **Step 2: Run them and check that they fail**

Run: `docker compose run --rm php vendor/bin/phpunit --filter StoryblokTransferGeneratorTest`
Expected: the three new tests FAIL. The existing ones pass.

- [ ] **Step 3: Implement the change**

In `generate()`, keep the `if ($properties === []) { continue; }` check **before** appending, so Review Focus 4 holds. After it, call a new `private function withStoryblokMetadata(string $componentName, array $properties, array &$warnings): array`. For each of `uid` and `editable` (a `private const METADATA_PROPERTIES = ['uid', 'editable']`):
- if a property of that name is already present, add a warning and skip it
- otherwise append `['name' => $name, 'type' => 'string', 'nullable' => true]`

Warning text, with `%2$s` as the property name and `%1$s` as the component:
`'Component "%1$s" has a field named "%2$s", so get%3$s() returns that field rather than Storyblok\'s _%2$s.'`, where `%3$s` is `ucfirst($name)`.

- [ ] **Step 4: Run them and check that they pass, with no regressions**

Run: `docker compose run --rm php vendor/bin/phpunit --filter StoryblokTransferGeneratorTest`
Expected: PASS, including `testSkipsComponentsThatEndUpWithNoUsableFields` and `testReportsWhatItGenerated` (which still expects no warnings).

- [ ] **Step 5: Commit**

```bash
git add src/StoryblokTransferGenerator.php tests/Integration/StoryblokTransferGeneratorTest.php
git commit -m "feat: generate uid and editable on every component transfer"
```

### Task 3: Hydrator: the schema field wins a collision

**Files:**
- Create: `tests/Fixture/MetaFixtureTransfer.php`
- Create: `tests/Fixture/CollidingFixtureTransfer.php`
- Modify: `src/Hydration/StoryblokHydrator.php` (`hydrate()`)
- Test: `tests/Unit/StoryblokHydratorTest.php`

**Interfaces:**
- Consumes: the property shape from Task 2. The fixtures stand in for generated classes.
- Produces: no new public API. `hydrate()` drops `_uid` when the content also has `uid`, and `_editable` when it also has `editable`.

- [ ] **Step 1: Write the fixtures and the failing tests**

Fixtures. Both extend `AbstractTransfer`, with private nullable properties plus getters and setters in the style of `ScalarFixtureTransfer`:
- `MetaFixtureTransfer` (component `meta_fixture`): `?string $title`, `?string $uid`, `?string $editable`. This is what a generated class now looks like.
- `CollidingFixtureTransfer` (component `colliding_fixture`): `?string $uid`, `?string $editable`, where both are schema fields.

Tests in `StoryblokHydratorTest`:
- `testKeepsUidAndEditable`: hydrate `MetaFixtureTransfer` with `['component' => 'meta_fixture', '_uid' => 'u1', '_editable' => '<!--e1-->', 'title' => 'T']`. Assert both getters.
- `testUidAndEditableAreNullOnAPublishedRead`: the same without the underscored keys. Assert both getters are `null`.
- `testASchemaFieldWinsOverTheMetadataKey` with a `#[DataProvider]` of two key orders:
  `['_uid' => 'meta', 'uid' => 'field', '_editable' => 'meta', 'editable' => 'field']` and `['uid' => 'field', '_uid' => 'meta', 'editable' => 'field', '_editable' => 'meta']`. Hydrate `CollidingFixtureTransfer` and assert both getters return `'field'`.
- `testKeepsEditableOnNestedBloks`: hydrate `NestedFixtureTransfer` with `'body' => [['component' => 'meta_fixture', '_uid' => 'b1', '_editable' => '<!--b1-->']]`. Assert that `getBody()[0]` is a `MetaFixtureTransfer` whose `getEditable()` is `'<!--b1-->'`.
- `testKeepsEditableOnBloksEmbeddedInRichtext`: follow `testCollectsAComponentEmbeddedInRichtext`, with a `meta_fixture` blok carrying `_editable`. Assert that `getEditable()` on the collected transfer returns it.

- [ ] **Step 2: Run them and check the results**

Run: `docker compose run --rm php vendor/bin/phpunit --filter StoryblokHydratorTest`
Expected: only `testASchemaFieldWinsOverTheMetadataKey` FAILS, and only for the key order where the underscored key comes last. The other new tests already pass, because `fromArray()` does the mapping. That confirms Task 2's premise.

- [ ] **Step 3: Implement the change**

At the top of `hydrate()`, after `assertHydratable()`: for each name in a `private const METADATA_KEYS = ['uid', 'editable']`, if `array_key_exists($name, $content)`, `unset($content['_' . $name])`. Add a docblock line explaining why: a schema field with that name owns the property, and the payload's key order must not decide which value wins.

- [ ] **Step 4: Run the whole suite and the linters**

Run: `make test && make cs && make stan`
Expected: all green.

- [ ] **Step 5: Commit**

```bash
git add src/Hydration/StoryblokHydrator.php tests/Fixture/MetaFixtureTransfer.php tests/Fixture/CollidingFixtureTransfer.php tests/Unit/StoryblokHydratorTest.php
git commit -m "feat: let a schema field named uid or editable win over Storyblok's metadata"
```

### Task 4: Docs and changelog

**Files:**
- Modify: `documentation/reference/field-types.md` (after the paragraph ending "…that a field is filled in.")
- Modify: `documentation/hydration.md` (a short section before `## Errors`)
- Modify: `documentation/laravel.md` and `documentation/symfony.md` (the `## Previewing drafts` sections)
- Modify: `documentation/limitations.md` (new section)
- Modify: `documentation/reading-content.md` (the "What you get back" envelope list, if it lists getters: add `getId()`)
- Modify: `CHANGELOG.md` (`## [Unreleased]`)

- [ ] **Step 1: Write the docs.** Content, in the existing docs' voice:
  - **field-types.md:** every generated class also has `getUid()` and `getEditable()`, filled from Storyblok's `_uid` and `_editable`. `getEditable()` is `null` on published reads.
  - **hydration.md:** the same two properties are filled by `StoryblokHydrator` too.
  - **laravel.md, Previewing drafts:** to get click-to-edit outlines in the Visual Editor, print the comment before each block:
    `{!! $blok->getEditable() !!}`. It is empty on published reads, so it can be printed unconditionally. Mention `$story->getId()` for matching the bridge's events to the current story.
  - **symfony.md, Previewing drafts:** the same in Twig, `{{ blok.editable|raw }}`.
  - **limitations.md:** `## A field named uid or editable hides Storyblok's metadata`. The schema field keeps the getter, the generator warns, and the fix is to rename the field in Storyblok.
  - **CHANGELOG `[Unreleased]` → `### Added`:** generated transfers keep `_uid` and `_editable` (`getUid()`, `getEditable()`) for click-to-edit in the Visual Editor, and `StoryTransfer::getId()`. **Regenerate your classes to get them.** Link to the Laravel guide's Previewing drafts section.

- [ ] **Step 2: Check the links**

Run: `grep -n "previewing-drafts" documentation/*.md CHANGELOG.md`
Expected: every link targets an existing `## Previewing drafts` heading.

- [ ] **Step 3: Commit**

```bash
git add documentation CHANGELOG.md
git commit -m "docs: document uid, editable and the story id"
```
