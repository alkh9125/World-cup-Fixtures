<?php
/**
 * Tournament overview: groups list + the knockout bracket projection.
 * Standings live on each group's own page (templates/archive-group.php) -
 * this page is the hub that links out to them.
 */

use SCD\Infrastructure\Repositories\GroupRepository;
use SCD\Infrastructure\Repositories\TournamentRepository;

defined( 'ABSPATH' ) || exit;

get_header();

$post       = get_queried_object();
$tournament = ( new TournamentRepository() )->findBySlug( $post->post_name );

if ( ! $tournament ) {
	get_footer();
	return;
}

$groups = ( new GroupRepository() )->findForTournament( (int) $tournament['id'] );
?>

<main class="scd" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>">
	<header>
		<h1>
			<?php if ( $tournament['name_ar'] ) : ?>
				<span class="scd-title-ar" lang="ar"><?php echo esc_html( $tournament['name_ar'] ); ?></span>
				<span class="scd-title-en"><?php echo esc_html( $tournament['name'] ); ?></span>
			<?php else : ?>
				<?php echo esc_html( $tournament['name'] ); ?>
			<?php endif; ?>
		</h1>
		<p>
			<?php echo esc_html( $tournament['season'] ); ?> &middot;
			<?php echo esc_html( ucfirst( str_replace( '_', ' ', $tournament['status'] ) ) ); ?>
		</p>
	</header>

	<section>
		<h2><?php esc_html_e( 'Groups', 'scd-standings' ); ?></h2>
		<ul>
			<?php foreach ( $groups as $group ) : ?>
				<li>
					<a href="<?php echo esc_url( home_url( '/' . $tournament['slug'] . '/' . $group['slug'] . '/' ) ); ?>">
						<?php echo esc_html( $group['name_ar'] ?: $group['name'] ); ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>

	<section x-data="scdBracket('<?php echo esc_js( $tournament['slug'] ); ?>')" x-init="load()" x-cloak>
		<h2><?php esc_html_e( 'Knockout bracket projection', 'scd-standings' ); ?></h2>
		<p class="scd-loading" x-show="loading"><?php esc_html_e( 'Loading…', 'scd-standings' ); ?></p>
		<p class="scd-error" x-show="error" x-cloak><?php esc_html_e( 'Could not load the bracket.', 'scd-standings' ); ?></p>
		<template x-if="!loading && !error && slots.length === 0">
			<p><?php esc_html_e( 'No bracket has been published for this tournament yet.', 'scd-standings' ); ?></p>
		</template>
		<div class="scd-bracket" x-show="!loading && slots.length > 0">
			<template x-for="slot in slots" :key="slot.slot_code">
				<div class="scd-bracket-round">
					<small x-text="slot.round"></small>
					<div class="scd-bracket-slot">
						<div x-text="sideLabel(slot.home)"></div>
						<div>&ndash;</div>
						<div x-text="sideLabel(slot.away)"></div>
					</div>
				</div>
			</template>
		</div>
	</section>
</main>

<?php
get_footer();
