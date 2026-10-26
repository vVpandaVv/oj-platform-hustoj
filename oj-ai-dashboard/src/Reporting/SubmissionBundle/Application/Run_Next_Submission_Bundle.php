<?php
/**
 * Run next submission bundle.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\SubmissionBundle\Application;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OJAI\Dashboard\Reporting\Export\Domain\Export_Work_Item;
use OJAI\Dashboard\Reporting\Export\Port\Export_Work_Repository_Port_Interface;
use OJAI\Dashboard\Reporting\SubmissionBundle\Domain\Submission_Bundle_Request_Snapshot;
use OJAI\Dashboard\Reporting\SubmissionBundle\Port\Submission_Bundle_Source_Port_Interface;
use OJAI\Dashboard\Reporting\SubmissionBundle\Port\Submission_Bundle_Writer_Port_Interface;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Context_Scope;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use OJAI\Dashboard\Storage\Port\Private_File_Store_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Executes one C50 job against the exact official submission manifest.
 */
final class Run_Next_Submission_Bundle {
	public const ACTION_EXECUTE    = 'assignment.submission_bundle.execute';
	public const LEASE_SECONDS     = 300;
	public const RETENTION_SECONDS = 86400;

	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;
	/**
	 * Lease tokens.
	 *
	 * @var Closure
	 */
	private Closure $lease_tokens;

	/**
	 * Initialize submission bundle dependencies and state.
	 *
	 * @param Export_Work_Repository_Port_Interface   $repository Persistence boundary used by this operation.
	 * @param Submission_Bundle_Source_Port_Interface $source Source.
	 * @param Submission_Bundle_Writer_Port_Interface $writer Writer.
	 * @param Private_File_Store_Port_Interface       $files Files.
	 * @param Access_Policy_Port_Interface            $policy Policy.
	 * @param Public_Id_Generator                     $ids Ids.
	 * @param Canonical_Json                          $canonical Canonical JSON encoder used for stable digests.
	 * @param callable|null                           $clock Clock.
	 * @param callable|null                           $lease_tokens Lease tokens.
	 */
	public function __construct(
		private Export_Work_Repository_Port_Interface $repository,
		private Submission_Bundle_Source_Port_Interface $source,
		private Submission_Bundle_Writer_Port_Interface $writer,
		private Private_File_Store_Port_Interface $files,
		private Access_Policy_Port_Interface $policy,
		private Public_Id_Generator $ids,
		private Canonical_Json $canonical,
		?callable $clock = null,
		?callable $lease_tokens = null
	) {
		$this->clock        = null === $clock ? static fn (): string => gmdate( 'Y-m-d H:i:s' ) : Closure::fromCallable( $clock );
		$this->lease_tokens = null === $lease_tokens ? static fn (): string => self::new_lease_token() : Closure::fromCallable( $lease_tokens );
	}

