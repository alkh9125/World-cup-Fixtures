<?php

namespace SCD\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCD\Domain\BracketProjector;

final class BracketProjectorTest extends TestCase {

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

	public function test_resolves_group_position_slots_directly(): void {
		$slots = ( new BracketProjector() )->resolveSlots( $this->template(), $this->groupPositions() );

		$this->assertSame( 101, $slots['R16-1']['home']['resolved'] );
		$this->assertSame( 202, $slots['R16-1']['away']['resolved'] );

		$this->assertSame( 201, $slots['R16-2']['home']['resolved'] );
		$this->assertNull( $slots['R16-2']['away']['resolved'] );
		$this->assertSame( [ 102, 103 ], $slots['R16-2']['away']['possible'] );
	}

	public function test_propagates_winner_of_as_union_of_referenced_possibilities(): void {
		$slots = ( new BracketProjector() )->resolveSlots( $this->template(), $this->groupPositions() );

		$this->assertSame( [ 101, 202 ], $slots['QF-1']['home']['possible'] );
		$this->assertEqualsCanonicalizing( [ 201, 102, 103 ], $slots['QF-1']['away']['possible'] );
		$this->assertNull( $slots['QF-1']['away']['resolved'] );
	}

	public function test_resolved_slot_override_collapses_downstream_possibilities(): void {
		$slots = ( new BracketProjector() )->resolveSlots(
			$this->template(),
			$this->groupPositions(),
			[ 'R16-1' => 101 ], // the actual match was played and T101 won
		);

		$this->assertSame( [ 101 ], $slots['QF-1']['home']['possible'] );
		$this->assertSame( 101, $slots['QF-1']['home']['resolved'] );
	}
}
