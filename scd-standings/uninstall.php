<?php
/**
 * Runs only when the plugin is deleted from Plugins screen (not on
 * deactivation - deactivation only unschedules cron, see scd-standings.php).
 * Drops the custom tables/options that make up the system of record;
 * the CPT posts (editorial/SEO layer) are removed through the normal
 * WordPress post-deletion path since they're regular `wp_posts` rows.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$tables = [
	'scd_tournaments',
	'scd_teams',
	'scd_groups',
	'scd_tournament_teams',
	'scd_matches',
	'scd_standings_cache',
	'scd_overrides',
	'scd_bracket_slots',
	'scd_scenarios_cache',
];

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

foreach ( [ 'scd_tournament', 'scd_team', 'scd_match', 'scd_scenario' ] as $postType ) {
	$postIds = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", $postType ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	foreach ( $postIds as $postId ) {
		wp_delete_post( (int) $postId, true );
	}
}

delete_option( 'scd_db_version' );
delete_option( 'scd_flush_rewrite_rules' );
delete_option( 'scd_api_football_key' );
delete_option( 'scd_sync_interval_minutes' );
