# Generating transfers

The generator reads your space's component schemas from the Storyblok
Management API and turns each component into a PHP transfer class.

```
Storyblok Management API → JSON definition files → PHP transfer classes
```

The classes are built by
[`tuxonice/transfer-objects`](https://github.com/tuxonice/data-transfer-object),
so they extend its `AbstractTransfer` and come with `fromArray()` and
`toArray()`.

## From the command line

```bash
vendor/bin/generate
```

It reads its settings from the environment:

| Variable | Default |
|---|---|
| `STORYBLOK_SPACE_ID` | required |
| `STORYBLOK_MANAGEMENT_TOKEN` | required |
| `STORYBLOK_DEFINITIONS_PATH` | `./src/dto-definitions` |
| `STORYBLOK_OUTPUT_PATH` | `./src/DataTransferObjects` |
| `STORYBLOK_NAMESPACE` | `App\DataTransferObjects` |
| `STORYBLOK_AUTH_SCHEME` | empty; set `Bearer` for OAuth-issued tokens |
| `STORYBLOK_MANAGEMENT_BASE_URI` | EU; see [Regions](reference/configuration.md#regions) for the others |

Relative paths are resolved from the directory you run the command in. Both
directories are created if they don't exist.

The command also accepts positional arguments:

```bash
vendor/bin/generate <space-id> <token> [definitions-path] [output-path] [namespace]
```

Prefer the environment variables. The arguments are positional, so setting a
custom output path means putting the token on the command line too, where it
ends up in your shell history and the process list.

## From PHP

```php
use Tlab\StoryblokTransfers\StoryblokTransferGenerator;

$generator = new StoryblokTransferGenerator(
    spaceId: '290156928914344',
    token: getenv('STORYBLOK_MANAGEMENT_TOKEN'),
    definitionsPath: __DIR__ . '/src/dto-definitions',
    outputPath: __DIR__ . '/src/DataTransferObjects',
    namespace: 'App\\DataTransferObjects',
);

$result = $generator->generate();

echo 'Generated: ', implode(', ', $result->componentNames), PHP_EOL;

foreach ($result->warnings as $warning) {
    echo $warning, PHP_EOL;
}
```

| `GenerationResult` property | Meaning |
|---|---|
| `componentNames` | PascalCased names of the classes generated (`Page`, `Hero`, …) |
| `warnings` | Fields that had to be left out, each with the reason |

Pass `authorizationScheme: 'Bearer'` if your token was issued through
Storyblok's OAuth flow. Personal access tokens are sent as they are, which is
the default.

For a space outside the EU, pass your region's endpoint (see
[Regions](reference/configuration.md#regions)):

```php
$generator = new StoryblokTransferGenerator(
    // …
    managementBaseUri: 'https://api-us.storyblok.com/v1/',
);
```

## What gets written

```
src/dto-definitions/       one JSON file per component
src/DataTransferObjects/   one PHP class per component
```

**Commit the definitions.** They are a readable record of your Storyblok
schema: when someone changes a content type, the change shows up as a diff in
review before any PHP changes.

**The classes are up to you.** They can always be rebuilt from the definitions,
so you can commit them or gitignore them and generate on deploy.

> **The output directory is cleared on every run.** Every `*Transfer.php` file
> in it is deleted before the new classes are written. Give the generator a
> directory of its own.

## Naming

| In Storyblok | In PHP |
|---|---|
| Component `page` | `PageTransfer` |
| Component `nav_item` or `nav-item` | `NavItemTransfer` |
| Field `footer_text` | `getFooterText()` / `setFooterText()` |

The [field types reference](reference/field-types.md) lists what each field type
becomes.

A component with no usable fields, such as one made only of tabs, generates no
class.

## When to regenerate

Regenerate whenever a component changes in Storyblok. Until you do:

- a **new field** is ignored when content is read
- a **new component** has no class. Nested in a page, it stays a raw array;
  as a page of its own, reading it throws (see
  [Errors](reference/errors.md))

Removing a component in Storyblok does **not** remove its definition file, so
its class keeps being generated. Delete the JSON file by hand.

## Use the same namespace when reading

`StoryblokContent` finds a story's class by its component name inside the
namespace you generated into. `fromEnvironment()` reads the same
`STORYBLOK_NAMESPACE` variable, so setting it once covers both.
