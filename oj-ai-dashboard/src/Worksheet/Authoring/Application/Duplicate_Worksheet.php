<?php
/**
 * DuplicateWorksheet use case.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Application;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Storage\Application\Clone_Worksheet_Asset;
use OJAI\Dashboard\Storage\Application\Clone_Worksheet_Asset_Command;
use OJAI\Dashboard\Storage\Port\Private_File_Store_Port_Interface;
use OJAI\Dashboard\Storage\Port\Worksheet_Asset_Lifecycle_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Canonicalizer;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Id_Rewriter;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Validation_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Revision_Projector;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Duplicate_Repository_Port_Interface;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds a fully independent root and compensates every uncommitted asset clone.
 */
final class Duplicate_Worksheet {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize duplicate worksheet dependencies and state.
	 *
	 * @param Worksheet_Duplicate_Repository_Port_Interface $worksheets Worksheets.
	 * @param Worksheet_Document_Canonicalizer              $documents Documents.
	 * @param Worksheet_Revision_Projector                  $projector Projector.
	 * @param Worksheet_Document_Id_Rewriter                $rewriter Rewriter.
	 * @param Clone_Worksheet_Asset                         $clone_asset Clone asset.
	 * @param Worksheet_Asset_Lifecycle_Port_Interface      $asset_lifecycle Asset lifecycle.
	 * @param Private_File_Store_Port_Interface             $private_files Private files.
	 * @param Public_Id_Generator                           $ids Ids.
	 * @param Canonical_Json                                $canonical Canonical JSON encoder used for stable digests.
	 * @param callable|null                                 $clock Clock.
	 */
	public function __construct(
		private Worksheet_Duplicate_Repository_Port_Interface $worksheets,
		private Worksheet_Document_Canonicalizer $documents,
		private Worksheet_Revision_Projector $projector,
		private Worksheet_Document_Id_Rewriter $rewriter,
		private Clone_Worksheet_Asset $clone_asset,
		private Worksheet_Asset_Lifecycle_Port_Interface $asset_lifecycle,
		private Private_File_Store_Port_Interface $private_files,
		private Public_Id_Generator $ids,
		private Canonical_Json $canonical,
		?callable $clock = null
	) {
		$this->clock = null === $clock
			? static fn (): string => gmdate( 'Y-m-d H:i:s' )
			: Closure::fromCallable( $clock );
	}

