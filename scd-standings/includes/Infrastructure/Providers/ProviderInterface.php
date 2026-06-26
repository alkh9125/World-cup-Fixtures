<?php

namespace SCD\Infrastructure\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes one external data source into the plain-array shape
 * Jobs\SyncJob feeds straight into TeamRepository/MatchRepository - so
 * swapping API-Football for Opta/Sportmonks/Football-Data.org later only
 * means writing one more class here, nothing downstream changes.
 */
interface ProviderInterface {

	/** Stable key stored in scd_tournaments.source_provider / scd_matches.source_provider. */
	public function id(): string;

	/**
	 * @param string $externalTournamentId provider-specific competition/league+season reference
	 * @return array<int,array{
	 *     external_id:string,
	 *     round:string,
	 *     status:string,
	 *     kickoff_at:?string,
	 *     venue:?string,
	 *     home_score:?int,
	 *     away_score:?int,
	 *     home_team:array{external_id:string,name:string,logo_url:?string},
	 *     away_team:array{external_id:string,name:string,logo_url:?string},
	 * }> normalized fixtures, one row per match
	 */
	public function fetchFixtures( string $externalTournamentId ): array;
}
