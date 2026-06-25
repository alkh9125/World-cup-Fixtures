<?php

namespace SCD\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Shared W/D/L combination enumerator used by QualificationStatusResolver
 * and GroupPositionCalculator so both brute-force searches stay consistent
 * and bounded.
 */
final class OutcomeEnumerator {

	public const MAX_REMAINING_MATCHES = 10;

	private const PLACEHOLDER_SCORES = [ [ 1, 0 ], [ 0, 0 ], [ 0, 1 ] ];

	/**
	 * Yields one hypothetical MatchResult[] per outcome combination, decoding
	 * the combination index as a base-3 number so memory stays O(n) rather
	 * than materializing all 3^n arrays at once.
	 *
	 * @param MatchResult[] $remainingMatches
	 *
	 * @return iterable<MatchResult[]>
	 */
	public static function enumerate( array $remainingMatches ): iterable {
		$n     = count( $remainingMatches );
		$total = 3 ** $n;

		for ( $i = 0; $i < $total; $i++ ) {
			$index  = $i;
			$result = [];

			foreach ( $remainingMatches as $match ) {
				$digit = $index % 3;
				$index = intdiv( $index, 3 );
				[ $h, $a ] = self::PLACEHOLDER_SCORES[ $digit ];
				$result[] = $match->withResult( $h, $a );
			}

			yield $result;
		}
	}
}
