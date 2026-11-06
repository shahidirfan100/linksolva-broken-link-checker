# LinkSolva Broken Link Checker roadmap

Updated 6 November 2026.

## Implementation status (6 November 2026)

Status checked against `main` at 1.0.23 and open PR [#8](https://github.com/shahidirfan100/linksolva-broken-link-checker/pull/8), head `d66b6ff`. PR #8 is still open and unmerged; its 1.0.24 changes are a candidate, not the WordPress.org release.

### Applied on `main` in 1.0.23

- Extraction reuses the stripped text and context once per source instead of repeating that work for every URL.
- The configured-site SSRF exception is limited to the exact host, scheme, and effective port.
- Worker duration, batch size, and parallel checks are bounded by PHP execution time and available memory. The plugin does not claim to measure shared-host CPU quotas.
- Scan history, source-aware repairs, and conflict-aware undo are part of the current release.

### Implemented in candidate 1.0.24 (PR #8)

- cURL requests are pinned to validated DNS addresses, including one redirect hop at a time. Requests fail closed when WordPress or environment proxy settings would bypass the pin.
- WordPress outbound-blocking and `pre_http_request` policies are honored by both parallel and single-request paths.
- Repair hashes normalize IDs consistently; repairs verify the recorded source occurrence, preserve menu fields, and detect source edits made during replacement verification.
- Metadata values are fetched in bounded slices using a `meta_id` cursor, and sources with no URLs skip checksum work. This does not yet remove offset traversal from every source provider.
- Version metadata and changelog are synchronized at 1.0.24. PR #8's PHP 7.4/8.4, Plugin Check, WordPress integration, performance, and browser jobs passed on 6 November 2026.

### Still planned or only partly covered

- Keep the last completed report visible while a new scan runs or fails. History snapshots exist, but a new scan still rebuilds the live result set; staging and promotion need a separate design and migration plan.
- Profile per-URL database work before adding hash caches or duplicate-safe bulk writes. The 2,000-URL fixture currently measures a findings page with 2,000 rows, but there is no dense repeated-URL fixture or large browser dashboard fixture with a before/after comparison.
- Extend cursor traversal beyond metadata rows. Posts, comments, menus, and the post-ID pages used by metadata scanning still use page/offset traversal in places; replace those paths where profiling shows a benefit.
- Add per-host concurrency limits and parse `Retry-After`. Current 429 and server-error retries use a fixed delay; the worker does not pace requests by host.
- Keep adaptive sizing scoped: memory and PHP-time limits already reduce work, but database latency and site response health do not drive backoff, and there is no explicit low-resource mode.
- Add soft-404 suspicion and repeat-failure evidence as review signals, plus WP-CLI scan/status/report commands.
- Expand source coverage with read-only adapters for widgets, synced patterns, site-editor templates, and builders. Video/audio element URLs can be extracted, but there are no platform-specific video checks or iframe adapters.
- Add orphan-page reporting only after menu, template, and builder coverage is broad enough to avoid false orphan results.
- Repairs are source-aware and undoable; a preview for bulk repairs is not implemented.
- Describe private-site support narrowly: the configured site authority has a specific exception, while other private-network targets are blocked by the SSRF guard. Test any broader local-network workflow in an isolated fixture before advertising it.

### Current CI performance readings

On the hosted runner for PR #8, the synthetic 2,000-URL scan took 9.79 seconds total, with a maximum worker step of 0.531 seconds, 55.3 MiB peak memory, and 12,740 queries. The 30,000-source sparse scan took 62.27 seconds total, with a maximum worker step of 0.478 seconds, 53.3 MiB peak memory, and 44,390 queries. A 1,000-link, 162,897-byte article extracted in 0.082 seconds. The 2,000-row findings page took 0.006 seconds and four queries in that run. These synthetic CI results are measurements, not a before/after comparison or a promise about customer hosting; the scan fixtures reject network requests, while browser checks use a local HTTP fixture.

## Product position

LinkSolva's position is a local scanner with evidence and careful repairs. It should compete on dependable results, low hosting overhead, and clear controls; comparative performance claims require equivalent independent measurements.

## Competitor observations (5 November 2026)

These are descriptions from the plugins' own directory pages, not independently verified benchmarks:

- [Broken Link Checker by WPMU DEV](https://wordpress.org/plugins/broken-link-checker/) offers local and cloud engines. Its [documentation](https://wpmudev.com/docs/wpmu-dev-plugins/broken-link-checker/) says the cloud engine uses a WPMU DEV Hub connection and shows reports in the Hub; the local engine remains available in wp-admin.
- [Broken Link Checker by AIOSEO](https://wordpress.org/plugins/broken-link-checker-seo/) runs checks in its cloud service and requires an AIOSEO account; its free plan scans up to 250 internal links each month. The listing describes video checks, broad WordPress source coverage, scheduled checks, inline repair, and service integrations.
- [LinkRevive AI](https://wordpress.org/plugins/linkrevive-ai/) keeps routine checks on the site, marks inconclusive results unverified, repeats failed checks, and offers soft-404 review, adaptive pacing, WP-CLI, and optional Wayback/AI suggestions. Its listing claims it pins checked DNS addresses at connection time; optional suggestions can send link context to services after an administrator action.
- [Linkmender](https://wordpress.org/plugins/linkmender/) checks links locally, uses spaced retries, supports row and bulk repair, and includes an orphan-page report. Its listing says it does not scan navigation menus or theme templates, which can make menu-linked pages appear orphaned.
- [Link Invader](https://wordpress.org/plugins/link-invader/) describes budgeted slices, per-host throttling, WordPress-side internal checks, and a WP-CLI runner. Its stated ~10 ms front-end impact on a 1,000-link test is a vendor result, not an independent comparison.
- [Awlware Link Checker](https://wordpress.org/plugins/awlware-link-checker/) is another local-first design reference; its listing emphasizes the WordPress HTTP API and privacy. Treat any performance statements on product pages as vendor claims.

## Differentiation to prioritize

- Keep the local-only data path and evidence trail explicit. LinkSolva can stand apart from cloud-first tools without requiring an account, while its status model avoids calling blocked, temporary, rate-limited, or inconclusive results broken.
- Keep every repair reversible and source-aware. Retain the complete before/after record and conflict check, and make read-only sources visibly non-editable.
- Add a soft-404 suspicion signal only as a separate review state. Never promote a guessed page-content result to confirmed broken without HTTP evidence.
- Add `Retry-After` support and host-aware pacing before adding broader automatic monitoring. This addresses remote rate limits while keeping the worker queue bounded.
- Expand read-only source adapters for common block and builder data, then add video-link checks with platform-specific evidence. Prioritize based on support demand and test each adapter against safe repair boundaries.
- Consider WP-CLI scan and status commands for agency workflows after the interrupted-scan and history-retention work is complete.
- Treat orphan detection as a later, clearly scoped tool. Account for menu, template, and builder sources before presenting a page as orphaned.

Competitor notes summarize the vendors' current plugin pages and documentation. They are not independent tests or performance comparisons.

## Prior release: 1.0.22

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

## Audit findings and forward roadmap (5 November 2026)

This audit was source review plus safe, static reasoning. It did not execute plugin code or contact live link targets. Performance timings and transport-level security behavior therefore remain pending isolated CI/fixture validation.

### Immediate fixes in the current work

- **Extraction CPU:** Link context used to strip tags and normalize the entire post once for every URL found in it. That multiplied full-content work by the link count. Extraction now creates the plain-text body and compact context once per source, then reuses the context. A 163 KB article / 1,000-link benchmark is added to CI; record the runner's elapsed time and memory before making a speed claim.
- **SSRF port exception:** the configured site hostname used to exempt every port from the nonstandard-port check. The exception now matches the configured host, scheme, and effective port. Focused regression cases cover the default and configured nonstandard home ports; other ports must be blocked.
- **Security follow-up:** DNS answers are checked before the WordPress Requests transport connects, but the validated address is not pinned to the later connection in the plugin code. DNS rebinding is a potential time-of-check/time-of-use gap, not a confirmed exploit in every supported transport. Resolve it or document a transport-specific fail-closed policy before claiming SSRF protection is complete. Test only with a disposable resolver and local listener in an isolated runner.
- **Not measured yet:** every candidate currently passes through resource and occurrence database lookups/writes; large result tables also incur full counts, a status sort, and paginated listing. Source providers use offset pagination and some materialize metadata before slicing. These are plausible local scan/admin bottlenecks; measure query count, plans, memory, and worker latency before rewriting them.

### Release sequence

#### 1.0.23 — close review findings and establish a baseline

- Implemented in PR #6: reuse plain text/context during extraction, restrict the configured-site SSRF exception to its exact authority, add regression coverage, and bump release metadata.
- GitHub Actions run on 5 November 2026 passed PHP 7.4/8.4 checks, Plugin Check, WordPress integration, browser checks, and all existing regressions. On the hosted Ubuntu 26.04 / PHP 8.2.35 / MySQL 8.0.46 runner: 1,000 links in a 162,897-byte article extracted in 0.065 s; the 2,000-URL scan took 9.76 s with 12,740 DB queries and 55.3 MiB peak memory; the 30,000-source sparse scan took 60.42 s with 44,388 queries and 53.3 MiB peak memory. Synthetic scan fixtures rejected all network requests. These are repeatable CI baselines, not a before/after comparison or customer-host performance claim.
- Add a dense repeated-link fixture and a large-result dashboard fixture. Compare baseline and candidate on the same runner and PHP/WordPress versions; avoid fixed timing assertions that are flaky across hosts.
- DNS pinning was still open at the 5 November review. It is covered in candidate 1.0.24 by the cURL transport, redirect, and proxy fixtures described above; retain this gate if another transport path is introduced.

#### Next — reduce local work per discovered URL

- Preserve the latest completed report while a new scan is running or if it stops. This requires staging scan results and promoting them after successful completion, plus a database migration plan.
- Profile `upsert_resource()` and `add_occurrence()` on repeated and unique URLs. Prototype per-scan hash caching and duplicate-safe writes only if idempotence, concurrent workers, resume, and ignored-resource behavior remain correct.
- Replace provider `OFFSET` traversal with bounded keyset/cursor progress where it avoids rescanning rows. Keep each step inside the existing time and memory budgets.
- Profile dashboard polling separately from discovery. Avoid recalculating heavy counts and sorted results more often than the visible UI needs; keep scan progress live without adding queries to public page views.
- Test on low-resource and larger fixtures, including query totals and DB size, before changing defaults or publishing speed claims.

#### Following — make request behavior safer and more predictable

- Add per-host concurrency limits and persist spaced retries for 429 responses, `Retry-After`, timeouts, and transient server failures.
- Consider adaptive worker sizing that backs off when database writes, memory, worker duration, or site response time degrade; LinkRevive and Link Invader both advertise host-aware operation, so this is a meaningful local-first differentiator.
- Keep weak signals such as soft 404s in a review state, and require repeat failure evidence before upgrading temporary outcomes to broken.
- Add WP-CLI scan/status/report commands after interrupted-scan recovery and history retention are dependable.

#### Later — expand source coverage without risking edits

- Add read-only adapters for block navigation, widgets, synced patterns, and site-editor templates, then prioritize builder adapters from support demand.
- Add iframe and video checks with platform-specific evidence. Do not label a video as broken solely because its containing page returns 200 or a provider blocks the request.
- Add orphan-page reporting only after menus, templates, and supported builder sources are represented, so the report does not mislead users.

### What can make LinkSolva stand out

- Keep the full scan, evidence, and repair history local, with no account or monthly link quota; retain support for staging and private sites where externally hosted crawlers cannot reach the content.
- Pair that privacy with a conservative evidence model: show why a URL is broken, blocked, rate-limited, or unverified; never collapse these into one red “broken” bucket.
- Make shared-host behavior measurable and adjustable: bounded work, per-host pacing, visible retry timing, and a clear low-resource mode.
- Keep repairs source-aware and undoable, including a preview of affected locations for bulk actions.
- Close the biggest competitor gaps in source coverage and CLI workflow, but only after each source adapter has a safe, tested edit boundary.

Competitor feature descriptions and performance claims in this section are taken from current WordPress.org listings and are not independent product tests.

## Release gates

- Apply Antislop during implementation and review for every change to the dashboard design, plugin code, or user-facing copy. Check interface structure, accessibility and responsive states; code and comments for unnecessary boilerplate or redundant explanations; and copy for generic phrasing, unsupported claims, and unclear actions. Preserve the LinkSolva name and brand colors.
- Plugin Check has no unreviewed findings; exceptions need a code-level reason and a narrow suppression.
- PHP syntax passes on PHP 7.4 and a current PHP release.
- Runtime smoke checks cover activation, deactivation, scan start and cancellation, URL status classes, redirect safety, settings sanitization, repair, and undo with `WP_DEBUG` enabled.
- Performance checks record peak memory, elapsed worker time, query count, and HTTP requests for representative small and large sites.
- WordPress.org metadata stays synchronized, and each release includes a user-facing changelog entry.
