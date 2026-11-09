# Configuration

The package reads its settings with `getenv()`. It doesn't load `.env` files,
so use whatever your app or framework already does for that.

## Environment variables

### Generating classes

Read by `vendor/bin/generate`.

| Variable | Required | Default | Purpose |
|---|---|---|---|
| `STORYBLOK_SPACE_ID` | yes | | Your space ID (Settings → General) |
| `STORYBLOK_MANAGEMENT_TOKEN` | yes | | A personal access token (My Account → Personal access tokens) |
| `STORYBLOK_DEFINITIONS_PATH` | no | `./src/dto-definitions` | Where the JSON definitions are written |
| `STORYBLOK_OUTPUT_PATH` | no | `./src/DataTransferObjects` | Where the PHP classes are written. **Cleared on every run** |
| `STORYBLOK_NAMESPACE` | no | `App\DataTransferObjects` | Namespace of the generated classes |
| `STORYBLOK_AUTH_SCHEME` | no | empty | `Bearer` for tokens issued through Storyblok's OAuth flow |

### Reading content

Read by `StoryblokContent::fromEnvironment()`.

| Variable | Required | Default | Purpose |
|---|---|---|---|
| `STORYBLOK_DELIVERY_TOKEN` | yes | | A preview or public token (your space → Settings → Access Tokens) |
| `STORYBLOK_NAMESPACE` | no | `App\DataTransferObjects` | Where to find the generated classes. Use the same value as for generating |
| `STORYBLOK_CONTENT_BASE_URI` | no | `https://api.storyblok.com/v2/` | Your region's endpoint; see below |
| `STORYBLOK_DEFAULT_VERSION` | no | `published` | `published` or `draft`. Any other value throws |

`STORYBLOK_DEFAULT_VERSION` ignores case and surrounding spaces, so `Draft`
works. A typo such as `drafts` throws when `fromEnvironment()` runs, rather than
quietly showing published content in your preview environment.

The two tokens are different and not interchangeable. The management token can
change your space, so keep it to the machines that generate classes; your
running app only needs the delivery token.

## Regions

Each Storyblok region has its own endpoint. Set `STORYBLOK_CONTENT_BASE_URI`
(or pass `baseUri:` to `StoryblokContent::create()`) for a space outside the EU:

| Region | `STORYBLOK_CONTENT_BASE_URI` |
|---|---|
| EU (default) | `https://api.storyblok.com/v2/` |
| US | `https://api-us.storyblok.com/v2/` |
| Canada | `https://api-ca.storyblok.com/v2/` |
| Australia | `https://api-ap.storyblok.com/v2/` |
| China | `https://app.storyblokchina.cn/v2/` |

The address must end in `/v2/` (or `/v2`). Anything else is rejected when the
client is created: with a wrong path, Storyblok answers every request as if the
story didn't exist, so every page would quietly become a 404.

Generating classes works for EU spaces only for now. See
[Limitations](../limitations.md#generation-only-works-for-eu-spaces).
