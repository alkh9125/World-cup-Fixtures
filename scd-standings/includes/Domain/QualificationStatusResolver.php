<?php

namespace SCD\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Determines each team's qualification status by brute-force enumeration of
 * remaining group fixtures.
 *
 * Simplifying assumption (documented, not hidden): remaining matches are
 * enumerated as Win/Draw/Loss categories using placeholder scorelines
 * (1-0 / 0-0 / 0-1) rather than every possible exact scoreline. This is the
 * same approach used by most public "permutation table" sites - it
 * correctly determines points-based qualification/elimination in all cases,
 * and is conservative for the rare goal-difference-only edge cases (those
 * surface as "still_possible" rather than a falsely confident
 * qualified/eliminated call). Already-played matches keep their real scores
 * throughout, so real goal difference is never altered.
 *
 * For round-robin groups (n <= 6 remaining matches) this is at most 3^6 =
 * 729 standings recomputations per team, each over a handful of teams - well
 * within a background job's budget, never run on a page request.
 */
final class QualificationStatusResolver {

	public const STATUS_QUALIFIED       = 'qualified';
	public const STATUS_ALMOST          = 'almost_qualified';
	public const STATUS_POSSIBLE        = 'still_possible';
	public const STATUS_ELIMINATED      = 'eliminated';

	public function __construct( private readonly StandingsCalculator $calculator ) {}

	/**
	 * @param int[]         $teamIds
	 * @param MatchResult[] $playedMatches Already decided matches in this group.
	 * @param MatchResult[] $remainingMatches Not-yet-played matches in this group.
	 *
	 * @return array<int,string> teamId => status constant
	 */
	public function resolveForGroup( array $teamIds, array $playedMatches, array $remainingMatches, ?int $groupId, int $qualifiersPerGroup = 2 ): array {
		$remainingCount = count( $remainingMatches );

		if ( 0 === $remainingCount ) {
			return $this->resolveFromFinalTable( $teamIds, $playedMatches, $groupId, $qualifiersPerGroup );
		}

		if ( $remainingCount > OutcomeEnumerator::MAX_REMAINING_MATCHES ) {
			// Safety valve: should not happen for a well-formed group stage,
			// but never let a malformed import explode into billions of
			// combinations - fall back to "possible" for everyone rather
			// than hang the recalculation job.
			return array_fill_keys( $teamIds, self::STATUS_POSSIBLE );
		}

		$ranksByTeam     = array_fill_keys( $teamIds, [] );
		$selfRanksByTeam = array_fill_keys( $teamIds, [] );

		foreach ( OutcomeEnumerator::enumerate( $remainingMatches ) as $hypothetical ) {
			$standings = $this->calculator->calculate(
				array_merge( $playedMatches, $hypothetical ),
				$teamIds,
				$groupId,
			);

			$rankByTeam = [];
			foreach ( $standings as $standing ) {
				$rankByTeam[ $standing->teamId ] = $standing->rank;
			}

			foreach ( $teamIds as $teamId ) {
				$ranksByTeam[ $teamId ][] = $rankByTeam[ $teamId ] ?? PHP_INT_MAX;

				if ( $this->teamAvoidedLoss( $teamId, $hypothetical ) ) {
					$selfRanksByTeam[ $teamId ][] = $rankByTeam[ $teamId ] ?? PHP_INT_MAX;
				}
			}
		}

		$statuses = [];

		foreach ( $teamIds as $teamId ) {
			$worstCase = max( $ranksByTeam[ $teamId ] );
			$bestCase  = min( $ranksByTeam[ $teamId ] );

			if ( $worstCase <= $qualifiersPerGroup ) {
				$statuses[ $teamId ] = self::STATUS_QUALIFIED;
				continue;
			}

			if ( $bestCase > $qualifiersPerGroup ) {
				$statuses[ $teamId ] = self::STATUS_ELIMINATED;
				continue;
			}

			$selfRanks = $selfRanksByTeam[ $teamId ];
			if ( ! empty( $selfRanks ) && max( $selfRanks ) <= $qualifiersPerGroup ) {
				$statuses[ $teamId ] = self::STATUS_ALMOST;
				continue;
			}

			$statuses[ $teamId ] = self::STATUS_POSSIBLE;
		}

		return $statuses;
	}

	/**
	 * @return array<int,string>
	 */
	private function resolveFromFinalTable( array $teamIds, array $playedMatches, ?int $groupId, int $qualifiersPerGroup ): array {
		$standings = $this->calculator->calculate( $playedMatches, $teamIds, $groupId );
		$statuses  = [];

		foreach ( $standings as $standing ) {
			$statuses[ $standing->teamId ] = $standing->rank <= $qualifiersPerGroup
				? self::STATUS_QUALIFIED
				: self::STATUS_ELIMINATED;
		}

		return $statuses;
	}

	private function teamAvoidedLoss( int $teamId, array $hypotheticalMatches ): bool {
		foreach ( $hypotheticalMatches as $match ) {
			/** @var MatchResult $match */
			if ( $match->homeTeamId !== $teamId && $match->awayTeamId !== $teamId ) {
				continue;
			}

			if ( $match->homeTeamId === $teamId && $match->homeScore < $match->awayScore ) {
				return false;
			}

			if ( $match->awayTeamId === $teamId && $match->awayScore < $match->homeScore ) {
				return false;
			}
		}

		return true;
	}
}
