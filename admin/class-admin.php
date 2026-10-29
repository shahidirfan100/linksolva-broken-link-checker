<?php
/**
 * WordPress admin screen and release export.
 *
 * @package LinkSolvaBrokenLinkChecker
 */

namespace LinkSolva\BrokenLinkChecker\Admin;

use LinkSolva\BrokenLinkChecker\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the plugin's administrator interface.
 */
final class Admin {
	/**
	 * Add the focused admin menu.
	 *
	 * @return void
	 */
	public static function menu() {
		add_menu_page( __( 'LinkSolva - Broken Link Checker', 'linksolva-broken-link-checker' ), __( 'LinkSolva', 'linksolva-broken-link-checker' ), 'manage_options', 'sblc-dashboard', array( __CLASS__, 'render' ), 'dashicons-admin-links', 35 );
		add_submenu_page( 'sblc-dashboard', __( 'LinkSolva', 'linksolva-broken-link-checker' ), __( 'Findings', 'linksolva-broken-link-checker' ), 'manage_options', 'sblc-dashboard', array( __CLASS__, 'render' ) );
		add_submenu_page( 'sblc-dashboard', __( 'Scan history', 'linksolva-broken-link-checker' ), __( 'Scan history', 'linksolva-broken-link-checker' ), 'manage_options', 'sblc-history', array( __CLASS__, 'render' ) );
		add_submenu_page( 'sblc-dashboard', __( 'Settings', 'linksolva-broken-link-checker' ), __( 'Settings', 'linksolva-broken-link-checker' ), 'manage_options', 'sblc-settings', array( __CLASS__, 'render' ) );
	}

