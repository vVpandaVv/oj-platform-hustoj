<?php
/**
 * Export query port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Reporting\Export\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Export query port interface.
 */
interface Export_Query_Port_Interface {
	/**
	 * Find job for actor.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $job_id Job id.
	 * @return array<string,mixed>|null
	 */
	public function find_job_for_actor( int $actor_user_id, string $job_id ): ?array;
	/**
	 * Find artifact for actor.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $artifact_id Artifact id.
	 * @return array<string,mixed>|null
	 */
	public function find_artifact_for_actor( int $actor_user_id, string $artifact_id ): ?array;
}
