<?php

namespace SCD\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * For one not-yet-played match, projects the three outcome categories
 * (home win / draw / away win) forward through the StandingsCalculator and
 * QualificationStatusResolver, and produces a structured diff against the
 * current (pre-match) state. This structured diff - not prose - is the
 * engine's output; NarrativeGenerator turns it into Arabic/English
 * sentences, kept as a separate concern so the diff stays testable on its
 * own.
 */
final class ScenarioEngine {

	public const OUTCOME_HOME_WIN = 'home_win';
	public const OUTCOME_DRAW     = 'draw';
	public const OUTCOME_AWAY_WIN = 'away_win';

	private const OUTCOME_SCORES = [
		self::OUTCOME_HOME_WIN => [ 1, 0 ],
		self::OUTCOME_DRAW     => [ 0, 0 ],
		self::OUTCOME_AWAY_WIN => [ 0, 1 ],
	];

	public function __construct(
		private readonly StandingsCalculator $calculator,
		private readonly QualificationStatusResolver $qualificationResolver,
	) {}

	/**
	 * @param MatchResult   $match            The match to project (must be unplayed).
	 * @param int[]         $teamIds          All teams in the match's group.
	 * @param MatchResult[] $playedMatches    Already-decided matches in the group (excludes $match).
	 * @param MatchResult[] $remainingMatches Other not-yet-played matches in the group (excludes $match).
	 *
	 * @return array<string,array{standings: Standing[], qualification: array<int,string>, changes: array}>
	 *         Keyed by OUTCOME_* constants.
	 */
	public function generateForMatch(
		MatchResult $match,
		array $teamIds,
		array $playedMatches,
		array $remainingMatches,
		?int $groupId,
		int $qualifiersPerGroup = 2,
	): array {
		$currentStandings    = $this->calculator->calculate( $playedMatches, $teamIds, $groupId );
		$currentQualification = $this->qualificationResolver->resolveForGroup(
			$teamIds,
			$playedMatches,
			array_merge( $remainingMatches, [ $match ] ),
			$groupId,
			$qualifiersPerGroup,
		);
		$currentRanks = $this->ranksByTeam( $currentStandings );

		$scenarios = [];

		foreach ( self::OUTCOME_SCORES as $outcome => [ $homeScore, $awayScore ] ) {
			$hypotheticalMatch = $match->withResult( $homeScore, $awayScore );
			$matchesWithResult = array_merge( $playedMatches, [ $hypotheticalMatch ] );

			$newStandings     = $this->calculator->calculate( $matchesWithResult, $teamIds, $groupId );
			$newQualification = $this->qualificationResolver->resolveForGroup(
				$teamIds,
				$matchesWithResult,
				$remainingMatches,
				$groupId,
				$qualifiersPerGroup,
			);
			$newRanks = $this->ranksByTeam( $newStandings );

			$scenarios[ $outcome ] = [
				'standings'     => $newStandings,
				'qualification' => $newQualification,
				'changes'       => $this->diff( $teamIds, $currentRanks, $newRanks, $currentQualification, $newQualification ),
			];
		}

		return $scenarios;
	}

	private function ranksByTeam( array $standings ): array {
		$ranks = [];
		foreach ( $standings as $standing ) {
			/** @var Standing $standing */
			$ranks[ $standing->teamId ] = $standing->rank;
		}

		return $ranks;
	}

	/**
	 * @return array<int,array{team_id:int, from_rank:int, to_rank:int, from_status:string, to_status:string}>
	 *         Only teams whose rank or qualification status actually changed.
	 */
	private function diff( array $teamIds, array $currentRanks, array $newRanks, array $currentQualification, array $newQualification ): array {
		$changes = [];

		foreach ( $teamIds as $teamId ) {
			$fromRank   = $currentRanks[ $teamId ] ?? 0;
			$toRank     = $newRanks[ $teamId ] ?? 0;
			$fromStatus = $currentQualification[ $teamId ] ?? QualificationStatusResolver::STATUS_POSSIBLE;
			$toStatus   = $newQualification[ $teamId ] ?? QualificationStatusResolver::STATUS_POSSIBLE;

			if ( $fromRank === $toRank && $fromStatus === $toStatus ) {
				continue;
			}

			$changes[] = [
				'team_id'     => $teamId,
				'from_rank'   => $fromRank,
				'to_rank'     => $toRank,
				'from_status' => $fromStatus,
				'to_status'   => $toStatus,
			];
		}

		return $changes;
	}
}
