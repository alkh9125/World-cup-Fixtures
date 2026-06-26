<?php

namespace SCD\Seeder;

use SCD\CPT\MatchCpt;
use SCD\CPT\TeamCpt;
use SCD\CPT\TournamentCpt;
use SCD\Infrastructure\Providers\WorldCup2026Bracket;
use SCD\Infrastructure\Repositories\GroupRepository;
use SCD\Infrastructure\Repositories\MatchRepository;
use SCD\Infrastructure\Repositories\TeamRepository;
use SCD\Infrastructure\Repositories\TournamentRepository;
use SCD\Infrastructure\Repositories\TournamentTeamRepository;
use SCD\Jobs\RecalculationPipeline;

defined( 'ABSPATH' ) || exit;

/**
 * One-shot demo dataset: 8 groups of 4 (the classic format
 * Domain\BracketProjector understands - see
 * Infrastructure\Providers\WorldCup2026Bracket), with the first two of each
 * group's three matchdays already played so the live standings,
 * qualification status, scenario pages and what-if simulator all have
 * something real to show right after activation. Triggered from
 * Admin\Dashboard's "Seed demo data" button; safe to run more than once -
 * it no-ops if the tournament slug already exists.
 */
final class WorldCup2026Seeder {

	private const VENUES = [
		'MetLife Stadium, New Jersey',
		'AT&T Stadium, Dallas',
		'SoFi Stadium, Los Angeles',
		'Mercedes-Benz Stadium, Atlanta',
		'Estadio Azteca, Mexico City',
		'BC Place, Vancouver',
		'Lincoln Financial Field, Philadelphia',
		'Arrowhead Stadium, Kansas City',
	];

	/** [name, name_ar, short_code] per group, 4 teams each, seeded 1-4 in list order. */
	private const GROUPS = [
		'A' => [ [ 'United States', 'الولايات المتحدة', 'USA' ], [ 'Saudi Arabia', 'السعودية', 'KSA' ], [ 'Netherlands', 'هولندا', 'NED' ], [ 'Senegal', 'السنغال', 'SEN' ] ],
		'B' => [ [ 'Mexico', 'المكسيك', 'MEX' ], [ 'Argentina', 'الأرجنتين', 'ARG' ], [ 'Japan', 'اليابان', 'JPN' ], [ 'Tunisia', 'تونس', 'TUN' ] ],
		'C' => [ [ 'Canada', 'كندا', 'CAN' ], [ 'Brazil', 'البرازيل', 'BRA' ], [ 'Croatia', 'كرواتيا', 'CRO' ], [ 'Ghana', 'غانا', 'GHA' ] ],
		'D' => [ [ 'England', 'إنجلترا', 'ENG' ], [ 'Spain', 'إسبانيا', 'ESP' ], [ 'South Korea', 'كوريا الجنوبية', 'KOR' ], [ 'Morocco', 'المغرب', 'MAR' ] ],
		'E' => [ [ 'Germany', 'ألمانيا', 'GER' ], [ 'Portugal', 'البرتغال', 'POR' ], [ 'Australia', 'أستراليا', 'AUS' ], [ 'Egypt', 'مصر', 'EGY' ] ],
		'F' => [ [ 'France', 'فرنسا', 'FRA' ], [ 'Belgium', 'بلجيكا', 'BEL' ], [ 'Nigeria', 'نيجيريا', 'NGA' ], [ 'Ecuador', 'الإكوادور', 'ECU' ] ],
		'G' => [ [ 'Italy', 'إيطاليا', 'ITA' ], [ 'Uruguay', 'الأوروغواي', 'URU' ], [ 'Iran', 'إيران', 'IRN' ], [ 'Costa Rica', 'كوستاريكا', 'CRC' ] ],
		'H' => [ [ 'Qatar', 'قطر', 'QAT' ], [ 'Colombia', 'كولومبيا', 'COL' ], [ 'Switzerland', 'سويسرا', 'SUI' ], [ 'Cameroon', 'الكاميرون', 'CMR' ] ],
	];

	/** Scorelines for finished matches, consumed in order across all groups/matchdays. */
	private const SAMPLE_SCORES = [
		[ 2, 1 ], [ 1, 1 ], [ 0, 2 ], [ 3, 0 ], [ 1, 0 ], [ 2, 2 ], [ 0, 0 ], [ 1, 2 ],
		[ 4, 1 ], [ 2, 0 ], [ 1, 3 ], [ 0, 1 ], [ 3, 2 ], [ 1, 1 ], [ 2, 1 ], [ 0, 3 ],
	];

	/** @return array{skipped:bool, tournament_id:int} */
	public static function run(): array {
		$existing = ( new TournamentRepository() )->findBySlug( 'world-cup-2026' );

		if ( $existing ) {
			return [ 'skipped' => true, 'tournament_id' => (int) $existing['id'] ];
		}

		$tournamentId = self::createTournament();
		$groupIds     = self::createGroups( $tournamentId );
		$teamIds      = self::createTeams( $tournamentId, $groupIds );

		self::createMatches( $tournamentId, $groupIds, $teamIds );

		RecalculationPipeline::run( $tournamentId );

		return [ 'skipped' => false, 'tournament_id' => $tournamentId ];
	}