	/**
	 * Enqueue assets only on plugin pages.
	 *
	 * @param string $hook Hook suffix.
	 * @return void
	 */
	public static function assets( $hook ) {
		if ( ! in_array( $hook, array( 'toplevel_page_sblc-dashboard', 'linksolva_page_sblc-settings', 'linksolva_page_sblc-history' ), true ) ) {
			return;
		}
		$page = filter_input( INPUT_GET, 'page', FILTER_SANITIZE_SPECIAL_CHARS );
		$page = $page ? sanitize_key( $page ) : '';
		wp_enqueue_style( 'sblc-admin', LINKSOLVA_BLC_PLUGIN_URL . 'assets/css/admin.css', array(), LINKSOLVA_BLC_VERSION );
		wp_enqueue_script( 'sblc-admin', LINKSOLVA_BLC_PLUGIN_URL . 'assets/js/admin.js', array(), LINKSOLVA_BLC_VERSION, true );
		wp_localize_script(
			'sblc-admin',
			'LINKSOLVA_BLC_DATA',
			array(
				'restUrl'   => esc_url_raw( rest_url( 'sblc/v1' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'exportUrl' => esc_url_raw( admin_url( 'admin-post.php?action=sblc_export&_wpnonce=' . wp_create_nonce( 'sblc_export' ) ) ),
				'page'      => 'sblc-settings' === $page ? 'settings' : ( 'sblc-history' === $page ? 'history' : 'findings' ),
				'homeUrl'   => esc_url_raw( home_url( '/' ) ),
				'logoUrl'   => esc_url_raw( LINKSOLVA_BLC_PLUGIN_URL . 'assets/images/linksolva-mark.png' ),
				'strings'   => array(
					'The request could not be completed.' => __( 'The request could not be completed.', 'linksolva-broken-link-checker' ),
					'Discovering sources'                 => __( 'Discovering sources', 'linksolva-broken-link-checker' ),
					'Verifying resources'                 => __( 'Verifying resources', 'linksolva-broken-link-checker' ),
					'Scan progress'                       => __( 'Scan progress', 'linksolva-broken-link-checker' ),
					'Stop scan'                           => __( 'Stop scan', 'linksolva-broken-link-checker' ),
					'All'                                 => __( 'All', 'linksolva-broken-link-checker' ),
					'Broken'                              => __( 'Broken', 'linksolva-broken-link-checker' ),
					'Redirects'                           => __( 'Redirects', 'linksolva-broken-link-checker' ),
					'Needs review'                        => __( 'Needs review', 'linksolva-broken-link-checker' ),
					'Blocked'                             => __( 'Blocked', 'linksolva-broken-link-checker' ),
					'Healthy'                             => __( 'Healthy', 'linksolva-broken-link-checker' ),
					'Ignored'                             => __( 'Ignored', 'linksolva-broken-link-checker' ),
					'Scan history'                        => __( 'Scan history', 'linksolva-broken-link-checker' ),
					'Scans to keep'             => __( 'Scans to keep', 'linksolva-broken-link-checker' ),
					'Off'                                 => __( 'Off', 'linksolva-broken-link-checker' ),
					'5 scans'                             => __( '5 scans', 'linksolva-broken-link-checker' ),
					'10 scans'                            => __( '10 scans', 'linksolva-broken-link-checker' ),
					'25 scans'                            => __( '25 scans', 'linksolva-broken-link-checker' ),
					'Save completed and stopped scans, including URL results and source locations. Lowering the limit removes the oldest saved runs.' => __( 'Save completed and stopped scans, including URL results and source locations. Lowering the limit removes the oldest saved runs.', 'linksolva-broken-link-checker' ),
					'No scans have been saved yet.' => __( 'No scans have been saved yet.', 'linksolva-broken-link-checker' ),
					'Review results saved from completed and stopped scans.' => __( 'Review results saved from completed and stopped scans.', 'linksolva-broken-link-checker' ),
					'Run'                                 => __( 'Run', 'linksolva-broken-link-checker' ),
					'Started (UTC)'                       => __( 'Started (UTC)', 'linksolva-broken-link-checker' ),
					'Finished (UTC)'                      => __( 'Finished (UTC)', 'linksolva-broken-link-checker' ),
					'URLs checked'                        => __( 'URLs checked', 'linksolva-broken-link-checker' ),
					'Sources found'                       => __( 'Sources found', 'linksolva-broken-link-checker' ),
					'Broken / review / redirects / healthy' => __( 'Broken / review / redirects / healthy', 'linksolva-broken-link-checker' ),
					'View results'                        => __( 'View results', 'linksolva-broken-link-checker' ),
					'Back to scan history'                => __( 'Back to scan history', 'linksolva-broken-link-checker' ),
					'Saved URL results from this run.' => __( 'Saved URL results from this run.', 'linksolva-broken-link-checker' ),
					'No URL results were saved for this run.' => __( 'No URL results were saved for this run.', 'linksolva-broken-link-checker' ),
					'View evidence'                        => __( 'View evidence', 'linksolva-broken-link-checker' ),
					'No source locations were saved for this URL.' => __( 'No source locations were saved for this URL.', 'linksolva-broken-link-checker' ),
					'Scan history is off. Enable it in Settings to save future runs.' => __( 'Scan history is off. Enable it in Settings to save future runs.', 'linksolva-broken-link-checker' ),
					'Loading history…'                    => __( 'Loading history…', 'linksolva-broken-link-checker' ),
					'Loading history results…'            => __( 'Loading history results…', 'linksolva-broken-link-checker' ),
					'Unverified'                          => __( 'Unverified', 'linksolva-broken-link-checker' ),
					'Queued'                              => __( 'Queued', 'linksolva-broken-link-checker' ),
					'Link scope'                          => __( 'Link scope', 'linksolva-broken-link-checker' ),
					'All links'                           => __( 'All links', 'linksolva-broken-link-checker' ),
					'Complete'                            => __( 'Complete', 'linksolva-broken-link-checker' ),
					'Incomplete'                          => __( 'Incomplete', 'linksolva-broken-link-checker' ),
					'Clear filters'                       => __( 'Clear filters', 'linksolva-broken-link-checker' ),
					'Summary totals include all links; status filters follow scope, type and search. During a scan, the selected scope is checked first, then other links.' => __( 'Summary totals include all links; status filters follow scope, type and search. During a scan, the selected scope is checked first, then other links.', 'linksolva-broken-link-checker' ),
					'Internal links'                      => __( 'Internal links', 'linksolva-broken-link-checker' ),
					'External links'                      => __( 'External links', 'linksolva-broken-link-checker' ),
					'Internal'                            => __( 'Internal', 'linksolva-broken-link-checker' ),
					'External'                            => __( 'External', 'linksolva-broken-link-checker' ),
					'Check internal links'                => __( 'Check internal links', 'linksolva-broken-link-checker' ),
					'Check external links'                => __( 'Check external links', 'linksolva-broken-link-checker' ),
					'Rate Limited'                        => __( 'Rate Limited', 'linksolva-broken-link-checker' ),
					'Temporary Error'                     => __( 'Temporary Error', 'linksolva-broken-link-checker' ),
					'SSL Error'                           => __( 'SSL Error', 'linksolva-broken-link-checker' ),
					'DNS Error'                           => __( 'DNS Error', 'linksolva-broken-link-checker' ),
					'Timeout'                             => __( 'Timeout', 'linksolva-broken-link-checker' ),
					'Findings'                            => __( 'Findings', 'linksolva-broken-link-checker' ),
					'Findings are grouped by URL, with every source location retained.' => __( 'Findings are grouped by URL, with every source location retained.', 'linksolva-broken-link-checker' ),
					'No response'                         => __( 'No response', 'linksolva-broken-link-checker' ),
					'Run new scan'                        => __( 'Run new scan', 'linksolva-broken-link-checker' ),
					'Export CSV'                          => __( 'Export CSV', 'linksolva-broken-link-checker' ),
					'Finding summary'                     => __( 'Finding summary', 'linksolva-broken-link-checker' ),
					'Unique URLs'                         => __( 'Unique URLs', 'linksolva-broken-link-checker' ),
					'Occurrences'                         => __( 'Occurrences', 'linksolva-broken-link-checker' ),
					'Search URL'                          => __( 'Search URL', 'linksolva-broken-link-checker' ),
					'Resource type'                       => __( 'Resource type', 'linksolva-broken-link-checker' ),
					'All types'                           => __( 'All types', 'linksolva-broken-link-checker' ),
					'Links'                               => __( 'Links', 'linksolva-broken-link-checker' ),
					'Images'                              => __( 'Images', 'linksolva-broken-link-checker' ),
					'Bulk action'                         => __( 'Bulk action', 'linksolva-broken-link-checker' ),
					'Bulk actions'                        => __( 'Bulk actions', 'linksolva-broken-link-checker' ),
					'Queue recheck'                       => __( 'Queue recheck', 'linksolva-broken-link-checker' ),
					'Ignore'                              => __( 'Ignore', 'linksolva-broken-link-checker' ),
					'Restore'                             => __( 'Restore', 'linksolva-broken-link-checker' ),
					'Apply'                               => __( 'Apply', 'linksolva-broken-link-checker' ),
					'Bulk URL replacement is intentionally unavailable.' => __( 'Bulk URL replacement is intentionally unavailable.', 'linksolva-broken-link-checker' ),
					'Select all'                          => __( 'Select all', 'linksolva-broken-link-checker' ),
					'Status'                              => __( 'Status', 'linksolva-broken-link-checker' ),
					'URL'                                 => __( 'URL', 'linksolva-broken-link-checker' ),
					'HTTP'                                => __( 'HTTP', 'linksolva-broken-link-checker' ),
					'Type'                                => __( 'Type', 'linksolva-broken-link-checker' ),
					'Sources'                             => __( 'Sources', 'linksolva-broken-link-checker' ),
					'Action'                              => __( 'Action', 'linksolva-broken-link-checker' ),
					'Showing'                             => __( 'Showing', 'linksolva-broken-link-checker' ),
					'of'                                  => __( 'of', 'linksolva-broken-link-checker' ),
					'Previous'                            => __( 'Previous', 'linksolva-broken-link-checker' ),
					'Next'                                => __( 'Next', 'linksolva-broken-link-checker' ),
					'source'                              => __( 'source', 'linksolva-broken-link-checker' ),
					'sources'                             => __( 'sources', 'linksolva-broken-link-checker' ),
					'Evidence'                            => __( 'Evidence', 'linksolva-broken-link-checker' ),
					'Settings'                            => __( 'Settings', 'linksolva-broken-link-checker' ),
					'Requests stay on this WordPress server. The plugin does not use an account, cloud scanner, tracking, or link credits.' => __( 'Requests stay on this WordPress server. The plugin does not use an account, cloud scanner, tracking, or link credits.', 'linksolva-broken-link-checker' ),
					'Verification safety'                 => __( 'Verification safety', 'linksolva-broken-link-checker' ),
					'Resources per worker request'        => __( 'Resources per worker request', 'linksolva-broken-link-checker' ),
					'The worker caps this at ten and lowers it when PHP memory is tight.' => __( 'The worker caps this at ten and lowers it when PHP memory is tight.', 'linksolva-broken-link-checker' ),
					'The worker caps this at four and lowers it when PHP memory is tight.' => __( 'The worker caps this at four and lowers it when PHP memory is tight.', 'linksolva-broken-link-checker' ),
					'Request timeout, seconds'            => __( 'Request timeout, seconds', 'linksolva-broken-link-checker' ),
					'Maximum redirect hops'               => __( 'Maximum redirect hops', 'linksolva-broken-link-checker' ),
					'Temporary failure retries'           => __( 'Temporary failure retries', 'linksolva-broken-link-checker' ),
					'Content sources'                     => __( 'Content sources', 'linksolva-broken-link-checker' ),
					'Public post types'                   => __( 'Public post types', 'linksolva-broken-link-checker' ),
					'Leave all unchecked to include every public post type except attachments and menu items.' => __( 'Leave all unchecked to include every public post type except attachments and menu items.', 'linksolva-broken-link-checker' ),
					'Scan approved comments'              => __( 'Scan approved comments', 'linksolva-broken-link-checker' ),
					'Scan navigation menu URLs'           => __( 'Scan navigation menu URLs', 'linksolva-broken-link-checker' ),
					'Check featured images'               => __( 'Check featured images', 'linksolva-broken-link-checker' ),
					'Read scalar custom-field and builder data' => __( 'Read scalar custom-field and builder data', 'linksolva-broken-link-checker' ),
					'Limit custom-field keys (one per line, optional)' => __( 'Limit custom-field keys (one per line, optional)', 'linksolva-broken-link-checker' ),
					'Builder metadata is read-only in this release; it is never rewritten blindly.' => __( 'Builder metadata is read-only in this release; it is never rewritten blindly.', 'linksolva-broken-link-checker' ),
					'Exclusions and lifecycle'            => __( 'Exclusions and lifecycle', 'linksolva-broken-link-checker' ),
					'Excluded domains (one per line)'     => __( 'Excluded domains (one per line)', 'linksolva-broken-link-checker' ),
					'Excluded URL fragments (one per line)' => __( 'Excluded URL fragments (one per line)', 'linksolva-broken-link-checker' ),
					'Scheduled scans'                     => __( 'Scheduled scans', 'linksolva-broken-link-checker' ),
					'Manual only'                         => __( 'Manual only', 'linksolva-broken-link-checker' ),
					'Daily'                               => __( 'Daily', 'linksolva-broken-link-checker' ),
					'Weekly'                              => __( 'Weekly', 'linksolva-broken-link-checker' ),
					'Scheduled work starts a bounded scan from WP-Cron. Manual scanning remains available if cron is delayed.' => __( 'Scheduled work starts a bounded scan from WP-Cron. Manual scanning remains available if cron is delayed.', 'linksolva-broken-link-checker' ),
					'Email a local summary when a scan completes' => __( 'Email a local summary when a scan completes', 'linksolva-broken-link-checker' ),
					'Notification email'                  => __( 'Notification email', 'linksolva-broken-link-checker' ),
					'Delete findings when the plugin is uninstalled' => __( 'Delete findings when the plugin is uninstalled', 'linksolva-broken-link-checker' ),
					'Save settings'                       => __( 'Save settings', 'linksolva-broken-link-checker' ),
					'Replacement URL'                     => __( 'Replacement URL', 'linksolva-broken-link-checker' ),
					'Replace URL'                         => __( 'Replace URL', 'linksolva-broken-link-checker' ),
					'Add nofollow'                        => __( 'Add nofollow', 'linksolva-broken-link-checker' ),
					'Unlink'                              => __( 'Unlink', 'linksolva-broken-link-checker' ),
					'Read-only source.'                   => __( 'Read-only source.', 'linksolva-broken-link-checker' ),
					'This source can be reviewed but is not automatically edited.' => __( 'This source can be reviewed but is not automatically edited.', 'linksolva-broken-link-checker' ),
					'Untitled source'                     => __( 'Untitled source', 'linksolva-broken-link-checker' ),
					'Open source'                         => __( 'Open source', 'linksolva-broken-link-checker' ),
					'Undone'                              => __( 'Undone', 'linksolva-broken-link-checker' ),
					'Undo'                                => __( 'Undo', 'linksolva-broken-link-checker' ),
					'Close evidence'                      => __( 'Close evidence', 'linksolva-broken-link-checker' ),
					'Recheck'                             => __( 'Recheck', 'linksolva-broken-link-checker' ),
					'Mark manually verified'              => __( 'Mark manually verified', 'linksolva-broken-link-checker' ),
					'Classification'                      => __( 'Classification', 'linksolva-broken-link-checker' ),
					'Confidence'                          => __( 'Confidence', 'linksolva-broken-link-checker' ),
					'HTTP result'                         => __( 'HTTP result', 'linksolva-broken-link-checker' ),
					'Final URL'                           => __( 'Final URL', 'linksolva-broken-link-checker' ),
					'Response time'                       => __( 'Response time', 'linksolva-broken-link-checker' ),
					'Last checked'                        => __( 'Last checked', 'linksolva-broken-link-checker' ),
					'Not checked'                         => __( 'Not checked', 'linksolva-broken-link-checker' ),
					'Explanation'                         => __( 'Explanation', 'linksolva-broken-link-checker' ),
					'Request history'                     => __( 'Request history', 'linksolva-broken-link-checker' ),
					'No request history.'                 => __( 'No request history.', 'linksolva-broken-link-checker' ),
					'Redirect chain'                      => __( 'Redirect chain', 'linksolva-broken-link-checker' ),
					'Source locations'                    => __( 'Source locations', 'linksolva-broken-link-checker' ),
					'No source locations remain in the current scan.' => __( 'No source locations remain in the current scan.', 'linksolva-broken-link-checker' ),
					'Repair history'                      => __( 'Repair history', 'linksolva-broken-link-checker' ),
					'Loading settings…'                   => __( 'Loading settings…', 'linksolva-broken-link-checker' ),
					'Loading findings…'                   => __( 'Loading findings…', 'linksolva-broken-link-checker' ),
					'No findings match these filters.'    => __( 'No findings match these filters.', 'linksolva-broken-link-checker' ),
					'Scan started. Discovery and verification will run in small bounded requests.' => __( 'Scan started. Discovery and verification will run in small bounded requests.', 'linksolva-broken-link-checker' ),
					'The scan status request timed out.' => __( 'The scan status request timed out.', 'linksolva-broken-link-checker' ),
					'The scan has not advanced. Automatic polling stopped; refresh this page to check its status.' => __( 'The scan has not advanced. Automatic polling stopped; refresh this page to check its status.', 'linksolva-broken-link-checker' ),
					'The scan worker is not responding. Automatic retries stopped; refresh this page to try again.' => __( 'The scan worker is not responding. Automatic retries stopped; refresh this page to try again.', 'linksolva-broken-link-checker' ),
					'The scan is still active. Refresh this page to resume it.' => __( 'The scan is still active. Refresh this page to resume it.', 'linksolva-broken-link-checker' ),
					'Select resources and a bulk action first.' => __( 'Select resources and a bulk action first.', 'linksolva-broken-link-checker' ),
					'Resources updated.'                  => __( 'Resources updated.', 'linksolva-broken-link-checker' ),
					'Resource updated.'                   => __( 'Resource updated.', 'linksolva-broken-link-checker' ),
					'Restore the source content saved before this repair?' => __( 'Restore the source content saved before this repair?', 'linksolva-broken-link-checker' ),
					'Repair undone.'                      => __( 'Repair undone.', 'linksolva-broken-link-checker' ),
					'Source updated.'                     => __( 'Source updated.', 'linksolva-broken-link-checker' ),
					'Settings saved.'                     => __( 'Settings saved.', 'linksolva-broken-link-checker' ),
					'This will change stored WordPress content and create an undo record. Continue?' => __( 'This will change stored WordPress content and create an undo record. Continue?', 'linksolva-broken-link-checker' ),
					'Unlink this occurrence while preserving its visible text?' => __( 'Unlink this occurrence while preserving its visible text?', 'linksolva-broken-link-checker' ),
					'Stop the current scan after its active bounded request?' => __( 'Stop the current scan after its active bounded request?', 'linksolva-broken-link-checker' ),
				),
			)
		);
	}

	/**
	 * Render the application shell.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$page     = filter_input( INPUT_GET, 'page', FILTER_SANITIZE_SPECIAL_CHARS );
		$settings = 'sblc-settings' === $page;
		$history  = 'sblc-history' === $page;
		$screen   = $settings ? 'settings' : ( $history ? 'history' : 'findings' );
		?>
		<div class="wrap sblc-wrap" id="sblc-app" data-screen="<?php echo esc_attr( $screen ); ?>">
			<header class="sblc-header">
				<div class="sblc-brand">
					<img src="<?php echo esc_url( LINKSOLVA_BLC_PLUGIN_URL . 'assets/images/linksolva-mark.png' ); ?>" alt="" width="42" height="42" />
					<div>
						<p class="sblc-eyebrow"><?php esc_html_e( 'LOCAL SITE TOOL', 'linksolva-broken-link-checker' ); ?></p>
						<h1><?php esc_html_e( 'LinkSolva - Broken Link Checker', 'linksolva-broken-link-checker' ); ?></h1>
					</div>
				</div>
				<nav class="sblc-nav" aria-label="<?php esc_attr_e( 'Plugin navigation', 'linksolva-broken-link-checker' ); ?>">
					<a class="<?php echo ( ! $settings && ! $history ) ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=sblc-dashboard' ) ); ?>"><?php esc_html_e( 'Findings', 'linksolva-broken-link-checker' ); ?></a>
					<a class="<?php echo $history ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=sblc-history' ) ); ?>"><?php esc_html_e( 'Scan history', 'linksolva-broken-link-checker' ); ?></a>
					<a class="<?php echo $settings ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=sblc-settings' ) ); ?>"><?php esc_html_e( 'Settings', 'linksolva-broken-link-checker' ); ?></a>
				</nav>
			</header>
			<main class="sblc-main">
				<noscript><div class="notice notice-warning"><p><?php esc_html_e( 'JavaScript is required for the interactive scanner. WordPress content is not changed by simply opening this page.', 'linksolva-broken-link-checker' ); ?></p></div></noscript>
				<div class="sblc-screen" data-screen-content></div>
			</main>
		</div>
		<?php
	}

	/**
	 * Stream a CSV export of the current resources.
	 *
	 * @return void
	 */
	public static function export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to export findings.', 'linksolva-broken-link-checker' ) );
		}
		check_admin_referer( 'sblc_export' );
		$list = Database::list_resources(
			array(
				'page'     => 1,
				'per_page' => 100,
				'status'   => 'all',
				'type'     => 'all',
				'scope'    => 'all',
				'search'   => '',
			)
		);
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=linksolva-broken-link-checker-findings.csv' );
		$output = fopen( 'php://output', 'w' );
		fputcsv( $output, array( 'URL', 'Status', 'Confidence', 'HTTP', 'Type', 'Occurrences', 'Final URL', 'Last Checked', 'Explanation' ) );
		$pages = max( 1, (int) ceil( $list['total'] / 100 ) );
		for ( $page = 1; $page <= $pages; $page++ ) {
			if ( 1 !== $page ) {
				$list = Database::list_resources(
					array(
						'page'     => $page,
						'per_page' => 100,
						'status'   => 'all',
						'type'     => 'all',
						'scope'    => 'all',
						'search'   => '',
					)
				);
			}
			foreach ( $list['items'] as $item ) {
				fputcsv( $output, array( $item->url, $item->status, $item->confidence, $item->http_code, $item->resource_type, $item->occurrence_count, $item->final_url, $item->last_checked, $item->explanation ) );
			}
		}
		exit;
	}
}
