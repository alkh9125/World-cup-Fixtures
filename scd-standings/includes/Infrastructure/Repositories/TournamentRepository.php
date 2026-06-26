<?php

namespace SCD\Infrastructure\Repositories;

defined( 'ABSPATH' ) || exit;

/**
 * Reads/writes the scd_tournaments table - the system of record. The
 * scd_tournament CPT (see CPT\TournamentCpt) only carries editorial/SEO
 * fields and stores this row's ID in postmeta `_scd_tournament_id`.
 */
final class TournamentRepository {

	private string $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'scd_tournaments';
	}

	public function find( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ),
			ARRAY_A,
		);

		return $row ?: null;
	}

	public function findBySlug( string $slug ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE slug = %s", $slug ),
			ARRAY_A,
		);

		return $row ?: null;
	}

	public function findByPostId( int $postId ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE post_id = %d", $postId ), ARRAY_A );

		return $row ?: null;
	}

	/** @return array[] */
	public function all(): array {
		global $wpdb;

		return $wpdb->get_results( "SELECT * FROM {$this->table} ORDER BY created_at DESC", ARRAY_A ) ?: [];
	}

	public function bracketTemplate( int $tournamentId ): array {
		$tournament = $this->find( $tournamentId );

		if ( ! $tournament || empty( $tournament['bracket_template_json'] ) ) {
			return [];
		}

		return json_decode( $tournament['bracket_template_json'], true ) ?: [];
	}

	public function insert( array $data ): int {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$wpdb->insert(
			$this->table,
			array_merge(
				$data,
				[
					'created_at' => $now,
					'updated_at' => $now,
				],
			),
		);

		return (int) $wpdb->insert_id;
	}

	public function update( int $id, array $data ): bool {
		global $wpdb;

		$data['updated_at'] = current_time( 'mysql', true );

		return false !== $wpdb->update( $this->table, $data, [ 'id' => $id ] );
	}
}
