<?php

namespace SCD\Domain\TieBreak;

use SCD\Domain\MatchResult;
use SCD\Domain\Standing;

defined( 'ABSPATH' ) || exit;

/**
 * Strategy contract for tournament-specific tie-break ordering. Different
 * competitions order criteria differently (FIFA World Cup vs UEFA club
 * competitions vs domestic leagues), so the calculation engine is never
 * hardcoded to one ruleset.
 */
interface RulesetInterface {

	/**
	 * @param Standing[]     $standings Unranked rows (rank = 0).
	 * @param MatchResult[]  $allMatches All matches in scope, used for head-to-head lookups.
	 *
	 * @return Standing[] Same rows, reordered best-first. Rank is not set here;
	 *                     the caller assigns rank by array position.
	 */
	public function rank( array $standings, array $allMatches ): array;
}
