<?php

namespace SCD\Infrastructure\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * The "no provider" provider. Tournaments with source_provider = 'manual'
 * are never touched by Jobs\SyncJob; their fixtures/results are entered
 * and edited entirely through Admin\Importer and Admin\OverridePanel.
 */
final class ManualAdapter implements ProviderInterface {

	public function id(): string {
		return 'manual';
	}

	public function fetchFixtures( string $externalTournamentId ): array {
		return [];
	}
}
