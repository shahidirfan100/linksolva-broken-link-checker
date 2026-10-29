=== LinkSolva - Broken Link Checker ===
Contributors: shahidirfan100
Tags: broken links, link checker, broken images, redirects, local scanner
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.16
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find broken links, redirects, and missing images locally with evidence, safe retries, and auditable repairs.

== Description ==

LinkSolva - Broken Link Checker is a local WordPress tool for administrators who need to know what is genuinely broken before changing content.

It discovers URLs in public posts, pages, public custom post types, approved comments, custom navigation menus, featured images, and optionally scalar custom-field or page-builder data. Findings are grouped by normalized URL, and every matching source location is retained. A check may make more than one HTTP request when it follows redirects or needs a limited GET fallback after HEAD.

The plugin classifies results instead of treating every failed request as a dead link:

* **Healthy:** a successful response was received.
* **Redirect:** the destination works but reaches another URL.
* **Broken:** HTTP 404 or 410 provides strong evidence that the destination no longer exists.
* **Blocked:** the destination rejected automated verification, such as HTTP 401 or 403.
* **Rate Limited:** the destination returned HTTP 429 and should be checked later.
* **Temporary Error:** the destination returned a 5xx response.
* **SSL Error, DNS Error, Timeout, or Unverified:** the scanner could not establish enough evidence to call the URL broken.

Every result includes request history, redirect chain, response time, confidence, explanation, and source locations.

Completed and stopped scan results can be kept locally for later review. Choose to retain 5, 10, or 25 runs, or turn history off. Saved runs include their URL results and source locations; stopped runs are marked incomplete. The oldest saved run is removed when the selected limit is exceeded. On upgrade, the latest completed run is saved from the findings still stored by the plugin. Results overwritten by earlier scans cannot be restored.

### Local by design

* No cloud account.
* No link credits or usage limits imposed by a third party.
* No telemetry, advertising, or developer tracking.
* No URLs or site content are sent to a developer service.
* HTTP requests are made from your WordPress server to the destinations found in your content.

### Safe repairs

Supported post content, approved comment content, and custom menu URLs can be replaced, unlinked, or marked nofollow. Builder metadata is read-only in this release. Each repair stores the complete before and after source, the user, time, operation, and checksums. Undo is blocked when the source changed after the repair.

### Resource controls

Scanning runs in small resumable worker requests. The scanner checks discovered URLs in small batches as it continues finding more. During discovery, the percentage shows the share of URLs found so far that have been checked; it can change as new URLs are found. Repeated occurrences do not re-queue a settled URL. Only one worker request processes the scan at a time. Worker duration follows PHP's execution-time limit. Batch size and parallel requests adjust to available PHP memory; no more than four URLs are checked at once. URLs that cannot be checked within a worker's time budget are marked unverified, counted, and shown for manual review instead of remaining queued. Network timeouts also remain unverified. PHP does not reliably expose shared-host CPU quotas, so the plugin uses conservative concurrency limits rather than claiming to measure CPU availability. Manual scanning remains available when WordPress cron is delayed.

### Privacy

This plugin does not create a remote account or transmit data to the developer. The server running WordPress may make requests to external URLs found in your content. The request destination, response status, timing, and limited diagnostic categories are stored in your WordPress database.

== Installation ==

1. Upload the `linksolva-broken-link-checker` folder to `/wp-content/plugins/`, or install the ZIP from **Plugins → Add New → Upload Plugin**.
2. Activate the plugin.
3. Open **Link Checker → Findings**.
4. Review **Link Checker → Settings** before starting a large scan.
5. Run a scan and open **Evidence** on any result that needs review.
6. Open **Link Checker → Scan history** to view results from saved runs.

== Frequently Asked Questions ==

= Does it use a cloud service? =

No. All discovery, storage, classification, and repair operations are local to your WordPress installation. The server still requests the URLs it is checking, as any link checker must.

= Is a 403 link reported as broken? =

No. A 401 or 403 response is classified as **Blocked** because automated access was rejected. Other inconclusive failures remain in a review state instead of being called broken.

= Is a 429 link reported as broken? =

No. HTTP 429 is classified as **Rate Limited** and scheduled for a later check.

= Can it edit page-builder data? =

The plugin can read scalar metadata when enabled, but it does not rewrite serialized or builder data automatically. This avoids corrupting Elementor, ACF, or other structured content.

= Can I remove all data on uninstall? =

Findings and saved scan history stay in the site's database. Enable **Delete findings when the plugin is uninstalled** in Settings if you want plugin-owned tables and options removed.

= How many completed scans can I keep? =

Choose 5, 10, or 25 runs in Settings, or turn scan history off. When you lower the limit, the oldest saved results are removed.

= Does it add scripts to the public website? =

No. Styles and scripts are loaded only on the plugin's WordPress admin screens.

== Screenshots ==

1. Findings dashboard with URL status, source counts, and filters for link scope and resource type.
2. Broken-link evidence with the HTTP result, request history, and source location.
3. Redirect evidence showing the final destination and each redirect hop.
4. Settings for bounded work, parallel checks, timeouts, content sources, scan scheduling, and history retention.

== Limitations ==

* Some websites intentionally reject automated verification. A successful browser visit cannot always be reproduced by a server-side request.
* Redirects to private, loopback, link-local, metadata, or otherwise reserved addresses are stopped for SSRF protection.
* Structured page-builder data is review-only until a source-specific safe editor is available.
* WordPress cron may be delayed or disabled by hosting configuration. The findings screen provides manual worker progress.

== Changelog ==

= 1.0.16 =
* Align status filter counts with the selected link scope, type, and search, and add a clear-filters control.
* Prioritize the selected internal or external URLs in the scan queue.
* Save stopped scans in history as incomplete when scan history is enabled.
* Bound the time spent waiting for the live findings request.

