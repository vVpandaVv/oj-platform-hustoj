<?php
/**
 * PublishWorksheetRevision use case.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Application;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Canonicalizer;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Id_Rewriter;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Validation_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Publication_Projector;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Publication_Configuration_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Publication_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Repository_Port_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PublishWorksheetRevision use case.
 */
final class Publish_Worksheet_Revision {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize publish worksheet revision dependencies and state.
	 *
	 * @param Worksheet_Repository_Port_Interface                $worksheets Worksheets.
	 * @param Worksheet_Publication_Repository_Port_Interface    $publications Publications.
	 * @param Worksheet_Publication_Configuration_Port_Interface $configuration Configuration.
	 * @param Validate_Worksheet_Revision                        $validator Validator.
	 * @param Worksheet_Document_Canonicalizer                   $documents Documents.
	 * @param Worksheet_Publication_Projector                    $projector Projector.
	 * @param Worksheet_Document_Id_Rewriter                     $document_ids Document ids.
	 * @param Public_Id_Generator                                $ids Ids.
	 * @param Canonical_Json                                     $canonical Canonical JSON encoder used for stable digests.
	 * @param callable|null                                      $clock Clock.
	 */
	public function __construct(
		private Worksheet_Repository_Port_Interface $worksheets,
		private Worksheet_Publication_Repository_Port_Interface $publications,
		private Worksheet_Publication_Configuration_Port_Interface $configuration,
		private Validate_Worksheet_Revision $validator,
		private Worksheet_Document_Canonicalizer $documents,
		private Worksheet_Publication_Projector $projector,
		private Worksheet_Document_Id_Rewriter $document_ids,
		private Public_Id_Generator $ids,
		private Canonical_Json $canonical,
		?callable $clock = null
	) {
		$this->clock = null === $clock ? static fn (): string => gmdate( 'Y-m-d H:i:s' ) : Closure::fromCallable( $clock );
	}

