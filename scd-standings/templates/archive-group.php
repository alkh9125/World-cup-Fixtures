<?php
/**
 * Virtual group archive (no own CPT post - see Frontend\Rewrites). Renders
 * the live standings table plus the no-reload what-if simulator for this
 * group's still-undecided matches.
 */

use SCD\Infrastructure\Repositories\GroupRepository;
use SCD\Infrastructure\Repositories\MatchRepository;
use SCD\Infrastructure\Repositories\TeamRepository;
use SCD\Infrastructure\Repositories\TournamentRepository;

defined( 'ABSPATH' ) || exit;

get_header();

$tournamentSlug = (string) get_query_var( 'scd_tournament_slug' );
$groupSlug      = (string) get_query_var( 'scd_group_slug' );
$tournament     = ( new TournamentRepository() )->findBySlug( $tournamentSlug );
$group          = $tournament ? ( new GroupRepository() )->findBySlug( (int) $tournament['id'], $groupSlug ) : null;

if ( ! $tournament || ! $group ) {
	get_footer();
	return;
}

$teams     = ( new TeamRepository() )->findForTournament( (int) $tournament['id'], (int) $group['id'] );
$undecided = array_filter(
	( new MatchRepository() )->findForGroup( (int) $group['id'] ),
	static fn ( array $m ) => 'finished' !== $m['status'],
);

$simulatorMatches = array_map(
	static function ( array $m ) use ( $teams ) {
		$home = current( array_filter( $teams, static fn ( array $t ) => (int) $t['id'] === (int) $m['home_team_id'] ) ) ?: null;
		$away = current( array_filter( $teams, static fn ( array $t ) => (int) $t['id'] === (int) $m['away_team_id'] ) ) ?: null;

		return [
			'id'        => (int) $m['id'],
			'home_name' => $home['name_ar'] ?: ( $home['name'] ?? '?' ),
			'away_name' => $away['name_ar'] ?: ( $away['name'] ?? '?' ),
		];
	},
	array_values( $undecided ),
);
?>

<main class="scd" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>">
	<header>
		<h1>
			<?php echo esc_html( $group['name_ar'] ?: $group['name'] ); ?>
			<a href="<?php echo esc_url( home_url( '/' . $tournament['slug'] . '/' ) ); ?>"><small>&larr; <?php echo esc_html( $tournament['name'] ); ?></small></a>
		</h1>
	</header>

	<section x-data="scdStandings('<?php echo esc_js( $tournament['slug'] ); ?>', '<?php echo esc_js( $group['slug'] ); ?>')" x-init="load()" x-cloak>
		<h2><?php esc_html_e( 'Standings', 'scd-standings' ); ?></h2>
		<p class="scd-loading" x-show="loading"><?php esc_html_e( 'Loading…', 'scd-standings' ); ?></p>
		<p class="scd-error" x-show="error" x-cloak><?php esc_html_e( 'Could not load the standings.', 'scd-standings' ); ?></p>
		<table class="scd-table" x-show="!loading && !error">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Team', 'scd-standings' ); ?></th>
					<th><?php esc_html_e( 'P', 'scd-standings' ); ?></th>
					<th><?php esc_html_e( 'W', 'scd-standings' ); ?></th>
					<th><?php esc_html_e( 'D', 'scd-standings' ); ?></th>
					<th><?php esc_html_e( 'L', 'scd-standings' ); ?></th>
					<th><?php esc_html_e( 'GD', 'scd-standings' ); ?></th>
					<th><?php esc_html_e( 'Pts', 'scd-standings' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<template x-for="row in rows" :key="row.team_id">
					<tr :class="'scd-qual-' + row.qualification_status.replace('still_', '')">
						<td class="scd-team-name">
							<img x-show="row.team_logo" :src="row.team_logo" class="scd-team-logo" alt="">
							<a :href="'<?php echo esc_url( home_url( '/' . $tournament['slug'] . '/' . $group['slug'] . '/' ) ); ?>' + row.team_slug + '/'" x-text="row.team_name_ar || row.team_name"></a>
						</td>
						<td x-text="row.played"></td>
						<td x-text="row.won"></td>
						<td x-text="row.drawn"></td>
						<td x-text="row.lost"></td>
						<td x-text="row.goal_difference"></td>
						<td x-text="row.points"></td>
					</tr>
				</template>
			</tbody>
		</table>
	</section>

	<?php if ( ! empty( $simulatorMatches ) ) : ?>
		<section
			x-data="<?php echo esc_attr( sprintf( 'scdSimulator(%d, %s)', $group['id'], wp_json_encode( $simulatorMatches ) ) ); ?>"
			x-cloak
		>
			<h2><?php esc_html_e( 'What if…?', 'scd-standings' ); ?></h2>
			<p><?php esc_html_e( 'Pick scores for the remaining matches and see how the table would look - nothing here is saved.', 'scd-standings' ); ?></p>

			<template x-for="input in inputs" :key="input.match_id">
				<div class="scd-simulator-row">
					<span x-text="input.home_name"></span>
					<input type="number" min="0" x-model="input.home_score">
					&ndash;
					<input type="number" min="0" x-model="input.away_score">
					<span x-text="input.away_name"></span>
				</div>
			</template>

			<button type="button" @click="run()" :disabled="loading"><?php esc_html_e( 'Simulate', 'scd-standings' ); ?></button>
			<button type="button" @click="reset()" x-show="submitted"><?php esc_html_e( 'Reset', 'scd-standings' ); ?></button>

			<p class="scd-error" x-show="error" x-cloak><?php esc_html_e( 'Could not run the simulation.', 'scd-standings' ); ?></p>

			<table class="scd-table" x-show="submitted">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Team', 'scd-standings' ); ?></th>
						<th><?php esc_html_e( 'Pts', 'scd-standings' ); ?></th>
						<th><?php esc_html_e( 'GD', 'scd-standings' ); ?></th>
						<th><?php esc_html_e( 'Status', 'scd-standings' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<template x-for="row in rows" :key="row.team_id">
						<tr :class="'scd-qual-' + row.qualification_status.replace('still_', '')">
							<td x-text="row.team_name_ar || row.team_name"></td>
							<td x-text="row.points"></td>
							<td x-text="row.goal_difference"></td>
							<td x-text="row.qualification_status"></td>
						</tr>
					</template>
				</tbody>
			</table>
		</section>
	<?php endif; ?>
</main>

<?php
get_footer();
