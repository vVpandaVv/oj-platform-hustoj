<?php
/**
 * ValidateWorksheetRevision use case.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Application;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Canonicalizer;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Id_Rewriter;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Validation_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Preview_Projector;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Projection_Safety_Inspector;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Asset_Readiness_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Repository_Port_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ValidateWorksheetRevision use case.
 */
final class Validate_Worksheet_Revision {
	public const PROJECTION_SCHEMA  = 'oj_ai.worksheet_validation_projection';
	public const PROJECTION_VERSION = '1.0.0';

	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize worksheet revision dependencies and state.
	 *
	 * @param Worksheet_Repository_Port_Interface        $worksheets Worksheets.
	 * @param Worksheet_Document_Canonicalizer           $documents Documents.
	 * @param Worksheet_Document_Id_Rewriter             $document_ids Document ids.
	 * @param Worksheet_Asset_Readiness_Port_Interface   $asset_readiness Asset readiness.
	 * @param Canonical_Json                             $canonical Canonical JSON encoder used for stable digests.
	 * @param callable|null                              $clock Clock.
	 * @param Worksheet_Preview_Projector|null           $previews Previews.
	 * @param Worksheet_Projection_Safety_Inspector|null $projection_safety Projection safety.
	 */
	public function __construct(
		private Worksheet_Repository_Port_Interface $worksheets,
		private Worksheet_Document_Canonicalizer $documents,
		private Worksheet_Document_Id_Rewriter $document_ids,
		private Worksheet_Asset_Readiness_Port_Interface $asset_readiness,
		private Canonical_Json $canonical,
		?callable $clock = null,
		private ?Worksheet_Preview_Projector $previews = null,
		private ?Worksheet_Projection_Safety_Inspector $projection_safety = null
	) {
		$this->clock = null === $clock
			? static fn (): string => gmdate( 'Y-m-d H:i:s' )
			: Closure::fromCallable( $clock );
	}

