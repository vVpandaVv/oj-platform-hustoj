<?php
/**
 * C20 submission ZIP confirmation use case.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\ZipImport\Application;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Submission\ZipImport\Port\Submission_Zip_Finalization_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs each mapped item in a separate transaction and then seals one root receipt.
 */
final class Confirm_Submission_Zip {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize confirm submission zip dependencies and state.
	 *
	 * @param Submission_Zip_Finalization_Repository_Port_Interface $repository Persistence boundary used by this operation.
	 * @param Public_Id_Generator                                   $ids Ids.
	 * @param callable|null                                         $clock Clock.
	 */
	public function __construct(
		private Submission_Zip_Finalization_Repository_Port_Interface $repository,
		private Public_Id_Generator $ids,
		?callable $clock = null
	) {
		$this->clock = null === $clock ? static fn (): string => gmdate( 'Y-m-d H:i:s' ) : Closure::fromCallable( $clock );
	}

	/**
	 * Execute confirm submission zip.
	 *
	 * @param Confirm_Submission_Zip_Command $command Command.
	 * @return Confirm_Submission_Zip_Result
	 */
	public function execute( Confirm_Submission_Zip_Command $command ): Confirm_Submission_Zip_Result {
		$receipt = $this->repository->find_confirmation_receipt( $command->idempotency_storage_key(), $command->request_digest() );
		if ( $receipt instanceof Confirm_Submission_Zip_Result ) {
			return $receipt;
		}
		$occurred_at  = $this->now();
		$base         = $this->base_record( $command, $occurred_at );
		$item_numbers = $this->repository->begin_confirmation( $base );
		if ( $item_numbers instanceof Confirm_Submission_Zip_Result ) {
			return $item_numbers;
		}

		foreach ( $item_numbers as $item_no ) {
			$this->repository->confirm_item(
				$base + array(
					'attachment_public_id'  => $this->ids->create()->value(),
					'item_audit_public_id'  => $this->ids->create()->value(),
					'item_idempotency_key'  => 'submission-zip-item:' . hash( 'sha256', $command->idempotency_storage_key() . '|item|' . $item_no ),
					'item_no'               => $item_no,
					'item_outbox_public_id' => $this->ids->create()->value(),
					'receipt_public_id'     => $this->ids->create()->value(),
					'revision_public_id'    => $this->ids->create()->value(),
					'submission_public_id'  => $this->ids->create()->value(),
				)
			);
		}

		return $this->repository->complete_confirmation(
			$base + array(
				'audit_public_id'  => $this->ids->create()->value(),
				'outbox_public_id' => $this->ids->create()->value(),
			)
		);
	}

	/**
	 * Base record.
	 *
	 * @param Confirm_Submission_Zip_Command $command Command.
	 * @param string                         $occurred_at Occurred at.
	 * @return array<string,mixed>
	 */
	private function base_record( Confirm_Submission_Zip_Command $command, string $occurred_at ): array {
		return array(
			'actor_user_id'               => $command->actor_user_id(),
			'authorized_context_id'       => $command->authorized_context_id(),
			'authorized_context_revision' => $command->authorized_context_revision(),
			'authorized_context_type'     => $command->authorized_context_type(),
			'command_id'                  => $command->command_id(),
			'correlation_id'              => $command->correlation_id(),
			'expected_mapping_version'    => $command->expected_mapping_version(),
			'idempotency_storage_key'     => $command->idempotency_storage_key(),
			'lease_outbox_public_id'      => $this->ids->create()->value(),
			'occurred_at_utc'             => $occurred_at,
			'reason'                      => $command->reason(),
			'request_digest'              => $command->request_digest(),
			'upload_id'                   => $command->upload_id(),
		);
	}

	/**
	 * Now.
	 *
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function now(): string {
		$value = ( $this->clock )();
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Submission ZIP confirmation clock returned an invalid UTC value.' );
		}
		return $value;
	}
}
