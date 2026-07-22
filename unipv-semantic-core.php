<?php
/**
 * Plugin Name:       UNIPV Semantic Core
 * Plugin URI:        https://github.com/ilbassa/unipv-semantic-core
 * Description:       Esportazione JSON-LD semantica allineata a schema.gov.it per i custom post type UNIPV.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Universita di Pavia
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       unipv-semantic-core
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'WP_ADMIN' ) && isset( $_GET['action'] ) && $_GET['action'] === 'activate' ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	ob_start();
	add_action( 'activated_plugin', function () {
		ob_end_clean();
	}, PHP_INT_MAX );
}

define( 'DESIITSE_PLUGIN_FILE', __FILE__ );
define( 'DESIITSE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

if ( ! defined( 'DESIITSE_VERSION' ) ) {
	define( 'DESIITSE_VERSION', '0.1.0' );
}
if ( ! defined( 'DESIITSE_DIR' ) ) {
	define( 'DESIITSE_DIR', DESIITSE_PLUGIN_DIR );
}
if ( ! defined( 'DESIITSE_CACHE_TTL' ) ) {
	define( 'DESIITSE_CACHE_TTL', (int) apply_filters( 'desiitse_cache_ttl', 3600 ) );
}

require_once DESIITSE_PLUGIN_DIR . 'inc/availability.php';
require_once DESIITSE_PLUGIN_DIR . 'inc/rate-limit.php';
require_once DESIITSE_PLUGIN_DIR . 'inc/ipa-lookup.php';
require_once DESIITSE_PLUGIN_DIR . 'inc/intranet-compat.php';
require_once DESIITSE_PLUGIN_DIR . 'inc/graph-helpers.php';
require_once DESIITSE_PLUGIN_DIR . 'inc/unipv-graph.php';
require_once DESIITSE_PLUGIN_DIR . 'inc/cache.php';
require_once DESIITSE_PLUGIN_DIR . 'inc/cron.php';

if ( is_admin() ) {
	require_once DESIITSE_PLUGIN_DIR . 'inc/admin-page.php';
}
