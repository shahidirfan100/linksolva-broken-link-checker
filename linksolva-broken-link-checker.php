<?php
/**
 * Plugin Name:       LinkSolva - Broken Link Checker
 * Plugin URI:        https://github.com/shahidirfan100/linksolva-broken-link-checker
 * Description:       Find and review broken links, redirects, and missing images locally with clear evidence and safe repairs.
 * Version:           1.0.15
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Shahid Irfan
 * Author URI:        https://profiles.wordpress.org/shahidirfan100/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       linksolva-broken-link-checker
 * Domain Path:       /languages
 *
 * @package LinkSolvaBrokenLinkChecker
 */

defined( 'ABSPATH' ) || exit;

define( 'LINKSOLVA_BLC_VERSION', '1.0.15' );
define( 'LINKSOLVA_BLC_PLUGIN_FILE', __FILE__ );
define( 'LINKSOLVA_BLC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LINKSOLVA_BLC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once LINKSOLVA_BLC_PLUGIN_DIR . 'includes/class-settings.php';
require_once LINKSOLVA_BLC_PLUGIN_DIR . 'includes/class-installer.php';
require_once LINKSOLVA_BLC_PLUGIN_DIR . 'includes/class-database.php';
require_once LINKSOLVA_BLC_PLUGIN_DIR . 'includes/class-url.php';
require_once LINKSOLVA_BLC_PLUGIN_DIR . 'includes/class-ssrf-guard.php';
require_once LINKSOLVA_BLC_PLUGIN_DIR . 'includes/class-http-checker.php';
require_once LINKSOLVA_BLC_PLUGIN_DIR . 'includes/class-extractor.php';
require_once LINKSOLVA_BLC_PLUGIN_DIR . 'includes/class-sources.php';
require_once LINKSOLVA_BLC_PLUGIN_DIR . 'includes/class-scanner.php';
require_once LINKSOLVA_BLC_PLUGIN_DIR . 'includes/class-repair.php';
require_once LINKSOLVA_BLC_PLUGIN_DIR . 'includes/class-rest.php';
require_once LINKSOLVA_BLC_PLUGIN_DIR . 'admin/class-admin.php';
require_once LINKSOLVA_BLC_PLUGIN_DIR . 'includes/class-plugin.php';

// phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- The callback registers one documented 60-second continuation interval for bounded background work.
add_filter( 'cron_schedules', array( 'LinkSolva\BrokenLinkChecker\\Installer', 'cron_schedules' ) );
register_activation_hook( LINKSOLVA_BLC_PLUGIN_FILE, array( 'LinkSolva\BrokenLinkChecker\\Installer', 'activate' ) );
register_deactivation_hook( LINKSOLVA_BLC_PLUGIN_FILE, array( 'LinkSolva\BrokenLinkChecker\\Installer', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		\LinkSolva\BrokenLinkChecker\Plugin::instance()->run();
	}
);
