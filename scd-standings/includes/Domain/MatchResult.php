<?php

namespace SCD\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable value object representing one fixture's result for calculation
 * purposes. This is the only shape the domain layer understands - it has
 * no knowledge of WordPress post IDs, providers, or storage.
 */
final class MatchResult {

	public function __construct(
		public readonly int $matchId,
		public readonly int $homeTeamId,
		public readonly int $awayTeamId,
		public readonly ?int $groupId,
		public readonly string $round,
		public readonly string $status,
		public readonly ?int $homeScore,
		public readonly ?int $awayScore,
	) {}

	public function isFinished(): bool {
		return 'finished' === $this->status;
	}

	public function isDecided(): bool {
		return $this->isFinished() && null !== $this->homeScore && null !== $this->awayScore;
	}

	public function winnerTeamId(): ?int {
		if ( ! $this->isDecided() ) {
			return null;
		}

		if ( $this->homeScore > $this->awayScore ) {
			return $this->homeTeamId;
		}

		if ( $this->awayScore > $this->homeScore ) {
			return $this->awayTeamId;
		}

		return null;
	}

	/**
	 * Returns a copy of this match with a hypothetical result applied -
	 * used by the ScenarioEngine to project "what if" outcomes without
	 * mutating real data.
	 */
	public function withResult( int $homeScore, int $awayScore ): self {
		return new self(
			$this->matchId,
			$this->homeTeamId,
			$this->awayTeamId,
			$this->groupId,
			$this->round,
			'finished',
			$homeScore,
			$awayScore,
		);
	}
}
