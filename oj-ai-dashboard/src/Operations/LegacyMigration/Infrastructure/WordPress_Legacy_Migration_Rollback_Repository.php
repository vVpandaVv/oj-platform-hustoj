<?php
/**
 * WordPress legacy migration rollback repository.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use OJAI\Dashboard\Database\Foundation\Infrastructure\WordPress_Schema_Lock;
use OJAI\Dashboard\Database\Foundation\Port\Schema_Lock_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Execute_Legacy_Migration;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Migration_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Rollback_Legacy_Migration_Result;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Rollback_Repository_Port_Interface;
use OJAI\Dashboard\Storage\Port\Private_File_Store_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compensates only unchanged targets created by one completed migration run.
 */
final class WordPress_Legacy_Migration_Rollback_Repository implements Legacy_Migration_Rollback_Repository_Port_Interface {
	private const TABLE_RUNS                  = 'oj_ai_import_runs';
	private const TABLE_ITEMS                 = 'oj_ai_import_items';
	private const TABLE_AUDIT                 = 'oj_ai_audit_events';
	private const TABLE_OUTBOX                = 'oj_ai_outbox_events';
	private const TABLE_CURRICULUMS           = 'oj_ai_curriculums';
	private const TABLE_STANDARDS             = 'oj_ai_achievement_standards';
	private const TABLE_WORKSHEETS            = 'oj_ai_worksheets';
	private const TABLE_WORKSHEET_REVISIONS   = 'oj_ai_worksheet_revisions';
	private const TABLE_QUESTIONS             = 'oj_ai_worksheet_revision_questions';
	private const TABLE_WORKSHEET_LINKS       = 'oj_ai_worksheet_achievement_links';
	private const TABLE_QUESTION_LINKS        = 'oj_ai_question_achievement_links';
	private const TABLE_PUBLICATIONS          = 'oj_ai_worksheet_publications';
	private const TABLE_ASSIGNMENTS           = 'oj_ai_assignments';
	private const TABLE_ASSIGNMENT_TARGETS    = 'oj_ai_assignment_targets';
	private const TABLE_SUBMISSIONS           = 'oj_ai_submissions';
	private const TABLE_SUBMISSION_REVISIONS  = 'oj_ai_submission_revisions';
	private const TABLE_SUBMISSION_RECEIPTS   = 'oj_ai_submission_receipts';
	private const TABLE_ATTACHMENTS           = 'oj_ai_submission_attachments';
	private const TABLE_ZIPS                  = 'oj_ai_zip_uploads';
	private const TABLE_ZIP_ITEMS             = 'oj_ai_zip_upload_items';
	private const TABLE_EVALUATIONS           = 'oj_ai_submission_evaluations';
	private const TABLE_EVALUATION_INPUTS     = 'oj_ai_submission_evaluation_inputs';
	private const TABLE_EVALUATION_RESULTS    = 'oj_ai_submission_evaluation_results';
	private const TABLE_EVALUATION_ITEMS      = 'oj_ai_submission_evaluation_items';
	private const TABLE_EVALUATION_REVIEWS    = 'oj_ai_submission_evaluation_reviews';
	private const TABLE_FEEDBACK              = 'oj_ai_submission_feedback_revisions';
	private const TABLE_RELEASES              = 'oj_ai_submission_release_revisions';
	private const TABLE_NOTES                 = 'oj_ai_student_note_revisions';
	private const TABLE_EVALUATION_RUNS       = 'oj_ai_evaluation_runs';
	private const TABLE_EVALUATION_ATTEMPTS   = 'oj_ai_evaluation_attempts';
	private const TABLE_PRIVATE_FILES         = 'oj_ai_private_files';
	private const TABLE_WORKSHEET_EVALUATIONS = 'oj_ai_worksheet_evaluations';
	private const TABLE_REPORTS               = 'oj_ai_report_snapshots';
	private const TABLE_EXPORTS               = 'oj_ai_export_jobs';
	private const TABLE_PDFS                  = 'oj_ai_pdf_artifacts';

	private const ITEM_SCHEMA       = 'oj_ai.legacy_item_payload';
	private const ITEM_PROFILE      = 'oj_ai.legacy_item_payload@1.0.0';
	private const EVENT_COMPENSATED = 'legacy_migration.targets_compensated';
	private const AGGREGATE_TYPE    = 'legacy_migration_run';
	private const OUTBOX_SCHEMA     = 'oj_ai.legacy_migration_rollback_event';
	private const AUDIT_SCHEMA      = 'oj_ai.legacy_migration_rollback_audit';
	private const LOCK_NAME_PREFIX  = 'oj_ai_dashboard_legacy_migration_';

	private const ROOT_TYPES = array(
		'curriculum',
		'achievement_standard',
		'worksheet',
		'assignment',
		'submission',
		'submission_attachment',
		'submission_zip',
		'submission_evaluation',
	);

	private const ALLOWED_TARGET_TYPES = array(
		'curriculum',
		'achievement_standard',
		'worksheet',
		'worksheet_question',
		'worksheet_achievement_link',
		'question_achievement_link',
		'assignment',
		'submission',
		'submission_revision',
		'submission_attachment',
		'submission_attachment_manifest',
		'submission_zip',
		'submission_zip_item',
		'assessment_plan_snapshot',
		'assessment_plan_rubric_snapshot',
		'submission_evaluation',
		'submission_evaluation_relation',
		'submission_evaluation_run',
		'submission_evaluation_result',
		'submission_evaluation_review',
		'submission_feedback_revision',
		'student_note_revision',
	);

	/**
	 * Initialize WordPress legacy migration rollback repository dependencies and state.
	 *
	 * @param Canonical_Json                    $canonical Canonical JSON encoder used for stable digests.
	 * @param Private_File_Store_Port_Interface $private_files Private files.
	 * @param object|null                       $database Database.
	 * @param Schema_Lock_Port_Interface|null   $lock Lock.
	 */
	public function __construct(
		private Canonical_Json $canonical,
		private Private_File_Store_Port_Interface $private_files,
		private ?object $database = null,
		private ?Schema_Lock_Port_Interface $lock = null
	) {
	}

	/**
	 * Compensated receipt.
	 *
	 * @param string $storage_key Storage key.
	 * @param string $request_digest Request digest.
	 * @return Rollback_Legacy_Migration_Result|null
	 */
	public function compensated_receipt( string $storage_key, string $request_digest ): ?Rollback_Legacy_Migration_Result {
		return $this->command_receipt( $storage_key, $request_digest, false );
	}

