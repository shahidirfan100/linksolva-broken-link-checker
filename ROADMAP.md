# LinkSolva reliability and scale roadmap

Updated 15 November 2026.

## Goal

Make scans finish predictably, preserve trustworthy evidence, and stay within shared-host limits. LinkSolva should compete as a local, privacy-preserving checker. Do not claim it is faster than another product until the same workload has been run on equivalent environments.

## Current state

- The WordPress.org release is 1.0.23. PR #9 is the superseding 1.0.24 release candidate; PR #8 has been superseded and is not the release source.
- PR #9 carries forward DNS-pinned cURL checks, fail-closed proxy handling, occurrence-specific repairs, and cursor-based postmeta traversal.
- The current candidate uses atomic URL and occurrence upserts, refreshes admin findings less often, adapts its polling delay, schedules short WP-Cron continuations, and reports when pinned transport is unavailable. Large-site discovery now loads post objects in batches instead of fetching each post again by ID. The latest 30,000-source run used about 54% fewer database queries than the earlier candidate, though its no-network runtime remains about 54 seconds.
- The plugin already has bounded workers, resumable discovery, URL-level evidence, scan history, source-aware repair/undo, and CI checks for PHP 7.4/8.4, Plugin Check, WordPress integration, and browser behavior.
- WP-Cron is traffic-driven. Shorter continuation events reduce idle time on active sites, but a system cron is still needed for dependable work on low-traffic sites.

## Baseline and measurement rules

The pre-candidate reference run recorded:

| Fixture | Reference |
|---|---|
| 2,000 unique URLs | 201 worker steps, 8.33 seconds, 12,737 database queries, 55.3 MiB peak memory |
| 30,000 source records with 3 unique URLs | 152 worker steps, 31.17 seconds, 44,359 database queries, 53.3 MiB peak memory |
| Findings list | 2,000 URLs in the dataset, 20 rows returned, 0.004 seconds, 4 queries |