	/**
	 * Run once.
	 *
	 * @return Run_Submission_Bundle_Result
	 */
	public function run_once(): Run_Submission_Bundle_Result {
		$now   = $this->now();
		$lease = (string) ( $this->lease_tokens )();
		$item  = $this->repository->claim_due(
			array( Submission_Bundle_Request_Snapshot::ARTIFACT_KIND ),
			$now,
			$lease,
			$this->plus_seconds( $now, self::LEASE_SECONDS )
		);
		if ( null === $item ) {
			return new Run_Submission_Bundle_Result( Run_Submission_Bundle_Result::IDLE );
		}

		$created     = false;
		$storage_key = '';
		$temporary   = '';
		try {
			$snapshot = Submission_Bundle_Request_Snapshot::restore( $item->snapshot_json(), $item->snapshot_hash(), $this->canonical );
			$this->assert_contract( $item, $snapshot );
			$this->authorize( $item, $snapshot );
			$this->assert_current( $item, $snapshot );
			$generated = $this->writer->write( $snapshot );
			$temporary = $generated->path();
			$this->authorize( $item, $snapshot );
			$this->assert_current( $item, $snapshot );

			$file_id     = $this->ids->create()->value();
			$storage_key = 'generated/submission-bundle/' . strtolower( $file_id ) . '.zip';
			$created     = $this->files->put_quarantined( $temporary, $storage_key, $generated->sha256() );
			$finished    = $this->now();
			$file        = array(
				'actor_user_id'      => $item->actor_user_id(),
				'artifact_kind'      => $item->artifact_kind(),
				'artifact_public_id' => $file_id,
				'byte_size'          => $generated->byte_size(),
				'created_at_utc'     => $finished,
				'expires_at_utc'     => $this->plus_seconds( $finished, self::RETENTION_SECONDS ),
				'file_public_id'     => $file_id,
				'job_public_id'      => $item->job_id(),
				'mime_type'          => $generated->mime_type(),
				'original_name'      => $generated->original_name(),
				'sha256'             => $generated->sha256(),
				'storage_key'        => $storage_key,
			);
			$artifact    = array(
				'artifact_public_id' => $file_id,
				'audit_public_id'    => $this->ids->create()->value(),
				'correlation_id'     => $this->correlation( $item ),
				'details'            => $generated->details(),
				'outbox_public_id'   => $this->ids->create()->value(),
				'page_count'         => null,
				'renderer_version'   => $generated->renderer_version(),
			);
			$committed   = $this->repository->complete_ready( $item, $file, $artifact, $finished );
			if ( ! $committed && $created ) {
				$this->files->delete( $storage_key );
			}
			return new Run_Submission_Bundle_Result(
				$committed ? Run_Submission_Bundle_Result::READY : Run_Submission_Bundle_Result::STALE,
				$item->job_id()
			);
		} catch ( Throwable $error ) {
			if ( '' !== $storage_key && $created ) {
				try {
					$this->files->delete( $storage_key ); } catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
					// This optional step must not replace the surrounding operation outcome.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- This optional step must not replace the surrounding operation outcome.
					}
			}
			$code = $error instanceof Submission_Bundle_Failure
				? $error->reason()
				: ( $error instanceof InvalidArgumentException ? Submission_Bundle_Failure::STORED_DATA_INVALID : Submission_Bundle_Failure::STORAGE_UNAVAILABLE );
			try {
				$failed = $this->repository->complete_failure(
					$item,
					$code,
					$this->ids->create()->value(),
					$this->ids->create()->value(),
					$this->correlation( $item ),
					$this->now()
				);
			} catch ( Throwable ) {
				$failed = false;
			}
			return new Run_Submission_Bundle_Result(
				$failed ? Run_Submission_Bundle_Result::FAILED : Run_Submission_Bundle_Result::STALE,
				$item->job_id()
			);
		} finally {
			if ( '' !== $temporary && is_file( $temporary ) ) {
				@unlink( $temporary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort removal of an owned temporary path must not mask the primary outcome. Private local streams and owned temporary paths require native filesystem operations, not WordPress FTP transport.
			} // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning. Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
		}
	}

