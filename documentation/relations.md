# Relations

A field that points at another story, such as a post's author, holds that
story's **uuid**, not the story. Storyblok can send the related stories along
with the response if you ask it to. This package then lets you look each one up
by its uuid.

## Asking for relations

Name the fields to resolve as `component.field`:

```php
use Tlab\StoryblokTransfers\Content\ContentOptions;

$options = (new ContentOptions())->withResolveRelations(['post.author']);

$story = $storyblok->stories()->bySlug('blog/hello-world', null, $options);
```

Listings take the same options through `StoryQuery`:

```php
$list = $storyblok->stories()->findBy(new StoryQuery(
    startsWith: 'blog/',
    options: (new ContentOptions())->withResolveRelations(['post.author']),
));
```

## Reading a relation

The generated property still holds the uuid. Pass it to `getRelation()`:

```php
use Tlab\StoryblokTransfers\Content\StoryTransfer;

$post = $story->getContent();                     // PostTransfer
$author = $story->getRelation($post->getAuthor()); // getAuthor() is the uuid

if ($author instanceof StoryTransfer) {
    printf('<a href="/%s">%s</a>', $author->getFullSlug(), $author->getName());

    $author->getContent();   // AuthorTransfer
}
```

A resolved relation is the **whole related story**, with its slug and name as
well as its content, so you can link to it.

`getRelation()` returns:

| Value | When |
|---|---|
| `StoryTransfer` | the relation was resolved |
| `null` | the field is empty, or it wasn't in `withResolveRelations()` |
| raw array | the related story's component has no generated class; it still has `full_slug` |

`getRelation()` accepts `null`, so you can pass the getter's result straight in.

### Fields that point at several stories

A multi-story field holds an array of uuids. Look each one up:

```php
foreach ($post->getRelatedPosts() as $uuid) {
    $related = $story->getRelation($uuid);

    if ($related instanceof StoryTransfer) {
        echo $related->getFullSlug(), PHP_EOL;
    }
}
```

## In listings

Every story in a listing shares one set of relations, so an author referenced
by ten posts is held once. Use either the story or the list:

```php
foreach ($list as $story) {
    $author = $story->getRelation($story->getContent()->getAuthor());
}

$list->getRelations()->get($uuid);   // the same lookup, from the list
```

## Large numbers of relations

Storyblok resolves at most 50 relations in one response and returns only the
uuids of the rest. The package fetches those for you, 50 at a time, with the
same version, language and cache version as your original read. You don't need
to do anything, but a listing with many relations makes extra requests.

## What isn't resolved

Relations are resolved **one level deep**. If an author has a `mentor` field,
`withResolveRelations(['post.author'])` brings back the author, and the author's
`mentor` field holds a uuid that `getRelation()` returns `null` for. Read the
mentor with a second call, such as `byUuid()`.

The lookup needs the `StoryTransfer` envelope, because that's where
`getRelation()` lives. A component deep inside the content, rendering itself on
its own, can't resolve its own relations. Pass the envelope down, or resolve
the relations before rendering the parts. See
[Limitations](limitations.md#reaching-a-relation-needs-the-envelope).
