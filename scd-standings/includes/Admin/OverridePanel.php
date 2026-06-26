<?php

namespace SCD\Admin;

use SCD\Infrastructure\Repositories\OverrideRepository;
use SCD\Infrastructure\Repositories\TeamRepository;
use SCD\Infrastructure\Repositories\TournamentRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Manual corrections layered on top of calculated standings (e.g. a
 * federation-issued points deduction the data provider doesn't model).
 * Creating or deactivating an override fires scd/overrides_changed, which
 * Jobs\RecalculationPipeline listens for to re-run and re-apply overrides
 * as the pipeline's last step - see RecalculationPipeline::applyOverrides().
 */
final class OverridePanel {

	public const MENU_SLUG = 'scd-standings-overrides';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_post_scd_create_override', [ $this, 'handle_create' ] );
		add_action( 'admin_post_scd_deactivate_override', [ $this, 'handle_deactivate' ] );
	}

	public function add_menu(): void {
		add_submenu_page(
			Dashboard::MENU_SLUG,
			__( 'Overrides', 'scd-standings' ),
			__( 'Overrides', 'scd-standings' ),
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'render' ],
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tournaments  = ( new TournamentRepository() )->all();
		$tournamentId = (int) ( $_GET['tournament_id'] ?? 0 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Standings Overrides', 'scd-standings' ); ?></h1>

			<?php if ( isset( $_GET['scd_notice'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $this->noticeText( sanitize_key( wp_unslash( $_GET['scd_notice'] ) ) ) ); ?></p></div>
			<?php endif; ?>

			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
				<label for="scd_tournament_picker"><?php esc_html_e( 'Tournament:', 'scd-standings' ); ?></label>
				<select id="scd_tournament_picker" name="tournament_id" onchange="this.form.submit()">
					<option value=""><?php esc_html_e( '— Select —', 'scd-standings' ); ?></option>
					<?php foreach ( $tournaments as $t ) : ?>
						<option value="<?php echo esc_attr( (string) $t['id'] ); ?>" <?php selected( $tournamentId, (int) $t['id'] ); ?>><?php echo esc_html( $t['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</form>

			<?php if ( ! $tournamentId ) : ?>
				<p><?php esc_html_e( 'Choose a tournament above to manage its overrides.', 'scd-standings' ); ?></p>
				<?php return; ?>
			<?php endif; ?>

			<?php
			$overrides = ( new OverrideRepository() )->findActiveForTournament( $tournamentId );
			$teamNames = ( new TeamRepository() )->findMany( array_map( static fn ( array $o ) => (int) $o['target_id'], $overrides ) );
			?>

			<h2><?php esc_html_e( 'Active overrides', 'scd-standings' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Target', 'scd-standings' ); ?></th>
						<th><?php esc_html_e( 'Field', 'scd-standings' ); ?></th>
						<th><?php esc_html_e( 'Value', 'scd-standings' ); ?></th>
						<th><?php esc_html_e( 'Reason', 'scd-standings' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $overrides ) ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'No active overrides.', 'scd-standings' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $overrides as $override ) : ?>
						<tr>
							<td>
								<?php echo esc_html( $override['target_type'] ); ?>
								#<?php echo esc_html( (string) $override['target_id'] ); ?>
								<?php if ( 'standing' === $override['target_type'] && isset( $teamNames[ (int) $override['target_id'] ] ) ) : ?>
									(<?php echo esc_html( $teamNames[ (int) $override['target_id'] ]['name'] ); ?>)
								<?php endif; ?>
							</td>
							<td><code><?php echo esc_html( $override['field'] ); ?></code></td>
							<td><?php echo esc_html( (string) $override['value'] ); ?></td>
							<td><?php echo esc_html( (string) $override['reason'] ); ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<?php wp_nonce_field( 'scd_deactivate_override' ); ?>
									<input type="hidden" name="action" value="scd_deactivate_override">
									<input type="hidden" name="override_id" value="<?php echo esc_attr( (string) $override['id'] ); ?>">
									<input type="hidden" name="tournament_id" value="<?php echo esc_attr( (string) $tournamentId ); ?>">
									<?php submit_button( __( 'Deactivate', 'scd-standings' ), 'secondary', 'submit', false ); ?>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Create an override', 'scd-standings' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'scd_create_override' ); ?>
				<input type="hidden" name="action" value="scd_create_override">
				<input type="hidden" name="tournament_id" value="<?php echo esc_attr( (string) $tournamentId ); ?>">
				<table class="form-table">
					<tr>
						<th><label for="scd_target_type"><?php esc_html_e( 'Target type', 'scd-standings' ); ?></label></th>
						<td>
							<select id="scd_target_type" name="target_type">
								<option value="standing"><?php esc_html_e( 'Standing row (by team)', 'scd-standings' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="scd_target_id"><?php esc_html_e( 'Team ID', 'scd-standings' ); ?></label></th>
						<td><input type="number" id="scd_target_id" name="target_id" required></td>
					</tr>
					<tr>
						<th><label for="scd_field"><?php esc_html_e( 'Field', 'scd-standings' ); ?></label></th>
						<td>
							<select id="scd_field" name="field">
								<?php foreach ( [ 'points', 'goal_difference', 'goals_for', 'goals_against', 'rank', 'qualification_status', 'tie_break_notes' ] as $field ) : ?>
									<option value="<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $field ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="scd_value"><?php esc_html_e( 'New value', 'scd-standings' ); ?></label></th>
						<td><input type="text" id="scd_value" name="value" class="regular-text" required></td>
					</tr>
					<tr>
						<th><label for="scd_reason"><?php esc_html_e( 'Reason', 'scd-standings' ); ?></label></th>
						<td><input type="text" id="scd_reason" name="reason" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. FIFA disciplinary points deduction', 'scd-standings' ); ?>"></td>
					</tr>
				</table>
				<?php submit_button( __( 'Create override', 'scd-standings' ) ); ?>
			</form>
		</div>
		<?php
	}

	public function handle_create(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'scd-standings' ) );
		}

		check_admin_referer( 'scd_create_override' );

		$tournamentId = (int) ( $_POST['tournament_id'] ?? 0 );

		( new OverrideRepository() )->create(
			$tournamentId,
			sanitize_key( wp_unslash( $_POST['target_type'] ?? 'standing' ) ),
			(int) ( $_POST['target_id'] ?? 0 ),
			sanitize_key( wp_unslash( $_POST['field'] ?? '' ) ),
			sanitize_text_field( wp_unslash( $_POST['value'] ?? '' ) ),
			sanitize_text_field( wp_unslash( $_POST['reason'] ?? '' ) ),
			get_current_user_id(),
		);

		do_action( 'scd/overrides_changed', $tournamentId );

		$this->redirect( $tournamentId, 'override_created' );
	}

	public function handle_deactivate(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'scd-standings' ) );
		}

		check_admin_referer( 'scd_deactivate_override' );

		$tournamentId = (int) ( $_POST['tournament_id'] ?? 0 );

		( new OverrideRepository() )->deactivate( (int) ( $_POST['override_id'] ?? 0 ) );

		do_action( 'scd/overrides_changed', $tournamentId );

		$this->redirect( $tournamentId, 'override_deactivated' );
	}

	private function redirect( int $tournamentId, string $notice ): void {
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tournament_id=' . $tournamentId . '&scd_notice=' . $notice ) );
		exit;
	}

	private function noticeText( string $key ): string {
		return match ( $key ) {
			'override_created'     => __( 'Override created.', 'scd-standings' ),
			'override_deactivated' => __( 'Override deactivated.', 'scd-standings' ),
			default                 => '',
		};
	}
}
