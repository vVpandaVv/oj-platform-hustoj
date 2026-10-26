<?php
/**
 * WordPress database adapter contract for the frozen add-on schema.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Database\Foundation\Infrastructure\WordPress_Schema_Inspector;
use OJAI\Dashboard\Database\Foundation\Infrastructure\WordPress_Schema_Ledger;
use OJAI\Dashboard\Database\Foundation\Installation_State;
use OJAI\Dashboard\Database\Foundation\Migration_Ledger_State;
use OJAI\Dashboard\Database\Foundation\Migration_Plan;
use OJAI\Dashboard\Database\Foundation\Schema_Catalog;
use OJAI\Dashboard\Database\Foundation\Schema_Metadata_Contract;
use OJAI\Dashboard\Database\Foundation\Schema_Postcondition_Verifier;

$addon_root = dirname( __DIR__, 2 );

defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );
require_once $addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core'
	. DIRECTORY_SEPARATOR . 'Autoloader.php';
( new Autoloader( $addon_root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * Database WordPress adapter assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @throws RuntimeException When a contract fails.
 * @return void
 */
function database_wordpress_adapter_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * WordPress database adapter contract for the frozen add-on schema.
 */
final class Database_WordPress_Metadata_Wpdb {
	/**
	 * Table name.
	 *
	 * @var string
	 */
	private string $table_name;

	/**
	 * Metadata.
	 *
	 * @var array<string,mixed>
	 */
	private array $metadata;

	/**
	 * Queries.
	 *
	 * @var array<int,string>
	 */
	public array $queries = array();

	/**
	 * Initialize database WordPress metadata wpdb dependencies and state.
	 *
	 * @param string              $table_name Table name.
	 * @param array<string,mixed> $metadata Metadata.
	 */
	public function __construct( string $table_name, array $metadata ) {
		$this->table_name = $table_name;
		$this->metadata   = $metadata;
	}

	/**
	 * Get col.
	 *
	 * @param string $sql Sql.
	 * @return array<int,string>
	 */
	public function get_col( string $sql ): array {
		$this->queries[] = $sql;

		return array( $this->table_name, 'wp_' . $this->table_name, 'oj_ai_invalid-name' );
	}

	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$args Args.
	 * @return string
	 */
	public function prepare( string $sql, mixed ...$args ): string {
		$this->queries[] = $sql;

		return $sql;
	}

	/**
	 * Get results.
	 *
	 * @param string $sql Sql.
	 * @param mixed  $mode Mode.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_results( string $sql, mixed $mode ): array {
		$this->queries[] = $sql;
		if ( str_contains( $sql, 'information_schema.TABLES' ) ) {
			return array(
				array(
					'table_name'      => $this->table_name,
					'engine'          => $this->metadata['engine'],
					'table_collation' => $this->metadata['collation'],
				),
			);
		}
		if ( str_contains( $sql, 'information_schema.COLUMNS' ) ) {
			$rows = array();
			foreach ( $this->metadata['columns'] as $name => $column ) {
				$type   = strtolower( $column['type'] );
				$type   = preg_replace( '/^(tinyint|smallint|mediumint|int|bigint)( unsigned)?$/', '$1(20)$2', $type );
				$rows[] = array(
					'table_name'     => $this->table_name,
					'column_name'    => $name,
					'column_type'    => $type,
					'is_nullable'    => $column['nullable'] ? 'YES' : 'NO',
					'column_default' => $column['default'],
					'extra'          => $column['auto_increment'] ? 'auto_increment' : '',
				);
			}

			return $rows;
		}
		if ( str_contains( $sql, 'information_schema.STATISTICS' ) ) {
			$rows = array();
			foreach ( $this->metadata['indexes'] as $name => $index ) {
				foreach ( $index['columns'] as $column ) {
					preg_match( '/^([a-z][a-z0-9_]*)(?:\(([0-9]+)\))?$/', $column, $matches );
					$rows[] = array(
						'table_name'  => $this->table_name,
						'index_name'  => $name,
						'non_unique'  => $index['unique'] ? 0 : 1,
						'column_name' => $matches[1],
						'sub_part'    => isset( $matches[2] ) ? (int) $matches[2] : null,
					);
				}
			}

			return $rows;
		}
		if ( str_contains( $sql, 'information_schema.KEY_COLUMN_USAGE' ) ) {
			$rows = array();
			foreach ( $this->metadata['foreign_keys'] as $name => $foreign_key ) {
				$rows[] = array(
					'table_name'             => $this->table_name,
					'constraint_name'        => $name,
					'column_name'            => $foreign_key['column'],
					'referenced_table_name'  => $foreign_key['table'],
					'referenced_column_name' => $foreign_key['reference'],
					'update_rule'            => $foreign_key['on_update'],
					'delete_rule'            => $foreign_key['on_delete'],
				);
			}

			return $rows;
		}

		return array();
	}
}

/**
 * WordPress database adapter contract for the frozen add-on schema.
 */
final class Database_WordPress_Ledger_Wpdb {
	/**
	 * Installation.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $installation = null;

	/**
	 * Migrations.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $migrations = array();

	/**
	 * Written tables.
	 *
	 * @var array<int,string>
	 */
	public array $written_tables = array();

	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$args Args.
	 * @return string
	 */
	public function prepare( string $sql, mixed ...$args ): string {
		return $sql;
	}

