# Links and datasources

## The links tree

The links tree is the structure of your space (every story and folder, with
its path and position) without any content. Use it for navigation menus and
sitemaps.

```php
foreach ($storyblok->links()->all() as $link) {
    if (!$link->isFolder()) {
        echo $link->getName(), ' → ', $link->getRealPath(), PHP_EOL;
    }
}
```

Narrow it to part of the tree:

```php
$blog = $storyblok->links()->all('blog/');
```

Each `LinkEntry` has:

| Method | Notes |
|---|---|
| `getUuid()` | |
| `getId()` | numeric id; `getParentId()` refers to it |
| `getParentId()` | `null` at the root |
| `getSlug()` | full slug, e.g. `blog/hello-world` |
| `getRealPath()` | the URL path, e.g. `/blog/hello-world` |
| `getName()` | |
| `getPosition()` | order among its siblings |
| `isFolder()` | |
| `isPublished()` | |
| `isStartpage()` | the folder's start page |

Entries come back in the order Storyblok sends them, not as a nested tree. Group
them by `getParentId()` to build one.

`all()` takes a `ContentOptions` as its second argument, for drafts or a cache
version, the same as [stories](reading-content.md#drafts-languages-and-cache-versions).

## Datasources

Datasources are the key/value lists you manage under Datasources in Storyblok,
often used for select options.

```php
foreach ($storyblok->datasources()->entries('categories') as $entry) {
    echo $entry->getValue(), ' => ', $entry->getName(), PHP_EOL;
}
```

Every entry is returned. The package fetches all pages for you.

### Translated values

Datasources translate through **dimensions** rather than languages. Pass the
dimension's name:

```php
foreach ($storyblok->datasources()->entries('categories', 'de') as $entry) {
    echo $entry->getName(), ': ', $entry->getDimensionValue() ?? $entry->getName(), PHP_EOL;
}
```

`getDimensionValue()` is `null` when the entry has no translation for that
dimension, so fall back to `getName()`.

Each `DatasourceEntry` has `getName()`, `getValue()`, `getId()` and
`getDimensionValue()`.

Asking for a datasource that doesn't exist throws
`ResourceNotFoundException`. See [Errors](reference/errors.md).
