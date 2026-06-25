<?php

namespace SCD\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * For a single group, computes - per final table position - the set of
 * teams that could still end up there given remaining fixtures. This feeds
 * BracketProjector's `group_position` rules so knockout slots can show
 * "possible opponents" before the group stage finishes.
 *
 * Uses the same bounded W/D/L enumeration as QualificationStatusResolver.
 */
final class GroupPositionCalculator {

	public function __construct( private readonly StandingsCalculator $calculator ) {}

	/**
	 * @param int[]         $teamIds
	 * @param MatchResult[] $playedMatches
	 * @param MatchResult[] $remainingMatches
	 *
	 * @return array<int,int[]> position (1-based) => possible team IDs
	 */
	public function calculate( array $teamIds, array $playedMatches, array $remainingMatches, ?int $groupId ): array {
		$remainingCount = count( $remainingMatches );

		if ( 0 === $remainingCount ) {
			$standings = $this->calculator->calculate( $playedMatches, $teamIds, $groupId );
			$result    = [];
			foreach ( $standings as $standing ) {
				$result[ $standing->rank ][] = $standing->teamId;
			}

			return $result;
		}

		if ( $remainingCount > OutcomeEnumerator::MAX_REMAINING_MATCHES ) {
			$allPositions = range( 1, count( $teamIds ) );
			return array_fill_keys( $allPositions, $teamIds );
		}

		$possibleByPosition = [];

		foreach ( OutcomeEnumerator::enumerate( $remainingMatches ) as $hypothetical ) {
			$standings = $this->calculator->calculate(
				array_merge( $playedMatches, $hypothetical ),
				$teamIds,
				$groupId,
			);

			foreach ( $standings as $standing ) {
				$possibleByPosition[ $standing->rank ][ $standing->teamId ] = true;
			}
		}

		return array_map( 'array_keys', $possibleByPosition );
	}
}
