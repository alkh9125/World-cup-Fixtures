<?php

namespace SCD\Jobs;

use SCD\Domain\BracketProjector;
use SCD\Domain\GroupPositionCalculator;
use SCD\Domain\MatchResult;
use SCD\Domain\NarrativeGenerator;
use SCD\Domain\QualificationStatusResolver;
use SCD\Domain\ScenarioEngine;
use SCD\Domain\StandingsCalculator;
use SCD\Domain\TieBreak\FifaRuleset;
use SCD\Infrastructure\Cache\CacheManager;
use SCD\Infrastructure\Repositories\BracketSlotRepository;
use SCD\Infrastructure\Repositories\GroupRepository;
use SCD\Infrastructure\Repositories\MatchRepository;
use SCD\Infrastructure\Repositories\OverrideRepository;
use SCD\Infrastructure\Repositories\ScenarioCacheRepository;
use SCD\Infrastructure\Repositories\StandingsCacheRepository;
use SCD\Infrastructure\Repositories\TeamRepository;
use SCD\Infrastructure\Repositories\TournamentRepository;
use SCD\Infrastructure\Repositories\TournamentTeamRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The single place that turns raw scd_matches rows into everything a page
 * request reads: standings + qualification (per group), the projected
 * bracket, and per-match what-if scenarios with their narrative summaries.
 * Always runs as a background job (debounced via scheduleFor()), never on
 * a page request - recalculating a 4-group qualifier round can be ~3^6
 * standings recomputations per group (see QualificationStatusResolver).
 *
 * Step order matters: standings/qualification must exist before the
 * bracket projection (which consumes group-position possibilities), the
 * bracket must exist before scenarios (so a scenario can eventually name a
 * likely next-round opponent), and overrides are applied last so a manual
 * correction is never silently recomputed away.
 *
 * Knockout-stage "what if" pages are out of scope here: ScenarioEngine
 * models group-table permutations, not single-elimination advancement, so
 * only group-stage matches get scenario rows for now.
 */
final class RecalculationPipeline {

	public const HOOK = 'scd_recalculate_tournament';

	private const DEBOUNCE_LOCK_TTL = 5 * MINUTE_IN_SECONDS;
	private const DEBOUNCE_DELAY    = 10; // seconds

	public static function register(): void {
		add_action( 'scd/tournament_synced', [ self::class, 'scheduleFor' ] );
		add_action( 'scd/overrides_changed', [ self::class, 'scheduleFor' ] );
		add_action( self::HOOK, [ self::class, 'run' ] );
	}

	/** Debounced: a burst of sync/override events for the same tournament only schedules one run. */
	public static function scheduleFor( int $tournamentId ): void {
		$lockKey = 'scd_recalc_pending_' . $tournamentId;

		if ( get_transient( $lockKey ) ) {
			return;
		}

		set_transient( $lockKey, 1, self::DEBOUNCE_LOCK_TTL );
		wp_schedule_single_event( time() + self::DEBOUNCE_DELAY, self::HOOK, [ $tournamentId ] );
	}

	public static function run( int $tournamentId ): void {
		delete_transient( 'scd_recalc_pending_' . $tournamentId );
		( new self() )->recalculate( $tournamentId );
	}

	public function recalculate( int $tournamentId ): void {
		$tournamentRepo = new TournamentRepository();
		$tournament     = $tournamentRepo->find( $tournamentId );

		if ( ! $tournament ) {
			return;
		}

		$format             = json_decode( (string) ( $tournament['format_json'] ?? '' ), true ) ?: [];
		$qualifiersPerGroup = (int) ( $format['qualifiers_per_group'] ?? 2 );

		$calculator       = new StandingsCalculator( new FifaRuleset() );
		$qualResolver     = new QualificationStatusResolver( $calculator );
		$groupPosCalc     = new GroupPositionCalculator( $calculator );
		$scenarioEngine   = new ScenarioEngine( $calculator, $qualResolver );
		$narrativeGen     = new NarrativeGenerator();

		$tournamentTeamRepo  = new TournamentTeamRepository();
		$matchRepo           = new MatchRepository();
		$standingsCacheRepo  = new StandingsCacheRepository();
		$scenarioCacheRepo   = new ScenarioCacheRepository();

		$groupPositionPossibilities = [];
		$groupContext               = []; // groupId => [teamIds, decided, undecided]

		foreach ( ( new GroupRepository() )->findForTournament( $tournamentId ) as $group ) {
			$groupId   = (int) $group['id'];
			$teamIds   = $tournamentTeamRepo->teamIdsForGroup( $groupId );
			$decided   = $matchRepo->decidedMatchesForGroup( $groupId );
			$undecided = $matchRepo->undecidedMatchesForGroup( $groupId );

			$standings = $calculator->calculate( $decided, $teamIds, $groupId );
			$qualByTeam = $qualResolver->resolveForGroup( $teamIds, $decided, $undecided, $groupId, $qualifiersPerGroup );

			$standingsCacheRepo->replaceForGroup( $tournamentId, $groupId, $standings, $qualByTeam );

			$groupPositionPossibilities[ $this->groupCode( $group ) ] =
				$groupPosCalc->calculate( $teamIds, $decided, $undecided, $groupId );

			$groupContext[ $groupId ] = [ 'teamIds' => $teamIds, 'decided' => $decided, 'undecided' => $undecided ];
		}

		$this->projectBracket( $tournamentId, $tournamentRepo, $groupPositionPossibilities, $matchRepo );
		$this->generateScenarios( $tournamentId, $groupContext, $qualifiersPerGroup, $scenarioEngine, $narrativeGen, $scenarioCacheRepo );
		$this->applyOverrides( $tournamentId, $standingsCacheRepo );

		( new CacheManager() )->purgeForTournament( $tournamentId );
	}