	private static function createTournament(): int {
		$postId = wp_insert_post( [
			'post_type'   => TournamentCpt::SLUG,
			'post_title'  => 'FIFA World Cup 2026',
			'post_name'   => 'world-cup-2026',
			'post_status' => 'publish',
		], true );

		return ( new TournamentRepository() )->insert( [
			'post_id'                => is_wp_error( $postId ) ? null : $postId,
			'slug'                   => 'world-cup-2026',
			'name'                   => 'FIFA World Cup 2026',
			'name_ar'                => 'كأس العالم 2026',
			'season'                 => '2026',
			'format_json'            => wp_json_encode( [ 'qualifiers_per_group' => 2 ] ),
			'bracket_template_json'  => wp_json_encode( WorldCup2026Bracket::template() ),
			'current_stage'          => 'group',
			'status'                 => 'ongoing',
			'source_provider'        => 'manual',
			'external_id'            => 'seed:world-cup-2026',
		] );
	}

	/** @return array<string,int> group letter => group_id */
	private static function createGroups( int $tournamentId ): array {
		$groupRepo = new GroupRepository();
		$groupIds  = [];

		foreach ( array_keys( self::GROUPS ) as $i => $letter ) {
			$groupIds[ $letter ] = $groupRepo->insert( [
				'tournament_id' => $tournamentId,
				'slug'          => 'group-' . strtolower( $letter ),
				'name'          => 'Group ' . $letter,
				'name_ar'       => 'المجموعة ' . self::arabicLetter( $letter ),
				'sort_order'    => $i,
			] );
		}

		return $groupIds;
	}

	/** @return array<string,array<int,int>> group letter => [seed(1-4) => team_id] */
	private static function createTeams( int $tournamentId, array $groupIds ): array {
		$teamRepo   = new TeamRepository();
		$assignRepo = new TournamentTeamRepository();
		$teamIds    = [];

		foreach ( self::GROUPS as $letter => $teams ) {
			$teamIds[ $letter ] = [];

			foreach ( $teams as $index => [ $name, $nameAr, $code ] ) {
				$slug = sanitize_title( $name );

				$postId = wp_insert_post( [
					'post_type'   => TeamCpt::SLUG,
					'post_title'  => $name,
					'post_name'   => $slug,
					'post_status' => 'publish',
				], true );

				$teamId = $teamRepo->insert( [
					'post_id'    => is_wp_error( $postId ) ? null : $postId,
					'slug'       => $slug,
					'name'       => $name,
					'name_ar'    => $nameAr,
					'short_code' => $code,
					'country'    => $name,
				] );

				$seed = $index + 1;
				$assignRepo->assign( $tournamentId, $teamId, $groupIds[ $letter ], $seed );
				$teamIds[ $letter ][ $seed ] = $teamId;
			}
		}

		return $teamIds;
	}

	/**
	 * @param array<string,int>            $groupIds
	 * @param array<string,array<int,int>> $teamIds
	 */
	private static function createMatches( int $tournamentId, array $groupIds, array $teamIds ): void {
		$matchRepo   = new MatchRepository();
		$scoreCursor = 0;
		$venueCursor = 0;

		// Round-robin pairing for a 4-team group, one pair of fixtures per matchday.
		$matchdays    = [ [ [ 1, 2 ], [ 3, 4 ] ], [ [ 1, 3 ], [ 4, 2 ] ], [ [ 4, 1 ], [ 2, 3 ] ] ];
		$kickoffDates = [ '2026-06-12', '2026-06-17', '2026-06-22' ];

		foreach ( self::GROUPS as $letter => $teams ) {
			foreach ( $matchdays as $matchdayIndex => $pairs ) {
				$finished = $matchdayIndex < 2; // First two matchdays already played; the third is still to come.

				foreach ( $pairs as $pairIndex => $seeds ) {
					[ $homeSeed, $awaySeed ] = $seeds;
					$homeTeamId = $teamIds[ $letter ][ $homeSeed ];
					$awayTeamId = $teamIds[ $letter ][ $awaySeed ];
					$homeSlug   = sanitize_title( $teams[ $homeSeed - 1 ][0] );
					$awaySlug   = sanitize_title( $teams[ $awaySeed - 1 ][0] );
					$slug       = sanitize_title( "{$homeSlug}-vs-{$awaySlug}-group-md{$matchdayIndex}" );

					$postId = wp_insert_post( [
						'post_type'   => MatchCpt::SLUG,
						'post_title'  => sprintf( '%s vs %s', $teams[ $homeSeed - 1 ][0], $teams[ $awaySeed - 1 ][0] ),
						'post_name'   => $slug,
						'post_status' => 'publish',
					], true );

					$data = [
						'post_id'       => is_wp_error( $postId ) ? null : $postId,
						'tournament_id' => $tournamentId,
						'group_id'      => $groupIds[ $letter ],
						'round'         => 'group',
						'home_team_id'  => $homeTeamId,
						'away_team_id'  => $awayTeamId,
						'kickoff_at'    => $kickoffDates[ $matchdayIndex ] . ' ' . ( 0 === $pairIndex ? '18:00:00' : '21:00:00' ),
						'venue'         => self::VENUES[ $venueCursor % count( self::VENUES ) ],
						'status'        => $finished ? 'finished' : 'scheduled',
					];

					if ( $finished ) {
						[ $homeScore, $awayScore ] = self::SAMPLE_SCORES[ $scoreCursor % count( self::SAMPLE_SCORES ) ];
						$data['home_score'] = $homeScore;
						$data['away_score'] = $awayScore;
						++$scoreCursor;
					}

					$matchRepo->insert( $data );
					++$venueCursor;
				}
			}
		}
	}

	private static function arabicLetter( string $letter ): string {
		$map = [ 'A' => 'أ', 'B' => 'ب', 'C' => 'ج', 'D' => 'د', 'E' => 'هـ', 'F' => 'و', 'G' => 'ز', 'H' => 'ح' ];

		return $map[ $letter ] ?? $letter;
	}
}
