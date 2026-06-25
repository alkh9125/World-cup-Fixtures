<?php

namespace SCD\Frontend;

use SCD\CPT\MatchCpt;
use SCD\CPT\ScenarioCpt;
use SCD\CPT\TeamCpt;
use SCD\CPT\TournamentCpt;
use SCD\Infrastructure\Repositories\GroupRepository;
use SCD\Infrastructure\Repositories\MatchRepository;
use SCD\Infrastructure\Repositories\ScenarioCacheRepository;
use SCD\Infrastructure\Repositories\TeamRepository;
use SCD\Infrastructure\Repositories\TournamentRepository;
use SCD\Infrastructure\Repositories\TournamentTeamRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the nested SEO URLs that the scd_team/scd_match/scd_scenario CPTs
 * cannot produce on their own (registered with rewrite => false):
 *
 *   /{tournament}/                      tournament single   (native CPT rewrite)
 *   /{tournament}/{group}/              group archive        (virtual - no own post)
 *   /{tournament}/{group}/{team}/       team single
 *   /{tournament}/{match}/              match single
 *   /{tournament}/scenarios/{scenario}/ scenario single
 *
 * Group archive and match single are both two path segments after the
 * tournament, so they are ambiguous from the regex alone (`{group}` vs
 * `{match}` slug). They share one rewrite rule tagged scd_route=ambiguous
 * and get disambiguated in resolve_ambiguous_route() by checking whether
 * the second segment is a known group slug for that tournament.
 *
 * Every route also re-validates that the resolved post actually belongs
 * to the tournament/group named in the URL (template_redirect), and
 * 301-redirects to the canonical URL when it does not - this is what
 * stops the same team/match page from being reachable under more than
 * one URL (duplicate content).
 */
final class Rewrites {

	public function register(): void {
		add_action( 'init', [ $this, 'add_rules' ] );
		add_action( 'init', [ $this, 'maybe_flush' ], 20 );
		add_filter( 'query_vars', [ $this, 'add_query_vars' ] );
		add_action( 'parse_request', [ $this, 'resolve_ambiguous_route' ] );
		add_action( 'template_redirect', [ $this, 'validate_context' ] );
		add_filter( 'post_type_link', [ $this, 'build_permalink' ], 10, 2 );
	}

	public function add_query_vars( array $vars ): array {
		$vars[] = 'scd_route';
		$vars[] = 'scd_tournament_slug';
		$vars[] = 'scd_group_slug';
		$vars[] = 'scd_slug2';

		return $vars;
	}

	/**
	 * Rules are added with 'top' in the order they must be checked - an
	 * earlier add_rewrite_rule() call ends up earlier in the merged
	 * rewrite table, so the most specific patterns (literal "scenarios"
	 * segment, then the generic 3-segment team route) are registered
	 * before the ambiguous 2-segment catch-all.
	 */
	public function add_rules(): void {
		add_rewrite_rule(
			'^([^/]+)/scenarios/([^/]+)/?$',
			'index.php?post_type=' . ScenarioCpt::SLUG . '&name=$matches[2]&scd_route=scenario&scd_tournament_slug=$matches[1]',
			'top',
		);

		add_rewrite_rule(
			'^([^/]+)/([^/]+)/([^/]+)/?$',
			'index.php?post_type=' . TeamCpt::SLUG . '&name=$matches[3]&scd_route=team&scd_tournament_slug=$matches[1]&scd_group_slug=$matches[2]',
			'top',
		);

		// Group archive vs. match single - disambiguated at request time.
		// The query is anchored on the tournament's own post so WP's main
		// query always resolves to something real; resolve_ambiguous_route()
		// swaps post_type/name to the match CPT if the 2nd segment turns
		// out not to be a group slug.
		add_rewrite_rule(
			'^([^/]+)/([^/]+)/?$',
			'index.php?post_type=' . TournamentCpt::SLUG . '&name=$matches[1]&scd_route=ambiguous&scd_tournament_slug=$matches[1]&scd_slug2=$matches[2]',
			'top',
		);
	}

	public function maybe_flush(): void {
		if ( get_option( 'scd_flush_rewrite_rules' ) ) {
			flush_rewrite_rules();
			delete_option( 'scd_flush_rewrite_rules' );
		}
	}

	public function resolve_ambiguous_route( \WP $wp ): void {
		if ( 'ambiguous' !== ( $wp->query_vars['scd_route'] ?? null ) ) {
			return;
		}

		$tournamentSlug = $wp->query_vars['scd_tournament_slug'] ?? '';
		$slug2          = $wp->query_vars['scd_slug2'] ?? '';

		$tournament = ( new TournamentRepository() )->findBySlug( $tournamentSlug );

		if ( ! $tournament ) {
			return; // No such tournament - let WP fall through to a 404.
		}

		$group = ( new GroupRepository() )->findBySlug( (int) $tournament['id'], $slug2 );

		if ( $group ) {
			$wp->query_vars['scd_route']      = 'group';
			$wp->query_vars['scd_group_slug'] = $slug2;
			unset( $wp->query_vars['scd_slug2'] );

			return;
		}

		// Not a group slug - re-target the main query at a match single.
		$wp->query_vars['scd_route'] = 'match';
		$wp->query_vars['post_type'] = MatchCpt::SLUG;
		$wp->query_vars['name']      = $slug2;
		unset( $wp->query_vars['scd_slug2'] );
	}

