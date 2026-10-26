<?php
/**
 * G5-IMP-08-S06 purge integration freeze contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/Support/Php_Source_Contract.php';

use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Database\Foundation\Schema_Catalog;
use OJAI\Dashboard\Operations\Purge\Application\Approve_Purge;
use OJAI\Dashboard\Operations\Purge\Application\Approve_Purge_Command;
use OJAI\Dashboard\Operations\Purge\Application\Approve_Purge_Result;
use OJAI\Dashboard\Operations\Purge\Application\Preview_Purge_Command;
use OJAI\Dashboard\Operations\Purge\Application\Purge_Failure;
use OJAI\Dashboard\Operations\Purge\Application\Purge_Preview_Builder;
use OJAI\Dashboard\Operations\Purge\Domain\Purge_Inventory_Snapshot;
use OJAI\Dashboard\Operations\Purge\Domain\Purge_Target_Catalog;
use OJAI\Dashboard\Operations\Purge\Infrastructure\Signed_Purge_Preview_Token;
use OJAI\Dashboard\Operations\Purge\Port\Purge_Approval_Repository_Port_Interface;
use OJAI\Dashboard\Operations\Purge\Port\Purge_Inventory_Port_Interface;
use OJAI\Dashboard\Operations\Purge\Port\Purge_Site_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

$root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
require_once $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Autoloader.php';
( new Autoloader( $root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * G5 imp08 s06 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_imp08_s06_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G5 imp08 s06 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_imp08_s06_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G5 imp08 s06 failure.
 *
 * @param callable():void $callback Callback.
 * @param string          $reason Reason.
 * @param string          $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_imp08_s06_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback();
	} catch ( Purge_Failure $failure ) {
		g5_imp08_s06_same( $reason, $failure->reason(), $message );
		return;
	}
	throw new RuntimeException( $message . ': no failure was raised.' );
}

/**
 * G5-IMP-08-S06 purge integration freeze contract.
 */
final class G5_Imp08_S06_Inventory implements Purge_Inventory_Port_Interface {
	/**
	 * Initialize g5 imp08 s06 inventory dependencies and state.
	 *
	 * @param array<string,int> $owner_rows Owner rows.
	 * @param int               $file_count File count.
	 * @param int               $blob_bytes Blob bytes.
	 * @param int               $hold_count Hold count.
	 */
	public function __construct(
		public array $owner_rows,
		public int $file_count = 1,
		public int $blob_bytes = 128,
		public int $hold_count = 0
	) {
	}

	/**
	 * Inspect.
	 *
	 * @return Purge_Inventory_Snapshot
	 */
	public function inspect(): Purge_Inventory_Snapshot {
		return new Purge_Inventory_Snapshot(
			$this->owner_rows,
			$this->file_count,
			$this->blob_bytes,
			$this->hold_count,
			array(
				'evaluation_runs' => 0,
				'export_jobs'     => 0,
				'import_runs'     => 0,
				'purge_jobs'      => 0,
				'zip_uploads'     => 0,
			)
		);
	}
}

/**
 * G5-IMP-08-S06 purge integration freeze contract.
 */
final class G5_Imp08_S06_Site implements Purge_Site_Port_Interface {
	/**
	 * Site id.
	 *
	 * @return string
	 */
	public function site_id(): string {
		return '7';
	}
}

/**
 * G5-IMP-08-S06 purge integration freeze contract.
 */
final class G5_Imp08_S06_Approval_Repository implements Purge_Approval_Repository_Port_Interface {
	/**
	 * Records.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $records = array();

	/**
	 * Approve.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Approve_Purge_Result
	 */
	public function approve( array $record ): Approve_Purge_Result {
		$this->records[] = $record;
		return new Approve_Purge_Result(
			(string) $record['job_public_id'],
			'approved',
			(string) $record['scope_type'],
			(string) $record['scope_hash'],
			(string) $record['preview_hash'],
			(string) $record['approved_at_utc'],
			(string) $record['approval_expires_at_utc'],
			false
		);
	}
}

$canonical                                  = new Canonical_Json();
$catalog                                    = new Schema_Catalog();
$owners                                     = array_fill_keys( array_keys( $catalog->owner_counts() ), 0 );
$owners[ Schema_Catalog::OWNER_DATABASE ]   = 2;
$owners[ Schema_Catalog::OWNER_OPERATIONS ] = 3;
$owners[ Schema_Catalog::OWNER_WORKSHEET ]  = 1;
$owners[ Schema_Catalog::OWNER_STORAGE ]    = 1;
$inventory                                  = new G5_Imp08_S06_Inventory( $owners );
$builder                                    = new Purge_Preview_Builder( $inventory, $catalog, $canonical );
$site                                       = new G5_Imp08_S06_Site();
$now                                        = '2026-08-26 12:00:00';
$clock                                      = static function () use ( &$now ): DateTimeImmutable {
	return new DateTimeImmutable( $now, new DateTimeZone( 'UTC' ) );
};
$nonce_counter                              = 0;
$nonce                                      = static function () use ( &$nonce_counter ): string {
	++$nonce_counter;
	return str_pad( dechex( $nonce_counter ), 32, '0', STR_PAD_LEFT );
};
$tokens                                     = new Signed_Purge_Preview_Token( $canonical, str_repeat( 'f', 64 ), $clock, $nonce );

