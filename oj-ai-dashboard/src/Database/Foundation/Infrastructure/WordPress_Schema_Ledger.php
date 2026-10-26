<?php
/**
 * WordPress schema ownership and migration ledger adapter.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Database\Foundation\Infrastructure;

use InvalidArgumentException;
use OJAI\Dashboard\Database\Foundation\Installation_State;
use OJAI\Dashboard\Database\Foundation\Migration_Definition;
use OJAI\Dashboard\Database\Foundation\Migration_Ledger_State;
use OJAI\Dashboard\Database\Foundation\Port\Schema_Ledger_Port_Interface;
use OJAI\Dashboard\Database\Foundation\Schema_Catalog;
use OJAI\Dashboard\Database\Foundation\Schema_Migration_Checkpoint;
use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists checkpoints in the two literal bootstrap tables.
 */
final class WordPress_Schema_Ledger implements Schema_Ledger_Port_Interface {
	private const INSTALLATIONS_TABLE = 'oj_ai_installations';
	private const MIGRATIONS_TABLE    = 'oj_ai_schema_migrations';

	/**
	 * Database.
	 *
	 * @var object
	 */
	private object $database;

	/**
	 * Initialize WordPress schema ledger dependencies and state.
	 *
	 * @param object $database Database.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct( object $database ) {
		foreach ( array( 'get_row', 'get_results', 'get_var', 'prepare', 'insert', 'update' ) as $method ) {
			if ( ! method_exists( $database, $method ) ) {
				throw new InvalidArgumentException( 'The database adapter is missing required ledger methods.' );
			}
		}
		$this->database = $database;
	}

	/**
	 * Installation row count.
	 *
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function installation_row_count(): int {
		$count = $this->database->get_var(
			'SELECT COUNT(*) FROM `' . self::INSTALLATIONS_TABLE . '`'
		);
		if ( ! is_int( $count )
			&& ! ( is_string( $count ) && ctype_digit( $count ) ) ) {
			throw new RuntimeException( 'schema_installation_ledger_count_failed' );
		}

		return (int) $count;
	}

	/**
	 * Find installation.
	 *
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_installation(): ?array {
		$sql = $this->database->prepare(
			'SELECT * FROM `' . self::INSTALLATIONS_TABLE . '` WHERE addon_id = %s LIMIT 1',
			Schema_Catalog::ADDON_ID
		);
		if ( ! is_string( $sql ) ) {
			throw new RuntimeException( 'schema_ledger_prepare_failed' );
		}
		$mode = defined( 'ARRAY_A' ) ? constant( 'ARRAY_A' ) : 'ARRAY_A';
		$row  = $this->database->get_row( $sql, $mode );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * All migrations.
	 *
	 * @return array<string,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function all_migrations(): array {
		$mode = defined( 'ARRAY_A' ) ? constant( 'ARRAY_A' ) : 'ARRAY_A';
		$rows = $this->database->get_results(
			'SELECT * FROM `' . self::MIGRATIONS_TABLE . '` ORDER BY migration_id ASC',
			$mode
		);
		if ( ! is_array( $rows ) ) {
			throw new RuntimeException( 'schema_ledger_read_failed' );
		}

		$records = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$migration_id = (string) ( $row['migration_id'] ?? '' );
			if ( '' === $migration_id || isset( $records[ $migration_id ] ) ) {
				throw new RuntimeException( 'schema_ledger_invalid' );
			}
			$records[ $migration_id ] = $row;
		}

		return $records;
	}

	/**
	 * Create installation.
	 *
	 * @param string $owner_marker Owner marker.
	 * @param string $schema_version Schema version.
	 * @param string $schema_fingerprint Schema fingerprint.
	 * @param string $state State.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $occurred_at_utc Occurred at utc.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function create_installation(
		string $owner_marker,
		string $schema_version,
		string $schema_fingerprint,
		string $state,
		int $actor_user_id,
		string $occurred_at_utc
	): void {
		$this->assert_installation_state( $state );
		$data   = array(
			'lock_version'         => 0,
			'created_by_user_id'   => $actor_user_id,
			'created_at_utc'       => $occurred_at_utc,
			'updated_by_user_id'   => $actor_user_id,
			'updated_at_utc'       => $occurred_at_utc,
			'addon_id'             => Schema_Catalog::ADDON_ID,
			'owner_marker'         => $owner_marker,
			'schema_version'       => $schema_version,
			'schema_fingerprint'   => $schema_fingerprint,
			'state'                => $state,
			'last_verified_at_utc' => null,
		);
		$result = $this->database->insert( self::INSTALLATIONS_TABLE, $data, $this->formats( $data ) );
		if ( false === $result ) {
			throw new RuntimeException( 'schema_installation_ledger_create_failed' );
		}
	}

	/**
	 * Update installation.
	 *
	 * @param string $schema_version Schema version.
	 * @param string $schema_fingerprint Schema fingerprint.
	 * @param string $state State.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $occurred_at_utc Occurred at utc.
	 * @param bool   $verified Verified.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function update_installation(
		string $schema_version,
		string $schema_fingerprint,
		string $state,
		int $actor_user_id,
		string $occurred_at_utc,
		bool $verified
	): void {
		$this->assert_installation_state( $state );
		$current = $this->find_installation();
		if ( null === $current ) {
			throw new RuntimeException( 'schema_installation_ledger_missing' );
		}

		$data = array(
			'lock_version'       => (int) ( $current['lock_version'] ?? 0 ) + 1,
			'updated_by_user_id' => $actor_user_id,
			'updated_at_utc'     => $occurred_at_utc,
			'schema_version'     => $schema_version,
			'schema_fingerprint' => $schema_fingerprint,
			'state'              => $state,
		);
		if ( $verified ) {
			$data['last_verified_at_utc'] = $occurred_at_utc;
		}
		$this->update_row(
			self::INSTALLATIONS_TABLE,
			$data,
			array( 'addon_id' => Schema_Catalog::ADDON_ID )
		);
	}

	/**
	 * Start migration.
	 *
	 * @param Migration_Definition $migration Migration.
	 * @param array<string,mixed>  $checkpoint Checkpoint.
	 * @param string               $started_at_utc Started at utc.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function start_migration(
		Migration_Definition $migration,
		array $checkpoint,
		string $started_at_utc
	): void {
		$data   = array_merge(
			array(
				'migration_id'     => $migration->id(),
				'checksum'         => $migration->checksum(),
				'state'            => Migration_Ledger_State::RUNNING,
				'attempt_count'    => 1,
				'started_at_utc'   => $started_at_utc,
				'completed_at_utc' => null,
				'error_code'       => null,
				'created_at_utc'   => $started_at_utc,
			),
			$this->checkpoint_columns( $checkpoint )
		);
		$result = $this->database->insert( self::MIGRATIONS_TABLE, $data, $this->formats( $data ) );
		if ( false === $result ) {
			throw new RuntimeException( 'schema_migration_ledger_create_failed' );
		}
	}

	/**
	 * Resume migration.
	 *
	 * @param Migration_Definition $migration Migration.
	 * @param int                  $attempt_count Attempt count.
	 * @param array<string,mixed>  $checkpoint Checkpoint.
	 * @param string               $started_at_utc Started at utc.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function resume_migration(
		Migration_Definition $migration,
		int $attempt_count,
		array $checkpoint,
		string $started_at_utc
	): void {
		if ( $attempt_count < 2 ) {
			throw new InvalidArgumentException( 'A resumed migration must increment its attempt count.' );
		}
		$data = array_merge(
			array(
				'checksum'         => $migration->checksum(),
				'state'            => Migration_Ledger_State::RUNNING,
				'attempt_count'    => $attempt_count,
				'started_at_utc'   => $started_at_utc,
				'completed_at_utc' => null,
				'error_code'       => null,
			),
			$this->checkpoint_columns( $checkpoint )
		);
		$this->update_row( self::MIGRATIONS_TABLE, $data, array( 'migration_id' => $migration->id() ) );
	}

	/**
	 * Save checkpoint.
	 *
	 * @param string              $migration_id Migration id.
	 * @param array<string,mixed> $checkpoint Checkpoint.
	 * @return void
	 */
	public function save_checkpoint( string $migration_id, array $checkpoint ): void {
		$this->assert_migration_id( $migration_id );
		$this->update_row(
			self::MIGRATIONS_TABLE,
			$this->checkpoint_columns( $checkpoint ),
			array( 'migration_id' => $migration_id )
		);
	}

