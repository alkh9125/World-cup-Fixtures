<?php

namespace SCD\CPT;

defined( 'ABSPATH' ) || exit;

/**
 * Editorial/SEO shell around one scd_matches row (e.g. match-impact
 * pages). URL is owned by Frontend\Rewrites, so rewrite => false here.
 */
final class MatchCpt {

	public const SLUG = 'scd_match';

	public function register(): void {
		add_action( 'init', [ $this, 'register_post_type' ] );
	}

	public function register_post_type(): void {
		register_post_type( self::SLUG, [
			'labels'              => [
				'name'          => __( 'Matches', 'scd-standings' ),
				'singular_name' => __( 'Match', 'scd-standings' ),
			],
			'public'              => true,
			'show_in_rest'        => true,
			'has_archive'         => false,
			'menu_icon'           => 'dashicons-screenoptions',
			'supports'            => [ 'title', 'editor', 'custom-fields' ],
			'rewrite'             => false,
			'show_in_menu'        => 'scd-standings',
		] );
	}
}
