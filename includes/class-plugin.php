<?php
/**
 * Plugin coordinator.
 *
 * @package LinkSolvaBrokenLinkChecker
 */

namespace LinkSolva\BrokenLinkChecker;

use LinkSolva\BrokenLinkChecker\Admin\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates plugin services and WordPress hooks.
 */
final class Plugin {
	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register runtime hooks.
	 *
	 * @return void
	 */
	public function run() {
		Installer::maybe_upgrade();
		add_action( 'admin_menu', array( Admin::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( Admin::class, 'assets' ) );
		add_action( 'admin_post_sblc_export', array( Admin::class, 'export' ) );
		add_action( 'wp_ajax_sblc_refresh_rest_nonce', array( Admin::class, 'refresh_rest_nonce' ) );
		add_action( 'rest_api_init', array( Rest::class, 'register' ) );
		add_action( 'sblc_worker', array( Scanner::class, 'scheduled_step' ) );
	}
}
