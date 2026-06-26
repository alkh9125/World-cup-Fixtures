<?php

namespace SCD\Infrastructure\Rest;

use SCD\Domain\OpponentFinder;
use SCD\Domain\QualificationStatusResolver;
use SCD\Domain\StandingsCalculator;
use SCD\Domain\TieBreak\FifaRuleset;
use SCD\Infrastructure\Repositories\BracketSlotRepository;
use SCD\Infrastructure\Repositories\GroupRepository;
use SCD\Infrastructure\Repositories\MatchRepository;
use SCD\Infrastructure\Repositories\ScenarioCacheRepository;
use SCD\Infrastructure\Repositories\StandingsCacheRepository;
use SCD\Infrastructure\Repositories\TeamRepository;
use SCD\Infrastructure\Repositories\TournamentRepository;
use SCD\Infrastructure\Repositories\TournamentTeamRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Public, read-mostly REST surface that powers the Alpine.js frontend.
 * Every GET route reads from the caches Jobs\RecalculationPipeline
 * maintains - never recomputes on request - except /simulate, which is a
 * pure, unpersisted computation so the what-if widget can update without
 * a page reload without ever touching scd_matches/scd_standings_cache.
 */
final class RestApi {

	private const NAMESPACE = 'scd/v1';

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/tournaments', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_tournaments' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( self::NAMESPACE, '/tournaments/(?P<slug>[a-z0-9-]+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_tournament' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( self::NAMESPACE, '/tournaments/(?P<slug>[a-z0-9-]+)/standings', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_standings' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( self::NAMESPACE, '/tournaments/(?P<slug>[a-z0-9-]+)/bracket', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_bracket' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( self::NAMESPACE, '/tournaments/(?P<slug>[a-z0-9-]+)/teams/(?P<team_slug>[a-z0-9-]+)/opponents', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_opponents' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( self::NAMESPACE, '/matches/(?P<match_id>\d+)/scenarios', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_scenarios' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( self::NAMESPACE, '/simulate', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'simulate' ],
			'permission_callback' => '__return_true',
		] );
	}

	public function get_tournaments(): WP_REST_Response {
		$tournaments = array_map( [ $this, 'tournamentPayload' ], ( new TournamentRepository() )->all() );

		return new WP_REST_Response( $tournaments );
	}

	public function get_tournament( WP_REST_Request $request ) {
		$tournament = $this->requireTournament( $request['slug'] );

		if ( $tournament instanceof WP_Error ) {
			return $tournament;
		}

		$groups = array_map(
			static fn ( array $g ) => [ 'id' => (int) $g['id'], 'slug' => $g['slug'], 'name' => $g['name'], 'name_ar' => $g['name_ar'] ],
			( new GroupRepository() )->findForTournament( (int) $tournament['id'] ),
		);

		return new WP_REST_Response( array_merge( $this->tournamentPayload( $tournament ), [ 'groups' => $groups ] ) );
	}

	public function get_standings( WP_REST_Request $request ) {
		$tournament = $this->requireTournament( $request['slug'] );

		if ( $tournament instanceof WP_Error ) {
			return $tournament;
		}

		$tournamentId   = (int) $tournament['id'];
		$groupRepo      = new GroupRepository();
		$standingsRepo  = new StandingsCacheRepository();
		$requestedGroup = $request->get_param( 'group' );

		$groups = $groupRepo->findForTournament( $tournamentId );

		if ( $requestedGroup ) {
			$groups = array_values( array_filter( $groups, static fn ( array $g ) => $g['slug'] === $requestedGroup ) );
		}

		$payload = [];

		foreach ( $groups as $group ) {
			$rows = $standingsRepo->findForGroup( $tournamentId, (int) $group['id'] );

			$payload[] = [
				'group'     => [ 'id' => (int) $group['id'], 'slug' => $group['slug'], 'name' => $group['name'], 'name_ar' => $group['name_ar'] ],
				'standings' => $this->enrichStandingsRows( $rows ),
			];
		}

		return new WP_REST_Response( $payload );
	}

