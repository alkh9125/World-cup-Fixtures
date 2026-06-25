<?php

namespace SCD\Infrastructure\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Creates/upgrades the scd_* custom tables that hold the system of record
 * for tournaments, teams, fixtures, standings cache, overrides, and the
 * bracket/scenario caches. CPTs (registered separately) only carry the
 * editorial/SEO layer and reference rows in these tables by ID.
 */
class Schema {

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$p               = $wpdb->prefix;

		$sql = [];

		$sql[] = "CREATE TABLE {$p}scd_tournaments (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NULL,
			slug VARCHAR(191) NOT NULL,
			name VARCHAR(191) NOT NULL,
			name_ar VARCHAR(191) NULL,
			season VARCHAR(20) NULL,
			format_json LONGTEXT NULL,
			tie_break_rules_json LONGTEXT NULL,
			bracket_template_json LONGTEXT NULL,
			current_stage VARCHAR(50) NOT NULL DEFAULT 'group',
			status VARCHAR(20) NOT NULL DEFAULT 'upcoming',
			source_provider VARCHAR(50) NULL,
			external_id VARCHAR(100) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY slug (slug)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$p}scd_teams (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NULL,
			slug VARCHAR(191) NOT NULL,
			name VARCHAR(191) NOT NULL,
			name_ar VARCHAR(191) NULL,
			short_code VARCHAR(10) NULL,
			country VARCHAR(100) NULL,
			logo_url VARCHAR(255) NULL,
			rating DECIMAL(6,2) NULL,
			external_ids_json LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY slug (slug)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$p}scd_groups (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tournament_id BIGINT UNSIGNED NOT NULL,
			post_id BIGINT UNSIGNED NULL,
			slug VARCHAR(191) NOT NULL,
			name VARCHAR(100) NOT NULL,
			name_ar VARCHAR(100) NULL,
			sort_order SMALLINT NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			KEY tournament_id (tournament_id),
			UNIQUE KEY tournament_slug (tournament_id, slug)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$p}scd_tournament_teams (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tournament_id BIGINT UNSIGNED NOT NULL,
			team_id BIGINT UNSIGNED NOT NULL,
			group_id BIGINT UNSIGNED NULL,
			seed SMALLINT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY tournament_team (tournament_id, team_id),
			KEY group_id (group_id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$p}scd_matches (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NULL,
			tournament_id BIGINT UNSIGNED NOT NULL,
			group_id BIGINT UNSIGNED NULL,
			round VARCHAR(30) NOT NULL DEFAULT 'group',
			slot_code VARCHAR(30) NULL,
			match_number SMALLINT NULL,
			home_team_id BIGINT UNSIGNED NULL,
			away_team_id BIGINT UNSIGNED NULL,
			home_placeholder VARCHAR(191) NULL,
			away_placeholder VARCHAR(191) NULL,
			kickoff_at DATETIME NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'scheduled',
			home_score SMALLINT NULL,
			away_score SMALLINT NULL,
			home_score_et SMALLINT NULL,
			away_score_et SMALLINT NULL,
			home_pen SMALLINT NULL,
			away_pen SMALLINT NULL,
			venue VARCHAR(191) NULL,
			source_provider VARCHAR(50) NULL,
			external_id VARCHAR(100) NULL,
			last_synced_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY tournament_id (tournament_id),
			KEY group_id (group_id),
			KEY round (round),
			KEY status (status),
			UNIQUE KEY external_ref (source_provider, external_id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$p}scd_standings_cache (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tournament_id BIGINT UNSIGNED NOT NULL,
			group_id BIGINT UNSIGNED NULL,
			team_id BIGINT UNSIGNED NOT NULL,
			played SMALLINT NOT NULL DEFAULT 0,
			won SMALLINT NOT NULL DEFAULT 0,
			drawn SMALLINT NOT NULL DEFAULT 0,
			lost SMALLINT NOT NULL DEFAULT 0,
			goals_for SMALLINT NOT NULL DEFAULT 0,
			goals_against SMALLINT NOT NULL DEFAULT 0,
			goal_difference SMALLINT NOT NULL DEFAULT 0,
			points SMALLINT NOT NULL DEFAULT 0,
			rank SMALLINT NOT NULL DEFAULT 0,
			qualification_status VARCHAR(20) NOT NULL DEFAULT 'possible',
			tie_break_notes VARCHAR(255) NULL,
			computed_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY tournament_group_team (tournament_id, group_id, team_id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$p}scd_overrides (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tournament_id BIGINT UNSIGNED NOT NULL,
			target_type VARCHAR(30) NOT NULL,
			target_id BIGINT UNSIGNED NOT NULL,
			field VARCHAR(60) NOT NULL,
			value LONGTEXT NULL,
			reason VARCHAR(255) NULL,
			created_by BIGINT UNSIGNED NULL,
			active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY tournament_target (tournament_id, target_type, target_id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$p}scd_bracket_slots (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tournament_id BIGINT UNSIGNED NOT NULL,
			round VARCHAR(30) NOT NULL,
			slot_code VARCHAR(30) NOT NULL,
			source_rule_json LONGTEXT NULL,
			resolved_team_id BIGINT UNSIGNED NULL,
			possible_teams_json LONGTEXT NULL,
			computed_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY tournament_slot (tournament_id, slot_code)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$p}scd_scenarios_cache (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NULL,
			match_id BIGINT UNSIGNED NOT NULL,
			outcome VARCHAR(20) NOT NULL,
			slug VARCHAR(191) NOT NULL,
			summary_ar TEXT NULL,
			summary_en TEXT NULL,
			impact_json LONGTEXT NULL,
			generated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY match_outcome (match_id, outcome)
		) {$charset_collate};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		update_option( 'scd_db_version', SCD_DB_VERSION );
	}

	public static function maybe_upgrade(): void {
		if ( get_option( 'scd_db_version' ) !== SCD_DB_VERSION ) {
			self::install();
		}
	}
}