	/**
	 * Execute worksheet revision.
	 *
	 * @param Validate_Worksheet_Revision_Command $command Command.
	 * @return Validate_Worksheet_Revision_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Validate_Worksheet_Revision_Command $command ): Validate_Worksheet_Revision_Result {
		$source = $this->worksheets->find_owned_revision(
			$command->actor_user_id(),
			$command->revision_id()
		);
		if ( ! is_array( $source ) ) {
			throw Worksheet_Failure::not_found();
		}

		$revision = $source['revision'] ?? null;
		if ( ! is_array( $revision ) || array_is_list( $revision ) ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
		$revision_id    = (string) ( $revision['id'] ?? '' );
		$document_hash  = (string) ( $revision['document_hash'] ?? '' );
		$revision_state = (string) ( $revision['state'] ?? '' );
		if (
			! hash_equals( $command->revision_id(), $revision_id )
			|| ! in_array( $revision_state, array( 'draft', 'approved' ), true )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $document_hash )
		) {
			throw Worksheet_Failure::stored_data_invalid();
		}
		if (
			! hash_equals( $command->expected_revision_id(), $revision_id )
			|| ! hash_equals( $command->expected_state(), $revision_state )
		) {
			throw Worksheet_Failure::revision_conflict(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				$command->expected_revision_id(),
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				$revision_id,
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				$document_hash
			);
		}

		$teacher_document = $source['teacher_document'] ?? null;
		$worksheet_id     = (string) ( $source['worksheet_id'] ?? '' );
		if ( ! is_array( $teacher_document ) || array_is_list( $teacher_document ) ) {
			throw Worksheet_Failure::stored_data_invalid();
		}

		try {
			$document = $this->documents->canonicalize( $teacher_document );
		} catch ( Worksheet_Document_Validation_Failure | InvalidArgumentException ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
		$source_validation_hash = (string) ( $revision['validation_hash'] ?? '' );
		if (
			array() !== $document->temporary_id_map()
			|| ! hash_equals( $document_hash, $document->document_hash() )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $source_validation_hash )
			|| ! hash_equals( $source_validation_hash, $document->validation_hash() )
		) {
			throw Worksheet_Failure::stored_data_invalid();
		}

		$assets   = $this->asset_readiness->inspect(
			$command->actor_user_id(),
			$worksheet_id,
			$this->document_ids->asset_ids( $document->document() )
		);
		$blockers = $assets['blockers'];
		if ( ! \OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Preparation::ready( $document->document() ) ) {
			$blockers[] = array( 'code' => 'worksheet_preparation_required' );
		}
		$student_check           = array(
			'id'     => 'student_projection',
			'status' => 'pending',
		);
		$print_check             = array(
			'id'     => 'print_projection',
			'status' => 'pending',
		);
		$renderer_check          = array(
			'id'     => 'renderer_parity',
			'status' => 'pending',
		);
		$private_check           = array(
			'id'     => 'private_field_scan',
			'status' => 'pending',
		);
		$safety_statistics       = array();
		$projected_preview_count = 0;
		if ( $this->previews instanceof Worksheet_Preview_Projector ) {
			try {
				$student_preview         = $this->previews->student( $document );
				$print_preview           = $this->previews->print( $document, 'student_blank' );
				$student_check           = array(
					'id'              => 'student_projection',
					'status'          => 'passed',
					'projection_hash' => $student_preview->projection_hash(),
				);
				$print_check             = array(
					'id'              => 'print_projection',
					'status'          => 'passed',
					'projection_hash' => $print_preview->projection_hash(),
				);
				$projected_preview_count = 2;
				if ( $this->projection_safety instanceof Worksheet_Projection_Safety_Inspector ) {
					$inspection        = $this->projection_safety->inspect( $document, $student_preview, $print_preview );
					$renderer_check    = array_merge(
						array( 'id' => 'renderer_parity' ),
						$inspection['renderer_parity']
					);
					$private_check     = array_merge(
						array( 'id' => 'private_field_scan' ),
						$inspection['private_field_scan']
					);
					$blockers          = array_merge( $blockers, $inspection['blockers'] );
					$safety_statistics = $inspection['statistics'];
				}
			} catch ( InvalidArgumentException ) {
				$student_check['status'] = 'blocked';
				$print_check['status']   = 'blocked';
				if ( $this->projection_safety instanceof Worksheet_Projection_Safety_Inspector ) {
					$renderer_check['status'] = 'blocked';
					$private_check['status']  = 'blocked';
				}
				$blockers[] = array( 'code' => 'worksheet_projection_invalid' );
			}
		}
		$checks            = array(
			array(
				'id'     => 'document_profile',
				'status' => 'passed',
			),
			array(
				'id'     => 'canonical_hash',
				'status' => 'passed',
			),
			array(
				'id'     => 'validation_receipt',
				'status' => 'passed',
			),
			array(
				'id'     => 'asset_readiness',
				'status' => $assets['ready'] ? 'passed' : 'blocked',
			),
			$student_check,
			$print_check,
			$renderer_check,
			$private_check,
		);
		$all_checks_passed = array_reduce(
			$checks,
			static fn ( bool $passed, array $check ): bool => $passed && 'passed' === ( $check['status'] ?? '' ),
			true
		);
		$release_ready     = array() === $blockers && $all_checks_passed;
		$status            = $release_ready ? 'ready' : ( array() === $blockers ? 'pending' : 'blocked' );
		$warnings          = array_map(
			static fn ( array $warning ): array => array( 'code' => (string) ( $warning['code'] ?? '' ) ),
			$document->warnings()
		);
		$statistics        = array_merge(
			$document->statistics(),
			$assets['statistics'],
			array( 'preview_projection_count' => $projected_preview_count ),
			$safety_statistics
		);
		$projection        = array(
			'schema_id'      => self::PROJECTION_SCHEMA,
			'schema_version' => self::PROJECTION_VERSION,
			'source'         => array(
				'document_hash'   => $document_hash,
				'revision_id'     => $revision_id,
				'validation_hash' => $source_validation_hash,
				'worksheet_id'    => $worksheet_id,
			),
			'status'         => $status,
			'release_ready'  => $release_ready,
			'checks'         => $checks,
			'blockers'       => $blockers,
			'warnings'       => $warnings,
			'statistics'     => $statistics,
		);
		$validated_at      = ( $this->clock )();

		return new Validate_Worksheet_Revision_Result(
			$worksheet_id,
			$revision_id,
			$revision_state,
			$document_hash,
			$source_validation_hash,
			$status,
			$release_ready,
			$checks,
			$blockers,
			$warnings,
			$statistics,
			hash( 'sha256', $this->canonical->encode( $projection ) ),
			$validated_at
		);
	}
}
