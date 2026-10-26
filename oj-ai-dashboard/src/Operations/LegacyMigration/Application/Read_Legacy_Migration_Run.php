<?php
/**
 * Read legacy migration run.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Application;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Migration_Plan;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Run_Query_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates and projects the frozen A-DS08-Q29 migration-run query.
 */
final class Read_Legacy_Migration_Run {
	public const ACTION_READ = 'operations.legacy_migration.read';

	/**
	 * Initialize legacy migration run dependencies and state.
	 *
	 * @param Legacy_Migration_Run_Query_Port_Interface $runs Runs.
	 * @param Canonical_Json                            $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct(
		private Legacy_Migration_Run_Query_Port_Interface $runs,
		private Canonical_Json $canonical
	) {
	}

	/**
	 * Read legacy migration run.
	 *
	 * @param string $run_id Run id.
	 * @return Read_Legacy_Migration_Run_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function read( string $run_id ): Read_Legacy_Migration_Run_Result {
		try {
			if ( ! Public_Id::is_valid( $run_id ) ) {
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::RUN_NOT_FOUND );
			}
			$record = $this->runs->find_record( $run_id );
			if ( null === $record ) {
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::RUN_NOT_FOUND );
			}
			return new Read_Legacy_Migration_Run_Result( $this->validate_record( $run_id, $record ) );
		} catch ( Legacy_Migration_Failure $failure ) {
			throw $failure;
		} catch ( InvalidArgumentException | JsonException $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::RUN_DATA_INVALID, $error );
		} catch ( Throwable $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE, $error );
		}
	}

	/**
	 * Validate record.
	 *
	 * @param string              $run_id Run id.
	 * @param array<string,mixed> $record Record.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function validate_record( string $run_id, array $record ): array {
		$this->exact(
			$record,
			array(
				'run_database_id',
				'public_id',
				'created_at_utc',
				'updated_at_utc',
				'source_kind',
				'source_version',
				'source_fingerprint',
				'state',
				'preview_schema',
				'preview_version',
				'preview_json',
				'preview_hash',
				'approved_at_utc',
				'checkpoint_schema',
				'checkpoint_version',
				'checkpoint_json',
				'checkpoint_hash',
				'item_total',
				'success_total',
				'failure_total',
				'skipped_total',
				'error_code',
				'observed_item_total',
				'observed_success_total',
				'observed_failure_total',
				'observed_skipped_total',
			)
		);
		if ( $run_id !== ( $record['public_id'] ?? null ) ) {
			$this->stored_invalid();
		}
		$this->positive_integer( $record['run_database_id'] ?? null );
		$created_at_utc  = $this->utc( $record['created_at_utc'] ?? null );
		$updated_at_utc  = $this->utc( $record['updated_at_utc'] ?? null );
		$approved_at_utc = $this->utc( $record['approved_at_utc'] ?? null );
		if ( $approved_at_utc < $created_at_utc || $updated_at_utc < $approved_at_utc ) {
			$this->stored_invalid();
		}

		$state = $record['state'] ?? null;
		if ( ! is_string( $state ) || ! in_array( $state, array( 'approved', 'running', 'completed', 'completed_with_errors', 'compensated' ), true ) ) {
			$this->stored_invalid();
		}
		$source_kind        = $this->required_token( $record['source_kind'] ?? null, 64 );
		$source_version     = $this->required_token( $record['source_version'] ?? null, 64 );
		$source_fingerprint = $this->hash_value( $record['source_fingerprint'] ?? null );
		$item_total         = $this->non_negative_integer( $record['item_total'] ?? null );
		$success_total      = $this->non_negative_integer( $record['success_total'] ?? null );
		$failure_total      = $this->non_negative_integer( $record['failure_total'] ?? null );
		$skipped_total      = $this->non_negative_integer( $record['skipped_total'] ?? null );

		if (
			Approve_Legacy_Migration::PREVIEW_SCHEMA !== ( $record['preview_schema'] ?? null )
			|| Approve_Legacy_Migration::PREVIEW_DATABASE_VERSION !== $this->database_version( $record['preview_version'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::RUN_PROFILE_UNSUPPORTED );
		}
		$preview_hash = $this->hash_value( $record['preview_hash'] ?? null );
		$preview      = $this->decode( $record['preview_json'] ?? null, $preview_hash );
		$this->exact( $preview, array( 'profile', 'state', 'plan' ) );
		if ( Approve_Legacy_Migration::PREVIEW_PROFILE !== ( $preview['profile'] ?? null ) || 'approved' !== ( $preview['state'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::RUN_PROFILE_UNSUPPORTED );
		}
		$plan = $preview['plan'] ?? null;
		if ( ! is_array( $plan ) || array_is_list( $plan ) ) {
			$this->stored_invalid();
		}
		( new Legacy_Migration_Plan( $this->canonical ) )->assert_valid( $plan );
		$source = $plan['source'] ?? null;
		$counts = $plan['counts'] ?? null;
		if ( ! is_array( $source ) || array_is_list( $source ) || ! is_array( $counts ) || array_is_list( $counts ) ) {
			$this->stored_invalid();
		}
		$source_profile = $this->required_token( $source['profile'] ?? null, 128 );
		$profile_kind   = strstr( $source_profile, '@', true );
		$plan_hash      = $this->hash_value( $plan['plan_hash'] ?? null );
		if (
			! is_string( $profile_kind ) || ! hash_equals( $source_kind, $profile_kind )
			|| ! hash_equals( $source_version, (string) ( $source['version'] ?? '' ) )
			|| ! hash_equals( $source_fingerprint, (string) ( $source['fingerprint'] ?? '' ) )
			|| $item_total !== ( $counts['migration_candidates'] ?? null )
		) {
			$this->stored_invalid();
		}

		$items      = $this->item_summary( $record, $item_total, $success_total, $failure_total, $skipped_total );
		$checkpoint = $this->checkpoint(
			$record,
			$state,
			$source_profile,
			$source_fingerprint,
			$plan_hash,
			$item_total,
			$success_total,
			$failure_total,
			$skipped_total
		);
		$this->assert_state( $state, $item_total, $items['processed'], $failure_total, $record['error_code'] ?? null );

		return array(
			'id'             => $run_id,
			'state'          => $state,
			'source'         => array(
				'profile'     => $source_profile,
				'version'     => $source_version,
				'fingerprint' => $source_fingerprint,
			),
			'plan_hash'      => $plan_hash,
			'preview'        => array(
				'profile'      => Approve_Legacy_Migration::PREVIEW_PROFILE,
				'plan_profile' => (string) $plan['profile'],
				'hash'         => $preview_hash,
				'counts'       => array(
					'source_rows'          => (int) $counts['source_rows'],
					'migration_candidates' => (int) $counts['migration_candidates'],
					'excluded'             => (int) $counts['excluded'],
					'conflicts'            => (int) $counts['conflicts'],
					'mapping_needed'       => (int) $counts['mapping_needed'],
				),
			),
			'approval'       => array(
				'state'           => 'approved',
				'approved_at_utc' => $approved_at_utc,
			),
			'checkpoint'     => $checkpoint,
			'items'          => $items,
			'error'          => null === $record['error_code'] ? null : array( 'code' => (string) $record['error_code'] ),
			'created_at_utc' => $created_at_utc,
			'updated_at_utc' => $updated_at_utc,
		);
	}

	/**
	 * Item summary.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param int                 $item_total Item total.
	 * @param int                 $success Success.
	 * @param int                 $failure Failure.
	 * @param int                 $skipped Skipped.
	 * @return array{total:int,processed:int,pending:int,success:int,failure:int,skipped:int}
	 */
	private function item_summary( array $record, int $item_total, int $success, int $failure, int $skipped ): array {
		$observed_total   = $this->non_negative_integer( $record['observed_item_total'] ?? null );
		$observed_success = $this->non_negative_integer( $record['observed_success_total'] ?? null );
		$observed_failure = $this->non_negative_integer( $record['observed_failure_total'] ?? null );
		$observed_skipped = $this->non_negative_integer( $record['observed_skipped_total'] ?? null );
		$processed        = $success + $failure + $skipped;
		if (
			$observed_total !== $observed_success + $observed_failure + $observed_skipped
			|| $success !== $observed_success || $failure !== $observed_failure || $skipped !== $observed_skipped
			|| $processed > $item_total
		) {
			$this->stored_invalid();
		}
		return array(
			'total'     => $item_total,
			'processed' => $processed,
			'pending'   => $item_total - $processed,
			'success'   => $success,
			'failure'   => $failure,
			'skipped'   => $skipped,
		);
	}

