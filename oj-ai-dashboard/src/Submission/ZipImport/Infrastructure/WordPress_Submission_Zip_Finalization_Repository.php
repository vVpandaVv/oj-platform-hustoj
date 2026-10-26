<?php
/**
 * WordPress C20/C21 submission ZIP finalization repository.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\ZipImport\Infrastructure;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Attempt_Allowance;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Revision_Integrity;
use OJAI\Dashboard\Submission\ZipImport\Application\Cancel_Submission_Zip_Result;
use OJAI\Dashboard\Submission\ZipImport\Application\Confirm_Submission_Zip_Result;
use OJAI\Dashboard\Submission\ZipImport\Application\Zip_Import_Failure;
use OJAI\Dashboard\Submission\ZipImport\Port\Submission_Zip_Finalization_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps every proxy submit atomic while allowing other ZIP items to succeed.
 * Platform authorization is handed in as an exact Context snapshot; no platform
 * table or internal namespace is read here.
 */
final class WordPress_Submission_Zip_Finalization_Repository implements Submission_Zip_Finalization_Repository_Port_Interface {
	private const TABLE_UPLOADS       = 'oj_ai_zip_uploads';
	private const TABLE_ITEMS         = 'oj_ai_zip_upload_items';
	private const TABLE_ASSIGNMENTS   = 'oj_ai_assignments';
	private const TABLE_TARGETS       = 'oj_ai_assignment_targets';
	private const TABLE_PUBLICATIONS  = 'oj_ai_worksheet_publications';
	private const TABLE_SUBMISSIONS   = 'oj_ai_submissions';
	private const TABLE_REVISIONS     = 'oj_ai_submission_revisions';
	private const TABLE_RECEIPTS      = 'oj_ai_submission_receipts';
	private const TABLE_ATTACHMENTS   = 'oj_ai_submission_attachments';
	private const TABLE_PRIVATE_FILES = 'oj_ai_private_files';
	private const TABLE_AUDIT         = 'oj_ai_audit_events';
	private const TABLE_OUTBOX        = 'oj_ai_outbox_events';

	private const MAPPING_SCHEMA          = 'oj_ai.submission_zip_mapping';
	private const RESPONSE_PROFILE_SCHEMA = 'oj_ai.student_response_schema';
	private const POLICY_SCHEMA           = 'oj_ai.assignment_resubmission_policy';
	private const CONFIRM_EVENT           = 'submission_zip.confirmed';
	private const CONFIRM_SCHEMA          = 'oj_ai.submission_zip_confirmation_event';
	private const CONFIRM_LEASE_EVENT     = 'submission_zip.confirmation.started';
	private const CONFIRM_LEASE_SCHEMA    = 'oj_ai.submission_zip_confirmation_lease';
	private const CANCEL_EVENT            = 'submission_zip.canceled';
	private const CANCEL_SCHEMA           = 'oj_ai.submission_zip_cancellation_event';
	private const PROXY_EVENT             = 'submission.proxy_submitted';
	private const PROXY_SCHEMA            = 'oj_ai.submission_proxy_submitted_event';
	private const AUDIT_SCHEMA            = 'oj_ai.submission_zip_finalization_audit';
	private const VERSION                 = 1;

	/**
	 * Canonical JSON encoder used for stable digests.
	 *
	 * @var Canonical_Json
	 */
	private Canonical_Json $canonical;
	/**
	 * Integrity.
	 *
	 * @var Submission_Revision_Integrity
	 */
	private Submission_Revision_Integrity $integrity;

	/**
	 * Initialize WordPress submission zip finalization repository dependencies and state.
	 *
	 * @param object|null         $database Database.
	 * @param Canonical_Json|null $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct( private ?object $database = null, ?Canonical_Json $canonical = null ) {
		$this->canonical = $canonical ?? new Canonical_Json();
		$this->integrity = new Submission_Revision_Integrity( $this->canonical );
	}

	/**
	 * Find confirmation receipt.
	 *
	 * @param string $idempotency_storage_key Idempotency storage key.
	 * @param string $request_digest Request digest.
	 * @return Confirm_Submission_Zip_Result|null
	 */
	public function find_confirmation_receipt( string $idempotency_storage_key, string $request_digest ): ?Confirm_Submission_Zip_Result {
		$this->confirmation_storage_key( $idempotency_storage_key );
		$this->hash_argument( $request_digest );
		return $this->confirmation_receipt( $idempotency_storage_key, $request_digest, false );
	}

