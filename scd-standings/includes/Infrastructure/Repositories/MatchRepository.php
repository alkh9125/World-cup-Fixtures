<?php

namespace SCD\Infrastructure\Repositories;

use SCD\Domain\MatchResult;

defined( 'ABSPATH' ) || exit;

/**
 * Reads/writes scd_matches and converts rows to/from the domain's
 * MatchResult value object - the only place that translation happens, so
 * the Domain namespace never has to know about WordPress or the schema.
 */
final class MatchRepository {

	private string $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'scd_matches';
	}

	public function find( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ), ARRAY_A );

		return $row ?: null;
	}

	public function findByPostId( int $postId ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE post_id = %d", $postId ), ARRAY_A );

		return $row ?: null;
	}

	public function findByExternalId( string $provider, string $externalId ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE source_provider = %s AND external_id = %s",
				$provider,
				$externalId,
			),
			ARRAY_A,
		);

		return $row ?: null;
	}

	/** @return array[] raw rows for a group, ordered by kickoff time */
	public function findForGroup( int $groupId ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE group_id = %d ORDER BY kickoff_at ASC", $groupId ),
			ARRAY_A,
		) ?: [];
	}

	/** @return array[] raw rows for a tournament round (e.g. 'round_of_16') */
	public function findForRound( int $tournamentId, string $round ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE tournament_id = %d AND round = %s ORDER BY kickoff_at ASC",
				$tournamentId,
				$round,
			),
			ARRAY_A,
		) ?: [];
	}

	/** @return MatchResult[] */
	public function decidedMatchesForGroup( int $groupId ): array {
		return array_map( [ self::class, 'toDomain' ], array_filter(
			$this->findForGroup( $groupId ),
			static fn ( array $row ) => 'finished' === $row['status'],
		) );
	}

	/** @return MatchResult[] */
	public function undecidedMatchesForGroup( int $groupId ): array {
		return array_map( [ self::class, 'toDomain' ], array_filter(
			$this->findForGroup( $groupId ),
			static fn ( array $row ) => 'finished' !== $row['status'],
		) );
	}

	public static function toDomain( array $row ): MatchResult {
		return new MatchResult(
			matchId:    (int) $row['id'],
			homeTeamId: (int) $row['home_team_id'],
			awayTeamId: (int) $row['away_team_id'],
			groupId:    null !== $row['group_id'] ? (int) $row['group_id'] : null,
			round:      $row['round'],
			status:     $row['status'],
			homeScore:  null !== $row['home_score'] ? (int) $row['home_score'] : null,
			awayScore:  null !== $row['away_score'] ? (int) $row['away_score'] : null,
		);
	}

	public function insert( array $data ): int {
		global $wpdb;

		$now = current_time( 'mysql', true );
		$wpdb->insert( $this->table, array_merge( $data, [ 'created_at' => $now, 'updated_at' => $now ] ) );

		return (int) $wpdb->insert_id;
	}

	public function update( int $id, array $data ): bool {
		global $wpdb;

		$data['updated_at'] = current_time( 'mysql', true );

		return false !== $wpdb->update( $this->table, $data, [ 'id' => $id ] );
	}

	/**
	 * Insert-or-update keyed by (source_provider, external_id) - the
	 * primary entry point for provider sync jobs. Returns [id, changed].
	 */
	public function upsertByExternalId( string $provider, string $externalId, array $data ): array {
		$existing = $this->findByExternalId( $provider, $externalId );

		$data['source_provider'] = $provider;
		$data['external_id']     = $externalId;
		$data['last_synced_at']  = current_time( 'mysql', true );

		if ( null === $existing ) {
			return [ $this->insert( $data ), true ];
		}

		$changed = $this->hasMeaningfulChange( $existing, $data );
		$this->update( (int) $existing['id'], $data );

		return [ (int) $existing['id'], $changed ];
	}

	private function hasMeaningfulChange( array $existing, array $incoming ): bool {
		foreach ( [ 'status', 'home_score', 'away_score', 'home_score_et', 'away_score_et', 'home_pen', 'away_pen' ] as $field ) {
			if ( array_key_exists( $field, $incoming ) && (string) ( $existing[ $field ] ?? '' ) !== (string) ( $incoming[ $field ] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}
}
