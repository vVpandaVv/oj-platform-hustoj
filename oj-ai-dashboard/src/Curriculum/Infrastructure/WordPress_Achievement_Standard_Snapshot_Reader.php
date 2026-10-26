<?php
/**
 * WordPress achievement standard snapshot reader.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Curriculum\Infrastructure;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Application\Worksheet_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Port\Achievement_Standard_Snapshot_Port_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the Curriculum-side read used to freeze worksheet link labels.
 */
final class WordPress_Achievement_Standard_Snapshot_Reader implements Achievement_Standard_Snapshot_Port_Interface {
	private const TABLE_STANDARDS   = 'oj_ai_achievement_standards';
	private const TABLE_CURRICULUMS = 'oj_ai_curriculums';

	/**
	 * Database.
	 *
	 * @var object|null
	 */
	private ?object $database;

	/**
	 * Initialize WordPress achievement standard snapshot reader dependencies and state.
	 *
	 * @param object|null $database Database.
	 */
	public function __construct( ?object $database = null ) {
		$this->database = $database;
	}

	/**
	 * Find snapshots.
	 *
	 * @param array<array-key,mixed> $standard_public_ids Values validated by this boundary.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_snapshots( array $standard_public_ids ): array {
		if ( ! array_is_list( $standard_public_ids ) ) {
			throw new InvalidArgumentException( 'Achievement standard IDs must be a JSON list.' );
		}
		$seen = array();
		foreach ( $standard_public_ids as $standard_id ) {
			if ( ! is_string( $standard_id ) ) {
				throw new InvalidArgumentException( 'Achievement standard public ID is invalid.' );
			}
			new Public_Id( $standard_id );
			if ( isset( $seen[ $standard_id ] ) ) {
				throw new InvalidArgumentException( 'Achievement standard public IDs must be unique.' );
			}
			$seen[ $standard_id ] = true;
		}
		$sorted = $standard_public_ids;
		sort( $sorted, SORT_STRING );
		if ( $sorted !== $standard_public_ids ) {
			throw new InvalidArgumentException( 'Achievement standard public IDs must be sorted.' );
		}
		if ( array() === $standard_public_ids ) {
			return array();
		}

		$database     = $this->database();
		$placeholders = implode( ', ', array_fill( 0, count( $standard_public_ids ), '%s' ) );
		$sql          = 'SELECT standard.public_id AS standard_public_id, '
			. 'curriculum.source_version AS standard_source_version, '
			. 'standard.code AS standard_code_snapshot, standard.title AS standard_label_snapshot '
			. 'FROM ' . self::TABLE_STANDARDS . ' standard '
			. 'INNER JOIN ' . self::TABLE_CURRICULUMS . ' curriculum ON curriculum.id = standard.curriculum_id '
			. 'WHERE standard.public_id IN (' . $placeholders . ') ORDER BY standard.public_id ASC';
		$prepared     = $database->prepare( $sql, ...$standard_public_ids );
		if ( ! is_string( $prepared ) || '' === $prepared ) {
			throw Worksheet_Failure::storage_unavailable();
		}
		if ( property_exists( $database, 'last_error' ) ) {
			$database->last_error = '';
		}
		$rows = $database->get_results( $prepared, $this->array_output() );
		if (
			! is_array( $rows )
			|| ( property_exists( $database, 'last_error' ) && '' !== trim( (string) $database->last_error ) )
		) {
			throw Worksheet_Failure::storage_unavailable();
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				throw Worksheet_Failure::storage_unavailable();
			}
		}

		return array_values( $rows );
	}

	/**
	 * Database.
	 *
	 * @return object
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function database(): object {
		$database = $this->database ?? ( $GLOBALS['wpdb'] ?? null );
		if (
			! is_object( $database )
			|| ! method_exists( $database, 'prepare' )
			|| ! method_exists( $database, 'get_results' )
		) {
			throw Worksheet_Failure::storage_unavailable();
		}
		return $database;
	}

	/**
	 * Array output.
	 *
	 * @return mixed
	 */
	private function array_output() {
		return defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A';
	}
}
