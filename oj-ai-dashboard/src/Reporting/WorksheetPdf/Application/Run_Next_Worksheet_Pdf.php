<?php
/**
 * Run next worksheet pdf.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\WorksheetPdf\Application;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use OJAI\Dashboard\Reporting\WorksheetPdf\Domain\Worksheet_Pdf_Request_Snapshot;
use OJAI\Dashboard\Reporting\WorksheetPdf\Domain\Worksheet_Pdf_Work_Item;
use OJAI\Dashboard\Reporting\WorksheetPdf\Domain\Worksheet_Print_Html_Serializer;
use OJAI\Dashboard\Reporting\WorksheetPdf\Port\Html_To_Pdf_Renderer_Port_Interface;
use OJAI\Dashboard\Reporting\WorksheetPdf\Port\Worksheet_Pdf_Work_Repository_Port_Interface;
use OJAI\Dashboard\Reporting\WorksheetPdf\Port\Own_Worksheet_Pdf_Source_Query_Port_Interface;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Context_Scope;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use OJAI\Dashboard\Storage\Port\Private_File_Store_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Application\Build_Worksheet_Print_Projection;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run next worksheet pdf.
 */
final class Run_Next_Worksheet_Pdf {
	public const ACTION_EXECUTE    = 'worksheet.pdf.execute';
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
	 * Initialize worksheet pdf dependencies and state.
	 *
	 * @param Worksheet_Pdf_Work_Repository_Port_Interface       $repository Persistence boundary used by this operation.
	 * @param Build_Worksheet_Print_Projection                   $projections Projections.
	 * @param Worksheet_Print_Html_Serializer                    $serializer Serializer.
	 * @param Html_To_Pdf_Renderer_Port_Interface                $renderer Renderer.
	 * @param Private_File_Store_Port_Interface                  $files Files.
	 * @param Access_Policy_Port_Interface                       $policy Policy.
	 * @param Public_Id_Generator                                $ids Ids.
	 * @param Canonical_Json                                     $canonical Canonical JSON encoder used for stable digests.
	 * @param callable|null                                      $clock Clock.
	 * @param callable|null                                      $lease_tokens Lease tokens.
	 * @param Own_Worksheet_Pdf_Source_Query_Port_Interface|null $own_sources Own sources.
	 */
	public function __construct(
		private Worksheet_Pdf_Work_Repository_Port_Interface $repository,
		private Build_Worksheet_Print_Projection $projections,
		private Worksheet_Print_Html_Serializer $serializer,
		private Html_To_Pdf_Renderer_Port_Interface $renderer,
		private Private_File_Store_Port_Interface $files,
		private Access_Policy_Port_Interface $policy,
		private Public_Id_Generator $ids,
		private Canonical_Json $canonical,
		?callable $clock = null,
		?callable $lease_tokens = null,
		private ?Own_Worksheet_Pdf_Source_Query_Port_Interface $own_sources = null
	) {
		$this->clock        = null === $clock ? static fn (): string => gmdate( 'Y-m-d H:i:s' ) : Closure::fromCallable( $clock );
		$this->lease_tokens = null === $lease_tokens ? static fn (): string => self::new_lease_token() : Closure::fromCallable( $lease_tokens );
	}

