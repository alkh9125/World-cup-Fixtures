<?php

namespace SCD\Infrastructure\Repositories;

defined( 'ABSPATH' ) || exit;

final class ScenarioCacheRepository {

	private string $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'scd_scenarios_cache';
	}

	/** @return array[] the 3 outcome rows for a match */
	public function findForMatch( int $matchId ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE match_id = %d", $matchId ),
			ARRAY_A,
		) ?: [];
	}

	public function findBySlug( string $slug ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE slug = %s", $slug ), ARRAY_A );

		return $row ?: null;
	}

	public function findByPostId( int $postId ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE post_id = %d", $postId ), ARRAY_A );

		return $row ?: null;
	}

	public function upsert( int $matchId, string $outcome, string $slug, string $summaryAr, string $summaryEn, array $impact ): void {
		global $wpdb;

		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT id FROM {$this->table} WHERE match_id = %d AND outcome = %s", $matchId, $outcome ),
			ARRAY_A,
		);

		$data = [
			'match_id'     => $matchId,
			'outcome'      => $outcome,
			'slug'         => $slug,
			'summary_ar'   => $summaryAr,
			'summary_en'   => $summaryEn,
			'impact_json'  => wp_json_encode( $impact ),
			'generated_at' => current_time( 'mysql', true ),
		];

		if ( $existing ) {
			$wpdb->update( $this->table, $data, [ 'id' => $existing['id'] ] );
		} else {
			$wpdb->insert( $this->table, $data );
		}
	}
}
