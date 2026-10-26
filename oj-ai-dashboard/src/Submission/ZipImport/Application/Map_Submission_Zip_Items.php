<?php
/**
 * C68 submission ZIP item mapping use case.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\ZipImport\Application;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Submission\ZipImport\Port\Submission_Zip_Mapping_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps clean ZIP items without creating a submission or submission revision.
 */
final class Map_Submission_Zip_Items {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize submission zip items dependencies and state.
	 *
	 * @param Submission_Zip_Mapping_Repository_Port_Interface $repository Persistence boundary used by this operation.
	 * @param Public_Id_Generator                              $ids Ids.
	 * @param callable|null                                    $clock Clock.
	 */
	public function __construct(
		private Submission_Zip_Mapping_Repository_Port_Interface $repository,
		private Public_Id_Generator $ids,
		?callable $clock = null
	) {
		$this->clock = null === $clock ? static fn (): string => gmdate( 'Y-m-d H:i:s' ) : Closure::fromCallable( $clock );
	}

	/**
	 * Execute submission zip items.
	 *
	 * @param Map_Submission_Zip_Items_Command $command Command.
	 * @return Map_Submission_Zip_Items_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Map_Submission_Zip_Items_Command $command ): Map_Submission_Zip_Items_Result {
		$receipt = $this->repository->find_receipt( $command->idempotency_storage_key(), $command->request_digest() );
		if ( $receipt instanceof Map_Submission_Zip_Items_Result ) {
			return $receipt;
		}

		$occurred_at = ( $this->clock )();
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $occurred_at ) ) {
			throw new InvalidArgumentException( 'Submission ZIP mapping clock returned an invalid UTC value.' );
		}

		return $this->repository->map(
			array(
				'actor_user_id'               => $command->actor_user_id(),
				'audit_public_id'             => $this->ids->create()->value(),
				'authorized_context_id'       => $command->authorized_context_id(),
				'authorized_context_revision' => $command->authorized_context_revision(),
				'authorized_context_type'     => $command->authorized_context_type(),
				'command_id'                  => $command->command_id(),
				'correlation_id'              => $command->correlation_id(),
				'expected_mapping_version'    => $command->expected_mapping_version(),
				'idempotency_storage_key'     => $command->idempotency_storage_key(),
				'items'                       => $command->items(),
				'occurred_at_utc'             => $occurred_at,
				'outbox_public_id'            => $this->ids->create()->value(),
				'request_digest'              => $command->request_digest(),
				'upload_id'                   => $command->upload_id(),
			)
		);
	}
}
