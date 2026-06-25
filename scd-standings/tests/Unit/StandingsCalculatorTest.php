<?php

namespace SCD\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCD\Domain\MatchResult;
use SCD\Domain\StandingsCalculator;
use SCD\Domain\TieBreak\FifaRuleset;

final class StandingsCalculatorTest extends TestCase {

	public function test_calculates_points_gd_and_ranking_with_no_ties(): void {
		$calculator = new StandingsCalculator( new FifaRuleset() );

		$matches = [
			new MatchResult( 1, 1, 2, 10, 'group', 'finished', 2, 1 ), // T1 beats T2
			new MatchResult( 2, 3, 4, 10, 'group', 'finished', 1, 1 ), // T3 draws T4
			new MatchResult( 3, 1, 3, 10, 'group', 'finished', 0, 0 ), // T1 draws T3
			new MatchResult( 4, 2, 4, 10, 'group', 'finished', 3, 0 ), // T2 beats T4
			new MatchResult( 5, 1, 4, 10, 'group', 'finished', 1, 0 ), // T1 beats T4
			new MatchResult( 6, 2, 3, 10, 'group', 'finished', 2, 2 ), // T2 draws T3
		];

		$standings = $calculator->calculate( $matches, [ 1, 2, 3, 4 ], 10 );
		$byTeam    = $this->indexByTeam( $standings );

		$this->assertSame( 1, $byTeam[1]->rank );
		$this->assertSame( 7, $byTeam[1]->points );
		$this->assertSame( 2, $byTeam[1]->goalDifference() );

		$this->assertSame( 2, $byTeam[2]->rank );
		$this->assertSame( 4, $byTeam[2]->points );

		$this->assertSame( 3, $byTeam[3]->rank );
		$this->assertSame( 3, $byTeam[3]->points );

		$this->assertSame( 4, $byTeam[4]->rank );
		$this->assertSame( 1, $byTeam[4]->points );
		$this->assertSame( -4, $byTeam[4]->goalDifference() );
	}

	public function test_team_with_zero_matches_played_still_appears(): void {
		$calculator = new StandingsCalculator( new FifaRuleset() );

		$standings = $calculator->calculate( [], [ 5, 6 ], null );

		$this->assertCount( 2, $standings );
		foreach ( $standings as $standing ) {
			$this->assertSame( 0, $standing->played );
			$this->assertSame( 0, $standing->points );
		}
	}

	/** @return array<int,\SCD\Domain\Standing> */
	private function indexByTeam( array $standings ): array {
		$result = [];
		foreach ( $standings as $standing ) {
			$result[ $standing->teamId ] = $standing;
		}

		return $result;
	}
}
