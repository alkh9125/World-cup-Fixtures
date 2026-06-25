<?php

namespace SCD\Domain\TieBreak;

use SCD\Domain\MatchResult;
use SCD\Domain\Standing;

defined( 'ABSPATH' ) || exit;

/**
 * FIFA group-stage tie-break order:
 *   1. Points
 *   2. Goal difference
 *   3. Goals scored
 *   4. Head-to-head points among tied teams
 *   5. Head-to-head goal difference among tied teams
 *   6. Head-to-head goals scored among tied teams
 *   7. Drawing of lots (flagged, not auto-resolved - this is the one
 *      criterion that cannot be computed; an admin override resolves it
 *      once the federation publishes the actual draw result).
 *
 * Fair-play/disciplinary points are intentionally omitted from automated
 * ranking since this system does not ingest card data; ties that would be
 * broken by fair play surface as "drawing of lots" and are resolvable via
 * admin override, never silently guessed.
 */
final class FifaRuleset implements RulesetInterface {

	public function rank( array $standings, array $allMatches ): array {
		usort( $standings, fn ( Standing $a, Standing $b ) => $this->primaryCompare( $b, $a ) );

		$result  = [];
		$cluster = [];

		$flush = function () use ( &$cluster, &$result, $allMatches ): void {
			if ( empty( $cluster ) ) {
				return;
			}

			$result = array_merge( $result, $this->resolveCluster( $cluster, $allMatches ) );
			$cluster = [];
		};

		foreach ( $standings as $standing ) {
			if ( empty( $cluster ) || $this->primaryCompare( $cluster[0], $standing ) === 0 ) {
				$cluster[] = $standing;
				continue;
			}

			$flush();
			$cluster[] = $standing;
		}

		$flush();

		return $result;
	}

	private function primaryCompare( Standing $a, Standing $b ): int {
		return [ $a->points, $a->goalDifference(), $a->goalsFor ]
			<=> [ $b->points, $b->goalDifference(), $b->goalsFor ];
	}

	/**
	 * @param Standing[]    $cluster Teams tied on points/GD/GF.
	 * @param MatchResult[] $allMatches
	 *
	 * @return Standing[]
	 */
	private function resolveCluster( array $cluster, array $allMatches ): array {
		if ( count( $cluster ) === 1 ) {
			return $cluster;
		}

		$teamIds = array_map( static fn ( Standing $s ) => $s->teamId, $cluster );

		$h2hMatches = array_values( array_filter(
			$allMatches,
			static fn ( MatchResult $m ) => $m->isDecided()
				&& in_array( $m->homeTeamId, $teamIds, true )
				&& in_array( $m->awayTeamId, $teamIds, true ),
		) );

		$h2h = [];
		foreach ( $cluster as $standing ) {
			$h2h[ $standing->teamId ] = [ 'points' => 0, 'gf' => 0, 'ga' => 0 ];
		}

		foreach ( $h2hMatches as $match ) {
			$home = &$h2h[ $match->homeTeamId ];
			$away = &$h2h[ $match->awayTeamId ];

			$home['gf'] += $match->homeScore;
			$home['ga'] += $match->awayScore;
			$away['gf'] += $match->awayScore;
			$away['ga'] += $match->homeScore;

			if ( $match->homeScore > $match->awayScore ) {
				$home['points'] += 3;
			} elseif ( $match->homeScore < $match->awayScore ) {
				$away['points'] += 3;
			} else {
				$home['points'] += 1;
				$away['points'] += 1;
			}
		}

		usort( $cluster, static function ( Standing $a, Standing $b ) use ( $h2h ): int {
			$aRow = $h2h[ $a->teamId ];
			$bRow = $h2h[ $b->teamId ];

			$aKey = [ $aRow['points'], $aRow['gf'] - $aRow['ga'], $aRow['gf'] ];
			$bKey = [ $bRow['points'], $bRow['gf'] - $bRow['ga'], $bRow['gf'] ];

			return $bKey <=> $aKey;
		} );

		// Re-detect ties remaining after head-to-head (e.g. 3-way cycles or
		// teams that haven't played each other yet) and flag them rather
		// than guessing an order.
		$stillTied = [];
		foreach ( $cluster as $standing ) {
			$row = $h2h[ $standing->teamId ];
			$key = implode( '|', [ $row['points'], $row['gf'] - $row['ga'], $row['gf'] ] );
			$stillTied[ $key ][] = $standing->teamId;
		}

		return array_map(
			static function ( Standing $standing ) use ( $stillTied ): Standing {
				foreach ( $stillTied as $teamIdsInGroup ) {
					if ( count( $teamIdsInGroup ) > 1 && in_array( $standing->teamId, $teamIdsInGroup, true ) ) {
						return $standing->withRank( 0, 'drawing_of_lots' );
					}
				}

				return $standing;
			},
			$cluster,
		);
	}
}
