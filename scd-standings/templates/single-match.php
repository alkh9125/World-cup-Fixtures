<?php
/**
 * Match profile: fixture facts (kickoff, venue, score) plus the per-match
 * "what could this result mean" widget (Domain\ScenarioGenerator output,
 * cached in wp_scd_scenarios_cache, served via the /matches/{id}/scenarios
 * REST route and rendered with the scdScenarios Alpine component).
 */

use SCD\Infrastructure\Repositories\MatchRepository;
use SCD\Infrastructure\Repositories\TeamRepository;
use SCD\Infrastructure\Repositories\TournamentRepository;

defined( 'ABSPATH' ) || exit;

get_header();

$post  = get_queried_object();
$match = ( new MatchRepository() )->findByPostId( $post->ID );

if ( ! $match ) {
	get_footer();
	return;
}

$tournamentSlug = (string) get_query_var( 'scd_tournament_slug' );
$tournament     = ( new TournamentRepository() )->findBySlug( $tournamentSlug );
$teamRepo       = new TeamRepository();
$home           = $teamRepo->find( (int) $match['home_team_id'] );
$away           = $teamRepo->find( (int) $match['away_team_id'] );
$isFinished     = 'finished' === $match['status'];
?>

<main class="scd" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>">
	<header>
		<h1>
			<?php echo esc_html( $home['name_ar'] ?: ( $home['name'] ?? '?' ) ); ?>
			&ndash;
			<?php echo esc_html( $away['name_ar'] ?: ( $away['name'] ?? '?' ) ); ?>
		</h1>
		<p>
			<?php echo esc_html( str_replace( '_', ' ', $match['round'] ) ); ?>
			<?php if ( $match['kickoff_at'] ) : ?>
				&middot; <?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $match['kickoff_at'] ) ); ?>
			<?php endif; ?>
			<?php if ( $match['venue'] ) : ?>
				&middot; <?php echo esc_html( $match['venue'] ); ?>
			<?php endif; ?>
		</p>
		<?php if ( $tournament ) : ?>
			<a href="<?php echo esc_url( home_url( '/' . $tournament['slug'] . '/' ) ); ?>"><small>&larr; <?php echo esc_html( $tournament['name'] ); ?></small></a>
		<?php endif; ?>
	</header>

	<?php if ( $isFinished ) : ?>
		<section>
			<h2><?php esc_html_e( 'Final score', 'scd-standings' ); ?></h2>
			<p class="scd-score">
				<?php echo esc_html( (string) $match['home_score'] ); ?> &ndash; <?php echo esc_html( (string) $match['away_score'] ); ?>
				<?php if ( null !== $match['home_pen'] && null !== $match['away_pen'] ) : ?>
					(<?php esc_html_e( 'pens', 'scd-standings' ); ?> <?php echo esc_html( (string) $match['home_pen'] ); ?>-<?php echo esc_html( (string) $match['away_pen'] ); ?>)
				<?php endif; ?>
			</p>
		</section>
	<?php else : ?>
		<section x-data="scdScenarios(<?php echo (int) $match['id']; ?>)" x-init="load()" x-cloak>
			<h2><?php esc_html_e( 'What this result could mean', 'scd-standings' ); ?></h2>
			<p class="scd-loading" x-show="loading"><?php esc_html_e( 'Loading…', 'scd-standings' ); ?></p>
			<p class="scd-error" x-show="error" x-cloak><?php esc_html_e( 'Could not load the scenarios.', 'scd-standings' ); ?></p>
			<template x-for="scenario in scenarios" :key="scenario.slug">
				<div class="scd-scenario-card">
					<p x-text="scenario.summary_ar || scenario.summary_en"></p>
					<a :href="'<?php echo esc_url( home_url( '/' . ( $tournament['slug'] ?? '' ) . '/scenarios/' ) ); ?>' + scenario.slug + '/'">
						<?php esc_html_e( 'Read more', 'scd-standings' ); ?>
					</a>
				</div>
			</template>
		</section>
	<?php endif; ?>
</main>

<?php
get_footer();
