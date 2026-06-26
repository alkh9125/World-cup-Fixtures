<?php

namespace SCD\Frontend;

use SCD\CPT\MatchCpt;
use SCD\CPT\ScenarioCpt;
use SCD\CPT\TeamCpt;
use SCD\CPT\TournamentCpt;

defined( 'ABSPATH' ) || exit;

/**
 * Loads Alpine.js (bundled locally - assets/js/vendor/alpine.min.js - so
 * the simulator/standings widgets work without an external CDN request)
 * plus this plugin's own frontend.js, only on the routes that need them.
 *
 * Load order matters: frontend.js must run *before* Alpine's cdn build
 * parses, because it registers Alpine.data() components inside an
 * `alpine:init` listener - Alpine dispatches that event itself right
 * before it walks the DOM. WP's dependency graph only guarantees a load
 * BEFORE its dependents, so the Alpine handle declares frontend.js as its
 * dependency (not the other way round), and a `defer` attribute is added
 * to the Alpine tag so it still waits for DOM-ready either way.
 */
final class Assets {

	private const ALPINE_HANDLE   = 'scd-alpine';
	private const FRONTEND_HANDLE = 'scd-frontend';
	private const STYLE_HANDLE    = 'scd-frontend-style';

	public function register(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'maybe_enqueue' ] );
		add_filter( 'script_loader_tag', [ $this, 'add_defer_attribute' ], 10, 2 );
	}

	public function maybe_enqueue(): void {
		if ( ! $this->isPluginRoute() ) {
			return;
		}

		wp_enqueue_style( self::STYLE_HANDLE, SCD_STANDINGS_URL . 'assets/css/frontend.css', [], SCD_STANDINGS_VERSION );

		wp_enqueue_script( self::FRONTEND_HANDLE, SCD_STANDINGS_URL . 'assets/js/frontend.js', [], SCD_STANDINGS_VERSION, true );
		wp_localize_script( self::FRONTEND_HANDLE, 'scdConfig', [
			'restUrl' => esc_url_raw( rest_url( 'scd/v1' ) ),
		] );

		wp_enqueue_script( self::ALPINE_HANDLE, SCD_STANDINGS_URL . 'assets/js/vendor/alpine.min.js', [ self::FRONTEND_HANDLE ], '3.14.3', true );
	}

	public function add_defer_attribute( string $tag, string $handle ): string {
		if ( self::ALPINE_HANDLE !== $handle || str_contains( $tag, ' defer' ) ) {
			return $tag;
		}

		return str_replace( ' src=', ' defer src=', $tag );
	}

	private function isPluginRoute(): bool {
		foreach ( [ TournamentCpt::SLUG, TeamCpt::SLUG, MatchCpt::SLUG, ScenarioCpt::SLUG ] as $postType ) {
			if ( is_singular( $postType ) ) {
				return true;
			}
		}

		return (bool) get_query_var( 'scd_route' );
	}
}
