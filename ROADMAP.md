# LinkSolva reliability and scale roadmap

Updated 14 November 2026.

## Goal

Make scans finish predictably, preserve trustworthy evidence, and stay within shared-host limits. LinkSolva should compete as a local, privacy-preserving checker. Do not claim it is faster than another product until the same workload has been run on equivalent environments.

## Current state

- The WordPress.org release is 1.0.23. PR #9 is the superseding 1.0.24 release candidate; PR #8 has been superseded and is not the release source.
- PR #9 carries forward DNS-pinned cURL checks, fail-closed proxy handling, occurrence-specific repairs, and cursor-based postmeta traversal.
- The current candidate also reduces database round trips with atomic URL and occurrence upserts, refreshes the admin findings less often, adapts its polling delay, schedules short WP-Cron continuations, and reports when the pinned transport is unavailable.
- The plugin already has bounded workers, resumable discovery, URL-level evidence, scan history, source-aware repair/undo, and CI checks for PHP 7.4/8.4, Plugin Check, WordPress integration, and browser behavior.
- WP-Cron is traffic-driven. Shorter continuation events reduce idle time on active sites, but a system cron is still needed for dependable work on low-traffic sites.

## Baseline and measurement rules

The last successful CI run inspected during this roadmap review recorded:

| Fixture | Baseline |
|---|---|
| 2,000 unique URLs | 201 worker steps, 8.33 seconds, 12,737 database queries, 55.3 MiB peak memory |
| 30,000 source records with 3 unique URLs | 152 worker steps, 31.17 seconds, 44,359 database queries, 53.3 MiB peak memory |
| Findings list | 2,000 URLs in the dataset, 20 rows returned, 0.004 seconds, 4 queries |

The scan fixtures reject network requests. These are local CI measurements, not live-link timings, customer-host performance, or a comparison with cloud scanners. The findings-list test is a 20-row page, not a 2,000-row page. Record the exact commit, PHP version, WordPress version, database version, requests made, query count, elapsed worker time, and peak memory with every new result.

## Release candidate 1.0.24 — make scan work safe and responsive

**Status: implementation in the superseding pull request; CI review required.**

- Pin each permitted HTTP connection to the validated DNS address. Fail closed for unsupported cURL transports, proxy routes, and WordPress outbound-block policies.
- Repair only the recorded source occurrence; detect source edits before changing or undoing content.
- Use atomic URL and occurrence writes to remove the read-before-write database query for each discovered item. Preserve duplicate handling, ignored resources, and scan-resume behavior.
- Show progress from the worker response. Refresh the findings and dashboard at phase changes, every ten steps, and completion instead of after every step.
- Set a minimum 250 ms admin pause and scale the pause to the observed worker response time, capped at 1.2 seconds. Keep slower backoff when progress stalls.
- Schedule one bounded WP-Cron continuation about every five seconds while a scan remains active, retaining the one-minute event as a fallback.
- Display a clear admin warning when the pinned cURL transport is unavailable; affected URLs must remain unverified.
- Continue the bounded postmeta cursor migration and restart active postmeta scans safely.

### Release gates

- PHP 7.4 and 8.4 syntax and release metadata pass.
- Strict WordPress Plugin Check passes.
- Integration coverage confirms one resource per unique URL, every source occurrence retained once, ignored resources remain ignored, and existing results reset only when a new scan begins.
- Repair, undo, DNS pinning, redirect, proxy, and outbound-policy checks pass.
- CI reruns both scan fixtures on the same runner and compares query counts, elapsed time, and memory with the baseline above.
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

**Acceptance:** every scan completes or ends with a specific recoverable error; performance reports are repeatable on the same runner; correctness counts match the fixtures; query and memory changes are measured against the documented baseline.

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