	/**
	 * Compensate.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Rollback_Legacy_Migration_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function compensate( array $record ): Rollback_Legacy_Migration_Result {
		$this->assert_record( $record );
		$receipt = $this->command_receipt( $record['command_storage_key'], $record['request_digest'], false );
		if ( $receipt instanceof Rollback_Legacy_Migration_Result ) {
			return $receipt;
		}

		$lock          = null;
		$lock_name     = self::LOCK_NAME_PREFIX . $record['site_id'];
		$lock_acquired = false;
		$transaction   = false;
		try {
			$lock = $this->lock();
			if ( ! $lock->acquire( $lock_name, 0 ) ) {
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::RUN_STATE_CONFLICT );
			}
			$lock_acquired = true;
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;

			$receipt = $this->command_receipt( $record['command_storage_key'], $record['request_digest'], true );
			if ( $receipt instanceof Rollback_Legacy_Migration_Result ) {
				$this->query_or_fail( 'COMMIT' );
				$transaction = false;
				return $receipt;
			}

			$run       = $this->terminal_run( $record );
			$inventory = $this->inventory( $run );
			$plan      = $this->target_plan( $inventory, $run );
			$this->assert_external_references( $plan );
			$private_files       = $this->private_file_plan( $plan, $run );
			$business_mutations  = $this->purge_private_files( $private_files, $record );
			$business_mutations += $this->delete_targets( $plan );

			$checkpoint          = $run['checkpoint'];
			$checkpoint['phase'] = 'compensated';
			$checkpoint_json     = $this->canonical->encode( $checkpoint );
			$checkpoint_hash     = hash( 'sha256', $checkpoint_json );
			$this->update_run( $run, $record, $checkpoint_json, $checkpoint_hash );
			$result = $this->result( $run, $checkpoint_hash, $business_mutations, (string) $record['compensated_at_utc'], false );
			$this->insert_audit( $run, $record, $checkpoint_hash, $business_mutations );
			$this->insert_outbox( $record, $result );
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return $result;
		} catch ( Legacy_Migration_Failure $failure ) {
			if ( $transaction ) {
				$this->rollback();
			}
			$winner = $this->recover_receipt( $record );
			if ( $winner instanceof Rollback_Legacy_Migration_Result ) {
				return $winner;
			}
			throw $failure;
		} catch ( Throwable $error ) {
			if ( $transaction ) {
				$this->rollback();
			}
			$winner = $this->recover_receipt( $record );
			if ( $winner instanceof Rollback_Legacy_Migration_Result ) {
				return $winner;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE, $error );
		} finally {
			if ( $lock_acquired && $lock instanceof Schema_Lock_Port_Interface ) {
				try {
					$lock->release( $lock_name ); } catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
					// Best-effort recovery must not replace the primary failure.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Best-effort recovery must not replace the primary failure.
					}
			}
		}
	}

	/**
	 * Terminal run.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function terminal_run( array $record ): array {
		$row = $this->row(
			$this->prepare(
				'SELECT * FROM ' . self::TABLE_RUNS . ' WHERE public_id = %s LIMIT 1 FOR UPDATE',
				array( $record['run_public_id'] )
			)
		);
		if ( null === $row ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::RUN_NOT_FOUND );
		}
		if (
			$record['expected_state'] !== ( $row['state'] ?? null )
			|| ! hash_equals( (string) $record['expected_checkpoint_hash'], (string) ( $row['checkpoint_hash'] ?? '' ) )
			|| ! hash_equals( (string) $record['expected_updated_at_utc'], (string) ( $row['updated_at_utc'] ?? '' ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::RUN_STATE_CONFLICT );
		}
		$state = (string) $row['state'];
		if ( ! in_array( $state, array( 'completed', 'completed_with_errors' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::RUN_STATE_CONFLICT );
		}
		$row['id']                      = $this->positive_integer( $row['id'] ?? null );
		$row['lock_version']            = $this->non_negative_integer( $row['lock_version'] ?? null );
		$row['approved_by_user_id']     = $this->positive_integer( $row['approved_by_user_id'] ?? null );
		$row['execution_actor_user_id'] = $this->positive_integer( $row['updated_by_user_id'] ?? null );
		foreach ( array( 'item_total', 'success_total', 'failure_total', 'skipped_total' ) as $key ) {
			$row[ $key ] = $this->non_negative_integer( $row[ $key ] ?? null );
		}
		if ( $row['success_total'] + $row['failure_total'] + $row['skipped_total'] !== $row['item_total'] ) {
			$this->stored_invalid();
		}
		if (
			Execute_Legacy_Migration::CHECKPOINT_SCHEMA !== ( $row['checkpoint_schema'] ?? null )
			|| Execute_Legacy_Migration::CHECKPOINT_VERSION !== $this->positive_integer( $row['checkpoint_version'] ?? null )
		) {
			$this->stored_invalid();
		}
		$checkpoint = $this->decode_stored( (string) ( $row['checkpoint_json'] ?? '' ), (string) $row['checkpoint_hash'] );
		$this->assert_checkpoint( $checkpoint, $state, $row );
		$row['checkpoint'] = $checkpoint;
		return $row;
	}

	/**
	 * Inventory.
	 *
	 * @param array<string,mixed> $run Run.
	 * @return array<string,mixed>
	 */
	private function inventory( array $run ): array {
		$rows = $this->rows(
			$this->prepare(
				'SELECT * FROM ' . self::TABLE_ITEMS . ' WHERE import_run_id = %d ORDER BY item_no ASC FOR UPDATE',
				array( $run['id'] )
			)
		);
		if ( count( $rows ) !== $run['item_total'] ) {
			$this->stored_invalid();
		}
		$inventory = array(
			'receipts'  => array(),
			'item_rows' => $rows,
		);
		$counts    = array(
			'success' => 0,
			'failure' => 0,
			'skipped' => 0,
		);
		foreach ( $rows as $index => $row ) {
			if (
				$index + 1 !== $this->positive_integer( $row['item_no'] ?? null )
				|| $run['id'] !== $this->positive_integer( $row['import_run_id'] ?? null )
				|| $run['execution_actor_user_id'] !== $this->positive_integer( $row['created_by_user_id'] ?? null )
				|| $run['execution_actor_user_id'] !== $this->positive_integer( $row['updated_by_user_id'] ?? null )
				|| $this->non_negative_integer( $row['lock_version'] ?? null ) > 1
			) {
				$this->stored_invalid();
			}
			$this->utc( $row['created_at_utc'] ?? null );
			$this->utc( $row['updated_at_utc'] ?? null );
			$state = $row['state'] ?? null;
			if ( ! is_string( $state ) || ! isset( $counts[ $state ] ) ) {
				$this->stored_invalid();
			}
			++$counts[ $state ];
			if ( self::ITEM_SCHEMA !== ( $row['payload_schema'] ?? null ) || 1 !== $this->positive_integer( $row['payload_version'] ?? null ) ) {
				$this->stored_invalid();
			}
			$payload = $this->decode_stored( (string) ( $row['payload_json'] ?? '' ), (string) ( $row['payload_hash'] ?? '' ) );
			$this->exact_keys( $payload, array( 'error_code', 'profile', 'source', 'state', 'target' ) );
			$source = $payload['source'] ?? null;
			if ( ! is_array( $source ) || array_is_list( $source ) ) {
				$this->stored_invalid();
			}
			$this->exact_keys( $source, array( 'checksum', 'key', 'type' ) );
			if (
				self::ITEM_PROFILE !== ( $payload['profile'] ?? null ) || $state !== ( $payload['state'] ?? null )
				|| ! hash_equals( (string) ( $row['source_type'] ?? '' ), (string) ( $source['type'] ?? '' ) )
				|| ! hash_equals( (string) ( $row['source_key'] ?? '' ), (string) ( $source['key'] ?? '' ) )
				|| ! hash_equals( (string) ( $row['source_checksum'] ?? '' ), (string) ( $source['checksum'] ?? '' ) )
				|| ( $row['error_code'] ?? null ) !== ( $payload['error_code'] ?? null )
			) {
				$this->stored_invalid();
			}
			$this->hash_value( (string) $row['source_checksum'] );
			if ( 'success' !== $state ) {
				if ( null !== ( $payload['target'] ?? null ) || null !== ( $row['target_type'] ?? null ) || null !== ( $row['target_public_id'] ?? null ) ) {
					$this->stored_invalid();
				}
				continue;
			}
			$target = $payload['target'] ?? null;
			if ( ! is_array( $target ) || array_is_list( $target ) ) {
				$this->stored_invalid();
			}
			$this->exact_keys( $target, array( 'content_hash', 'metadata', 'public_id', 'type' ) );
			$type = (string) ( $target['type'] ?? '' );
			$id   = (string) ( $target['public_id'] ?? '' );
			if (
				! in_array( $type, self::ALLOWED_TARGET_TYPES, true ) || ! Public_Id::is_valid( $id )
				|| ! hash_equals( $type, (string) ( $row['target_type'] ?? '' ) )
				|| ! hash_equals( $id, (string) ( $row['target_public_id'] ?? '' ) )
				|| ! is_array( $target['metadata'] ?? null ) || array_is_list( $target['metadata'] )
			) {
				$this->stored_invalid();
			}
			$this->hash_value( (string) $target['content_hash'] );
			$inventory['receipts'][ $type ][] = array(
				'item'   => $row,
				'target' => $target,
			);
		}
		if (
			$counts['success'] !== $run['success_total'] || $counts['failure'] !== $run['failure_total']
			|| $counts['skipped'] !== $run['skipped_total']
		) {
			$this->stored_invalid();
		}
		foreach ( self::ROOT_TYPES as $type ) {
			$seen = array();
			foreach ( $inventory['receipts'][ $type ] ?? array() as $receipt ) {
				$id = (string) $receipt['target']['public_id'];
				if ( isset( $seen[ $id ] ) ) {
					$this->stored_invalid();
				}
				$seen[ $id ] = true;
			}
		}
		return $inventory;
	}

