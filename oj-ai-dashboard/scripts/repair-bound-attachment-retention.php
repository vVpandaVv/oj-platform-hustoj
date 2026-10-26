<?php
/** Explicit CLI maintenance; no web endpoint and no automatic execution. */
declare(strict_types=1);

use OJAI\Dashboard\Storage\Infrastructure\Local_Private_File_Store;
use OJAI\Dashboard\Submission\Student\Infrastructure\WordPress_Bound_Attachment_Retention_Repair;
use OJAI\Dashboard\Submission\Teacher\Infrastructure\WordPress_Teacher_Attachment_Query;
use OJAI\Dashboard\Submission\Teacher\Infrastructure\WordPress_Teacher_Submission_Query;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;

if ( 'cli' !== PHP_SAPI ) { http_response_code( 404 ); exit; }

try {
	$options = getopt( '', array( 'wordpress-root:', 'web-root:', 'context:', 'attachment:', 'actor:', 'work-dir:', 'apply', 'plan-sha256:' ) );
	foreach ( array( 'wordpress-root', 'web-root', 'context', 'attachment', 'actor', 'work-dir' ) as $key ) {
		if ( ! is_string( $options[ $key ] ?? null ) || '' === $options[ $key ] ) { throw new RuntimeException( 'Required option: --' . $key ); }
	}
	if ( ! preg_match( '/^(class|group):[1-9][0-9]*$/D', $options['context'] )
		|| ! ctype_digit( $options['actor'] ) || (int) $options['actor'] < 1 ) {
		throw new RuntimeException( 'Invalid context or actor.' );
	}
	$root = realpath( $options['wordpress-root'] );
	$web_root = realpath( $options['web-root'] );
	$directory = realpath( $options['work-dir'] );
	if ( false === $root || ! is_file( $root . '/wp-load.php' ) || false === $web_root || ! is_dir( $web_root )
		|| false === $directory || ! is_dir( $directory ) ) {
		throw new RuntimeException( 'Existing WordPress root, verified web root and private work directory required.' );
	}
	$inside = static function ( string $path, string $base ): bool {
		$path = str_replace( '\\', '/', $path );
		$base = rtrim( str_replace( '\\', '/', $base ), '/' );
		if ( 'Windows' === PHP_OS_FAMILY ) { $path = strtolower( $path ); $base = strtolower( $base ); }
		return $path === $base || str_starts_with( $path, $base . '/' );
	};
	if ( $inside( $directory, $root ) || $inside( $directory, $web_root ) || ! is_writable( $directory ) ) {
		throw new RuntimeException( 'Work directory must be writable and outside the web root.' );
	}
	if ( 'Windows' !== PHP_OS_FAMILY && 0 !== ( fileperms( $directory ) & 0077 ) ) {
		throw new RuntimeException( 'Work directory must have private permissions (0700).' );
	}
	umask( 0077 );
	define( 'DISABLE_WP_CRON', true );
	require $root . '/wp-load.php';
	global $wpdb;
	if ( ! $wpdb instanceof wpdb ) { throw new RuntimeException( 'WordPress database is unavailable.' ); }
	if ( defined( 'OJ_AI_WEB_ROOT' ) && realpath( (string) constant( 'OJ_AI_WEB_ROOT' ) ) !== $web_root ) {
		throw new RuntimeException( 'The supplied web root differs from the configured boundary.' );
	}
	if ( ! defined( 'OJ_AI_WEB_ROOT' ) ) { define( 'OJ_AI_WEB_ROOT', $web_root ); }
	if ( ! user_can( (int) $options['actor'], 'manage_options' ) ) { throw new RuntimeException( 'An administrator maintenance actor is required.' ); }
	if ( ! class_exists( WordPress_Bound_Attachment_Retention_Repair::class ) ) {
		throw new RuntimeException( 'Install the validated retention fix before running maintenance.' );
	}
	$source = ( new ReflectionClass( WordPress_Bound_Attachment_Retention_Repair::class ) )->getFileName();
	$implementation_hash = hash_file( 'sha256', $source );
	$store = new Local_Private_File_Store();
	$repair = new WordPress_Bound_Attachment_Retention_Repair( $wpdb, new WordPress_Teacher_Submission_Query( $wpdb, new Canonical_Json() ), $store );
	$assert_context = static function () use ( $wpdb, $options ): void {
		$context = $wpdb->get_var( $wpdb->prepare( 'SELECT a.context_id FROM oj_ai_submission_attachments f '
			. 'INNER JOIN oj_ai_submissions s ON s.id = f.submission_id INNER JOIN oj_ai_assignments a ON a.id = s.assignment_id '
			. 'WHERE f.public_id = %s', $options['attachment'] ) );
		if ( '' !== (string) $wpdb->last_error || $options['context'] !== $context ) { throw new RuntimeException( 'Attachment is not in the explicitly selected context.' ); }
	};
	$assert_context();
	$now = gmdate( 'Y-m-d H:i:s' );
	$preview = $repair->repair( $options['attachment'], (int) $options['actor'], $now );
	$plan_path = $directory . '/plan.json';
	$backup_path = $directory . '/attachment.before.bin';
	if ( ! array_key_exists( 'apply', $options ) ) {
		if ( is_file( $plan_path ) || is_file( $backup_path ) ) { throw new RuntimeException( 'Use a new private work directory; existing backups are never overwritten.' ); }
		$row = ( new WordPress_Teacher_Attachment_Query( $wpdb ) )->find( $preview['submission_id'], $options['attachment'] );
		if ( null === $row || $preview['before']['id'] !== $row['public_id'] || $preview['before']['lock_version'] !== (int) $row['lock_version'] ) {
			throw new RuntimeException( 'Metadata changed while preparing the backup.' );
		}
		$path = $store->readable_path( $row['storage_key'], $row['sha256'] );
		$input = fopen( $path, 'rb' );
		$output = fopen( $backup_path, 'xb' );
		if ( false === $input || false === $output ) { throw new RuntimeException( 'Cannot open the verified source or exclusive backup.' ); }
		$copied = stream_copy_to_stream( $input, $output );
		fclose( $input );
		fclose( $output );
		if ( $copied !== $preview['before']['byte_size'] || hash_file( 'sha256', $backup_path ) !== $preview['before']['sha256'] ) {
			throw new RuntimeException( 'Backup byte count or checksum does not match.' );
		}
		$plan = array( 'schema' => 'ojai.bound-retention-repair.1', 'wordpress_root' => $root, 'web_root' => $web_root,
			'context' => $options['context'], 'actor' => (int) $options['actor'], 'created_at_utc' => $now,
			'implementation_sha256' => $implementation_hash, 'preview' => $preview, 'file_row_before' => $row );
		$json = json_encode( $plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
		$output = fopen( $plan_path, 'xb' );
		if ( false === $output || strlen( $json ) !== fwrite( $output, $json ) ) { throw new RuntimeException( 'Could not persist the complete repair plan.' ); }
		fclose( $output );
		echo json_encode( array( 'mode' => 'preview', 'status' => $preview['status'], 'attachment_id' => $options['attachment'],
			'plan_sha256' => hash( 'sha256', $json ), 'database_modified' => false ), JSON_THROW_ON_ERROR ) . "\n";
		exit;
	}
	$expected_hash = $options['plan-sha256'] ?? '';
	if ( ! is_string( $expected_hash ) || ! preg_match( '/^[a-f0-9]{64}$/D', $expected_hash ) || ! is_file( $plan_path ) || is_link( $plan_path ) ) {
		throw new RuntimeException( '--apply requires the reviewed --plan-sha256 and existing plan.' );
	}
	$json = file_get_contents( $plan_path );
	if ( false === $json || ! hash_equals( $expected_hash, hash( 'sha256', $json ) ) ) { throw new RuntimeException( 'Plan checksum mismatch.' ); }
	$plan = json_decode( $json, true, 64, JSON_THROW_ON_ERROR );
	if ( 'ojai.bound-retention-repair.1' !== ( $plan['schema'] ?? null ) || $root !== ( $plan['wordpress_root'] ?? null )
		|| $web_root !== ( $plan['web_root'] ?? null )
		|| $options['context'] !== ( $plan['context'] ?? null ) || (int) $options['actor'] !== ( $plan['actor'] ?? null )
		|| $implementation_hash !== ( $plan['implementation_sha256'] ?? null )
		|| $options['attachment'] !== ( $plan['preview']['attachment_id'] ?? null )
		|| ! is_file( $backup_path ) || is_link( $backup_path )
		|| filesize( $backup_path ) !== ( $plan['preview']['before']['byte_size'] ?? null )
		|| hash_file( 'sha256', $backup_path ) !== ( $plan['preview']['before']['sha256'] ?? null ) ) {
		throw new RuntimeException( 'Plan scope, implementation or verified backup does not match.' );
	}
	$receipt_path = $directory . '/apply-' . bin2hex( random_bytes( 12 ) ) . '.jsonl';
	$journal = fopen( $receipt_path, 'xb' );
	if ( false === $journal ) { throw new RuntimeException( 'Cannot open private maintenance journal.' ); }
	$started = json_encode( array( 'started_at_utc' => $now, 'plan_sha256' => $expected_hash ), JSON_THROW_ON_ERROR ) . "\n";
	if ( strlen( $started ) !== fwrite( $journal, $started ) || ! fflush( $journal ) || ! fsync( $journal ) ) {
		throw new RuntimeException( 'Cannot persist maintenance start; database unchanged.' );
	}
	$assert_context();
	if ( 'unchanged' === $preview['status'] ) {
		if ( $preview['before']['id'] !== $plan['preview']['before']['id']
			|| $preview['before']['sha256'] !== $plan['preview']['before']['sha256']
			|| $preview['official_revision_id'] !== $plan['preview']['official_revision_id'] ) {
			throw new RuntimeException( 'Repaired metadata differs from the original plan.' );
		}
		$result = $preview;
	} else {
		$result = $repair->repair( $options['attachment'], (int) $options['actor'], gmdate( 'Y-m-d H:i:s' ), true, $plan['preview'] );
	}
	$finished = json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
	if ( strlen( $finished ) !== fwrite( $journal, $finished ) || ! fflush( $journal ) || ! fsync( $journal ) ) {
		throw new RuntimeException( 'Repair may be committed; completion journal failed. Reinspect before retrying.' );
	}
	fclose( $journal );
	echo $finished;
} catch ( Throwable $error ) {
	// Stable domain reasons only; SQL, storage paths and student payloads stay private.
	$reason = method_exists( $error, 'reason' ) ? $error->reason() : ( $error instanceof RuntimeException ? $error->getMessage() : 'Unexpected maintenance failure.' );
	fwrite( STDERR, (string) $reason . "\n" );
	exit( 1 );
}