	/**
	 * Begin confirmation.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return array<int,int>|Confirm_Submission_Zip_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function begin_confirmation( array $record ): array|Confirm_Submission_Zip_Result {
		$this->assert_confirmation_base( $record, true );
		$receipt = $this->confirmation_receipt( $record['idempotency_storage_key'], $record['request_digest'], false );
		if ( $receipt instanceof Confirm_Submission_Zip_Result ) {
			return $receipt;
		}

		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$receipt     = $this->confirmation_receipt( $record['idempotency_storage_key'], $record['request_digest'], true );
			if ( $receipt instanceof Confirm_Submission_Zip_Result ) {
				$this->query_or_fail( 'COMMIT' );
				return $receipt;
			}

			$root = $this->lock_root( $record['upload_id'] );
			$this->assert_confirmation_root( $root, $record, array( 'mapped', 'confirming' ) );
			$mapping = $this->mapping( $root );
			$this->assert_mapping_version( $mapping, $record );
			if ( 'mapped' === $root['upload_state'] ) {
				$this->claim_confirmation( $root, $record );
				$this->insert_confirmation_lease( $record );
			} else {
				$this->assert_confirmation_lease( $record, true );
			}

			$items = $this->lock_items( $this->positive_integer( $root['upload_pk'] ?? null ) );
			$this->assert_item_count( $root, $mapping, $items );
			$pending = array();
			foreach ( $items as $index => $item ) {
				$state        = $this->item_state( $item['state'] ?? null );
				$mapping_item = $mapping['items'][ $index ];
				$this->assert_item_identity( $item, $mapping_item, $index + 1 );
				if ( 'mapped' === $state ) {
					$this->assert_mapped_item( $item, $mapping_item );
					$pending[] = $index + 1;
				} elseif ( 'imported' === $state ) {
					$this->public_id( $item['result_submission_public_id'] ?? null );
					$this->selection( $mapping_item['selection'] ?? null );
					if ( null !== ( $item['error_code'] ?? null ) ) {
						$this->stored_invalid();
					}
				} elseif ( 'failed' === $state || 'excluded' === $state ) {
					$this->item_error( $item['error_code'] ?? null );
					if ( null !== ( $item['result_submission_public_id'] ?? null ) ) {
						$this->stored_invalid();
					}
				} else {
					throw Zip_Import_Failure::because( Zip_Import_Failure::MAPPING_INVALID );
				}
			}
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return $pending;
		} catch ( Zip_Import_Failure $failure ) {
			$this->rollback();
			throw $failure;
		} catch ( Throwable ) {
			if ( $transaction ) {
				$this->rollback();
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Confirm item.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return array{error_code:?string,item_no:int,result_submission_id:?string,state:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function confirm_item( array $record ): array {
		$this->assert_confirmation_item_record( $record );
		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$root        = $this->lock_root( $record['upload_id'] );
			$this->assert_confirmation_root( $root, $record, array( 'confirming' ) );
			$this->assert_confirmation_lease( $record, true );
			$mapping = $this->mapping( $root );
			$this->assert_mapping_version( $mapping, $record );
			$item = $this->lock_item( $this->positive_integer( $root['upload_pk'] ?? null ), $record['item_no'] );
			if ( null === $item ) {
				$this->stored_invalid();
			}
			$mapping_item = $mapping['items'][ $record['item_no'] - 1 ] ?? null;
			if ( ! is_array( $mapping_item ) ) {
				$this->stored_invalid();
			}
			$this->assert_item_identity( $item, $mapping_item, $record['item_no'] );

			$state = $this->item_state( $item['state'] ?? null );
			if ( 'imported' === $state ) {
				$result = $this->imported_item_result( $item, $record, true );
				$this->query_or_fail( 'COMMIT' );
				return $result;
			}
			if ( 'failed' === $state ) {
				$result = $this->failed_item_result( $item );
				$this->query_or_fail( 'COMMIT' );
				return $result;
			}
			if ( 'mapped' !== $state ) {
				throw Zip_Import_Failure::because( Zip_Import_Failure::STATE_CONFLICT );
			}
			$this->assert_mapped_item( $item, $mapping_item );
			$selection = $this->selection( $mapping_item['selection'] ?? null );
			if ( null === $selection ) {
				$this->stored_invalid();
			}

			$target = $this->lock_target( $this->positive_integer( $root['assignment_pk'] ?? null ), $selection['target_id'] );
			if (
				null === $target || 'active' !== ( $target['state'] ?? null )
				|| $selection['learner_user_id'] !== $this->positive_integer( $target['learner_user_id'] ?? null )
				|| $selection['learner_user_id'] !== $this->positive_integer( $item['learner_user_id'] ?? null )
			) {
				$this->rollback();
				$transaction = false;
				return $this->record_item_failure( $record, Zip_Import_Failure::MAPPING_INVALID );
			}
			$this->assert_assignment_snapshot( $root );
			$response = $this->upload_response( $root, $selection['response_id'] );
			$file     = $this->lock_private_file( $this->public_id( $item['file_public_id'] ?? null ) );
			if ( ! $this->valid_item_file( $file, $root, $item, $mapping_item, $response ) ) {
				$this->rollback();
				$transaction = false;
				return $this->record_item_failure( $record, Zip_Import_Failure::MAPPING_INVALID );
			}

			$submission     = $this->lock_submission( $this->positive_integer( $root['assignment_pk'] ?? null ), $selection['learner_user_id'] );
			$business_error = $this->submission_business_error( $root, $submission, $record );
			if ( null !== $business_error ) {
				$this->rollback();
				$transaction = false;
				return $this->record_item_failure( $record, $business_error );
			}

			$policy = $this->resubmission_policy( $root );
			if ( null !== $submission && 'reopened' === ( $submission['state'] ?? null ) ) {
				$used = $this->positive_integer( $submission['official_attempt_no'] ?? null );
				if ( Submission_Attempt_Allowance::remaining( $policy['max_attempts'], $used, $submission['state'] ) < 1 || ( null !== $policy['reopen_until_utc'] && $record['occurred_at_utc'] > $policy['reopen_until_utc'] ) ) {
					$this->rollback();
					$transaction = false;
					return $this->record_item_failure( $record, 'zip_proxy_submit_not_allowed' );
				}
			}

			if ( null === $submission ) {
				$submission = $this->insert_submission_root( $root, $selection['learner_user_id'], $record );
			}
			$submission_id       = $this->public_id( $submission['public_id'] ?? null );
			$current_revision    = $this->lock_current_revision( $submission );
			$revision_no         = null === $current_revision ? 1 : $this->positive_integer( $current_revision['revision_no'] ?? null ) + 1;
			$base_revision_no    = null === $current_revision ? null : $this->positive_integer( $current_revision['revision_no'] ?? null );
			$attachment_ids      = array( $record['attachment_public_id'] );
			$attachment_set_hash = hash( 'sha256', $this->canonical->encode( $attachment_ids ) );
			$response_value      = array(
				'answers'                 => array( $selection['response_id'] => $attachment_ids ),
				'publication_hash'        => $this->hash_value( $root['publication_hash'] ?? null ),
				'publication_id'          => $this->public_id( $root['worksheet_publication_public_id'] ?? null ),
				'response_schema_version' => Submission_Revision_Integrity::RESPONSE_SCHEMA_VERSION,
			);
			$response_json       = $this->canonical->encode( $response_value );
			$response_hash       = hash( 'sha256', $response_json );
			$content_hash        = $this->integrity->content_hash(
				$submission_id,
				$revision_no,
				$base_revision_no,
				'saved',
				Submission_Revision_Integrity::RESPONSE_SCHEMA_VERSION,
				$response_hash,
				$attachment_set_hash
			);

			$this->insert_or_fail(
				self::TABLE_REVISIONS,
				array(
					'public_id'               => $record['revision_public_id'],
					'content_hash'            => $content_hash,
					'created_by_user_id'      => $record['actor_user_id'],
					'created_at_utc'          => $record['occurred_at_utc'],
					'submission_id'           => $this->positive_integer( $submission['id'] ?? null ),
					'revision_no'             => $revision_no,
					'base_revision_no'        => $base_revision_no,
					'state'                   => 'saved',
					'response_schema_version' => Submission_Revision_Integrity::RESPONSE_SCHEMA_VERSION,
					'response_schema'         => Submission_Revision_Integrity::RESPONSE_SCHEMA,
					'response_version'        => Submission_Revision_Integrity::RESPONSE_VERSION,
					'response_json'           => $response_json,
					'response_hash'           => $response_hash,
					'attachment_set_hash'     => $attachment_set_hash,
				)
			);
			$revision_pk = $this->insert_id();
			$this->promote_item_file( $file, $submission_id, $record );
			$this->insert_bound_attachment( $submission, $revision_pk, $file, $selection['response_id'], $record );

			$latest_receipt = $this->latest_receipt( $this->positive_integer( $submission['id'] ?? null ) );
			$receipt_no     = null === $latest_receipt ? 1 : $latest_receipt + 1;
			$attempt_no     = 'reopened' === ( $submission['state'] ?? null )
				? $this->positive_integer( $submission['official_attempt_no'] ?? null ) + 1
				: 1;
			if ( 'draft' === ( $submission['state'] ?? null ) && ( null !== $latest_receipt || 0 !== $this->non_negative_integer( $submission['official_attempt_no'] ?? null ) ) ) {
				$this->stored_invalid();
			}
			$timeliness           = $record['occurred_at_utc'] <= $this->required_utc( $root['due_at_utc'] ?? null ) ? 'on_time' : 'late';
			$receipt_content      = array(
				'action'                      => 'proxy_submit',
				'actor_type'                  => 'teacher',
				'actor_user_id'               => $record['actor_user_id'],
				'assignment_schedule_hash'    => $this->hash_value( $root['schedule_hash'] ?? null ),
				'assignment_schedule_version' => $this->positive_integer( $root['schedule_version'] ?? null ),
				'attachment_set_hash'         => $attachment_set_hash,
				'context_revision'            => $record['authorized_context_revision'],
				'idempotency_key'             => $record['item_idempotency_key'],
				'attempt_no'                  => $attempt_no,
				'publication_hash'            => $this->hash_value( $root['publication_hash'] ?? null ),
				'reason'                      => $record['reason'],
				'receipt_no'                  => $receipt_no,
				'received_at_utc'             => $record['occurred_at_utc'],
				'resubmission_policy_hash'    => $policy['hash'],
				'submission_id'               => $submission_id,
				'submission_revision_hash'    => $content_hash,
				'submission_revision_id'      => $record['revision_public_id'],
				'timeliness'                  => $timeliness,
			);
			$receipt_content_hash = hash( 'sha256', $this->canonical->encode( $receipt_content ) );
			$this->insert_or_fail(
				self::TABLE_RECEIPTS,
				array(
					'public_id'                   => $record['receipt_public_id'],
					'created_at_utc'              => $record['occurred_at_utc'],
					'content_hash'                => $receipt_content_hash,
					'submission_id'               => $this->positive_integer( $submission['id'] ?? null ),
					'submission_revision_id'      => $revision_pk,
					'receipt_no'                  => $receipt_no,
					'action'                      => 'proxy_submit',
					'idempotency_key'             => $record['item_idempotency_key'],
					'context_revision'            => $record['authorized_context_revision'],
					'actor_type'                  => 'teacher',
					'actor_user_id'               => $record['actor_user_id'],
					'reason'                      => $record['reason'],
					'attempt_no'                  => $attempt_no,
					'submission_revision_hash'    => $content_hash,
					'publication_hash'            => $this->hash_value( $root['publication_hash'] ?? null ),
					'attachment_set_hash'         => $attachment_set_hash,
					'assignment_schedule_version' => $this->positive_integer( $root['schedule_version'] ?? null ),
					'assignment_schedule_hash'    => $this->hash_value( $root['schedule_hash'] ?? null ),
					'resubmission_policy_hash'    => $policy['hash'],
					'timeliness'                  => $timeliness,
					'received_at_utc'             => $record['occurred_at_utc'],
				)
			);
			$this->update_submission_root( $submission, $record['revision_public_id'], $attempt_no, $record );
			$this->mark_item_imported( $item, $submission_id, $record );
			$result = array(
				'error_code'           => null,
				'item_no'              => $record['item_no'],
				'result_submission_id' => $submission_id,
				'state'                => 'imported',
			);
			$this->insert_proxy_audit( $root, $record, $submission_id, $content_hash, $attempt_no );
			$this->insert_proxy_outbox( $root, $record, $result, $record['revision_public_id'], $record['receipt_public_id'], $content_hash );
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return $result;
		} catch ( Zip_Import_Failure $failure ) {
			$this->rollback();
			throw $failure;
		} catch ( Throwable ) {
			if ( $transaction ) {
				$this->rollback();
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Complete confirmation.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Confirm_Submission_Zip_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function complete_confirmation( array $record ): Confirm_Submission_Zip_Result {
		$this->assert_confirmation_completion_record( $record );
		$receipt = $this->confirmation_receipt( $record['idempotency_storage_key'], $record['request_digest'], false );
		if ( $receipt instanceof Confirm_Submission_Zip_Result ) {
			return $receipt;
		}
		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$receipt     = $this->confirmation_receipt( $record['idempotency_storage_key'], $record['request_digest'], true );
			if ( $receipt instanceof Confirm_Submission_Zip_Result ) {
				$this->query_or_fail( 'COMMIT' );
				return $receipt;
			}
			$root = $this->lock_root( $record['upload_id'] );
			$this->assert_confirmation_root( $root, $record, array( 'confirming' ) );
			$this->assert_confirmation_lease( $record, true );
			$mapping = $this->mapping( $root );
			$this->assert_mapping_version( $mapping, $record );
			$items = $this->lock_items( $this->positive_integer( $root['upload_pk'] ?? null ) );
			$this->assert_item_count( $root, $mapping, $items );
			$result_items = array();
			$counts       = array(
				'excluded' => 0,
				'failed'   => 0,
				'imported' => 0,
			);
			foreach ( $items as $index => $item ) {
				$this->assert_item_identity( $item, $mapping['items'][ $index ], $index + 1 );
				$state = $this->item_state( $item['state'] ?? null );
				if ( 'imported' === $state ) {
					$result_items[] = $this->imported_item_result( $item, $record, false );
				} elseif ( 'failed' === $state || 'excluded' === $state ) {
					$result_items[] = array(
						'error_code'           => $this->item_error( $item['error_code'] ?? null ),
						'item_no'              => $index + 1,
						'result_submission_id' => null,
						'state'                => $state,
					);
				} else {
					throw Zip_Import_Failure::because( Zip_Import_Failure::STATE_CONFLICT );
				}
				++$counts[ $state ];
			}
			$cleanup_after = $this->plus_day( $record['occurred_at_utc'] );
			$result        = new Confirm_Submission_Zip_Result(
				array(
					'assignment_id'   => $this->public_id( $root['assignment_id'] ?? null ),
					'excluded_total'  => $counts['excluded'],
					'failed_total'    => $counts['failed'],
					'id'              => $record['upload_id'],
					'imported_total'  => $counts['imported'],
					'item_total'      => count( $result_items ),
					'mapping_version' => $record['expected_mapping_version'],
					'state'           => 'completed',
				),
				$result_items,
				$record['actor_user_id'],
				$record['occurred_at_utc'],
				false
			);
			$this->update_upload_state( $root, 'completed', $cleanup_after, $record );
			$this->insert_root_audit( self::CONFIRM_EVENT, $root, $record, $result->upload(), $record['audit_public_id'] );
			$this->insert_confirmation_outbox( $record, $result );
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return $result;
		} catch ( Zip_Import_Failure $failure ) {
			if ( $transaction ) {
				$this->rollback();
			}
			throw $failure;
		} catch ( Throwable ) {
			if ( $transaction ) {
				$this->rollback();
			}
			$receipt = $this->confirmation_receipt( $record['idempotency_storage_key'], $record['request_digest'], false );
			if ( $receipt instanceof Confirm_Submission_Zip_Result ) {
				return $receipt;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Find cancellation receipt.
	 *
	 * @param string $idempotency_storage_key Idempotency storage key.
	 * @param string $request_digest Request digest.
	 * @return Cancel_Submission_Zip_Result|null
	 */
	public function find_cancellation_receipt( string $idempotency_storage_key, string $request_digest ): ?Cancel_Submission_Zip_Result {
		$this->cancellation_storage_key( $idempotency_storage_key );
		$this->hash_argument( $request_digest );
		return $this->cancellation_receipt( $idempotency_storage_key, $request_digest, false );
	}