	/**
	 * Target plan.
	 *
	 * @param array $inventory Inventory.
	 * @param array $run Run.
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function target_plan( array $inventory, array $run ): array {
		$plan  = array();
		$actor = (int) $run['execution_actor_user_id'];

		$plan[ self::TABLE_CURRICULUMS ] = $this->root_rows( self::TABLE_CURRICULUMS, 'curriculum', $inventory, $actor, 0, 'content_hash' );
		$plan[ self::TABLE_STANDARDS ]   = $this->root_rows( self::TABLE_STANDARDS, 'achievement_standard', $inventory, $actor, 0, 'content_hash' );
		$this->assert_parent_set( $plan[ self::TABLE_STANDARDS ], 'curriculum_id', $plan[ self::TABLE_CURRICULUMS ] );
		$this->assert_exact_children( self::TABLE_STANDARDS, 'curriculum_id', $plan[ self::TABLE_CURRICULUMS ], $plan[ self::TABLE_STANDARDS ] );

		$plan[ self::TABLE_WORKSHEETS ] = $this->root_rows( self::TABLE_WORKSHEETS, 'worksheet', $inventory, $actor, 0 );
		$revision_ids                   = array();
		$question_ids                   = array();
		$worksheet_link_hashes          = array();
		$question_link_hashes           = array();
		foreach ( $this->receipts( $inventory, 'worksheet' ) as $receipt ) {
			$metadata              = $receipt['target']['metadata'];
			$revision_id           = $this->public_id_value( $metadata['revision_public_id'] ?? null );
			$revision_ids[]        = $revision_id;
			$question_ids          = array_merge( $question_ids, $this->public_id_values( $metadata['question_public_ids'] ?? null ) );
			$worksheet_link_hashes = array_merge( $worksheet_link_hashes, $this->hash_values( $metadata['worksheet_link_hashes'] ?? null ) );
			$question_link_hashes  = array_merge( $question_link_hashes, $this->hash_values( $metadata['question_link_hashes'] ?? null ) );
			$root                  = $this->find_public( $plan[ self::TABLE_WORKSHEETS ], (string) $receipt['target']['public_id'] );
			if ( ( $root['current_revision_public_id'] ?? null ) !== $revision_id || null !== ( $root['latest_publication_public_id'] ?? null ) ) {
				$this->target_changed();
			}
		}
		$plan[ self::TABLE_WORKSHEET_REVISIONS ] = array_values( $this->public_rows( self::TABLE_WORKSHEET_REVISIONS, $revision_ids ) );
		$this->assert_parent_set( $plan[ self::TABLE_WORKSHEET_REVISIONS ], 'worksheet_id', $plan[ self::TABLE_WORKSHEETS ] );
		$this->assert_exact_children( self::TABLE_WORKSHEET_REVISIONS, 'worksheet_id', $plan[ self::TABLE_WORKSHEETS ], $plan[ self::TABLE_WORKSHEET_REVISIONS ] );
		$this->assert_receipt_hashes( $plan[ self::TABLE_WORKSHEET_REVISIONS ], $this->receipts( $inventory, 'worksheet' ), 'revision_public_id', 'revision_content_hash' );
		$plan[ self::TABLE_QUESTIONS ] = $this->children( self::TABLE_QUESTIONS, 'worksheet_revision_id', $plan[ self::TABLE_WORKSHEET_REVISIONS ] );
		$this->assert_public_set( $plan[ self::TABLE_QUESTIONS ], $question_ids );
		$this->assert_direct_receipt_hashes( $plan[ self::TABLE_QUESTIONS ], $this->receipts( $inventory, 'worksheet_question' ) );
		$plan[ self::TABLE_WORKSHEET_LINKS ] = $this->children( self::TABLE_WORKSHEET_LINKS, 'worksheet_revision_id', $plan[ self::TABLE_WORKSHEET_REVISIONS ] );
		$plan[ self::TABLE_QUESTION_LINKS ]  = $this->children( self::TABLE_QUESTION_LINKS, 'question_id', $plan[ self::TABLE_QUESTIONS ] );
		$this->assert_hash_set( $plan[ self::TABLE_WORKSHEET_LINKS ], $worksheet_link_hashes );
		$this->assert_hash_set( $plan[ self::TABLE_QUESTION_LINKS ], $question_link_hashes );
		$this->assert_standard_link_ownership( $plan );

		$plan[ self::TABLE_ASSIGNMENTS ] = $this->root_rows( self::TABLE_ASSIGNMENTS, 'assignment', $inventory, $actor, 0 );
		$publication_ids                 = array();
		$target_count                    = 0;
		foreach ( $this->receipts( $inventory, 'assignment' ) as $receipt ) {
			$metadata          = $receipt['target']['metadata'];
			$publication_ids[] = $this->public_id_value( $metadata['publication_public_id'] ?? null );
			$target_count     += $this->non_negative_integer( $metadata['target_count'] ?? null );
		}
		$plan[ self::TABLE_PUBLICATIONS ] = array_values( $this->public_rows( self::TABLE_PUBLICATIONS, array_values( array_unique( $publication_ids ) ) ) );
		$this->assert_parent_set( $plan[ self::TABLE_PUBLICATIONS ], 'worksheet_revision_id', $plan[ self::TABLE_WORKSHEET_REVISIONS ] );
		$this->assert_exact_children( self::TABLE_PUBLICATIONS, 'worksheet_revision_id', $plan[ self::TABLE_WORKSHEET_REVISIONS ], $plan[ self::TABLE_PUBLICATIONS ] );
		$this->assert_assignment_publications( $plan, $inventory, $actor );
		$plan[ self::TABLE_ASSIGNMENT_TARGETS ] = $this->children( self::TABLE_ASSIGNMENT_TARGETS, 'assignment_id', $plan[ self::TABLE_ASSIGNMENTS ] );
		if ( count( $plan[ self::TABLE_ASSIGNMENT_TARGETS ] ) !== $target_count ) {
			$this->target_changed();
		}

		$plan[ self::TABLE_SUBMISSIONS ] = $this->root_rows( self::TABLE_SUBMISSIONS, 'submission', $inventory, $actor, null );
		$this->assert_parent_set( $plan[ self::TABLE_SUBMISSIONS ], 'assignment_id', $plan[ self::TABLE_ASSIGNMENTS ] );
		$this->assert_exact_children( self::TABLE_SUBMISSIONS, 'assignment_id', $plan[ self::TABLE_ASSIGNMENTS ], $plan[ self::TABLE_SUBMISSIONS ] );
		$plan[ self::TABLE_SUBMISSION_REVISIONS ] = $this->children( self::TABLE_SUBMISSION_REVISIONS, 'submission_id', $plan[ self::TABLE_SUBMISSIONS ] );
		$plan[ self::TABLE_SUBMISSION_RECEIPTS ]  = $this->children( self::TABLE_SUBMISSION_RECEIPTS, 'submission_id', $plan[ self::TABLE_SUBMISSIONS ] );
		$plan[ self::TABLE_ATTACHMENTS ]          = $this->root_rows( self::TABLE_ATTACHMENTS, 'submission_attachment', $inventory, $actor, null );
		$this->assert_submission_children( $plan, $inventory );

		$plan[ self::TABLE_ZIPS ] = $this->root_rows( self::TABLE_ZIPS, 'submission_zip', $inventory, $actor, null );
		$this->assert_parent_set( $plan[ self::TABLE_ZIPS ], 'assignment_id', $plan[ self::TABLE_ASSIGNMENTS ] );
		$this->assert_exact_children( self::TABLE_ZIPS, 'assignment_id', $plan[ self::TABLE_ASSIGNMENTS ], $plan[ self::TABLE_ZIPS ] );
		$plan[ self::TABLE_ZIP_ITEMS ] = $this->children( self::TABLE_ZIP_ITEMS, 'zip_upload_id', $plan[ self::TABLE_ZIPS ] );
		$this->assert_zip_children( $plan, $inventory );

		$plan[ self::TABLE_EVALUATIONS ] = $this->root_rows( self::TABLE_EVALUATIONS, 'submission_evaluation', $inventory, $actor, 0 );
		$this->assert_evaluation_roots( $plan );
		$plan[ self::TABLE_EVALUATION_INPUTS ]   = $this->children( self::TABLE_EVALUATION_INPUTS, 'submission_evaluation_id', $plan[ self::TABLE_EVALUATIONS ] );
		$plan[ self::TABLE_EVALUATION_RESULTS ]  = $this->children( self::TABLE_EVALUATION_RESULTS, 'submission_evaluation_id', $plan[ self::TABLE_EVALUATIONS ] );
		$plan[ self::TABLE_EVALUATION_REVIEWS ]  = $this->children( self::TABLE_EVALUATION_REVIEWS, 'submission_evaluation_id', $plan[ self::TABLE_EVALUATIONS ] );
		$plan[ self::TABLE_FEEDBACK ]            = $this->children( self::TABLE_FEEDBACK, 'submission_evaluation_id', $plan[ self::TABLE_EVALUATIONS ] );
		$plan[ self::TABLE_RELEASES ]            = $this->children( self::TABLE_RELEASES, 'submission_evaluation_id', $plan[ self::TABLE_EVALUATIONS ] );
		$plan[ self::TABLE_NOTES ]               = $this->children( self::TABLE_NOTES, 'submission_evaluation_id', $plan[ self::TABLE_EVALUATIONS ] );
		$run_ids                                 = $this->evaluation_metadata_ids( $inventory, 'assessment_run_public_ids' );
		$plan[ self::TABLE_EVALUATION_RUNS ]     = array_values( $this->public_rows( self::TABLE_EVALUATION_RUNS, $run_ids ) );
		$plan[ self::TABLE_EVALUATION_ATTEMPTS ] = $this->children( self::TABLE_EVALUATION_ATTEMPTS, 'evaluation_run_id', $plan[ self::TABLE_EVALUATION_RUNS ] );
		if ( array() !== $plan[ self::TABLE_EVALUATION_ATTEMPTS ] ) {
			$this->target_changed();
		}
		$plan[ self::TABLE_EVALUATION_ITEMS ] = $this->children( self::TABLE_EVALUATION_ITEMS, 'submission_evaluation_result_id', $plan[ self::TABLE_EVALUATION_RESULTS ] );
		$this->assert_evaluation_children( $plan, $inventory, $actor );
		$this->assert_submission_locks_and_pointers( $plan, $inventory );

		return $plan;
	}

	/**
	 * Private file plan.
	 *
	 * @param array $plan Plan.
	 * @param array $run Run.
	 * @return array<int,array<string,mixed>>
	 */
	private function private_file_plan( array $plan, array $run ): array {
		$specs       = array();
		$submissions = $this->keyed( $plan[ self::TABLE_SUBMISSIONS ] );
		foreach ( $plan[ self::TABLE_ATTACHMENTS ] as $row ) {
			$submission = $submissions[ (int) $row['submission_id'] ] ?? null;
			if ( ! is_array( $submission ) ) {
				$this->target_changed();
			}
			$this->add_file_spec( $specs, $row['file_public_id'] ?? null, 'submission', $submission['public_id'], 'submission_attachment', 'submission_staging', 'ready' );
		}
		$assignments = $this->keyed( $plan[ self::TABLE_ASSIGNMENTS ] );
		$zips        = $this->keyed( $plan[ self::TABLE_ZIPS ] );
		foreach ( $plan[ self::TABLE_ZIPS ] as $row ) {
			$assignment = $assignments[ (int) $row['assignment_id'] ] ?? null;
			if ( ! is_array( $assignment ) ) {
				$this->target_changed();
			}
			$this->add_file_spec( $specs, $row['file_public_id'] ?? null, 'assignment', $assignment['public_id'], 'submission_zip_source', 'submission_zip_staging', 'quarantined' );
		}
		foreach ( $plan[ self::TABLE_ZIP_ITEMS ] as $row ) {
			if ( null === ( $row['file_public_id'] ?? null ) ) {
				continue;
			}
			$zip = $zips[ (int) $row['zip_upload_id'] ] ?? null;
			if ( ! is_array( $zip ) ) {
				$this->target_changed();
			}
			$this->add_file_spec( $specs, $row['file_public_id'], 'submission_zip', $zip['public_id'], 'submission_zip_item', 'submission_zip_staging', 'quarantined' );
		}
		$rows = $this->public_rows( self::TABLE_PRIVATE_FILES, array_keys( $specs ) );
		foreach ( $rows as $id => $row ) {
			$spec = $specs[ $id ];
			$this->assert_mutable( $row, (int) $run['execution_actor_user_id'], 0 );
			if (
				$spec['owner_type'] !== ( $row['owner_type'] ?? null ) || $spec['owner_public_id'] !== ( $row['owner_public_id'] ?? null )
				|| $spec['purpose'] !== ( $row['purpose'] ?? null ) || $spec['retention_class'] !== ( $row['retention_class'] ?? null )
				|| $spec['state'] !== ( $row['state'] ?? null ) || 'clean' !== ( $row['scan_state'] ?? null )
				|| null !== ( $row['legal_hold_until_utc'] ?? null ) || null !== ( $row['purged_at_utc'] ?? null )
				|| ! is_string( $row['storage_key'] ?? null ) || '' === $row['storage_key']
			) {
				$this->target_changed();
			}
			$rows[ $id ]['rollback_spec'] = $spec;
		}
		$owned        = $this->rows_where_in( self::TABLE_PRIVATE_FILES, 'owner_public_id', $this->all_public_ids( $plan ) );
		$active_owned = array();
		foreach ( $owned as $row ) {
			if ( null === ( $row['purged_at_utc'] ?? null ) ) {
				$active_owned[] = (string) ( $row['public_id'] ?? '' );
			}
		}
		$this->assert_same_strings( $active_owned, array_keys( $specs ) );
		return array_values( $rows );
	}

	/**
	 * Reject invalid external references.
	 *
	 * @param array $plan Plan.
	 * @return void
	 */
	private function assert_external_references( array $plan ): void {
		$all_ids = $this->all_public_ids( $plan );
		if ( array() === $all_ids ) {
			return;
		}
		foreach ( array(
			array( self::TABLE_REPORTS, 'subject_public_id' ),
			array( self::TABLE_EXPORTS, 'source_public_id' ),
			array( self::TABLE_PDFS, 'source_public_id' ),
		) as $reference ) {
			if ( array() !== $this->rows_where_in( $reference[0], $reference[1], $all_ids ) ) {
				$this->target_changed();
			}
		}
		$revision_ids = $this->public_ids( $plan[ self::TABLE_WORKSHEET_REVISIONS ] );
		if ( array() !== $this->rows_where_in( self::TABLE_WORKSHEET_EVALUATIONS, 'worksheet_revision_public_id', $revision_ids ) ) {
			$this->target_changed();
		}
	}

	/**
	 * Purge private files.
	 *
	 * @param array<int,array<string,mixed>> $files Files.
	 * @param array<string,mixed>            $record Record.
	 * @return int
	 */
	private function purge_private_files( array $files, array $record ): int {
		$count = 0;
		foreach ( $files as $file ) {
			// Missing bytes are accepted so a crash after unlink remains recoverable.
			$this->private_files->delete( (string) $file['storage_key'] );
			$spec    = $file['rollback_spec'];
			$changed = $this->query_count(
				$this->prepare(
					'UPDATE ' . self::TABLE_PRIVATE_FILES . ' SET state = %s, lock_version = lock_version + 1, '
					. 'updated_by_user_id = %d, updated_at_utc = %s, expires_at_utc = NULL, purged_at_utc = %s '
					. 'WHERE id = %d AND public_id = %s AND lock_version = %d AND state = %s AND scan_state = %s '
					. 'AND owner_type = %s AND owner_public_id = %s AND purpose = %s AND retention_class = %s '
					. 'AND legal_hold_until_utc IS NULL AND purged_at_utc IS NULL',
					array(
						'purged',
						$record['actor_user_id'],
						$record['compensated_at_utc'],
						$record['compensated_at_utc'],
						$file['id'],
						$file['public_id'],
						$file['lock_version'],
						$spec['state'],
						'clean',
						$spec['owner_type'],
						$spec['owner_public_id'],
						$spec['purpose'],
						$spec['retention_class'],
					)
				)
			);
			if ( 1 !== $changed ) {
				$this->target_changed();
			}
			++$count;
		}
		return $count;
	}

