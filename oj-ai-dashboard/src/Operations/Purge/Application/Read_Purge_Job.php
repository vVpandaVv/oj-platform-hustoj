<?php
/**
 * Read purge job.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Purge\Application;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Database\Foundation\Installation_State;
use OJAI\Dashboard\Database\Foundation\Schema_Catalog;
use OJAI\Dashboard\Operations\Purge\Domain\Purge_Target_Catalog;
use OJAI\Dashboard\Operations\Purge\Port\Purge_Job_Query_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates and projects the frozen A-DS08-Q36 purge-job query.
 */
final class Read_Purge_Job {
	public const ACTION_READ = 'operations.purge.read';

	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize purge job dependencies and state.
	 *
	 * @param Purge_Job_Query_Port_Interface $jobs Jobs.
	 * @param Canonical_Json                 $canonical Canonical JSON encoder used for stable digests.
	 * @param Schema_Catalog                 $catalog Catalog.
	 * @param callable|null                  $clock Clock.
	 */
	public function __construct(
		private Purge_Job_Query_Port_Interface $jobs,
		private Canonical_Json $canonical,
		private Schema_Catalog $catalog,
		?callable $clock = null
	) {
		$this->clock = null === $clock
			? static fn (): string => gmdate( 'Y-m-d H:i:s' )
			: Closure::fromCallable( $clock );
	}

	/**
	 * Read purge job.
	 *
	 * @param string $job_id Job id.
	 * @return Read_Purge_Job_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function read( string $job_id ): Read_Purge_Job_Result {
		try {
			if ( ! Public_Id::is_valid( $job_id ) ) {
				throw Purge_Failure::because( Purge_Failure::JOB_NOT_FOUND );
			}
			$record = $this->jobs->find_record( $job_id );
			if ( null === $record ) {
				throw Purge_Failure::because( Purge_Failure::JOB_NOT_FOUND );
			}
			return new Read_Purge_Job_Result( $this->validate_record( $job_id, $record ) );
		} catch ( Purge_Failure $failure ) {
			throw $failure;
		} catch ( InvalidArgumentException | JsonException $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::JOB_DATA_INVALID, $error );
		} catch ( Throwable $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE, $error );
		}
	}

	/**
	 * Validate record.
	 *
	 * @param string              $job_id Job id.
	 * @param array<string,mixed> $record Record.
	 * @return array<string,mixed>
	 */
	private function validate_record( string $job_id, array $record ): array {
		$this->exact(
			$record,
			array(
				'job_database_id',
				'public_id',
				'lock_version',
				'created_at_utc',
				'updated_at_utc',
				'scope_type',
				'scope_public_id',
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
				'completed_at_utc',
				'error_code',
				'current_legal_hold_count',
			)
		);
		if ( $job_id !== ( $record['public_id'] ?? null ) ) {
			$this->stored_invalid();
		}
		$this->positive_integer( $record['job_database_id'] ?? null );
		$this->non_negative_integer( $record['lock_version'] ?? null );

		$created  = $this->utc( $record['created_at_utc'] ?? null );
		$updated  = $this->utc( $record['updated_at_utc'] ?? null );
		$approved = $this->utc( $record['approved_at_utc'] ?? null );
		if ( $approved < $created || $updated < $approved ) {
			$this->stored_invalid();
		}

		$state = $record['state'] ?? null;
		if ( ! is_string( $state ) || ! in_array( $state, array( 'approved', 'running', 'cancelled', 'completed', 'failed' ), true ) ) {
			$this->stored_invalid();
		}
		if ( Preview_Purge_Command::SCOPE_ADDON !== ( $record['scope_type'] ?? null ) || null !== ( $record['scope_public_id'] ?? null ) ) {
			$this->stored_invalid();
		}

		$preview            = $this->preview( $record );
		$checkpoint         = $this->checkpoint( $record, $state, $preview );
		$completed_at_utc   = $this->completed_at( $record['completed_at_utc'] ?? null, $state, $approved, $updated );
		$error              = $this->error( $record['error_code'] ?? null, $state );
		$current_hold_count = $this->non_negative_integer( $record['current_legal_hold_count'] ?? null );
		$preview_hold_count = (int) $preview['private_files']['legal_hold_count'];

		$expires = $approved->modify( '+' . Execute_Purge::APPROVAL_TTL_SECONDS . ' seconds' );
		return array(
			'id'               => $job_id,
			'state'            => $state,
			'preview'          => array(
				'profile'         => Purge_Preview_Builder::PROFILE,
				'hash'            => $preview['preview_hash'],
				'scope_hash'      => $preview['scope_hash'],
				'table_count'     => $preview['table_count'],
				'total_row_count' => $preview['total_row_count'],
				'owner_rows'      => $preview['owner_rows'],
				'private_files'   => array(
					'file_count' => $preview['private_files']['file_count'],
					'blob_bytes' => $preview['private_files']['blob_bytes'],
				),
				'incomplete_jobs' => $preview['incomplete_jobs'],
			),
			'approval'         => array(
				'status'          => $this->now() >= $expires ? 'expired' : 'valid',
				'approved_at_utc' => $approved->format( 'Y-m-d H:i:s' ),
				'expires_at_utc'  => $expires->format( 'Y-m-d H:i:s' ),
			),
			'checkpoint'       => $checkpoint,
			'legal_hold'       => array(
				'preview_count'    => $preview_hold_count,
				'current_count'    => $current_hold_count,
				'changed'          => $preview_hold_count !== $current_hold_count,
				'blocks_execution' => $current_hold_count > 0,
			),
			'error'            => $error,
			'created_at_utc'   => $created->format( 'Y-m-d H:i:s' ),
			'updated_at_utc'   => $updated->format( 'Y-m-d H:i:s' ),
			'completed_at_utc' => $completed_at_utc,
		);
	}

