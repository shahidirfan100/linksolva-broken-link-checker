# LinkSolva Broken Link Checker roadmap

Updated 5 November 2026.

## Product position

LinkSolva's position is a local scanner with evidence and careful repairs. It should compete on dependable results, low hosting overhead, and clear controls; comparative performance claims require equivalent independent measurements.

## Competitor observations (5 November 2026)

These are descriptions from the plugins' own directory pages, not independently verified benchmarks:

- [Broken Link Checker by WPMU DEV](https://wordpress.org/plugins/broken-link-checker/) offers local and cloud engines. Its [documentation](https://wpmudev.com/docs/wpmu-dev-plugins/broken-link-checker/) says the cloud engine uses a WPMU DEV Hub connection and shows reports in the Hub; the local engine remains available in wp-admin.
- [Broken Link Checker by AIOSEO](https://wordpress.org/plugins/broken-link-checker-seo/) runs checks in its cloud service and requires an AIOSEO account; its free plan scans up to 250 internal links each month. The listing describes video checks, broad WordPress source coverage, scheduled checks, inline repair, and service integrations.
- [LinkRevive AI](https://wordpress.org/plugins/linkrevive-ai/) keeps routine checks on the site, marks inconclusive results unverified, repeats failed checks, and offers soft-404 review, plain-language triage, optional email, and on-demand Wayback/AI suggestions. Its listing says optional suggestions can send link context to services after an administrator action.
- [Linkmender](https://wordpress.org/plugins/linkmender/) checks links locally, uses spaced retries, supports row and bulk repair, and includes an orphan-page report. Its listing says it does not scan navigation menus or theme templates, which can make menu-linked pages appear orphaned.
- [Awlware Link Checker](https://wordpress.org/plugins/awlware-link-checker/) describes short worker slices, local resolution for eligible internal links, paced per-host requests, connection address pinning, and WP-CLI. It is a useful design reference, not a comparably established competitor; its directory lists fewer than 10 active installations.
- [Link Invader](https://wordpress.org/plugins/link-invader/) describes bounded work, per-host throttling, local internal-link resolution and a WP-CLI runner. Evaluate each technique against LinkSolva's own correctness and resource measurements.

## Differentiation to prioritize

- Keep the local-only data path and evidence trail explicit. LinkSolva can stand apart from cloud-first tools without requiring an account, while its status model avoids calling blocked, temporary, rate-limited, or inconclusive results broken.
- Keep every repair reversible and source-aware. Retain the complete before/after record and conflict check, and make read-only sources visibly non-editable.
- Add a soft-404 suspicion signal only as a separate review state. Never promote a guessed page-content result to confirmed broken without HTTP evidence.
- Add `Retry-After` support and host-aware pacing before adding broader automatic monitoring. This addresses remote rate limits while keeping the worker queue bounded.
- Expand read-only source adapters for common block and builder data, then add video-link checks with platform-specific evidence. Prioritize based on support demand and test each adapter against safe repair boundaries.
- Consider WP-CLI scan and status commands for agency workflows after the interrupted-scan and history-retention work is complete.
- Treat orphan detection as a later, clearly scoped tool. Account for menu, template, and builder sources before presenting a page as orphaned.

Competitor notes summarize the vendors' current plugin pages and documentation. They are not independent tests or performance comparisons.

## Current candidate: 1.0.22

- Current changes give a fresh worker more time for a request, bound retries, load dashboard data concurrently, count source locations for the visible page, and show evidence and repair progress. Expired REST nonces can be refreshed for signed-in administrators; an expired login still requires signing in.
- Bracketed IPv6 literals are normalized before request-safety classification; the guard now reports blocked IPv6 destinations as blocked addresses instead of DNS failures.
- GitHub tests cover WordPress Plugin Check, PHP compatibility, a 2,000-URL synthetic fixture, browser evidence and unlink flows, permission handling, and conflict-aware undo. The synthetic 2,000-URL scan does not send network requests. Results are not measurements of customer hosting or competitor performance.
- The browser workflow captures real plugin screens with labelled sample findings and performs a five-URL HTTP scan against a local fixture: healthy, 404, redirect, two-second response and HEAD-to-GET fallback. On the 30 October GitHub runner, one run took 2.16 seconds; PHP web-server processes peaked at 375.7 MiB summed RSS and 10% summed `ps` lifetime-average CPU. This includes the server and workers, double-counts shared memory, and is not a per-request or shared-host benchmark. Expand the workload and record idle baselines before drawing performance conclusions.
- Do not treat unverified URLs as healthy or confirmed broken, or replace site-level HTTP evidence with a database lookup unless the meaning and limitations of the local result are clear.

## MVP definition

The MVP is a local audit workflow that:

1. Finds links and images in supported WordPress sources and groups repeated URLs.
2. Shows the response evidence and every source location.
3. Separates confirmed 404/410 results from blocked, temporary, and inconclusive responses.
4. Uses bounded work, safe request targets, and repair actions with conflict-aware undo.
5. Supports manual and scheduled scans without sending site content to a developer service.
6. Passes the official Plugin Check suite and PHP syntax checks at the declared minimum PHP version.

The current release already provides much of this baseline: local scans, posts and public post types, menus, featured images, optional comments and scalar metadata, bounded workers, SSRF checks, response history, CSV export, and audited repairs.

## Release 1.0.11

- Reduce browser polling frequency during a live scan.
- Make the evidence drawer usable with Escape and keyboard focus control.
- Correct copy that implied one HTTP request per URL or called the current concurrency value a measured safe default.
- Add GitHub Actions checks for PHP 7.4 and 8.4 syntax, version consistency, and the official WordPress Plugin Check Action.
- Keep the LinkSolva name, palette, and existing dashboard structure.

## Release 1.0.12

- Finish scans when URLs cannot be checked within the worker time budget, and report the skipped count for manual review.
- Adjust worker duration, batch size, and parallel checks to PHP execution-time and memory headroom.
- Keep CPU-quota behavior conservative because shared hosts do not expose a reliable per-account CPU signal.

## Release 1.0.15 candidate

- Check discovered URLs between source batches and show progress against URLs found so far, noting that the percentage can change as new URLs are discovered.
- Keep scans resumable when cron is delayed; do not treat inactivity alone as a failed URL.
- Stop a new scan when the previous completed report cannot be saved and report database failures instead of assuming an empty queue.
- Add a disposable WordPress/MySQL integration test for a completed scan and failed history save.
- Release blockers: test the candidate on WordPress, handle interrupted workers with targeted recovery, and bound history snapshots for large sites. Benchmark resource use before performance claims.

## Release 1.0.18 candidate review

- Report database write failures during discovery instead of silently dropping URLs or source locations.
- PRs #2, #3, and #4 are stacked; the newest branch includes the earlier changes. Validate the complete tree rather than publishing any earlier branch independently.
- Release blockers remain: recovery after repeated hard PHP termination, bounded and resumable history snapshots, browser-level refresh and live-update coverage, repair and undo smoke tests, and large-site performance measurements with real HTTP traffic.
- Security review must assess DNS rebinding between address validation and the subsequent network connection, including the parallel Requests path and redirect hops.

## Priority work for the next product release

### P0: preserve trust during long scans

- Stage scan results and promote them only after a scan completes. A failed, cancelled, or interrupted scan should leave the previous completed report available. The current scanner resets the visible occurrence view when a new scan starts, so this needs a data-model change and migration plan.
- Add host-aware request limits, honor `Retry-After`, and persist spaced retry schedules for 429, 5xx, timeouts, and connection failures. Test rate limiting, cancellation, cron delays, and host fairness; do not introduce an unbounded queue.
- Measure CPU, process memory, database growth, request counts, dashboard latency, and worker duration with real HTTP traffic on small and large fixture sites. Record idle baseline and account for shared-memory reporting; compare default and low-resource concurrency profiles before changing defaults.
- Resolve eligible same-site post and media existence locally when semantics are unambiguous. Preserve HTTP checks for redirects, access restrictions, status, cached pages, and rendered behavior; never equate an existing database record with a verified HTTP 200.

### P1: close source-coverage gaps

- Add excerpts and additional common sources such as widgets, block navigation, synced patterns, and site-editor templates.
- Audit and test existing `srcset`, video, and audio extraction; add iframe coverage where stored content can be parsed safely.
- Add popular page-builder adapters as read-only first. Enable editing only after a source-specific save and undo path exists.
- Keep source-type labels and editability visible so users know which findings can be changed in place.

### P1: improve triage and operations

- Add WP-CLI commands for scan, status, report, and locating occurrences of a URL.
- Add a soft-404 suspicion state. It must remain a review signal and must never be reported as a confirmed broken link without stronger evidence.
- Require spaced repeat failures before changing a temporary or refused request into a broken result.
- Group findings by host and show the number of locations a repair would affect.

### P2: optional monitoring tools

- Evaluate an opt-in 404 request log as a separate feature. Keep it disabled by default, privacy documented, bounded in storage, and separate from link-check results.
- Add editor-side warnings only after the background scan and report remain stable on large sites.

## Release gates

- Apply Antislop during implementation and review for every change to the dashboard design, plugin code, or user-facing copy. Check interface structure, accessibility and responsive states; code and comments for unnecessary boilerplate or redundant explanations; and copy for generic phrasing, unsupported claims, and unclear actions. Preserve the LinkSolva name and brand colors.
- Plugin Check has no unreviewed findings; exceptions need a code-level reason and a narrow suppression.
- PHP syntax passes on PHP 7.4 and a current PHP release.
- Runtime smoke checks cover activation, deactivation, scan start and cancellation, URL status classes, redirect safety, settings sanitization, repair, and undo with `WP_DEBUG` enabled.
- Performance checks record peak memory, elapsed worker time, query count, and HTTP requests for representative small and large sites.
- WordPress.org metadata stays synchronized, and each release includes a user-facing changelog entry.
