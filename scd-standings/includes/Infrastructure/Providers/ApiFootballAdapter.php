<?php

namespace SCD\Infrastructure\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * https://www.api-football.com/ (v3, api-sports.io host) free tier: 100
 * requests/day, so Jobs\SyncJob polls on an hour-or-longer interval by
 * default rather than anything near-real-time - see
 * Admin\Settings for the configurable interval.
 *
 * $externalTournamentId is "{league_id}:{season}", e.g. "1:2026" for the
 * World Cup 2026 league/season pair as listed by the API's /leagues
 * endpoint. Stored verbatim in scd_tournaments.external_id.
 */
final class ApiFootballAdapter implements ProviderInterface {

	private const BASE_URL = 'https://v3.football.api-sports.io';

	private string $apiKey;

	public function __construct( ?string $apiKey = null ) {
		$this->apiKey = $apiKey ?? (string) get_option( 'scd_api_football_key', '' );
	}

	public function id(): string {
		return 'api_football';
	}

	public function fetchFixtures( string $externalTournamentId ): array {
		[ $leagueId, $season ] = array_pad( explode( ':', $externalTournamentId, 2 ), 2, '' );

		if ( '' === $this->apiKey || '' === $leagueId || '' === $season ) {
			return [];
		}

		$url = add_query_arg(
			[ 'league' => $leagueId, 'season' => $season ],
			self::BASE_URL . '/fixtures',
		);

		$response = wp_remote_get( $url, [
			'headers' => [ 'x-apisports-key' => $this->apiKey ],
			'timeout' => 15,
		] );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return [];
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['response'] ) || ! is_array( $body['response'] ) ) {
			return [];
		}

		return array_values( array_filter( array_map( [ $this, 'mapFixture' ], $body['response'] ) ) );
	}

	private function mapFixture( array $raw ): ?array {
		if ( empty( $raw['fixture']['id'] ) || empty( $raw['teams']['home']['id'] ) || empty( $raw['teams']['away']['id'] ) ) {
			return null;
		}

		return [
			'external_id' => (string) $raw['fixture']['id'],
			'round'       => $this->mapRound( (string) ( $raw['league']['round'] ?? '' ) ),
			'status'      => $this->mapStatus( (string) ( $raw['fixture']['status']['short'] ?? '' ) ),
			'kickoff_at'  => ! empty( $raw['fixture']['date'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( $raw['fixture']['date'] ) ) : null,
			'venue'       => $raw['fixture']['venue']['name'] ?? null,
			'home_score'  => $raw['goals']['home'] ?? null,
			'away_score'  => $raw['goals']['away'] ?? null,
			'home_team'   => [
				'external_id' => (string) $raw['teams']['home']['id'],
				'name'        => (string) $raw['teams']['home']['name'],
				'logo_url'    => $raw['teams']['home']['logo'] ?? null,
			],
			'away_team'   => [
				'external_id' => (string) $raw['teams']['away']['id'],
				'name'        => (string) $raw['teams']['away']['name'],
				'logo_url'    => $raw['teams']['away']['logo'] ?? null,
			],
		];
	}

	/** API-Football statuses: NS|TBD scheduled, 1H/HT/2H/ET/BT/P/SUSP/INT live, FT/AET/PEN finished, PST/CANC/ABD postponed/cancelled. */
	private function mapStatus( string $short ): string {
		return match ( $short ) {
			'FT', 'AET', 'PEN'                                => 'finished',
			'1H', 'HT', '2H', 'ET', 'BT', 'P', 'SUSP', 'INT', 'LIVE' => 'live',
			'PST', 'CANC', 'ABD'                              => 'postponed',
			default                                            => 'scheduled',
		};
	}

	/** Folds API-Football's free-text round label into the plugin's round vocabulary. */
	private function mapRound( string $label ): string {
		$normalized = strtolower( $label );

		return match ( true ) {
			str_contains( $normalized, 'group' )       => 'group',
			str_contains( $normalized, 'round of 16' )  => 'round_of_16',
			str_contains( $normalized, 'quarter' )      => 'quarter_final',
			str_contains( $normalized, 'semi' )         => 'semi_final',
			str_contains( $normalized, '3rd place' )    => 'third_place',
			str_contains( $normalized, 'final' )        => 'final',
			default                                     => 'group',
		};
	}
}
