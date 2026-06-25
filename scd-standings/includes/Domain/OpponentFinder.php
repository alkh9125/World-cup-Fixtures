<?php

namespace SCD\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Given a team and the resolved bracket (BracketProjector output), lists
 * every possible opponent at a given round plus the conditions - for both
 * the team and the candidate opponent - required for that matchup to occur.
 *
 * Conditions are reported one hop deep (e.g. "must finish 1st in Group A"
 * for a round-of-16 slot, or "must win their round-of-16 match" for a
 * quarter-final slot) rather than fully unrolled back to the group stage.
 * Unrolling every multi-round path back to group positions produces a
 * combinatorial AND/OR tree that is precise but unreadable; one-hop
 * conditions match how broadcasters and the example in the product spec
 * ("Requires: Finish first, Benfica finishes second") phrase it, and stay
 * bounded regardless of bracket depth.
 */
final class OpponentFinder {

	/**
	 * @param array<int,array{round:string, slot_code:string, home_rule:array, away_rule:array}> $bracketTemplate
	 * @param array<string,array{round:string, home:array{resolved:?int,possible:int[]}, away:array{resolved:?int,possible:int[]}}> $slotResults
	 *
	 * @return array<int,array{opponent_team_id:int, slot_code:string, own_conditions:array, opponent_conditions:array}>
	 */
	public function find( int $teamId, array $bracketTemplate, array $slotResults, string $round ): array {
		$rulesBySlot = [];
		foreach ( $bracketTemplate as $slot ) {
			$rulesBySlot[ $slot['slot_code'] ] = $slot;
		}

		$matches = [];

		foreach ( $slotResults as $slotCode => $slot ) {
			if ( $slot['round'] !== $round ) {
				continue;
			}

			if ( in_array( $teamId, $slot['home']['possible'], true ) ) {
				$ownSide      = 'home';
				$opponentSide = 'away';
			} elseif ( in_array( $teamId, $slot['away']['possible'], true ) ) {
				$ownSide      = 'away';
				$opponentSide = 'home';
			} else {
				continue;
			}

			$rule = $rulesBySlot[ $slotCode ] ?? null;
			if ( null === $rule ) {
				continue;
			}

			$ownConditions = $this->describeCondition( $rule[ $ownSide . '_rule' ] );

			foreach ( $slot[ $opponentSide ]['possible'] as $opponentTeamId ) {
				if ( $opponentTeamId === $teamId ) {
					continue;
				}

				$matches[] = [
					'opponent_team_id'    => $opponentTeamId,
					'slot_code'           => $slotCode,
					'own_conditions'      => $ownConditions,
					'opponent_conditions' => $this->describeCondition( $rule[ $opponentSide . '_rule' ] ),
				];
			}
		}

		return $matches;
	}

	private function describeCondition( array $rule ): array {
		return match ( $rule['type'] ) {
			'group_position' => [ [ 'type' => 'group_position', 'group' => $rule['group'], 'position' => $rule['position'] ] ],
			'winner_of'       => [ [ 'type' => 'win_match', 'slot_code' => $rule['slot'] ] ],
			'loser_of'        => [ [ 'type' => 'lose_match', 'slot_code' => $rule['slot'] ] ],
			default           => [],
		};
	}
}
