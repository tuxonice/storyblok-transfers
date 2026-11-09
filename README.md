# Storyblok Transfers

[![CI](https://github.com/tuxonice/storyblok-transfers/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/tuxonice/storyblok-transfers/actions/workflows/ci.yml)

Typed PHP objects for your Storyblok content, in any framework.

- **Generate** a PHP transfer class for every component in your Storyblok space.
- **Read** stories from the Content Delivery API straight into those classes, with
  relations, drafts, languages, links and datasources.

```
Storyblok space ──generate──▶ PageTransfer, HeroTransfer, …
Storyblok story ──read──────▶ $story->getContent()  // PageTransfer
```

## Installation

```bash
composer require tuxonice/storyblok-transfers
```

Requires PHP 8.3+.

## In short

```bash
# Generate classes from your space's components
STORYBLOK_SPACE_ID=290156928914344 \
STORYBLOK_MANAGEMENT_TOKEN=your-personal-access-token \
vendor/bin/generate
```

```php
use App\DataTransferObjects\PageTransfer;
use Tlab\StoryblokTransfers\Content\StoryblokContent;

// Reads STORYBLOK_DELIVERY_TOKEN from the environment
$storyblok = StoryblokContent::fromEnvironment();

$story = $storyblok->stories()->bySlug('home', PageTransfer::class);

echo $story?->getContent()->getTitle();   // a typed PageTransfer
```

Start with **[Getting started](documentation/getting-started.md)**, which walks
through this from an empty project.

## Documentation

- [Getting started](documentation/getting-started.md)
- [Generating transfers](documentation/generating-transfers.md)
- [Reading content](documentation/reading-content.md)
- [Relations](documentation/relations.md)
- [Links and datasources](documentation/links-and-datasources.md)
- [Hydrating content yourself](documentation/hydration.md)
- [Caching](documentation/caching.md)
- [Limitations](documentation/limitations.md)
- Reference: [configuration](documentation/reference/configuration.md),
  [field types](documentation/reference/field-types.md),
  [errors](documentation/reference/errors.md)

Working on the package itself? See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

MIT
