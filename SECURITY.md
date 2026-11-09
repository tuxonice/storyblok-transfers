# Security policy

## Reporting a vulnerability

Please **don't open a public issue** for a security problem.

Report it privately through GitHub instead:
[report a vulnerability](https://github.com/tuxonice/storyblok-transfers/security/advisories/new).
Only the maintainer can see the report.

Include what you found, how to reproduce it, and which version you used. You'll
get an answer within a few days, and you'll be credited in the fix unless you'd
rather not be.

## What counts

This package handles two Storyblok tokens: a Management API token, which can
change your space, and a Content Delivery API token. Anything that could leak
either of them is in scope, for example a token appearing in an exception
message, a log line or a cache key.

Bugs in Storyblok's own APIs belong with Storyblok.

## Supported versions

Security fixes go into the latest release.
