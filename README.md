# LinkSolva - Broken Link Checker

Local WordPress link verification with evidence-based classifications, URL deduplication, bounded scans, and conflict-aware repairs.

The plugin does not require a cloud account, developer service, telemetry, advertising, or link credits. It checks destinations from the WordPress server and stores findings in plugin-owned tables.

## Current version

1.0.21

## Scope

The current release covers public posts, pages, public custom post types, approved comments, custom navigation menu URLs, featured images, HTML links and images, plain-text HTTP(S) URLs, and optional read-only scalar metadata discovery.

It deliberately does not rewrite serialized page-builder structures. Direct edits are available only for standard post content, approved comments, and menu URLs, with an audit record and checksum-protected undo.

## Status model

The scanner distinguishes successful responses, redirects, definitive 404/410 failures, automated-access blocks, rate limits, temporary server errors, TLS failures, DNS failures, timeouts, and uncertainty. A non-2xx response is not automatically a broken-link finding.

## Development notes

The plugin uses WordPress HTTP APIs and the WordPress-bundled Requests library for parallel initial HEAD requests, with redirect following disabled. Each redirect target is normalized and validated before the next request. Requests are limited by timeout, redirect count, response size, and a serialized worker lock. Worker duration follows PHP's execution-time limit. Batch size and parallel checks adjust to available PHP memory, with a maximum of four concurrent URL checks. When the remaining worker budget is too short, verification waits for a fresh worker. Timeouts are retried up to the configured limit before remaining unverified. PHP execution limits can shorten the effective request timeout. PHP does not expose reliable per-account CPU quotas on all shared hosts, so the plugin uses conservative concurrency limits rather than claiming to measure CPU availability. Replacement repairs are verified before the old orphaned finding is retired, and undo restores the original resource mapping. The admin application uses plain JavaScript and scoped CSS loaded only on plugin screens.

## WordPress.org media

The banners, icons, and four screenshots in `wordpress-org-assets/` belong in the top-level `/assets` directory of the WordPress.org SVN repository, beside `/trunk`. The numbered screenshots match the four captions in `readme.txt`. These directory images are separate from the installable plugin ZIP.

## License

GPLv2 or later. See the plugin header and [GNU GPL](https://www.gnu.org/licenses/gpl-2.0.html).
