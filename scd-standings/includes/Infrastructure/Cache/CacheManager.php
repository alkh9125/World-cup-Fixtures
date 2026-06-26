<?php

namespace SCD\Infrastructure\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around a single WP object-cache group for rendered
 * fragments (standings tables, bracket views, scenario pages). Backed by
 * wp_cache_* so it's a no-op store on sites without a persistent object
 * cache plugin, and a real shared cache on sites with one (Redis/Memcached)
 * - no extra dependency either way.
 */
final class CacheManager {

	public const GROUP = 'scd_standings';

	public function remember( string $key, int $ttlSeconds, callable $compute ) {
		$cached = wp_cache_get( $key, self::GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		$value = $compute();
		wp_cache_set( $key, $value, self::GROUP, $ttlSeconds );

		return $value;
	}

	/**
	 * Drops every cached fragment for one tournament. Called as the last
	 * step of Jobs\RecalculationPipeline so the next page view always
	 * reflects freshly computed standings/bracket/scenarios.
	 */
	public function purgeForTournament( int $tournamentId ): void {
		foreach ( [ 'standings', 'bracket', 'qualification', 'scenarios' ] as $fragment ) {
			wp_cache_delete( "{$fragment}_{$tournamentId}", self::GROUP );
		}
	}
}
