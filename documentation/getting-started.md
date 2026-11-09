# Getting started

By the end of this page you will have a PHP class for every component in your
Storyblok space, and a story read from Storyblok into one of them.

## 1. Install

```bash
composer require tuxonice/storyblok-transfers
```

PHP 8.1 or newer.

## 2. Get your two tokens

The package talks to two different Storyblok APIs, and each needs its own token.
They are **not interchangeable**.

| Token | Used for | Where to find it |
|---|---|---|
| **Personal access token** (Management API) | Generating classes from your component schemas | My Account → Personal access tokens |
| **Preview or public token** (Content Delivery API) | Reading stories | Your space → Settings → Access Tokens |

You also need your **space ID**, from your space → Settings → General.

Put them in your environment. However your app loads its environment is fine;
the package only calls `getenv()`.

```bash
STORYBLOK_SPACE_ID=290156928914344
STORYBLOK_MANAGEMENT_TOKEN=your-personal-access-token
STORYBLOK_DELIVERY_TOKEN=your-preview-token
```

The preview token can read drafts; the public token reads published content
only. Use the preview token in development and preview environments.

> If your space is outside the EU, see
> [Configuration → Regions](reference/configuration.md#regions) before going on.

## 3. Generate the classes

```bash
vendor/bin/generate
```

This reads every component in your space and writes two things into your
project:

```
src/dto-definitions/       Page.json, Hero.json, …        commit these
src/DataTransferObjects/   PageTransfer.php, HeroTransfer.php, …
```

A component called `page` becomes `App\DataTransferObjects\PageTransfer`, with a
typed getter and setter for each field:

```php
class PageTransfer extends AbstractTransfer
{
    public function getTitle(): ?string { … }
    public function getHero(): ?AssetTransfer { … }
    public function getBody(): array { … }   // the nested bloks
}
```

If a field was left out, the command tells you which one and why. See
[Limitations](limitations.md#fields-whose-names-cannot-round-trip-are-skipped).

## 4. Make them autoloadable

Point Composer at the generated classes, then refresh the autoloader:

```json
{
    "autoload": {
        "psr-4": {
            "App\\DataTransferObjects\\": "src/DataTransferObjects/"
        }
    }
}
```

```bash
composer dump-autoload
```

## 5. Read a story

```php
use App\DataTransferObjects\PageTransfer;
use Tlab\StoryblokTransfers\Content\StoryblokContent;

$storyblok = StoryblokContent::fromEnvironment();

$story = $storyblok->stories()->bySlug('home');

if ($story === null) {
    // No story at that slug: render your 404 page.
}

$page = $story->getContent();

if ($page instanceof PageTransfer) {
    echo $page->getTitle();
}
```

`bySlug()` looks at the story's component and picks the matching class for you,
so the same call works for any page type. `$story` also carries what Storyblok
knows about the story itself: `getFullSlug()`, `getName()`, `getPublishedAt()`
and so on.

If you already know which class to expect, say so, and you get that type back
without the `instanceof` check:

```php
$story = $storyblok->stories()->bySlug('home', PageTransfer::class);

echo $story?->getContent()->getTitle();
```

## Where next

- [Reading content](reading-content.md): listings, drafts, languages
- [Relations](relations.md): fields that point at other stories
- [Generating transfers](generating-transfers.md): custom paths and namespaces,
  and when to regenerate