$preview        = $builder->build( Preview_Purge_Command::SCOPE_ADDON );
$first_key_hash = hash( 'sha256', 'g5-imp08-s06-token-key' );
$issued         = $tokens->issue( $site->site_id(), $preview['scope_hash'], $preview['preview_hash'], $first_key_hash );
g5_imp08_s06_same( '2026-08-26 12:00:00', $issued['issued_at_utc'], 'C71 token issue time drifted.' );
g5_imp08_s06_same( '2026-08-26 12:30:00', $issued['expires_at_utc'], 'C71 token TTL is not exactly 1,800 seconds.' );
$now = '2026-08-26 12:29:59';
g5_imp08_s06_same( $preview['preview_hash'], $tokens->verify( $issued['preview_token'] )['preview_hash'], 'C71 token expired before its boundary.' );
$now = '2026-08-26 12:30:00';
g5_imp08_s06_failure(
	static fn () => $tokens->verify( $issued['preview_token'] ),
	Purge_Failure::TOKEN_EXPIRED,
	'Expired preview token was accepted at the exact boundary.'
);

$approval_key_hash = hash( 'sha256', 'g5-imp08-s06-approval-key' );
$fresh             = $tokens->issue( $site->site_id(), $preview['scope_hash'], $preview['preview_hash'], $approval_key_hash );
g5_imp08_s06_same( $preview['preview_hash'], $tokens->verify( $fresh['preview_token'] )['preview_hash'], 'Fresh preview token could not enter reapproval.' );
$repository = new G5_Imp08_S06_Approval_Repository();
$entropy    = 0;
$ids        = new Public_Id_Generator(
	static function ( int $length ) use ( &$entropy ): string {
		++$entropy;
		return str_repeat( chr( $entropy ), $length );
	}
);
$approval   = new Approve_Purge( $builder, $tokens, $site, $repository, $ids, $canonical, $clock );
$result     = $approval->execute(
	new Approve_Purge_Command(
		77,
		Preview_Purge_Command::SCOPE_ADDON,
		$fresh['preview_token'],
		hash( 'sha256', 'g5-imp08-s06-backup-evidence' ),
		new DateTimeImmutable( '2026-08-25 12:30:00', new DateTimeZone( 'UTC' ) ),
		Approve_Purge_Command::CONFIRMATION_PHRASE,
		$approval_key_hash,
		'corr-g5-imp08-s06-approve',
		$canonical
	)
);
g5_imp08_s06_same( '2026-08-26 12:30:00', $result->approved_at_utc(), 'C72 approval time drifted.' );
g5_imp08_s06_same( '2026-08-26 13:00:00', $result->approval_expires_at_utc(), 'C72 approval TTL is not exactly 1,800 seconds.' );
g5_imp08_s06_same( '2026-08-25 12:30:00', $repository->records[0]['backup_verified_at_utc'] ?? null, 'C72 rejected or changed backup evidence at the exact 24-hour boundary.' );
g5_imp08_s06_same( 1, count( $repository->records ), 'C72 exact backup boundary did not create one approval ledger.' );
foreach ( array( 'business_mutation_count', 'file_mutation_count', 'option_mutation_count', 'role_mutation_count', 'platform_write_count' ) as $zero_method ) {
	g5_imp08_s06_same( 0, $result->{$zero_method}(), 'C72 changed a protected boundary: ' . $zero_method );
}

$now                   = '2026-08-26 13:10:00';
$inventory->hold_count = 0;
$hold_preview          = $builder->build( Preview_Purge_Command::SCOPE_ADDON );
$hold_key_hash         = hash( 'sha256', 'g5-imp08-s06-hold-key' );
$hold_token            = $tokens->issue( $site->site_id(), $hold_preview['scope_hash'], $hold_preview['preview_hash'], $hold_key_hash );
$inventory->hold_count = 1;
g5_imp08_s06_failure(
	static fn () => $approval->execute(
		new Approve_Purge_Command(
			77,
			Preview_Purge_Command::SCOPE_ADDON,
			$hold_token['preview_token'],
			hash( 'sha256', 'g5-imp08-s06-held-backup' ),
			new DateTimeImmutable( '2026-08-26 13:00:00', new DateTimeZone( 'UTC' ) ),
			Approve_Purge_Command::CONFIRMATION_PHRASE,
			$hold_key_hash,
			'corr-g5-imp08-s06-held',
			$canonical
		)
	),
	Purge_Failure::LEGAL_HOLD_BLOCKED,
	'C72 accepted a legal hold introduced after preview.'
);
g5_imp08_s06_same( 1, count( $repository->records ), 'Legal-hold rejection wrote another approval ledger.' );