	/**
	 * Preview.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function preview( array $record ): array {
		if (
			Approve_Purge::PREVIEW_SCHEMA !== ( $record['preview_schema'] ?? null )
			|| Approve_Purge::PREVIEW_DATABASE_VERSION !== $this->database_version( $record['preview_version'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::JOB_PROFILE_UNSUPPORTED );
		}
		$preview = $this->decode(
			$record['preview_json'] ?? null,
			$this->hash_value( $record['preview_hash'] ?? null )
		);
		$this->exact(
			$preview,
			array(
				'profile',
				'scope',
				'scope_hash',
				'table_count',
				'total_row_count',
				'owner_rows',
				'private_files',
				'incomplete_jobs',
				'preview_hash',
			)
		);
		if ( Purge_Preview_Builder::PROFILE !== ( $preview['profile'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::JOB_PROFILE_UNSUPPORTED );
		}

		$scope = $this->object_value( $preview['scope'] ?? null, array( 'type', 'public_id' ) );
		if ( Preview_Purge_Command::SCOPE_ADDON !== ( $scope['type'] ?? null ) || null !== ( $scope['public_id'] ?? null ) ) {
			$this->stored_invalid();
		}
		$scope_hash       = $this->hash_value( $preview['scope_hash'] ?? null );
		$scope_descriptor = array(
			'addon_id'           => Schema_Catalog::ADDON_ID,
			'schema_version'     => Schema_Catalog::SCHEMA_VERSION,
			'schema_fingerprint' => $this->catalog->fingerprint(),
			'table_names'        => $this->catalog->table_names(),
			'scope'              => $scope,
		);
		if ( ! hash_equals( $scope_hash, hash( 'sha256', $this->canonical->encode( $scope_descriptor ) ) ) ) {
			$this->stored_invalid();
		}
		if ( Schema_Catalog::TABLE_COUNT !== $this->non_negative_integer( $preview['table_count'] ?? null ) ) {
			$this->stored_invalid();
		}

		$owner_rows = $this->count_map( $preview['owner_rows'] ?? null, array_keys( $this->catalog->owner_counts() ) );
		$total_rows = $this->sum( $owner_rows );
		if ( $total_rows !== $this->non_negative_integer( $preview['total_row_count'] ?? null ) ) {
			$this->stored_invalid();
		}
		$private = $this->integer_object(
			$preview['private_files'] ?? null,
			array( 'file_count', 'blob_bytes', 'legal_hold_count' )
		);
		if ( 0 !== $private['legal_hold_count'] || $private['legal_hold_count'] > $private['file_count'] ) {
			$this->stored_invalid();
		}
		$jobs_object = $this->object_value( $preview['incomplete_jobs'] ?? null, array( 'total', 'by_type' ) );
		$jobs        = $this->count_map(
			$jobs_object['by_type'] ?? null,
			array( 'evaluation_runs', 'export_jobs', 'zip_uploads', 'import_runs', 'purge_jobs' )
		);
		$job_total   = $this->sum( $jobs );
		if ( $job_total !== $this->non_negative_integer( $jobs_object['total'] ?? null ) ) {
			$this->stored_invalid();
		}

		$preview_hash = $this->hash_value( $preview['preview_hash'] ?? null );
		$hash_input   = $preview;
		unset( $hash_input['preview_hash'] );
		if ( ! hash_equals( $preview_hash, hash( 'sha256', $this->canonical->encode( $hash_input ) ) ) ) {
			$this->stored_invalid();
		}

		$preview['owner_rows']      = $owner_rows;
		$preview['total_row_count'] = $total_rows;
		$preview['private_files']   = $private;
		$preview['incomplete_jobs'] = array(
			'total'   => $job_total,
			'by_type' => $jobs,
		);
		return $preview;
	}

	/**
	 * Checkpoint.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param string              $state State.
	 * @param array<string,mixed> $preview Preview.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function checkpoint( array $record, string $state, array $preview ): ?array {
		$values   = array(
			$record['checkpoint_schema'] ?? null,
			$record['checkpoint_version'] ?? null,
			$record['checkpoint_json'] ?? null,
			$record['checkpoint_hash'] ?? null,
		);
		$non_null = count( array_filter( $values, static fn ( mixed $value ): bool => null !== $value ) );
		if ( 0 === $non_null ) {
			if ( ! in_array( $state, array( 'approved', 'cancelled' ), true ) ) {
				$this->stored_invalid();
			}
			return null;
		}
		if ( 4 !== $non_null || 'approved' === $state ) {
			$this->stored_invalid();
		}
		if (
			Execute_Purge::CHECKPOINT_SCHEMA !== ( $record['checkpoint_schema'] ?? null )
			|| Execute_Purge::CHECKPOINT_VERSION !== $this->database_version( $record['checkpoint_version'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::JOB_PROFILE_UNSUPPORTED );
		}

		$hash     = $this->hash_value( $record['checkpoint_hash'] ?? null );
		$document = $this->decode( $record['checkpoint_json'] ?? null, $hash );
		$this->exact(
			$document,
			array(
				'profile',
				'phase',
				'batch_size',
				'time_limit_seconds',
				'scope_hash',
				'preview_hash',
				'cursor',
				'totals',
				'expected',
				'maintenance',
				'postcondition',
			)
		);
		if ( Execute_Purge::CHECKPOINT_PROFILE !== ( $document['profile'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::JOB_PROFILE_UNSUPPORTED );
		}
		if (
			$state !== ( $document['phase'] ?? null )
			|| Execute_Purge::BATCH_SIZE !== ( $document['batch_size'] ?? null )
			|| Execute_Purge::TIME_LIMIT_SECONDS !== ( $document['time_limit_seconds'] ?? null )
			|| ! hash_equals( (string) $preview['scope_hash'], (string) ( $document['scope_hash'] ?? '' ) )
			|| ! hash_equals( (string) $preview['preview_hash'], (string) ( $document['preview_hash'] ?? '' ) )
		) {
			$this->stored_invalid();
		}

		$cursor      = $this->object_value( $document['cursor'] ?? null, array( 'stage', 'table_index' ) );
		$table_index = $this->non_negative_integer( $cursor['table_index'] ?? null );
		if ( ! in_array( $cursor['stage'] ?? null, array( 'private_files', 'tables', 'verify' ), true ) || $table_index > Purge_Target_Catalog::TARGET_TABLE_COUNT ) {
			$this->stored_invalid();
		}
		$totals               = $this->integer_object( $document['totals'] ?? null, array( 'deleted_rows', 'deleted_files', 'deleted_bytes' ) );
		$expected             = $this->integer_object(
			$document['expected'] ?? null,
			array( 'target_table_count', 'target_row_count', 'private_file_count', 'private_blob_bytes' )
		);
		$expected_target_rows = ( new Purge_Target_Catalog( $this->catalog ) )->expected_row_count( $preview );
		if (
			Purge_Target_Catalog::TARGET_TABLE_COUNT !== $expected['target_table_count']
			|| $expected_target_rows !== $expected['target_row_count']
			|| $preview['private_files']['file_count'] !== $expected['private_file_count']
			|| $preview['private_files']['blob_bytes'] !== $expected['private_blob_bytes']
		) {
			$this->stored_invalid();
		}
		$maintenance = $this->object_value( $document['maintenance'] ?? null, array( 'previous_installation_state' ) );
		if ( ! in_array(
			$maintenance['previous_installation_state'] ?? null,
			array( Installation_State::REGISTRATION_PENDING, Installation_State::RUNTIME_READY, Installation_State::MAINTENANCE_REQUIRED ),
			true
		) ) {
			$this->stored_invalid();
		}
		$postcondition_object = $this->object_value(
			$document['postcondition'] ?? null,
			array( 'remaining_rows', 'remaining_files', 'remaining_blob_bytes', 'verified_at_utc' )
		);
		$postcondition        = array(
			'remaining_rows'       => $this->non_negative_integer( $postcondition_object['remaining_rows'] ?? null ),
			'remaining_files'      => $this->non_negative_integer( $postcondition_object['remaining_files'] ?? null ),
			'remaining_blob_bytes' => $this->non_negative_integer( $postcondition_object['remaining_blob_bytes'] ?? null ),
		);
		$verified             = $postcondition_object['verified_at_utc'] ?? null;
		if ( null !== $verified ) {
			$verified = $this->utc( $verified )->format( 'Y-m-d H:i:s' );
		}
		if (
			$totals['deleted_rows'] > $expected['target_row_count']
			|| $totals['deleted_files'] > $expected['private_file_count']
			|| $totals['deleted_bytes'] > $expected['private_blob_bytes']
		) {
			$this->stored_invalid();
		}
		if ( 'completed' === $state && (
			null === $verified
			|| 0 !== $postcondition['remaining_rows']
			|| 0 !== $postcondition['remaining_files']
			|| 0 !== $postcondition['remaining_blob_bytes']
			|| $totals['deleted_rows'] !== $expected['target_row_count']
			|| $totals['deleted_files'] !== $expected['private_file_count']
			|| $totals['deleted_bytes'] !== $expected['private_blob_bytes']
		) ) {
			$this->stored_invalid();
		}

		return array(
			'profile'            => Execute_Purge::CHECKPOINT_PROFILE,
			'phase'              => $state,
			'batch_size'         => Execute_Purge::BATCH_SIZE,
			'time_limit_seconds' => Execute_Purge::TIME_LIMIT_SECONDS,
			'cursor'             => array(
				'stage'       => $cursor['stage'],
				'table_index' => $table_index,
			),
			'totals'             => $totals,
			'postcondition'      => array_merge( $postcondition, array( 'verified_at_utc' => $verified ) ),
			'hash'               => $hash,
		);
	}

	/**
	 * Completed at.
	 *
	 * @param mixed             $value Value.
	 * @param string            $state State.
	 * @param DateTimeImmutable $approved Approved.
	 * @param DateTimeImmutable $updated Updated.
	 * @return string|null
	 */
	private function completed_at( mixed $value, string $state, DateTimeImmutable $approved, DateTimeImmutable $updated ): ?string {
		if ( ! in_array( $state, array( 'cancelled', 'completed', 'failed' ), true ) ) {
			if ( null !== $value ) {
				$this->stored_invalid();
			}
			return null;
		}
		$completed = $this->utc( $value );
		if ( $completed < $approved || $completed > $updated ) {
			$this->stored_invalid();
		}
		return $completed->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Error.
	 *
	 * @param mixed  $value Value.
	 * @param string $state State.
	 * @return array{code:string}|null
	 */
	private function error( mixed $value, string $state ): ?array {
		if ( 'failed' !== $state ) {
			if ( null !== $value ) {
				$this->stored_invalid();
			}
			return null;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-z0-9][a-z0-9_.:-]{2,99}$/D', $value ) ) {
			$this->stored_invalid();
		}
		return array( 'code' => $value );
	}

