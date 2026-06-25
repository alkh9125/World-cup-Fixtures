<?php

namespace SCD\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCD\Domain\BracketProjector;
use SCD\Domain\OpponentFinder;

final class OpponentFinderTest extends TestCase {

	private function template(): array {
		return [
			[
				'round'     => 'round_of_16',
				'slot_code' => 'R16-1',
				'home_rule' => [ 'type' => 'group_position', 'group' => 'A', 'position' => 1 ],
				'away_rule' => [ 'type' => 'group_position', 'group' => 'B', 'position' => 2 ],
			],
			[
				'round'     => 'round_of_16',
				'slot_code' => 'R16-2',
				'home_rule' => [ 'type' => 'group_position', 'group' => 'B', 'position' => 1 ],
				'away_rule' => [ 'type' => 'group_position', 'group' => 'A', 'position' => 2 ],
			],
			[
				'round'     => 'quarter_final',
				'slot_code' => 'QF-1',
				'home_rule' => [ 'type' => 'winner_of', 'slot' => 'R16-1' ],
				'away_rule' => [ 'type' => 'winner_of', 'slot' => 'R16-2' ],
			],
		];
	}

	private function groupPositions(): array {
		return [
			'A' => [ 1 => [ 101 ], 2 => [ 102, 103 ] ],
			'B' => [ 1 => [ 201 ], 2 => [ 202 ] ],
		];
	}

	public function test_finds_single_certain_opponent_for_group_winner(): void {
		$slots    = ( new BracketProjector() )->resolveSlots( $this->template(), $this->groupPositions() );
		$opponents = ( new OpponentFinder() )->find( 101, $this->template(), $slots, 'round_of_16' );

		$this->assertCount( 1, $opponents );
		$this->assertSame( 202, $opponents[0]['opponent_team_id'] );
		$this->assertSame( [ [ 'type' => 'group_position', 'group' => 'A', 'position' => 1 ] ], $opponents[0]['own_conditions'] );
		$this->assertSame( [ [ 'type' => 'group_position', 'group' => 'B', 'position' => 2 ] ], $opponents[0]['opponent_conditions'] );
	}

	public function test_finds_conditional_opponent_for_uncertain_runner_up(): void {
		$slots    = ( new BracketProjector() )->resolveSlots( $this->template(), $this->groupPositions() );
		$opponents = ( new OpponentFinder() )->find( 102, $this->template(), $slots, 'round_of_16' );

		$this->assertCount( 1, $opponents );
		$this->assertSame( 201, $opponents[0]['opponent_team_id'] );
		$this->assertSame( [ [ 'type' => 'group_position', 'group' => 'A', 'position' => 2 ] ], $opponents[0]['own_conditions'] );
	}

	public function test_quarter_final_opponents_are_described_one_hop_via_win_match(): void {
		$slots    = ( new BracketProjector() )->resolveSlots( $this->template(), $this->groupPositions() );
		$opponents = ( new OpponentFinder() )->find( 101, $this->template(), $slots, 'quarter_final' );

		$this->assertCount( 3, $opponents ); // union of R16-2 possibilities: 201, 102, 103
		$opponentIds = array_column( $opponents, 'opponent_team_id' );
		$this->assertEqualsCanonicalizing( [ 201, 102, 103 ], $opponentIds );

		foreach ( $opponents as $opponent ) {
			$this->assertSame( [ [ 'type' => 'win_match', 'slot_code' => 'R16-1' ] ], $opponent['own_conditions'] );
			$this->assertSame( [ [ 'type' => 'win_match', 'slot_code' => 'R16-2' ] ], $opponent['opponent_conditions'] );
		}
	}
}