$targets      = new Purge_Target_Catalog( $catalog );
$target_names = $targets->table_names();
g5_imp08_s06_same( 43, count( $target_names ), 'C73 target table count drifted.' );
$protected = array_values( array_diff( $catalog->table_names(), $target_names ) );
sort( $protected, SORT_STRING );
$expected_protected = array(
	'oj_ai_audit_events',
	'oj_ai_installations',
	'oj_ai_outbox_events',
	'oj_ai_purge_jobs',
	'oj_ai_schema_migrations',
);
sort( $expected_protected, SORT_STRING );
g5_imp08_s06_same( $expected_protected, $protected, 'C73 protected ledger set drifted.' );

$execute_source    = file_get_contents( $root . '/src/Operations/Purge/Application/Execute_Purge.php' );
$query_source      = file_get_contents( $root . '/src/Operations/Purge/Application/Read_Purge_Job.php' );
$repository_source = file_get_contents( $root . '/src/Operations/Purge/Infrastructure/WordPress_Purge_Operation_Repository.php' );
$uninstall_source  = file_get_contents( $root . '/uninstall.php' );
foreach ( array( $execute_source, $query_source, $repository_source, $uninstall_source ) as $source ) {
	g5_imp08_s06_assert( is_string( $source ), 'A purge integration source could not be read.' );
}
g5_imp08_s06_assert( str_contains( $execute_source, '$now_time >= $expires_time' ), 'C73 exact approval expiry boundary is not fail-closed.' );
g5_imp08_s06_assert( str_contains( $query_source, '$this->now() >= $expires' ), 'Q36 exact approval expiry boundary differs from C73.' );
$execution_markers = array(
	'$this->assert_approval( $job, $started_at_utc )',
	'$this->assert_deletion_ready( $stored_preview )',
	'$current_preview = $this->normalize_approval_delta(',
	'$this->assert_deletion_ready( $current_preview )',
	'$result = $this->repository->start(',
);
$last_position     = -1;
foreach ( $execution_markers as $marker ) {
	$position = Ojai_Php_Source_Contract::position( $execute_source, $marker );
	g5_imp08_s06_assert( false !== $position && $position > $last_position, 'C73 revalidation order drifted: ' . $marker );
	$last_position = $position;
}
foreach ( array(
	"0 === \$postcondition['remaining_rows']",
	"0 === \$postcondition['remaining_files']",
	"0 === \$postcondition['remaining_blob_bytes']",
	"\$terminal_state = \$verified ? 'completed' : 'failed'",
) as $marker ) {
	g5_imp08_s06_assert( Ojai_Php_Source_Contract::contains( $repository_source, $marker ), 'C73 final zero verification drifted: ' . $marker );
}
g5_imp08_s06_assert(
	strpos( $repository_source, '$this->purge_private_files(' ) < strpos( $repository_source, '$this->purge_tables(' ),
	'C73 no longer purges private bytes before table metadata.'
);
foreach ( array( 'DROP TABLE', 'DELETE FROM', 'TRUNCATE TABLE', 'delete_option(', 'remove_role(', 'wp_delete_file(', 'unlink(' ) as $forbidden ) {
	g5_imp08_s06_assert( ! str_contains( $uninstall_source, $forbidden ), 'Uninstall crossed the preservation boundary: ' . $forbidden );
}

$purge_files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src/Operations/Purge', FilesystemIterator::SKIP_DOTS ) );
foreach ( $purge_files as $file ) {
	if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}
	$source = file_get_contents( $file->getPathname() );
	g5_imp08_s06_assert( is_string( $source ), 'Purge runtime source could not be read: ' . $file->getPathname() );
	foreach ( array( '$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'CodingPanda\\OjPlatform\\Kernel\\' ) as $forbidden ) {
		g5_imp08_s06_assert( ! str_contains( $source, $forbidden ), 'Purge runtime crossed a frozen boundary: ' . $file->getFilename() . ' -> ' . $forbidden );
	}
}

$constituent_markers = array(
	'tests/Contract/uninstall-data-preservation-g5-imp-08-s01.php' => 'uninstall preserves all 48 literal tables',
	'tests/Contract/purge-preview-g5-imp-08-s02.php'   => 'C71 exact 48-table owner inventory',
	'tests/Contract/purge-approval-g5-imp-08-s03.php'  => 'strict backup and phrase validation',
	'tests/Contract/purge-execution-cancel-g5-imp-08-s04.php' => 'C73 accepted approval at the exact expiry boundary.',
	'tests/Contract/purge-job-query-g5-imp-08-s05.php' => 'Q36 did not expire approval at the exact 1,800-second boundary.',
);
foreach ( $constituent_markers as $relative => $marker ) {
	$source = file_get_contents( $root . '/' . $relative );
	g5_imp08_s06_assert( is_string( $source ) && str_contains( $source, $marker ), 'S01-S05 integration marker drifted: ' . $relative );
}

fwrite( STDOUT, "G5-IMP-08-S06 passed: S01-S05 preservation, preview, approval, execution, cancellation, and Q36 status are integrated with exact token and approval expiry, exact 24-hour backup acceptance, legal-hold blocking, protected ledgers, private-byte ordering, and final zero verification.\n" );
