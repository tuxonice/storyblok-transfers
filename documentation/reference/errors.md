# Errors

What each call throws, and what it does instead of throwing.

All the package's exceptions extend `RuntimeException`. Messages never contain
your delivery token, so they're safe to log.

## Reading content

### `bySlug()` and `byUuid()`

| Situation | Result |
|---|---|
| No story with that slug or uuid | returns `null` |
| Empty slug (`''`, `'/'`) or empty uuid | returns `null`, without a request |
| The story's component has no generated class | `UnresolvableComponentException` |
| You passed a class and the story is a different component | `UnexpectedComponentException` |
| Bad token (401), rate limit (429), Storyblok error (5xx) | `StoryblokApiException` |
| Network failure, or a response that isn't valid JSON or isn't a story | `StoryblokApiException` |

### `findBy()`

| Situation | Result |
|---|---|
| Nothing matches | an empty list |
| A story's component has no generated class | that story is left out and listed in `getSkipped()` |
| You passed a class and a story is a different component | `UnexpectedComponentException` |
| API, network or response errors | `StoryblokApiException`, as above |

### `links()->all()` and `datasources()->entries()`

| Situation | Result |
|---|---|
| A link or entry Storyblok sent is incomplete | that item is left out |
| The datasource doesn't exist | `ResourceNotFoundException` |
| API, network or response errors | `StoryblokApiException` |

### Setting up `StoryblokContent`

| Situation | Result |
|---|---|
| `STORYBLOK_DELIVERY_TOKEN` not set (`fromEnvironment()`) | `RuntimeException` |
| `STORYBLOK_DEFAULT_VERSION` is not `published` or `draft` | `RuntimeException` |
| Base URI doesn't end in `/v2/` | `InvalidArgumentException` |

These happen as soon as `StoryblokContent` is built, so a misconfigured
deployment fails at startup rather than on the first page view.

## Generating classes

| Situation | Result |
|---|---|
| Bad token, wrong space ID, wrong region, or another Management API error | `StoryblokApiException` |
| A field that can't be generated | left out, and reported in `GenerationResult::$warnings` |
| An output directory that can't be created, or a file that can't be written | `RuntimeException` |

`vendor/bin/generate` prints the error and exits with status `1`.

## Hydrating content yourself

| Situation | Result |
|---|---|
| A blok whose component has no generated class | stays a raw array |
| An empty asset or link | `null` |
| The target class doesn't exist or isn't a transfer | `HydrationException` |

## Where the classes live

| Exception | Namespace |
|---|---|
| `StoryblokApiException`, `ResourceNotFoundException` | `Tlab\StoryblokTransfers\Client` |
| `UnresolvableComponentException`, `UnexpectedComponentException` | `Tlab\StoryblokTransfers\Content` |
| `HydrationException` | `Tlab\StoryblokTransfers\Hydration` |