	/**
	 * Reject invalid contract.
	 *
	 * @param Export_Work_Item                   $item Item.
	 * @param Submission_Bundle_Request_Snapshot $snapshot Snapshot.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_contract( Export_Work_Item $item, Submission_Bundle_Request_Snapshot $snapshot ): void {
		$context = $snapshot->context();
		if (
			'assignment' !== $item->source_type()
			|| Submission_Bundle_Request_Snapshot::VERSION !== $item->request_contract_version()
			|| Submission_Bundle_Request_Snapshot::SCHEMA !== $item->snapshot_schema()
			|| Submission_Bundle_Request_Snapshot::DATABASE_VERSION !== $item->snapshot_version()
			|| ! hash_equals( $snapshot->hash(), $item->request_hash() )
			|| ! hash_equals( $snapshot->source_id(), $item->source_id() )
			|| ! hash_equals( $snapshot->source_hash(), $item->source_hash() )
			|| Submission_Bundle_Request_Snapshot::ARTIFACT_KIND !== $item->artifact_kind()
			|| ! hash_equals( $snapshot->profile(), $item->profile_version() )
			|| ( $context['type'] ) !== $item->context_type()
			|| ( $context['id'] ) !== $item->context_id()
			|| ( $context['revision'] ) !== $item->context_revision()
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::STORED_DATA_INVALID );
		}
	}

	/**
	 * Authorize.
	 *
	 * @param Export_Work_Item                   $item Item.
	 * @param Submission_Bundle_Request_Snapshot $snapshot Snapshot.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function authorize( Export_Work_Item $item, Submission_Bundle_Request_Snapshot $snapshot ): void {
		try {
			$decision = $this->policy->authorize(
				new Access_Policy_Request(
					$item->actor_user_id(),
					Access_Policy_Request::SHELL_CONSOLE,
					Access_Policy_Request::SURFACE_JOB,
					self::ACTION_EXECUTE,
					array( Addon_Capabilities::EXPORT_REPORTS ),
					new Context_Scope(
						$item->context_type(),
						$item->context_id(),
						Context_Scope::OPERATION_REPORT,
						null,
						$item->context_revision()
					),
					new Object_Scope(
						Object_Scope::TYPE_ASSIGNMENT,
						$item->source_id(),
						'export_submissions',
						(string) $snapshot->schedule_version(),
						$snapshot->source_state()
					),
					true,
					$this->correlation( $item )
				)
			);
			$context  = $decision->context();
			$object   = $decision->object();
			if (
				! $decision->is_allowed()
				|| ! $context instanceof Context_Authorization_Result
				|| ! $context->is_allowed()
				|| ! $object instanceof Object_Authorization_Result
				|| ! $object->is_allowed()
				|| 'context_staff' !== $object->actor_relation()
				|| $context->type() !== $item->context_type()
				|| $context->id() !== $item->context_id()
				|| $context->revision() !== $item->context_revision()
				|| ! hash_equals( (string) $snapshot->schedule_version(), (string) $object->current_revision() )
				|| $snapshot->source_state() !== $object->current_state()
			) {
				throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::SOURCE_ACCESS_DENIED );
			}
		} catch ( Submission_Bundle_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::SOURCE_ACCESS_DENIED );
		}
	}

	/**
	 * Reject invalid current.
	 *
	 * @param Export_Work_Item                   $item Item.
	 * @param Submission_Bundle_Request_Snapshot $snapshot Snapshot.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_current( Export_Work_Item $item, Submission_Bundle_Request_Snapshot $snapshot ): void {
		try {
			$current = $this->source->read(
				$item->source_id(),
				$item->context_type(),
				$item->context_id(),
				$item->context_revision(),
				$snapshot->include_allowed_originals()
			);
			if ( null === $current ) {
				throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::SOURCE_STALE );
			}
			Submission_Bundle_Request_Snapshot::assert_source( $current, $snapshot->include_allowed_originals(), $this->canonical );
			if ( ! hash_equals( $this->canonical->encode( $snapshot->manifest() ), $this->canonical->encode( $current ) ) ) {
				throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::SOURCE_STALE );
			}
		} catch ( Submission_Bundle_Failure $failure ) {
			if ( in_array( $failure->reason(), array( Submission_Bundle_Failure::STORAGE_UNAVAILABLE, Submission_Bundle_Failure::STORED_DATA_INVALID ), true ) ) {
				throw $failure;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::SOURCE_STALE );
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::STORED_DATA_INVALID );
		}
	}

	/**
	 * Now.
	 *
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function now(): string {
		$value = (string) ( $this->clock )();
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::STORAGE_UNAVAILABLE );
		}
		return $value;
	}

	/**
	 * Plus seconds.
	 *
	 * @param string $utc Utc.
	 * @param int    $seconds Seconds.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function plus_seconds( string $utc, int $seconds ): string {
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $utc, new DateTimeZone( 'UTC' ) );
		if ( false === $date ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::STORAGE_UNAVAILABLE );
		}
		return $date->modify( '+' . $seconds . ' seconds' )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Correlation.
	 *
	 * @param Export_Work_Item $item Item.
	 * @return string
	 */
	private function correlation( Export_Work_Item $item ): string {
		return 'submission-bundle:' . substr( hash( 'sha256', $item->job_id() . '|' . $item->lease_token() ), 0, 32 );
	}

	/**
	 * New lease token.
	 *
	 * @return string
	 */
	private static function new_lease_token(): string {
		$bytes    = random_bytes( 16 );
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
		$hex      = bin2hex( $bytes );
		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20 );
	}
}
