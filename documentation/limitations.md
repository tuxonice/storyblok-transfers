# Limitations

Known rough edges, and what to do about each.

## `bloks` hold more than their declared type says

`bloks` properties are declared as a list of `BlokTransfer`, but the hydrator
fills them with the **concrete** class for each blok (`HeroTransfer`,
`TeaserTransfer`, …), and with raw arrays for components that have no generated
class. PHP only enforces `array`, so this is safe at runtime, but static
analysis of your code will believe the narrower type. Check each item:

```php
foreach ($page->getBody() as $blok) {
    if (!$blok instanceof TeaserTransfer) {
        continue;
    }

    echo $blok->getHeadline();
}
```

Declaring the real type would mean rewriting every generated class to extend
`BlokTransfer`, which was judged too invasive for the benefit.

## Fields whose names cannot round-trip are skipped

A generated property's name is rebuilt from the Storyblok field name by
camel-casing it, and only a narrow set of names survives that in both
directions. Fields that don't are **left out of the class and reported** in the
generator's warnings:

- digits anywhere in the name (`headline_2`, `image_1`)
- consecutive capitals in the name itself (`CTA`)

Rename the field in Storyblok (for example `headline_two`) to have it generated.

## The output directory is cleared on every run

Every `*Transfer.php` and `*TransferImmutable.php` file in the output directory
is deleted before the new classes are written. Point the generator at a
directory that holds nothing else.

## Stale definitions are not pruned

Deleting a component in Storyblok doesn't remove its definition file, so its
class keeps being generated. Delete the JSON file by hand.

## Relation resolution is one level deep

`withResolveRelations()` brings back the stories a field points at, but not the
stories *those* point at. Read the second level with a second call. See
[Relations](relations.md#what-isnt-resolved).

## Reaching a relation needs the envelope

`getRelation()` lives on the `StoryTransfer` envelope, but the uuid it needs
may sit on a blok deep inside the content. A component rendering itself on its
own therefore can't resolve its own relations. Pass the envelope down, or look
the relations up before rendering the parts.

This is the price of keeping the generated property a plain `?string` uuid: the
alternative would change every generated class.
