<?php

namespace SCD\Infrastructure\Repositories;

defined( 'ABSPATH' ) || exit;

final class BracketSlotRepository {

	private string $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'scd_bracket_slots';
	}

	/** @return array[] */
	public function findForTournament( int $tournamentId ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE tournament_id = %d", $tournamentId ),
			ARRAY_A,
		) ?: [];
	}

	/**
	 * @param array<string,array{round:string, home:array, away:array}> $slotResults BracketProjector output
	 */
	public function replaceForTournament( int $tournamentId, array $slotResults ): void {
		global $wpdb;

		$wpdb->delete( $this->table, [ 'tournament_id' => $tournamentId ] );

		$now = current_time( 'mysql', true );

		foreach ( $slotResults as $slotCode => $slot ) {
			$wpdb->insert( $this->table, [
				'tournament_id'        => $tournamentId,
				'round'                => $slot['round'],
				'slot_code'            => $slotCode,
				'possible_teams_json'  => wp_json_encode( [
					'home' => $slot['home'],
					'away' => $slot['away'],
				] ),
				'resolved_team_id'     => $slot['winner_resolved'] ?? null,
				'computed_at'          => $now,
			] );
		}
	}
}
