<?php
/**
 * Plugin Name:       SCD Standings & Scenarios
 * Plugin URI:        https://saudiopendata.com
 * Description:       Live tournament standings, qualification scenarios, knockout projections, and an interactive what-if simulator for SaudiOpenData.com.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            SaudiOpenData
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       scd-standings
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'SCD_STANDINGS_VERSION', '1.1.0' );
define( 'SCD_STANDINGS_FILE', __FILE__ );
define( 'SCD_STANDINGS_DIR', plugin_dir_path( __FILE__ ) );
define( 'SCD_STANDINGS_URL', plugin_dir_url( __FILE__ ) );
define( 'SCD_DB_VERSION', '1.0.0' );

$scd_autoload = SCD_STANDINGS_DIR . 'vendor/autoload.php';
if ( file_exists( $scd_autoload ) ) {
	require_once $scd_autoload;
} else {
	require_once SCD_STANDINGS_DIR . 'includes/autoload-fallback.php';
}

require_once SCD_STANDINGS_DIR . 'includes/Infrastructure/Database/Schema.php';

use SCD\Infrastructure\Database\Schema;
use SCD\Plugin;

register_activation_hook( __FILE__, static function () {
	Schema::install();
	// CPTs/rewrite rules aren't registered yet at activation time (that
	// happens on plugins_loaded) - flag it so Rewrites::maybe_flush() can
	// flush once they are, on the very next init.
	update_option( 'scd_flush_rewrite_rules', 1 );
} );
register_deactivation_hook( __FILE__, static function () {
	SCD\Jobs\SyncJob::unschedule();
	flush_rewrite_rules();
} );

add_action( 'plugins_loaded', static function () {
	Plugin::instance()->boot();
} );
