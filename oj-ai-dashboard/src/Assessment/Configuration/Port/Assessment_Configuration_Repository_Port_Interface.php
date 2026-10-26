<?php
/**
 * Assessment configuration repository port.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Assessment\Configuration\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Assessment configuration repository port.
 */
interface Assessment_Configuration_Repository_Port_Interface {
	/**
	 * List revisions.
	 *
	 * @param string $resource_type Resource type.
	 * @return array<int,array<string,mixed>>
	 */
	public function list_revisions( string $resource_type ): array;

	/**
	 * Create revision.
	 *
	 * @param array<string,mixed> $command Command.
	 * @return array<string,mixed>
	 */
	public function create_revision( array $command ): array;

	/**
	 * Activate revision.
	 *
	 * @param array<string,mixed> $command Command.
	 * @return array<string,mixed>
	 */
	public function activate_revision( array $command ): array;

	/**
	 * Seed defaults.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $correlation_id Request correlation identifier for diagnostics.
	 * @return array<string,string>
	 */
	public function seed_defaults( int $actor_user_id, string $correlation_id ): array;
}
