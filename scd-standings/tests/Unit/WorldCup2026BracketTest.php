<?php

namespace SCD\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCD\Domain\BracketProjector;
use SCD\Infrastructure\Providers\WorldCup2026Bracket;

final class WorldCup2026BracketTest extends TestCase {

	public function test_template_has_the_16_slots_of_a_32_team_knockout(): void {
		$template = WorldCup2026Bracket::template();

		$this->assertCount( 16, $template );
		$this->assertSame(
			[ 'R16-1', 'R16-2', 'R16-3', 'R16-4', 'R16-5', 'R16-6', 'R16-7', 'R16-8', 'QF-1', 'QF-2', 'QF-3', 'QF-4', 'SF-1', 'SF-2', '3RD', 'FINAL' ],
			array_column( $template, 'slot_code' ),
		);
	}

	public function test_every_winner_or_loser_of_rule_references_an_earlier_slot(): void {
		$template = WorldCup2026Bracket::template();
		$seen     = [];

		foreach ( $template as $slot ) {
			foreach ( [ $slot['home_rule'], $slot['away_rule'] ] as $rule ) {
				if ( in_array( $rule['type'], [ 'winner_of', 'loser_of' ], true ) ) {
					$this->assertArrayHasKey( $rule['slot'], $seen, "{$rule['slot']} must be resolved before {$slot['slot_code']}" );
				}
			}

			$seen[ $slot['slot_code'] ] = true;
		}
	}

	public function test_projector_resolves_the_full_template_without_error(): void {
		$groupPositions = [];

		foreach ( range( 'A', 'H' ) as $letter ) {
			$groupPositions[ $letter ] = [ 1 => [ 1 ], 2 => [ 2 ] ];
		}

		$slots = ( new BracketProjector() )->resolveSlots( WorldCup2026Bracket::template(), $groupPositions );

		$this->assertArrayHasKey( 'FINAL', $slots );
		$this->assertArrayHasKey( '3RD', $slots );
	}
}
