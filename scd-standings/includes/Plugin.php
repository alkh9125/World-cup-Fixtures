<?php

namespace SCD;

use SCD\Infrastructure\Database\Schema;
use SCD\CPT\TournamentCpt;
use SCD\CPT\TeamCpt;
use SCD\CPT\MatchCpt;
use SCD\CPT\ScenarioCpt;
use SCD\Infrastructure\Rest\RestApi;
use SCD\Admin\Dashboard;
use SCD\Admin\Settings;
use SCD\Admin\Importer;
use SCD\Admin\OverridePanel;
use SCD\Jobs\SyncJob;
use SCD\Jobs\RecalculationPipeline;
use SCD\SEO\SeoModule;
use SCD\Frontend\TemplateLoader;
use SCD\Frontend\Assets;

defined( 'ABSPATH' ) || exit;

/**
 * Composition root. Wires infrastructure, admin, frontend, and SEO modules
 * together. The Domain\ namespace is intentionally never referenced here
 * directly with WordPress globals - it is only ever invoked through the
 * pipeline/repositories, keeping it unit-testable in isolation.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {}

	public function boot(): void {
		load_plugin_textdomain( 'scd-standings', false, dirname( plugin_basename( SCD_STANDINGS_FILE ) ) . '/languages' );

		Schema::maybe_upgrade();

		( new TournamentCpt() )->register();
		( new TeamCpt() )->register();
		( new MatchCpt() )->register();
		( new ScenarioCpt() )->register();

		( new RestApi() )->register();

		( new Dashboard() )->register();
		( new Settings() )->register();
		( new Importer() )->register();
		( new OverridePanel() )->register();

		SyncJob::register();
		RecalculationPipeline::register();

		( new SeoModule() )->register();

		( new TemplateLoader() )->register();
		( new Assets() )->register();
	}
}
