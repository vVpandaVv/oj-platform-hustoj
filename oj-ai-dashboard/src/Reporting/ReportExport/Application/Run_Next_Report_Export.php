<?php
/**
 * Run next report export.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\ReportExport\Application;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OJAI\Dashboard\Reporting\Export\Domain\Export_Work_Item;
use OJAI\Dashboard\Reporting\Export\Port\Export_Work_Repository_Port_Interface;
use OJAI\Dashboard\Reporting\Report\Application\Read_Report_Snapshot;
use OJAI\Dashboard\Reporting\Report\Application\Report_Snapshot_Query_Failure;
use OJAI\Dashboard\Reporting\ReportExport\Domain\Report_Export_Request_Snapshot;
use OJAI\Dashboard\Reporting\ReportExport\Port\Report_Export_Writer_Port_Interface;
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
 * Executes one C46, C47, or C69 job against the exact report snapshot.
 */
final class Run_Next_Report_Export {
	public const ACTION_EXECUTE    = 'report.export.execute';
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
	 * Initialize report export dependencies and state.
	 *
	 * @param Export_Work_Repository_Port_Interface $repository Persistence boundary used by this operation.
	 * @param Read_Report_Snapshot                  $reports Reports.
	 * @param Report_Export_Writer_Port_Interface   $writer Writer.
	 * @param Private_File_Store_Port_Interface     $files Files.
	 * @param Access_Policy_Port_Interface          $policy Policy.
	 * @param Public_Id_Generator                   $ids Ids.
	 * @param Canonical_Json                        $canonical Canonical JSON encoder used for stable digests.
	 * @param callable|null                         $clock Clock.
	 * @param callable|null                         $lease_tokens Lease tokens.
	 */
	public function __construct(
		private Export_Work_Repository_Port_Interface $repository,
		private Read_Report_Snapshot $reports,
		private Report_Export_Writer_Port_Interface $writer,
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
	 * @return Run_Report_Export_Result
	 */
	public function run_once(): Run_Report_Export_Result {
		$now   = $this->now();
		$lease = (string) ( $this->lease_tokens )();
		$item  = $this->repository->claim_due( array( 'report_csv', 'report_pdf', 'report_json_package' ), $now, $lease, $this->plus_seconds( $now, self::LEASE_SECONDS ) );
		if ( null === $item ) {
			return new Run_Report_Export_Result( Run_Report_Export_Result::IDLE );
		}
		$created     = false;
		$storage_key = '';
		$temporary   = '';
		try {
			$snapshot = Report_Export_Request_Snapshot::restore( $item->snapshot_json(), $item->snapshot_hash(), $this->canonical );
			$this->assert_contract( $item, $snapshot );
			$context       = $this->authorize( $item, $snapshot );
			$manifest_json = $this->assert_current( $item, $snapshot, $context );
			$generated     = $this->writer->write( $snapshot, $manifest_json );
			$temporary     = $generated->path();
			$context       = $this->authorize( $item, $snapshot );
			$this->assert_current( $item, $snapshot, $context );

			$file_id     = $this->ids->create()->value();
			$artifact_id = 'report_pdf' === $item->artifact_kind() ? $this->ids->create()->value() : $file_id;
			$extension   = match ( $item->artifact_kind() ) {
				'report_csv' => 'csv', 'report_pdf' => 'pdf', default => 'zip' };
			$storage_key = 'generated/report/' . strtolower( $file_id ) . '.' . $extension;
			$created     = $this->files->put_quarantined( $temporary, $storage_key, $generated->sha256() );
			$finished    = $this->now();
			$expires     = $this->plus_seconds( $finished, self::RETENTION_SECONDS );
			$file        = array(
				'actor_user_id'      => $item->actor_user_id(),
				'artifact_kind'      => $item->artifact_kind(),
				'artifact_public_id' => $artifact_id,
				'byte_size'          => $generated->byte_size(),
				'created_at_utc'     => $finished,
				'expires_at_utc'     => $expires,
				'file_public_id'     => $file_id,
				'job_public_id'      => $item->job_id(),
				'mime_type'          => $generated->mime_type(),
				'original_name'      => $generated->original_name(),
				'sha256'             => $generated->sha256(),
				'storage_key'        => $storage_key,
			);
			$artifact    = array(
				'artifact_public_id' => $artifact_id,
				'audit_public_id'    => $this->ids->create()->value(),
				'correlation_id'     => $this->correlation( $item ),
				'details'            => $generated->details(),
				'outbox_public_id'   => $this->ids->create()->value(),
				'page_count'         => $generated->page_count(),
				'renderer_version'   => $generated->renderer_version(),
			);
			$committed   = $this->repository->complete_ready( $item, $file, $artifact, $finished );
			if ( ! $committed && $created ) {
				$this->files->delete( $storage_key );
			}
			return new Run_Report_Export_Result( $committed ? Run_Report_Export_Result::READY : Run_Report_Export_Result::STALE, $item->job_id() );
		} catch ( Throwable $error ) {
			if ( '' !== $storage_key && $created ) {
				try {
					$this->files->delete( $storage_key ); } catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
					// This optional step must not replace the surrounding operation outcome.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- This optional step must not replace the surrounding operation outcome.
					}
			}
			$code = $error instanceof Report_Export_Failure ? $error->reason() : ( $error instanceof InvalidArgumentException ? Report_Export_Failure::STORED_DATA_INVALID : Report_Export_Failure::STORAGE_UNAVAILABLE );
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
			return new Run_Report_Export_Result( $failed ? Run_Report_Export_Result::FAILED : Run_Report_Export_Result::STALE, $item->job_id() );
		} finally {
			if ( '' !== $temporary && is_file( $temporary ) ) {
				@unlink( $temporary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort removal of an owned temporary path must not mask the primary outcome. Private local streams and owned temporary paths require native filesystem operations, not WordPress FTP transport.
			} // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning. Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
		}
	}

	/**
	 * Reject invalid contract.
	 *
	 * @param Export_Work_Item               $item Item.
	 * @param Report_Export_Request_Snapshot $snapshot Snapshot.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_contract( Export_Work_Item $item, Report_Export_Request_Snapshot $snapshot ): void {
		$context = $snapshot->context();
		if (
			'report_snapshot' !== $item->source_type() || Report_Export_Request_Snapshot::VERSION !== $item->request_contract_version()
			|| Report_Export_Request_Snapshot::SCHEMA !== $item->snapshot_schema() || Report_Export_Request_Snapshot::DATABASE_VERSION !== $item->snapshot_version()
			|| ! hash_equals( $snapshot->hash(), $item->request_hash() ) || ! hash_equals( $snapshot->source_id(), $item->source_id() )
			|| ! hash_equals( $snapshot->source_hash(), $item->source_hash() ) || ! hash_equals( $snapshot->artifact_kind(), $item->artifact_kind() )
			|| ! hash_equals( $snapshot->profile(), $item->profile_version() )
			|| ( $context['type'] ) !== $item->context_type() || ( $context['id'] ) !== $item->context_id()
			|| ( $context['revision'] ) !== $item->context_revision()
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Export_Failure::because( Report_Export_Failure::STORED_DATA_INVALID );
		}
	}

	/**
	 * Authorize.
	 *
	 * @param Export_Work_Item               $item Item.
	 * @param Report_Export_Request_Snapshot $snapshot Snapshot.
	 * @return Context_Authorization_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function authorize( Export_Work_Item $item, Report_Export_Request_Snapshot $snapshot ): Context_Authorization_Result {
		try {
			$decision = $this->policy->authorize(
				new Access_Policy_Request(
					$item->actor_user_id(),
					Access_Policy_Request::SHELL_CONSOLE,
					Access_Policy_Request::SURFACE_JOB,
					self::ACTION_EXECUTE,
					array( Addon_Capabilities::EXPORT_REPORTS ),
					new Context_Scope( $item->context_type(), $item->context_id(), Context_Scope::OPERATION_REPORT, null, $item->context_revision() ),
					new Object_Scope( Object_Scope::TYPE_REPORT, $item->source_id(), 'view', $item->source_hash(), $snapshot->source_state() ),
					true,
					$this->correlation( $item )
				)
			);
			$context  = $decision->context();
			$object   = $decision->object();
			if (
				! $decision->is_allowed() || ! $context instanceof Context_Authorization_Result || ! $context->is_allowed()
				|| ! $object instanceof Object_Authorization_Result || ! $object->is_allowed()
				|| $context->type() !== $item->context_type() || $context->id() !== $item->context_id() || $context->revision() !== $item->context_revision()
				|| ! hash_equals( (string) $context->actor_relation(), (string) $object->actor_relation() )
				|| ! hash_equals( $item->source_hash(), (string) $object->current_revision() ) || $snapshot->source_state() !== $object->current_state()
			) {
				throw Report_Export_Failure::because( Report_Export_Failure::SOURCE_ACCESS_DENIED );
			}
			return $context;
		} catch ( Report_Export_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Export_Failure::because( Report_Export_Failure::SOURCE_ACCESS_DENIED );
		}
	}

	/**
	 * Reject invalid current.
	 *
	 * @param Export_Work_Item               $item Item.
	 * @param Report_Export_Request_Snapshot $snapshot Snapshot.
	 * @param Context_Authorization_Result   $context Access or execution context for this operation.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_current( Export_Work_Item $item, Report_Export_Request_Snapshot $snapshot, Context_Authorization_Result $context ): string {
		try {
			$result = $this->reports->read_source_manifest( $item->actor_user_id(), $item->source_id(), $context ); } catch ( Report_Snapshot_Query_Failure $failure ) {
			$reason = match ( $failure->reason() ) {
				Report_Snapshot_Query_Failure::SOURCE_ACCESS_DENIED => Report_Export_Failure::SOURCE_ACCESS_DENIED,
				Report_Snapshot_Query_Failure::PROFILE_UNSUPPORTED => Report_Export_Failure::PROFILE_UNSUPPORTED,
				Report_Snapshot_Query_Failure::STORED_DATA_INVALID => Report_Export_Failure::STORED_DATA_INVALID,
				Report_Snapshot_Query_Failure::NOT_FOUND => Report_Export_Failure::SOURCE_STALE,
				default => Report_Export_Failure::STORAGE_UNAVAILABLE,
			};
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Report_Export_Failure::because( $reason );
			}
			$payload        = $result->payload();
			$stored_context = $payload['context'] ?? null;
			$value          = $snapshot->value();
			$source_context = $snapshot->projection()['context'] ?? null;
			if (
			! is_array( $stored_context ) || ! is_array( $source_context ) || ! hash_equals( $snapshot->source_hash(), (string) ( $payload['snapshot_hash'] ?? '' ) )
			|| ! hash_equals( $snapshot->filter_hash(), (string) ( $payload['filter_hash'] ?? '' ) )
			|| ! hash_equals( $snapshot->source_manifest_hash(), (string) ( $payload['source_manifest_hash'] ?? '' ) )
			|| ! hash_equals( $snapshot->projection_hash(), (string) ( $payload['projection_hash'] ?? '' ) )
			|| $snapshot->row_count() !== ( $payload['entry_count'] ?? null ) || $value['source']['generated_at_utc'] !== ( $payload['generated_at_utc'] ?? null )
			|| ( $source_context['type'] ?? null ) !== ( $stored_context['type'] ?? null ) || ( $source_context['id'] ?? null ) !== ( $stored_context['id'] ?? null )
			|| ( $source_context['revision'] ?? null ) !== ( $stored_context['snapshot_revision'] ?? null )
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Report_Export_Failure::because( Report_Export_Failure::SOURCE_STALE );
			}
			return $result->source_manifest_json();
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
			throw Report_Export_Failure::because( Report_Export_Failure::STORAGE_UNAVAILABLE );
		} return $value;
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
			throw Report_Export_Failure::because( Report_Export_Failure::STORAGE_UNAVAILABLE );
		} return $date->modify( '+' . $seconds . ' seconds' )->format( 'Y-m-d H:i:s' );
	}
	/**
	 * Correlation.
	 *
	 * @param Export_Work_Item $item Item.
	 * @return string
	 */
	private function correlation( Export_Work_Item $item ): string {
		return 'report-export:' . substr( hash( 'sha256', $item->job_id() . '|' . $item->lease_token() ), 0, 32 );
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