	/**
	 * Execute duplicate worksheet.
	 *
	 * @param Duplicate_Worksheet_Command $command Command.
	 * @param array|null                  $project Project.
	 * @return Duplicate_Worksheet_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Duplicate_Worksheet_Command $command, ?array $project = null ): Duplicate_Worksheet_Result {
		$storage_key = $command->idempotency_storage_key();
		$digest      = $command->request_digest();
		if ( null !== $project ) {
			$pin         = \OJAI\Dashboard\Assessment\Project\Domain\Project_Configuration::pin( $project );
			$storage_key = hash( 'sha256', $storage_key . '|' . $project['project_id'] );
			$digest      = hash( 'sha256', $digest . '|' . $this->canonical->encode( $pin ) );
		}
		$receipt = $this->worksheets->find_duplicate_receipt(
			$command->actor_user_id(),
			$storage_key,
			$digest
		);
		if ( $receipt instanceof Duplicate_Worksheet_Result ) {
			return $receipt;
		}

		$source = $this->worksheets->find_owned_duplicate_source(
			$command->actor_user_id(),
			$command->source_worksheet_id()
		);
		if ( ! is_array( $source ) ) {
			throw Worksheet_Failure::not_found();
		}
		$current_revision_id  = is_string( $source['current_revision_id'] ?? null ) ? $source['current_revision_id'] : '';
		$source_document_hash = is_string( $source['source_document_hash'] ?? null ) ? $source['source_document_hash'] : '';
		if (
			! hash_equals( $command->expected_state(), (string) ( $source['state'] ?? '' ) )
			|| ! hash_equals( $command->expected_revision_id(), $current_revision_id )
		) {
			throw Worksheet_Failure::revision_conflict(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				$command->expected_revision_id(),
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				$current_revision_id,
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				$source_document_hash
			);
		}

		$destination_worksheet_id = $this->ids->create()->value();
		$cloned_assets            = array();
		try {
			$source_document          = $this->source_document( $source );
			$source_document['title'] = $command->copy_title( (string) ( $source['title'] ?? '' ) );
			$source_links             = $this->source_list( $source, 'achievement_links' );
			$standard_snapshots       = $this->source_list( $source, 'standard_snapshots' );

			$asset_map = array();
			foreach ( $this->rewriter->asset_ids( $source_document ) as $position => $source_asset_id ) {
				$clone                         = $this->clone_asset->execute(
					new Clone_Worksheet_Asset_Command(
						$command->actor_user_id(),
						$source_asset_id,
						$command->expected_revision_id(),
						$destination_worksheet_id,
						$command->asset_idempotency_fingerprint( $position ),
						$command->correlation_id()
					)
				);
				$asset_map[ $source_asset_id ] = $clone->asset_id();
				$cloned_assets[]               = $clone->asset_id();
			}

			$rewritten = $this->rewriter->rewrite(
				$source_document,
				$source_links,
				$asset_map,
				$command->rewrite_seed()
			);
			if ( null !== $project ) {
				$rewritten['teacher_document']  = \OJAI\Dashboard\Assessment\Project\Domain\Project_Configuration::seed( $rewritten['teacher_document'], $project );
				$rewritten['achievement_links'] = array();
				$standard_snapshots             = array();
			}
			$document   = $this->documents->canonicalize( $rewritten['teacher_document'] );
			$projection = $this->projector->project(
				$document,
				$rewritten['achievement_links'],
				$standard_snapshots
			);
			$created_at = ( $this->clock )();
			$this->assert_utc( $created_at );
			$revision_id = $this->ids->create()->value();
			$audit_id    = $this->ids->create()->value();
			$outbox_id   = $this->ids->create()->value();
			$this->assert_generated_ids(
				$destination_worksheet_id,
				$revision_id,
				$audit_id,
				$outbox_id,
				$document->document_json(),
				$projection->questions()
			);

			$worksheet_links    = $projection->worksheet_achievement_links();
			$question_links     = $projection->question_achievement_links();
			$content_hash       = $this->revision_content_hash(
				$document->document_hash(),
				$worksheet_links,
				$question_links
			);
			$canonical_document = $document->document();
			$result             = $this->worksheets->duplicate_initial(
				array(
					'actor_user_id'               => $command->actor_user_id(),
					'command_id'                  => $command->command_id(),
					'correlation_id'              => $command->correlation_id(),
					'saved_at_utc'                => $created_at,
					'idempotency_storage_key'     => $storage_key,
					'request_digest'              => $digest,
					'assessment_project'          => null === $project ? null : $pin,
					'source_worksheet_public_id'  => $command->source_worksheet_id(),
					'source_document_hash'        => $source_document_hash,
					'worksheet_public_id'         => $destination_worksheet_id,
					'expected_state'              => $command->expected_state(),
					'expected_revision_public_id' => $command->expected_revision_id(),
					'revision_public_id'          => $revision_id,
					'audit_public_id'             => $audit_id,
					'outbox_public_id'            => $outbox_id,
					'title'                       => (string) $canonical_document['title'],
					'locale'                      => (string) $canonical_document['locale'],
					'document_id'                 => (string) $canonical_document['document_id'],
					'document_json'               => $document->document_json(),
					'document_hash'               => $document->document_hash(),
					'validation_json'             => $document->validation_json(),
					'validation_hash'             => $document->validation_hash(),
					'content_hash'                => $content_hash,
					'temporary_id_map'            => $document->temporary_id_map(),
					'questions'                   => $projection->questions(),
					'worksheet_achievement_links' => $worksheet_links,
					'question_achievement_links'  => $question_links,
					'asset_bindings'              => $projection->asset_bindings(),
					'staged_asset_receipt_ids'    => array_values( $asset_map ),
					'projection_json'             => $projection->projection_json(),
					'projection_hash'             => $projection->projection_hash(),
					'asset_count'                 => count( $asset_map ),
					'reissued_id_count'           => (int) $rewritten['reissued_id_count'],
				)
			);
			if ( $result->replayed() ) {
				$this->compensate( $command, $destination_worksheet_id, $cloned_assets );
			}
			return $result;
		} catch ( Worksheet_Document_Validation_Failure | InvalidArgumentException $failure ) {
			$this->compensate( $command, $destination_worksheet_id, $cloned_assets );
			throw Worksheet_Failure::stored_data_invalid();
		} catch ( Throwable $failure ) {
			$this->compensate( $command, $destination_worksheet_id, $cloned_assets );
			throw $failure;
		}
	}

	/**
	 * Source document.
	 *
	 * @param array<string,mixed> $source Source.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function source_document( array $source ): array {
		$document = $source['teacher_document'] ?? null;
		if ( ! is_array( $document ) || array_is_list( $document ) ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
		return $document;
	}

	/**
	 * Source list.
	 *
	 * @param array<string,mixed> $source Source.
	 * @param string              $key Key.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function source_list( array $source, string $key ): array {
		$value = $source[ $key ] ?? null;
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
		return $value;
	}

	/**
	 * Reject invalid generated ids.
	 *
	 * @param string                         $worksheet_id Worksheet public identifier.
	 * @param string                         $revision_id Revision id.
	 * @param string                         $audit_id Audit id.
	 * @param string                         $outbox_id Outbox id.
	 * @param string                         $document_json Document json.
	 * @param array<int,array<string,mixed>> $questions Questions.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_generated_ids(
		string $worksheet_id,
		string $revision_id,
		string $audit_id,
		string $outbox_id,
		string $document_json,
		array $questions
	): void {
		$ids = array_merge(
			array( $worksheet_id, $revision_id, $audit_id, $outbox_id ),
			array_column( $questions, 'public_id' )
		);
		if ( count( $ids ) !== count( array_unique( $ids ) ) ) {
			throw new InvalidArgumentException( 'DuplicateWorksheet generated duplicate row IDs.' );
		}
		foreach ( $ids as $id ) {
			if ( str_contains( $document_json, (string) $id ) ) {
				throw new InvalidArgumentException( 'DuplicateWorksheet generated a row ID present in the document.' );
			}
		}
	}

	/**
	 * Revision content hash.
	 *
	 * @param string                         $document_hash Document hash.
	 * @param array<int,array<string,mixed>> $worksheet_links Worksheet links.
	 * @param array<int,array<string,mixed>> $question_links Question links.
	 * @return string
	 */
	private function revision_content_hash( string $document_hash, array $worksheet_links, array $question_links ): string {
		if ( array() === $worksheet_links && array() === $question_links ) {
			return $document_hash;
		}
		return hash(
			'sha256',
			$this->canonical->encode(
				array(
					'document_hash'                     => $document_hash,
					'worksheet_achievement_link_hashes' => array_column( $worksheet_links, 'content_hash' ),
					'question_achievement_link_hashes'  => array_column( $question_links, 'content_hash' ),
				)
			)
		);
	}

