<?php

namespace SCD\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCD\Domain\MatchResult;
use SCD\Domain\QualificationStatusResolver;
use SCD\Domain\StandingsCalculator;
use SCD\Domain\TieBreak\FifaRuleset;

/**
 * Fixture used by this test (also reused by ScenarioEngineTest):
 *
 *   T1 beat T2 1-0, T1 beat T3 1-0, T1 beat T4 1-0   (T1 already on 9 pts, done)
 *   T2 beat T3 2-0                                    (T2 on 3 pts, 1 match left: vs T4)
 *   T4 beat T3 1-0                                    (T4 on 3 pts, 1 match left: vs T2)
 *   T3 has played all 3 matches, 0 pts                 (mathematically eliminated)
 *   Remaining: T2 vs T4 - decides the second qualifying spot.
 *
 * Hand-verified outcomes of T2 vs T4 (top 2 of the group qualify):
 *   T2 win  -> T1=9(1) T2=6(2) T4=3(3) T3=0(4)
 *   draw    -> T1=9(1) T2=4(2) T4=4(3, behind on GD) T3=0(4)
 *   T4 win  -> T1=9(1) T4=6(2) T2=3(3) T3=0(4)
 *
 * So: T1 qualified outright; T2 qualifies in 2 of 3 outcomes and never
 * loses in those 2 (controls its own destiny) -> almost_qualified; T4
 * qualifies in 1 of 3 and is eliminated when it merely draws -> still
 * contested but does NOT control its own destiny -> still_possible; T3
 * never reaches top 2 -> eliminated.
 */
final class QualificationStatusResolverTest extends TestCase {

	public function test_resolves_all_four_statuses_in_one_group(): void {
		$resolver = new QualificationStatusResolver( new StandingsCalculator( new FifaRuleset() ) );

		$played = [
			new MatchResult( 1, 1, 2, 1, 'group', 'finished', 1, 0 ),
			new MatchResult( 2, 1, 3, 1, 'group', 'finished', 1, 0 ),
			new MatchResult( 3, 1, 4, 1, 'group', 'finished', 1, 0 ),
			new MatchResult( 4, 2, 3, 1, 'group', 'finished', 2, 0 ),
			new MatchResult( 5, 4, 3, 1, 'group', 'finished', 1, 0 ),
		];

		$remaining = [
			new MatchResult( 6, 2, 4, 1, 'group', 'scheduled', null, null ),
		];

		$statuses = $resolver->resolveForGroup( [ 1, 2, 3, 4 ], $played, $remaining, 1, 2 );

		$this->assertSame( QualificationStatusResolver::STATUS_QUALIFIED, $statuses[1] );
		$this->assertSame( QualificationStatusResolver::STATUS_ALMOST, $statuses[2] );
		$this->assertSame( QualificationStatusResolver::STATUS_ELIMINATED, $statuses[3] );
		$this->assertSame( QualificationStatusResolver::STATUS_POSSIBLE, $statuses[4] );
	}

	public function test_no_remaining_matches_resolves_directly_from_final_table(): void {
		$resolver = new QualificationStatusResolver( new StandingsCalculator( new FifaRuleset() ) );

		$played = [
			new MatchResult( 1, 1, 2, 1, 'group', 'finished', 3, 0 ),
			new MatchResult( 2, 3, 4, 1, 'group', 'finished', 0, 3 ),
		];

		$statuses = $resolver->resolveForGroup( [ 1, 2, 3, 4 ], $played, [], 1, 2 );

		$this->assertSame( QualificationStatusResolver::STATUS_QUALIFIED, $statuses[1] );
		$this->assertSame( QualificationStatusResolver::STATUS_QUALIFIED, $statuses[4] );
		$this->assertSame( QualificationStatusResolver::STATUS_ELIMINATED, $statuses[2] );
		$this->assertSame( QualificationStatusResolver::STATUS_ELIMINATED, $statuses[3] );
	}
}
