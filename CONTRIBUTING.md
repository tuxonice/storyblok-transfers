# Contributing

Notes for working on the package itself. To use it in your app, see the
[documentation](documentation/README.md).

## Setup

The repository ships a Docker environment, so you don't need PHP installed
locally. Run everything through it.

```bash
cp .env.example .env   # fill in a test space
make build             # build the image
make install           # composer install
```

## Checks

```bash
make test              # phpunit
make test-coverage     # phpunit with Xdebug coverage
make stan              # phpstan, level 8
make cs                # phpcs, PSR-12
make shell             # a shell in the container
```

All three of `test`, `stan` and `cs` must pass before a change is merged.

## Smoke test against a real space

The unit and integration tests run against recorded responses. To check the
whole path against a real Storyblok space, generating classes and then reading a
story into them:

```bash
docker compose run --rm php php tools/smoke-test.php [story-slug]
```

It reads the same variables as the package (see below) and writes its output to
`tools/.output`, which is git-ignored. It only ever reads from the space. Run it
with `--help` for its full usage, including how to exercise relations.

## Environment

`.env` holds the package's own variables (see
[Configuration](documentation/reference/configuration.md)) plus these, which only
matter for the Docker setup:

| Variable | Default | Purpose |
|---|---|---|
| `XDEBUG_MODE` | `off` | `off`, `coverage`, `debug`, `develop`, `trace` |
| `DOCKER_UID` | `1001` | Owner of files the container writes |
| `DOCKER_GID` | `1001` | Group of files the container writes |

The Makefile sets `DOCKER_UID` and `DOCKER_GID` from `id -u` and `id -g`, so you
only need them when calling `docker compose` directly.

Docker Compose loads `.env` on its own. Anything already exported in your shell
wins over the file.

## Design notes

Design documents and implementation plans for past work live in
`docs/superpowers/`. They explain why things are the way they are, and are worth
reading before changing the generator, the hydrator or the content layer.
