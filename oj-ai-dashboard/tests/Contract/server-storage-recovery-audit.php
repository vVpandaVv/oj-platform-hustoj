<?php
/**
 * Server storage recovery audit.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require dirname( __DIR__, 2 ) . '/scripts/verify-server-storage-recovery.php';

$checks = 0;
$expect = static function ( bool $ok, string $name ) use ( &$checks ): void {
	if ( ! $ok ) {
		throw new RuntimeException( 'FAIL: ' . $name );
	}
	++$checks;
};
$reject = static function ( callable $call, string $code ) use ( $expect ): void {
	try {
		$call();
	} catch ( RuntimeException $error ) {
		$expect( $error->getMessage() === $code, $code );
		return;
	}
	throw new RuntimeException( 'Expected rejection: ' . $code );
};
$expect( '/private/site' === ( audit_config_path( "<?php define('OJ_AI_PRIVATE_STORAGE_DIR', '/private/site');" ) ), 'literal config' );
$expect( null === ( audit_config_path( "<?php // define('OJ_AI_PRIVATE_STORAGE_DIR', '/ignored');" ) ), 'comment ignored' );
$expect( null === ( audit_config_path( '<?php define("DB_PASSWORD", "not-read");' ) ), 'unrelated secret ignored' );
$reject( static fn() => audit_config_path( "<?php define('OJ_AI_PRIVATE_STORAGE_DIR', dirname(__DIR__) . '/private');" ), 'config_dynamic_path_requires_review' );
$reject( static fn() => audit_config_path( "<?php define('OJ_AI_PRIVATE_STORAGE_DIR', '/a'); define('OJ_AI_PRIVATE_STORAGE_DIR', '/b');" ), 'config_multiple_storage_paths' );

$work = sys_get_temp_dir() . '/ojai-storage-audit-' . bin2hex( random_bytes( 8 ) );
mkdir( $work, 0700 );
mkdir( $work . '/web', 0700 );
mkdir( $work . '/private', 0700 );
try {
	$root = audit_private_root( $work . '/private', $work . '/web' );
	$expect( is_dir( $root ), 'private sibling root' );
	$reject( static fn() => audit_private_root( $work . '/web', $work . '/web' ), 'storage_root_inside_wordpress' );
	$reject( static fn() => audit_private_root( $work, $work . '/web' ), 'storage_root_contains_wordpress' );
	file_put_contents( $root . '/test.bin', 'private-fixture' );
	$expect( audit_file_path( $root, 'test.bin' ) === str_replace( '\\', '/', (string) realpath( $root . '/test.bin' ) ), 'safe file' );
	$reject( static fn() => audit_file_path( $root, '../test.bin' ), 'storage_key_invalid' );
	foreach ( array( 'a/../test.bin', 'a//test.bin' ) as $key ) {
		$reject( static fn() => audit_file_path( $root, $key ), 'storage_key_traversal' );
	}
	$reject( static fn() => audit_file_path( $root, '/test.bin' ), 'storage_key_invalid' );
	$reject( static fn() => audit_file_path( $root, 'absent.bin' ), 'storage_file_missing' );
	$hash = hash( 'sha256', 'private-fixture' );
	audit_copy( $root . '/test.bin', $work . '/backup', 15, $hash );
	audit_copy( $work . '/backup', $work . '/restored', 15, $hash );
	$expect( 'private-fixture' === ( file_get_contents( $work . '/restored' ) ), 'two-step restore' );
	$reject( static fn() => audit_copy( $root . '/test.bin', $work . '/bad-size', 3, $hash ), 'file_size_mismatch' );
	$reject( static fn() => audit_copy( $root . '/test.bin', $work . '/bad-hash', 15, str_repeat( '0', 64 ) ), 'file_hash_mismatch' );
	file_put_contents( $work . '/pdf', "%PDF-1.7\nfixture\n%%EOF\n" );
	$expect( 'pdf_header_and_eof_only' === ( audit_artifact( $work . '/pdf', 'application/pdf' ) ), 'pdf limited check labeled' );
	file_put_contents( $work . '/truncated', "%PDF-1.7\nfixture" );
	$reject( static fn() => audit_artifact( $work . '/truncated', 'application/pdf' ), 'pdf_truncated' );
} finally {
	foreach ( (array) glob( $work . '/private/*' ) as $file ) {
		unlink( $file );
	}
	foreach ( (array) glob( $work . '/*' ) as $file ) {
		if ( is_file( $file ) ) {
			unlink( $file );
		}
	}
	rmdir( $work . '/private' );
	rmdir( $work . '/web' );
	rmdir( $work );
}
echo 'PASS: server storage audit ', (int) $checks, ' checks.', PHP_EOL;
