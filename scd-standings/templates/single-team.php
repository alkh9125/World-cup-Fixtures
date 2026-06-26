<?php
/**
 * Team profile: identity + the "possible opponents" widget (per knockout
 * round, driven by Domain\OpponentFinder via the /opponents REST route).
 */

use SCD\Infrastructure\Repositories\TeamRepository;
use SCD\Infrastructure\Repositories\TournamentRepository;
use SCD\Infrastructure\Repositories\TournamentTeamRepository;

defined( 'ABSPATH' ) || exit;

get_header();

$post = get_queried_object();
$team = ( new TeamRepository() )->findByPostId( $post->ID );

if ( ! $team ) {
	get_footer();
	return;
}

$tournamentSlug = (string) get_query_var( 'scd_tournament_slug' );
$tournament     = ( new TournamentRepository() )->findBySlug( $tournamentSlug );
$rounds         = [ 'round_of_16', 'quarter_final', 'semi_final', 'third_place', 'final' ];
?>

<main class="scd" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>">
	<header>
		<h1>
			<?php if ( $team['logo_url'] ) : ?>
				<img src="<?php echo esc_url( $team['logo_url'] ); ?>" alt="" class="scd-team-logo">
			<?php endif; ?>
			<?php if ( $team['name_ar'] ) : ?>
				<span class="scd-title-ar" lang="ar"><?php echo esc_html( $team['name_ar'] ); ?></span>
				<span class="scd-title-en"><?php echo esc_html( $team['name'] ); ?></span>
			<?php else : ?>
				<?php echo esc_html( $team['name'] ); ?>
			<?php endif; ?>
		</h1>
		<?php if ( $team['country'] ) : ?>
			<p><?php echo esc_html( $team['country'] ); ?></p>
		<?php endif; ?>
		<?php if ( $tournament ) : ?>
			<a href="<?php echo esc_url( home_url( '/' . $tournament['slug'] . '/' ) ); ?>"><small>&larr; <?php echo esc_html( $tournament['name'] ); ?></small></a>
		<?php endif; ?>
	</header>

	<?php if ( $tournament ) : ?>
		<section
			x-data="scdOpponents('<?php echo esc_js( $tournament['slug'] ); ?>', '<?php echo esc_js( $team['slug'] ); ?>', '')"
			x-cloak
		>
			<h2><?php esc_html_e( 'Possible opponents', 'scd-standings' ); ?></h2>
			<label for="scd_round_select"><?php esc_html_e( 'Round:', 'scd-standings' ); ?></label>
			<select id="scd_round_select" x-model="round" @change="load()">
				<option value=""><?php esc_html_e( '— Select a round —', 'scd-standings' ); ?></option>
				<?php foreach ( $rounds as $round ) : ?>
					<option value="<?php echo esc_attr( $round ); ?>"><?php echo esc_html( str_replace( '_', ' ', $round ) ); ?></option>
				<?php endforeach; ?>
			</select>

			<p class="scd-loading" x-show="loading"><?php esc_html_e( 'Loading…', 'scd-standings' ); ?></p>
			<p class="scd-error" x-show="error" x-cloak><?php esc_html_e( 'Could not load opponents.', 'scd-standings' ); ?></p>
			<template x-if="round && !loading && !error && opponents.length === 0">
				<p><?php esc_html_e( 'No opponent possibilities projected for this round yet.', 'scd-standings' ); ?></p>
			</template>
			<ul>
				<template x-for="opp in opponents" :key="opp.opponent_team_id + (opp.round || '')">
					<li x-text="opp.opponent_name_ar || opp.opponent_name"></li>
				</template>
			</ul>
		</section>
	<?php endif; ?>
</main>

<?php
get_footer();
