<?php

namespace SCD\CPT;

defined( 'ABSPATH' ) || exit;

/**
 * Editorial/SEO shell around one scd_scenarios_cache row ("what if X
 * wins" pages). URL is owned by Frontend\Rewrites, so rewrite => false
 * here. Simulator-generated states are noindexed by SEO\SeoModule and
 * canonicalised back to this page.
 */
final class ScenarioCpt {

	public const SLUG = 'scd_scenario';

	public function register(): void {
		add_action( 'init', [ $this, 'register_post_type' ] );
	}

	public function register_post_type(): void {
		register_post_type( self::SLUG, [
			'labels'              => [
				'name'          => __( 'Scenarios', 'scd-standings' ),
				'singular_name' => __( 'Scenario', 'scd-standings' ),
			],
			'public'              => true,
			'show_in_rest'        => true,
			'has_archive'         => false,
			'menu_icon'           => 'dashicons-randomize',
			'supports'            => [ 'title', 'editor', 'custom-fields' ],
			'rewrite'             => false,
			'show_in_menu'        => 'scd-standings',
		] );
	}
}
