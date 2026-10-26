<?php
/**
 * Legacy mapping decision validator port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Legacy mapping decision validator port interface.
 */
interface Legacy_Mapping_Decision_Validator_Port_Interface {
	/**
	 * Validate legacy mapping decision validator port interface.
	 *
	 * @param int                            $actor_user_id Authenticated actor from the access context.
	 * @param array<int,array<string,mixed>> $manifest Manifest.
	 * @param array<int,array<string,mixed>> $decisions Decisions.
	 * @return void
	 */
	public function validate( int $actor_user_id, array $manifest, array $decisions ): void;
}
