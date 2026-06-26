<?php

namespace SCD\Admin;

use SCD\CPT\MatchCpt;
use SCD\CPT\TeamCpt;
use SCD\Infrastructure\Repositories\GroupRepository;
use SCD\Infrastructure\Repositories\MatchRepository;
use SCD\Infrastructure\Repositories\TeamRepository;
use SCD\Infrastructure\Repositories\TournamentRepository;
use SCD\Infrastructure\Repositories\TournamentTeamRepository;
use SCD\Jobs\SyncJob;

defined( 'ABSPATH' ) || exit;

/**
 * Manual data entry for one tournament: groups, teams (+ group assignment),
 * matches, and the knockout bracket template. This is the *only* place
 * scd_teams/scd_matches rows get created for manually-run tournaments, and
 * it is also where the initial team/group setup happens for API-Football
 * tournaments before Jobs\SyncJob takes over refreshing fixtures/results.
 *
 * Every team/match row created here also gets a matching scd_team/scd_match
 * post (post_name = the row's slug) - Frontend\Rewrites resolves nested
 * URLs against these posts, so a row without one would 404 on the frontend.
 */
final class Importer {

	public const MENU_SLUG = 'scd-standings-import';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_post_scd_add_group', [ $this, 'handle_add_group' ] );
		add_action( 'admin_post_scd_add_team', [ $this, 'handle_add_team' ] );
		add_action( 'admin_post_scd_add_match', [ $this, 'handle_add_match' ] );
		add_action( 'admin_post_scd_save_bracket_template', [ $this, 'handle_save_bracket_template' ] );
		add_action( 'admin_post_scd_sync_now', [ $this, 'handle_sync_now' ] );
	}

	public function add_menu(): void {
		add_submenu_page(
			Dashboard::MENU_SLUG,
			__( 'Import Data', 'scd-standings' ),
			__( 'Import Data', 'scd-standings' ),
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
		$tournament   = $tournamentId ? ( new TournamentRepository() )->find( $tournamentId ) : null;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import Data', 'scd-standings' ); ?></h1>

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

			<?php if ( ! $tournament ) : ?>
				<p><?php esc_html_e( 'Choose a tournament above to manage its groups, teams, and matches.', 'scd-standings' ); ?></p>
				<?php return; ?>
			<?php endif; ?>

			<?php
			$groups = ( new GroupRepository() )->findForTournament( $tournamentId );
			$teams  = ( new TeamRepository() )->findForTournament( $tournamentId );
			$matches = ( new MatchRepository() )->allForTournament( $tournamentId );
			?>

			<h2><?php esc_html_e( 'Groups', 'scd-standings' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Name', 'scd-standings' ); ?></th><th><?php esc_html_e( 'Slug', 'scd-standings' ); ?></th><th><?php esc_html_e( 'Order', 'scd-standings' ); ?></th></tr></thead>
				<tbody>
					<?php if ( empty( $groups ) ) : ?>
						<tr><td colspan="3"><?php esc_html_e( 'No groups yet.', 'scd-standings' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $groups as $group ) : ?>
						<tr><td><?php echo esc_html( $group['name'] ); ?></td><td><code><?php echo esc_html( $group['slug'] ); ?></code></td><td><?php echo esc_html( (string) $group['sort_order'] ); ?></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'scd_add_group' ); ?>
				<input type="hidden" name="action" value="scd_add_group">
				<input type="hidden" name="tournament_id" value="<?php echo esc_attr( (string) $tournamentId ); ?>">
				<input type="text" name="name" placeholder="<?php esc_attr_e( 'Group A', 'scd-standings' ); ?>" required>
				<input type="text" name="name_ar" placeholder="<?php esc_attr_e( 'Name (Arabic)', 'scd-standings' ); ?>" dir="rtl">
				<input type="text" name="slug" placeholder="group-a" required>
				<input type="number" name="sort_order" placeholder="0" style="width:5em">
				<?php submit_button( __( 'Add group', 'scd-standings' ), 'secondary', 'submit', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Teams', 'scd-standings' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Name', 'scd-standings' ); ?></th><th><?php esc_html_e( 'Slug', 'scd-standings' ); ?></th><th><?php esc_html_e( 'Group', 'scd-standings' ); ?></th><th><?php esc_html_e( 'Seed', 'scd-standings' ); ?></th></tr></thead>
				<tbody>
					<?php if ( empty( $teams ) ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'No teams yet.', 'scd-standings' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $teams as $team ) : ?>
						<tr>
							<td><?php echo esc_html( $team['name'] ); ?></td>
							<td><code><?php echo esc_html( $team['slug'] ); ?></code></td>
							<td><?php echo esc_html( $this->groupNameFor( $groups, $team['group_id'] ?? null ) ); ?></td>
							<td><?php echo esc_html( (string) ( $team['seed'] ?? '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'scd_add_team' ); ?>
				<input type="hidden" name="action" value="scd_add_team">
				<input type="hidden" name="tournament_id" value="<?php echo esc_attr( (string) $tournamentId ); ?>">
				<input type="text" name="name" placeholder="<?php esc_attr_e( 'Team name', 'scd-standings' ); ?>" required>
				<input type="text" name="name_ar" placeholder="<?php esc_attr_e( 'Name (Arabic)', 'scd-standings' ); ?>" dir="rtl">
				<input type="text" name="slug" placeholder="team-slug" required>
				<input type="text" name="country" placeholder="<?php esc_attr_e( 'Country', 'scd-standings' ); ?>">
				<select name="group_id">
					<option value=""><?php esc_html_e( '— No group —', 'scd-standings' ); ?></option>
					<?php foreach ( $groups as $group ) : ?>
						<option value="<?php echo esc_attr( (string) $group['id'] ); ?>"><?php echo esc_html( $group['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="number" name="seed" placeholder="<?php esc_attr_e( 'Seed', 'scd-standings' ); ?>" style="width:5em">
				<?php submit_button( __( 'Add team', 'scd-standings' ), 'secondary', 'submit', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Matches', 'scd-standings' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Round', 'scd-standings' ); ?></th><th><?php esc_html_e( 'Home', 'scd-standings' ); ?></th><th><?php esc_html_e( 'Away', 'scd-standings' ); ?></th><th><?php esc_html_e( 'Kickoff', 'scd-standings' ); ?></th><th><?php esc_html_e( 'Status', 'scd-standings' ); ?></th></tr></thead>
				<tbody>
					<?php if ( empty( $matches ) ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'No matches yet.', 'scd-standings' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $matches as $match ) : ?>
						<tr>
							<td><?php echo esc_html( $match['round'] ); ?></td>
							<td><?php echo esc_html( $this->teamNameFor( $teams, $match['home_team_id'] ) ); ?></td>
							<td><?php echo esc_html( $this->teamNameFor( $teams, $match['away_team_id'] ) ); ?></td>
							<td><?php echo esc_html( (string) $match['kickoff_at'] ); ?></td>
							<td><?php echo esc_html( $match['status'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'scd_add_match' ); ?>
				<input type="hidden" name="action" value="scd_add_match">
				<input type="hidden" name="tournament_id" value="<?php echo esc_attr( (string) $tournamentId ); ?>">
				<select name="round">
					<?php foreach ( [ 'group', 'round_of_16', 'quarter_final', 'semi_final', 'third_place', 'final' ] as $round ) : ?>
						<option value="<?php echo esc_attr( $round ); ?>"><?php echo esc_html( $round ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="group_id">
					<option value=""><?php esc_html_e( '— Knockout (no group) —', 'scd-standings' ); ?></option>
					<?php foreach ( $groups as $group ) : ?>
						<option value="<?php echo esc_attr( (string) $group['id'] ); ?>"><?php echo esc_html( $group['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="home_team_id">
					<option value=""><?php esc_html_e( '— Home team —', 'scd-standings' ); ?></option>
					<?php foreach ( $teams as $team ) : ?>
						<option value="<?php echo esc_attr( (string) $team['id'] ); ?>"><?php echo esc_html( $team['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="away_team_id">
					<option value=""><?php esc_html_e( '— Away team —', 'scd-standings' ); ?></option>
					<?php foreach ( $teams as $team ) : ?>
						<option value="<?php echo esc_attr( (string) $team['id'] ); ?>"><?php echo esc_html( $team['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="datetime-local" name="kickoff_at">
				<input type="text" name="venue" placeholder="<?php esc_attr_e( 'Venue', 'scd-standings' ); ?>">
				<?php submit_button( __( 'Add match', 'scd-standings' ), 'secondary', 'submit', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Bracket template', 'scd-standings' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'scd_save_bracket_template' ); ?>
				<input type="hidden" name="action" value="scd_save_bracket_template">
				<input type="hidden" name="tournament_id" value="<?php echo esc_attr( (string) $tournamentId ); ?>">
				<p class="description"><?php esc_html_e( 'JSON consumed by Domain\\BracketProjector - group_position rules address groups by single-letter code (e.g. "A"), matching this tournament\'s group slugs (group-a, group-b, ...).', 'scd-standings' ); ?></p>
				<textarea name="bracket_template_json" rows="12" class="large-text code"><?php echo esc_textarea( (string) ( $tournament['bracket_template_json'] ?? '' ) ); ?></textarea>
				<?php submit_button( __( 'Save bracket template', 'scd-standings' ), 'secondary', 'submit', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Automated sync', 'scd-standings' ); ?></h2>
			<p><?php esc_html_e( 'Runs immediately for every tournament with a non-manual data source (not just this one) - the same job WP-Cron runs on a schedule.', 'scd-standings' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'scd_sync_now' ); ?>
				<input type="hidden" name="action" value="scd_sync_now">
				<input type="hidden" name="tournament_id" value="<?php echo esc_attr( (string) $tournamentId ); ?>">
				<?php submit_button( __( 'Sync now', 'scd-standings' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	public function handle_add_group(): void {
		$tournamentId = $this->guardAndGetTournamentId( 'scd_add_group' );

		( new GroupRepository() )->insert( [
			'tournament_id' => $tournamentId,
			'name'          => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			'name_ar'       => sanitize_text_field( wp_unslash( $_POST['name_ar'] ?? '' ) ),
			'slug'          => sanitize_title( wp_unslash( $_POST['slug'] ?? '' ) ),
			'sort_order'    => (int) ( $_POST['sort_order'] ?? 0 ),
		] );

		$this->redirectToImporter( $tournamentId, 'group_added' );
	}

	public function handle_add_team(): void {
		$tournamentId = $this->guardAndGetTournamentId( 'scd_add_team' );

		$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$slug = sanitize_title( wp_unslash( $_POST['slug'] ?? '' ) );

		$postId = wp_insert_post( [
			'post_type'   => TeamCpt::SLUG,
			'post_title'  => $name,
			'post_name'   => $slug,
			'post_status' => 'publish',
		], true );

		$teamId = ( new TeamRepository() )->insert( [
			'post_id' => is_wp_error( $postId ) ? null : $postId,
			'slug'    => $slug,
			'name'    => $name,
			'name_ar' => sanitize_text_field( wp_unslash( $_POST['name_ar'] ?? '' ) ),
			'country' => sanitize_text_field( wp_unslash( $_POST['country'] ?? '' ) ),
		] );

		$groupId = (int) ( $_POST['group_id'] ?? 0 ) ?: null;
		$seed    = '' !== ( $_POST['seed'] ?? '' ) ? (int) $_POST['seed'] : null;

		( new TournamentTeamRepository() )->assign( $tournamentId, $teamId, $groupId, $seed );

		$this->redirectToImporter( $tournamentId, 'team_added' );
	}

	public function handle_add_match(): void {
		$tournamentId = $this->guardAndGetTournamentId( 'scd_add_match' );

		$homeTeamId = (int) ( $_POST['home_team_id'] ?? 0 ) ?: null;
		$awayTeamId = (int) ( $_POST['away_team_id'] ?? 0 ) ?: null;
		$round      = sanitize_key( wp_unslash( $_POST['round'] ?? 'group' ) );
		$groupId    = (int) ( $_POST['group_id'] ?? 0 ) ?: null;
		$kickoffRaw = sanitize_text_field( wp_unslash( $_POST['kickoff_at'] ?? '' ) );
		$kickoffAt  = '' !== $kickoffRaw ? gmdate( 'Y-m-d H:i:s', strtotime( $kickoffRaw ) ) : null;

		$teamRepo = new TeamRepository();
		$homeSlug = $homeTeamId ? ( $teamRepo->find( $homeTeamId )['slug'] ?? (string) $homeTeamId ) : 'tbd';
		$awaySlug = $awayTeamId ? ( $teamRepo->find( $awayTeamId )['slug'] ?? (string) $awayTeamId ) : 'tbd';
		$slug     = sanitize_title( "{$homeSlug}-vs-{$awaySlug}-{$round}" );

		$postId = wp_insert_post( [
			'post_type'   => MatchCpt::SLUG,
			'post_title'  => sprintf( '%s vs %s', $homeSlug, $awaySlug ),
			'post_name'   => $slug,
			'post_status' => 'publish',
		], true );

		( new MatchRepository() )->insert( [
			'post_id'       => is_wp_error( $postId ) ? null : $postId,
			'tournament_id' => $tournamentId,
			'group_id'      => $groupId,
			'round'         => $round,
			'home_team_id'  => $homeTeamId,
			'away_team_id'  => $awayTeamId,
			'kickoff_at'    => $kickoffAt,
			'status'        => 'scheduled',
			'venue'         => sanitize_text_field( wp_unslash( $_POST['venue'] ?? '' ) ),
		] );

		$this->redirectToImporter( $tournamentId, 'match_added' );
	}

	public function handle_save_bracket_template(): void {
		$tournamentId = $this->guardAndGetTournamentId( 'scd_save_bracket_template' );

		$json = wp_unslash( $_POST['bracket_template_json'] ?? '' );

		if ( null !== json_decode( $json, true ) || '' === trim( $json ) ) {
			( new TournamentRepository() )->update( $tournamentId, [ 'bracket_template_json' => $json ] );
		}

		$this->redirectToImporter( $tournamentId, 'bracket_template_saved' );
	}

	public function handle_sync_now(): void {
		$tournamentId = $this->guardAndGetTournamentId( 'scd_sync_now' );

		SyncJob::run();

		$this->redirectToImporter( $tournamentId, 'synced' );
	}

	private function guardAndGetTournamentId( string $nonceAction ): int {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'scd-standings' ) );
		}

		check_admin_referer( $nonceAction );

		return (int) ( $_POST['tournament_id'] ?? 0 );
	}

	private function redirectToImporter( int $tournamentId, string $notice ): void {
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tournament_id=' . $tournamentId . '&scd_notice=' . $notice ) );
		exit;
	}

	private function groupNameFor( array $groups, ?int $groupId ): string {
		if ( ! $groupId ) {
			return '';
		}

		foreach ( $groups as $group ) {
			if ( (int) $group['id'] === $groupId ) {
				return $group['name'];
			}
		}

		return '';
	}

	private function teamNameFor( array $teams, ?int $teamId ): string {
		if ( ! $teamId ) {
			return '';
		}

		foreach ( $teams as $team ) {
			if ( (int) $team['id'] === $teamId ) {
				return $team['name'];
			}
		}

		return '';
	}

	private function noticeText( string $key ): string {
		return match ( $key ) {
			'group_added'             => __( 'Group added.', 'scd-standings' ),
			'team_added'               => __( 'Team added.', 'scd-standings' ),
			'match_added'              => __( 'Match added.', 'scd-standings' ),
			'bracket_template_saved'   => __( 'Bracket template saved.', 'scd-standings' ),
			'synced'                   => __( 'Sync job ran. Recalculation will follow shortly if anything changed.', 'scd-standings' ),
			default                    => '',
		};
	}
}
