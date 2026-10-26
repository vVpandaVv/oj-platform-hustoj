<?php
/**
 * Run next submission pdf.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\SubmissionPdf\Application;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use OJAI\Dashboard\Reporting\SubmissionPdf\Domain\Submission_Pdf_Request_Snapshot;
use OJAI\Dashboard\Reporting\SubmissionPdf\Domain\Submission_Pdf_Work_Item;
use OJAI\Dashboard\Reporting\SubmissionPdf\Port\Own_Submission_Pdf_Source_Query_Port_Interface;
use OJAI\Dashboard\Reporting\SubmissionPdf\Domain\Submission_Print_Html_Serializer;
use OJAI\Dashboard\Reporting\SubmissionPdf\Port\Submission_Pdf_Work_Repository_Port_Interface;
use OJAI\Dashboard\Reporting\WorksheetPdf\Port\Html_To_Pdf_Renderer_Port_Interface;
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
 * Revalidates the exact official submission and Context before rendering and persistence.
 */
final class Run_Next_Submission_Pdf {
	public const ACTION_EXECUTE    = 'submission.pdf.execute';
	public const LEASE_SECONDS     = 300;
	public const RETENTION_SECONDS = 86400;
	public const MAX_PDF_BYTES     = 104_857_600;
	public const MAX_PDF_PAGES     = 500;

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
	 * Initialize submission pdf dependencies and state.
	 *
	 * @param Submission_Pdf_Work_Repository_Port_Interface       $repository Persistence boundary used by this operation.
	 * @param Build_Submission_Print_Projection                   $projections Projections.
	 * @param Submission_Print_Html_Serializer                    $serializer Serializer.
	 * @param Html_To_Pdf_Renderer_Port_Interface                 $renderer Renderer.
	 * @param Private_File_Store_Port_Interface                   $files Files.
	 * @param Access_Policy_Port_Interface                        $policy Policy.
	 * @param Public_Id_Generator                                 $ids Ids.
	 * @param Canonical_Json                                      $canonical Canonical JSON encoder used for stable digests.
	 * @param callable|null                                       $clock Clock.
	 * @param callable|null                                       $lease_tokens Lease tokens.
	 * @param Own_Submission_Pdf_Source_Query_Port_Interface|null $own_sources Own sources.
	 */
	public function __construct(
		private Submission_Pdf_Work_Repository_Port_Interface $repository,
		private Build_Submission_Print_Projection $projections,
		private Submission_Print_Html_Serializer $serializer,
		private Html_To_Pdf_Renderer_Port_Interface $renderer,
		private Private_File_Store_Port_Interface $files,
		private Access_Policy_Port_Interface $policy,
		private Public_Id_Generator $ids,
		private Canonical_Json $canonical,
		?callable $clock = null,
		?callable $lease_tokens = null,
		private ?Own_Submission_Pdf_Source_Query_Port_Interface $own_sources = null
	) {
		$this->clock        = null === $clock
			? static fn (): string => gmdate( 'Y-m-d H:i:s' )
			: Closure::fromCallable( $clock );
		$this->lease_tokens = null === $lease_tokens
			? static fn (): string => self::new_lease_token()
			: Closure::fromCallable( $lease_tokens );
	}

