<?php

namespace SCD\CPT;

defined( 'ABSPATH' ) || exit;

/**
 * Editorial/SEO shell around one scd_tournaments row (see
 * Infrastructure\Repositories\TournamentRepository for the real data).
 * Lives at the site root, e.g. /club-world-cup/.
 */
final class TournamentCpt {

	public const SLUG = 'scd_tournament';

	public function register(): void {
		add_action( 'init', [ $this, 'register_post_type' ] );
	}

	public function register_post_type(): void {
		register_post_type( self::SLUG, [
			'labels'              => [
				'name'          => __( 'Tournaments', 'scd-standings' ),
				'singular_name' => __( 'Tournament', 'scd-standings' ),
			],
			'public'              => true,
			'show_in_rest'        => true,
			'has_archive'         => false,
			'menu_icon'           => 'dashicons-awards',
			'supports'            => [ 'title', 'editor', 'thumbnail', 'custom-fields' ],
			'rewrite'             => [ 'slug' => '', 'with_front' => false ],
			'show_in_menu'        => 'scd-standings',
		] );
	}
}
