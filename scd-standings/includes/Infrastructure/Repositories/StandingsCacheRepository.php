<?php

namespace SCD\Infrastructure\Repositories;

use SCD\Domain\Standing;

defined( 'ABSPATH' ) || exit;

/**
 * Persists StandingsCalculator output. This table is fully derived and
 * rebuildable at any time from scd_matches - it exists purely so page
 * requests can read a table without recomputing it, not as a second
 * source of truth.
 */
final class StandingsCacheRepository {

	private string $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'scd_standings_cache';
	}

	/** @return array[] ordered by rank */
	public function findForGroup( int $tournamentId, ?int $groupId ): array {
		global $wpdb;

		$sql    = "SELECT * FROM {$this->table} WHERE tournament_id = %d AND group_id " . ( null === $groupId ? 'IS NULL' : '= %d' ) . ' ORDER BY rank ASC';
		$params = null === $groupId ? [ $tournamentId ] : [ $tournamentId, $groupId ];

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) ?: [];
	}

	/**
	 * Replaces the cached table for one group atomically.
	 *
	 * @param Standing[] $standings
	 */
	public function replaceForGroup( int $tournamentId, ?int $groupId, array $standings, array $qualificationByTeam ): void {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table} WHERE tournament_id = %d AND group_id " . ( null === $groupId ? 'IS NULL' : '= %d' ),
				...( null === $groupId ? [ $tournamentId ] : [ $tournamentId, $groupId ] ),
			),
		);

		foreach ( $standings as $standing ) {
			$wpdb->insert( $this->table, [
				'tournament_id'         => $tournamentId,
				'group_id'              => $groupId,
				'team_id'               => $standing->teamId,
				'played'                => $standing->played,
				'won'                   => $standing->won,
				'drawn'                 => $standing->drawn,
				'lost'                  => $standing->lost,
				'goals_for'             => $standing->goalsFor,
				'goals_against'         => $standing->goalsAgainst,
				'goal_difference'       => $standing->goalDifference(),
				'points'                => $standing->points,
				'rank'                  => $standing->rank,
				'qualification_status'  => $qualificationByTeam[ $standing->teamId ] ?? 'still_possible',
				'tie_break_notes'       => $standing->tieBreakNote,
				'computed_at'           => $now,
			] );
		}
	}
}
