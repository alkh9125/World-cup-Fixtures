<?php

namespace SCD\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCD\Domain\MatchResult;
use SCD\Domain\StandingsCalculator;
use SCD\Domain\TieBreak\FifaRuleset;

final class FifaRulesetTest extends TestCase {

	public function test_head_to_head_breaks_a_points_gd_gf_tie(): void {
		$calculator = new StandingsCalculator( new FifaRuleset() );

		// T10 and T20 finish level on points/GD/GF (3 pts, GD 0, GF 1 each)
		// via different opponents (T88/T99), so only their head-to-head
		// match (T10 beat T20 1-0) decides the order between them. T88/T99
		// are deliberately NOT tied with T10/T20, to keep the cluster to
		// exactly the two teams being tested.
		$matches = [
			new MatchResult( 1, 10, 20, 1, 'group', 'finished', 1, 0 ), // T10 beats T20 head-to-head
			new MatchResult( 2, 10, 88, 1, 'group', 'finished', 0, 1 ), // T10 loses to T88
			new MatchResult( 3, 20, 99, 1, 'group', 'finished', 1, 0 ), // T20 beats T99
		];

		$standings = $calculator->calculate( $matches, [ 10, 20, 88, 99 ], 1 );
		$byTeam    = [];
		foreach ( $standings as $s ) {
			$byTeam[ $s->teamId ] = $s;
		}

		$this->assertSame( 3, $byTeam[10]->points );
		$this->assertSame( 3, $byTeam[20]->points );
		$this->assertSame( 0, $byTeam[10]->goalDifference() );
		$this->assertSame( 0, $byTeam[20]->goalDifference() );

		$this->assertLessThan( $byTeam[20]->rank, $byTeam[10]->rank, 'T10 must rank above T20 due to head-to-head win' );
		$this->assertNull( $byTeam[10]->tieBreakNote );
		$this->assertNull( $byTeam[20]->tieBreakNote );
	}

	public function test_unresolvable_tie_is_flagged_for_drawing_of_lots(): void {
		$calculator = new StandingsCalculator( new FifaRuleset() );

		// T5/T6 drew their only match 1-1 - identical points/GD/GF and an
		// identical head-to-head sub-table, so no automated criterion can
		// separate them; this must be flagged, not silently ordered.
		$matches = [
			new MatchResult( 1, 5, 6, 2, 'group', 'finished', 1, 1 ),
		];

		$standings = $calculator->calculate( $matches, [ 5, 6 ], 2 );

		foreach ( $standings as $standing ) {
			$this->assertSame( 'drawing_of_lots', $standing->tieBreakNote );
		}
	}
}
