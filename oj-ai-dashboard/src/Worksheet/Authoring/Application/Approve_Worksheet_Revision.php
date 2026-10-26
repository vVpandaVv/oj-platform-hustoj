<?php
/**
 * ApproveWorksheetRevision use case.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Application;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Approval_Decision;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Approval_Policy;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Approval_Policy_Gate;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Id_Rewriter;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Approval_Evidence_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Approval_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Worksheet_Evaluation_Failure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ApproveWorksheetRevision use case.
 */
final class Approve_Worksheet_Revision {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize worksheet revision dependencies and state.
	 *
	 * @param Worksheet_Repository_Port_Interface          $worksheets Worksheets.
	 * @param Worksheet_Approval_Repository_Port_Interface $approvals Approvals.
	 * @param Validate_Worksheet_Revision                  $validator Validator.
	 * @param Worksheet_Approval_Evidence_Port_Interface   $evidence Evidence.
	 * @param Worksheet_Approval_Policy_Gate               $gate Gate.
	 * @param Worksheet_Document_Id_Rewriter               $document_ids Document ids.
	 * @param Public_Id_Generator                          $ids Ids.
	 * @param Canonical_Json                               $canonical Canonical JSON encoder used for stable digests.
	 * @param callable|null                                $clock Clock.
	 */
	public function __construct(
		private Worksheet_Repository_Port_Interface $worksheets,
		private Worksheet_Approval_Repository_Port_Interface $approvals,
		private Validate_Worksheet_Revision $validator,
		private Worksheet_Approval_Evidence_Port_Interface $evidence,
		private Worksheet_Approval_Policy_Gate $gate,
		private Worksheet_Document_Id_Rewriter $document_ids,
		private Public_Id_Generator $ids,
		private Canonical_Json $canonical,
		?callable $clock = null
	) {
		$this->clock = null === $clock
			? static fn (): string => gmdate( 'Y-m-d H:i:s' )
			: Closure::fromCallable( $clock );
	}

	/**
	 * Readiness.
	 *
	 * @param int                                $actor_user_id Authenticated actor from the access context.
	 * @param Validate_Worksheet_Revision_Result $validation Validation.
	 * @return Worksheet_Approval_Decision
	 */
	public function readiness(
		int $actor_user_id,
		Validate_Worksheet_Revision_Result $validation
	): Worksheet_Approval_Decision {
		$snapshot = $this->approval_snapshot(
			$actor_user_id,
			$validation
		);
		return $snapshot['decision'];
	}

