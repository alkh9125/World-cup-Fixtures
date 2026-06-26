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

	public function findOne( int $matchId, string $outcome ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE match_id = %d AND outcome = %s", $matchId, $outcome ),
			ARRAY_A,
		);

		return $row ?: null;
	}

	public function upsert( int $matchId, string $outcome, string $slug, string $summaryAr, string $summaryEn, array $impact, ?int $postId = null ): void {
		global $wpdb;

		$existing = $this->findOne( $matchId, $outcome );

		$data = [
			'post_id'      => $postId,
			'match_id'     => $matchId,
			'outcome'      => $outcome,
			'slug'         => $slug,
			'summary_ar'   => $summaryAr,
			'summary_en'   => $summaryEn,
			'impact_json'  => wp_json_encode( $impact ),
			'generated_at' => current_time( 'mysql', true ),
		];

		if ( $existing ) {
			if ( null === $postId ) {
				unset( $data['post_id'] ); // Keep whatever post_id was already set if the caller didn't resolve one.
			}

			$wpdb->update( $this->table, $data, [ 'id' => $existing['id'] ] );
		} else {
			$wpdb->insert( $this->table, $data );
		}
	}
}
