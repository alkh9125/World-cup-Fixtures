<?php

namespace SCD\Infrastructure\Repositories;

defined( 'ABSPATH' ) || exit;

/**
 * Audit-logged manual corrections layered on top of calculated values.
 * Overrides are never destructive: applying one never mutates
 * scd_matches/scd_standings_cache directly, it is read by the
 * recalculation pipeline as the final pipeline step (see
 * Jobs\RecalculationPipeline::applyOverrides) and overlaid on the
 * computed result.
 */
final class OverrideRepository {

	private string $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'scd_overrides';
	}

	/** @return array[] active overrides for one tournament */
	public function findActiveForTournament( int $tournamentId ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE tournament_id = %d AND active = 1 ORDER BY created_at DESC",
				$tournamentId,
			),
			ARRAY_A,
		) ?: [];
	}

	public function create( int $tournamentId, string $targetType, int $targetId, string $field, $value, string $reason, int $createdBy ): int {
		global $wpdb;

		$wpdb->insert( $this->table, [
			'tournament_id' => $tournamentId,
			'target_type'   => $targetType,
			'target_id'     => $targetId,
			'field'         => $field,
			'value'         => is_scalar( $value ) ? (string) $value : wp_json_encode( $value ),
			'reason'        => $reason,
			'created_by'    => $createdBy,
			'active'        => 1,
			'created_at'    => current_time( 'mysql', true ),
		] );

		return (int) $wpdb->insert_id;
	}

	public function deactivate( int $id ): bool {
		global $wpdb;

		return false !== $wpdb->update( $this->table, [ 'active' => 0 ], [ 'id' => $id ] );
	}
}
