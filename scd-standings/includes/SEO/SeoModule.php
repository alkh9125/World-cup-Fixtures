<?php

namespace SCD\SEO;

use SCD\CPT\TournamentCpt;
use SCD\Infrastructure\Repositories\GroupRepository;
use SCD\Infrastructure\Repositories\MatchRepository;
use SCD\Infrastructure\Repositories\ScenarioCacheRepository;
use SCD\Infrastructure\Repositories\StandingsCacheRepository;
use SCD\Infrastructure\Repositories\TeamRepository;
use SCD\Infrastructure\Repositories\TournamentRepository;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Title/meta-description/canonical/JSON-LD for the 5 plugin routes.
 *
 * Defers to Yoast/RankMath for title, meta description and canonical when
 * one is active - they already own those hooks site-wide and do them well.
 * The one thing neither can get right is the virtual group-archive route
 * (Frontend\Rewrites anchors it on the tournament's own post, so without
 * help here its title/canonical would describe the tournament, not the
 * group), so that override always runs. Structured data is always emitted
 * regardless of an SEO plugin, since neither generates schema for our
 * custom tables.
 */
final class SeoModule {

	private ?array $context = null;

	public function register(): void {
		add_filter( 'document_title_parts', [ $this, 'filter_title_parts' ] );
		add_action( 'wp_head', [ $this, 'output_canonical' ], 1 );
		add_action( 'wp_head', [ $this, 'output_meta_description' ], 1 );
		add_action( 'wp_head', [ $this, 'output_structured_data' ] );
		add_filter( 'wp_robots', [ $this, 'filter_robots' ] );
	}

	private function hasSeoPlugin(): bool {
		return defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' );
	}

	public function filter_title_parts( array $parts ): array {
		$context = $this->resolveContext();

		if ( 'group' !== $context['route'] || ! $context['tournament'] || ! $context['group'] ) {
			return $parts;
		}

		$parts['title'] = sprintf(
			'%s - %s',
			$context['group']['name_ar'] ?: $context['group']['name'],
			$context['tournament']['name_ar'] ?: $context['tournament']['name'],
		);

		return $parts;
	}