	public function get_bracket( WP_REST_Request $request ) {
		$tournament = $this->requireTournament( $request['slug'] );

		if ( $tournament instanceof WP_Error ) {
			return $tournament;
		}

		$rows     = ( new BracketSlotRepository() )->findForTournament( (int) $tournament['id'] );
		$teamRepo = new TeamRepository();

		$slots = array_map( function ( array $row ) use ( $teamRepo ) {
			$decoded = json_decode( (string) $row['possible_teams_json'], true ) ?: [ 'home' => [], 'away' => [] ];

			return [
				'slot_code'        => $row['slot_code'],
				'round'            => $row['round'],
				'resolved_team_id' => null !== $row['resolved_team_id'] ? (int) $row['resolved_team_id'] : null,
				'home'             => $this->enrichSlotSide( $decoded['home'] ?? [], $teamRepo ),
				'away'             => $this->enrichSlotSide( $decoded['away'] ?? [], $teamRepo ),
			];
		}, $rows );

		return new WP_REST_Response( $slots );
	}

	public function get_opponents( WP_REST_Request $request ) {
		$tournament = $this->requireTournament( $request['slug'] );

		if ( $tournament instanceof WP_Error ) {
			return $tournament;
		}

		$round = (string) $request->get_param( 'round' );

		if ( '' === $round ) {
			return new WP_Error( 'scd_missing_round', __( 'A round query parameter is required.', 'scd-standings' ), [ 'status' => 400 ] );
		}

		$team = ( new TeamRepository() )->findBySlug( (string) $request['team_slug'] );

		if ( ! $team ) {
			return new WP_Error( 'scd_team_not_found', __( 'Team not found.', 'scd-standings' ), [ 'status' => 404 ] );
		}

		$tournamentId    = (int) $tournament['id'];
		$bracketTemplate = ( new TournamentRepository() )->bracketTemplate( $tournamentId );
		$slotResults     = $this->buildSlotResults( $tournamentId );

		$matches  = ( new OpponentFinder() )->find( (int) $team['id'], $bracketTemplate, $slotResults, $round );
		$teamRepo = new TeamRepository();

		$opponentIds = array_unique( array_map( static fn ( array $m ) => $m['opponent_team_id'], $matches ) );
		$names       = $teamRepo->findMany( $opponentIds );

		$payload = array_map( static function ( array $m ) use ( $names ) {
			$opponent = $names[ $m['opponent_team_id'] ] ?? null;

			return array_merge( $m, [
				'opponent_name'    => $opponent['name'] ?? null,
				'opponent_name_ar' => $opponent['name_ar'] ?? null,
				'opponent_slug'    => $opponent['slug'] ?? null,
			] );
		}, $matches );

		return new WP_REST_Response( $payload );
	}

	public function get_scenarios( WP_REST_Request $request ): WP_REST_Response {
		$rows = ( new ScenarioCacheRepository() )->findForMatch( (int) $request['match_id'] );

		$payload = array_map( static function ( array $row ) {
			return [
				'outcome'    => $row['outcome'],
				'slug'       => $row['slug'],
				'summary_ar' => $row['summary_ar'],
				'summary_en' => $row['summary_en'],
				'changes'    => json_decode( (string) $row['impact_json'], true ) ?: [],
			];
		}, $rows );

		return new WP_REST_Response( $payload );
	}