	/**
	 * Checkpoint.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param string              $state State.
	 * @param string              $source_profile Source profile.
	 * @param string              $source_fingerprint Source fingerprint.
	 * @param string              $plan_hash Plan hash.
	 * @param int                 $item_total Item total.
	 * @param int                 $success Success.
	 * @param int                 $failure Failure.
	 * @param int                 $skipped Skipped.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function checkpoint(
		array $record,
		string $state,
		string $source_profile,
		string $source_fingerprint,
		string $plan_hash,
		int $item_total,
		int $success,
		int $failure,
		int $skipped
	): ?array {
		$values   = array(
			$record['checkpoint_schema'] ?? null,
			$record['checkpoint_version'] ?? null,
			$record['checkpoint_json'] ?? null,
			$record['checkpoint_hash'] ?? null,
		);
		$non_null = count( array_filter( $values, static fn ( mixed $value ): bool => null !== $value ) );
		if ( 0 === $non_null ) {
			if ( 'approved' !== $state ) {
				$this->stored_invalid();
			}
			return null;
		}
		if ( 4 !== $non_null || 'approved' === $state ) {
			$this->stored_invalid();
		}
		if (
			Execute_Legacy_Migration::CHECKPOINT_SCHEMA !== $record['checkpoint_schema']
			|| Execute_Legacy_Migration::CHECKPOINT_VERSION !== $this->database_version( $record['checkpoint_version'] )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::RUN_PROFILE_UNSUPPORTED );
		}
		$hash     = $this->hash_value( $record['checkpoint_hash'] );
		$document = $this->decode( $record['checkpoint_json'], $hash );
		$this->exact( $document, array( 'batch_size', 'cursor', 'phase', 'plan_hash', 'profile', 'source', 'totals' ) );
		$source          = $this->object_value( $document['source'] ?? null, array( 'fingerprint', 'profile' ) );
		$cursor          = $this->object_value( $document['cursor'] ?? null, array( 'after_source_id', 'next_item_no', 'table_index' ) );
		$totals          = $this->object_value( $document['totals'] ?? null, array( 'failure', 'skipped', 'success' ) );
		$table_index     = $this->non_negative_integer( $cursor['table_index'] ?? null );
		$after_source_id = $this->non_negative_integer( $cursor['after_source_id'] ?? null );
		$next_item_no    = $this->positive_integer( $cursor['next_item_no'] ?? null );
		if (
			Execute_Legacy_Migration::CHECKPOINT_PROFILE !== ( $document['profile'] ?? null )
			|| $state !== ( $document['phase'] ?? null )
			|| Execute_Legacy_Migration::BATCH_SIZE !== ( $document['batch_size'] ?? null )
			|| ! hash_equals( $source_profile, (string) ( $source['profile'] ?? '' ) )
			|| ! hash_equals( $source_fingerprint, (string) ( $source['fingerprint'] ?? '' ) )
			|| ! hash_equals( $plan_hash, (string) ( $document['plan_hash'] ?? '' ) )
			|| $success !== ( $totals['success'] ?? null )
			|| $failure !== ( $totals['failure'] ?? null )
			|| $skipped !== ( $totals['skipped'] ?? null )
			|| $next_item_no !== $success + $failure + $skipped + 1
			|| $next_item_no > $item_total + 1
		) {
			$this->stored_invalid();
		}
		return array(
			'profile'    => Execute_Legacy_Migration::CHECKPOINT_PROFILE,
			'phase'      => $state,
			'batch_size' => Execute_Legacy_Migration::BATCH_SIZE,
			'cursor'     => array(
				'table_index'     => $table_index,
				'after_source_id' => $after_source_id,
				'next_item_no'    => $next_item_no,
			),
			'totals'     => array(
				'success' => $success,
				'failure' => $failure,
				'skipped' => $skipped,
			),
			'hash'       => $hash,
		);
	}

	/**
	 * Reject invalid state.
	 *
	 * @param string $state State.
	 * @param int    $item_total Item total.
	 * @param int    $processed Processed.
	 * @param int    $failure Failure.
	 * @param mixed  $error_code Error code.
	 * @return void
	 */
	private function assert_state( string $state, int $item_total, int $processed, int $failure, mixed $error_code ): void {
		if ( null !== $error_code && ( ! is_string( $error_code ) || 1 !== preg_match( '/^[a-z0-9][a-z0-9_.:-]{2,99}$/D', $error_code ) ) ) {
			$this->stored_invalid();
		}
		if ( 'approved' === $state && ( 0 !== $processed || null !== $error_code ) ) {
			$this->stored_invalid();
		}
		if ( 'running' === $state && null !== $error_code ) {
			$this->stored_invalid();
		}
		if ( 'completed' === $state && ( $processed !== $item_total || 0 !== $failure || null !== $error_code ) ) {
			$this->stored_invalid();
		}
		if ( 'completed_with_errors' === $state && ( $processed !== $item_total || $failure < 1 || 'legacy_migration_item_failures' !== $error_code ) ) {
			$this->stored_invalid();
		}
		if ( 'compensated' === $state && ( $processed !== $item_total || null !== $error_code ) ) {
			$this->stored_invalid();
		}
	}