	/**
	 * Run once.
	 *
	 * @return Run_Submission_Pdf_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function run_once(): Run_Submission_Pdf_Result {
		$now   = $this->now();
		$lease = (string) ( $this->lease_tokens )();
		$item  = $this->repository->claim_due( $now, $lease, $this->plus_seconds( $now, self::LEASE_SECONDS ) );
		if ( null === $item ) {
			return new Run_Submission_Pdf_Result( Run_Submission_Pdf_Result::IDLE );
		}

		$created     = false;
		$storage_key = '';
		$temporary   = '';
		try {
			$snapshot = Submission_Pdf_Request_Snapshot::restore(
				$item->snapshot_json(),
				$item->snapshot_hash(),
				$this->canonical
			);
			$this->assert_item_matches_snapshot( $item, $snapshot );
			if ( ! $this->authorized( $item, $snapshot ) ) {
				throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::ACCESS_DENIED );
			}
			$this->assert_same_snapshot( $item, $snapshot );

			$html     = $this->serializer->serialize( $snapshot->projection(), $snapshot->source_id() );
			$rendered = $this->renderer->render( $html );
			if ( strlen( $rendered->bytes() ) > self::MAX_PDF_BYTES || $rendered->page_count() > self::MAX_PDF_PAGES ) {
				throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::TOO_LARGE );
			}

			$temporary = tempnam( sys_get_temp_dir(), 'oj-ai-submission-pdf-' );
			if ( false === $temporary || strlen( $rendered->bytes() ) !== file_put_contents( $temporary, $rendered->bytes(), LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
				throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::STORAGE_UNAVAILABLE );
			}
			$sha = hash_file( 'sha256', $temporary );
			if ( ! is_string( $sha ) ) {
				throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::STORAGE_UNAVAILABLE );
			}

			if ( ! $this->authorized( $item, $snapshot ) ) {
				throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::ACCESS_DENIED );
			}
			$this->assert_same_snapshot( $item, $snapshot );

			$artifact_id = $this->ids->create()->value();
			$file_id     = $this->ids->create()->value();
			$storage_key = 'generated/pdf/submission-' . strtolower( $artifact_id ) . '.pdf';
			$created     = $this->files->put_quarantined( $temporary, $storage_key, $sha );
			if ( ! $created ) {
				throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::STORAGE_UNAVAILABLE );
			}

			$finished    = $this->now();
			$expires     = $this->plus_seconds( $finished, self::RETENTION_SECONDS );
			$submission  = $snapshot->submission();
			$correlation = $this->correlation( $item->job_id(), $item->lease_token() );
			$file        = array(
				'actor_user_id'      => $item->actor_user_id(),
				'artifact_public_id' => $artifact_id,
				'byte_size'          => strlen( $rendered->bytes() ),
				'created_at_utc'     => $finished,
				'expires_at_utc'     => $expires,
				'file_public_id'     => $file_id,
				'original_name'      => 'submission-' . strtolower( (string) $submission['id'] ) . '.pdf',
				'sha256'             => $sha,
				'storage_key'        => $storage_key,
			);
			$artifact    = array(
				'artifact_public_id'   => $artifact_id,
				'audit_public_id'      => $this->ids->create()->value(),
				'correlation_id'       => $correlation,
				'outbox_public_id'     => $this->ids->create()->value(),
				'page_count'           => $rendered->page_count(),
				'renderer_version'     => $rendered->renderer_version(),
				'submission_public_id' => (string) $submission['id'],
			);
			$committed   = $this->repository->complete_ready( $item, $file, $artifact, $finished );
			if ( ! $committed ) {
				$this->files->delete( $storage_key );
			}
			return new Run_Submission_Pdf_Result(
				$committed ? Run_Submission_Pdf_Result::READY : Run_Submission_Pdf_Result::STALE,
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
			$code = $error instanceof Submission_Pdf_Failure
				? $error->reason()
				: Submission_Pdf_Failure::RENDER_FAILED;
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
			return new Run_Submission_Pdf_Result(
				$failed ? Run_Submission_Pdf_Result::FAILED : Run_Submission_Pdf_Result::STALE,
				$item->job_id()
			);
		} finally {
			if ( '' !== $temporary && is_file( $temporary ) ) {
				@unlink( $temporary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort removal of an owned temporary path must not mask the primary outcome. Private local streams and owned temporary paths require native filesystem operations, not WordPress FTP transport.
			} // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning. Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
		}
	}

	/**
	 * Reject invalid item matches snapshot.
	 *
	 * @param Submission_Pdf_Work_Item        $item Item.
	 * @param Submission_Pdf_Request_Snapshot $snapshot Snapshot.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_item_matches_snapshot( Submission_Pdf_Work_Item $item, Submission_Pdf_Request_Snapshot $snapshot ): void {
		$context = $snapshot->context();
		if (
			! hash_equals( $snapshot->source_id(), $item->source_id() )
			|| ! hash_equals( $snapshot->source_hash(), $item->source_hash() )
			|| ! hash_equals( $snapshot->hash(), $item->request_hash() )
			|| ! hash_equals( $snapshot->profile_version(), $item->profile_version() )
			|| ! hash_equals( (string) $context['type'], $item->context_type() )
			|| ! hash_equals( (string) $context['id'], $item->context_id() )
			|| ! hash_equals( (string) $context['revision'], $item->context_revision() )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::STORED_DATA_INVALID );
		}
	}

	/**
	 * Reject invalid same snapshot.
	 *
	 * @param Submission_Pdf_Work_Item        $item Item.
	 * @param Submission_Pdf_Request_Snapshot $snapshot Snapshot.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_same_snapshot( Submission_Pdf_Work_Item $item, Submission_Pdf_Request_Snapshot $snapshot ): void {
		try {
			$fresh_source = null;
			if ( $snapshot->is_own() ) {
				if ( null === $this->own_sources ) {
					throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::ACCESS_DENIED );
				}
				$fresh_source = $this->own_sources->find_current_official( $item->actor_user_id(), (string) $snapshot->submission()['id'] );
				if ( null === $fresh_source ) {
					throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::ACCESS_DENIED );
				}
			}
			$fresh          = $this->projections->build(
				$snapshot->source_id(),
				$snapshot->source_hash(),
				(string) $snapshot->publication()['hash'],
				$snapshot->attachment_set_hash()
			);
			$timezone       = (string) ( $snapshot->value()['print']['timezone'] ?? '' );
			$fresh_snapshot = is_array( $fresh_source )
				? Submission_Pdf_Request_Snapshot::freeze_own( $fresh, $fresh_source, $timezone, $this->canonical )
				: Submission_Pdf_Request_Snapshot::freeze( $fresh, $timezone, $this->canonical );
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::SOURCE_STALE );
		}
		if ( ! hash_equals( $snapshot->json(), $fresh_snapshot->json() ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::SOURCE_STALE );
		}
	}

	/**
	 * Authorized.
	 *
	 * @param Submission_Pdf_Work_Item        $item Item.
	 * @param Submission_Pdf_Request_Snapshot $snapshot Snapshot.
	 * @return bool
	 */
	private function authorized( Submission_Pdf_Work_Item $item, Submission_Pdf_Request_Snapshot $snapshot ): bool {
		$context    = $snapshot->context();
		$submission = $snapshot->submission();
		$is_own     = $snapshot->is_own();
		if ( $is_own && ( null === $this->own_sources || ! is_array( $snapshot->ownership() ) ) ) {
			return false;
		}
		$expected_relation = $is_own ? 'self_submission' : 'context_staff';
		try {
			$decision         = $this->policy->authorize(
				new Access_Policy_Request(
					$item->actor_user_id(),
					$is_own ? Access_Policy_Request::SHELL_PUBLIC : Access_Policy_Request::SHELL_CONSOLE,
					Access_Policy_Request::SURFACE_JOB,
					self::ACTION_EXECUTE,
					array( $is_own ? Addon_Capabilities::PARTICIPATE : Addon_Capabilities::EXPORT_REPORTS ),
					new Context_Scope(
						(string) $context['type'],
						(string) $context['id'],
						$is_own ? Context_Scope::OPERATION_PARTICIPATE : Context_Scope::OPERATION_REPORT,
						$is_own ? $item->actor_user_id() : null,
						(string) $context['revision']
					),
					new Object_Scope(
						Object_Scope::TYPE_SUBMISSION,
						(string) $submission['id'],
						$is_own ? 'export_pdf' : 'teacher_view',
						(string) $submission['lock_version'],
						(string) $submission['state']
					),
					true,
					$this->correlation( $item->job_id(), $item->lease_token() )
				)
			);
			$context_evidence = $decision->context();
			$object_evidence  = $decision->object();
			return $decision->is_allowed()
				&& $context_evidence instanceof Context_Authorization_Result && $context_evidence->is_allowed()
				&& hash_equals( (string) $context['type'], (string) $context_evidence->type() )
				&& hash_equals( (string) $context['id'], (string) $context_evidence->id() )
				&& hash_equals( (string) $context['revision'], (string) $context_evidence->revision() )
				&& $object_evidence instanceof Object_Authorization_Result && $object_evidence->is_allowed()
				&& $expected_relation === $object_evidence->actor_relation()
				&& hash_equals( (string) $submission['lock_version'], (string) $object_evidence->current_revision() )
				&& hash_equals( (string) $submission['state'], (string) $object_evidence->current_state() );
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
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::STORAGE_UNAVAILABLE );
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
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::STORAGE_UNAVAILABLE );
		}
		return $date->modify( '+' . $seconds . ' seconds' )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Correlation.
	 *
	 * @param string $job Job.
	 * @param string $lease Lease.
	 * @return string
	 */
	private function correlation( string $job, string $lease ): string {
		return 'submission-pdf:' . substr( hash( 'sha256', $job . '|' . $lease ), 0, 32 );
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
