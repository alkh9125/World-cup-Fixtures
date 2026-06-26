<?php
/**
 * Scenario page: the deterministic narrative + standings-impact for one
 * specific match outcome (one of the rows RecalculationPipeline writes to
 * wp_scd_scenarios_cache after every recalculation).
 */

use SCD\Infrastructure\Repositories\MatchRepository;
use SCD\Infrastructure\Repositories\ScenarioCacheRepository;
use SCD\Infrastructure\Repositories\TeamRepository;
use SCD\Infrastructure\Repositories\TournamentRepository;

defined( 'ABSPATH' ) || exit;

get_header();

$post     = get_queried_object();
$scenario = ( new ScenarioCacheRepository() )->findByPostId( $post->ID );
$match    = $scenario ? ( new MatchRepository() )->find( (int) $scenario['match_id'] ) : null;

if ( ! $scenario || ! $match ) {
	get_footer();
	return;
}

$tournament = ( new TournamentRepository() )->find( (int) $match['tournament_id'] );
$teamRepo   = new TeamRepository();
$home       = $teamRepo->find( (int) $match['home_team_id'] );
$away       = $teamRepo->find( (int) $match['away_team_id'] );
$changes    = json_decode( (string) $scenario['impact_json'], true ) ?: [];
?>

<main class="scd" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>">
	<header>
		<h1>
			<?php echo esc_html( $home['name_ar'] ?: ( $home['name'] ?? '?' ) ); ?>
			&ndash;
			<?php echo esc_html( $away['name_ar'] ?: ( $away['name'] ?? '?' ) ); ?>
			<small>&middot; <?php echo esc_html( str_replace( '_', ' ', $scenario['outcome'] ) ); ?></small>
		</h1>
		<?php if ( $match['post_id'] ) : ?>
			<a href="<?php echo esc_url( get_permalink( (int) $match['post_id'] ) ); ?>"><small>&larr; <?php esc_html_e( 'Back to the match', 'scd-standings' ); ?></small></a>
		<?php endif; ?>
	</header>

	<section class="scd-scenario-card">
		<?php if ( $scenario['summary_ar'] ) : ?>
			<p lang="ar"><?php echo esc_html( $scenario['summary_ar'] ); ?></p>
		<?php endif; ?>
		<?php if ( $scenario['summary_en'] ) : ?>
			<p><?php echo esc_html( $scenario['summary_en'] ); ?></p>
		<?php endif; ?>
	</section>

	<?php if ( ! empty( $changes ) ) : ?>
		<section>
			<h2><?php esc_html_e( 'Standings impact', 'scd-standings' ); ?></h2>
			<table class="scd-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Team', 'scd-standings' ); ?></th>
						<th><?php esc_html_e( 'Pts', 'scd-standings' ); ?></th>
						<th><?php esc_html_e( 'GD', 'scd-standings' ); ?></th>
						<th><?php esc_html_e( 'Status', 'scd-standings' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $changes as $row ) : ?>
						<tr class="<?php echo esc_attr( 'scd-qual-' . str_replace( 'still_', '', (string) ( $row['qualification_status'] ?? '' ) ) ); ?>">
							<td><?php echo esc_html( $row['team_name_ar'] ?? $row['team_name'] ?? '' ); ?></td>
							<td><?php echo esc_html( (string) ( $row['points'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $row['goal_difference'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $row['qualification_status'] ?? '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</section>
	<?php endif; ?>
</main>

<?php
get_footer();