	/**
	 * Cancel.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Cancel_Submission_Zip_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function cancel( array $record ): Cancel_Submission_Zip_Result {
		$this->assert_cancellation_record( $record );
		$receipt = $this->cancellation_receipt( $record['idempotency_storage_key'], $record['request_digest'], false );
		if ( $receipt instanceof Cancel_Submission_Zip_Result ) {
			return $receipt;
		}
		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$receipt     = $this->cancellation_receipt( $record['idempotency_storage_key'], $record['request_digest'], true );
			if ( $receipt instanceof Cancel_Submission_Zip_Result ) {
				$this->query_or_fail( 'COMMIT' );
				return $receipt;
			}
			$root = $this->lock_root( $record['upload_id'] );
			if ( null === $root || ! $this->same_context( $root, $record ) ) {
				throw Zip_Import_Failure::because( Zip_Import_Failure::NOT_FOUND );
			}
			if ( ! in_array( $root['upload_state'] ?? null, array( 'scanning', 'ready', 'mapped' ), true ) ) {
				throw Zip_Import_Failure::because( Zip_Import_Failure::STATE_CONFLICT );
			}
			$mapping = $this->mapping( $root );
			$this->assert_mapping_version( $mapping, $record );
			$items = $this->lock_items( $this->positive_integer( $root['upload_pk'] ?? null ) );
			foreach ( $items as $item ) {
				if ( 'imported' === $this->item_state( $item['state'] ?? null ) || null !== ( $item['result_submission_public_id'] ?? null ) ) {
					throw Zip_Import_Failure::because( Zip_Import_Failure::STATE_CONFLICT );
				}
			}
			$result = new Cancel_Submission_Zip_Result(
				array(
					'assignment_id'     => $this->public_id( $root['assignment_id'] ?? null ),
					'cleanup_after_utc' => $record['cleanup_after_utc'],
					'id'                => $record['upload_id'],
					'item_total'        => $this->non_negative_integer( $root['item_total'] ?? null ),
					'mapping_version'   => $record['expected_mapping_version'],
					'state'             => 'canceled',
				),
				$record['actor_user_id'],
				$record['occurred_at_utc'],
				false
			);
			$this->update_upload_state( $root, 'canceled', $record['cleanup_after_utc'], $record );
			$this->insert_root_audit( self::CANCEL_EVENT, $root, $record, $result->upload(), $record['audit_public_id'] );
			$this->insert_cancellation_outbox( $record, $result );
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return $result;
		} catch ( Zip_Import_Failure $failure ) {
			if ( $transaction ) {
				$this->rollback();
			}
			throw $failure;
		} catch ( Throwable ) {
			if ( $transaction ) {
				$this->rollback();
			}
			$receipt = $this->cancellation_receipt( $record['idempotency_storage_key'], $record['request_digest'], false );
			if ( $receipt instanceof Cancel_Submission_Zip_Result ) {
				return $receipt;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Record item failure.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param string              $error_code Error code.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function record_item_failure( array $record, string $error_code ): array {
		$this->item_error( $error_code );
		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$root        = $this->lock_root( $record['upload_id'] );
			$this->assert_confirmation_root( $root, $record, array( 'confirming' ) );
			$this->assert_confirmation_lease( $record, true );
			$mapping = $this->mapping( $root );
			$this->assert_mapping_version( $mapping, $record );
			$item = $this->lock_item( $this->positive_integer( $root['upload_pk'] ?? null ), $record['item_no'] );
			if ( null === $item ) {
				$this->stored_invalid();
			}
			$state = $this->item_state( $item['state'] ?? null );
			if ( 'imported' === $state ) {
				$result = $this->imported_item_result( $item, $record, false );
				$this->query_or_fail( 'COMMIT' );
				return $result;
			}
			if ( 'failed' === $state ) {
				$result = $this->failed_item_result( $item );
				$this->query_or_fail( 'COMMIT' );
				return $result;
			}
			if ( 'mapped' !== $state ) {
				throw Zip_Import_Failure::because( Zip_Import_Failure::STATE_CONFLICT );
			}
			$changed = $this->change_count(
				$this->prepare(
					'UPDATE ' . self::TABLE_ITEMS . ' SET state = %s, error_code = %s, result_submission_public_id = NULL, '
					. 'lock_version = lock_version + 1, updated_by_user_id = %d, updated_at_utc = %s '
					. 'WHERE id = %d AND lock_version = %d AND state = %s AND result_submission_public_id IS NULL',
					array(
						'failed',
						$error_code,
						$record['actor_user_id'],
						$record['occurred_at_utc'],
						$this->positive_integer( $item['id'] ?? null ),
						$this->non_negative_integer( $item['lock_version'] ?? null ),
						'mapped',
					)
				)
			);
			if ( 1 !== $changed ) {
				throw Zip_Import_Failure::because( Zip_Import_Failure::STATE_CONFLICT );
			}
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return array(
				'error_code'           => $error_code,
				'item_no'              => $record['item_no'],
				'result_submission_id' => null,
				'state'                => 'failed',
			);
		} catch ( Zip_Import_Failure $failure ) {
			$this->rollback();
			throw $failure;
		} catch ( Throwable ) {
			if ( $transaction ) {
				$this->rollback();
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Lock root.
	 *
	 * @param string $upload_id Upload id.
	 * @return array<string,mixed>|null
	 */
	private function lock_root( string $upload_id ): ?array {
		$sql = 'SELECT u.id AS upload_pk, u.public_id AS upload_id, u.lock_version AS upload_lock_version, u.state AS upload_state, u.mapping_schema, u.mapping_version, u.mapping_json, u.mapping_hash, u.item_total, u.success_total, u.failure_total, a.id AS assignment_pk, a.public_id AS assignment_id, a.state AS assignment_state, a.context_type, a.context_id, a.context_revision, a.worksheet_publication_public_id, a.publication_hash, a.opens_at_utc, a.due_at_utc, a.closes_at_utc, a.schedule_version, a.schedule_hash, a.auto_submit_enabled, a.resubmission_policy_schema, a.resubmission_policy_version, a.resubmission_policy_json, a.resubmission_policy_hash, p.content_hash AS publication_content_hash, p.publication_hash AS stored_publication_hash, p.response_schema_schema, p.response_schema_version, p.response_schema_json, p.response_schema_hash FROM ' . self::TABLE_UPLOADS . ' u INNER JOIN ' . self::TABLE_ASSIGNMENTS . ' a ON a.id = u.assignment_id '
			. 'INNER JOIN ' . self::TABLE_PUBLICATIONS . ' p ON p.public_id = a.worksheet_publication_public_id '
			. 'WHERE u.public_id = %s LIMIT 1 FOR UPDATE';
		return $this->get_row( $this->prepare( $sql, array( $upload_id ) ) );
	}

	/**
	 * Lock items.
	 *
	 * @param int $upload_pk Upload pk.
	 * @return array<int,array<string,mixed>>
	 */
	private function lock_items( int $upload_pk ): array {
		return $this->get_results(
			$this->prepare(
				'SELECT id, lock_version, item_no, relative_path, file_public_id, learner_user_id, state, byte_size, sha256, result_submission_public_id, error_code FROM ' . self::TABLE_ITEMS . ' WHERE zip_upload_id = %d ORDER BY item_no ASC FOR UPDATE',
				array( $upload_pk )
			)
		);
	}

	/**
	 * Lock item.
	 *
	 * @param int $upload_pk Upload pk.
	 * @param int $item_no Item no.
	 * @return array<string,mixed>|null
	 */
	private function lock_item( int $upload_pk, int $item_no ): ?array {
		return $this->get_row(
			$this->prepare(
				'SELECT id, lock_version, item_no, relative_path, file_public_id, learner_user_id, state, byte_size, sha256, result_submission_public_id, error_code FROM ' . self::TABLE_ITEMS . ' WHERE zip_upload_id = %d AND item_no = %d LIMIT 1 FOR UPDATE',
				array( $upload_pk, $item_no )
			)
		);
	}

	/**
	 * Lock target.
	 *
	 * @param int    $assignment_pk Assignment pk.
	 * @param string $target_id Target id.
	 * @return array<string,mixed>|null
	 */
	private function lock_target( int $assignment_pk, string $target_id ): ?array {
		return $this->get_row(
			$this->prepare(
				'SELECT public_id, learner_user_id, state FROM ' . self::TABLE_TARGETS . ' WHERE assignment_id = %d AND public_id = %s LIMIT 1 FOR UPDATE',
				array( $assignment_pk, $target_id )
			)
		);
	}

	/**
	 * Lock private file.
	 *
	 * @param string $file_id File id.
	 * @return array<string,mixed>|null
	 */
	private function lock_private_file( string $file_id ): ?array {
		return $this->get_row(
			$this->prepare(
				'SELECT id, public_id, lock_version, owner_type, owner_public_id, purpose, original_name, mime_type, byte_size, sha256, state, scan_state, retention_class, expires_at_utc, legal_hold_until_utc, purged_at_utc FROM '
				. self::TABLE_PRIVATE_FILES . ' WHERE public_id = %s LIMIT 1 FOR UPDATE',
				array( $file_id )
			)
		);
	}

	/**
	 * Lock submission.
	 *
	 * @param int $assignment_pk Assignment pk.
	 * @param int $learner_user_id Learner user id.
	 * @return array<string,mixed>|null
	 */
	private function lock_submission( int $assignment_pk, int $learner_user_id ): ?array {
		return $this->get_row(
			$this->prepare(
				'SELECT id, public_id, lock_version, state, current_revision_public_id, official_revision_public_id, official_attempt_no, started_at_utc FROM ' . self::TABLE_SUBMISSIONS
				. ' WHERE assignment_id = %d AND learner_user_id = %d LIMIT 1 FOR UPDATE',
				array( $assignment_pk, $learner_user_id )
			)
		);
	}

	/**
	 * Submission business error.
	 *
	 * @param array<string,mixed>      $root Root.
	 * @param array<string,mixed>|null $submission Submission.
	 * @param array<string,mixed>      $record Record.
	 * @return string|null
	 */
	private function submission_business_error( array $root, ?array $submission, array $record ): ?string {
		if ( ! in_array( $root['assignment_state'] ?? null, array( 'open', 'closed' ), true ) ) {
			return 'zip_proxy_submit_not_allowed';
		}
		if ( null === $submission ) {
			return null;
		}
		$this->public_id( $submission['public_id'] ?? null );
		$state    = $this->submission_state( $submission['state'] ?? null );
		$official = $this->optional_public_id( $submission['official_revision_public_id'] ?? null );
		$attempt  = $this->non_negative_integer( $submission['official_attempt_no'] ?? null );
		if ( 'draft' === $state ) {
			return null === $official && 0 === $attempt ? null : Zip_Import_Failure::OFFICIAL_EXISTS;
		}
		if ( 'reopened' === $state ) {
			if ( null === $official || $attempt < 1 || null === $this->optional_public_id( $submission['current_revision_public_id'] ?? null ) ) {
				$this->stored_invalid();
			}
			return null;
		}
		return Zip_Import_Failure::OFFICIAL_EXISTS;
	}

	/**
	 * Resubmission policy.
	 *
	 * @param array<string,mixed> $root Root.
	 * @return array{max_attempts:int,reopen_until_utc:?string,hash:string}
	 */
	private function resubmission_policy( array $root ): array {
		if ( self::POLICY_SCHEMA !== ( $root['resubmission_policy_schema'] ?? null ) || self::VERSION !== $this->positive_integer( $root['resubmission_policy_version'] ?? null ) ) {
			$this->stored_invalid();
		}
		$hash  = $this->hash_value( $root['resubmission_policy_hash'] ?? null );
		$value = $this->decode_json( (string) ( $root['resubmission_policy_json'] ?? '' ), $hash );
		$this->exact_keys( $value, array( 'copy_previous_answers', 'max_attempts', 'reopen_until_utc' ) );
		$maximum = $this->positive_integer( $value['max_attempts'] ?? null );
		$until   = $this->optional_utc( $value['reopen_until_utc'] ?? null );
		if ( $maximum > 10 || ! is_bool( $value['copy_previous_answers'] ?? null ) ) {
			$this->stored_invalid();
		}
		return array(
			'max_attempts'     => $maximum,
			'reopen_until_utc' => $until,
			'hash'             => $hash,
		);
	}

