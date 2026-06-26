<?php

namespace SCD\Infrastructure\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * The classic 8-group, 16-slot knockout bracket (round of 16 through the
 * final) that Domain\BracketProjector consumes - see that class for the
 * exact slot shape. Pairings follow the pattern FIFA has used for every
 * 32-team World Cup draw since 1998 (1st of one group against 2nd of its
 * "mirror" group), so this is the seed data for any 8-group, 2-qualifiers-
 * per-group tournament, not just the one named in the class.
 */
final class WorldCup2026Bracket {

	/** @return array<int,array{round:string, slot_code:string, home_rule:array, away_rule:array}> */
	public static function template(): array {
		$roundOf16 = [
			[ 'R16-1', 'A', 1, 'B', 2 ],
			[ 'R16-2', 'C', 1, 'D', 2 ],
			[ 'R16-3', 'E', 1, 'F', 2 ],
			[ 'R16-4', 'G', 1, 'H', 2 ],
			[ 'R16-5', 'B', 1, 'A', 2 ],
			[ 'R16-6', 'D', 1, 'C', 2 ],
			[ 'R16-7', 'F', 1, 'E', 2 ],
			[ 'R16-8', 'H', 1, 'G', 2 ],
		];

		$slots = array_map( static fn ( array $r ) => [
			'round'     => 'round_of_16',
			'slot_code' => $r[0],
			'home_rule' => [ 'type' => 'group_position', 'group' => $r[1], 'position' => $r[2] ],
			'away_rule' => [ 'type' => 'group_position', 'group' => $r[3], 'position' => $r[4] ],
		], $roundOf16 );

		$quarterFinals = [
			[ 'QF-1', 'R16-1', 'R16-2' ],
			[ 'QF-2', 'R16-3', 'R16-4' ],
			[ 'QF-3', 'R16-5', 'R16-6' ],
			[ 'QF-4', 'R16-7', 'R16-8' ],
		];

		foreach ( $quarterFinals as $q ) {
			$slots[] = [
				'round'     => 'quarter_final',
				'slot_code' => $q[0],
				'home_rule' => [ 'type' => 'winner_of', 'slot' => $q[1] ],
				'away_rule' => [ 'type' => 'winner_of', 'slot' => $q[2] ],
			];
		}

		$semiFinals = [
			[ 'SF-1', 'QF-1', 'QF-2' ],
			[ 'SF-2', 'QF-3', 'QF-4' ],
		];

		foreach ( $semiFinals as $s ) {
			$slots[] = [
				'round'     => 'semi_final',
				'slot_code' => $s[0],
				'home_rule' => [ 'type' => 'winner_of', 'slot' => $s[1] ],
				'away_rule' => [ 'type' => 'winner_of', 'slot' => $s[2] ],
			];
		}

		$slots[] = [
			'round'     => 'third_place',
			'slot_code' => '3RD',
			'home_rule' => [ 'type' => 'loser_of', 'slot' => 'SF-1' ],
			'away_rule' => [ 'type' => 'loser_of', 'slot' => 'SF-2' ],
		];

		$slots[] = [
			'round'     => 'final',
			'slot_code' => 'FINAL',
			'home_rule' => [ 'type' => 'winner_of', 'slot' => 'SF-1' ],
			'away_rule' => [ 'type' => 'winner_of', 'slot' => 'SF-2' ],
		];

		return $slots;
	}
}
