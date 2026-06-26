<?php

namespace SCD\Frontend;

use SCD\CPT\TournamentCpt;

defined( 'ABSPATH' ) || exit;

/**
 * Swaps in this plugin's own template file for every route Frontend\Rewrites
 * resolves, the same way a theme's single.php/archive.php would be picked -
 * themes can override any of them by adding their own
 * {theme}/scd-standings/{name}.php (checked first via locate_template()).
 *
 * The tournament's own page is a native CPT single (no scd_route query var
 * - Rewrites only tags the routes it had to invent), so it's detected here
 * via is_singular() instead.
 */
final class TemplateLoader {

	private const ROUTE_TEMPLATES = [
		'tournament' => 'single-tournament',
		'group'      => 'archive-group',
		'team'       => 'single-team',
		'match'      => 'single-match',
		'scenario'   => 'single-scenario',
	];

	public function register(): void {
		add_filter( 'template_include', [ $this, 'maybe_override_template' ] );
	}

	public function maybe_override_template( string $template ): string {
		if ( is_404() ) {
			return $template;
		}

		$route = (string) get_query_var( 'scd_route' );

		if ( '' === $route && is_singular( TournamentCpt::SLUG ) ) {
			$route = 'tournament';
		}

		if ( ! isset( self::ROUTE_TEMPLATES[ $route ] ) ) {
			return $template;
		}

		$located = $this->locate( self::ROUTE_TEMPLATES[ $route ] );

		return $located ?: $template;
	}

	private function locate( string $name ): ?string {
		$themeOverride = locate_template( [ 'scd-standings/' . $name . '.php' ], false );

		if ( $themeOverride ) {
			return $themeOverride;
		}

		$pluginTemplate = SCD_STANDINGS_DIR . 'templates/' . $name . '.php';

		return file_exists( $pluginTemplate ) ? $pluginTemplate : null;
	}
}
