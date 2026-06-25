<?php

namespace SCD\Domain;

use SCD\Domain\TieBreak\RulesetInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Pure aggregation of finished MatchResults into Standing rows, then ranked
 * by the supplied tie-break ruleset. No I/O, no WordPress, fully
 * deterministic given the same inputs - this is what makes it unit-testable
 * and reusable by both the live recalculation pipeline and the scenario
 * engine's hypothetical projections.
 *
 * @param int[] $teamIds Teams expected in this table, including those with
 *                        zero matches played (so a 0-played team still
 *                        appears with 0 points rather than being omitted).
 */
final class StandingsCalculator {

	public function __construct( private readonly RulesetInterface $ruleset ) {}

	/**
	 * @param MatchResult[] $matches
	 * @param int[]         $teamIds
	 *
	 * @return Standing[] Ranked, best (rank 1) first.
	 */
	public function calculate( array $matches, array $teamIds, ?int $groupId = null ): array {
		$rows = [];

		foreach ( $teamIds as $teamId ) {
			$rows[ $teamId ] = new Standing( $teamId, $groupId );
		}

		foreach ( $matches as $match ) {
			if ( ! $match->isDecided() ) {
				continue;
			}

			if ( ! isset( $rows[ $match->homeTeamId ] ) || ! isset( $rows[ $match->awayTeamId ] ) ) {
				continue;
			}

			$rows[ $match->homeTeamId ] = $this->applyResult(
				$rows[ $match->homeTeamId ],
				$match->homeScore,
				$match->awayScore,
			);

			$rows[ $match->awayTeamId ] = $this->applyResult(
				$rows[ $match->awayTeamId ],
				$match->awayScore,
				$match->homeScore,
			);
		}

		$ranked = $this->ruleset->rank( array_values( $rows ), $matches );

		$position = 0;
		return array_map(
			static function ( Standing $standing ) use ( &$position ): Standing {
				++$position;
				return $standing->withRank( $position, $standing->tieBreakNote );
			},
			$ranked,
		);
	}

	private function applyResult( Standing $row, int $scored, int $conceded ): Standing {
		$won   = $row->won;
		$drawn = $row->drawn;
		$lost  = $row->lost;

		if ( $scored > $conceded ) {
			++$won;
		} elseif ( $scored === $conceded ) {
			++$drawn;
		} else {
			++$lost;
		}

		return new Standing(
			teamId:       $row->teamId,
			groupId:      $row->groupId,
			played:       $row->played + 1,
			won:          $won,
			drawn:        $drawn,
			lost:         $lost,
			goalsFor:     $row->goalsFor + $scored,
			goalsAgainst: $row->goalsAgainst + $conceded,
			points:       ( $won * 3 ) + $drawn,
		);
	}
}
