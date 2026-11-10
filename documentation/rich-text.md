# Rich text

A `richtext` field comes back as a `RichtextTransfer`. It holds Storyblok's
document exactly as it was sent, plus the components your editors inserted into
it, turned into your generated classes.

```php
$body = $page->getBody();   // RichtextTransfer, or null when the field is empty

$body->getType();      // 'doc'
$body->getAttrs();     // document-level attributes, often []
$body->getContent();   // the document's nodes, plain arrays as Storyblok sent them
$body->getBloks();     // the inserted components, by blok id
$body->toDocument();   // the whole document, ready for a renderer
```

## Rendering

The package doesn't render rich text. Hand `toDocument()` to a renderer: it
returns the document in the shape renderers expect.

With Storyblok's PHP renderer,
[`storyblok/richtext-resolver`](https://github.com/storyblok/storyblok-php-richtext-renderer):

```bash
composer require storyblok/richtext-resolver
```

```php
use Storyblok\RichtextRender\Resolver;

echo (new Resolver())->render($body->toDocument());
```

That renders paragraphs, headings, lists, links and so on. It leaves out any
components inserted into the text: the renderer has no idea how to draw them.

## Components inside rich text

When an editor inserts a component into rich text, Storyblok stores it as a
`blok` node with an id. The package turns each of those components into your
generated class and makes them available through `getBloks()`, keyed by that id:

```php
foreach ($body->getBloks() as $id => $components) {
    foreach ($components as $component) {
        if ($component instanceof TeaserTransfer) {
            echo $component->getHeadline();
        }
    }
}
```

A component whose class you haven't generated yet stays a raw array, the same as
in a [`bloks` field](hydration.md#bloks-are-your-concrete-classes), so check the
type as you go.

### Rendering them with the rest of the text

Tell the renderer how to draw a `blok` node, and look its components up in
`getBloks()` by the node's id:

```php
use Storyblok\RichtextRender\Resolver;
use Storyblok\RichtextRender\Schema;

$schema = new Schema();

$resolver = new Resolver([
    'marks' => $schema->getMarks(),
    // Passing 'nodes' replaces the renderer's defaults instead of adding to
    // them, so merge the defaults back in, or paragraphs stop rendering too.
    'nodes' => $schema->getNodes() + [
        'blok' => fn (array $node): array => [
            'html' => $twig->render('bloks.html.twig', [
                'bloks' => $body->getBloks()[$node['attrs']['id'] ?? ''] ?? [],
            ]),
        ],
    ],
]);

echo $resolver->render($body->toDocument());
```

Use whatever you render templates with in place of `$twig`.

### Why the components sit beside the text

The nodes in `getContent()` are left exactly as Storyblok sent them, components
included, as plain arrays. Renderers walk those arrays and would break on
objects in their place, so the hydrated components live in `getBloks()` instead,
where the renderer can ask for them by id.

A component inserted into rich text can have a rich text field of its own. That
field becomes its own `RichtextTransfer`, with its own `getBloks()`; each
document holds only the components inserted directly into it.

## Things to know

- **No document means `null`.** If the field holds something that isn't an
  array, such as an empty string, `getBody()` returns `null`. An empty array
  gives a `RichtextTransfer` with no content.
- **A blok node without an id is skipped.** Its components can't be looked up by
  id, so they don't appear in `getBloks()`. Storyblok always sends an id, so this
  only matters for hand-written content.
- **`toArray(true)` scrambles the document.** A deep `toArray(true)` drops the
  keys of `attrs` (`['lang' => 'en']` comes out as `['en']`) and of the blok map,
  and empties the components. Use `toDocument()` for the document and
  `getBloks()` for the components, or the plain `toArray()`, which keeps both.
