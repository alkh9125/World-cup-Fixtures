<?php
/**
 * Minimal PSR-4 autoloader used only when composer's vendor/autoload.php
 * has not been generated (e.g. plugin uploaded as a zip without `composer install`).
 * Maps the SCD\ namespace to includes/.
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register( static function ( string $class ): void {
	$prefix = 'SCD\\';

	if ( ! str_starts_with( $class, $prefix ) ) {
		return;
	}

	$relative = substr( $class, strlen( $prefix ) );
	$path     = SCD_STANDINGS_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

	if ( is_readable( $path ) ) {
		require $path;
	}
} );