	/**
	 * Decode purge job.
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
	 * Integer object.
	 *
	 * @param mixed             $value Value.
	 * @param array<int,string> $keys Keys.
	 * @return array<string,int>
	 */
	private function integer_object( mixed $value, array $keys ): array {
		$object = $this->object_value( $value, $keys );
		$result = array();
		foreach ( $keys as $key ) {
			$result[ $key ] = $this->non_negative_integer( $object[ $key ] ?? null );
		}
		return $result;
	}

	/**
	 * Count map.
	 *
	 * @param mixed             $value Value.
	 * @param array<int,string> $keys Keys.
	 * @return array<string,int>
	 */
	private function count_map( mixed $value, array $keys ): array {
		return $this->integer_object( $value, $keys );
	}

	/**
	 * Sum.
	 *
	 * @param array<string,int> $counts Counts.
	 * @return int
	 */
	private function sum( array $counts ): int {
		$total = 0;
		foreach ( $counts as $count ) {
			if ( $count > PHP_INT_MAX - $total ) {
				$this->stored_invalid();
			}
			$total += $count;
		}
		return $total;
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
	 * Utc.
	 *
	 * @param mixed $value Value.
	 * @return DateTimeImmutable
	 */
	private function utc( mixed $value ): DateTimeImmutable {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			$this->stored_invalid();
		}
		$time = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );
		if ( ! $time instanceof DateTimeImmutable || $time->format( 'Y-m-d H:i:s' ) !== $value ) {
			$this->stored_invalid();
		}
		return $time;
	}

	/**
	 * Now.
	 *
	 * @return DateTimeImmutable
	 */
	private function now(): DateTimeImmutable {
		$value = ( $this->clock )();
		return $this->utc( is_string( $value ) ? $value : null );
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
			$integer = (int) $value;
			if ( (string) $integer !== $value ) {
				$this->stored_invalid();
			}
			$value = $integer;
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
		throw Purge_Failure::because( Purge_Failure::JOB_DATA_INVALID );
	}
}