	public function output_canonical(): void {
		if ( $this->hasSeoPlugin() ) {
			return;
		}

		$url = $this->canonicalUrl();

		if ( $url ) {
			printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $url ) );
		}
	}

	private function canonicalUrl(): ?string {
		$context = $this->resolveContext();

		if ( 'group' === $context['route'] ) {
			if ( ! $context['tournament'] || ! $context['group'] ) {
				return null;
			}

			return home_url( '/' . $context['tournament']['slug'] . '/' . $context['group']['slug'] . '/' );
		}

		if ( '' !== $context['route'] && is_singular() ) {
			return get_permalink() ?: null;
		}

		return null;
	}

	public function output_meta_description(): void {
		if ( $this->hasSeoPlugin() ) {
			return;
		}

		$description = $this->metaDescription();

		if ( $description ) {
			printf( '<meta name="description" content="%s" />' . "\n", esc_attr( wp_strip_all_tags( $description ) ) );
		}
	}

	private function metaDescription(): ?string {
		$context = $this->resolveContext();

		switch ( $context['route'] ) {
			case 'tournament':
				return $context['tournament'] ? sprintf(
					/* translators: 1: tournament name, 2: season */
					__( 'Live standings, qualification scenarios and bracket projections for %1$s (%2$s).', 'scd-standings' ),
					$context['tournament']['name_ar'] ?: $context['tournament']['name'],
					$context['tournament']['season'],
				) : null;

			case 'group':
				return ( $context['group'] && $context['tournament'] ) ? sprintf(
					/* translators: 1: group name, 2: tournament name */
					__( 'Live %1$s standings for %2$s, updated automatically with qualification status for every team.', 'scd-standings' ),
					$context['group']['name_ar'] ?: $context['group']['name'],
					$context['tournament']['name_ar'] ?: $context['tournament']['name'],
				) : null;

			case 'team':
				return $context['team'] ? sprintf(
					/* translators: %s: team name */
					__( '%s fixtures, group standing and possible knockout-round opponents.', 'scd-standings' ),
					$context['team']['name_ar'] ?: $context['team']['name'],
				) : null;

			case 'match':
				return $context['match'] ? $this->matchDescription( $context['match'] ) : null;

			case 'scenario':
				return $context['scenario'] ? ( $context['scenario']['summary_ar'] ?: $context['scenario']['summary_en'] ) : null;
		}

		return null;
	}

	private function matchDescription( array $match ): string {
		$teamRepo = new TeamRepository();
		$home     = $teamRepo->find( (int) $match['home_team_id'] );
		$away     = $teamRepo->find( (int) $match['away_team_id'] );

		return sprintf(
			/* translators: 1: home team, 2: away team, 3: round */
			__( '%1$s vs %2$s - %3$s. Kickoff time, venue and what each possible result would mean for the standings.', 'scd-standings' ),
			$home['name_ar'] ?? $home['name'] ?? '?',
			$away['name_ar'] ?? $away['name'] ?? '?',
			str_replace( '_', ' ', $match['round'] ),
		);
	}

	/**
	 * Hypothetical "what if" pages are only useful while the match they
	 * describe is still undecided - once it finishes, the real result
	 * supersedes them, so they are noindexed rather than left to compete
	 * with the (now more relevant) match page for the same query.
	 */
	public function filter_robots( array $robots ): array {
		$context = $this->resolveContext();

		if ( 'scenario' !== $context['route'] || ! $context['match'] ) {
			return $robots;
		}

		if ( 'finished' === $context['match']['status'] ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}

		return $robots;
	}

	public function output_structured_data(): void {
		$data = $this->structuredData();

		if ( $data ) {
			printf( '<script type="application/ld+json">%s</script>' . "\n", wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		}
	}

	private function structuredData(): ?array {
		$context = $this->resolveContext();

		switch ( $context['route'] ) {
			case 'tournament':
				return $context['tournament'] ? $this->tournamentSchema( $context['tournament'] ) : null;

			case 'group':
				return ( $context['tournament'] && $context['group'] ) ? $this->groupSchema( $context['tournament'], $context['group'] ) : null;

			case 'team':
				return $context['team'] ? $this->teamSchema( $context['team'] ) : null;

			case 'match':
				return $context['match'] ? $this->matchSchema( $context['match'] ) : null;

			case 'scenario':
				return ( $context['scenario'] && $context['match'] ) ? $this->scenarioSchema( $context['scenario'], $context['match'] ) : null;
		}

		return null;
	}

	private function tournamentSchema( array $tournament ): array {
		return [
			'@context'    => 'https://schema.org',
			'@type'       => 'SportsEvent',
			'name'        => $tournament['name_ar'] ?: $tournament['name'],
			'sport'       => 'Soccer',
			'url'         => home_url( '/' . $tournament['slug'] . '/' ),
			'description' => trim( $tournament['season'] . ' ' . $tournament['name'] ),
		];
	}

	private function groupSchema( array $tournament, array $group ): array {
		$rows     = ( new StandingsCacheRepository() )->findForGroup( (int) $tournament['id'], (int) $group['id'] );
		$teamRepo = new TeamRepository();
		$teams    = $teamRepo->findMany( array_map( static fn ( array $row ) => (int) $row['team_id'], $rows ) );

		$items = array_map( static function ( array $row ) use ( $teams ) {
			$team = $teams[ (int) $row['team_id'] ] ?? null;

			return [
				'@type'    => 'ListItem',
				'position' => (int) $row['rank'],
				'item'     => [
					'@type' => 'SportsTeam',
					'name'  => $team['name_ar'] ?? ( $team['name'] ?? '' ),
				],
			];
		}, $rows );

		return [
			'@context'        => 'https://schema.org',
			'@type'           => 'ItemList',
			'name'            => $group['name_ar'] ?: $group['name'],
			'itemListElement' => $items,
		];
	}

	private function teamSchema( array $team ): array {
		$schema = [
			'@context' => 'https://schema.org',
			'@type'    => 'SportsTeam',
			'name'     => $team['name_ar'] ?: $team['name'],
			'sport'    => 'Soccer',
		];

		if ( $team['logo_url'] ) {
			$schema['logo'] = $team['logo_url'];
		}

		if ( $team['country'] ) {
			$schema['location'] = $team['country'];
		}

		return $schema;
	}

	private function matchSchema( array $match ): array {
		$teamRepo = new TeamRepository();
		$home     = $teamRepo->find( (int) $match['home_team_id'] );
		$away     = $teamRepo->find( (int) $match['away_team_id'] );

		$schema = [
			'@context'    => 'https://schema.org',
			'@type'       => 'SportsEvent',
			'name'        => sprintf( '%s vs %s', $home['name'] ?? '?', $away['name'] ?? '?' ),
			'sport'       => 'Soccer',
			'competitor'  => [
				[ '@type' => 'SportsTeam', 'name' => $home['name_ar'] ?? ( $home['name'] ?? '?' ) ],
				[ '@type' => 'SportsTeam', 'name' => $away['name_ar'] ?? ( $away['name'] ?? '?' ) ],
			],
			'eventStatus' => 'finished' === $match['status']
				? 'https://schema.org/EventCompleted'
				: 'https://schema.org/EventScheduled',
		];

		if ( $match['kickoff_at'] ) {
			$schema['startDate'] = mysql2date( 'c', $match['kickoff_at'] );
		}

		if ( $match['venue'] ) {
			$schema['location'] = [ '@type' => 'Place', 'name' => $match['venue'] ];
		}

		return $schema;
	}

	private function scenarioSchema( array $scenario, array $match ): array {
		$body = $scenario['summary_ar'] ?: $scenario['summary_en'];

		$schema = [
			'@context'    => 'https://schema.org',
			'@type'       => 'Article',
			'headline'    => wp_trim_words( (string) $body, 12 ),
			'articleBody' => $body,
			'inLanguage'  => $scenario['summary_ar'] ? 'ar' : 'en',
		];

		$matchUrl = $match['post_id'] ? get_permalink( (int) $match['post_id'] ) : false;

		if ( $matchUrl ) {
			$schema['about'] = [ '@type' => 'SportsEvent', '@id' => $matchUrl ];
		}

		return $schema;
	}

	private function route(): string {
		$route = (string) get_query_var( 'scd_route' );

		if ( '' === $route && is_singular( TournamentCpt::SLUG ) ) {
			return 'tournament';
		}

		return $route;
	}

	private function resolveContext(): array {
		if ( null !== $this->context ) {
			return $this->context;
		}

		$context = [ 'route' => $this->route(), 'tournament' => null, 'group' => null, 'team' => null, 'match' => null, 'scenario' => null ];

		if ( '' === $context['route'] || is_404() ) {
			return $this->context = $context;
		}

		$tournamentSlug         = (string) get_query_var( 'scd_tournament_slug' );
		$context['tournament']  = $tournamentSlug ? ( new TournamentRepository() )->findBySlug( $tournamentSlug ) : null;

		if ( ! $context['tournament'] ) {
			return $this->context = $context;
		}

		$post = get_queried_object();

		if ( 'group' === $context['route'] ) {
			$groupSlug         = (string) get_query_var( 'scd_group_slug' );
			$context['group']  = ( new GroupRepository() )->findBySlug( (int) $context['tournament']['id'], $groupSlug );
		} elseif ( 'team' === $context['route'] && $post instanceof WP_Post ) {
			$context['team'] = ( new TeamRepository() )->findByPostId( $post->ID );
		} elseif ( 'match' === $context['route'] && $post instanceof WP_Post ) {
			$context['match'] = ( new MatchRepository() )->findByPostId( $post->ID );
		} elseif ( 'scenario' === $context['route'] && $post instanceof WP_Post ) {
			$context['scenario'] = ( new ScenarioCacheRepository() )->findByPostId( $post->ID );
			$context['match']    = $context['scenario'] ? ( new MatchRepository() )->find( (int) $context['scenario']['match_id'] ) : null;
		}

		return $this->context = $context;
	}
}