= 1.0.15 =
* Check discovered links as the scan continues to find more, and show progress for URLs found so far.

= 1.0.14 =
* Keep scans resumable when scheduled workers are delayed and show verification progress against a fixed URL total.
* Prevent a new scan from clearing the current report if its history snapshot cannot be saved.
* Report database errors instead of treating missing progress data as a completed scan.

= 1.0.13 =
* Added configurable retention for 5, 10, or 25 completed scans, with an option to turn history off.
* Saved historical URL results and source locations locally, with read-only review from the new Scan history screen.
* Added bounded, paginated history queries and automatic removal of the oldest saved results.

= 1.0.12 =
* Completed scans now mark URLs that reach the worker time limit for manual review instead of leaving the queue stuck.
* The dashboard and optional email summary report URLs that timed out or were skipped.
* Worker duration follows the PHP execution-time limit; batch size and parallel checks adjust to available PHP memory.

= 1.0.11 =
* Added WordPress Plugin Check and PHP syntax checks to GitHub Actions.
* Reduced scan polling frequency and improved keyboard operation of the evidence drawer.
* Clarified request-count, concurrency, and interrupted-scan messages.

= 1.0.10 =
* Updated the plugin name, directory slug, translation domain, and matching directory graphics for the WordPress.org resubmission.

= 1.0.9 =
* Cleared the scan worker lock when the plugin is deactivated or uninstalled, including when findings are retained.
* Required the relevant WordPress source-edit capability before applying or undoing a repair.
* Reused the initial URL safety decision within a parallel batch to avoid an extra DNS validation for each result.
* Paginated navigation-menu discovery so each worker loads only the current small batch of menu items.

= 1.0.8 =
* Stopped issuing an immediate GET request after a HEAD network failure or timeout; the URL is recorded as unverified and retried later so the scan can continue.
* Kept batch transport failures and missing responses from triggering a second sequential network attempt.

= 1.0.7 =
* Capped each HTTP request to the remaining worker time budget so slow destinations cannot hold a scan request beyond its configured work window.
* Kept unchecked URLs queued for the next worker request when the time budget expires.
* Serialized worker requests so cron and admin polling cannot issue duplicate checks for the same batch.

= 1.0.6 =
* Added bounded parallel URL verification with a configurable 1–8 request limit to reduce scan time on large sites.
* Avoided redundant GET requests for definitive 404 and 410 responses while retaining GET fallback for inconclusive HEAD responses.
* Reindexed replacement URLs immediately, verified them before returning success, and retired orphaned old findings.
* Restored the original occurrence/resource mapping on undo and kept replacement and unlink operations auditable.

= 1.0.5 =
* Serialized discovery and verification so each discovered batch is classified before more source URLs are fetched.
* Prevented repeated occurrences from resetting an already verified URL to Queued during the same scan.
* Kept ignored resources out of the verification queue while preserving their current-scan visibility.
* Added progress messages and resilient REST polling so active scans continue updating after transient browser requests fail.

= 1.0.4 =
* Prevented a scan from completing while current-scan resources remain queued.
* Normalized legacy nullable queue markers during upgrade.
* Removed the stale empty-table row when live results arrive.

= 1.0.3 =
* Added Internal links, External links, and All links filtering on the Findings screen.
* Added separate settings to include or exclude internal and external links from scans.
* Reset rediscovered URLs to Queued until their current verification request finishes.
* Updated individual result rows without replacing the complete table body during scan progress.

= 1.0.2 =
* Updated scan progress, counters, filters, and result rows in place to prevent dashboard blinking during active scans.
* Preserved the dashboard shell and filter controls while new findings are added.
* Added bounded GET verification when a server rejects or fails a HEAD request.
* Distinguished URLs waiting in the scan queue from completed unverified results.
* Made every new manual or scheduled scan retry previously inconclusive URLs instead of silently skipping them.

= 1.0.1 =
* Fixed admin REST requests on sites using plain permalinks.
* Improved DNS failure classification and nonstandard-port URL resolution.
* Preserved every repeated source occurrence while checking each unique URL once.
* Hardened repair auditing, undo behavior, recheck scheduling, translations, and admin asset loading.
* Added a one-minute bounded worker continuation and refreshed WordPress.org release assets.

= 1.0.0 =
* New local-only broken link and image checker.
* Added evidence-based HTTP classifications, URL deduplication, bounded scanning, safe repairs, and conflict-aware undo.

== Upgrade Notice ==

= 1.0.16 =
Status filter counts now reflect the selected link scope, type, and search.

= 1.0.15 =
Checks URLs during discovery and shows progress for URLs found so far.

= 1.0.14 =
Improves scan progress reporting and protects the previous report when history cannot be saved.

= 1.0.13 =
Completed scans can now be saved and reviewed later. Choose the number of completed runs to retain in Settings.

= 1.0.12 =
Scans now finish with timed-out URLs listed for manual review. Worker duration follows the site's PHP execution-time limit, while batch size and parallel checks adjust to available PHP memory.

= 1.0.11 =
The admin scan monitor now makes fewer polling requests. The evidence drawer supports Escape and keeps keyboard focus inside while open.

= 1.0.10 =
The plugin now uses the LinkSolva brand and its matching WordPress.org directory slug.

= 1.0.9 =
Scan worker locks are cleared on deactivation and uninstall. Repairs now require permission to edit the affected WordPress source.

= 1.0.8 =
Unresponsive destinations no longer cause an immediate duplicate request; scan work moves on and retries them later.

= 1.0.7 =
Worker requests now respect the configured time budget and cannot overlap, which helps keep scans predictable on shared hosting.
