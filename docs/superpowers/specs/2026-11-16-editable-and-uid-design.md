# Keep `_uid`, `_editable` and the story id — design

PACKAGE-PROPOSALS.md item 1, from the blog's FRICTION.md #17.

## Why

Storyblok's Visual Editor draws a click-to-edit outline around a block when the
page prints that block's `_editable` comment (`<!--#storyblok#{...}-->`) before
it. Draft reads carry `_editable` and `_uid` on every component, but hydration
drops both, so an app on this package only gets live reload, never outlines.
The story's numeric `id` is also dropped from the envelope, and the bridge needs
it to match a story.

## Goals

- Every generated transfer has `getUid(): ?string` and `getEditable(): ?string`,
  filled from `_uid` and `_editable` on hydration. This covers root content,
  nested `bloks` and components embedded in richtext.
- `StoryTransfer::getId(): ?int`, filled from the story's `id`.
- The docs show how to print `_editable` in a template.

## Non-goals

- No change to transfer-objects (the base class stays `AbstractTransfer`).
- No shared interface for "has editable". Templates call the getter on the
  concrete class.
- Nothing for the bundled `AssetTransfer` / `LinkTransfer` / `RichtextTransfer`:
  Storyblok sends no `_editable` on them.
- No helper that renders the comment. It is one line in a template.

## Approaches

- **A. Properties on every generated class (chosen).** The generator appends
  `uid` and `editable` (`?string`) to each component definition.
  `AbstractTransfer::fromArray()` already maps `_uid` → `uid` and `_editable` →
  `editable`, so hydration needs almost no change. Costs: users must regenerate,
  and a schema field named `uid` or `editable` collides (handled below).
- B. A `WeakMap` side table read through the envelope (`$story->editable($blok)`).
  No regeneration, but it needs the envelope everywhere, the same limitation
  relations have.
- C. A shared base class in transfer-objects. Typed, but needs a release of two
  packages.

## Design

1. **Generator.** After mapping a component's fields, append
   `{"name": "uid", "type": "string", "nullable": true}` and the same for
   `editable` (in whatever shape `FieldTypeMapper` emits for a string), unless
   the component already has a property of that name.
2. **Collisions.** If a schema field's property name is `uid` or `editable`,
   the schema field wins. The generator adds a warning to `GenerationResult`
   saying that `getUid()`/`getEditable()` on that class return the field, not
   Storyblok's metadata. The hydrator (`MetadataKeyFilter`) drops `_uid` from
   the payload when another key normalizes to `uid` (`uid`, `uid_`, `Uid`), or
   when the class's `uid` property is not `?string`; likewise for `_editable`.
   So the field wins whatever order the keys arrive in, and a number or boolean
   field never receives the metadata string.
   *Amended after the final review:* a `?string` field absent from the payload
   (a story saved before the field existed) can't be told apart from the
   metadata property, so it receives the metadata. That gap is documented in
   `limitations.md`.
3. **Envelope.** `StoryTransfer` gets an `?int $id` constructor parameter, added
   last so existing calls keep working, and `getId()`. `StoryMapper` fills it
   from `id` when it is an int.
4. **Docs.** `hydration.md` and `reference/field-types.md` mention the two
   properties. The "Previewing drafts" sections in `laravel.md` and
   `symfony.md` show printing `{!! $blok->getEditable() !!}` (Twig:
   `{{ blok.editable|raw }}`) before a block. `limitations.md` gets the
   collision rule. CHANGELOG `[Unreleased]` gets an Added entry that says to
   regenerate.

## Calls I made

- The properties go last in the definition, after the schema fields.
- Published reads have no `_editable`, so `getEditable()` returns `null` there.
  Templates can print it unconditionally.
- `toArray()` will now include `uid` and `editable`. That is acceptable: it's
  the transfers' own data, and nothing in the package round-trips `toArray()`
  back to Storyblok.

## Left to the builder

- Test placement and fixture shape, following the existing generator, hydrator
  and mapper tests.