	/**
	 * Decode legacy migration run.
	 *
	 * @param mixed  $json Json.
	 * @param string $hash Hash.
	 * @return array<string,mixed>
	 */
	private function decode( mixed $json, string $hash ): array {
		if ( ! is_string( $json ) || ! hash_equals( $hash, hash( 'sha256', $json ) ) ) {
			$this->stored_invalid();
		}
		$value = json_decode( $json, true, 128, JSON_THROW_ON_ERROR );
		if ( ! is_array( $value ) || array_is_list( $value ) || ! hash_equals( $json, $this->canonical->encode( $value ) ) ) {
			$this->stored_invalid();
		}
		return $value;
	}

	/**
	 * Exact.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $keys Keys.
	 * @return void
	 */
	private function exact( array $value, array $keys ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $keys, SORT_STRING );
		if ( $actual !== $keys ) {
			$this->stored_invalid();
		}
	}

	/**
	 * Object value.
	 *
	 * @param mixed             $value Value.
	 * @param array<int,string> $keys Keys.
	 * @return array<string,mixed>
	 */
	private function object_value( mixed $value, array $keys ): array {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			$this->stored_invalid();
		}
		$this->exact( $value, $keys );
		return $value;
	}

	/**
	 * Hash value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function hash_value( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			$this->stored_invalid();
		}
		return $value;
	}

	/**
	 * Required token.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @return string
	 */
	private function required_token( mixed $value, int $maximum ): string {
		if ( ! is_string( $value ) || strlen( $value ) > $maximum || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]+$/D', $value ) ) {
			$this->stored_invalid();
		}
		return $value;
	}

	/**
	 * Utc.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function utc( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			$this->stored_invalid();
		}
		return $value;
	}

	/**
	 * Database version.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private function database_version( mixed $value ): int {
		$value = $this->non_negative_integer( $value );
		if ( $value < 1 ) {
			$this->stored_invalid();
		}
		return $value;
	}

	/**
	 * Positive integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private function positive_integer( mixed $value ): int {
		$value = $this->non_negative_integer( $value );
		if ( $value < 1 ) {
			$this->stored_invalid();
		}
		return $value;
	}

	/**
	 * Non negative integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private function non_negative_integer( mixed $value ): int {
		if ( is_string( $value ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) {
			$value = (int) $value;
		}
		if ( ! is_int( $value ) || $value < 0 ) {
			$this->stored_invalid();
		}
		return $value;
	}

	/**
	 * Stored invalid.
	 *
	 * @return never
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function stored_invalid(): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::RUN_DATA_INVALID );
	}
}