	/**
	 * Readiness.
	 *
	 * @param int                                $actor_user_id Authenticated actor from the access context.
	 * @param Validate_Worksheet_Revision_Result $validation Validation.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function readiness(
		int $actor_user_id,
		Validate_Worksheet_Revision_Result $validation
	): array {
		$approval = $this->publications->find_approval_evidence(
			$actor_user_id,
			$validation->worksheet_id(),
			$validation->revision_id()
		);
		if ( ! is_array( $approval ) || array() === $approval ) {
			throw Worksheet_Failure::publication_approval_unavailable();
		}
		$this->assert_approval( $approval );
		$ready = $validation->release_ready()
			&& hash_equals( (string) $approval['document_hash'], $validation->document_hash() )
			&& hash_equals( (string) $approval['validation_projection_hash'], $validation->projection_hash() );

		return array(
			'status'         => $ready ? 'approved' : 'blocked',
			'approval_ready' => $ready,
			'decision_hash'  => $ready ? (string) $approval['approval_decision_hash'] : null,
			'evidence_hash'  => $ready ? (string) $approval['approval_evidence_hash'] : null,
			'blockers'       => $ready
				? array()
				: array( array( 'code' => 'worksheet_approval_evidence_stale' ) ),
			'warnings'       => array(),
		);
	}

	/**
	 * Execute publish worksheet revision.
	 *
	 * @param Publish_Worksheet_Revision_Command $command Command.
	 * @return Publish_Worksheet_Revision_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Publish_Worksheet_Revision_Command $command ): Publish_Worksheet_Revision_Result {
		$receipt = $this->publications->find_command_receipt(
			$command->actor_user_id(),
			$command->idempotency_storage_key(),
			$command->request_digest()
		);
		if ( $receipt instanceof Publish_Worksheet_Revision_Result ) {
			return $receipt;
		}

		$source = $this->worksheets->find_owned_revision( $command->actor_user_id(), $command->revision_id() );
		if ( ! is_array( $source ) ) {
			throw Worksheet_Failure::not_found();
		}
		$revision         = $source['revision'] ?? null;
		$teacher_document = $source['teacher_document'] ?? null;
		$worksheet_id     = $source['worksheet_id'] ?? null;
		if (
			! is_array( $revision ) || array_is_list( $revision )
			|| ! is_array( $teacher_document ) || array_is_list( $teacher_document )
			|| ! is_string( $worksheet_id )
		) {
			throw Worksheet_Failure::stored_data_invalid();
		}

		$validation = $this->validator->execute(
			new Validate_Worksheet_Revision_Command(
				$command->actor_user_id(),
				$command->revision_id(),
				$command->command_id(),
				$command->expected_state(),
				$command->expected_revision_id(),
				hash( 'sha256', $command->idempotency_storage_key() . '|validation' ),
				$command->correlation_id(),
				$this->canonical
			)
		);
		if ( ! $validation->release_ready() ) {
			$blockers = $validation->blockers();
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Failure::publication_blocked( (string) ( $blockers[0]['code'] ?? 'worksheet_validation_not_ready' ) );
		}

		$approval = $this->publications->find_approval_evidence(
			$command->actor_user_id(),
			$worksheet_id,
			$command->revision_id()
		);
		if ( ! is_array( $approval ) || array() === $approval ) {
			throw Worksheet_Failure::publication_approval_unavailable();
		}
		$this->assert_approval( $approval );
		$current_decision_hash = (string) $approval['approval_decision_hash'];
		if (
			! hash_equals( $command->expected_document_hash(), $validation->document_hash() )
			|| ! hash_equals( $command->expected_approval_decision_hash(), $current_decision_hash )
			|| ! hash_equals( (string) $approval['document_hash'], $validation->document_hash() )
			|| ! hash_equals( (string) $approval['validation_projection_hash'], $validation->projection_hash() )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Failure::publication_conflict( $validation->document_hash(), $current_decision_hash );
		}

		try {
			$document = $this->documents->canonicalize( $teacher_document );
			if (
				array() !== $document->temporary_id_map()
				|| ! hash_equals( $validation->document_hash(), $document->document_hash() )
				|| ! hash_equals( $validation->source_validation_hash(), $document->validation_hash() )
			) {
				throw new InvalidArgumentException( 'Worksheet publication source drifted.' );
			}
			$configuration = $this->configuration instanceof \OJAI\Dashboard\Worksheet\Authoring\Port\Scoped_Publication_Configuration_Port_Interface
				? $this->configuration->for_worksheet( $worksheet_id, $document->document() ) : $this->configuration->active();
			$projection    = $this->projector->project( $document, $configuration );
		} catch ( Worksheet_Failure $failure ) {
			throw $failure;
		} catch ( Worksheet_Document_Validation_Failure | InvalidArgumentException ) {
			throw Worksheet_Failure::publication_blocked( 'worksheet_publication_projection_invalid' );
		}

		$published_at = ( $this->clock )();
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $published_at ) ) {
			throw new InvalidArgumentException( 'PublishWorksheetRevision clock returned an invalid UTC value.' );
		}
		$profiles = array();
		foreach ( $projection->profiles() as $name => $profile ) {
			$json              = $this->canonical->encode( $profile );
			$profiles[ $name ] = array(
				'hash'    => hash( 'sha256', $json ),
				'json'    => $json,
				'schema'  => (string) $profile['schema_id'],
				'version' => 1,
			);
		}
		$asset_ids = $this->document_ids->asset_ids( $teacher_document );
		sort( $asset_ids, SORT_STRING );

		return $this->publications->publish_revision(
			array(
				'actor_user_id'               => $command->actor_user_id(),
				'approval_decision_hash'      => $current_decision_hash,
				'approval_evidence_hash'      => (string) $approval['approval_evidence_hash'],
				'approval_guard'              => $approval['guard'],
				'asset_public_ids'            => $asset_ids,
				'audit_public_id'             => $this->ids->create()->value(),
				'command_id'                  => $command->command_id(),
				'configuration_guard'         => $configuration['guard'] ?? array(),
				'correlation_id'              => $command->correlation_id(),
				'expected_document_hash'      => $validation->document_hash(),
				'expected_state'              => $command->expected_state(),
				'idempotency_storage_key'     => $command->idempotency_storage_key(),
				'outbox_public_id'            => $this->ids->create()->value(),
				'profiles'                    => $profiles,
				'projection_manifest'         => $projection->manifest(),
				'projection_manifest_version' => $projection->manifest_version(),
				'publication_hash'            => $projection->publication_hash(),
				'publication_public_id'       => $this->ids->create()->value(),
				'published_at_utc'            => $published_at,
				'request_digest'              => $command->request_digest(),
				'revision_public_id'          => $command->revision_id(),
				'source_validation_hash'      => $validation->source_validation_hash(),
				'validation_projection_hash'  => $validation->projection_hash(),
				'worksheet_public_id'         => $worksheet_id,
			)
		);
	}

	/**
	 * Reject invalid approval.
	 *
	 * @param array<string,mixed> $approval Approval.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_approval( array $approval ): void {
		$expected = array( 'approval_decision_hash', 'approval_evidence_hash', 'document_hash', 'guard', 'validation_projection_hash' );
		$actual   = array_keys( $approval );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected || ! is_array( $approval['guard'] ) || array_is_list( $approval['guard'] ) ) {
			throw Worksheet_Failure::publication_approval_unavailable();
		}
		foreach ( array( 'approval_decision_hash', 'approval_evidence_hash', 'document_hash', 'validation_projection_hash' ) as $field ) {
			if ( ! is_string( $approval[ $field ] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $approval[ $field ] ) ) {
				throw Worksheet_Failure::publication_approval_unavailable();
			}
		}
	}
}
