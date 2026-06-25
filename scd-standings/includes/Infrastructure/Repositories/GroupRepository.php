<?php

namespace SCD\Infrastructure\Repositories;

defined( 'ABSPATH' ) || exit;

final class GroupRepository {

	private string $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'scd_groups';
	}

	public function find( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ), ARRAY_A );

		return $row ?: null;
	}

	public function findBySlug( int $tournamentId, string $slug ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE tournament_id = %d AND slug = %s", $tournamentId, $slug ),
			ARRAY_A,
		);

		return $row ?: null;
	}

	/** @return array[] ordered by sort_order */
	public function findForTournament( int $tournamentId ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE tournament_id = %d ORDER BY sort_order ASC", $tournamentId ),
			ARRAY_A,
		) ?: [];
	}

	public function insert( array $data ): int {
		global $wpdb;
		$wpdb->insert( $this->table, $data );

		return (int) $wpdb->insert_id;
	}

	public function update( int $id, array $data ): bool {
		global $wpdb;

		return false !== $wpdb->update( $this->table, $data, [ 'id' => $id ] );
	}
}
