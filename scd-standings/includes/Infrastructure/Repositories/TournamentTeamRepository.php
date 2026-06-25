<?php

namespace SCD\Infrastructure\Repositories;

defined( 'ABSPATH' ) || exit;

/** Manages which group/seed each team holds within a tournament. */
final class TournamentTeamRepository {

	private string $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'scd_tournament_teams';
	}

	public function assign( int $tournamentId, int $teamId, ?int $groupId, ?int $seed = null ): void {
		global $wpdb;

		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM {$this->table} WHERE tournament_id = %d AND team_id = %d",
				$tournamentId,
				$teamId,
			),
			ARRAY_A,
		);

		$data = [ 'group_id' => $groupId, 'seed' => $seed ];

		if ( $existing ) {
			$wpdb->update( $this->table, $data, [ 'id' => $existing['id'] ] );
			return;
		}

		$wpdb->insert( $this->table, array_merge( $data, [ 'tournament_id' => $tournamentId, 'team_id' => $teamId ] ) );
	}

	/** @return int[] team IDs in a group */
	public function teamIdsForGroup( int $groupId ): array {
		global $wpdb;

		return array_map( 'intval', $wpdb->get_col(
			$wpdb->prepare( "SELECT team_id FROM {$this->table} WHERE group_id = %d", $groupId ),
		) );
	}

	public function groupIdForTeam( int $tournamentId, int $teamId ): ?int {
		global $wpdb;

		$groupId = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT group_id FROM {$this->table} WHERE tournament_id = %d AND team_id = %d",
				$tournamentId,
				$teamId,
			),
		);

		return null !== $groupId ? (int) $groupId : null;
	}

	/**
	 * A team can be entered into more than one tournament (different
	 * editions/years). Returns every (tournament_id, group_id) pairing on
	 * record, most recent assignment first; callers building a single
	 * canonical URL for a team currently take the first row.
	 *
	 * @return array<int,array{tournament_id:int, group_id:?int}>
	 */
	public function assignmentsForTeam( int $teamId ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT tournament_id, group_id FROM {$this->table} WHERE team_id = %d ORDER BY id DESC", $teamId ),
			ARRAY_A,
		) ?: [];

		return array_map(
			static fn ( array $row ) => [
				'tournament_id' => (int) $row['tournament_id'],
				'group_id'      => null !== $row['group_id'] ? (int) $row['group_id'] : null,
			],
			$rows,
		);
	}
}