	private function projectBracket( int $tournamentId, TournamentRepository $tournamentRepo, array $groupPositionPossibilities, MatchRepository $matchRepo ): void {
		$bracketTemplate = $tournamentRepo->bracketTemplate( $tournamentId );

		if ( empty( $bracketTemplate ) ) {
			return;
		}

		$resolvedBySlot = [];

		foreach ( $matchRepo->allForTournament( $tournamentId ) as $row ) {
			if ( 'group' === $row['round'] || 'finished' !== $row['status'] || empty( $row['slot_code'] ) ) {
				continue;
			}

			$winner = $this->knockoutWinner( $row );

			if ( null !== $winner ) {
				$resolvedBySlot[ $row['slot_code'] ] = $winner;
			}
		}

		$slotResults = ( new BracketProjector() )->resolveSlots( $bracketTemplate, $groupPositionPossibilities, $resolvedBySlot );
		( new BracketSlotRepository() )->replaceForTournament( $tournamentId, $slotResults );
	}

	/** Regular time first, then extra time, then penalties - the first decisive scoreline wins the slot. */
	private function knockoutWinner( array $row ): ?int {
		foreach ( [ [ 'home_score', 'away_score' ], [ 'home_score_et', 'away_score_et' ], [ 'home_pen', 'away_pen' ] ] as [ $homeField, $awayField ] ) {
			if ( null === $row[ $homeField ] || null === $row[ $awayField ] ) {
				continue;
			}

			if ( (int) $row[ $homeField ] > (int) $row[ $awayField ] ) {
				return (int) $row['home_team_id'];
			}

			if ( (int) $row[ $awayField ] > (int) $row[ $homeField ] ) {
				return (int) $row['away_team_id'];
			}
		}

		return null;
	}

	private function generateScenarios(
		int $tournamentId,
		array $groupContext,
		int $qualifiersPerGroup,
		ScenarioEngine $scenarioEngine,
		NarrativeGenerator $narrativeGen,
		ScenarioCacheRepository $scenarioCacheRepo,
	): void {
		$teamRepo = new TeamRepository();

		foreach ( $groupContext as $groupId => $context ) {
			$teamNames = $teamRepo->findMany( $context['teamIds'] );
			$namesAr   = array_map( static fn ( array $t ) => $t['name_ar'] ?: $t['name'], $teamNames );
			$namesEn   = array_map( static fn ( array $t ) => $t['name'], $teamNames );

			/** @var MatchResult $match */
			foreach ( $context['undecided'] as $match ) {
				$otherUndecided = array_values( array_filter(
					$context['undecided'],
					static fn ( MatchResult $m ) => $m->matchId !== $match->matchId,
				) );

				$results = $scenarioEngine->generateForMatch(
					$match,
					$context['teamIds'],
					$context['decided'],
					$otherUndecided,
					$groupId,
					$qualifiersPerGroup,
				);

				foreach ( $results as $outcome => $result ) {
					$slug    = $this->scenarioSlug( $match, $outcome, $teamNames );
					$summary = $narrativeGen->generate( $namesAr, $namesEn, $match, $outcome, $result );

					$scenarioCacheRepo->upsert( $match->matchId, $outcome, $slug, $summary['ar'], $summary['en'], $result['changes'] );
				}
			}
		}
	}

	private function scenarioSlug( MatchResult $match, string $outcome, array $teamNames ): string {
		$homeSlug = $teamNames[ $match->homeTeamId ]['slug'] ?? (string) $match->homeTeamId;
		$awaySlug = $teamNames[ $match->awayTeamId ]['slug'] ?? (string) $match->awayTeamId;

		return sanitize_title( "{$homeSlug}-vs-{$awaySlug}-" . str_replace( '_', '-', $outcome ) );
	}

	private function applyOverrides( int $tournamentId, StandingsCacheRepository $standingsCacheRepo ): void {
		foreach ( ( new OverrideRepository() )->findActiveForTournament( $tournamentId ) as $override ) {
			if ( 'standing' !== $override['target_type'] ) {
				continue;
			}

			$standingsCacheRepo->applyFieldOverride( $tournamentId, (int) $override['target_id'], $override['field'], $override['value'] );
		}
	}

	/**
	 * Bracket templates address groups by letter code (e.g. "A"), matching
	 * Domain\BracketProjector's `group_position` rule shape. Seed data (see
	 * the WC2026 fixture importer) is expected to slug groups as
	 * "group-a", "group-b", ... so the code is just that trailing letter.
	 */
	private function groupCode( array $group ): string {
		$slug = (string) $group['slug'];
		$dash = strrpos( $slug, '-' );

		return strtoupper( false !== $dash ? substr( $slug, $dash + 1 ) : $slug );
	}
}
