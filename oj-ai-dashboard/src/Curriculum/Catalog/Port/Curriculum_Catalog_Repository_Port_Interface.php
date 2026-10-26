<?php
/**
 * Curriculum catalog repository port.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Curriculum\Catalog\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Curriculum catalog repository port.
 */
interface Curriculum_Catalog_Repository_Port_Interface {
	/**
	 * List curriculums.
	 *
	 * @param string $search Search.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array<int,array<string,mixed>>
	 */
	public function list_curriculums( string $search, int $limit ): array;

	/**
	 * List standards.
	 *
	 * @param string $curriculum_id Curriculum id.
	 * @param string $search Search.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array<int,array<string,mixed>>
	 */
	public function list_standards( string $curriculum_id, string $search, int $limit ): array;

	/**
	 * Save curriculum.
	 *
	 * @param array<string,mixed> $command Command.
	 * @return array<string,mixed>
	 */
	public function save_curriculum( array $command ): array;

	/**
	 * Save standard.
	 *
	 * @param array<string,mixed> $command Command.
	 * @return array<string,mixed>
	 */
	public function save_standard( array $command ): array;
}