	/**
	 * Execute worksheet revision.
	 *
	 * @param Approve_Worksheet_Revision_Command $command Command.
	 * @return Approve_Worksheet_Revision_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Approve_Worksheet_Revision_Command $command ): Approve_Worksheet_Revision_Result {
		$receipt = $this->approvals->find_command_receipt(
			$command->actor_user_id(),
			$command->idempotency_storage_key(),
			$command->request_digest()
		);
		if ( $receipt instanceof Approve_Worksheet_Revision_Result ) {
			return $receipt;
		}

		$source = $this->worksheets->find_owned_revision(
			$command->actor_user_id(),
			$command->revision_id()
		);
		if ( ! is_array( $source ) ) {
			throw Worksheet_Failure::not_found();
		}
		$revision         = $source['revision'] ?? null;
		$teacher_document = $source['teacher_document'] ?? null;
		$worksheet_id     = (string) ( $source['worksheet_id'] ?? '' );
		if (
			! is_array( $revision )
			|| array_is_list( $revision )
			|| ! is_array( $teacher_document )
			|| array_is_list( $teacher_document )
		) {
			throw Worksheet_Failure::stored_data_invalid();
		}

		$validation              = $this->validator->execute(
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
		$source_evidence_changed =
			! hash_equals( $command->expected_document_hash(), $validation->document_hash() )
			|| ! hash_equals(
				$command->expected_validation_projection_hash(),
				$validation->projection_hash()
			);

		$snapshot = $this->approval_snapshot( $command->actor_user_id(), $validation );
		$evidence = $snapshot['evidence'];
		$policy   = $snapshot['policy'];
		$decision = $snapshot['decision'];

		if (
			$source_evidence_changed
			|| ! hash_equals( $command->expected_approval_decision_hash(), $decision->decision_hash() )
		) {
			throw Worksheet_Failure::approval_conflict(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				$validation->document_hash(),
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				$validation->projection_hash(),
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				$decision->decision_hash()
			);
		}
		if ( ! $decision->approval_ready() ) {
			$blockers = $decision->blockers();
			$first    = (string) ( $blockers[0]['code'] ?? 'worksheet_approval_not_ready' );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Failure::approval_blocked( $decision->decision_hash(), $first );
		}

		$approved_at = ( $this->clock )();
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $approved_at ) ) {
			throw new InvalidArgumentException( 'ApproveWorksheetRevision clock returned an invalid UTC value.' );
		}
		$asset_ids = $this->document_ids->asset_ids( $teacher_document );
		sort( $asset_ids, SORT_STRING );

		return $this->approvals->approve_revision(
			array(
				'actor_user_id'              => $command->actor_user_id(),
				'command_id'                 => $command->command_id(),
				'correlation_id'             => $command->correlation_id(),
				'approved_at_utc'            => $approved_at,
				'idempotency_storage_key'    => $command->idempotency_storage_key(),
				'request_digest'             => $command->request_digest(),
				'worksheet_public_id'        => $worksheet_id,
				'revision_public_id'         => $command->revision_id(),
				'expected_state'             => $command->expected_state(),
				'expected_document_hash'     => $validation->document_hash(),
				'source_validation_hash'     => $validation->source_validation_hash(),
				'validation_projection_hash' => $validation->projection_hash(),
				'policy_revision_public_id'  => $policy->revision_id(),
				'policy_hash'                => $policy->content_hash(),
				'evaluation_projection_hash' => $decision->evaluation_projection_hash(),
				'approval_evidence_hash'     => $decision->evidence_hash(),
				'approval_decision_hash'     => $decision->decision_hash(),
				'configuration_guard'        => $evidence['configuration_guard'] ?? array(),
				'evaluation_guard'           => $evidence['evaluation_guard'] ?? array(),
				'asset_public_ids'           => $asset_ids,
				'audit_public_id'            => $this->ids->create()->value(),
				'outbox_public_id'           => $this->ids->create()->value(),
			)
		);
	}

	/**
	 * Approval snapshot.
	 *
	 * @param int                                $actor_user_id Authenticated actor from the access context.
	 * @param Validate_Worksheet_Revision_Result $validation Validation.
	 * @return array{decision:Worksheet_Approval_Decision,evidence:array<string,mixed>,policy:Worksheet_Approval_Policy}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function approval_snapshot(
		int $actor_user_id,
		Validate_Worksheet_Revision_Result $validation
	): array {
		try {
			$evidence = $this->evidence->current(
				$actor_user_id,
				$validation->worksheet_id(),
				$validation->revision_id()
			);
			return $this->decide_evidence( $validation, $evidence );
		} catch ( Worksheet_Evaluation_Failure ) {
			throw Worksheet_Failure::approval_evidence_unavailable();
		} catch ( InvalidArgumentException ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
	}

	/**
	 * Decide evidence.
	 *
	 * @param Validate_Worksheet_Revision_Result $validation Validation.
	 * @param array<array-key,mixed>             $evidence Evidence validated before entering the policy gate.
	 * @return array{decision:Worksheet_Approval_Decision,evidence:array<string,mixed>,policy:Worksheet_Approval_Policy}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decide_evidence( Validate_Worksheet_Revision_Result $validation, array $evidence ): array {
		$policy = $evidence['policy'] ?? null;
		if ( ! $policy instanceof Worksheet_Approval_Policy ) {
			throw new InvalidArgumentException( 'Worksheet approval policy evidence is invalid.' );
		}
		$evaluation_detail = $evidence['evaluation_detail'] ?? null;
		if ( null !== $evaluation_detail && ( ! is_array( $evaluation_detail ) || array_is_list( $evaluation_detail ) ) ) {
			throw new InvalidArgumentException( 'Worksheet approval evaluation evidence is invalid.' );
		}
		return array(
			'decision' => $this->gate->decide( $validation, $policy, $evaluation_detail ),
			'evidence' => $evidence,
			'policy'   => $policy,
		);
	}
}