	/**
	 * Delete targets.
	 *
	 * @param array $plan Plan.
	 * @return int
	 */
	private function delete_targets( array $plan ): int {
		$count = 0;
		$order = array(
			self::TABLE_EVALUATION_ATTEMPTS,
			self::TABLE_EVALUATION_ITEMS,
			self::TABLE_NOTES,
			self::TABLE_RELEASES,
			self::TABLE_FEEDBACK,
			self::TABLE_EVALUATION_REVIEWS,
			self::TABLE_EVALUATION_RESULTS,
			self::TABLE_EVALUATION_INPUTS,
			self::TABLE_EVALUATION_RUNS,
			self::TABLE_EVALUATIONS,
			self::TABLE_SUBMISSION_RECEIPTS,
			self::TABLE_ATTACHMENTS,
			self::TABLE_SUBMISSION_REVISIONS,
			self::TABLE_SUBMISSIONS,
			self::TABLE_ZIP_ITEMS,
			self::TABLE_ZIPS,
			self::TABLE_ASSIGNMENT_TARGETS,
			self::TABLE_ASSIGNMENTS,
			self::TABLE_PUBLICATIONS,
			self::TABLE_QUESTION_LINKS,
			self::TABLE_WORKSHEET_LINKS,
			self::TABLE_QUESTIONS,
			self::TABLE_WORKSHEET_REVISIONS,
			self::TABLE_WORKSHEETS,
			self::TABLE_STANDARDS,
			self::TABLE_CURRICULUMS,
		);
		foreach ( $order as $table ) {
			$rows = $plan[ $table ] ?? array();
			if ( array() === $rows ) {
				continue;
			}
			$ids     = $this->database_ids( $rows );
			$sql     = 'DELETE FROM ' . $table . ' WHERE id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
			$changed = $this->query_count( $this->prepare( $sql, $ids ) );
			if ( count( $ids ) !== $changed ) {
				$this->target_changed();
			}
			$count += $changed;
		}
		return $count;
	}

