<?php

namespace SCD\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin-wide settings: the API-Football key (Infrastructure\Providers\ApiFootballAdapter
 * reads it straight from the option) and the WP-Cron sync interval
 * (Jobs\SyncJob reads scd_sync_interval_minutes when it registers its
 * custom cron schedule).
 */
final class Settings {

	public const MENU_SLUG = 'scd-standings-settings';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_post_scd_save_settings', [ $this, 'handle_save' ] );
	}

	public function add_menu(): void {
		add_submenu_page(
			Dashboard::MENU_SLUG,
			__( 'Settings', 'scd-standings' ),
			__( 'Settings', 'scd-standings' ),
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'render' ],
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$apiKey         = (string) get_option( 'scd_api_football_key', '' );
		$intervalMinutes = (int) get_option( 'scd_sync_interval_minutes', 60 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'SCD Standings Settings', 'scd-standings' ); ?></h1>

			<?php if ( isset( $_GET['scd_notice'] ) && 'settings_saved' === sanitize_key( wp_unslash( $_GET['scd_notice'] ) ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'scd-standings' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'scd_save_settings' ); ?>
				<input type="hidden" name="action" value="scd_save_settings">
				<table class="form-table">
					<tr>
						<th><label for="scd_api_football_key"><?php esc_html_e( 'API-Football key', 'scd-standings' ); ?></label></th>
						<td>
							<input type="text" id="scd_api_football_key" name="api_football_key" class="regular-text" value="<?php echo esc_attr( $apiKey ); ?>">
							<p class="description"><?php esc_html_e( 'From your api-football.com / api-sports.io dashboard. Leave blank to disable automated sync entirely.', 'scd-standings' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="scd_sync_interval_minutes"><?php esc_html_e( 'Sync interval (minutes)', 'scd-standings' ); ?></label></th>
						<td>
							<input type="number" id="scd_sync_interval_minutes" name="sync_interval_minutes" value="<?php echo esc_attr( (string) $intervalMinutes ); ?>" min="15" step="1">
							<p class="description"><?php esc_html_e( 'API-Football\'s free tier allows 100 requests/day - 60 minutes (the default) keeps every tournament well under that. Minimum 15.', 'scd-standings' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save settings', 'scd-standings' ) ); ?>
			</form>
		</div>
		<?php
	}

	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'scd-standings' ) );
		}

		check_admin_referer( 'scd_save_settings' );

		update_option( 'scd_api_football_key', sanitize_text_field( wp_unslash( $_POST['api_football_key'] ?? '' ) ) );
		update_option( 'scd_sync_interval_minutes', max( 15, (int) ( $_POST['sync_interval_minutes'] ?? 60 ) ) );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&scd_notice=settings_saved' ) );
		exit;
	}
}
