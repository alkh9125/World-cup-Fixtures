<?php

namespace SCD\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Projects the knockout bracket from a tournament's bracket template plus
 * each group's "who could finish in position N" possibility sets.
 *
 * Bracket template shape (stored as JSON on the tournament, see
 * Infrastructure\Providers\WorldCup2026Bracket for the seed data):
 *
 *   [
 *     ['round' => 'round_of_16', 'slot_code' => 'R16-1',
 *      'home_rule' => ['type' => 'group_position', 'group' => 'A', 'position' => 1],
 *      'away_rule' => ['type' => 'group_position', 'group' => 'B', 'position' => 2]],
 *     ['round' => 'quarter_final', 'slot_code' => 'QF-1',
 *      'home_rule' => ['type' => 'winner_of', 'slot' => 'R16-1'],
 *      'away_rule' => ['type' => 'winner_of', 'slot' => 'R16-2']],
 *     ...
 *   ]
 *
 * Slots must be ordered so that any slot referenced by `winner_of`/`loser_of`
 * appears earlier in the array than the slot referencing it - the caller
 * (bracket template author) guarantees this, since it mirrors the actual
 * tournament regulations and never changes at runtime.
 *
 * `loser_of` is approximated identically to `winner_of` (union of both
 * sides' possibility sets) since, without simulating exact match outcomes,
 * the engine cannot distinguish which specific team becomes the loser -
 * this is a documented simplification appropriate for a "possible
 * opponents" feature, which only needs the possibility set, not a
 * probability-weighted single answer.
 */
final class BracketProjector {

	/**
	 * @param array<int,array{round:string, slot_code:string, home_rule:array, away_rule:array}> $bracketTemplate
	 * @param array<string,array<int,int[]>> $groupPositionPossibilities groupCode => position => possible team IDs
	 * @param array<string,int> $resolvedBySlot slot_code => actual winning team ID, once that match has been played
	 *
	 * @return array<string,array{round:string, home:array{resolved:?int,possible:int[]}, away:array{resolved:?int,possible:int[]}}>
	 */
	public function resolveSlots( array $bracketTemplate, array $groupPositionPossibilities, array $resolvedBySlot = [] ): array {
		$slotResults = [];

		foreach ( $bracketTemplate as $slot ) {
			$slotResults[ $slot['slot_code'] ] = [
				'round' => $slot['round'],
				'home'  => $this->resolveSide( $slot['home_rule'], $groupPositionPossibilities, $slotResults ),
				'away'  => $this->resolveSide( $slot['away_rule'], $groupPositionPossibilities, $slotResults ),
			];

			if ( isset( $resolvedBySlot[ $slot['slot_code'] ] ) ) {
				// A real match result overrides the projection entirely -
				// downstream slots referencing this one will read the
				// single resolved team rather than a possibility set.
				$winner = $resolvedBySlot[ $slot['slot_code'] ];
				$slotResults[ $slot['slot_code'] ]['winner_resolved'] = $winner;
			}
		}

		return $slotResults;
	}

	private function resolveSide( array $rule, array $groupPositionPossibilities, array $slotResults ): array {
		switch ( $rule['type'] ) {
			case 'group_position':
				$possible = $groupPositionPossibilities[ $rule['group'] ][ $rule['position'] ] ?? [];
				break;

			case 'winner_of':
			case 'loser_of':
				$referenced = $slotResults[ $rule['slot'] ] ?? null;

				if ( null === $referenced ) {
					$possible = [];
					break;
				}

				if ( isset( $referenced['winner_resolved'] ) ) {
					$possible = [ $referenced['winner_resolved'] ];
					break;
				}

				$possible = array_values( array_unique( array_merge(
					$referenced['home']['possible'],
					$referenced['away']['possible'],
				) ) );
				break;

			default:
				$possible = [];
		}

		return [
			'resolved' => 1 === count( $possible ) ? $possible[0] : null,
			'possible' => $possible,
		];
	}
}
