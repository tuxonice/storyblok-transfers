# Field types

What each Storyblok field type becomes in a generated class.

| Storyblok type | PHP property | Notes |
|---|---|---|
| `text`, `textarea`, `markdown`, `option`, `uid`, `datetime` | `?string` | `datetime` stays an ISO 8601 string |
| `story` | `?string` | The story's uuid. See [Relations](../relations.md) |
| `number` | `?float` | |
| `boolean` | `?bool` | |
| `options` | `array<string>` | Multi-select. A multi-story field gives a list of uuids |
| `asset` | `?AssetTransfer` | |
| `multiasset` | `array<AssetTransfer>` | |
| `link`, `multilink` | `?LinkTransfer` | |
| `bloks` | `array<BlokTransfer>` | Filled with your concrete classes; see [Hydrating content](../hydration.md#bloks-are-your-concrete-classes) |
| `richtext` | `?RichtextTransfer` | The document, plus the components inserted into it. See [Rich text](../rich-text.md) |
| `table` | `?array` | Storyblok's table structure |
| `tab`, `section` | not generated | Editor layout only; they hold no data |
| anything else, such as a custom plugin | `?array` | Passed through as it is |

**Arrays are never `null`.** They default to `[]`, because
`tuxonice/transfer-objects` doesn't allow nullable arrays. Every other property
is nullable, since Storyblok rarely guarantees that a field is filled in.

## Bundled transfers

These ship with the package, under `Tlab\StoryblokTransfers\Transfers`, and the
generated classes refer to them.

### `RichtextTransfer`

| Method | |
|---|---|
| `getType()` | `?string`, `doc` |
| `getAttrs()` | document-level attributes, `[]` when there are none |
| `getContent()` | the document's nodes, as Storyblok sent them |
| `getBloks()` | the components inserted into the text, by blok id |
| `toDocument()` | the whole document, for a renderer |

See [Rich text](../rich-text.md).

### `AssetTransfer`

| Getter | |
|---|---|
| `getId()` | `?int` |
| `getFilename()` | `?string`, the asset's URL |
| `getAlt()` | `?string` |
| `getTitle()` | `?string` |
| `getCopyright()` | `?string` |
| `getFieldtype()` | `?string`, always `asset` |

### `LinkTransfer`

| Getter | |
|---|---|
| `getId()` | `?string`, the linked story's uuid for internal links |
| `getUrl()` | `?string`, the URL for external links |
| `getCachedUrl()` | `?string`, the linked story's path for internal links |
| `getLinktype()` | `?string`: `story`, `url`, `email` or `asset` |
| `getFieldtype()` | `?string`, always `multilink` |

### `BlokTransfer`

Has only `getComponent()`. It's the declared type of `bloks` fields; at runtime
they hold your generated classes instead.

Every property on the bundled transfers is nullable, because Storyblok leaves
fields out when they're empty.