	/**
	 * Pure, unpersisted "what if these specific results happened" - powers
	 * the no-reload simulator widget. Body: { group_id: int, results:
	 * [{match_id, home_score, away_score}, ...] }.
	 */
	public function simulate( WP_REST_Request $request ) {
		$groupId = (int) $request->get_param( 'group_id' );
		$group   = $groupId ? ( new GroupRepository() )->find( $groupId ) : null;

		if ( ! $group ) {
			return new WP_Error( 'scd_group_not_found', __( 'Group not found.', 'scd-standings' ), [ 'status' => 404 ] );
		}

		$tournamentId       = (int) $group['tournament_id'];
		$tournament         = ( new TournamentRepository() )->find( $tournamentId );
		$format             = json_decode( (string) ( $tournament['format_json'] ?? '' ), true ) ?: [];
		$qualifiersPerGroup = (int) ( $format['qualifiers_per_group'] ?? 2 );

		$matchRepo = new MatchRepository();
		$teamIds   = ( new TournamentTeamRepository() )->teamIdsForGroup( $groupId );
		$decided   = $matchRepo->decidedMatchesForGroup( $groupId );
		$undecided = $matchRepo->undecidedMatchesForGroup( $groupId );

		$requestedResults = (array) $request->get_param( 'results' );
		$applied          = [];
		$stillUndecided   = [];

		foreach ( $undecided as $match ) {
			$override = current( array_filter( $requestedResults, static fn ( $r ) => (int) ( $r['match_id'] ?? 0 ) === $match->matchId ) );

			if ( $override ) {
				$applied[] = $match->withResult( (int) $override['home_score'], (int) $override['away_score'] );
			} else {
				$stillUndecided[] = $match;
			}
		}

		$calculator   = new StandingsCalculator( new FifaRuleset() );
		$qualResolver = new QualificationStatusResolver( $calculator );

		$allMatches    = array_merge( $decided, $applied );
		$standings     = $calculator->calculate( $allMatches, $teamIds, $groupId );
		$qualification = $qualResolver->resolveForGroup( $teamIds, $allMatches, $stillUndecided, $groupId, $qualifiersPerGroup );

		$rows = array_map( static function ( $standing ) use ( $qualification ) {
			$row                          = $standing->toArray();
			$row['qualification_status'] = $qualification[ $standing->teamId ] ?? QualificationStatusResolver::STATUS_POSSIBLE;

			return $row;
		}, $standings );

		return new WP_REST_Response( $this->enrichStandingsRows( $rows, 'team_id' ) );
	}

	private function tournamentPayload( array $tournament ): array {
		return [
			'id'            => (int) $tournament['id'],
			'slug'          => $tournament['slug'],
			'name'          => $tournament['name'],
			'name_ar'       => $tournament['name_ar'],
			'season'        => $tournament['season'],
			'current_stage' => $tournament['current_stage'],
			'status'        => $tournament['status'],
		];
	}

	/** @param array[] $rows scd_standings_cache rows (or simulate()'s Standing::toArray() rows) */
	private function enrichStandingsRows( array $rows, string $teamIdKey = 'team_id' ): array {
		$teamIds = array_map( static fn ( array $r ) => (int) $r[ $teamIdKey ], $rows );
		$names   = ( new TeamRepository() )->findMany( $teamIds );

		usort( $rows, static fn ( array $a, array $b ) => $a['rank'] <=> $b['rank'] );

		return array_map( static function ( array $row ) use ( $names, $teamIdKey ) {
			$team = $names[ (int) $row[ $teamIdKey ] ] ?? null;

			return array_merge( $row, [
				'team_name'    => $team['name'] ?? null,
				'team_name_ar' => $team['name_ar'] ?? null,
				'team_slug'    => $team['slug'] ?? null,
				'team_logo'    => $team['logo_url'] ?? null,
			] );
		}, $rows );
	}

	private function enrichSlotSide( array $side, TeamRepository $teamRepo ): array {
		$possibleIds = $side['possible'] ?? [];
		$names       = $teamRepo->findMany( $possibleIds );

		return [
			'resolved' => $side['resolved'] ?? null,
			'possible' => array_map(
				static fn ( int $id ) => [
					'team_id' => $id,
					'name'    => $names[ $id ]['name'] ?? null,
					'name_ar' => $names[ $id ]['name_ar'] ?? null,
					'slug'    => $names[ $id ]['slug'] ?? null,
				],
				$possibleIds,
			),
		];
	}

	/** @return array<string,array{round:string, home:array, away:array}> */
	private function buildSlotResults( int $tournamentId ): array {
		$slotResults = [];

		foreach ( ( new BracketSlotRepository() )->findForTournament( $tournamentId ) as $row ) {
			$decoded = json_decode( (string) $row['possible_teams_json'], true ) ?: [ 'home' => [], 'away' => [] ];

			$slotResults[ $row['slot_code'] ] = [
				'round' => $row['round'],
				'home'  => $decoded['home'] ?? [ 'resolved' => null, 'possible' => [] ],
				'away'  => $decoded['away'] ?? [ 'resolved' => null, 'possible' => [] ],
			];
		}

		return $slotResults;
	}

	/** @return array|WP_Error */
	private function requireTournament( string $slug ) {
		$tournament = ( new TournamentRepository() )->findBySlug( $slug );

		if ( ! $tournament ) {
			return new WP_Error( 'scd_tournament_not_found', __( 'Tournament not found.', 'scd-standings' ), [ 'status' => 404 ] );
		}

		return $tournament;
	}
}
