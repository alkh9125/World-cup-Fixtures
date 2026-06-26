<?php

namespace SCD\Infrastructure\Providers;

defined( 'ABSPATH' ) || exit;

/** Resolves the provider keyed by scd_tournaments.source_provider. */
final class ProviderFactory {

	public static function forId( string $providerId ): ProviderInterface {
		return match ( $providerId ) {
			'api_football' => new ApiFootballAdapter(),
			default        => new ManualAdapter(),
		};
	}
}