	/**
	 * Get row.
	 *
	 * @param string $sql Sql.
	 * @param mixed  $mode Mode.
	 * @return array<string,mixed>|null
	 */
	public function get_row( string $sql, mixed $mode ): ?array {
		return $this->installation;
	}

	/**
	 * Get var.
	 *
	 * @param string $sql Sql.
	 * @return int
	 */
	public function get_var( string $sql ): int {
		return null === $this->installation ? 0 : 1;
	}

	/**
	 * Get results.
	 *
	 * @param string $sql Sql.
	 * @param mixed  $mode Mode.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_results( string $sql, mixed $mode ): array {
		return array_values( $this->migrations );
	}

	/**
	 * Insert.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data Data.
	 * @param array<int,string>   $formats Formats.
	 * @return int|false
	 */
	public function insert( string $table, array $data, array $formats ): int|false {
		$this->written_tables[] = $table;
		if ( 'oj_ai_installations' === $table ) {
			$this->installation = $data;
			return 1;
		}
		if ( 'oj_ai_schema_migrations' === $table ) {
			$this->migrations[ (string) $data['migration_id'] ] = $data;
			return 1;
		}

		return false;
	}

	/**
	 * Update database WordPress ledger wpdb.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data Data.
	 * @param array<string,mixed> $where Where.
	 * @param array<int,string>   $formats Formats.
	 * @param array<int,string>   $where_formats Where formats.
	 * @return int|false
	 */
	public function update(
		string $table,
		array $data,
		array $where,
		array $formats,
		array $where_formats
	): int|false {
		$this->written_tables[] = $table;
		if ( 'oj_ai_installations' === $table && null !== $this->installation ) {
			$this->installation = array_merge( $this->installation, $data );
			return 1;
		}
		if ( 'oj_ai_schema_migrations' === $table ) {
			$id = (string) ( $where['migration_id'] ?? '' );
			if ( isset( $this->migrations[ $id ] ) ) {
				$this->migrations[ $id ] = array_merge( $this->migrations[ $id ], $data );
				return 1;
			}
		}

		return false;
	}
}

$catalog     = new Schema_Catalog();
$table_name  = 'oj_ai_zip_upload_items';
$definition  = $catalog->tables()[ $table_name ];
$expected    = Schema_Metadata_Contract::expected( $definition );
$metadata_db = new Database_WordPress_Metadata_Wpdb( $table_name, $expected );
$inspector   = new WordPress_Schema_Inspector( $metadata_db );
$names       = $inspector->literal_table_names();
database_wordpress_adapter_assert( $names === array( $table_name ), 'Inspector adopted a prefixed or invalid candidate.' );
$verification = ( new Schema_Postcondition_Verifier( $catalog, $inspector ) )->verify_tables( array( $table_name ) );
database_wordpress_adapter_assert( $verification->passed(), 'WordPress information_schema normalization changed the frozen table.' );
database_wordpress_adapter_assert(
	str_contains( $metadata_db->queries[0], "LEFT(TABLE_NAME, 6) = 'oj_ai_'" ),
	'Inspector must inventory only the literal add-on namespace.'
);

$mariadb_table    = 'oj_ai_installations';
$mariadb_metadata = Schema_Metadata_Contract::expected( $catalog->table( $mariadb_table ) );
$mariadb_metadata['columns']['last_verified_at_utc']['default'] = 'NULL';
$mariadb_verification = ( new Schema_Postcondition_Verifier(
	$catalog,
	new WordPress_Schema_Inspector(
		new Database_WordPress_Metadata_Wpdb( $mariadb_table, $mariadb_metadata )
	)
) )->verify_tables( array( $mariadb_table ) );
database_wordpress_adapter_assert(
	$mariadb_verification->passed(),
	'MariaDB SQL NULL metadata was treated as a string default.'
);
database_wordpress_adapter_assert(
	'NULL' === Schema_Metadata_Contract::normalize_default( "'NULL'" ),
	'A quoted literal NULL default was normalized as SQL NULL.'
);

$implicit_table                                     = 'oj_ai_worksheet_revisions';
$implicit_expected                                  = Schema_Metadata_Contract::expected( $catalog->table( $implicit_table ) );
$implicit_expected['indexes']['fk_revision_source'] = array(
	'unique'  => false,
	'columns' => array( 'source_revision_id' ),
);
ksort( $implicit_expected['indexes'], SORT_STRING );
$implicit_db           = new Database_WordPress_Metadata_Wpdb( $implicit_table, $implicit_expected );
$implicit_verification = ( new Schema_Postcondition_Verifier(
	$catalog,
	new WordPress_Schema_Inspector( $implicit_db )
) )->verify_tables( array( $implicit_table ) );
database_wordpress_adapter_assert(
	$implicit_verification->passed(),
	'An engine-created FK support index failed the exact schema postcondition.'
);

$implicit_expected['indexes']['ix_unexpected_source_copy'] = array(
	'unique'  => false,
	'columns' => array( 'source_revision_id' ),
);
ksort( $implicit_expected['indexes'], SORT_STRING );
$unexpected_db           = new Database_WordPress_Metadata_Wpdb( $implicit_table, $implicit_expected );
$unexpected_verification = ( new Schema_Postcondition_Verifier(
	$catalog,
	new WordPress_Schema_Inspector( $unexpected_db )
) )->verify_tables( array( $implicit_table ) );
database_wordpress_adapter_assert( ! $unexpected_verification->passed(), 'An unrelated duplicate index was normalized away.' );

$ledger_db = new Database_WordPress_Ledger_Wpdb();
$ledger    = new WordPress_Schema_Ledger( $ledger_db );
database_wordpress_adapter_assert( 0 === $ledger->installation_row_count(), 'Empty installation ledger count changed.' );
$ledger->create_installation(
	$catalog->owner_marker(),
	Schema_Catalog::SCHEMA_VERSION,
	$catalog->fingerprint(),
	Installation_State::INSTALLING,
	7,
	'2026-08-14 12:00:00'
);
database_wordpress_adapter_assert( $ledger->find_installation()['addon_id'] === Schema_Catalog::ADDON_ID, 'Owner marker row was not readable.' );
database_wordpress_adapter_assert( 1 === $ledger->installation_row_count(), 'Installation ledger row count changed.' );
$ledger->update_installation(
	Schema_Catalog::SCHEMA_VERSION,
	$catalog->fingerprint(),
	Installation_State::REGISTRATION_PENDING,
	7,
	'2026-08-14 12:01:00',
	true
);
database_wordpress_adapter_assert(
	'2026-08-14 12:01:00' === ( $ledger_db->installation['last_verified_at_utc'] ),
	'Verified installation timestamp was not persisted.'
);

$migration  = ( new Migration_Plan( $catalog ) )->migrations()[0];
$checkpoint = array(
	'phase'            => 'applying',
	'cursor'           => 0,
	'completed_tables' => array(),
	'next_table'       => 'oj_ai_installations',
	'postcondition'    => 'pending',
	'correlation_id'   => 'adapter-20260814-001',
	'repair'           => false,
);
$ledger->start_migration( $migration, $checkpoint, '2026-08-14 12:00:00' );
$checkpoint['cursor']           = 2;
$checkpoint['completed_tables'] = $migration->table_names();
$checkpoint['next_table']       = null;
$checkpoint['phase']            = 'completed';
$checkpoint['postcondition']    = 'passed';
$ledger->save_checkpoint( $migration->id(), $checkpoint );
$ledger->finish_migration(
	$migration->id(),
	Migration_Ledger_State::APPLIED,
	$checkpoint,
	null,
	'2026-08-14 12:02:00'
);
$migration_rows = $ledger->all_migrations();
database_wordpress_adapter_assert( $migration_rows[ $migration->id() ]['state'] === Migration_Ledger_State::APPLIED, 'Migration ledger did not finish applied.' );
database_wordpress_adapter_assert(
	$ledger_db->migrations[ $migration->id() ]['checkpoint_hash'] === hash( 'sha256', (string) $ledger_db->migrations[ $migration->id() ]['checkpoint_json'] ),
	'Migration checkpoint hash must cover the exact stored JSON.'
);
foreach ( $ledger_db->written_tables as $written_table ) {
	database_wordpress_adapter_assert(
		in_array( $written_table, array( 'oj_ai_installations', 'oj_ai_schema_migrations' ), true ),
		'Ledger adapter wrote outside its two literal tables.'
	);
}

echo 'WordPress database adapters passed: literal inventory, normalized metadata and FK support indexes, owner ledger, checkpoint hash.' . PHP_EOL;
