<?php

namespace SCD\Admin;

use SCD\CPT\TournamentCpt;
use SCD\Infrastructure\Repositories\TournamentRepository;
use SCD\Jobs\RecalculationPipeline;

defined( 'ABSPATH' ) || exit;

/**
 * Top-level "SCD Standings" admin menu. Owns the scd-standings menu slug
 * that the CPTs (TournamentCpt etc.) and the other Admin\* pages attach
 * themselves under via show_in_menu/add_submenu_page.
 */
final class Dashboard {

	public const MENU_SLUG = 'scd-standings';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_post_scd_create_tournament', [ $this, 'handle_create_tournament' ] );
		add_action( 'admin_post_scd_recalculate', [ $this, 'handle_recalculate' ] );
	}

	public function add_menu(): void {
		add_menu_page(
			__( 'SCD Standings', 'scd-standings' ),
			__( 'SCD Standings', 'scd-standings' ),
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'render' ],
			'dashicons-awards',
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tournaments = ( new TournamentRepository() )->all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'SCD Standings & Scenarios', 'scd-standings' ); ?></h1>

			<?php if ( isset( $_GET['scd_notice'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $this->noticeText( sanitize_key( wp_unslash( $_GET['scd_notice'] ) ) ) ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Tournaments', 'scd-standings' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'scd-standings' ); ?></th>
						<th><?php esc_html_e( 'Slug', 'scd-standings' ); ?></th>
						<th><?php esc_html_e( 'Season', 'scd-standings' ); ?></th>
						<th><?php esc_html_e( 'Provider', 'scd-standings' ); ?></th>
						<th><?php esc_html_e( 'Stage', 'scd-standings' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $tournaments ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'No tournaments yet - create one below.', 'scd-standings' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $tournaments as $tournament ) : ?>
						<tr>
							<td><?php echo esc_html( $tournament['name'] ); ?></td>
							<td><code><?php echo esc_html( $tournament['slug'] ); ?></code></td>
							<td><?php echo esc_html( (string) $tournament['season'] ); ?></td>
							<td><?php echo esc_html( (string) $tournament['source_provider'] ); ?></td>
							<td><?php echo esc_html( $tournament['current_stage'] ); ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
									<?php wp_nonce_field( 'scd_recalculate' ); ?>
									<input type="hidden" name="action" value="scd_recalculate">
									<input type="hidden" name="tournament_id" value="<?php echo esc_attr( $tournament['id'] ); ?>">
									<?php submit_button( __( 'Recalculate now', 'scd-standings' ), 'secondary', 'submit', false ); ?>
								</form>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Importer::MENU_SLUG . '&tournament_id=' . (int) $tournament['id'] ) ); ?>" class="button"><?php esc_html_e( 'Import data', 'scd-standings' ); ?></a>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . OverridePanel::MENU_SLUG . '&tournament_id=' . (int) $tournament['id'] ) ); ?>" class="button"><?php esc_html_e( 'Overrides', 'scd-standings' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Create a tournament', 'scd-standings' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'scd_create_tournament' ); ?>
				<input type="hidden" name="action" value="scd_create_tournament">
				<table class="form-table">
					<tr>
						<th><label for="scd_name"><?php esc_html_e( 'Name', 'scd-standings' ); ?></label></th>
						<td><input type="text" id="scd_name" name="name" class="regular-text" required></td>
					</tr>
					<tr>
						<th><label for="scd_name_ar"><?php esc_html_e( 'Name (Arabic)', 'scd-standings' ); ?></label></th>
						<td><input type="text" id="scd_name_ar" name="name_ar" class="regular-text" dir="rtl"></td>
					</tr>
					<tr>
						<th><label for="scd_slug"><?php esc_html_e( 'Slug', 'scd-standings' ); ?></label></th>
						<td><input type="text" id="scd_slug" name="slug" class="regular-text" placeholder="club-world-cup" required></td>
					</tr>
					<tr>
						<th><label for="scd_season"><?php esc_html_e( 'Season', 'scd-standings' ); ?></label></th>
						<td><input type="text" id="scd_season" name="season" class="regular-text" placeholder="2026"></td>
					</tr>
					<tr>
						<th><label for="scd_qualifiers"><?php esc_html_e( 'Qualifiers per group', 'scd-standings' ); ?></label></th>
						<td><input type="number" id="scd_qualifiers" name="qualifiers_per_group" value="2" min="1" max="8"></td>
					</tr>
					<tr>
						<th><label for="scd_provider"><?php esc_html_e( 'Data source', 'scd-standings' ); ?></label></th>
						<td>
							<select id="scd_provider" name="source_provider">
								<option value="manual"><?php esc_html_e( 'Manual entry', 'scd-standings' ); ?></option>
								<option value="api_football"><?php esc_html_e( 'API-Football (automated)', 'scd-standings' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="scd_external_id"><?php esc_html_e( 'Provider reference (league:season)', 'scd-standings' ); ?></label></th>
						<td><input type="text" id="scd_external_id" name="external_id" class="regular-text" placeholder="1:2026"></td>
					</tr>
				</table>
				<?php submit_button( __( 'Create tournament', 'scd-standings' ) ); ?>
			</form>
		</div>
		<?php
	}

	public function handle_create_tournament(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'scd-standings' ) );
		}

		check_admin_referer( 'scd_create_tournament' );

		$qualifiersPerGroup = max( 1, (int) ( $_POST['qualifiers_per_group'] ?? 2 ) );
		$slug               = sanitize_title( wp_unslash( $_POST['slug'] ?? '' ) );
		$name               = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );

		// The CPT post is what Frontend\Rewrites resolves '/{tournament}/' against -
		// without it the tournament's own page (and everything nested under it) 404s.
		$postId = wp_insert_post( [
			'post_type'   => TournamentCpt::SLUG,
			'post_title'  => $name,
			'post_name'   => $slug,
			'post_status' => 'publish',
		], true );

		( new TournamentRepository() )->insert( [
			'post_id'              => is_wp_error( $postId ) ? null : $postId,
			'slug'                 => $slug,
			'name'                 => $name,
			'name_ar'              => sanitize_text_field( wp_unslash( $_POST['name_ar'] ?? '' ) ),
			'season'               => sanitize_text_field( wp_unslash( $_POST['season'] ?? '' ) ),
			'format_json'          => wp_json_encode( [ 'qualifiers_per_group' => $qualifiersPerGroup ] ),
			'current_stage'        => 'group',
			'status'               => 'upcoming',
			'source_provider'      => sanitize_key( wp_unslash( $_POST['source_provider'] ?? 'manual' ) ),
			'external_id'          => sanitize_text_field( wp_unslash( $_POST['external_id'] ?? '' ) ),
		] );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&scd_notice=tournament_created' ) );
		exit;
	}

	public function handle_recalculate(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'scd-standings' ) );
		}

		check_admin_referer( 'scd_recalculate' );

		$tournamentId = (int) ( $_POST['tournament_id'] ?? 0 );

		if ( $tournamentId ) {
			RecalculationPipeline::run( $tournamentId );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&scd_notice=recalculated' ) );
		exit;
	}

	private function noticeText( string $key ): string {
		return match ( $key ) {
			'tournament_created' => __( 'Tournament created.', 'scd-standings' ),
			'recalculated'       => __( 'Recalculation complete.', 'scd-standings' ),
			default               => '',
		};
	}
}