	/**
	 * Reject invalid assignment snapshot.
	 *
	 * @param array<string,mixed> $root Root.
	 * @return void
	 */
	private function assert_assignment_snapshot( array $root ): void {
		$opens  = $this->optional_utc( $root['opens_at_utc'] ?? null );
		$due    = $this->required_utc( $root['due_at_utc'] ?? null );
		$closes = $this->required_utc( $root['closes_at_utc'] ?? null );
		if ( ( null !== $opens && $opens > $due ) || $due > $closes ) {
			$this->stored_invalid();
		}
		$version          = $this->positive_integer( $root['schedule_version'] ?? null );
		$schedule_hash    = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'auto_submit_enabled' => 1 === $this->zero_or_one( $root['auto_submit_enabled'] ?? null ),
					'closes_at_utc'       => $closes,
					'due_at_utc'          => $due,
					'opens_at_utc'        => $opens,
					'schedule_version'    => $version,
				)
			)
		);
		$publication_hash = $this->hash_value( $root['publication_hash'] ?? null );
		if (
			! hash_equals( $schedule_hash, $this->hash_value( $root['schedule_hash'] ?? null ) )
			|| ! hash_equals( $publication_hash, $this->hash_value( $root['publication_content_hash'] ?? null ) )
			|| ! hash_equals( $publication_hash, $this->hash_value( $root['stored_publication_hash'] ?? null ) )
		) {
			$this->stored_invalid();
		}
	}

	/**
	 * Upload response.
	 *
	 * @param array<string,mixed> $root Root.
	 * @param string              $response_id Response id.
	 * @return array{id:string,kind:string,max_bytes:int,mime_types:array<int,string>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function upload_response( array $root, string $response_id ): array {
		if ( self::RESPONSE_PROFILE_SCHEMA !== ( $root['response_schema_schema'] ?? null ) || self::VERSION !== $this->positive_integer( $root['response_schema_version'] ?? null ) ) {
			$this->stored_invalid();
		}
		$profile = $this->decode_json( (string) ( $root['response_schema_json'] ?? '' ), $this->hash_value( $root['response_schema_hash'] ?? null ) );
		$items   = $profile['payload']['items'] ?? null;
		if ( self::RESPONSE_PROFILE_SCHEMA !== ( $profile['schema_id'] ?? null ) || ! is_array( $items ) || ! array_is_list( $items ) ) {
			$this->stored_invalid();
		}
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || array_is_list( $item ) || ! in_array( $item['kind'] ?? null, array( 'image_upload', 'file_upload' ), true ) ) {
				continue;
			}
			$id = $this->public_id( $item['response_id'] ?? null );
			if ( $id !== $response_id ) {
				continue;
			}
			$constraints = $item['constraints'] ?? array();
			if ( ! is_array( $constraints ) || array_is_list( $constraints ) ) {
				$this->stored_invalid();
			}
			$mimes   = $constraints['mime_types'] ?? ( 'image_upload' === $item['kind'] ? array( 'image/jpeg', 'image/png', 'image/webp' ) : null );
			$maximum = $constraints['max_bytes'] ?? 26214400;
			if ( ! is_array( $mimes ) || ! array_is_list( $mimes ) || array() === $mimes || ! is_int( $maximum ) || $maximum < 1 || $maximum > 26214400 ) {
				$this->stored_invalid();
			}
			foreach ( $mimes as $mime ) {
				$this->mime( $mime );
			}
			return array(
				'id'         => $id,
				'kind'       => $item['kind'],
				'max_bytes'  => $maximum,
				'mime_types' => array_values( array_unique( $mimes ) ),
			);
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Zip_Import_Failure::because( Zip_Import_Failure::MAPPING_INVALID );
	}

	/**
	 * Valid item file.
	 *
	 * @param array<string,mixed>|null $file File.
	 * @param array<string,mixed>      $root Root.
	 * @param array<string,mixed>      $item Item.
	 * @param array<string,mixed>      $mapping_item Mapping item.
	 * @param array<string,mixed>      $response Response.
	 * @return bool
	 */
	private function valid_item_file( ?array $file, array $root, array $item, array $mapping_item, array $response ): bool {
		if ( null === $file ) {
			return false;
		}
		$mime = $mapping_item['detected_mime_type'] ?? null;
		return is_string( $mime )
			&& $mime === ( $file['mime_type'] ?? null )
			&& in_array( $mime, $response['mime_types'], true )
			&& in_array( $response['id'], $mapping_item['compatible_response_ids'], true )
			&& $this->positive_integer( $item['byte_size'] ?? null ) <= $response['max_bytes']
			&& $this->positive_integer( $item['byte_size'] ?? null ) === $this->positive_integer( $file['byte_size'] ?? null )
			&& hash_equals( $this->hash_value( $item['sha256'] ?? null ), $this->hash_value( $file['sha256'] ?? null ) )
			&& basename( $this->path( $item['relative_path'] ?? null ) ) === ( $file['original_name'] ?? null )
			&& 'submission_zip' === ( $file['owner_type'] ?? null )
			&& ( $root['upload_id'] ?? null ) === ( $file['owner_public_id'] ?? null )
			&& 'submission_zip_item' === ( $file['purpose'] ?? null )
			&& 'quarantined' === ( $file['state'] ?? null ) && 'clean' === ( $file['scan_state'] ?? null )
			&& 'submission_zip_staging' === ( $file['retention_class'] ?? null )
			&& null === ( $file['legal_hold_until_utc'] ?? null ) && null === ( $file['purged_at_utc'] ?? null );
	}

	/**
	 * Insert submission root.
	 *
	 * @param array<string,mixed> $root Root.
	 * @param int                 $learner_user_id Learner user id.
	 * @param array<string,mixed> $record Record.
	 * @return array<string,mixed>
	 */
	private function insert_submission_root( array $root, int $learner_user_id, array $record ): array {
		$this->insert_or_fail(
			self::TABLE_SUBMISSIONS,
			array(
				'public_id'                   => $record['submission_public_id'],
				'lock_version'                => 0,
				'created_by_user_id'          => $record['actor_user_id'],
				'created_at_utc'              => $record['occurred_at_utc'],
				'updated_by_user_id'          => $record['actor_user_id'],
				'updated_at_utc'              => $record['occurred_at_utc'],
				'assignment_id'               => $this->positive_integer( $root['assignment_pk'] ?? null ),
				'learner_user_id'             => $learner_user_id,
				'state'                       => 'draft',
				'current_revision_public_id'  => null,
				'official_revision_public_id' => null,
				'official_attempt_no'         => 0,
				'official_review_public_id'   => null,
				'official_review_hash'        => null,
				'current_release_public_id'   => null,
				'current_release_hash'        => null,
				'started_at_utc'              => $record['occurred_at_utc'],
				'submitted_at_utc'            => null,
				'closed_at_utc'               => null,
			)
		);
		return array(
			'id'                          => $this->insert_id(),
			'public_id'                   => $record['submission_public_id'],
			'lock_version'                => 0,
			'state'                       => 'draft',
			'current_revision_public_id'  => null,
			'official_revision_public_id' => null,
			'official_attempt_no'         => 0,
			'started_at_utc'              => $record['occurred_at_utc'],
		);
	}

	/**
	 * Lock current revision.
	 *
	 * @param array<string,mixed> $submission Submission.
	 * @return array<string,mixed>|null
	 */
	private function lock_current_revision( array $submission ): ?array {
		$current = $this->optional_public_id( $submission['current_revision_public_id'] ?? null );
		if ( null === $current ) {
			if ( 'reopened' === ( $submission['state'] ?? null ) ) {
				$this->stored_invalid();
			}
			return null;
		}
		$row = $this->get_row(
			$this->prepare(
				'SELECT public_id, revision_no FROM ' . self::TABLE_REVISIONS . ' WHERE submission_id = %d AND public_id = %s LIMIT 1 FOR UPDATE',
				array( $this->positive_integer( $submission['id'] ?? null ), $current )
			)
		);
		if ( null === $row || $current !== $this->public_id( $row['public_id'] ?? null ) ) {
			$this->stored_invalid();
		}
		$this->positive_integer( $row['revision_no'] ?? null );
		return $row;
	}

	/**
	 * Promote item file.
	 *
	 * @param array<string,mixed> $file File.
	 * @param string              $submission_id Submission public identifier.
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function promote_item_file( array $file, string $submission_id, array $record ): void {
		$changed = $this->change_count(
			$this->prepare(
				'UPDATE ' . self::TABLE_PRIVATE_FILES . ' SET owner_type = %s, owner_public_id = %s, purpose = %s, state = %s, '
				. 'lock_version = lock_version + 1, updated_by_user_id = %d, updated_at_utc = %s '
				. 'WHERE id = %d AND public_id = %s AND lock_version = %d AND owner_type = %s AND owner_public_id = %s '
				. 'AND purpose = %s AND state = %s AND scan_state = %s AND retention_class = %s '
				. 'AND legal_hold_until_utc IS NULL AND purged_at_utc IS NULL',
				array(
					'submission',
					$submission_id,
					'submission_attachment',
					'ready',
					$record['actor_user_id'],
					$record['occurred_at_utc'],
					$this->positive_integer( $file['id'] ?? null ),
					$this->public_id( $file['public_id'] ?? null ),
					$this->non_negative_integer( $file['lock_version'] ?? null ),
					'submission_zip',
					$record['upload_id'],
					'submission_zip_item',
					'quarantined',
					'clean',
					'submission_zip_staging',
				)
			)
		);
		if ( 1 !== $changed ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::STATE_CONFLICT );
		}
	}

	/**
	 * Insert bound attachment.
	 *
	 * @param array<string,mixed> $submission Submission.
	 * @param int                 $revision_pk Revision pk.
	 * @param array<string,mixed> $file File.
	 * @param string              $response_id Response id.
	 * @param array<string,mixed> $record Record.
	 * @return void
	 */
	private function insert_bound_attachment( array $submission, int $revision_pk, array $file, string $response_id, array $record ): void {
		$this->insert_or_fail(
			self::TABLE_ATTACHMENTS,
			array(
				'public_id'                 => $record['attachment_public_id'],
				'lock_version'              => 0,
				'created_by_user_id'        => $record['actor_user_id'],
				'created_at_utc'            => $record['occurred_at_utc'],
				'updated_by_user_id'        => $record['actor_user_id'],
				'updated_at_utc'            => $record['occurred_at_utc'],
				'submission_id'             => $this->positive_integer( $submission['id'] ?? null ),
				'submission_revision_id'    => $revision_pk,
				'response_key'              => $response_id,
				'file_public_id'            => $this->public_id( $file['public_id'] ?? null ),
				'state'                     => 'bound',
				'original_name'             => $this->filename( $file['original_name'] ?? null ),
				'mime_type'                 => $this->mime( $file['mime_type'] ?? null ),
				'byte_size'                 => $this->positive_integer( $file['byte_size'] ?? null ),
				'sha256'                    => $this->hash_value( $file['sha256'] ?? null ),
				'bound_at_utc'              => $record['occurred_at_utc'],
				'ai_representation_schema'  => null,
				'ai_representation_version' => null,
				'ai_representation_json'    => null,
				'ai_representation_hash'    => null,
			)
		);
	}

	/**
	 * Latest receipt.
	 *
	 * @param int $submission_pk Submission pk.
	 * @return int|null
	 */
	private function latest_receipt( int $submission_pk ): ?int {
		$row = $this->get_row(
			$this->prepare(
				'SELECT receipt_no FROM ' . self::TABLE_RECEIPTS . ' WHERE submission_id = %d ORDER BY receipt_no DESC LIMIT 1 FOR UPDATE',
				array( $submission_pk )
			)
		);
		return null === $row ? null : $this->positive_integer( $row['receipt_no'] ?? null );
	}

	/**
	 * Update submission root.
	 *
	 * @param array<string,mixed> $submission Submission.
	 * @param string              $revision_id Revision id.
	 * @param int                 $attempt_no Attempt no.
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function update_submission_root( array $submission, string $revision_id, int $attempt_no, array $record ): void {
		$current = $this->optional_public_id( $submission['current_revision_public_id'] ?? null );
		$sql     = 'UPDATE ' . self::TABLE_SUBMISSIONS . ' SET lock_version = lock_version + 1, updated_by_user_id = %d, '
			. 'updated_at_utc = %s, state = %s, current_revision_public_id = %s, official_revision_public_id = %s, '
			. 'official_attempt_no = %d, submitted_at_utc = %s WHERE id = %d AND lock_version = %d AND state = %s ';
		$values  = array(
			$record['actor_user_id'],
			$record['occurred_at_utc'],
			'proxy_submitted',
			$revision_id,
			$revision_id,
			$attempt_no,
			$record['occurred_at_utc'],
			$this->positive_integer( $submission['id'] ?? null ),
			$this->non_negative_integer( $submission['lock_version'] ?? null ),
			$submission['state'],
		);
		if ( null === $current ) {
			$sql .= 'AND current_revision_public_id IS NULL '; } else {
			$sql     .= 'AND current_revision_public_id = %s ';
			$values[] = $current;
			}
			$official = $this->optional_public_id( $submission['official_revision_public_id'] ?? null );
			if ( null === $official ) {
				$sql .= 'AND official_revision_public_id IS NULL AND official_attempt_no = 0'; } else {
						$sql     .= 'AND official_revision_public_id = %s AND official_attempt_no = %d';
						$values[] = $official;
						$values[] = $attempt_no - 1;
				}
				if ( 1 !== $this->change_count( $this->prepare( $sql, $values ) ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Zip_Import_Failure::because( Zip_Import_Failure::STATE_CONFLICT );
				}
	}

	/**
	 * Mark item imported.
	 *
	 * @param array<string,mixed> $item Item.
	 * @param string              $submission_id Submission public identifier.
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function mark_item_imported( array $item, string $submission_id, array $record ): void {
		if ( 1 !== $this->change_count(
			$this->prepare(
				'UPDATE ' . self::TABLE_ITEMS . ' SET state = %s, result_submission_public_id = %s, error_code = NULL, '
				. 'lock_version = lock_version + 1, updated_by_user_id = %d, updated_at_utc = %s '
				. 'WHERE id = %d AND lock_version = %d AND state = %s AND result_submission_public_id IS NULL',
				array(
					'imported',
					$submission_id,
					$record['actor_user_id'],
					$record['occurred_at_utc'],
					$this->positive_integer( $item['id'] ?? null ),
					$this->non_negative_integer( $item['lock_version'] ?? null ),
					'mapped',
				)
			)
		) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::STATE_CONFLICT );
		}
	}

	/**
	 * Insert proxy audit.
	 *
	 * @param array<string,mixed> $root Root.
	 * @param array<string,mixed> $record Record.
	 * @param string              $submission_id Submission public identifier.
	 * @param string              $content_hash Content hash.
	 * @param int                 $attempt_no Attempt no.
	 * @return void
	 */
	private function insert_proxy_audit( array $root, array $record, string $submission_id, string $content_hash, int $attempt_no ): void {
		$previous = $this->get_var( 'SELECT event_hash FROM ' . self::TABLE_AUDIT . ' ORDER BY id DESC LIMIT 1 FOR UPDATE' );
		if ( null !== $previous ) {
			$this->hash_value( $previous );
		}
		$before        = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'item_no' => $record['item_no'],
					'state'   => 'mapped',
				)
			)
		);
		$after         = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'attempt_no'    => $attempt_no,
					'item_no'       => $record['item_no'],
					'state'         => 'imported',
					'submission_id' => $submission_id,
				)
			)
		);
		$metadata_json = $this->canonical->encode(
			array(
				'attempt_no'               => $attempt_no,
				'item_no'                  => $record['item_no'],
				'submission_revision_hash' => $content_hash,
			)
		);
		$this->insert_audit_row(
			$record['item_audit_public_id'],
			self::PROXY_EVENT,
			'submission',
			$submission_id,
			$root,
			$record,
			$before,
			$after,
			$metadata_json,
			$previous
		);
	}

	/**
	 * Insert proxy outbox.
	 *
	 * @param array<string,mixed> $root Root.
	 * @param array<string,mixed> $record Record.
	 * @param array<string,mixed> $result Result.
	 * @param string              $revision_id Revision id.
	 * @param string              $receipt_id Receipt id.
	 * @param string              $content_hash Content hash.
	 * @return void
	 */
	private function insert_proxy_outbox( array $root, array $record, array $result, string $revision_id, string $receipt_id, string $content_hash ): void {
		$payload_json = $this->canonical->encode(
			array(
				'assignment_id'            => $this->public_id( $root['assignment_id'] ?? null ),
				'item_no'                  => $record['item_no'],
				'receipt_id'               => $receipt_id,
				'result'                   => $result,
				'revision_id'              => $revision_id,
				'submission_revision_hash' => $content_hash,
				'upload_id'                => $record['upload_id'],
			)
		);
		$this->insert_outbox_row(
			$record['item_outbox_public_id'],
			'submission',
			(string) $result['result_submission_id'],
			self::PROXY_EVENT,
			$record['item_idempotency_key'],
			self::PROXY_SCHEMA,
			$payload_json,
			$record
		);
	}

	/**
	 * Insert root audit.
	 *
	 * @param string              $event Event.
	 * @param array<string,mixed> $root Root.
	 * @param array<string,mixed> $record Record.
	 * @param array<string,mixed> $after After.
	 * @param string              $public_id Public id.
	 * @return void
	 */
	private function insert_root_audit( string $event, array $root, array $record, array $after, string $public_id ): void {
		$previous = $this->get_var( 'SELECT event_hash FROM ' . self::TABLE_AUDIT . ' ORDER BY id DESC LIMIT 1 FOR UPDATE' );
		if ( null !== $previous ) {
			$this->hash_value( $previous );
		}
		$before        = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'mapping_version' => $record['expected_mapping_version'],
					'state'           => $root['upload_state'],
				)
			)
		);
		$after_hash    = hash( 'sha256', $this->canonical->encode( $after ) );
		$metadata_json = $this->canonical->encode(
			array(
				'command_id'      => $record['command_id'],
				'mapping_version' => $record['expected_mapping_version'],
			)
		);
		$this->insert_audit_row( $public_id, $event, 'submission_zip', $record['upload_id'], $root, $record, $before, $after_hash, $metadata_json, $previous );
	}

	/**
	 * Insert audit row.
	 *
	 * @param string              $public_id Public id.
	 * @param string              $event Event.
	 * @param string              $object_type Object type.
	 * @param string              $object_id Object id.
	 * @param array<string,mixed> $root Root.
	 * @param array<string,mixed> $record Record.
	 * @param string              $before Before.
	 * @param string              $after After.
	 * @param string              $metadata_json Metadata json.
	 * @param mixed               $previous Previous.
	 * @return void
	 */
	private function insert_audit_row( string $public_id, string $event, string $object_type, string $object_id, array $root, array $record, string $before, string $after, string $metadata_json, mixed $previous ): void {
		$metadata_hash = hash( 'sha256', $metadata_json );
		$event_hash    = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'action_key'          => $event,
					'actor_user_id'       => $record['actor_user_id'],
					'after_digest'        => $after,
					'before_digest'       => $before,
					'correlation_id'      => $record['correlation_id'],
					'metadata_hash'       => $metadata_hash,
					'object_public_id'    => $object_id,
					'object_type'         => $object_type,
					'occurred_at_utc'     => $record['occurred_at_utc'],
					'previous_event_hash' => null === $previous ? null : (string) $previous,
				)
			)
		);
		$this->insert_or_fail(
			self::TABLE_AUDIT,
			array(
				'public_id'           => $public_id,
				'occurred_at_utc'     => $record['occurred_at_utc'],
				'actor_user_id'       => $record['actor_user_id'],
				'action_key'          => $event,
				'object_type'         => $object_type,
				'object_public_id'    => $object_id,
				'context_type'        => $record['authorized_context_type'],
				'context_id'          => $record['authorized_context_id'],
				'context_revision'    => $record['authorized_context_revision'],
				'correlation_id'      => $record['correlation_id'],
				'before_digest'       => $before,
				'after_digest'        => $after,
				'metadata_schema'     => self::AUDIT_SCHEMA,
				'metadata_version'    => self::VERSION,
				'metadata_json'       => $metadata_json,
				'metadata_hash'       => $metadata_hash,
				'previous_event_hash' => null === $previous ? null : (string) $previous,
				'event_hash'          => $event_hash,
			)
		);
	}

	/**
	 * Insert confirmation lease.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 */
	private function insert_confirmation_lease( array $record ): void {
		$payload_json = $this->canonical->encode(
			array(
				'command_storage_key' => $record['idempotency_storage_key'],
				'request_digest'      => $record['request_digest'],
				'upload_id'           => $record['upload_id'],
				'mapping_version'     => $record['expected_mapping_version'],
			)
		);
		$this->insert_outbox_row(
			$record['lease_outbox_public_id'],
			'submission_zip',
			$record['upload_id'],
			self::CONFIRM_LEASE_EVENT,
			$this->confirmation_lease_key( $record ),
			self::CONFIRM_LEASE_SCHEMA,
			$payload_json,
			$record
		);
	}

	/**
	 * Reject invalid confirmation lease.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param bool                $for_update For update.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_confirmation_lease( array $record, bool $for_update ): void {
		$sql = 'SELECT event_type, payload_schema, payload_version, payload_json, payload_hash FROM ' . self::TABLE_OUTBOX . ' WHERE idempotency_key = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = $this->get_row( $this->prepare( $sql, array( $this->confirmation_lease_key( $record ) ) ) );
		if ( null === $row ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::STATE_CONFLICT );
		}
		if ( self::CONFIRM_LEASE_EVENT !== ( $row['event_type'] ?? null ) || self::CONFIRM_LEASE_SCHEMA !== ( $row['payload_schema'] ?? null ) || self::VERSION !== $this->positive_integer( $row['payload_version'] ?? null ) ) {
			$this->stored_invalid();
		}
		$payload = $this->decode_json( (string) ( $row['payload_json'] ?? '' ), $this->hash_value( $row['payload_hash'] ?? null ) );
		$this->exact_keys( $payload, array( 'command_storage_key', 'mapping_version', 'request_digest', 'upload_id' ) );
		if (
			$record['idempotency_storage_key'] !== ( $payload['command_storage_key'] ?? null )
			|| $record['request_digest'] !== ( $payload['request_digest'] ?? null )
			|| $record['upload_id'] !== ( $payload['upload_id'] ?? null )
			|| $record['expected_mapping_version'] !== ( $payload['mapping_version'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::IDEMPOTENCY_CONFLICT );
		}
	}

	/**
	 * Insert confirmation outbox.
	 *
	 * @param array<string,mixed>           $record Record.
	 * @param Confirm_Submission_Zip_Result $result Result.
	 * @return void
	 */
	private function insert_confirmation_outbox( array $record, Confirm_Submission_Zip_Result $result ): void {
		$payload_json = $this->canonical->encode(
			array(
				'request_digest' => $record['request_digest'],
				'result'         => array(
					'confirmed_at_utc'     => $result->confirmed_at_utc(),
					'confirmed_by_user_id' => $result->confirmed_by_user_id(),
					'items'                => $result->items(),
					'upload'               => $result->upload(),
				),
			)
		);
		$this->insert_outbox_row( $record['outbox_public_id'], 'submission_zip', $record['upload_id'], self::CONFIRM_EVENT, $record['idempotency_storage_key'], self::CONFIRM_SCHEMA, $payload_json, $record );
	}

	/**
	 * Insert cancellation outbox.
	 *
	 * @param array<string,mixed>          $record Record.
	 * @param Cancel_Submission_Zip_Result $result Result.
	 * @return void
	 */
	private function insert_cancellation_outbox( array $record, Cancel_Submission_Zip_Result $result ): void {
		$payload_json = $this->canonical->encode(
			array(
				'request_digest' => $record['request_digest'],
				'result'         => array(
					'canceled_at_utc'     => $result->canceled_at_utc(),
					'canceled_by_user_id' => $result->canceled_by_user_id(),
					'upload'              => $result->upload(),
				),
			)
		);
		$this->insert_outbox_row( $record['outbox_public_id'], 'submission_zip', $record['upload_id'], self::CANCEL_EVENT, $record['idempotency_storage_key'], self::CANCEL_SCHEMA, $payload_json, $record );
	}

	/**
	 * Insert outbox row.
	 *
	 * @param string              $public_id Public id.
	 * @param string              $aggregate_type Aggregate type.
	 * @param string              $aggregate_id Aggregate id.
	 * @param string              $event Event.
	 * @param string              $key Key.
	 * @param string              $schema Schema.
	 * @param string              $payload_json Payload json.
	 * @param array<string,mixed> $record Record.
	 * @return void
	 */
	private function insert_outbox_row( string $public_id, string $aggregate_type, string $aggregate_id, string $event, string $key, string $schema, string $payload_json, array $record ): void {
		$this->insert_or_fail(
			self::TABLE_OUTBOX,
			array(
				'public_id'            => $public_id,
				'lock_version'         => 0,
				'created_by_user_id'   => $record['actor_user_id'],
				'created_at_utc'       => $record['occurred_at_utc'],
				'updated_by_user_id'   => $record['actor_user_id'],
				'updated_at_utc'       => $record['occurred_at_utc'],
				'aggregate_type'       => $aggregate_type,
				'aggregate_public_id'  => $aggregate_id,
				'event_type'           => $event,
				'idempotency_key'      => $key,
				'payload_schema'       => $schema,
				'payload_version'      => self::VERSION,
				'payload_json'         => $payload_json,
				'payload_hash'         => hash( 'sha256', $payload_json ),
				'available_at_utc'     => $record['occurred_at_utc'],
				'published_at_utc'     => null,
				'lease_token'          => null,
				'lease_expires_at_utc' => null,
				'attempt_count'        => 0,
				'last_error_code'      => null,
			)
		);
	}

	/**
	 * Claim confirmation.
	 *
	 * @param array<string,mixed> $root Root.
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function claim_confirmation( array $root, array $record ): void {
		if ( 1 !== $this->change_count(
			$this->prepare(
				'UPDATE ' . self::TABLE_UPLOADS . ' SET state = %s, lock_version = lock_version + 1, updated_by_user_id = %d, updated_at_utc = %s '
				. 'WHERE id = %d AND public_id = %s AND lock_version = %d AND state = %s',
				array(
					'confirming',
					$record['actor_user_id'],
					$record['occurred_at_utc'],
					$this->positive_integer( $root['upload_pk'] ?? null ),
					$record['upload_id'],
					$this->non_negative_integer( $root['upload_lock_version'] ?? null ),
					'mapped',
				)
			)
		) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::STATE_CONFLICT );
		}
	}

	/**
	 * Update upload state.
	 *
	 * @param array<string,mixed> $root Root.
	 * @param string              $state State.
	 * @param string              $cleanup_after Cleanup after.
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function update_upload_state( array $root, string $state, string $cleanup_after, array $record ): void {
		if ( 1 !== $this->change_count(
			$this->prepare(
				'UPDATE ' . self::TABLE_UPLOADS . ' SET state = %s, cleanup_after_utc = %s, lock_version = lock_version + 1, '
				. 'updated_by_user_id = %d, updated_at_utc = %s WHERE id = %d AND public_id = %s AND lock_version = %d AND state = %s',
				array(
					$state,
					$cleanup_after,
					$record['actor_user_id'],
					$record['occurred_at_utc'],
					$this->positive_integer( $root['upload_pk'] ?? null ),
					$record['upload_id'],
					$this->non_negative_integer( $root['upload_lock_version'] ?? null ),
					$root['upload_state'],
				)
			)
		) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::STATE_CONFLICT );
		}
	}

	/**
	 * Imported item result.
	 *
	 * @param array<string,mixed> $item Item.
	 * @param array<string,mixed> $record Record.
	 * @param bool                $validate_receipt Validate receipt.
	 * @return array
	 */
	private function imported_item_result( array $item, array $record, bool $validate_receipt ): array {
		$submission_id = $this->public_id( $item['result_submission_public_id'] ?? null );
		if ( null !== ( $item['error_code'] ?? null ) ) {
			$this->stored_invalid();
		}
		if ( $validate_receipt ) {
			$row = $this->get_row(
				$this->prepare(
					'SELECT s.public_id AS submission_id, s.state, r.action, r.idempotency_key FROM ' . self::TABLE_SUBMISSIONS . ' s '
					. 'INNER JOIN ' . self::TABLE_RECEIPTS . ' r ON r.submission_id = s.id WHERE s.public_id = %s AND r.idempotency_key = %s LIMIT 1 FOR UPDATE',
					array( $submission_id, $record['item_idempotency_key'] )
				)
			);
			if ( null === $row || 'proxy_submitted' !== ( $row['state'] ?? null ) || 'proxy_submit' !== ( $row['action'] ?? null ) ) {
				$this->stored_invalid();
			}
		}
		return array(
			'error_code'           => null,
			'item_no'              => $this->positive_integer( $item['item_no'] ?? null ),
			'result_submission_id' => $submission_id,
			'state'                => 'imported',
		);
	}

	/**
	 * Failed item result.
	 *
	 * @param array<string,mixed> $item Item.
	 * @return array
	 */
	private function failed_item_result( array $item ): array {
		return array(
			'error_code'           => $this->item_error( $item['error_code'] ?? null ),
			'item_no'              => $this->positive_integer( $item['item_no'] ?? null ),
			'result_submission_id' => null,
			'state'                => 'failed',
		);
	}

	/**
	 * Confirmation receipt.
	 *
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @param bool   $for_update For update.
	 * @return Confirm_Submission_Zip_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function confirmation_receipt( string $key, string $digest, bool $for_update ): ?Confirm_Submission_Zip_Result {
		$row = $this->outbox_receipt_row( $key, $for_update );
		if ( null === $row ) {
			return null;
		}
		if ( 'submission_zip' !== ( $row['aggregate_type'] ?? null ) || self::CONFIRM_EVENT !== ( $row['event_type'] ?? null ) || self::CONFIRM_SCHEMA !== ( $row['payload_schema'] ?? null ) || self::VERSION !== $this->positive_integer( $row['payload_version'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::IDEMPOTENCY_CONFLICT );
		}
		$payload = $this->decode_json( (string) ( $row['payload_json'] ?? '' ), $this->hash_value( $row['payload_hash'] ?? null ) );
		$this->exact_keys( $payload, array( 'request_digest', 'result' ) );
		if ( ! is_string( $payload['request_digest'] ?? null ) || ! hash_equals( $digest, $payload['request_digest'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::IDEMPOTENCY_CONFLICT );
		}
		$result = $payload['result'] ?? null;
		if ( ! is_array( $result ) || array_is_list( $result ) ) {
			$this->stored_invalid();
		}
		$this->exact_keys( $result, array( 'confirmed_at_utc', 'confirmed_by_user_id', 'items', 'upload' ) );
		try {
			return new Confirm_Submission_Zip_Result( $result['upload'], $result['items'], $result['confirmed_by_user_id'], $result['confirmed_at_utc'], true ); } catch ( Throwable ) {
			$this->stored_invalid();
			}
	}

	/**
	 * Cancellation receipt.
	 *
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @param bool   $for_update For update.
	 * @return Cancel_Submission_Zip_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function cancellation_receipt( string $key, string $digest, bool $for_update ): ?Cancel_Submission_Zip_Result {
		$row = $this->outbox_receipt_row( $key, $for_update );
		if ( null === $row ) {
			return null;
		}
		if ( 'submission_zip' !== ( $row['aggregate_type'] ?? null ) || self::CANCEL_EVENT !== ( $row['event_type'] ?? null ) || self::CANCEL_SCHEMA !== ( $row['payload_schema'] ?? null ) || self::VERSION !== $this->positive_integer( $row['payload_version'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::IDEMPOTENCY_CONFLICT );
		}
		$payload = $this->decode_json( (string) ( $row['payload_json'] ?? '' ), $this->hash_value( $row['payload_hash'] ?? null ) );
		$this->exact_keys( $payload, array( 'request_digest', 'result' ) );
		if ( ! is_string( $payload['request_digest'] ?? null ) || ! hash_equals( $digest, $payload['request_digest'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::IDEMPOTENCY_CONFLICT );
		}
		$result = $payload['result'] ?? null;
		if ( ! is_array( $result ) || array_is_list( $result ) ) {
			$this->stored_invalid();
		}
		$this->exact_keys( $result, array( 'canceled_at_utc', 'canceled_by_user_id', 'upload' ) );
		try {
			return new Cancel_Submission_Zip_Result( $result['upload'], $result['canceled_by_user_id'], $result['canceled_at_utc'], true ); } catch ( Throwable ) {
			$this->stored_invalid();
			}
	}

	/**
	 * Outbox receipt row.
	 *
	 * @param string $key Key.
	 * @param bool   $for_update For update.
	 * @return array<string,mixed>|null
	 */
	private function outbox_receipt_row( string $key, bool $for_update ): ?array {
		$sql = 'SELECT aggregate_type, aggregate_public_id, event_type, payload_schema, payload_version, payload_json, payload_hash FROM ' . self::TABLE_OUTBOX . ' WHERE idempotency_key = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		return $this->get_row( $this->prepare( $sql, array( $key ) ) );
	}

	/**
	 * Mapping.
	 *
	 * @param array<string,mixed> $root Root.
	 * @return array<string,mixed>
	 */
	private function mapping( array $root ): array {
		if ( self::MAPPING_SCHEMA !== ( $root['mapping_schema'] ?? null ) || self::VERSION !== $this->positive_integer( $root['mapping_version'] ?? null ) ) {
			$this->stored_invalid();
		}
		$value = $this->decode_json( (string) ( $root['mapping_json'] ?? '' ), $this->hash_value( $root['mapping_hash'] ?? null ) );
		$this->exact_keys( $value, array( 'items', 'mapping_revision', 'scan_error_code', 'source' ) );
		if ( null !== $value['scan_error_code'] || ! is_array( $value['items'] ?? null ) || ! array_is_list( $value['items'] ) ) {
			$this->stored_invalid();
		}
		$items = array();
		foreach ( $value['items'] as $index => $item ) {
			if ( ! is_array( $item ) || array_is_list( $item ) ) {
				$this->stored_invalid();
			}
			$this->exact_keys( $item, array( 'compatible_response_ids', 'detected_mime_type', 'item_no', 'selection', 'suggestion' ) );
			if ( $index + 1 !== $this->positive_integer( $item['item_no'] ?? null ) || ! is_array( $item['compatible_response_ids'] ?? null ) || ! array_is_list( $item['compatible_response_ids'] ) ) {
				$this->stored_invalid();
			}
			$responses = array();
			foreach ( $item['compatible_response_ids'] as $id ) {
				$id = $this->public_id( $id );
				if ( isset( $responses[ $id ] ) ) {
					$this->stored_invalid();
				} $responses[ $id ] = true;
			}
			$mime    = null === ( $item['detected_mime_type'] ?? null ) ? null : $this->mime( $item['detected_mime_type'] );
			$items[] = array(
				'compatible_response_ids' => array_keys( $responses ),
				'detected_mime_type'      => $mime,
				'item_no'                 => $index + 1,
				'selection'               => $this->selection( $item['selection'] ?? null ),
				'suggestion'              => $item['suggestion'],
			);
		}
		$source = $value['source'] ?? null;
		if ( ! is_array( $source ) || array_is_list( $source ) ) {
			$this->stored_invalid();
		}
		$this->exact_keys( $source, array( 'byte_size', 'compressed_entry_bytes', 'entry_count', 'sha256', 'uncompressed_bytes' ) );
		$this->positive_integer( $source['byte_size'] ?? null );
		$this->non_negative_integer( $source['compressed_entry_bytes'] ?? null );
		$this->positive_integer( $source['entry_count'] ?? null );
		$this->hash_value( $source['sha256'] ?? null );
		$this->non_negative_integer( $source['uncompressed_bytes'] ?? null );
		return array(
			'items'            => $items,
			'mapping_revision' => $this->non_negative_integer( $value['mapping_revision'] ?? null ),
			'scan_error_code'  => null,
			'source'           => $source,
		);
	}

	/**
	 * Reject invalid confirmation root.
	 *
	 * @param array<string,mixed>|null $root Root.
	 * @param array<string,mixed>      $record Record.
	 * @param array<int,string>        $states States.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_confirmation_root( ?array $root, array $record, array $states ): void {
		if ( null === $root || ! $this->same_context( $root, $record ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::NOT_FOUND );
		}
		if ( ! in_array( $root['upload_state'] ?? null, $states, true ) ) {
			if ( 'scanning' === ( $root['upload_state'] ?? null ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Zip_Import_Failure::because( Zip_Import_Failure::PREVIEW_NOT_READY );
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::STATE_CONFLICT );
		}
	}

	/**
	 * Reject invalid item count.
	 *
	 * @param array<string,mixed>            $root Root.
	 * @param array<string,mixed>            $mapping Mapping.
	 * @param array<int,array<string,mixed>> $items Items.
	 * @return void
	 */
	private function assert_item_count( array $root, array $mapping, array $items ): void {
		$total = $this->non_negative_integer( $root['item_total'] ?? null );
		if ( count( $items ) !== $total || count( $mapping['items'] ) !== $total || $this->non_negative_integer( $root['success_total'] ?? null ) + $this->non_negative_integer( $root['failure_total'] ?? null ) !== $total ) {
			$this->stored_invalid();
		}
	}

	/**
	 * Reject invalid item identity.
	 *
	 * @param array<string,mixed> $item Item.
	 * @param array<string,mixed> $mapping_item Mapping item.
	 * @param int                 $number Number.
	 * @return void
	 */
	private function assert_item_identity( array $item, array $mapping_item, int $number ): void {
		if ( $number !== $this->positive_integer( $item['item_no'] ?? null ) || $number !== ( $mapping_item['item_no'] ?? null ) ) {
			$this->stored_invalid();
		}
	}

	/**
	 * Reject invalid mapped item.
	 *
	 * @param array<string,mixed> $item Item.
	 * @param array<string,mixed> $mapping_item Mapping item.
	 * @return void
	 */
	private function assert_mapped_item( array $item, array $mapping_item ): void {
		$selection = $this->selection( $mapping_item['selection'] ?? null );
		if ( null === $selection || $selection['learner_user_id'] !== $this->positive_integer( $item['learner_user_id'] ?? null ) || null === ( $item['file_public_id'] ?? null ) || null !== ( $item['error_code'] ?? null ) || null !== ( $item['result_submission_public_id'] ?? null ) ) {
			$this->stored_invalid();
		}
	}

	/**
	 * Reject invalid mapping version.
	 *
	 * @param array<string,mixed> $mapping Mapping.
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_mapping_version( array $mapping, array $record ): void {
		if ( $record['expected_mapping_version'] !== ( $mapping['mapping_revision'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::STATE_CONFLICT );
		}
	}

	/**
	 * Same context.
	 *
	 * @param array<string,mixed> $root Root.
	 * @param array<string,mixed> $record Record.
	 * @return bool
	 */
	private function same_context( array $root, array $record ): bool {
		return $record['authorized_context_type'] === ( $root['context_type'] ?? null )
			&& $record['authorized_context_id'] === ( $root['context_id'] ?? null )
			&& $record['authorized_context_revision'] === ( $root['context_revision'] ?? null );
	}

	/**
	 * Confirmation lease key.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return string
	 */
	private function confirmation_lease_key( array $record ): string {
		return 'submission-zip-confirm-lease:' . hash( 'sha256', $record['upload_id'] . '|' . $record['expected_mapping_version'] );
	}

	/**
	 * Reject invalid confirmation base.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param bool                $with_lease_id With lease id.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_confirmation_base( array $record, bool $with_lease_id ): void {
		$expected = array( 'actor_user_id', 'authorized_context_id', 'authorized_context_revision', 'authorized_context_type', 'command_id', 'correlation_id', 'expected_mapping_version', 'idempotency_storage_key', 'occurred_at_utc', 'reason', 'request_digest', 'upload_id' );
		if ( $with_lease_id ) {
			$expected[] = 'lease_outbox_public_id';
		}
		$this->record_keys( $record, $expected );
		$this->assert_common_record( $record );
		if ( $record['expected_mapping_version'] < 2 ) {
			throw new InvalidArgumentException( 'Submission ZIP confirmation mapping version is invalid.' );
		}
		$this->teacher_reason( $record['reason'] );
		if ( $with_lease_id ) {
			$this->public_id( $record['lease_outbox_public_id'] );
		}
	}

	/**
	 * Reject invalid confirmation item record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_confirmation_item_record( array $record ): void {
		$this->assert_confirmation_base( array_intersect_key( $record, array_flip( array( 'actor_user_id', 'authorized_context_id', 'authorized_context_revision', 'authorized_context_type', 'command_id', 'correlation_id', 'expected_mapping_version', 'idempotency_storage_key', 'lease_outbox_public_id', 'occurred_at_utc', 'reason', 'request_digest', 'upload_id' ) ) ), true );
		$this->record_keys(
			$record,
			array(
				'actor_user_id',
				'attachment_public_id',
				'authorized_context_id',
				'authorized_context_revision',
				'authorized_context_type',
				'command_id',
				'correlation_id',
				'expected_mapping_version',
				'idempotency_storage_key',
				'item_audit_public_id',
				'item_idempotency_key',
				'item_no',
				'item_outbox_public_id',
				'lease_outbox_public_id',
				'occurred_at_utc',
				'reason',
				'receipt_public_id',
				'request_digest',
				'revision_public_id',
				'submission_public_id',
				'upload_id',
			)
		);
		foreach ( array( 'attachment_public_id', 'item_audit_public_id', 'item_outbox_public_id', 'receipt_public_id', 'revision_public_id', 'submission_public_id' ) as $field ) {
			$this->public_id( $record[ $field ] );
		}
		if ( ! is_int( $record['item_no'] ) || $record['item_no'] < 1 || $record['item_no'] > 500 || 1 !== preg_match( '/^submission-zip-item:[a-f0-9]{64}$/D', $record['item_idempotency_key'] ) ) {
			throw new InvalidArgumentException( 'Submission ZIP confirmation item record is invalid.' );
		}
	}

	/**
	 * Reject invalid confirmation completion record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 */
	private function assert_confirmation_completion_record( array $record ): void {
		$this->assert_confirmation_base( array_intersect_key( $record, array_flip( array( 'actor_user_id', 'authorized_context_id', 'authorized_context_revision', 'authorized_context_type', 'command_id', 'correlation_id', 'expected_mapping_version', 'idempotency_storage_key', 'lease_outbox_public_id', 'occurred_at_utc', 'reason', 'request_digest', 'upload_id' ) ) ), true );
		$this->record_keys( $record, array( 'actor_user_id', 'audit_public_id', 'authorized_context_id', 'authorized_context_revision', 'authorized_context_type', 'command_id', 'correlation_id', 'expected_mapping_version', 'idempotency_storage_key', 'lease_outbox_public_id', 'occurred_at_utc', 'outbox_public_id', 'reason', 'request_digest', 'upload_id' ) );
		$this->public_id( $record['audit_public_id'] );
		$this->public_id( $record['outbox_public_id'] );
	}

	/**
	 * Reject invalid cancellation record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_cancellation_record( array $record ): void {
		$this->record_keys( $record, array( 'actor_user_id', 'audit_public_id', 'authorized_context_id', 'authorized_context_revision', 'authorized_context_type', 'cleanup_after_utc', 'command_id', 'correlation_id', 'expected_mapping_version', 'idempotency_storage_key', 'occurred_at_utc', 'outbox_public_id', 'request_digest', 'upload_id' ) );
		$this->assert_common_record( $record );
		if ( $record['expected_mapping_version'] < 0 ) {
			throw new InvalidArgumentException( 'Submission ZIP cancellation mapping version is invalid.' );
		}
		$this->public_id( $record['audit_public_id'] );
		$this->public_id( $record['outbox_public_id'] );
		$this->required_utc( $record['cleanup_after_utc'] );
		if ( $record['cleanup_after_utc'] !== $this->plus_day( $record['occurred_at_utc'] ) ) {
			throw new InvalidArgumentException( 'Submission ZIP cancellation cleanup time is invalid.' );
		}
	}

	/**
	 * Reject invalid common record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_common_record( array $record ): void {
		if ( ! is_int( $record['actor_user_id'] ?? null ) || $record['actor_user_id'] < 1 || ! is_int( $record['expected_mapping_version'] ?? null ) ) {
			throw new InvalidArgumentException( 'Submission ZIP finalization identity is invalid.' );
		}
		$this->public_id( $record['upload_id'] ?? null );
		$this->token( $record['command_id'] ?? null, 128 );
		$this->token( $record['correlation_id'] ?? null, 128 );
		$this->context_value( $record['authorized_context_type'] ?? null, 16 );
		$this->context_value( $record['authorized_context_id'] ?? null, 191 );
		$this->context_value( $record['authorized_context_revision'] ?? null, 191 );
		$this->hash_argument( $record['request_digest'] ?? '' );
		$this->required_utc( $record['occurred_at_utc'] ?? null );
		if ( str_starts_with( $record['idempotency_storage_key'] ?? '', 'submission-zip-confirm:' ) ) {
			$this->confirmation_storage_key( $record['idempotency_storage_key'] ); } else {
			$this->cancellation_storage_key( $record['idempotency_storage_key'] ?? '' );
			}
	}

	/**
	 * Record keys.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param array<int,string>   $expected Expected.
	 * @return void
	 */
	private function record_keys( array $record, array $expected ): void {
		$this->exact_keys( $record, $expected );
	}

	/**
	 * Selection.
	 *
	 * @param mixed $value Value.
	 * @return array{learner_user_id:int,response_id:string,target_id:string}|null
	 */
	private function selection( mixed $value ): ?array {
		if ( null === $value ) {
			return null;
		}
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			$this->stored_invalid();
		}
		$this->exact_keys( $value, array( 'learner_user_id', 'response_id', 'target_id' ) );
		return array(
			'learner_user_id' => $this->positive_integer( $value['learner_user_id'] ?? null ),
			'response_id'     => $this->public_id( $value['response_id'] ?? null ),
			'target_id'       => $this->public_id( $value['target_id'] ?? null ),
		);
	}

	/**
	 * Item state.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function item_state( mixed $value ): string {
		if ( ! is_string( $value ) || ! in_array( $value, array( 'ready', 'rejected', 'mapped', 'imported', 'failed', 'excluded' ), true ) ) {
			$this->stored_invalid();
		}
		return $value;
	}

	/**
	 * Submission state.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function submission_state( mixed $value ): string {
		if ( ! is_string( $value ) || ! in_array( $value, array( 'draft', 'conflict', 'submitted', 'auto_submitted', 'proxy_submitted', 'reopened', 'locked' ), true ) ) {
			$this->stored_invalid();
		}
		return $value;
	}

	/**
	 * Item error.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function item_error( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(?:zip_[a-z0-9_]{2,95}|submission_official_exists)$/D', $value ) ) {
			$this->stored_invalid();
		}
		return $value;
	}

	/**
	 * Teacher reason.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function teacher_reason( mixed $value ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) || $value !== trim( $value ) || strlen( $value ) > 500 || 1 !== preg_match( '//u', $value ) || 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value ) ) {
			throw new InvalidArgumentException( 'Submission ZIP teacher reason is invalid.' );
		}
		return $value;
	}

	/**
	 * Plus day.
	 *
	 * @param string $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function plus_day( string $value ): string {
		$time = strtotime( $this->required_utc( $value ) . ' UTC' );
		if ( false === $time ) {
			throw new InvalidArgumentException( 'Submission ZIP UTC value is invalid.' );
		} return gmdate( 'Y-m-d H:i:s', $time + 86400 );
	}

	/**
	 * Decode json.
	 *
	 * @param string $json Json.
	 * @param string $hash Hash.
	 * @return array<string,mixed>
	 */
	private function decode_json( string $json, string $hash ): array {
		if ( ! hash_equals( $hash, hash( 'sha256', $json ) ) ) {
			$this->stored_invalid();
		}
		try {
			$value = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			$this->stored_invalid();
		}
		if ( ! is_array( $value ) || array_is_list( $value ) || $this->canonical->encode( $value ) !== $json ) {
			$this->stored_invalid();
		}
		return $value;
	}

	/**
	 * Exact keys.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $expected Expected.
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
	 * Public id.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function public_id( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			$this->stored_invalid();
		} try {
			return ( new Public_Id( $value ) )->value();
		} catch ( Throwable ) {
			$this->stored_invalid();
		}
	}
	/**
	 * Optional public id.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private function optional_public_id( mixed $value ): ?string {
		return null === $value ? null : $this->public_id( $value );
	}
	/**
	 * Hash value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function hash_value( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			$this->stored_invalid();
		} return $value;
	}
	/**
	 * Positive integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private function positive_integer( mixed $value ): int {
		if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			$value = (int) $value;
		} if ( ! is_int( $value ) || $value < 1 ) {
			$this->stored_invalid();
		} return $value;
	}
	/**
	 * Non negative integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private function non_negative_integer( mixed $value ): int {
		if ( is_string( $value ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) {
			$value = (int) $value;
		} if ( ! is_int( $value ) || $value < 0 ) {
			$this->stored_invalid();
		} return $value;
	}
	/**
	 * Zero or one.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private function zero_or_one( mixed $value ): int {
		$value = $this->non_negative_integer( $value );
		if ( $value > 1 ) {
			$this->stored_invalid();
		} return $value;
	}
	/**
	 * Required utc.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function required_utc( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			$this->stored_invalid();
		} return $value;
	}
	/**
	 * Optional utc.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private function optional_utc( mixed $value ): ?string {
		return null === $value ? null : $this->required_utc( $value );
	}
	/**
	 * Filename.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function filename( mixed $value ): string {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 255 || 1 !== preg_match( '//u', $value ) || 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) || str_contains( $value, '/' ) || str_contains( $value, '\\' ) ) {
			$this->stored_invalid();
		} return $value;
	}
	/**
	 * Path.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function path( mixed $value ): string {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 512 || 1 !== preg_match( '//u', $value ) || 1 === preg_match( '/[\x00-\x1F\x7F\\\\]/', $value ) ) {
			$this->stored_invalid();
		} return $value;
	}
	/**
	 * Mime.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function mime( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9!#$&^_.+-]*\/[A-Za-z0-9][A-Za-z0-9!#$&^_.+-]*$/D', $value ) ) {
			$this->stored_invalid();
		} return $value;
	}
	/**
	 * Token.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function token( mixed $value, int $maximum ): string {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > $maximum || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $value ) ) {
			throw new InvalidArgumentException( 'Submission ZIP finalization token is invalid.' );
		} return $value;
	}
	/**
	 * Context value.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function context_value( mixed $value, int $maximum ): string {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > $maximum || 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
			throw new InvalidArgumentException( 'Submission ZIP finalization Context is invalid.' );
		} return $value;
	}
	/**
	 * Confirmation storage key.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function confirmation_storage_key( string $value ): void {
		if ( 1 !== preg_match( '/^submission-zip-confirm:[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Submission ZIP confirmation storage key is invalid.' );
		}
	}
	/**
	 * Cancellation storage key.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function cancellation_storage_key( string $value ): void {
		if ( 1 !== preg_match( '/^submission-zip-cancel:[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Submission ZIP cancellation storage key is invalid.' );
		}
	}
	/**
	 * Hash argument.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function hash_argument( string $value ): void {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Submission ZIP finalization hash is invalid.' );
		}
	}
	/**
	 * Stored invalid.
	 *
	 * @return never
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function stored_invalid(): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Zip_Import_Failure::because( Zip_Import_Failure::STORED_DATA_INVALID );
	}

	/**
	 * Prepare.
	 *
	 * @param string           $sql Sql.
	 * @param array<int,mixed> $values Values.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function prepare( string $sql, array $values ): string {
		$prepared = $this->database()->prepare( $sql, ...$values );
		if ( ! is_string( $prepared ) || '' === $prepared ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
		} return $prepared;
	}
	/**
	 * Get row.
	 *
	 * @param string $sql Sql.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function get_row( string $sql ): ?array {
		$db = $this->database();
		$this->reset_error( $db );
		$row = $db->get_row( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		$this->assert_database_ok( $db );
		if ( null === $row ) {
			return null;
		} if ( ! is_array( $row ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
		} return $row;
	}
	/**
	 * Get results.
	 *
	 * @param string $sql Sql.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function get_results( string $sql ): array {
		$db = $this->database();
		$this->reset_error( $db );
		$rows = $db->get_results( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		$this->assert_database_ok( $db );
		if ( ! is_array( $rows ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
		} foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
			}
		} return $rows;
	}
	/**
	 * Get var.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 */
	private function get_var( string $sql ): mixed {
		$db = $this->database();
		$this->reset_error( $db );
		$value = $db->get_var( $sql );
		$this->assert_database_ok( $db );
		return $value;
	}
	/**
	 * Query or fail.
	 *
	 * @param string $sql Sql.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function query_or_fail( string $sql ): void {
		$db = $this->database();
		$this->reset_error( $db );
		$result = $db->query( $sql );
		$this->assert_database_ok( $db );
		if ( false === $result ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
		}
	}
	/**
	 * Change count.
	 *
	 * @param string $sql Sql.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function change_count( string $sql ): int {
		$db = $this->database();
		$this->reset_error( $db );
		$result = $db->query( $sql );
		$this->assert_database_ok( $db );
		if ( ! is_int( $result ) || $result < 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
		} return $result;
	}
	/**
	 * Insert or fail.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function insert_or_fail( string $table, array $record ): void {
		$db = $this->database();
		$this->reset_error( $db );
		$inserted = $db->insert( $table, $record );
		$this->assert_database_ok( $db );
		if ( false === $inserted || 1 !== (int) $inserted ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
		}
	}
	/**
	 * Insert id.
	 *
	 * @return int
	 */
	private function insert_id(): int {
		return $this->positive_integer( $this->database()->insert_id ?? null );
	}
	/**
	 * Rollback.
	 *
	 * @return void
	 */
	private function rollback(): void {
		try {
			$this->query_or_fail( 'ROLLBACK' ); } catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
			// Best-effort recovery must not replace the primary failure.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Best-effort recovery must not replace the primary failure.
			}
	}
	/**
	 * Database.
	 *
	 * @return object
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function database(): object {
		$db = $this->database ?? ( $GLOBALS['wpdb'] ?? null );
		if ( ! is_object( $db ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
		} foreach ( array( 'prepare', 'get_row', 'get_results', 'get_var', 'query', 'insert' ) as $method ) {
			if ( ! method_exists( $db, $method ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
			}
		} return $db;
	}
	/**
	 * Reset error.
	 *
	 * @param object $db Db.
	 * @return void
	 */
	private function reset_error( object $db ): void {
		if ( property_exists( $db, 'last_error' ) ) {
			$db->last_error = '';
		}
	}
	/**
	 * Reject invalid database ok.
	 *
	 * @param object $db Db.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_database_ok( object $db ): void {
		if ( '' !== trim( (string) ( $db->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
		}
	}
}
