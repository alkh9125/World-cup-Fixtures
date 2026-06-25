<?php

namespace SCD\Infrastructure\Repositories;

defined( 'ABSPATH' ) || exit;

final class TeamRepository {

	private string $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'scd_teams';
	}

	public function find( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ), ARRAY_A );

		return $row ?: null;
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

	public function findByExternalId( string $provider, string $externalId ): ?array {
		global $wpdb;

		$rows = $wpdb->get_results( "SELECT * FROM {$this->table} WHERE external_ids_json IS NOT NULL", ARRAY_A ) ?: [];

		foreach ( $rows as $row ) {
			$ids = json_decode( $row['external_ids_json'], true ) ?: [];
			if ( ( $ids[ $provider ] ?? null ) === $externalId ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * @param int[] $ids
	 * @return array<int,array> teamId => row
	 */
	public function findMany( array $ids ): array {
		global $wpdb;

		if ( empty( $ids ) ) {
			return [];
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE id IN ({$placeholders})", $ids ),
			ARRAY_A,
		) ?: [];

		$byId = [];
		foreach ( $rows as $row ) {
			$byId[ (int) $row['id'] ] = $row;
		}

		return $byId;
	}

	/** Teams entered in a tournament, optionally scoped to one group. */
	public function findForTournament( int $tournamentId, ?int $groupId = null ): array {
		global $wpdb;

		$teamsTable = $wpdb->prefix . 'scd_teams';
		$ttTable    = $wpdb->prefix . 'scd_tournament_teams';

		$sql = "SELECT t.*, tt.group_id, tt.seed FROM {$teamsTable} t
				INNER JOIN {$ttTable} tt ON tt.team_id = t.id
				WHERE tt.tournament_id = %d";
		$params = [ $tournamentId ];

		if ( null !== $groupId ) {
			$sql      .= ' AND tt.group_id = %d';
			$params[] = $groupId;
		}

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) ?: [];
	}

	public function insert( array $data ): int {
		global $wpdb;

		$now = current_time( 'mysql', true );
		$wpdb->insert( $this->table, array_merge( $data, [ 'created_at' => $now, 'updated_at' => $now ] ) );

		return (int) $wpdb->insert_id;
	}

	public function update( int $id, array $data ): bool {
		global $wpdb;

		$data['updated_at'] = current_time( 'mysql', true );

		return false !== $wpdb->update( $this->table, $data, [ 'id' => $id ] );
	}
}