	/**
	 * Finish migration.
	 *
	 * @param string              $migration_id Migration id.
	 * @param string              $state State.
	 * @param array<string,mixed> $checkpoint Checkpoint.
	 * @param string|null         $error_code Error code.
	 * @param string              $completed_at_utc Completed at utc.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function finish_migration(
		string $migration_id,
		string $state,
		array $checkpoint,
		?string $error_code,
		string $completed_at_utc
	): void {
		$this->assert_migration_id( $migration_id );
		if ( ! in_array( $state, array( Migration_Ledger_State::APPLIED, Migration_Ledger_State::REPAIR_REQUIRED, Migration_Ledger_State::BLOCKED ), true ) ) {
			throw new InvalidArgumentException( 'A migration attempt may only finish in a terminal attempt state.' );
		}
		if ( null !== $error_code && 1 !== preg_match( '/^[a-z][a-z0-9_]{2,99}$/', $error_code ) ) {
			throw new InvalidArgumentException( 'Migration errors must use stable codes.' );
		}

		$data = array_merge(
			array(
				'state'            => $state,
				'completed_at_utc' => $completed_at_utc,
				'error_code'       => $error_code,
			),
			$this->checkpoint_columns( $checkpoint )
		);
		$this->update_row( self::MIGRATIONS_TABLE, $data, array( 'migration_id' => $migration_id ) );
	}

	/**
	 * Checkpoint columns.
	 *
	 * @param array<string,mixed> $checkpoint Checkpoint.
	 * @return array<string,mixed>
	 */
	private function checkpoint_columns( array $checkpoint ): array {
		return Schema_Migration_Checkpoint::columns( $checkpoint );
	}

	/**
	 * Update row.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data Data.
	 * @param array<string,mixed> $where Where.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function update_row( string $table, array $data, array $where ): void {
		$result = $this->database->update(
			$table,
			$data,
			$where,
			$this->formats( $data ),
			$this->formats( $where )
		);
		if ( false === $result ) {
			throw new RuntimeException( 'schema_ledger_update_failed' );
		}
	}

	/**
	 * Formats.
	 *
	 * @param array<string,mixed> $values Values.
	 * @return array<int,string>
	 */
	private function formats( array $values ): array {
		$formats = array();
		foreach ( $values as $value ) {
			$formats[] = is_int( $value ) ? '%d' : '%s';
		}

		return $formats;
	}

	/**
	 * Reject invalid installation state.
	 *
	 * @param string $state State.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_installation_state( string $state ): void {
		if ( ! in_array( $state, Installation_State::all(), true ) ) {
			throw new InvalidArgumentException( 'Unknown installation state.' );
		}
	}

	/**
	 * Reject invalid migration id.
	 *
	 * @param string $migration_id Migration id.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_migration_id( string $migration_id ): void {
		if ( 1 !== preg_match( '/^[0-9]{12}_[a-z0-9_]+$/', $migration_id ) ) {
			throw new InvalidArgumentException( 'Invalid migration ID.' );
		}
	}
}