The previous 1.0.24 candidate run (#43613510567) and the updated candidate run (#43721633515, code commit `d56a25b`) recorded:

| Fixture | Earlier candidate | Updated candidate |
|---|---|---|
| 2,000 unique URLs | 201 steps, 10.33 seconds, 8,740 queries, 55.3 MiB | 201 steps, 8.11 seconds, 8,579 queries, 55.3 MiB |
| 30,000 source records with 3 unique URLs | 152 steps, 57.42 seconds, 44,378 queries, 53.3 MiB | 152 steps, 53.74 seconds, 20,377 queries, 53.3 MiB |
| Findings list | 2,000 URLs in the dataset, 20 rows returned, 0.006 seconds, 4 queries | 2,000 URLs in the dataset, 20 rows returned, 0.006 seconds, 4 queries |
| Cold post-source batch | Not measured | 10 posts, 5 queries |
| Long-content extraction | 1,000 links from 162,897 bytes in 0.043 seconds | 1,000 links from 162,897 bytes in 0.068 seconds |

The updated candidate reduces query count by about 33% for the 2,000-URL fixture and about 54% for the sparse 30,000-record fixture compared with the pre-candidate reference. The cold ten-post source batch uses five queries, within its regression limit. The 30,000-record run still takes 53.74 seconds with network checks disabled. The ID-to-post N+1 lookup has been removed, but the remaining cost has not been isolated. These are separate CI runs, not paired controlled benchmarks; elapsed times vary and do not establish a speed advantage. Profile the remaining source traversal and writes before setting a further optimization target. The findings-list check still covers a 20-row page, not a 2,000-row page.

All scan fixtures reject network requests. These are local synthetic CI measurements, not live-link timings, customer-host performance, or a comparison with cloud scanners. Record the exact commit, PHP version, WordPress version, database version, requests made, query count, elapsed worker time, and peak memory with every new result. The findings-list test is a 20-row page, not a 2,000-row page.

## Release candidate 1.0.24 — make scan work safe and responsive

**Status: implementation and release checks complete in PR #9. All five CI jobs passed on code commit `d56a25b`; strict Plugin Check reported no errors, PHP 7.4/8.4 checks passed, and the WordPress.org package was built and inspected. The 1.0.24 candidate cuts large sparse-scan queries by about 54% against the reference, but its roughly 54-second synthetic runtime still needs profiling and paired repeats.**

- Pin each permitted HTTP connection to the validated DNS address. Fail closed for unsupported cURL transports, proxy routes, and WordPress outbound-block policies.
- Repair only the recorded source occurrence; detect source edits before changing or undoing content.
- Use atomic URL and occurrence writes to remove the read-before-write database query for each discovered item. Preserve duplicate handling, ignored resources, and scan-resume behavior.
- Show progress from the worker response. Refresh the findings and dashboard at phase changes, every ten steps, and completion instead of after every step.
- Set a minimum 250 ms admin pause and scale the pause to the observed worker response time, capped at 1.2 seconds. Keep slower backoff when progress stalls.
- Schedule one bounded WP-Cron continuation about every five seconds while a scan remains active, retaining the one-minute event as a fallback.
- Display a clear admin warning when the pinned cURL transport is unavailable; affected URLs must remain unverified.
- Continue the bounded postmeta cursor migration and restart active postmeta scans safely.
- Load post objects in the same batched WordPress query used for source discovery; avoid a second `get_post()` lookup per source record.

### Release gates

- PHP 7.4 and 8.4 syntax and release metadata pass.
- Strict WordPress Plugin Check passes.
- Integration coverage confirms one resource per unique URL, every source occurrence retained once, ignored resources remain ignored, and existing results reset only when a new scan begins.
- Repair, undo, DNS pinning, redirect, proxy, and outbound-policy checks pass.
- CI reruns both scan fixtures and compares query counts, elapsed time, and memory with the baseline above. The latest run is useful query-count evidence, but it is not a paired before/after timing run.
- Browser checks confirm progress updates without a dashboard/list request after each step, final results refresh at completion, and the cURL warning is visible.
- All scan errors remain visible and recoverable; no performance claim is published from a no-network fixture.

## Phase 1 — prove performance on realistic sites

Do this before adding more scan features.

1. Add deterministic end-to-end browser runs for a small site, 2,000 unique URLs, repeated links across many sources, and 30,000 job records with sparse links.
2. Exercise healthy, missing, redirecting, slow, rate-limited, and unreachable targets through a controlled HTTP fixture.
3. Report request count, database queries, per-step duration, total wall time, peak memory, and completion state. Separate discovery, HTTP verification, report refresh, and history snapshot timings.
4. Run the same fixtures against MySQL and supported MariaDB versions. Inspect query plans before adding indexes.
5. Profile resource writes and source traversal after the atomic upsert change. Batch or cache further only when the measurements show a remaining bottleneck and duplicate/restart behavior stays correct.
6. Test low PHP memory and execution-time limits. Workers must stop within the budget and resume without losing source locations or treating untested URLs as healthy.
7. Add per-stage timers and query counts for source selection, permalink resolution, content extraction, URL normalization/deduplication, resource writes, occurrence writes, and verification. Repeat paired runs on the same runner and database; prioritize the 30,000-source / 3-URL case because the latest no-network run still takes 53.74 seconds and makes 20,377 queries after the post-object batching fix.

**Acceptance:** every scan completes or ends with a specific recoverable error; performance reports are repeatable on the same runner; correctness counts match the fixtures; query and memory changes are measured against the documented baseline; the remaining 30,000-source cost is attributed to a measured stage and a target is documented before further optimization.

## Phase 2 — dependable background operation

1. Add WP-CLI commands to start, advance, inspect, cancel, and export a scan so hosting cron can run it without an open admin page.
2. Add a health/readiness panel for cURL transport, proxy routing, WP-Cron activity, database write failures, and the last worker timestamp.
3. Preserve the last completed report while a new scan runs. Stage new results and promote them only after a completed scan; include a migration and rollback plan before changing the live result tables.
4. Recover stale worker locks and interrupted history snapshots with targeted cleanup. Never discard the only completed report during recovery.
5. Document system cron setup and verify both standard traffic-driven WP-Cron and low-traffic deployments.

**Acceptance:** a scan can finish after the initiating browser closes, a stopped scan can resume, and an administrator can distinguish delayed cron from a stalled worker or unavailable transport.

## Phase 3 — safer network behavior

1. Add per-host concurrency limits.
2. Parse and honor `Retry-After` for HTTP 429 and supported transient responses.
3. Add persisted, spaced retries for timeouts and temporary errors. Do not repeatedly request a host that is rate-limiting the site.
4. Consider internal-link resolution through WordPress as a separate evidence class. A database match is not proof that the public HTTP route returns the same result.
5. Keep soft-404 signals in a review state. Require repeat evidence before changing a confirmed status.

**Acceptance:** a single host cannot consume the whole scan budget; temporary and rate-limited results remain distinct from confirmed 404/410 results.

## Phase 4 — broaden coverage without unsafe edits

1. Add read-only adapters for widgets, synced patterns, site-editor templates, and widely used builders based on support demand.
2. Add source-specific repair support only when exact occurrence location, conflict detection, and undo are covered.
3. Add iframe and platform-specific video checks with evidence appropriate to each provider.
4. Add orphan-page reports only after menu, template, and builder coverage is broad enough to avoid false orphan results.
5. Consider optional Wayback or AI suggestions only after a clear administrator action and an explicit disclosure of what content leaves the site.

## Definition of a reliable release

A release is ready when installation and upgrade preserve existing data; scans complete or report why they stopped; every finding has clear HTTP or local-resolution evidence; retries and resume do not duplicate locations; repairs are occurrence-specific and undoable; and tests cover the declared PHP/WordPress/database support range. Feature count and vendor speed claims are not release criteria.