	/**
	 * Run once.
	 *
	 * @return Run_Worksheet_Pdf_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function run_once(): Run_Worksheet_Pdf_Result {
		$now   = $this->now();
		$lease = (string) ( $this->lease_tokens )();
		$item  = $this->repository->claim_due( $now, $lease, $this->plus_seconds( $now, self::LEASE_SECONDS ) );
		if ( null === $item ) {
			return new Run_Worksheet_Pdf_Result( Run_Worksheet_Pdf_Result::IDLE );
		}
		$created     = false;
		$storage_key = '';
		$temporary   = '';
		try {
			$snapshot = Worksheet_Pdf_Request_Snapshot::restore( $item->snapshot_json(), $item->snapshot_hash(), $this->canonical );
			if ( ! hash_equals( $snapshot->source_id(), $item->source_id() ) || ! hash_equals( $snapshot->source_hash(), $item->source_hash() )
				|| ! hash_equals( $snapshot->source_type(), $item->source_type() )
				|| ! hash_equals( $snapshot->profile_version(), $item->profile_version() )
				|| ! $this->context_matches_item( $snapshot->context(), $item->context() ) || ! $this->authorized( $item, $snapshot, $now ) ) {
				throw Worksheet_Pdf_Failure::because( Worksheet_Pdf_Failure::ACCESS_DENIED );
			}
			$this->assert_projection_matches( $item->actor_user_id(), $snapshot );
			$html     = $this->serializer->serialize( $snapshot->projection(), $this->document_id( $snapshot ) );
			$rendered = $this->renderer->render( $html );
			if ( strlen( $rendered->bytes() ) > self::MAX_PDF_BYTES || $rendered->page_count() > self::MAX_PDF_PAGES ) {
				throw Worksheet_Pdf_Failure::because( Worksheet_Pdf_Failure::TOO_LARGE );
			}
			if ( ! $this->authorized( $item, $snapshot, $this->now() ) ) {
				throw Worksheet_Pdf_Failure::because( Worksheet_Pdf_Failure::ACCESS_DENIED );
			}
			$this->assert_projection_matches( $item->actor_user_id(), $snapshot );
			$temporary = tempnam( sys_get_temp_dir(), 'oj-ai-pdf-' );
			if ( false === $temporary || strlen( $rendered->bytes() ) !== file_put_contents( $temporary, $rendered->bytes(), LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
				throw Worksheet_Pdf_Failure::because( Worksheet_Pdf_Failure::STORAGE_UNAVAILABLE );
			}
			$sha = hash_file( 'sha256', $temporary );
			if ( ! is_string( $sha ) ) {
				throw Worksheet_Pdf_Failure::because( Worksheet_Pdf_Failure::STORAGE_UNAVAILABLE );
			}
			$artifact_id = $this->ids->create()->value();
			$file_id     = $this->ids->create()->value();
			$storage_key = 'generated/pdf/' . strtolower( $artifact_id ) . '.pdf';
			$created     = $this->files->put_quarantined( $temporary, $storage_key, $sha );
			$finished    = $this->now();
			$expires     = $this->plus_seconds( $finished, self::RETENTION_SECONDS );
			$correlation = $this->correlation( $item->job_id(), $item->lease_token() );
			$file        = array(
				'actor_user_id'      => $item->actor_user_id(),
				'artifact_public_id' => $artifact_id,
				'byte_size'          => strlen( $rendered->bytes() ),
				'created_at_utc'     => $finished,
				'expires_at_utc'     => $expires,
				'file_public_id'     => $file_id,
				'original_name'      => 'worksheet-' . strtolower( $this->document_id( $snapshot ) ) . '.pdf',
				'sha256'             => $sha,
				'storage_key'        => $storage_key,
			);
			$artifact    = array(
				'artifact_public_id' => $artifact_id,
				'renderer_version'   => $rendered->renderer_version(),
				'page_count'         => $rendered->page_count(),
				'audit_public_id'    => $this->ids->create()->value(),
				'outbox_public_id'   => $this->ids->create()->value(),
				'correlation_id'     => $correlation,
			);
			$committed   = $this->repository->complete_ready( $item, $file, $artifact, $finished );
			if ( ! $committed && $created ) {
				$this->files->delete( $storage_key );
			}
			return new Run_Worksheet_Pdf_Result( $committed ? Run_Worksheet_Pdf_Result::READY : Run_Worksheet_Pdf_Result::STALE, $item->job_id() );
		} catch ( Throwable $error ) {
			if ( '' !== $storage_key && $created ) {
				try {
					$this->files->delete( $storage_key ); } catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
					// This optional step must not replace the surrounding operation outcome.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- This optional step must not replace the surrounding operation outcome.
					}
			}
			$code = $error instanceof Worksheet_Pdf_Failure ? $error->reason() : Worksheet_Pdf_Failure::RENDER_FAILED;
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
			return new Run_Worksheet_Pdf_Result( $failed ? Run_Worksheet_Pdf_Result::FAILED : Run_Worksheet_Pdf_Result::STALE, $item->job_id() );
		} finally {
			if ( '' !== $temporary && is_file( $temporary ) ) {
				@unlink( $temporary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort removal of an owned temporary path must not mask the primary outcome. Private local streams and owned temporary paths require native filesystem operations, not WordPress FTP transport.
			} // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning. Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
		}
	}

	/**
	 * Reject invalid projection matches.
	 *
	 * @param int                            $actor Actor.
	 * @param Worksheet_Pdf_Request_Snapshot $snapshot Snapshot.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_projection_matches( int $actor, Worksheet_Pdf_Request_Snapshot $snapshot ): void {
		try {
			$fresh = 'worksheet_publication' === $snapshot->source_type()
				? $this->projections->build_exact_publication( $snapshot->source_id(), $snapshot->source_hash(), 'student_blank' )
				: $this->projections->build( $actor, 'revision', $snapshot->source_id(), $snapshot->source_hash(), 'student_blank' );
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Pdf_Failure::because( Worksheet_Pdf_Failure::SOURCE_STALE );
		}
		$projection = $fresh['print']['projection'] ?? null;
		try {
			$matches = hash_equals( $this->canonical->encode( $snapshot->projection() ), $this->canonical->encode( $projection ) ); } catch ( Throwable ) {
			$matches = false;
			}
			if ( ! $matches ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Pdf_Failure::because( Worksheet_Pdf_Failure::SOURCE_STALE );
			}
	}

	/**
	 * Authorized.
	 *
	 * @param Worksheet_Pdf_Work_Item        $item Item.
	 * @param Worksheet_Pdf_Request_Snapshot $snapshot Snapshot.
	 * @param string                         $now Clock value supplied for deterministic scheduling.
	 * @return bool
	 */
	private function authorized( Worksheet_Pdf_Work_Item $item, Worksheet_Pdf_Request_Snapshot $snapshot, string $now ): bool {
		if ( 'worksheet_publication' === $snapshot->source_type() ) {
			return $this->authorized_own( $item, $snapshot, $now );
		}
		try {
			$decision = $this->policy->authorize(
				new Access_Policy_Request(
					$item->actor_user_id(),
					Access_Policy_Request::SHELL_CONSOLE,
					Access_Policy_Request::SURFACE_JOB,
					self::ACTION_EXECUTE,
					array( Addon_Capabilities::EXPORT_REPORTS ),
					null,
					new Object_Scope( Object_Scope::TYPE_WORKSHEET_REVISION, $snapshot->source_id(), 'view', $snapshot->source_id(), $snapshot->source_state() ),
					true,
					$this->correlation( $item->job_id(), $item->lease_token() )
				)
			);
			return $decision->is_allowed() && null !== $decision->object() && 'owner' === $decision->object()->actor_relation();
		} catch ( Throwable ) {
			return false;
		}
	}