	/**
	 * Compensate.
	 *
	 * @param Duplicate_Worksheet_Command $command Command.
	 * @param string                      $worksheet_id Worksheet public identifier.
	 * @param array<int,string>           $asset_ids Asset ids.
	 * @return void
	 */
	private function compensate(
		Duplicate_Worksheet_Command $command,
		string $worksheet_id,
		array $asset_ids
	): void {
		$purged_at = ( $this->clock )();
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $purged_at ) ) {
			return;
		}
		foreach ( array_reverse( $asset_ids ) as $asset_id ) {
			try {
				$row = $this->asset_lifecycle->find_by_public_id( $asset_id );
				if (
					! is_array( $row ) || 'quarantined' !== (string) ( $row['state'] ?? '' )
					|| 'clean' !== (string) ( $row['scan_state'] ?? '' )
					|| $command->actor_user_id() !== (int) ( $row['created_by_user_id'] ?? 0 )
					|| 'worksheet' !== (string) ( $row['owner_type'] ?? '' )
					|| ! hash_equals( $worksheet_id, (string) ( $row['owner_public_id'] ?? '' ) )
					|| 'worksheet_asset' !== (string) ( $row['purpose'] ?? '' )
				) {
					continue;
				}
				if ( $this->asset_lifecycle->mark_quarantined_purged(
					$command->actor_user_id(),
					$worksheet_id,
					$asset_id,
					$purged_at
				) ) {
					$this->private_files->delete( (string) ( $row['storage_key'] ?? '' ) );
				}
			} catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Best-effort recovery must not replace the primary failure.
				// Retention cleanup handles any remaining unbound quarantine.
			}
		}
	}

	/**
	 * Reject invalid utc.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_utc( string $value ): void {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			throw new InvalidArgumentException( 'DuplicateWorksheet clock returned an invalid UTC value.' );
		}
	}
}