	/**
	 * Confirms the resolved post genuinely belongs to the tournament/group
	 * named in the URL and 301s to the canonical URL when it doesn't (e.g.
	 * a team requested under the wrong group, or a match requested under
	 * the wrong tournament). Unrecognised slugs are left as a 404.
	 */
	public function validate_context(): void {
		$route = get_query_var( 'scd_route' );

		if ( ! $route || is_404() ) {
			return;
		}

		$tournamentSlug = get_query_var( 'scd_tournament_slug' );
		$tournament     = $tournamentSlug ? ( new TournamentRepository() )->findBySlug( $tournamentSlug ) : null;

		if ( ! $tournament ) {
			$this->force_404();
			return;
		}

		if ( 'group' === $route ) {
			// Virtual route, anchored on the tournament's own (valid) post -
			// nothing further to validate, Frontend\TemplateLoader renders it.
			return;
		}

		if ( 'team' === $route ) {
			$this->validate_team_context( (int) $tournament['id'], (string) get_query_var( 'scd_group_slug' ) );
			return;
		}

		if ( 'match' === $route ) {
			$this->validate_match_context( (int) $tournament['id'] );
			return;
		}

		if ( 'scenario' === $route ) {
			$this->validate_scenario_context( (int) $tournament['id'] );
		}
	}

	private function validate_team_context( int $tournamentId, string $groupSlugInUrl ): void {
		$post = get_queried_object();

		if ( ! $post instanceof \WP_Post || TeamCpt::SLUG !== $post->post_type ) {
			$this->force_404();
			return;
		}

		$team = ( new TeamRepository() )->findByPostId( $post->ID );

		if ( ! $team ) {
			$this->force_404();
			return;
		}

		$actualGroupId  = ( new TournamentTeamRepository() )->groupIdForTeam( $tournamentId, (int) $team['id'] );
		$expectedGroup  = $actualGroupId ? ( new GroupRepository() )->find( $actualGroupId ) : null;

		if ( ! $expectedGroup || $expectedGroup['slug'] !== $groupSlugInUrl ) {
			$this->force_404();
		}
	}

	private function validate_match_context( int $tournamentId ): void {
		$post = get_queried_object();

		if ( ! $post instanceof \WP_Post || MatchCpt::SLUG !== $post->post_type ) {
			$this->force_404();
			return;
		}

		$match = ( new MatchRepository() )->findByPostId( $post->ID );

		if ( ! $match || (int) $match['tournament_id'] !== $tournamentId ) {
			$this->force_404();
		}
	}

	private function validate_scenario_context( int $tournamentId ): void {
		$post = get_queried_object();

		if ( ! $post instanceof \WP_Post || ScenarioCpt::SLUG !== $post->post_type ) {
			$this->force_404();
			return;
		}

		$scenario = ( new ScenarioCacheRepository() )->findByPostId( $post->ID );
		$match    = $scenario ? ( new MatchRepository() )->find( (int) $scenario['match_id'] ) : null;

		if ( ! $match || (int) $match['tournament_id'] !== $tournamentId ) {
			$this->force_404();
		}
	}

	private function force_404(): void {
		global $wp_query;

		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * Builds the nested permalink for scd_team/scd_match/scd_scenario
	 * posts. A team can be entered in more than one tournament edition;
	 * this takes its most recent tournament assignment as canonical.
	 */
	public function build_permalink( string $permalink, \WP_Post $post ): string {
		if ( TeamCpt::SLUG === $post->post_type ) {
			return $this->team_permalink( $post ) ?? $permalink;
		}

		if ( MatchCpt::SLUG === $post->post_type ) {
			return $this->match_permalink( $post ) ?? $permalink;
		}

		if ( ScenarioCpt::SLUG === $post->post_type ) {
			return $this->scenario_permalink( $post ) ?? $permalink;
		}

		return $permalink;
	}

	private function team_permalink( \WP_Post $post ): ?string {
		$team = ( new TeamRepository() )->findByPostId( $post->ID );

		if ( ! $team ) {
			return null;
		}

		$assignment = ( new TournamentTeamRepository() )->assignmentsForTeam( (int) $team['id'] )[0] ?? null;

		if ( ! $assignment || null === $assignment['group_id'] ) {
			return null;
		}

		$tournament = ( new TournamentRepository() )->find( $assignment['tournament_id'] );
		$group      = ( new GroupRepository() )->find( $assignment['group_id'] );

		if ( ! $tournament || ! $group ) {
			return null;
		}

		return home_url( sprintf( '/%s/%s/%s/', $tournament['slug'], $group['slug'], $team['slug'] ) );
	}

	private function match_permalink( \WP_Post $post ): ?string {
		$match = ( new MatchRepository() )->findByPostId( $post->ID );

		if ( ! $match ) {
			return null;
		}

		$tournament = ( new TournamentRepository() )->find( (int) $match['tournament_id'] );

		if ( ! $tournament ) {
			return null;
		}

		return home_url( sprintf( '/%s/%s/', $tournament['slug'], $post->post_name ) );
	}

	private function scenario_permalink( \WP_Post $post ): ?string {
		$scenario = ( new ScenarioCacheRepository() )->findByPostId( $post->ID );
		$match    = $scenario ? ( new MatchRepository() )->find( (int) $scenario['match_id'] ) : null;

		if ( ! $match ) {
			return null;
		}

		$tournament = ( new TournamentRepository() )->find( (int) $match['tournament_id'] );

		if ( ! $tournament ) {
			return null;
		}

		return home_url( sprintf( '/%s/scenarios/%s/', $tournament['slug'], $post->post_name ) );
	}
}