	/**
	 * Update run.
	 *
	 * @param array<string,mixed> $run Run.
	 * @param array<string,mixed> $record Record.
	 * @param string              $checkpoint_json Checkpoint json.
	 * @param string              $checkpoint_hash Checkpoint hash.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function update_run( array $run, array $record, string $checkpoint_json, string $checkpoint_hash ): void {
		$changed = $this->query_count(
			$this->prepare(
				'UPDATE ' . self::TABLE_RUNS . ' SET state = %s, lock_version = lock_version + 1, updated_by_user_id = %d, '
				. 'updated_at_utc = %s, checkpoint_json = %s, checkpoint_hash = %s, error_code = NULL '
				. 'WHERE id = %d AND public_id = %s AND lock_version = %d AND state = %s AND checkpoint_hash = %s AND updated_at_utc = %s',
				array(
					'compensated',
					$record['actor_user_id'],
					$record['compensated_at_utc'],
					$checkpoint_json,
					$checkpoint_hash,
					$run['id'],
					$record['run_public_id'],
					$run['lock_version'],
					$record['expected_state'],
					$record['expected_checkpoint_hash'],
					$record['expected_updated_at_utc'],
				)
			)
		);
		if ( 1 !== $changed ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::RUN_STATE_CONFLICT );
		}
	}

	/**
	 * Insert audit.
	 *
	 * @param array<string,mixed> $run Run.
	 * @param array<string,mixed> $record Record.
	 * @param string              $checkpoint_hash Checkpoint hash.
	 * @param int                 $target_count Target count.
	 * @return void
	 */
	private function insert_audit( array $run, array $record, string $checkpoint_hash, int $target_count ): void {
		$previous = $this->scalar( 'SELECT event_hash FROM ' . self::TABLE_AUDIT . ' ORDER BY id DESC LIMIT 1 FOR UPDATE' );
		if ( null !== $previous ) {
			$this->hash_value( (string) $previous );
		}
		$metadata_json = $this->canonical->encode(
			array(
				'command_id'               => $record['command_id'],
				'compensated_target_count' => $target_count,
				'previous_checkpoint_hash' => $run['checkpoint_hash'],
				'request_digest'           => $record['request_digest'],
			)
		);
		$metadata_hash = hash( 'sha256', $metadata_json );
		$event_hash    = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'action_key'          => self::EVENT_COMPENSATED,
					'actor_user_id'       => $record['actor_user_id'],
					'after_digest'        => $checkpoint_hash,
					'before_digest'       => $run['checkpoint_hash'],
					'correlation_id'      => $record['correlation_id'],
					'metadata_hash'       => $metadata_hash,
					'object_public_id'    => $record['run_public_id'],
					'object_type'         => self::AGGREGATE_TYPE,
					'occurred_at_utc'     => $record['compensated_at_utc'],
					'previous_event_hash' => $previous,
				)
			)
		);
		$this->insert_or_fail(
			self::TABLE_AUDIT,
			array(
				'public_id'           => $record['audit_public_id'],
				'occurred_at_utc'     => $record['compensated_at_utc'],
				'actor_user_id'       => $record['actor_user_id'],
				'action_key'          => self::EVENT_COMPENSATED,
				'object_type'         => self::AGGREGATE_TYPE,
				'object_public_id'    => $record['run_public_id'],
				'context_type'        => null,
				'context_id'          => null,
				'context_revision'    => null,
				'correlation_id'      => $record['correlation_id'],
				'before_digest'       => $run['checkpoint_hash'],
				'after_digest'        => $checkpoint_hash,
				'metadata_schema'     => self::AUDIT_SCHEMA,
				'metadata_version'    => 1,
				'metadata_json'       => $metadata_json,
				'metadata_hash'       => $metadata_hash,
				'previous_event_hash' => $previous,
				'event_hash'          => $event_hash,
			)
		);
	}

	/**
	 * Insert outbox.
	 *
	 * @param array<string,mixed>              $record Record.
	 * @param Rollback_Legacy_Migration_Result $result Result.
	 * @return void
	 */
	private function insert_outbox( array $record, Rollback_Legacy_Migration_Result $result ): void {
		$payload_json = $this->canonical->encode(
			array(
				'command_id'     => $record['command_id'],
				'event'          => self::EVENT_COMPENSATED,
				'request_digest' => $record['request_digest'],
				'receipt'        => $this->receipt_payload( $result ),
			)
		);
		$this->insert_or_fail(
			self::TABLE_OUTBOX,
			array(
				'public_id'            => $record['outbox_public_id'],
				'lock_version'         => 0,
				'created_by_user_id'   => $record['actor_user_id'],
				'created_at_utc'       => $record['compensated_at_utc'],
				'updated_by_user_id'   => $record['actor_user_id'],
				'updated_at_utc'       => $record['compensated_at_utc'],
				'aggregate_type'       => self::AGGREGATE_TYPE,
				'aggregate_public_id'  => $record['run_public_id'],
				'event_type'           => self::EVENT_COMPENSATED,
				'idempotency_key'      => $record['command_storage_key'],
				'payload_schema'       => self::OUTBOX_SCHEMA,
				'payload_version'      => 1,
				'payload_json'         => $payload_json,
				'payload_hash'         => hash( 'sha256', $payload_json ),
				'available_at_utc'     => $record['compensated_at_utc'],
				'published_at_utc'     => null,
				'lease_token'          => null,
				'lease_expires_at_utc' => null,
				'attempt_count'        => 0,
				'last_error_code'      => null,
			)
		);
	}

	/**
	 * Command receipt.
	 *
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @param bool   $for_update For update.
	 * @return Rollback_Legacy_Migration_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function command_receipt( string $key, string $digest, bool $for_update ): ?Rollback_Legacy_Migration_Result {
		$this->storage_key( $key );
		$this->hash_value( $digest );
		$sql = 'SELECT aggregate_public_id, event_type, payload_schema, payload_version, payload_json, payload_hash FROM '
			. self::TABLE_OUTBOX . ' WHERE idempotency_key = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = $this->row( $this->prepare( $sql, array( $key ) ) );
		if ( null === $row ) {
			return null;
		}
		if (
			self::EVENT_COMPENSATED !== ( $row['event_type'] ?? null ) || self::OUTBOX_SCHEMA !== ( $row['payload_schema'] ?? null )
			|| 1 !== $this->positive_integer( $row['payload_version'] ?? null )
		) {
			$this->stored_invalid();
		}
		$payload = $this->decode_stored( (string) $row['payload_json'], (string) $row['payload_hash'] );
		$this->exact_keys( $payload, array( 'command_id', 'event', 'receipt', 'request_digest' ) );
		if ( ! hash_equals( $digest, (string) ( $payload['request_digest'] ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::IDEMPOTENCY_CONFLICT );
		}
		$receipt = $payload['receipt'] ?? null;
		if ( ! is_array( $receipt ) || array_is_list( $receipt ) || ( $row['aggregate_public_id'] ?? null ) !== ( $receipt['run_id'] ?? null ) ) {
			$this->stored_invalid();
		}
		return $this->result_from_receipt( $receipt, true );
	}

	/**
	 * Result.
	 *
	 * @param array<string,mixed> $run Run.
	 * @param string              $checkpoint_hash Checkpoint hash.
	 * @param int                 $count Count.
	 * @param string              $at At.
	 * @param bool                $replayed Replayed.
	 * @return Rollback_Legacy_Migration_Result
	 */
	private function result( array $run, string $checkpoint_hash, int $count, string $at, bool $replayed ): Rollback_Legacy_Migration_Result {
		return new Rollback_Legacy_Migration_Result(
			(string) $run['public_id'],
			'compensated',
			$checkpoint_hash,
			(int) $run['item_total'],
			(int) $run['success_total'],
			(int) $run['failure_total'],
			(int) $run['skipped_total'],
			$count,
			$at,
			$replayed
		);
	}

	/**
	 * Result from receipt.
	 *
	 * @param array<string,mixed> $receipt Receipt.
	 * @param bool                $replayed Replayed.
	 * @return Rollback_Legacy_Migration_Result
	 */
	private function result_from_receipt( array $receipt, bool $replayed ): Rollback_Legacy_Migration_Result {
		$this->exact_keys(
			$receipt,
			array(
				'checkpoint_hash',
				'compensated_at_utc',
				'compensated_target_count',
				'failure_total',
				'item_total',
				'run_id',
				'skipped_total',
				'state',
				'success_total',
			)
		);
		return new Rollback_Legacy_Migration_Result(
			(string) ( $receipt['run_id'] ?? '' ),
			(string) ( $receipt['state'] ?? '' ),
			(string) ( $receipt['checkpoint_hash'] ?? '' ),
			(int) ( $receipt['item_total'] ?? -1 ),
			(int) ( $receipt['success_total'] ?? -1 ),
			(int) ( $receipt['failure_total'] ?? -1 ),
			(int) ( $receipt['skipped_total'] ?? -1 ),
			(int) ( $receipt['compensated_target_count'] ?? -1 ),
			(string) ( $receipt['compensated_at_utc'] ?? '' ),
			$replayed
		);
	}

	/**
	 * Receipt payload.
	 *
	 * @param Rollback_Legacy_Migration_Result $result Result.
	 * @return array<string,mixed>
	 */
	private function receipt_payload( Rollback_Legacy_Migration_Result $result ): array {
		return array(
			'run_id'                   => $result->run_id(),
			'state'                    => $result->state(),
			'checkpoint_hash'          => $result->checkpoint_hash(),
			'item_total'               => $result->item_total(),
			'success_total'            => $result->success_total(),
			'failure_total'            => $result->failure_total(),
			'skipped_total'            => $result->skipped_total(),
			'compensated_target_count' => $result->compensated_target_count(),
			'compensated_at_utc'       => $result->compensated_at_utc(),
		);
	}

	/**
	 * Reject invalid record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 */
	private function assert_record( array $record ): void {
		$this->exact_keys(
			$record,
			array(
				'actor_user_id',
				'audit_public_id',
				'command_id',
				'command_storage_key',
				'compensated_at_utc',
				'correlation_id',
				'expected_checkpoint_hash',
				'expected_state',
				'expected_updated_at_utc',
				'outbox_public_id',
				'request_digest',
				'run_public_id',
				'site_id',
			)
		);
		if (
			! is_int( $record['actor_user_id'] ) || $record['actor_user_id'] < 1
			|| ! is_string( $record['site_id'] ) || 1 !== preg_match( '/^[1-9][0-9]{0,18}$/D', $record['site_id'] )
			|| ! in_array( $record['expected_state'], array( 'completed', 'completed_with_errors' ), true )
		) {
			$this->stored_invalid();
		}
		foreach ( array( 'audit_public_id', 'command_id', 'outbox_public_id', 'run_public_id' ) as $key ) {
			new Public_Id( (string) $record[ $key ] );
		}
		foreach ( array( 'expected_checkpoint_hash', 'request_digest' ) as $key ) {
			$this->hash_value( (string) $record[ $key ] );
		}
		$this->storage_key( (string) $record['command_storage_key'] );
		$this->safe_token( (string) $record['correlation_id'], 8, 128 );
		$this->utc( $record['expected_updated_at_utc'] );
		$this->utc( $record['compensated_at_utc'] );
	}

	/**
	 * Reject invalid checkpoint.
	 *
	 * @param array<string,mixed> $checkpoint Checkpoint.
	 * @param string              $state State.
	 * @param array<string,mixed> $run Run.
	 * @return void
	 */
	private function assert_checkpoint( array $checkpoint, string $state, array $run ): void {
		$this->exact_keys( $checkpoint, array( 'batch_size', 'cursor', 'phase', 'plan_hash', 'profile', 'source', 'totals' ) );
		$source = $checkpoint['source'] ?? null;
		$cursor = $checkpoint['cursor'] ?? null;
		$totals = $checkpoint['totals'] ?? null;
		if ( ! is_array( $source ) || ! is_array( $cursor ) || ! is_array( $totals ) ) {
			$this->stored_invalid();
		}
		$this->exact_keys( $source, array( 'fingerprint', 'profile' ) );
		$this->exact_keys( $cursor, array( 'after_source_id', 'next_item_no', 'table_index' ) );
		$this->exact_keys( $totals, array( 'failure', 'skipped', 'success' ) );
		if (
			Execute_Legacy_Migration::CHECKPOINT_PROFILE !== ( $checkpoint['profile'] ?? null ) || $state !== ( $checkpoint['phase'] ?? null )
			|| Execute_Legacy_Migration::BATCH_SIZE !== ( $checkpoint['batch_size'] ?? null )
			|| $run['success_total'] !== ( $totals['success'] ?? null ) || $run['failure_total'] !== ( $totals['failure'] ?? null )
			|| $run['skipped_total'] !== ( $totals['skipped'] ?? null ) || $run['item_total'] + 1 !== ( $cursor['next_item_no'] ?? null )
		) {
			$this->stored_invalid();
		}
		$this->hash_value( (string) ( $checkpoint['plan_hash'] ?? '' ) );
		$this->hash_value( (string) ( $source['fingerprint'] ?? '' ) );
	}

	/**
	 * Receipts.
	 *
	 * @param array  $inventory Inventory.
	 * @param string $type Type.
	 * @return array<int,array<string,mixed>>
	 */
	private function receipts( array $inventory, string $type ): array {
		return $inventory['receipts'][ $type ] ?? array();
	}

	/**
	 * Root rows.
	 *
	 * @param string      $table Table.
	 * @param string      $type Type.
	 * @param array       $inventory Inventory.
	 * @param int         $actor Actor.
	 * @param int|null    $expected_lock Expected lock.
	 * @param string|null $hash_column Hash column.
	 * @return array<int,array<string,mixed>>
	 */
	private function root_rows( string $table, string $type, array $inventory, int $actor, ?int $expected_lock, ?string $hash_column = null ): array {
		$receipts = $this->receipts( $inventory, $type );
		$ids      = array_map( static fn ( array $receipt ): string => (string) $receipt['target']['public_id'], $receipts );
		$rows     = $this->public_rows( $table, $ids );
		foreach ( $receipts as $receipt ) {
			$id  = (string) $receipt['target']['public_id'];
			$row = $rows[ $id ] ?? null;
			if ( ! is_array( $row ) ) {
				$this->target_changed();
			}
			if ( null !== $expected_lock ) {
				$this->assert_mutable( $row, $actor, $expected_lock ); } else {
				$this->assert_created_by( $row, $actor );
				}
				if ( null !== $hash_column && ! hash_equals( (string) $receipt['target']['content_hash'], (string) ( $row[ $hash_column ] ?? '' ) ) ) {
					$this->target_changed();
				}
		}
		return array_values( $rows );
	}

	/**
	 * Reject invalid mutable.
	 *
	 * @param array $row Row.
	 * @param int   $actor Actor.
	 * @param int   $expected_lock Expected lock.
	 * @return void
	 */
	private function assert_mutable( array $row, int $actor, int $expected_lock ): void {
		$this->assert_created_by( $row, $actor );
		if ( $actor !== $this->positive_integer( $row['updated_by_user_id'] ?? null ) || $expected_lock !== $this->non_negative_integer( $row['lock_version'] ?? null ) ) {
			$this->target_changed();
		}
	}

	/**
	 * Reject invalid created by.
	 *
	 * @param array $row Row.
	 * @param int   $actor Actor.
	 * @return void
	 */
	private function assert_created_by( array $row, int $actor ): void {
		if ( $actor !== $this->positive_integer( $row['created_by_user_id'] ?? null ) ) {
			$this->target_changed();
		}
		$this->utc( $row['created_at_utc'] ?? null );
	}

	/**
	 * Reject invalid parent set.
	 *
	 * @param array  $children Children.
	 * @param string $column Column.
	 * @param array  $parents Parents.
	 * @return void
	 */
	private function assert_parent_set( array $children, string $column, array $parents ): void {
		$ids = array_fill_keys( $this->database_ids( $parents ), true );
		foreach ( $children as $row ) {
			if ( ! isset( $ids[ (int) ( $row[ $column ] ?? 0 ) ] ) ) {
				$this->target_changed();
			}
		}
	}

	/**
	 * Reject invalid exact children.
	 *
	 * @param string $table Table.
	 * @param string $column Column.
	 * @param array  $parents Parents.
	 * @param array  $expected Expected.
	 * @return void
	 */
	private function assert_exact_children( string $table, string $column, array $parents, array $expected ): void {
		$actual = $this->children( $table, $column, $parents );
		$this->assert_same_integers( $this->database_ids( $actual ), $this->database_ids( $expected ) );
	}

	/**
	 * Reject invalid standard link ownership.
	 *
	 * @param array $plan Plan.
	 * @return void
	 */
	private function assert_standard_link_ownership( array $plan ): void {
		$standards = $this->public_ids( $plan[ self::TABLE_STANDARDS ] );
		$expected  = array_merge( $this->database_ids( $plan[ self::TABLE_WORKSHEET_LINKS ] ), $this->database_ids( $plan[ self::TABLE_QUESTION_LINKS ] ) );
		$actual    = array_merge(
			$this->database_ids( $this->rows_where_in( self::TABLE_WORKSHEET_LINKS, 'standard_public_id', $standards ) ),
			$this->database_ids( $this->rows_where_in( self::TABLE_QUESTION_LINKS, 'standard_public_id', $standards ) )
		);
		$this->assert_same_integers( $actual, $expected );
	}

	/**
	 * Reject invalid assignment publications.
	 *
	 * @param array $plan Plan.
	 * @param array $inventory Inventory.
	 * @param int   $actor Actor.
	 * @return void
	 */
	private function assert_assignment_publications( array $plan, array $inventory, int $actor ): void {
		$publications = array();
		foreach ( $plan[ self::TABLE_PUBLICATIONS ] as $row ) {
			$publications[ $row['public_id'] ] = $row;
		}
		$assignments = array();
		foreach ( $plan[ self::TABLE_ASSIGNMENTS ] as $row ) {
			$assignments[ $row['public_id'] ] = $row;
		}
		$revisions = $this->keyed( $plan[ self::TABLE_WORKSHEET_REVISIONS ] );
		foreach ( $this->receipts( $inventory, 'assignment' ) as $receipt ) {
			$metadata    = $receipt['target']['metadata'];
			$assignment  = $assignments[ $receipt['target']['public_id'] ] ?? null;
			$publication = $publications[ $metadata['publication_public_id'] ?? '' ] ?? null;
			if (
				! is_array( $assignment ) || ! is_array( $publication )
				|| ! isset( $revisions[ $this->positive_integer( $publication['worksheet_revision_id'] ?? null ) ] )
				|| ( $assignment['worksheet_publication_public_id'] ?? null ) !== ( $publication['public_id'] ?? null )
				|| $revisions[ (int) $publication['worksheet_revision_id'] ]['worksheet_id'] !== ( $publication['worksheet_id'] ?? null )
				|| 'retired' !== ( $publication['state'] ?? null )
				|| $actor !== $this->positive_integer( $publication['created_by_user_id'] ?? null )
				|| $actor !== $this->positive_integer( $publication['retired_by_user_id'] ?? null )
				|| ! hash_equals( (string) ( $metadata['publication_hash'] ?? '' ), (string) ( $publication['content_hash'] ?? '' ) )
				|| ! hash_equals( (string) ( $metadata['publication_hash'] ?? '' ), (string) ( $publication['publication_hash'] ?? '' ) )
				|| ! hash_equals( (string) ( $metadata['publication_hash'] ?? '' ), (string) ( $assignment['publication_hash'] ?? '' ) )
				|| ! hash_equals( (string) ( $metadata['schedule_hash'] ?? '' ), (string) ( $assignment['schedule_hash'] ?? '' ) )
				|| ! hash_equals( (string) ( $metadata['target_snapshot_hash'] ?? '' ), (string) ( $assignment['target_snapshot_hash'] ?? '' ) )
			) {
				$this->target_changed();
			}
			$created = $this->utc( $publication['created_at_utc'] ?? null );
			if ( $created !== $this->utc( $publication['published_at_utc'] ?? null ) || $created !== $this->utc( $publication['retired_at_utc'] ?? null ) ) {
				$this->target_changed();
			}
		}
		$actual = $this->rows_where_in( self::TABLE_ASSIGNMENTS, 'worksheet_publication_public_id', array_keys( $publications ) );
		$this->assert_same_integers( $this->database_ids( $actual ), $this->database_ids( $plan[ self::TABLE_ASSIGNMENTS ] ) );
	}

	/**
	 * Reject invalid submission children.
	 *
	 * @param array $plan Plan.
	 * @param array $inventory Inventory.
	 * @return void
	 */
	private function assert_submission_children( array $plan, array $inventory ): void {
		$revision_ids    = array();
		$revision_hashes = array();
		$receipt_ids     = array();
		$receipt_hashes  = array();
		$attachment_ids  = array();
		foreach ( $this->receipts( $inventory, 'submission' ) as $receipt ) {
			$metadata = $receipt['target']['metadata'];
			$this->merge_id_hash_map( $revision_ids, $revision_hashes, $metadata['revision_public_ids'] ?? null, $metadata['revision_target_hashes'] ?? null );
			$this->merge_id_hash_map( $receipt_ids, $receipt_hashes, $metadata['receipt_public_ids'] ?? null, $metadata['receipt_hashes'] ?? null );
		}
		foreach ( $this->receipts( $inventory, 'submission_attachment' ) as $receipt ) {
			$attachment_ids[] = (string) $receipt['target']['public_id'];
		}
		$this->assert_public_hash_map( $plan[ self::TABLE_SUBMISSION_REVISIONS ], $revision_ids, $revision_hashes );
		$this->assert_public_hash_map( $plan[ self::TABLE_SUBMISSION_RECEIPTS ], $receipt_ids, $receipt_hashes );
		$this->assert_public_set( $plan[ self::TABLE_ATTACHMENTS ], $attachment_ids );
		$this->assert_parent_set( $plan[ self::TABLE_ATTACHMENTS ], 'submission_id', $plan[ self::TABLE_SUBMISSIONS ] );
		$this->assert_exact_children( self::TABLE_ATTACHMENTS, 'submission_id', $plan[ self::TABLE_SUBMISSIONS ], $plan[ self::TABLE_ATTACHMENTS ] );
		$attachments = array();
		foreach ( $plan[ self::TABLE_ATTACHMENTS ] as $row ) {
			$attachments[ $row['public_id'] ] = $row;
		}
		foreach ( $this->receipts( $inventory, 'submission_attachment' ) as $receipt ) {
			$metadata = $receipt['target']['metadata'];
			$row      = $attachments[ $receipt['target']['public_id'] ] ?? null;
			if ( ! is_array( $row ) || ( $row['file_public_id'] ?? null ) !== ( $metadata['file_public_id'] ?? null ) || ( $row['response_key'] ?? null ) !== ( $metadata['response_public_id'] ?? null ) ) {
				$this->target_changed();
			}
			$lock = $this->non_negative_integer( $row['lock_version'] ?? null );
			if ( ! ( 0 === $lock && 'ready' === ( $row['state'] ?? null ) ) && ! ( 1 === $lock && 'bound' === ( $row['state'] ?? null ) ) ) {
				$this->target_changed();
			}
		}
	}

	/**
	 * Reject invalid zip children.
	 *
	 * @param array $plan Plan.
	 * @param array $inventory Inventory.
	 * @return void
	 */
	private function assert_zip_children( array $plan, array $inventory ): void {
		$expected = array();
		foreach ( $this->receipts( $inventory, 'submission_zip_item' ) as $receipt ) {
			$metadata         = $receipt['target']['metadata'];
			$key              = (string) $receipt['target']['public_id'] . ':' . $this->positive_integer( $metadata['zip_item_number'] ?? null );
			$expected[ $key ] = $metadata['file_public_id'] ?? null;
		}
		$zips = $this->keyed( $plan[ self::TABLE_ZIPS ] );
		foreach ( $plan[ self::TABLE_ZIP_ITEMS ] as $row ) {
			$zip = $zips[ (int) $row['zip_upload_id'] ] ?? null;
			$key = is_array( $zip ) ? $zip['public_id'] . ':' . $row['item_no'] : '';
			if ( ! array_key_exists( $key, $expected ) || ( $row['file_public_id'] ?? null ) !== $expected[ $key ] || null !== ( $row['result_submission_public_id'] ?? null ) || 0 !== $this->non_negative_integer( $row['lock_version'] ?? null ) ) {
				$this->target_changed();
			}
			unset( $expected[ $key ] );
		}
		if ( array() !== $expected ) {
			$this->target_changed();
		}
		foreach ( $this->receipts( $inventory, 'submission_zip' ) as $receipt ) {
			$row           = $this->find_public( $plan[ self::TABLE_ZIPS ], (string) $receipt['target']['public_id'] );
			$metadata      = $receipt['target']['metadata'];
			$finalized     = array_key_exists( 'ready_count', $metadata );
			$expected_lock = $finalized ? 1 : 0;
			if (
				$expected_lock !== $this->non_negative_integer( $row['lock_version'] ?? null )
				|| ( $row['file_public_id'] ?? null ) !== ( $metadata['file_public_id'] ?? null )
				|| ! hash_equals( (string) ( $row['mapping_hash'] ?? '' ), (string) ( $metadata['mapping_hash'] ?? '' ) )
				|| ( $finalized ? 'ready' : 'scanning' ) !== ( $row['state'] ?? null )
			) {
				$this->target_changed();
			}
		}
	}

	/**
	 * Reject invalid evaluation roots.
	 *
	 * @param array $plan Plan.
	 * @return void
	 */
	private function assert_evaluation_roots( array $plan ): void {
		$revisions = array_fill_keys( $this->public_ids( $plan[ self::TABLE_SUBMISSION_REVISIONS ] ), true );
		foreach ( $plan[ self::TABLE_EVALUATIONS ] as $row ) {
			if ( ! isset( $revisions[ (string) ( $row['submission_revision_public_id'] ?? '' ) ] ) ) {
				$this->target_changed();
			}
		}
		$actual = $this->rows_where_in( self::TABLE_EVALUATIONS, 'submission_revision_public_id', array_keys( $revisions ) );
		$this->assert_same_integers( $this->database_ids( $actual ), $this->database_ids( $plan[ self::TABLE_EVALUATIONS ] ) );
	}

	/**
	 * Reject invalid evaluation children.
	 *
	 * @param array $plan Plan.
	 * @param array $inventory Inventory.
	 * @param int   $actor Actor.
	 * @return void
	 */
	private function assert_evaluation_children( array $plan, array $inventory, int $actor ): void {
		if ( count( $plan[ self::TABLE_EVALUATION_INPUTS ] ) !== count( $plan[ self::TABLE_EVALUATIONS ] ) ) {
			$this->target_changed();
		}
		$expected = array(
			self::TABLE_EVALUATION_RUNS    => $this->evaluation_metadata_ids( $inventory, 'assessment_run_public_ids' ),
			self::TABLE_EVALUATION_RESULTS => $this->evaluation_metadata_ids( $inventory, 'assessment_result_public_ids' ),
			self::TABLE_EVALUATION_REVIEWS => $this->evaluation_metadata_ids( $inventory, 'regrade_review_public_ids' ),
			self::TABLE_FEEDBACK           => $this->evaluation_metadata_ids( $inventory, 'feedback_public_ids' ),
			self::TABLE_RELEASES           => $this->evaluation_metadata_ids( $inventory, 'release_public_ids' ),
			self::TABLE_NOTES              => $this->evaluation_metadata_ids( $inventory, 'special_note_public_ids' ),
		);
		foreach ( $expected as $table => $ids ) {
			$this->assert_public_set( $plan[ $table ], $ids );
		}
		$item_count = 0;
		foreach ( $this->receipts( $inventory, 'submission_evaluation' ) as $receipt ) {
			$hashes = $receipt['target']['metadata']['grading_item_source_hashes'] ?? null;
			if ( ! is_array( $hashes ) ) {
				$this->stored_invalid();
			}
			$item_count += count( $hashes );
		}
		if ( count( $plan[ self::TABLE_EVALUATION_ITEMS ] ) !== $item_count ) {
			$this->target_changed();
		}
		foreach ( $plan[ self::TABLE_EVALUATION_RUNS ] as $row ) {
			$this->assert_mutable( $row, $actor, 0 );
			if ( 'submission' !== ( $row['subject_type'] ?? null ) ) {
				$this->target_changed();
			}
		}
		$this->assert_evaluation_hash_map( $plan[ self::TABLE_EVALUATION_RESULTS ], $inventory, 'assessment_result_public_ids', 'assessment_result_target_hashes' );
		$this->assert_evaluation_hash_map( $plan[ self::TABLE_EVALUATION_REVIEWS ], $inventory, 'regrade_review_public_ids', 'regrade_review_target_hashes' );
		$this->assert_evaluation_hash_map( $plan[ self::TABLE_FEEDBACK ], $inventory, 'feedback_public_ids', 'feedback_target_hashes' );
		$this->assert_evaluation_hash_map( $plan[ self::TABLE_RELEASES ], $inventory, 'release_public_ids', 'release_target_hashes' );
		$this->assert_evaluation_hash_map( $plan[ self::TABLE_NOTES ], $inventory, 'special_note_public_ids', 'special_note_target_hashes' );
		$actual_runs = $this->rows_where_in( self::TABLE_EVALUATION_RUNS, 'evaluation_public_id', $this->public_ids( $plan[ self::TABLE_EVALUATIONS ] ) );
		$this->assert_same_integers( $this->database_ids( $actual_runs ), $this->database_ids( $plan[ self::TABLE_EVALUATION_RUNS ] ) );
	}

	/**
	 * Reject invalid submission locks and pointers.
	 *
	 * @param array $plan Plan.
	 * @param array $inventory Inventory.
	 * @return void
	 */
	private function assert_submission_locks_and_pointers( array $plan, array $inventory ): void {
		$expected            = array();
		$attachments_by_root = array();
		foreach ( $plan[ self::TABLE_ATTACHMENTS ] as $attachment ) {
			$submission_id                           = $this->positive_integer( $attachment['submission_id'] ?? null );
			$attachments_by_root[ $submission_id ][] = $attachment;
		}
		foreach ( $this->receipts( $inventory, 'submission' ) as $receipt ) {
			$metadata         = $receipt['target']['metadata'];
			$id               = (string) $receipt['target']['public_id'];
			$submission       = $this->find_public( $plan[ self::TABLE_SUBMISSIONS ], $id );
			$attachments      = $attachments_by_root[ $this->positive_integer( $submission['id'] ?? null ) ] ?? array();
			$attachment_count = $this->non_negative_integer( $metadata['attachment_count'] ?? null );
			$deferred_count   = $this->non_negative_integer( $metadata['deferred_attachment_count'] ?? null );
			$finalized        = 0 < $attachment_count && count( $attachments ) === $attachment_count;
			foreach ( $attachments as $attachment ) {
				$lock  = $this->non_negative_integer( $attachment['lock_version'] ?? null );
				$bound = 1 === $lock && 'bound' === ( $attachment['state'] ?? null );
				$ready = 0 === $lock && 'ready' === ( $attachment['state'] ?? null );
				if ( ( $finalized && ! $bound ) || ( ! $finalized && ! $ready ) ) {
					$this->target_changed();
				}
			}
			if ( ( $finalized && 0 !== $deferred_count ) || ( ! $finalized && 0 < $attachment_count && 0 === $deferred_count ) ) {
				$this->target_changed();
			}
			$expected[ $id ] = array(
				'lock'                        => $finalized ? 1 : 0,
				'current_revision_public_id'  => $metadata['current_revision_public_id'] ?? null,
				'official_revision_public_id' => $metadata['official_revision_public_id'] ?? null,
				'official_review_public_id'   => null,
				'official_review_hash'        => null,
				'current_release_public_id'   => null,
				'current_release_hash'        => null,
			);
		}
		foreach ( $this->receipts( $inventory, 'submission_evaluation' ) as $receipt ) {
			$metadata = $receipt['target']['metadata'];
			$id       = (string) ( $metadata['submission_public_id'] ?? '' );
			if ( ! isset( $expected[ $id ] ) ) {
				$this->target_changed();
			}
			if ( isset( $metadata['official_review_public_id'] ) ) {
				++$expected[ $id ]['lock'];
				$expected[ $id ]['official_review_public_id'] = $metadata['official_review_public_id'];
				$review                                       = $this->find_public( $plan[ self::TABLE_EVALUATION_REVIEWS ], (string) $metadata['official_review_public_id'] );
				$expected[ $id ]['official_review_hash']      = $review['content_hash'] ?? null;
			}
			if ( isset( $metadata['current_release_public_id'] ) ) {
				$expected[ $id ]['current_release_public_id'] = $metadata['current_release_public_id'];
				$expected[ $id ]['current_release_hash']      = $metadata['current_release_hash'] ?? null;
			}
		}
		foreach ( $plan[ self::TABLE_SUBMISSIONS ] as $row ) {
			$values = $expected[ $row['public_id'] ] ?? null;
			if ( ! is_array( $values ) || $values['lock'] !== $this->non_negative_integer( $row['lock_version'] ?? null ) ) {
				$this->target_changed();
			}
			foreach ( array( 'current_revision_public_id', 'official_revision_public_id', 'official_review_public_id', 'official_review_hash', 'current_release_public_id', 'current_release_hash' ) as $key ) {
				if ( $values[ $key ] !== ( $row[ $key ] ?? null ) ) {
					$this->target_changed();
				}
			}
		}
	}

	/**
	 * Reject invalid evaluation hash map.
	 *
	 * @param array  $rows Rows.
	 * @param array  $inventory Inventory.
	 * @param string $id_key Id key.
	 * @param string $hash_key Hash key.
	 * @return void
	 */
	private function assert_evaluation_hash_map( array $rows, array $inventory, string $id_key, string $hash_key ): void {
		$ids    = array();
		$hashes = array();
		foreach ( $this->receipts( $inventory, 'submission_evaluation' ) as $receipt ) {
			$metadata = $receipt['target']['metadata'];
			$this->merge_id_hash_map( $ids, $hashes, $metadata[ $id_key ] ?? null, $metadata[ $hash_key ] ?? null );
		}
		$this->assert_public_hash_map( $rows, $ids, $hashes );
	}

	/**
	 * Merge id hash map.
	 *
	 * @param array &$ids Ids.
	 * @param array &$hashes Hashes.
	 * @param mixed $new_ids New ids.
	 * @param mixed $new_hashes New hashes.
	 * @return void
	 */
	private function merge_id_hash_map( array &$ids, array &$hashes, mixed $new_ids, mixed $new_hashes ): void {
		if ( ! is_array( $new_ids ) || ! is_array( $new_hashes ) || array_keys( $new_ids ) !== array_keys( $new_hashes ) ) {
			$this->stored_invalid();
		}
		foreach ( $new_ids as $key => $id ) {
			$id = $this->public_id_value( $id );
			if ( isset( $hashes[ $id ] ) ) {
				$this->stored_invalid();
			} $ids[]       = $id;
			$hashes[ $id ] = $this->hash_value( (string) $new_hashes[ $key ] );
		}
	}

	/**
	 * Reject invalid public hash map.
	 *
	 * @param array $rows Rows.
	 * @param array $ids Ids.
	 * @param array $hashes Hashes.
	 * @return void
	 */
	private function assert_public_hash_map( array $rows, array $ids, array $hashes ): void {
		$this->assert_public_set( $rows, $ids );
		foreach ( $rows as $row ) {
			$id = (string) ( $row['public_id'] ?? '' );
			if ( ! isset( $hashes[ $id ] ) || ! hash_equals( $hashes[ $id ], (string) ( $row['content_hash'] ?? '' ) ) ) {
				$this->target_changed();
			}
		}
	}

	/**
	 * Reject invalid receipt hashes.
	 *
	 * @param array  $rows Rows.
	 * @param array  $receipts Receipts.
	 * @param string $id_key Id key.
	 * @param string $hash_key Hash key.
	 * @return void
	 */
	private function assert_receipt_hashes( array $rows, array $receipts, string $id_key, string $hash_key ): void {
		$expected = array();
		foreach ( $receipts as $receipt ) {
			$metadata = $receipt['target']['metadata'];
			$expected[ $this->public_id_value( $metadata[ $id_key ] ?? null ) ] = $this->hash_value( (string) ( $metadata[ $hash_key ] ?? '' ) );
		}
		foreach ( $rows as $row ) {
			$id = (string) $row['public_id'];
			if ( ! isset( $expected[ $id ] ) || ! hash_equals( $expected[ $id ], (string) ( $row['content_hash'] ?? '' ) ) ) {
				$this->target_changed();
			}
		}
	}

	/**
	 * Reject invalid direct receipt hashes.
	 *
	 * @param array $rows Rows.
	 * @param array $receipts Receipts.
	 * @return void
	 */
	private function assert_direct_receipt_hashes( array $rows, array $receipts ): void {
		$expected = array();
		foreach ( $receipts as $receipt ) {
			$expected[ $receipt['target']['public_id'] ] = $receipt['target']['content_hash'];
		}
		if ( count( $expected ) !== count( $rows ) ) {
			$this->target_changed();
		}
		foreach ( $rows as $row ) {
			$id = (string) $row['public_id'];
			if ( ! isset( $expected[ $id ] ) || ! hash_equals( $expected[ $id ], (string) $row['content_hash'] ) ) {
				$this->target_changed();
			}
		}
	}

	/**
	 * Reject invalid hash set.
	 *
	 * @param array $rows Rows.
	 * @param array $expected Expected.
	 * @return void
	 */
	private function assert_hash_set( array $rows, array $expected ): void {
		$this->assert_same_strings( array_map( static fn ( array $row ): string => (string) ( $row['content_hash'] ?? '' ), $rows ), $expected );
	}
	/**
	 * Reject invalid public set.
	 *
	 * @param array $rows Rows.
	 * @param array $expected Expected.
	 * @return void
	 */
	private function assert_public_set( array $rows, array $expected ): void {
		$this->assert_same_strings( $this->public_ids( $rows ), $expected );
	}

	/**
	 * Evaluation metadata ids.
	 *
	 * @param array  $inventory Inventory.
	 * @param string $key Key.
	 * @return array
	 */
	private function evaluation_metadata_ids( array $inventory, string $key ): array {
		$ids = array();
		foreach ( $this->receipts( $inventory, 'submission_evaluation' ) as $receipt ) {
			$ids = array_merge( $ids, $this->public_id_values( $receipt['target']['metadata'][ $key ] ?? null ) );
		}
		return $ids;
	}

	/**
	 * Add file spec.
	 *
	 * @param array  &$specs Specs.
	 * @param mixed  $id Id.
	 * @param string $owner_type Owner type.
	 * @param mixed  $owner_id Owner id.
	 * @param string $purpose Purpose.
	 * @param string $retention Retention.
	 * @param string $state State.
	 * @return void
	 */
	private function add_file_spec( array &$specs, mixed $id, string $owner_type, mixed $owner_id, string $purpose, string $retention, string $state ): void {
		$id       = $this->public_id_value( $id );
		$owner_id = $this->public_id_value( $owner_id );
		if ( isset( $specs[ $id ] ) ) {
			$this->stored_invalid();
		}
		$specs[ $id ] = array(
			'owner_type'      => $owner_type,
			'owner_public_id' => $owner_id,
			'purpose'         => $purpose,
			'retention_class' => $retention,
			'state'           => $state,
		);
	}

	/**
	 * Public rows.
	 *
	 * @param string $table Table.
	 * @param array  $ids Ids.
	 * @return array<string,array<string,mixed>>
	 */
	private function public_rows( string $table, array $ids ): array {
		$ids = array_values( array_unique( $ids ) );
		if ( array() === $ids ) {
			return array();
		}
		foreach ( $ids as $id ) {
			new Public_Id( (string) $id );
		}
		$rows   = $this->rows_where_in( $table, 'public_id', $ids );
		$result = array();
		foreach ( $rows as $row ) {
			$id = (string) ( $row['public_id'] ?? '' );
			if ( isset( $result[ $id ] ) ) {
				$this->stored_invalid();
			} $result[ $id ] = $row;
		}
		if ( count( $result ) !== count( $ids ) ) {
			$this->target_changed();
		}
		return $result;
	}

	/**
	 * Children.
	 *
	 * @param string $table Table.
	 * @param string $column Column.
	 * @param array  $parents Parents.
	 * @return array<int,array<string,mixed>>
	 */
	private function children( string $table, string $column, array $parents ): array {
		return $this->rows_where_in( $table, $column, $this->database_ids( $parents ) );
	}

	/**
	 * Rows where in.
	 *
	 * @param string $table Table.
	 * @param string $column Column.
	 * @param array  $values Values.
	 * @return array<int,array<string,mixed>>
	 */
	private function rows_where_in( string $table, string $column, array $values ): array {
		if ( array() === $values ) {
			return array();
		}
		if ( 1 !== preg_match( '/^oj_ai_[a-z0-9_]+$/D', $table ) || 1 !== preg_match( '/^[a-z][a-z0-9_]*$/D', $column ) ) {
			$this->stored_invalid();
		}
		$placeholder = is_int( reset( $values ) ) ? '%d' : '%s';
		$sql         = 'SELECT * FROM ' . $table . ' WHERE ' . $column . ' IN (' . implode( ',', array_fill( 0, count( $values ), $placeholder ) ) . ') ORDER BY id ASC FOR UPDATE';
		return $this->rows( $this->prepare( $sql, array_values( $values ) ) );
	}

	/**
	 * Find public.
	 *
	 * @param array  $rows Rows.
	 * @param string $id Id.
	 * @return array
	 */
	private function find_public( array $rows, string $id ): array {
		foreach ( $rows as $row ) {
			if ( $id === ( $row['public_id'] ?? null ) ) {
				return $row;
			}
		} $this->target_changed();
	}
	/**
	 * All public ids.
	 *
	 * @param array $plan Plan.
	 * @return array
	 */
	private function all_public_ids( array $plan ): array {
		$ids = array();
		foreach ( $plan as $rows ) {
			$ids = array_merge( $ids, $this->public_ids( $rows ) );
		} return array_values( array_unique( $ids ) );
	}
	/**
	 * Public ids.
	 *
	 * @param array $rows Rows.
	 * @return array
	 */
	private function public_ids( array $rows ): array {
		$ids = array();
		foreach ( $rows as $row ) {
			if ( isset( $row['public_id'] ) && is_string( $row['public_id'] ) && Public_Id::is_valid( $row['public_id'] ) ) {
				$ids[] = $row['public_id'];
			}
		} return $ids;
	}
	/**
	 * Database ids.
	 *
	 * @param array $rows Rows.
	 * @return array
	 */
	private function database_ids( array $rows ): array {
		return array_map( fn ( array $row ): int => $this->positive_integer( $row['id'] ?? null ), $rows );
	}
	/**
	 * Keyed.
	 *
	 * @param array $rows Rows.
	 * @return array
	 */
	private function keyed( array $rows ): array {
		$result = array();
		foreach ( $rows as $row ) {
			$result[ $this->positive_integer( $row['id'] ?? null ) ] = $row;
		} return $result;
	}

	/**
	 * Public id values.
	 *
	 * @param mixed $values Values.
	 * @return array
	 */
	private function public_id_values( mixed $values ): array {
		if ( ! is_array( $values ) ) {
			$this->stored_invalid();
		} $result = array();
		foreach ( $values as $value ) {
			$result[] = $this->public_id_value( $value );
		} return $result;
	}
	/**
	 * Public id value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function public_id_value( mixed $value ): string {
		if ( ! is_string( $value ) || ! Public_Id::is_valid( $value ) ) {
			$this->stored_invalid();
		} return $value;
	}
	/**
	 * Hash values.
	 *
	 * @param mixed $values Values.
	 * @return array
	 */
	private function hash_values( mixed $values ): array {
		if ( ! is_array( $values ) ) {
			$this->stored_invalid();
		} $result = array();
		foreach ( $values as $value ) {
			$result[] = $this->hash_value( (string) $value );
		} return $result;
	}
	/**
	 * Reject invalid same strings.
	 *
	 * @param array $actual Actual.
	 * @param array $expected Expected.
	 * @return void
	 */
	private function assert_same_strings( array $actual, array $expected ): void {
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			$this->target_changed();
		}
	}
	/**
	 * Reject invalid same integers.
	 *
	 * @param array $actual Actual.
	 * @param array $expected Expected.
	 * @return void
	 */
	private function assert_same_integers( array $actual, array $expected ): void {
		sort( $actual, SORT_NUMERIC );
		sort( $expected, SORT_NUMERIC );
		if ( $actual !== $expected ) {
			$this->target_changed();
		}
	}

	/**
	 * Recover receipt.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Rollback_Legacy_Migration_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function recover_receipt( array $record ): ?Rollback_Legacy_Migration_Result {
		try {
			return $this->command_receipt( (string) $record['command_storage_key'], (string) $record['request_digest'], false ); } catch ( Legacy_Migration_Failure $failure ) {
			if ( Legacy_Migration_Failure::IDEMPOTENCY_CONFLICT === $failure->reason() ) {
				throw $failure;
			} return null;
			} catch ( Throwable ) {
				return null;
			}
	}

	/**
	 * Decode stored.
	 *
	 * @param string $json Json.
	 * @param string $hash Hash.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode_stored( string $json, string $hash ): array {
		$this->hash_value( $hash );
		if ( ! hash_equals( $hash, hash( 'sha256', $json ) ) ) {
			$this->stored_invalid();
		}
		try {
			$value = json_decode( $json, true, 128, JSON_THROW_ON_ERROR ); } catch ( JsonException $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE, $error );
			}
			if ( ! is_array( $value ) || array_is_list( $value ) || ! hash_equals( $json, $this->canonical->encode( $value ) ) ) {
				$this->stored_invalid();
			}
			return $value;
	}

	/**
	 * Database.
	 *
	 * @return object
	 */
	private function database(): object {
		$database = $this->database ?? ( $GLOBALS['wpdb'] ?? null );
		if ( ! is_object( $database ) ) {
			$this->stored_invalid();
		} return $database;
	}
	/**
	 * Lock.
	 *
	 * @return Schema_Lock_Port_Interface
	 */
	private function lock(): Schema_Lock_Port_Interface {
		return $this->lock ??= new WordPress_Schema_Lock( $this->database() );
	}
	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param array  $values Values.
	 * @return string
	 */
	private function prepare( string $sql, array $values ): string {
		$prepared = $this->database()->prepare( $sql, ...$values );
		if ( ! is_string( $prepared ) || '' === $prepared ) {
			$this->stored_invalid();
		} return $prepared;
	}
	/**
	 * Row.
	 *
	 * @param string $sql Sql.
	 * @return array<string,mixed>|null
	 */
	private function row( string $sql ): ?array {
		$database = $this->database();
		$this->reset_error( $database );
		$row = $database->get_row( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		$this->assert_read( $database );
		if ( null === $row ) {
			return null;
		} if ( ! is_array( $row ) || array_is_list( $row ) ) {
			$this->stored_invalid();
		} return $row;
	}
	/**
	 * Rows.
	 *
	 * @param string $sql Sql.
	 * @return array<int,array<string,mixed>>
	 */
	private function rows( string $sql ): array {
		$database = $this->database();
		$this->reset_error( $database );
		$rows = $database->get_results( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		$this->assert_read( $database );
		if ( ! is_array( $rows ) ) {
			$this->stored_invalid();
		} foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || array_is_list( $row ) ) {
				$this->stored_invalid();
			}
		} return $rows;
	}
	/**
	 * Scalar.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 */
	private function scalar( string $sql ): mixed {
		$database = $this->database();
		$this->reset_error( $database );
		$value = $database->get_var( $sql );
		$this->assert_read( $database );
		return $value;
	}
	/**
	 * Query count.
	 *
	 * @param string $sql Sql.
	 * @return int
	 */
	private function query_count( string $sql ): int {
		$database = $this->database();
		$this->reset_error( $database );
		$result = $database->query( $sql );
		if ( false === $result || '' !== trim( (string) ( $database->last_error ?? '' ) ) || ! is_int( $result ) ) {
			$this->stored_invalid();
		} return $result;
	}
	/**
	 * Query or fail.
	 *
	 * @param string $sql Sql.
	 * @return void
	 * @throws \Throwable Propagates failures from the database query boundary.
	 */
	private function query_or_fail( string $sql ): void {
		$this->query_count( $sql );
	}
	/**
	 * Insert or fail.
	 *
	 * @param string $table Table.
	 * @param array  $data Data.
	 * @return void
	 */
	private function insert_or_fail( string $table, array $data ): void {
		$database = $this->database();
		$this->reset_error( $database );
		if ( false === $database->insert( $table, $data ) || '' !== trim( (string) ( $database->last_error ?? '' ) ) ) {
			$this->stored_invalid();
		}
	}
	/**
	 * Rollback.
	 *
	 * @return void
	 */
	private function rollback(): void {
		try {
			$this->database()->query( 'ROLLBACK' ); } catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
			// Best-effort recovery must not replace the primary failure.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Best-effort recovery must not replace the primary failure.
			}
	}
	/**
	 * Reset error.
	 *
	 * @param object $database Database.
	 * @return void
	 */
	private function reset_error( object $database ): void {
		if ( property_exists( $database, 'last_error' ) ) {
			$database->last_error = '';
		}
	}
	/**
	 * Reject invalid read.
	 *
	 * @param object $database Database.
	 * @return void
	 */
	private function assert_read( object $database ): void {
		if ( '' !== trim( (string) ( $database->last_error ?? '' ) ) ) {
			$this->stored_invalid();
		}
	}

	/**
	 * Exact keys.
	 *
	 * @param array $value Value.
	 * @param array $expected Expected.
	 * @return void
	 */
	private function exact_keys( array $value, array $expected ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			$this->stored_invalid();
		}
	}
	/**
	 * Storage key.
	 *
	 * @param string $value Value.
	 * @return void
	 */
	private function storage_key( string $value ): void {
		if ( 1 !== preg_match( '/^command:[a-f0-9]{64}$/D', $value ) ) {
			$this->stored_invalid();
		}
	}
	/**
	 * Hash value.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function hash_value( string $value ): string {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			$this->stored_invalid();
		} return $value;
	}
	/**
	 * Utc.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function utc( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			$this->stored_invalid();
		} $time = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );
		if ( ! $time instanceof DateTimeImmutable || $time->format( 'Y-m-d H:i:s' ) !== $value ) {
			$this->stored_invalid();
		} return $value;
	}
	/**
	 * Safe token.
	 *
	 * @param string $value Value.
	 * @param int    $minimum Minimum.
	 * @param int    $maximum Maximum.
	 * @return void
	 */
	private function safe_token( string $value, int $minimum, int $maximum ): void {
		if ( strlen( $value ) < $minimum || strlen( $value ) > $maximum || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $value ) ) {
			$this->stored_invalid();
		}
	}
	/**
	 * Positive integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private function positive_integer( mixed $value ): int {
		if ( is_int( $value ) && $value > 0 ) {
			return $value;
		} if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			return (int) $value;
		} $this->stored_invalid();
	}
	/**
	 * Non negative integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private function non_negative_integer( mixed $value ): int {
		if ( is_int( $value ) && $value >= 0 ) {
			return $value;
		} if ( is_string( $value ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) {
			return (int) $value;
		} $this->stored_invalid();
	}
	/**
	 * Stored invalid.
	 *
	 * @return never
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function stored_invalid(): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
	}
	/**
	 * Target changed.
	 *
	 * @return never
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function target_changed(): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::TARGET_CHANGED );
	}
}
