# Hydrating content yourself

[`StoryblokContent`](reading-content.md) fetches and hydrates in one step, and
it's what most apps should use. Use `StoryblokHydrator` directly when you
already have a content array from somewhere else, such as a webhook payload,
the Storyblok Visual Editor's bridge, or your own HTTP client.

```php
use Tlab\StoryblokTransfers\Hydration\StoryblokHydrator;

$hydrator = new StoryblokHydrator('App\\DataTransferObjects');

$page = $hydrator->hydrate(PageTransfer::class, $story['content']);
```

Pass the story's `content` object, not the whole story.

## Why not `fromArray()`?

The generated classes have a `fromArray()` from `tuxonice/transfer-objects`, but
it hands each value straight to the setter. An asset arrives as an array where
the setter expects an `AssetTransfer`, and PHP throws:

```php
// TypeError
PageTransfer::fromArray(['image' => ['id' => 1, 'filename' => 'a.jpg']]);
```

The hydrator converts the nested values first.

| Field | Hydrated into |
|---|---|
| `asset` | `AssetTransfer` |
| `multiasset` | a list of `AssetTransfer` |
| `link`, `multilink` | `LinkTransfer` |
| `bloks` | the generated class for each blok, matched on its `component` |
| everything else | passed through as it is |

Bloks inside bloks work at any depth. An empty asset or link, which Storyblok
sends as `""` or as an object full of `null`s, becomes `null`.

## Bloks are your concrete classes

A `bloks` field is declared as a list of `BlokTransfer`, but the hydrator fills
it with the generated class for each blok, such as `HeroTransfer` or
`TeaserTransfer`. Check the type as you go:

```php
foreach ($page->getBody() as $blok) {
    if ($blok instanceof HeroTransfer) {
        // render the hero
    } elseif ($blok instanceof TeaserTransfer) {
        // render the teaser
    }
}
```

A blok whose component has **no generated class** stays a raw array rather than
throwing, so an editor adding a new component in Storyblok can't break your
page. Regenerate to pick it up. Until then, skip anything that isn't an object:

```php
foreach ($page->getBody() as $blok) {
    if (!is_object($blok)) {
        continue;   // a component you haven't generated yet
    }

    // …
}
```

See [Limitations](limitations.md#bloks-hold-more-than-their-declared-type-says)
for why the declared type is narrower than what's inside.

## Errors

`hydrate()` throws `HydrationException` only for a programming mistake: a class
that doesn't exist, or one that isn't a transfer. Content never makes it throw.
