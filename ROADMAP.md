# LinkSolva Broken Link Checker roadmap

Updated 29 October 2026.

## Product position

LinkSolva's useful distinction is that link discovery, evidence, and repairs stay inside the customer's WordPress site. The WordPress.org directory currently lists Broken Link Checker by AIOSEO with 200,000+ active installations and Link Checker with 4,000+ active installations. AIOSEO's directory description says its free tier checks up to 250 internal links each month and runs scanning as a cloud service. These figures and plan details are a dated directory snapshot, not a forecast.

The product should compete on local control, useful evidence, careful treatment of uncertain failures, broad source coverage, and safe repairs. It should not claim to be faster or safer without measurements.

Current references:

- [Broken Link Checker directory tag](https://wordpress.org/plugins/tags/broken-link-checker/)
- [Broken Link Checker by AIOSEO](https://wordpress.org/plugins/broken-link-checker-seo/)
- [Link Checker](https://wordpress.org/plugins/link-checker/)
- [Awlware Link Checker](https://wordpress.org/plugins/awlware-link-checker/), a useful local-scanner feature benchmark for WP-CLI, source coverage, host pacing, and retry behavior.

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

## Priority work for the next product release

### P0: preserve trust during long scans

- Stage scan results and promote them only after a scan completes. A failed, cancelled, or interrupted scan should leave the previous completed report available. The current scanner resets the visible occurrence view when a new scan starts, so this needs a data-model change and migration plan.
- Add host-aware request limits and honor `Retry-After`. Space retries for 429, 5xx, timeouts, and connection failures so several URLs on one remote host do not create a burst.
- Measure memory, database growth, request counts, and worker duration on small and large fixture sites before changing defaults.
- Verify same-site links through local WordPress data when possible, while retaining HTTP checks where status, redirects, or rendered behavior require them.

### P1: close source-coverage gaps

- Add excerpts and additional common sources such as widgets, block navigation, synced patterns, and site-editor templates.
- Inspect `srcset`, iframe, video, and audio URLs where the stored source can be parsed safely.
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
