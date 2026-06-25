<?php

namespace SCD\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCD\Domain\MatchResult;
use SCD\Domain\QualificationStatusResolver;
use SCD\Domain\ScenarioEngine;
use SCD\Domain\StandingsCalculator;
use SCD\Domain\TieBreak\FifaRuleset;

/**
 * Reuses the QualificationStatusResolverTest fixture: T1 is already
 * qualified and done; T3 is already eliminated and done; the T2 vs T4
 * match is the only one left and decides the second qualifying spot.
 */
final class ScenarioEngineTest extends TestCase {

	private function engine(): ScenarioEngine {
		$calculator = new StandingsCalculator( new FifaRuleset() );
		return new ScenarioEngine( $calculator, new QualificationStatusResolver( $calculator ) );
	}

	private function played(): array {
		return [
			new MatchResult( 1, 1, 2, 1, 'group', 'finished', 1, 0 ),
			new MatchResult( 2, 1, 3, 1, 'group', 'finished', 1, 0 ),
			new MatchResult( 3, 1, 4, 1, 'group', 'finished', 1, 0 ),
			new MatchResult( 4, 2, 3, 1, 'group', 'finished', 2, 0 ),
			new MatchResult( 5, 4, 3, 1, 'group', 'finished', 1, 0 ),
		];
	}

	public function test_home_win_outcome_flips_t2_to_qualified_and_t4_to_eliminated(): void {
		$match = new MatchResult( 6, 2, 4, 1, 'group', 'scheduled', null, null );

		$scenarios = $this->engine()->generateForMatch( $match, [ 1, 2, 3, 4 ], $this->played(), [], 1, 2 );

		$changes = $this->changesByTeam( $scenarios[ ScenarioEngine::OUTCOME_HOME_WIN ]['changes'] );

		$this->assertSame( QualificationStatusResolver::STATUS_QUALIFIED, $changes[2]['to_status'] );
		$this->assertSame( QualificationStatusResolver::STATUS_ELIMINATED, $changes[4]['to_status'] );
		$this->assertArrayNotHasKey( 1, $changes, 'T1 status/rank does not change - must not appear in the diff' );
	}

	public function test_away_win_outcome_flips_t4_to_qualified_and_t2_to_eliminated(): void {
		$match = new MatchResult( 6, 2, 4, 1, 'group', 'scheduled', null, null );

		$scenarios = $this->engine()->generateForMatch( $match, [ 1, 2, 3, 4 ], $this->played(), [], 1, 2 );

		$changes = $this->changesByTeam( $scenarios[ ScenarioEngine::OUTCOME_AWAY_WIN ]['changes'] );

		$this->assertSame( QualificationStatusResolver::STATUS_ELIMINATED, $changes[2]['to_status'] );
		$this->assertSame( QualificationStatusResolver::STATUS_QUALIFIED, $changes[4]['to_status'] );
	}

	public function test_draw_outcome_also_resolves_the_group_in_t2_favour_on_goal_difference(): void {
		$match = new MatchResult( 6, 2, 4, 1, 'group', 'scheduled', null, null );

		$scenarios = $this->engine()->generateForMatch( $match, [ 1, 2, 3, 4 ], $this->played(), [], 1, 2 );

		$changes = $this->changesByTeam( $scenarios[ ScenarioEngine::OUTCOME_DRAW ]['changes'] );

		$this->assertSame( QualificationStatusResolver::STATUS_QUALIFIED, $changes[2]['to_status'] );
		$this->assertSame( QualificationStatusResolver::STATUS_ELIMINATED, $changes[4]['to_status'] );
	}

	private function changesByTeam( array $changes ): array {
		$result = [];
		foreach ( $changes as $change ) {
			$result[ $change['team_id'] ] = $change;
		}

		return $result;
	}
}
