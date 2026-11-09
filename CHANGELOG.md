# Changelog

All notable changes to this package are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
the package uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

Nothing has been released yet. Everything below will make up the first release.

### Added

- **Generating transfers.** `StoryblokTransferGenerator` and `vendor/bin/generate`
  read a space's component schemas from the Management API and write one JSON
  definition and one PHP transfer class per component. Fields that cannot be
  generated are left out and reported as warnings.
- **Bundled transfers** for asset, link and blok fields: `AssetTransfer`,
  `LinkTransfer` and `BlokTransfer`.
- **Hydration.** `StoryblokHydrator` turns a Storyblok content array into the
  generated classes, including nested bloks at any depth. A blok whose component
  has no generated class stays a raw array instead of throwing.
- **Reading content.** `StoryblokContent` reads stories from the Content
  Delivery API into the generated classes:
  - stories by slug or uuid, and listings through `StoryQuery`, with filters,
    sorting and pagination
  - drafts, languages and cache versions through `ContentOptions`
  - resolved relations as whole related stories through `getRelation()`,
    including relations past Storyblok's 50-per-response limit
  - the links tree and datasource entries, with every page of entries fetched
  - a `ContentClient` interface to wrap for caching
- **Regions.** Both generating and reading work with spaces in any Storyblok
  region, through `STORYBLOK_MANAGEMENT_BASE_URI` and
  `STORYBLOK_CONTENT_BASE_URI`.
- **Documentation** in `documentation/`, starting with a getting-started guide.

### Notes for the first release

- Requires **PHP 8.3** or newer.
- `RichtextTransfer` ships with the package but is not yet used by the generator
  or the hydrator; rich text fields still arrive as arrays. See
  [Limitations](documentation/limitations.md#rich-text-is-a-raw-array).

[Unreleased]: https://github.com/tuxonice/storyblok-transfers/commits/main
