<?php
/**
 * Run next worksheet package.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\WorksheetPackage\Application;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use OJAI\Dashboard\Reporting\WorksheetPackage\Domain\Worksheet_Package_Request_Snapshot;
use OJAI\Dashboard\Reporting\WorksheetPackage\Port\Worksheet_Package_Work_Repository_Port_Interface;
use OJAI\Dashboard\Reporting\WorksheetPackage\Port\Worksheet_Package_Writer_Port_Interface;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
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
 * Run next worksheet package.
 */
final class Run_Next_Worksheet_Package {
	public const ACTION_EXECUTE    = 'worksheet.package.execute';
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
	 * Initialize worksheet package dependencies and state.
	 *
	 * @param Worksheet_Package_Work_Repository_Port_Interface $repository Persistence boundary used by this operation.
	 * @param Build_Worksheet_Package_Source                   $sources Sources.
	 * @param Worksheet_Package_Writer_Port_Interface          $writer Writer.
	 * @param Private_File_Store_Port_Interface                $files Files.
	 * @param Access_Policy_Port_Interface                     $policy Policy.
	 * @param Public_Id_Generator                              $ids Ids.
	 * @param Canonical_Json                                   $canonical Canonical JSON encoder used for stable digests.
	 * @param callable|null                                    $clock Clock.
	 * @param callable|null                                    $lease_tokens Lease tokens.
	 */
	public function __construct(
		private Worksheet_Package_Work_Repository_Port_Interface $repository,
		private Build_Worksheet_Package_Source $sources,
		private Worksheet_Package_Writer_Port_Interface $writer,
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
	 * @return Run_Worksheet_Package_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function run_once(): Run_Worksheet_Package_Result {
		$now   = $this->now();
		$lease = (string) ( $this->lease_tokens )();
		$item  = $this->repository->claim_due( $now, $lease, $this->plus_seconds( $now, self::LEASE_SECONDS ) );
		if ( null === $item ) {
			return new Run_Worksheet_Package_Result( Run_Worksheet_Package_Result::IDLE );
		}
		$created     = false;
		$storage_key = '';
		$temporary   = '';
		try {
			$snapshot = Worksheet_Package_Request_Snapshot::restore( $item->snapshot_json(), $item->snapshot_hash(), $this->canonical );
			if ( ! hash_equals( $snapshot->source_id(), $item->source_id() ) || ! hash_equals( $snapshot->source_hash(), $item->source_hash() )
				|| ! hash_equals( Worksheet_Package_Request_Snapshot::PROFILE_VERSION, $item->profile_version() )
				|| ! $this->authorized( $item->actor_user_id(), $item->source_id(), $snapshot->source_state(), $item->job_id(), $item->lease_token() ) ) {
				throw Worksheet_Package_Failure::because( Worksheet_Package_Failure::ACCESS_DENIED );
			}
			$source = $this->sources->build( $item->actor_user_id(), $item->source_id(), $item->source_hash(), $snapshot->source_state() );
			$this->assert_same_snapshot( $snapshot, $source->snapshot() );
			$archive   = $this->writer->write( $source );
			$temporary = $archive->path();
			if ( ! $this->authorized( $item->actor_user_id(), $item->source_id(), $snapshot->source_state(), $item->job_id(), $item->lease_token() ) ) {
				throw Worksheet_Package_Failure::because( Worksheet_Package_Failure::ACCESS_DENIED );
			}
			$fresh = $this->sources->build( $item->actor_user_id(), $item->source_id(), $item->source_hash(), $snapshot->source_state() );
			$this->assert_same_snapshot( $snapshot, $fresh->snapshot() );
			$file_id     = $this->ids->create()->value();
			$storage_key = 'generated/package/' . strtolower( $file_id ) . '.ojws';
			$created     = $this->files->put_quarantined( $temporary, $storage_key, $archive->sha256() );
			$finished    = $this->now();
			$expires     = $this->plus_seconds( $finished, self::RETENTION_SECONDS );
			$file        = array(
				'actor_user_id'  => $item->actor_user_id(),
				'byte_size'      => $archive->byte_size(),
				'created_at_utc' => $finished,
				'expires_at_utc' => $expires,
				'file_public_id' => $file_id,
				'job_public_id'  => $item->job_id(),
				'original_name'  => 'worksheet-' . strtolower( $item->source_id() ) . '.ojws',
				'sha256'         => $archive->sha256(),
				'storage_key'    => $storage_key,
			);
			$event       = array(
				'audit_public_id'  => $this->ids->create()->value(),
				'correlation_id'   => $this->correlation( $item->job_id(), $item->lease_token() ),
				'exporter_version' => $archive->exporter_version(),
				'outbox_public_id' => $this->ids->create()->value(),
			);
			$committed   = $this->repository->complete_ready( $item, $file, $event, $finished );
			if ( ! $committed && $created ) {
				$this->files->delete( $storage_key );
			}
			return new Run_Worksheet_Package_Result( $committed ? Run_Worksheet_Package_Result::READY : Run_Worksheet_Package_Result::STALE, $item->job_id() );
		} catch ( Throwable $error ) {
			if ( '' !== $storage_key && $created ) {
				try {
					$this->files->delete( $storage_key ); } catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
					// This optional step must not replace the surrounding operation outcome.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- This optional step must not replace the surrounding operation outcome.
					}
			}
			$code = $error instanceof Worksheet_Package_Failure ? $error->reason() : Worksheet_Package_Failure::ARCHIVE_FAILED;
			try {
				$failed = $this->repository->complete_failure(
					$item,
					$code,
					$this->ids->create()->value(),
					$this->ids->create()->value(),
					$this->correlation( $item->job_id(), $item->lease_token() ),
					$this->now()
				);
			} catch ( Throwable ) {
				$failed = false;
			}
			return new Run_Worksheet_Package_Result( $failed ? Run_Worksheet_Package_Result::FAILED : Run_Worksheet_Package_Result::STALE, $item->job_id() );
		} finally {
			if ( '' !== $temporary && is_file( $temporary ) ) {
				@unlink( $temporary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort removal of an owned temporary path must not mask the primary outcome. Private local streams and owned temporary paths require native filesystem operations, not WordPress FTP transport.
			} // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning. Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
		}
	}

	/**
	 * Reject invalid same snapshot.
	 *
	 * @param Worksheet_Package_Request_Snapshot $expected Expected.
	 * @param Worksheet_Package_Request_Snapshot $actual Actual.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_same_snapshot( Worksheet_Package_Request_Snapshot $expected, Worksheet_Package_Request_Snapshot $actual ): void {
		if ( ! hash_equals( $expected->hash(), $actual->hash() ) || ! hash_equals( $expected->json(), $actual->json() ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Package_Failure::because( Worksheet_Package_Failure::SOURCE_STALE );
		}
	}

	/**
	 * Authorized.
	 *
	 * @param int    $actor Actor.
	 * @param string $revision Revision.
	 * @param string $state State.
	 * @param string $job Job.
	 * @param string $lease Lease.
	 * @return bool
	 */
	private function authorized( int $actor, string $revision, string $state, string $job, string $lease ): bool {
		try {
			$decision = $this->policy->authorize(
				new Access_Policy_Request(
					$actor,
					Access_Policy_Request::SHELL_CONSOLE,
					Access_Policy_Request::SURFACE_JOB,
					self::ACTION_EXECUTE,
					array( Addon_Capabilities::EXPORT_REPORTS ),
					null,
					new Object_Scope( Object_Scope::TYPE_WORKSHEET_REVISION, $revision, 'view', $revision, $state ),
					true,
					$this->correlation( $job, $lease )
				)
			);
			return $decision->is_allowed() && null !== $decision->object() && 'owner' === $decision->object()->actor_relation();
		} catch ( Throwable ) {
			return false;
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
			throw Worksheet_Package_Failure::because( Worksheet_Package_Failure::STORAGE_UNAVAILABLE );
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
			throw Worksheet_Package_Failure::because( Worksheet_Package_Failure::STORAGE_UNAVAILABLE );
		} return $date->modify( '+' . $seconds . ' seconds' )->format( 'Y-m-d H:i:s' );
	}
	/**
	 * Correlation.
	 *
	 * @param string $job Job.
	 * @param string $lease Lease.
	 * @return string
	 */
	private function correlation( string $job, string $lease ): string {
		return 'package:' . substr( hash( 'sha256', $job . '|' . $lease ), 0, 32 );
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
