<?php

namespace SCD\Jobs;

use SCD\Infrastructure\Providers\ProviderFactory;
use SCD\Infrastructure\Repositories\MatchRepository;
use SCD\Infrastructure\Repositories\TeamRepository;
use SCD\Infrastructure\Repositories\TournamentRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Periodically pulls fixtures/results for every tournament that has a
 * non-manual source_provider, via WP-Cron (not Action Scheduler - the
 * plugin must keep working when uploaded as a zip with no `composer
 * install`/vendor library available, see includes/autoload-fallback.php).
 *
 * This only refreshes teams/matches already known to the DB; the
 * *initial* import (creating team rows, assigning groups) is a deliberate
 * one-off action in Admin\Importer, not something this job infers.
 */
final class SyncJob {

	public const HOOK             = 'scd_sync_fixtures';
	private const DEFAULT_INTERVAL_MINUTES = 60; // API-Football free tier: 100 requests/day.

	public static function register(): void {
		add_filter( 'cron_schedules', [ self::class, 'register_schedule' ] );
		add_action( 'init', [ self::class, 'maybe_schedule' ] );
		add_action( self::HOOK, [ self::class, 'run' ] );
	}

	public static function register_schedule( array $schedules ): array {
		$minutes = max( 15, (int) get_option( 'scd_sync_interval_minutes', self::DEFAULT_INTERVAL_MINUTES ) );

		$schedules['scd_sync_interval'] = [
			'interval' => $minutes * MINUTE_IN_SECONDS,
			'display'  => sprintf( __( 'Every %d minutes (SCD Standings sync)', 'scd-standings' ), $minutes ),
		];

		return $schedules;
	}

	public static function maybe_schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'scd_sync_interval', self::HOOK );
		}
	}

	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::HOOK );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}

	public static function run(): void {
		$tournamentRepo = new TournamentRepository();
		$matchRepo      = new MatchRepository();
		$teamRepo       = new TeamRepository();

		foreach ( $tournamentRepo->all() as $tournament ) {
			$providerId = (string) ( $tournament['source_provider'] ?? '' );

			if ( '' === $providerId || 'manual' === $providerId || empty( $tournament['external_id'] ) ) {
				continue;
			}

			$provider = ProviderFactory::forId( $providerId );
			$fixtures = $provider->fetchFixtures( (string) $tournament['external_id'] );
			$changed  = false;

			foreach ( $fixtures as $fixture ) {
				$homeTeamId = self::resolveTeamId( $teamRepo, $providerId, $fixture['home_team'] );
				$awayTeamId = self::resolveTeamId( $teamRepo, $providerId, $fixture['away_team'] );

				[ , $fixtureChanged ] = $matchRepo->upsertByExternalId( $providerId, $fixture['external_id'], [
					'tournament_id' => (int) $tournament['id'],
					'round'         => $fixture['round'],
					'status'        => $fixture['status'],
					'kickoff_at'    => $fixture['kickoff_at'],
					'venue'         => $fixture['venue'],
					'home_team_id'  => $homeTeamId,
					'away_team_id'  => $awayTeamId,
					'home_score'    => $fixture['home_score'],
					'away_score'    => $fixture['away_score'],
				] );

				$changed = $changed || $fixtureChanged;
			}

			if ( $changed ) {
				/** @see Jobs\RecalculationPipeline (debounces and rebuilds standings/qualification/bracket/scenarios for this tournament) */
				do_action( 'scd/tournament_synced', (int) $tournament['id'] );
			}
		}
	}

	/** Finds the team by this provider's external id, creating a bare row on first sight. */
	private static function resolveTeamId( TeamRepository $teamRepo, string $providerId, array $teamData ): int {
		$existing = $teamRepo->findByExternalId( $providerId, $teamData['external_id'] );

		if ( $existing ) {
			return (int) $existing['id'];
		}

		return $teamRepo->insert( [
			'slug'              => sanitize_title( $teamData['name'] ),
			'name'              => $teamData['name'],
			'logo_url'          => $teamData['logo_url'],
			'external_ids_json' => wp_json_encode( [ $providerId => $teamData['external_id'] ] ),
		] );
	}
}
