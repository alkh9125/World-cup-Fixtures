<?php

namespace SCD\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable row of a standings table. `rank` and `tieBreakNotes` are filled
 * in by the TieBreakResolver after raw points/GD/GF aggregation.
 */
final class Standing {

	public function __construct(
		public readonly int $teamId,
		public readonly ?int $groupId,
		public readonly int $played = 0,
		public readonly int $won = 0,
		public readonly int $drawn = 0,
		public readonly int $lost = 0,
		public readonly int $goalsFor = 0,
		public readonly int $goalsAgainst = 0,
		public readonly int $points = 0,
		public readonly int $rank = 0,
		public readonly ?string $tieBreakNote = null,
	) {}

	public function goalDifference(): int {
		return $this->goalsFor - $this->goalsAgainst;
	}

	public function withRank( int $rank, ?string $note = null ): self {
		return new self(
			$this->teamId,
			$this->groupId,
			$this->played,
			$this->won,
			$this->drawn,
			$this->lost,
			$this->goalsFor,
			$this->goalsAgainst,
			$this->points,
			$rank,
			$note ?? $this->tieBreakNote,
		);
	}

	public function toArray(): array {
		return [
			'team_id'         => $this->teamId,
			'group_id'        => $this->groupId,
			'played'          => $this->played,
			'won'             => $this->won,
			'drawn'           => $this->drawn,
			'lost'            => $this->lost,
			'goals_for'       => $this->goalsFor,
			'goals_against'   => $this->goalsAgainst,
			'goal_difference' => $this->goalDifference(),
			'points'          => $this->points,
			'rank'            => $this->rank,
			'tie_break_note'  => $this->tieBreakNote,
		];
	}
}