	/**
	 * Authorized own.
	 *
	 * @param Worksheet_Pdf_Work_Item        $item Item.
	 * @param Worksheet_Pdf_Request_Snapshot $snapshot Snapshot.
	 * @param string                         $now Clock value supplied for deterministic scheduling.
	 * @return bool
	 */
	private function authorized_own( Worksheet_Pdf_Work_Item $item, Worksheet_Pdf_Request_Snapshot $snapshot, string $now ): bool {
		$assignment = $snapshot->assignment();
		$context    = $snapshot->context();
		if ( null === $this->own_sources || ! is_array( $assignment ) || ! is_array( $context ) ) {
			return false;
		}
		try {
			$current = $this->own_sources->find_available( $item->actor_user_id(), (string) $assignment['id'], $now );
			if ( null === $current || ! $this->own_source_matches( $current, $assignment, $context, $snapshot ) ) {
				return false;
			}
			$decision         = $this->policy->authorize(
				new Access_Policy_Request(
					$item->actor_user_id(),
					Access_Policy_Request::SHELL_PUBLIC,
					Access_Policy_Request::SURFACE_JOB,
					self::ACTION_EXECUTE,
					array( Addon_Capabilities::PARTICIPATE ),
					new Context_Scope( (string) $context['type'], (string) $context['id'], Context_Scope::OPERATION_PARTICIPATE, $item->actor_user_id(), (string) $context['revision'] ),
					new Object_Scope( Object_Scope::TYPE_ASSIGNMENT, (string) $assignment['id'], 'export_pdf' ),
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
				&& 'self_target' === $object_evidence->actor_relation();
		} catch ( Throwable ) {
			return false;
		}
	}

	/**
	 * Own source matches.
	 *
	 * @param array<string,mixed>            $current Current.
	 * @param array<string,mixed>            $assignment Assignment.
	 * @param array<string,string>           $context Access or execution context for this operation.
	 * @param Worksheet_Pdf_Request_Snapshot $snapshot Snapshot.
	 * @return bool
	 */
	private function own_source_matches( array $current, array $assignment, array $context, Worksheet_Pdf_Request_Snapshot $snapshot ): bool {
		return hash_equals( (string) $assignment['id'], (string) ( $current['assignment_id'] ?? '' ) )
			&& hash_equals( (string) $assignment['target_id'], (string) ( $current['target_id'] ?? '' ) )
			&& 'active' === ( $current['target_state'] ?? null )
			&& hash_equals( $snapshot->source_id(), (string) ( $current['publication_id'] ?? '' ) )
			&& hash_equals( $snapshot->source_hash(), (string) ( $current['publication_hash'] ?? '' ) )
			&& hash_equals( (string) $context['type'], (string) ( $current['context_type'] ?? '' ) )
			&& hash_equals( (string) $context['id'], (string) ( $current['context_id'] ?? '' ) )
			&& hash_equals( (string) $assignment['binding_context_revision'], (string) ( $current['binding_context_revision'] ?? '' ) )
			&& hash_equals( (string) $context['revision'], (string) ( $current['binding_context_revision'] ?? '' ) );
	}

	/**
	 * Context matches item.
	 *
	 * @param array<string,string>|null $snapshot_context Snapshot context.
	 * @param array<string,string>|null $item_context Item context.
	 * @return bool
	 */
	private function context_matches_item( ?array $snapshot_context, ?array $item_context ): bool {
		if ( null === $snapshot_context || null === $item_context ) {
			return $snapshot_context === $item_context;
		}
		return hash_equals( (string) $snapshot_context['type'], (string) $item_context['type'] )
			&& hash_equals( (string) $snapshot_context['id'], (string) $item_context['id'] )
			&& hash_equals( (string) $snapshot_context['revision'], (string) $item_context['revision'] );
	}

	/**
	 * Document id.
	 *
	 * @param Worksheet_Pdf_Request_Snapshot $snapshot Snapshot.
	 * @return string
	 */
	private function document_id( Worksheet_Pdf_Request_Snapshot $snapshot ): string {
		$assignment = $snapshot->assignment();
		return is_array( $assignment ) ? (string) $assignment['id'] : $snapshot->source_id();
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
			throw Worksheet_Pdf_Failure::because( Worksheet_Pdf_Failure::STORAGE_UNAVAILABLE );
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
			throw Worksheet_Pdf_Failure::because( Worksheet_Pdf_Failure::STORAGE_UNAVAILABLE );
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
		return 'pdf:' . substr( hash( 'sha256', $job . '|' . $lease ), 0, 32 );
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
