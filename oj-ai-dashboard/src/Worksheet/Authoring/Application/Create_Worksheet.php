<?php
/**
 * CreateWorksheet use case.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Application;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Initial_Document_Factory;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Repository_Port_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates one root and its first immutable draft revision atomically.
 */
final class Create_Worksheet {
	/**
	 * Worksheets.
	 *
	 * @var Worksheet_Repository_Port_Interface
	 */
	private Worksheet_Repository_Port_Interface $worksheets;
	/**
	 * Ids.
	 *
	 * @var Public_Id_Generator
	 */
	private Public_Id_Generator $ids;
	/**
	 * Documents.
	 *
	 * @var Initial_Document_Factory
	 */
	private Initial_Document_Factory $documents;
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize worksheet dependencies and state.
	 *
	 * @param Worksheet_Repository_Port_Interface $worksheets Worksheets.
	 * @param Public_Id_Generator                 $ids Ids.
	 * @param Initial_Document_Factory            $documents Documents.
	 * @param callable|null                       $clock Clock.
	 */
	public function __construct(
		Worksheet_Repository_Port_Interface $worksheets,
		Public_Id_Generator $ids,
		Initial_Document_Factory $documents,
		?callable $clock = null
	) {
		$this->worksheets = $worksheets;
		$this->ids        = $ids;
		$this->documents  = $documents;
		$this->clock      = null === $clock
			? static fn (): string => gmdate( 'Y-m-d H:i:s' )
			: Closure::fromCallable( $clock );
	}

	/**
	 * Execute worksheet.
	 *
	 * @param Create_Worksheet_Command $command Command.
	 * @param array|null               $project Project.
	 * @return Create_Worksheet_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Create_Worksheet_Command $command, ?array $project = null ): Create_Worksheet_Result {
		$created_at = ( $this->clock )();
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $created_at ) ) {
			throw new InvalidArgumentException( 'CreateWorksheet clock returned an invalid UTC value.' );
		}

		$worksheet_id = $this->ids->create()->value();
		$revision_id  = $this->ids->create()->value();
		$audit_id     = $this->ids->create()->value();
		$outbox_id    = $this->ids->create()->value();
		$document     = $this->documents->create( $command->title(), $command->locale(), $project );
		$issued_ids   = array_merge(
			array( $worksheet_id, $revision_id, $audit_id, $outbox_id, $document['document_id'] ),
			$document['node_ids']
		);
		if ( count( $issued_ids ) !== count( array_unique( $issued_ids ) ) ) {
			throw new InvalidArgumentException( 'CreateWorksheet generated duplicate public IDs.' );
		}

		return $this->worksheets->create_initial(
			array(
				'actor_user_id'           => $command->actor_user_id(),
				'command_id'              => $command->command_id(),
				'correlation_id'          => $command->correlation_id(),
				'created_at_utc'          => $created_at,
				'idempotency_storage_key' => null === $project ? $command->idempotency_storage_key() : hash( 'sha256', $command->idempotency_storage_key() . '|' . $project['project_id'] ),
				'request_digest'          => null === $project ? $command->request_digest() : hash( 'sha256', $command->request_digest() . '|' . $project['revision_id'] . '|' . $project['content_hash'] ),
				'worksheet_public_id'     => $worksheet_id,
				'revision_public_id'      => $revision_id,
				'audit_public_id'         => $audit_id,
				'outbox_public_id'        => $outbox_id,
				'title'                   => $command->title(),
				'locale'                  => $command->locale(),
				'document_id'             => $document['document_id'],
				'document_json'           => $document['document_json'],
				'document_hash'           => $document['document_hash'],
				'validation_hash'         => $document['validation_hash'],
				'assessment_project'      => null === $project ? null : \OJAI\Dashboard\Assessment\Project\Domain\Project_Configuration::pin( $project ),
			)
		);
	}
}
