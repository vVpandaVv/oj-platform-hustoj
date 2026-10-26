<?php
/**
 * G5-IMP-07-S06 source drift, token expiry, execution, and compensation freeze.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/Support/Php_Source_Contract.php';

use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Migration_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\Signed_Legacy_Preview_Token;
use OJAI\Dashboard\Storage\Infrastructure\Local_Private_File_Store;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;

$root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
require_once $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Autoloader.php';
( new Autoloader( $root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * G5 s06 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s06_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G5 s06 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s06_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G5 s06 failure.
 *
 * @param callable():void $callback Callback.
 * @param string          $reason Reason.
 * @param string          $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s06_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback();
	} catch ( Legacy_Migration_Failure $failure ) {
		g5_s06_same( $reason, $failure->reason(), $message );
		return;
	}
	throw new RuntimeException( $message . ': no failure was raised.' );
}

/**
 * G5 s06 source.
 *
 * @param string $root Root.
 * @param string $relative Relative.
 * @return string
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s06_source( string $root, string $relative ): string {
	$source = file_get_contents( $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative ) );
	if ( ! is_string( $source ) ) {
		throw new RuntimeException( 'S06 source could not be read: ' . $relative );
	}
	return $source;
}

/**
 * G5 s06 order.
 *
 * @param string $source Source.
 * @param array  $markers Markers.
 * @phpstan-param list<string> $markers
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s06_order( string $source, array $markers, string $message ): void {
	$offset = -1;
	foreach ( $markers as $marker ) {
		$position = strpos( $source, $marker );
		if ( false === $position || $position <= $offset ) {
			throw new RuntimeException( $message . ': ' . $marker );
		}
		$offset = $position;
	}
}

$canonical    = new Canonical_Json();
$now          = new DateTimeImmutable( '2026-08-26 10:00:00', new DateTimeZone( 'UTC' ) );
$nonce        = 0;
$tokens       = new Signed_Legacy_Preview_Token(
	$canonical,
	str_repeat( 's', 64 ),
	static function () use ( &$now ): DateTimeImmutable {
		return $now;
	},
	static function () use ( &$nonce ): string {
		++$nonce;
		return str_pad( dechex( $nonce ), 32, '0', STR_PAD_LEFT );
	}
);
$issued       = $tokens->issue( '7', hash( 'sha256', 'source-v1' ), hash( 'sha256', 'plan-v1' ), hash( 'sha256', 'key-v1' ) );
$issued_time  = new DateTimeImmutable( $issued['issued_at_utc'], new DateTimeZone( 'UTC' ) );
$expires_time = new DateTimeImmutable( $issued['expires_at_utc'], new DateTimeZone( 'UTC' ) );
g5_s06_same( 1800, $expires_time->getTimestamp() - $issued_time->getTimestamp(), 'Preview token TTL drifted from 30 minutes.' );

$now = new DateTimeImmutable( '2026-08-26 10:29:59', new DateTimeZone( 'UTC' ) );
g5_s06_same( '7', $tokens->verify( $issued['preview_token'] )['site'], 'Preview token failed before its expiry boundary.' );
$now = new DateTimeImmutable( '2026-08-26 10:30:00', new DateTimeZone( 'UTC' ) );
g5_s06_failure(
	static fn () => $tokens->verify( $issued['preview_token'] ),
	Legacy_Migration_Failure::TOKEN_EXPIRED,
	'Expired preview token was accepted at the exact boundary.'
);

$replacement = $tokens->issue( '7', hash( 'sha256', 'source-v1' ), hash( 'sha256', 'plan-v1' ), hash( 'sha256', 'key-v2' ) );
g5_s06_assert( ! hash_equals( $issued['preview_token'], $replacement['preview_token'] ), 'Re-preview reused the expired token.' );
$now = new DateTimeImmutable( '2026-08-26 10:30:01', new DateTimeZone( 'UTC' ) );
g5_s06_same( hash( 'sha256', 'key-v2' ), $tokens->verify( $replacement['preview_token'] )['idempotency_key_hash'], 'Fresh preview token could not enter reapproval.' );

$_SERVER['DOCUMENT_ROOT'] = ABSPATH;
$private_root             = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'oj-ai-g5-imp-07-s06-' . bin2hex( random_bytes( 8 ) );
$private_directory        = $private_root . DIRECTORY_SEPARATOR . 'legacy';
$private_path             = $private_directory . DIRECTORY_SEPARATOR . 'retry.bin';
try {
	if ( ! mkdir( $private_directory, 0700, true ) && ! is_dir( $private_directory ) ) {
		throw new RuntimeException( 'S06 private fixture directory is unavailable.' );
	}
	if ( false === file_put_contents( $private_path, 'retry-safe-private-bytes' ) ) {
		throw new RuntimeException( 'S06 private fixture is unavailable.' );
	}
	$private_files = new Local_Private_File_Store( $private_root );
	$private_files->delete( 'legacy/retry.bin' );
	g5_s06_assert( ! file_exists( $private_path ), 'First private purge did not remove bytes.' );
	$private_files->delete( 'legacy/retry.bin' );
	g5_s06_assert( ! file_exists( $private_path ), 'Private purge retry was not idempotent after byte deletion.' );
} finally {
	if ( is_file( $private_path ) ) {
		@unlink( $private_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort removal of an owned temporary path must not mask the primary outcome.
	}
	if ( is_dir( $private_directory ) ) {
		@rmdir( $private_directory ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort removal of an owned temporary path must not mask the primary outcome.
	}
	if ( is_dir( $private_root ) ) {
		@rmdir( $private_root ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort removal of an owned temporary path must not mask the primary outcome.
	}
}

$token_source         = g5_s06_source( $root, 'src/Operations/LegacyMigration/Infrastructure/Signed_Legacy_Preview_Token.php' );
$approve_source       = g5_s06_source( $root, 'src/Operations/LegacyMigration/Application/Approve_Legacy_Migration.php' );
$execute_source       = g5_s06_source( $root, 'src/Operations/LegacyMigration/Application/Execute_Legacy_Migration.php' );
$batch_source         = g5_s06_source( $root, 'src/Operations/LegacyMigration/Application/Process_Legacy_Migration_Batch.php' );
$execution_repository = g5_s06_source( $root, 'src/Operations/LegacyMigration/Infrastructure/WordPress_Legacy_Migration_Execution_Repository.php' );
$rollback_repository  = g5_s06_source( $root, 'src/Operations/LegacyMigration/Infrastructure/WordPress_Legacy_Migration_Rollback_Repository.php' );

foreach ( array( 'private const TTL_SECONDS = 1800;', 'if ( $now >= $expires )', 'Legacy_Migration_Failure::TOKEN_EXPIRED' ) as $marker ) {
	g5_s06_assert( Ojai_Php_Source_Contract::contains( $token_source, $marker ), 'Token expiry contract marker is missing: ' . $marker );
}
g5_s06_order(
	$approve_source,
	array( '$this->tokens->verify', '$this->sources->inspect', '$this->repository->approve' ),
	'Approval no longer verifies token and current source before persistence'
);
g5_s06_order(
	$execute_source,
	array( '$this->repository->approved_run', '$this->sources->inspect', '$this->repository->start(' ),
	'C62 no longer revalidates an approved source before starting'
);
g5_s06_order(
	$batch_source,
	array( '$this->repository->running_run', '$this->sources->inspect', '$this->reader->read', '$this->repository->commit_batch' ),
	'C62 worker no longer revalidates source before target mutation'
);
foreach ( array( $approve_source, $execute_source, $batch_source ) as $source ) {
	g5_s06_assert( str_contains( $source, 'Legacy_Migration_Failure::SOURCE_STALE' ), 'A source-drift boundary lost SOURCE_STALE.' );
}

$lock_marker = "private const LOCK_NAME_PREFIX = 'oj_ai_dashboard_legacy_migration_';";
g5_s06_assert( Ojai_Php_Source_Contract::contains( $execution_repository, $lock_marker ), 'C62 site lock prefix is missing.' );
g5_s06_assert( Ojai_Php_Source_Contract::contains( $rollback_repository, $lock_marker ), 'C63 site lock prefix no longer matches C62.' );
foreach ( array( "array( 'completed', 'completed_with_errors' )", 'purge_private_files', 'TARGET_CHANGED', "'phase'] = 'compensated'" ) as $marker ) {
	g5_s06_assert( Ojai_Php_Source_Contract::marker( $rollback_repository, $marker ), 'C63 integration marker is missing: ' . $marker );
}
foreach ( array( 'DELETE FROM oj_ai_import_runs', 'DELETE FROM oj_ai_import_items', 'DELETE FROM oj_ai_audit_events', 'DELETE FROM oj_ai_outbox_events', 'DELETE FROM oj_ai_private_files' ) as $forbidden ) {
	g5_s06_assert( ! str_contains( $rollback_repository, $forbidden ), 'C63 deletes preserved evidence: ' . $forbidden );
}

$approval_contract = g5_s06_source( $root, 'tests/Contract/legacy-migration-approval-g5-imp-07-s02.php' );
$recovery_contract = g5_s06_source( $root, 'tests/Contract/legacy-migration-recovery-g5-imp-07-s03-f.php' );
$rollback_contract = g5_s06_source( $root, 'tests/Contract/legacy-migration-rollback-g5-imp-07-s05.php' );
foreach ( array( 'C70 accepted a changed source fingerprint.', 'Source drift mutated the ledger.', 'C70 accepted a token at its expiry boundary.', 'Expired approval mutated the ledger.' ) as $marker ) {
	g5_s06_assert( str_contains( $approval_contract, $marker ), 'S02 regression evidence is missing: ' . $marker );
}
foreach ( array( 'Worker source drift did not fail before the target transaction.', 'Worker source drift changed target, item, or ledger rows.', 'Retry from the durable checkpoint did not complete.', 'Completed-run replay created duplicate targets or receipts.' ) as $marker ) {
	g5_s06_assert( str_contains( $recovery_contract, $marker ), 'S03 regression evidence is missing: ' . $marker );
}
foreach ( array( 'C63 private tombstone CAS did not fail closed.', 'C63 removed the import item ledger.', 'C63 did not acquire the site migration lock.', 'C63 real replay duplicated audit events.' ) as $marker ) {
	g5_s06_assert( str_contains( $rollback_contract, $marker ), 'S05 regression evidence is missing: ' . $marker );
}

foreach ( array( $token_source, $approve_source, $execute_source, $batch_source, $execution_repository, $rollback_repository ) as $source ) {
	foreach ( array( '$wpdb->prefix', '$wpdb->base_prefix', 'CodingPanda\\OjPlatform\\Kernel\\' ) as $forbidden ) {
		g5_s06_assert( ! str_contains( $source, $forbidden ), 'S06 crossed a frozen target or platform boundary: ' . $forbidden );
	}
}

fwrite( STDOUT, "G5-IMP-07-S06 passed: 30-minute token expiry and re-preview, source revalidation, C62/C63 site serialization, retry-safe private purge, durable replay, and preserved ledgers are frozen.\n" );
