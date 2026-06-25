<?php

namespace SCD\CPT;

defined( 'ABSPATH' ) || exit;

/**
 * Editorial/SEO shell around one scd_teams row. URL is owned by
 * Frontend\Rewrites (nested under its tournament's group), so this CPT
 * registers with rewrite => false.
 */
final class TeamCpt {

	public const SLUG = 'scd_team';

	public function register(): void {
		add_action( 'init', [ $this, 'register_post_type' ] );
	}

	public function register_post_type(): void {
		register_post_type( self::SLUG, [
			'labels'              => [
				'name'          => __( 'Teams', 'scd-standings' ),
				'singular_name' => __( 'Team', 'scd-standings' ),
			],
			'public'              => true,
			'show_in_rest'        => true,
			'has_archive'         => false,
			'menu_icon'           => 'dashicons-groups',
			'supports'            => [ 'title', 'editor', 'thumbnail', 'custom-fields' ],
			'rewrite'             => false,
			'show_in_menu'        => 'scd-standings',
		] );
	}
}
