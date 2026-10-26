<?php
/**
 * Preview legacy migration.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Application;

use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Migration_Plan;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Site_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Preview_Token_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Source_Inspector_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Preview legacy migration.
 */
final class Preview_Legacy_Migration {
	public const PLAN_PROFILE = Legacy_Migration_Plan::PROFILE;

	/**
	 * Initialize preview legacy migration dependencies and state.
	 *
	 * @param Legacy_Source_Inspector_Port_Interface $sources Sources.
	 * @param Legacy_Preview_Token_Port_Interface    $tokens Tokens.
	 * @param Legacy_Migration_Site_Port_Interface   $sites Sites.
	 * @param Canonical_Json                         $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct(
		private Legacy_Source_Inspector_Port_Interface $sources,
		private Legacy_Preview_Token_Port_Interface $tokens,
		private Legacy_Migration_Site_Port_Interface $sites,
		private Canonical_Json $canonical
	) {
	}

	/**
	 * Execute preview legacy migration.
	 *
	 * @param Preview_Legacy_Migration_Command $command Command.
	 * @return Preview_Legacy_Migration_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Preview_Legacy_Migration_Command $command ): Preview_Legacy_Migration_Result {
		try {
			$site_id = $this->sites->site_id();
			$source  = $this->sources->inspect( $command->source_profile() );
			if ( $source->profile() !== $command->source_profile() ) {
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_PROFILE_MISMATCH );
			}

			$plan = ( new Legacy_Migration_Plan( $this->canonical ) )->build( $site_id, $source );

			$token = $this->tokens->issue(
				$site_id,
				$source->source_fingerprint(),
				(string) $plan['plan_hash'],
				$command->idempotency_key_hash()
			);

			return new Preview_Legacy_Migration_Result(
				$plan,
				$token['preview_token'],
				$token['issued_at_utc'],
				$token['expires_at_utc']
			);
		} catch ( Legacy_Migration_Failure $failure ) {
			throw $failure;
		} catch ( Throwable $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_UNAVAILABLE, $error );
		}
	}
}
