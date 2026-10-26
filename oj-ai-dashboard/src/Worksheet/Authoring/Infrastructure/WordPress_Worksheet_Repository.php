<?php
/**
 * WordPress worksheet repository.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Infrastructure;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Storage\Infrastructure\WordPress_Worksheet_Asset_Binder;
use OJAI\Dashboard\Worksheet\Authoring\Application\Duplicate_Worksheet_Result;
use OJAI\Dashboard\Storage\Port\Worksheet_Asset_Binder_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Application\Create_Worksheet_Result;
use OJAI\Dashboard\Worksheet\Authoring\Application\Save_Worksheet_Draft_Result;
use OJAI\Dashboard\Worksheet\Authoring\Application\Worksheet_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Initial_Document_Factory;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Profile;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Revision_Projector;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Duplicate_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Package_Source_Query_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Repository_Port_Interface;
use RuntimeException;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns SQL for Worksheet aggregate transactions and their operations records.
 */
final class WordPress_Worksheet_Repository implements Worksheet_Repository_Port_Interface, Worksheet_Duplicate_Repository_Port_Interface, Worksheet_Package_Source_Query_Port_Interface {
	private const TABLE_WORKSHEETS      = 'oj_ai_worksheets';
	private const TABLE_REVISIONS       = 'oj_ai_worksheet_revisions';
	private const TABLE_QUESTIONS       = 'oj_ai_worksheet_revision_questions';
	private const TABLE_WORKSHEET_LINKS = 'oj_ai_worksheet_achievement_links';
	private const TABLE_QUESTION_LINKS  = 'oj_ai_question_achievement_links';
	private const TABLE_AUDIT           = 'oj_ai_audit_events';
	private const TABLE_PUBLICATIONS    = 'oj_ai_worksheet_publications';
	private const TABLE_OUTBOX          = 'oj_ai_outbox_events';

	private const AUDIT_METADATA_SCHEMA           = 'oj_ai.audit_metadata';
	private const OUTBOX_PAYLOAD_SCHEMA           = 'oj_ai.worksheet_created_event';
	private const PROFILE_VERSION                 = 1;
	private const SAVE_OUTBOX_PAYLOAD_SCHEMA      = 'oj_ai.worksheet_draft_saved_event';
	private const SAVE_EVENT_TYPE                 = 'worksheet.draft_saved';
	private const DUPLICATE_OUTBOX_PAYLOAD_SCHEMA = 'oj_ai.worksheet_duplicated_event';
	private const DUPLICATE_EVENT_TYPE            = 'worksheet.duplicated';


	/**
	 * Database.
	 *
	 * @var object|null
	 */
	private ?object $database;
	/**
	 * Canonical JSON encoder used for stable digests.
	 *
	 * @var Canonical_Json
	 */
	private Canonical_Json $canonical;
	/**
	 * Cursors.
	 *
	 * @var Signed_Cursor_Codec
	 */
	private Signed_Cursor_Codec $cursors;

	/**
	 * Assets.
	 *
	 * @var Worksheet_Asset_Binder_Port_Interface
	 */
	private Worksheet_Asset_Binder_Port_Interface $assets;

	/**
	 * Initialize WordPress worksheet repository dependencies and state.
	 *
	 * @param object|null                                                                    $database Database.
	 * @param Canonical_Json|null                                                            $canonical Canonical JSON encoder used for stable digests.
	 * @param Signed_Cursor_Codec|null                                                       $cursors Cursors.
	 * @param Worksheet_Asset_Binder_Port_Interface|null                                     $assets Assets.
	 * @param \OJAI\Dashboard\Assessment\Project\Port\Project_Repository_Port_Interface|null $projects Projects.
	 */
	public function __construct(
		?object $database = null,
		?Canonical_Json $canonical = null,
		?Signed_Cursor_Codec $cursors = null,
		?Worksheet_Asset_Binder_Port_Interface $assets = null,
		private ?\OJAI\Dashboard\Assessment\Project\Port\Project_Repository_Port_Interface $projects = null
	) {
		$this->database  = $database;
		$this->canonical = $canonical ?? new Canonical_Json();
		$this->cursors   = $cursors ?? new Signed_Cursor_Codec( $this->canonical );
		$this->assets    = $assets ?? new WordPress_Worksheet_Asset_Binder( $database );
	}

	/**
	 * Create initial.
	 *
	 * @param array $record Record.
	 * @return Create_Worksheet_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function create_initial( array $record ): Create_Worksheet_Result {
		$this->assert_initial_record( $record );
		$replay = $this->find_idempotent_receipt( $record, false );
		if ( $replay instanceof Create_Worksheet_Result ) {
			return $replay;
		}

		$database            = $this->database();
		$transaction_started = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction_started = true;

			$replay = $this->find_idempotent_receipt( $record, true );
			if ( $replay instanceof Create_Worksheet_Result ) {
				$this->query_or_fail( 'COMMIT' );
				return $replay;
			}

			$this->insert_or_fail(
				self::TABLE_WORKSHEETS,
				array(
					'public_id'                    => $record['worksheet_public_id'],
					'lock_version'                 => 0,
					'created_by_user_id'           => $record['actor_user_id'],
					'created_at_utc'               => $record['created_at_utc'],
					'updated_by_user_id'           => $record['actor_user_id'],
					'updated_at_utc'               => $record['created_at_utc'],
					'title'                        => $record['title'],
					'owner_user_id'                => $record['actor_user_id'],
					'state'                        => 'draft',
					'current_revision_public_id'   => $record['revision_public_id'],
					'latest_publication_public_id' => null,
					'archived_at_utc'              => null,
				),
				array( '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
			);
			$worksheet_pk = $this->insert_id( $database );

			$this->insert_or_fail(
				self::TABLE_REVISIONS,
				array(
					'public_id'                 => $record['revision_public_id'],
					'content_hash'              => $record['document_hash'],
					'created_by_user_id'        => $record['actor_user_id'],
					'created_at_utc'            => $record['created_at_utc'],
					'worksheet_id'              => $worksheet_pk,
					'revision_no'               => 1,
					'state'                     => 'draft',
					'source_revision_id'        => null,
					'editor_profile_version'    => Initial_Document_Factory::EDITOR_PROFILE,
					'document_schema_version'   => Initial_Document_Factory::DOCUMENT_SCHEMA_VERSION,
					'teacher_document_schema'   => Initial_Document_Factory::DOCUMENT_SCHEMA,
					'teacher_document_version'  => self::PROFILE_VERSION,
					'teacher_document_json'     => $record['document_json'],
					'teacher_document_hash'     => $record['document_hash'],
					'document_hash'             => $record['document_hash'],
					'validation_schema_version' => Initial_Document_Factory::VALIDATION_VERSION,
					'validation_hash'           => $record['validation_hash'],
					'approved_by_user_id'       => null,
					'approved_at_utc'           => null,
				),
				array(
					'%s',
					'%s',
					'%d',
					'%s',
					'%d',
					'%d',
					'%s',
					'%d',
					'%s',
					'%s',
					'%s',
					'%d',
					'%s',
					'%s',
					'%s',
					'%s',
					'%s',
					'%d',
					'%s',
				)
			);
			$revision_pk = $this->insert_id( $database );

			if ( null !== ( $record['assessment_project'] ?? null ) ) {
				if ( null === $this->projects ) {
					throw Worksheet_Failure::storage_unavailable();
				}
				$this->projects->attach_created( $worksheet_pk, $record['actor_user_id'], $record['assessment_project'] );
			}
			$this->insert_audit_event( $record );
			$this->insert_outbox_event( $record );
			$this->assert_initial_postcondition( $worksheet_pk, $revision_pk, $record );

			$this->query_or_fail( 'COMMIT' );
			$transaction_started = false;

			return $this->result_from_record( $record, false );
		} catch ( Worksheet_Failure | \OJAI\Dashboard\Assessment\Project\Domain\Project_Failure $failure ) {
			if ( $transaction_started ) {
				$this->rollback();
			}
			throw $failure;
		} catch ( Throwable ) {
			if ( $transaction_started ) {
				$this->rollback();
			}

			$replay = $this->find_idempotent_receipt( $record, false );
			if ( $replay instanceof Create_Worksheet_Result ) {
				return $replay;
			}
			throw Worksheet_Failure::storage_unavailable();
		}
	}
	/**
	 * Find duplicate receipt.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $idempotency_storage_key Idempotency storage key.
	 * @param string $request_digest Request digest.
	 * @return Duplicate_Worksheet_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_duplicate_receipt(
		int $actor_user_id,
		string $idempotency_storage_key,
		string $request_digest
	): ?Duplicate_Worksheet_Result {
		if ( $actor_user_id < 1 ) {
			throw new InvalidArgumentException( 'Duplicate worksheet receipt actor is invalid.' );
		}
		foreach ( array( $idempotency_storage_key, $request_digest ) as $hash ) {
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
				throw new InvalidArgumentException( 'Duplicate worksheet receipt digest is invalid.' );
			}
		}
		return $this->find_duplicate_idempotent_receipt(
			$actor_user_id,
			$idempotency_storage_key,
			$request_digest,
			false
		);
	}

	/**
	 * Find owned duplicate source.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $worksheet_id Worksheet public identifier.
	 * @return array|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_owned_duplicate_source( int $actor_user_id, string $worksheet_id ): ?array {
		$root = $this->find_owned_worksheet( $actor_user_id, $worksheet_id );
		if ( null === $root ) {
			return null;
		}
		$current_revision_id = (string) ( $root['current_revision_id'] ?? '' );
		$revision            = $this->find_owned_revision( $actor_user_id, $current_revision_id );
		if (
			null === $revision
			|| $worksheet_id !== (string) ( $revision['worksheet_id'] ?? '' )
			|| (string) ( $root['title'] ?? '' ) !== (string) ( $revision['teacher_document']['title'] ?? '' )
		) {
			throw Worksheet_Failure::stored_data_invalid();
		}

		$sql = 'SELECT r.id, r.content_hash FROM ' . self::TABLE_REVISIONS . ' r '
			. 'INNER JOIN ' . self::TABLE_WORKSHEETS . ' w ON w.id = r.worksheet_id '
			. 'WHERE r.public_id = %s AND w.public_id = %s AND w.owner_user_id = %d LIMIT 1';
		$row = $this->get_row( $this->prepare( $sql, array( $current_revision_id, $worksheet_id, $actor_user_id ) ) );
		if ( null === $row ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
		$revision_pk  = $this->positive_integer( $row['id'] ?? null );
		$content_hash = (string) ( $row['content_hash'] ?? '' );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $content_hash ) ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
		$link_source = $this->duplicate_link_source( $revision_pk );

		return array(
			'title'                => (string) $root['title'],
			'state'                => (string) $root['state'],
			'current_revision_id'  => $current_revision_id,
			'source_document_hash' => (string) $revision['revision']['document_hash'],
			'source_content_hash'  => $content_hash,
			'teacher_document'     => $revision['teacher_document'],
			'achievement_links'    => $link_source['achievement_links'],
			'standard_snapshots'   => $link_source['standard_snapshots'],
		);
	}

	/**
	 * Duplicate initial.
	 *
	 * @param array $record Record.
	 * @return Duplicate_Worksheet_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function duplicate_initial( array $record ): Duplicate_Worksheet_Result {
		$this->assert_duplicate_record( $record );
		$replay = $this->find_duplicate_idempotent_receipt(
			(int) $record['actor_user_id'],
			(string) $record['idempotency_storage_key'],
			(string) $record['request_digest'],
			false
		);
		if ( $replay instanceof Duplicate_Worksheet_Result ) {
			return $replay;
		}

		$transaction_started = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction_started = true;
			$replay              = $this->find_duplicate_idempotent_receipt(
				(int) $record['actor_user_id'],
				(string) $record['idempotency_storage_key'],
				(string) $record['request_digest'],
				true
			);
			if ( $replay instanceof Duplicate_Worksheet_Result ) {
				$this->query_or_fail( 'COMMIT' );
				$transaction_started = false;
				return $replay;
			}

			$source_root = $this->lock_owned_root(
				(int) $record['actor_user_id'],
				(string) $record['source_worksheet_public_id']
			);
			if ( null === $source_root ) {
				throw Worksheet_Failure::not_found();
			}
			$source_revision = $this->lock_current_revision(
				(int) $source_root['id'],
				(string) $source_root['current_revision_public_id']
			);
			if ( null === $source_revision ) {
				throw Worksheet_Failure::stored_data_invalid();
			}
			if (
				(string) $record['expected_state'] !== (string) $source_root['state']
				|| (string) $record['expected_revision_public_id'] !== (string) $source_revision['public_id']
				|| ! hash_equals( (string) $record['source_document_hash'], (string) $source_revision['document_hash'] )
			) {
				throw Worksheet_Failure::revision_conflict(
					(string) $record['expected_revision_public_id'],
					(string) $source_revision['public_id'],
					(string) $source_revision['document_hash']
				);
			}

			$this->insert_or_fail(
				self::TABLE_WORKSHEETS,
				array(
					'public_id'                    => $record['worksheet_public_id'],
					'lock_version'                 => 0,
					'created_by_user_id'           => $record['actor_user_id'],
					'created_at_utc'               => $record['saved_at_utc'],
					'updated_by_user_id'           => $record['actor_user_id'],
					'updated_at_utc'               => $record['saved_at_utc'],
					'title'                        => $record['title'],
					'owner_user_id'                => $record['actor_user_id'],
					'state'                        => 'draft',
					'current_revision_public_id'   => $record['revision_public_id'],
					'latest_publication_public_id' => null,
					'archived_at_utc'              => null,
				),
				array( '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
			);
			$worksheet_pk = $this->insert_id( $this->database() );

			$this->insert_or_fail(
				self::TABLE_REVISIONS,
				array(
					'public_id'                 => $record['revision_public_id'],
					'content_hash'              => $record['content_hash'],
					'created_by_user_id'        => $record['actor_user_id'],
					'created_at_utc'            => $record['saved_at_utc'],
					'worksheet_id'              => $worksheet_pk,
					'revision_no'               => 1,
					'state'                     => 'draft',
					'source_revision_id'        => $source_revision['id'],
					'editor_profile_version'    => Worksheet_Document_Profile::EDITOR_PROFILE,
					'document_schema_version'   => Worksheet_Document_Profile::SCHEMA_VERSION,
					'teacher_document_schema'   => Worksheet_Document_Profile::SCHEMA_ID,
					'teacher_document_version'  => self::PROFILE_VERSION,
					'teacher_document_json'     => $record['document_json'],
					'teacher_document_hash'     => $record['document_hash'],
					'document_hash'             => $record['document_hash'],
					'validation_schema_version' => Worksheet_Document_Profile::VALIDATION_VERSION,
					'validation_hash'           => $record['validation_hash'],
					'approved_by_user_id'       => null,
					'approved_at_utc'           => null,
				),
				array(
					'%s',
					'%s',
					'%d',
					'%s',
					'%d',
					'%d',
					'%s',
					'%d',
					'%s',
					'%s',
					'%s',
					'%d',
					'%s',
					'%s',
					'%s',
					'%s',
					'%s',
					'%d',
					'%s',
				)
			);
			$revision_pk  = $this->insert_id( $this->database() );
			$question_ids = $this->insert_revision_questions( $revision_pk, $record );
			$this->insert_revision_achievement_links( $revision_pk, $question_ids, $record );
			$asset_receipts = $this->bind_asset_receipts( $record );
			if ( null !== ( $record['assessment_project'] ?? null ) ) {
				if ( null === $this->projects ) {
					throw Worksheet_Failure::storage_unavailable();
				}
				$this->projects->attach_created( $worksheet_pk, $record['actor_user_id'], $record['assessment_project'] );
			}
			$this->insert_duplicate_audit_event( $record, $source_revision, $asset_receipts );
			$this->insert_duplicate_outbox_event( $record, $asset_receipts );
			$this->assert_save_postcondition(
				$worksheet_pk,
				(string) $record['revision_public_id'],
				0,
				$revision_pk
			);
			$this->query_or_fail( 'COMMIT' );
			$transaction_started = false;
			return $this->duplicate_result_from_record( $record, false );
		} catch ( Worksheet_Failure | \OJAI\Dashboard\Assessment\Project\Domain\Project_Failure $failure ) {
			if ( $transaction_started ) {
				$this->rollback();
			}
			throw $failure;
		} catch ( Throwable ) {
			if ( $transaction_started ) {
				$this->rollback();
			}
			$replay = $this->find_duplicate_idempotent_receipt(
				(int) $record['actor_user_id'],
				(string) $record['idempotency_storage_key'],
				(string) $record['request_digest'],
				false
			);
			if ( $replay instanceof Duplicate_Worksheet_Result ) {
				return $replay;
			}
			throw Worksheet_Failure::storage_unavailable();
		}
	}



	/**
	 * Append draft revision.
	 *
	 * @param array $record Record.
	 * @return Save_Worksheet_Draft_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function append_draft_revision( array $record ): Save_Worksheet_Draft_Result {
		$this->assert_save_record( $record );
		$transaction_started = false;
		try {
			$replay = $this->find_save_idempotent_receipt( $record, false );
			if ( $replay instanceof Save_Worksheet_Draft_Result ) {
				return $replay;
			}

			$this->query_or_fail( 'START TRANSACTION' );
			$transaction_started = true;

			$replay = $this->find_save_idempotent_receipt( $record, true );
			if ( $replay instanceof Save_Worksheet_Draft_Result ) {
				$this->query_or_fail( 'COMMIT' );
				$transaction_started = false;
				return $replay;
			}

			$root = $this->lock_owned_root(
				(int) $record['actor_user_id'],
				(string) $record['worksheet_public_id']
			);
			if ( null === $root ) {
				throw Worksheet_Failure::not_found();
			}
			$current = $this->lock_current_revision(
				(int) $root['id'],
				(string) $root['current_revision_public_id']
			);
			if ( null === $current ) {
				throw Worksheet_Failure::stored_data_invalid();
			}
			if (
				(string) $record['expected_revision_public_id'] !== $current['public_id']
				|| (string) $record['expected_state'] !== $root['state']
			) {
				throw Worksheet_Failure::revision_conflict(
					(string) $record['expected_revision_public_id'],
					(string) $current['public_id'],
					(string) $current['document_hash']
				);
			}

			if ( null !== $this->projects ) {
				$document = json_decode( $record['document_json'], true, 128, JSON_THROW_ON_ERROR );
				$this->projects->sync_draft_pin( (string) $record['worksheet_public_id'], $document['settings']['assessment_project'] ?? null, (int) $record['actor_user_id'] );
			}
			$asset_receipts = $this->bind_asset_receipts( $record );
			if ( hash_equals( (string) $current['content_hash'], (string) $record['content_hash'] ) ) {
				$result = $this->save_result(
					$record,
					(string) $current['public_id'],
					(int) $current['revision_no'],
					(string) $current['state'],
					(string) $current['public_id'],
					(string) $root['state'],
					true
				);
				$this->insert_save_outbox_event( $record, $result, $asset_receipts );
				$this->query_or_fail( 'COMMIT' );
				$transaction_started = false;
				return $result;
			}

			$revision_no = (int) $current['revision_no'] + 1;
			$this->insert_or_fail(
				self::TABLE_REVISIONS,
				array(
					'public_id'                 => $record['revision_public_id'],
					'content_hash'              => $record['content_hash'],
					'created_by_user_id'        => $record['actor_user_id'],
					'created_at_utc'            => $record['saved_at_utc'],
					'worksheet_id'              => $root['id'],
					'revision_no'               => $revision_no,
					'state'                     => 'draft',
					'source_revision_id'        => $current['id'],
					'editor_profile_version'    => Worksheet_Document_Profile::EDITOR_PROFILE,
					'document_schema_version'   => Worksheet_Document_Profile::SCHEMA_VERSION,
					'teacher_document_schema'   => Worksheet_Document_Profile::SCHEMA_ID,
					'teacher_document_version'  => self::PROFILE_VERSION,
					'teacher_document_json'     => $record['document_json'],
					'teacher_document_hash'     => $record['document_hash'],
					'document_hash'             => $record['document_hash'],
					'validation_schema_version' => Worksheet_Document_Profile::VALIDATION_VERSION,
					'validation_hash'           => $record['validation_hash'],
					'approved_by_user_id'       => null,
					'approved_at_utc'           => null,
				),
				array(
					'%s',
					'%s',
					'%d',
					'%s',
					'%d',
					'%d',
					'%s',
					'%d',
					'%s',
					'%s',
					'%s',
					'%d',
					'%s',
					'%s',
					'%s',
					'%s',
					'%s',
					'%d',
					'%s',
				)
			);
			$revision_pk  = $this->insert_id( $this->database() );
			$question_ids = $this->insert_revision_questions( $revision_pk, $record );
			$this->insert_revision_achievement_links( $revision_pk, $question_ids, $record );

			$update_sql = 'UPDATE ' . self::TABLE_WORKSHEETS
				. ' SET title = %s, current_revision_public_id = %s, lock_version = lock_version + 1, '
				. 'updated_by_user_id = %d, updated_at_utc = %s '
				. 'WHERE id = %d AND lock_version = %d AND current_revision_public_id = %s AND state = %s';
			$this->query_exactly_one_or_fail(
				$this->prepare(
					$update_sql,
					array(
						$record['title'],
						$record['revision_public_id'],
						$record['actor_user_id'],
						$record['saved_at_utc'],
						$root['id'],
						$root['lock_version'],
						$current['public_id'],
						$root['state'],
					)
				)
			);

			$result = $this->save_result(
				$record,
				(string) $record['revision_public_id'],
				$revision_no,
				'draft',
				(string) $current['public_id'],
				(string) $root['state'],
				false
			);
			$this->insert_save_audit_event( $record, $current, $asset_receipts );
			$this->insert_save_outbox_event( $record, $result, $asset_receipts );
			$this->assert_save_postcondition(
				(int) $root['id'],
				(string) $record['revision_public_id'],
				(int) $root['lock_version'] + 1,
				$revision_pk
			);
			$this->query_or_fail( 'COMMIT' );
			$transaction_started = false;
			return $result;
		} catch ( \OJAI\Dashboard\Assessment\Project\Domain\Project_Failure $failure ) {
			if ( $transaction_started ) {
				$this->rollback();
			}
			throw $failure;
		} catch ( Worksheet_Failure $failure ) {
			if ( $transaction_started ) {
				$this->rollback();
			}
			throw $failure;
		} catch ( Throwable ) {
			if ( $transaction_started ) {
				$this->rollback();
			}
			$replay = $this->find_save_idempotent_receipt( $record, false );
			if ( $replay instanceof Save_Worksheet_Draft_Result ) {
				return $replay;
			}
			throw Worksheet_Failure::storage_unavailable();
		}
	}

	/**
	 * List owned.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $state State.
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function list_owned( int $actor_user_id, string $state, string $cursor, int $limit ): array {
		$cursor_payload = $this->cursors->decode( $cursor, 'worksheet_list' );
		$sql            = 'SELECT w.public_id, w.title, w.state, w.current_revision_public_id, w.latest_publication_public_id, publication.publication_hash AS latest_publication_hash, w.lock_version, w.created_at_utc, w.updated_at_utc FROM ' . self::TABLE_WORKSHEETS . ' w LEFT JOIN ' . self::TABLE_PUBLICATIONS
			. ' publication ON publication.public_id = w.latest_publication_public_id WHERE w.owner_user_id = %d';
		$values         = array( $actor_user_id );

		if ( '' !== $state ) {
			$sql     .= ' AND w.state = %s';
			$values[] = $state;
		}
		if ( array() !== $cursor_payload ) {
			$this->assert_exact_keys( $cursor_payload, array( 'i', 's', 't' ) );
			if ( ( $cursor_payload['s'] ?? null ) !== $state ) {
				throw new InvalidArgumentException( 'Worksheet cursor filter does not match.' );
			}
			$cursor_id   = (string) $cursor_payload['i'];
			$cursor_time = (string) $cursor_payload['t'];
			new Public_Id( $cursor_id );
			$this->assert_utc( $cursor_time );
			$sql     .= ' AND (w.updated_at_utc < %s OR (w.updated_at_utc = %s AND w.public_id < %s))';
			$values[] = $cursor_time;
			$values[] = $cursor_time;
			$values[] = $cursor_id;
		}

		$sql     .= ' ORDER BY w.updated_at_utc DESC, w.public_id DESC LIMIT %d';
		$values[] = $limit + 1;
		$rows     = $this->get_results( $this->prepare( $sql, $values ) );
		$has_more = count( $rows ) > $limit;
		if ( $has_more ) {
			array_pop( $rows );
		}

		$items = array_map( fn ( array $row ): array => $this->worksheet_projection( $row ), $rows );
		$next  = '';
		if ( $has_more && array() !== $items ) {
			$last = $items[ count( $items ) - 1 ];
			$next = $this->cursors->encode(
				'worksheet_list',
				array(
					'i' => $last['id'],
					's' => $state,
					't' => $last['updated_at_utc'],
				)
			);
		}

		return array(
			'items'       => $items,
			'next_cursor' => $next,
		);
	}

	/**
	 * Find owned worksheet.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $worksheet_id Worksheet public identifier.
	 * @return array|null
	 */
	public function find_owned_worksheet( int $actor_user_id, string $worksheet_id ): ?array {
		$sql = 'SELECT w.public_id, w.title, w.state, w.current_revision_public_id, w.latest_publication_public_id, publication.publication_hash AS latest_publication_hash, w.lock_version, w.created_at_utc, w.updated_at_utc FROM ' . self::TABLE_WORKSHEETS . ' w LEFT JOIN ' . self::TABLE_PUBLICATIONS
			. ' publication ON publication.public_id = w.latest_publication_public_id '
			. 'WHERE w.public_id = %s AND w.owner_user_id = %d LIMIT 1';
		$row = $this->get_row( $this->prepare( $sql, array( $worksheet_id, $actor_user_id ) ) );

		return null === $row ? null : $this->worksheet_projection( $row );
	}

	/**
	 * List owned revisions.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $worksheet_id Worksheet public identifier.
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function list_owned_revisions(
		int $actor_user_id,
		string $worksheet_id,
		string $cursor,
		int $limit
	): ?array {
		if ( null === $this->find_owned_worksheet( $actor_user_id, $worksheet_id ) ) {
			return null;
		}

		$cursor_payload = $this->cursors->decode( $cursor, 'worksheet_revision_list' );
		$sql            = 'SELECT r.public_id, r.revision_no, r.state, source.public_id AS source_revision_public_id, r.editor_profile_version, r.document_schema_version, r.document_hash, r.validation_schema_version, r.validation_hash, r.created_at_utc, publication.public_id AS publication_public_id, publication.state AS publication_state FROM ' . self::TABLE_REVISIONS . ' r '
			. 'INNER JOIN ' . self::TABLE_WORKSHEETS . ' w ON w.id = r.worksheet_id '
			. 'LEFT JOIN ' . self::TABLE_REVISIONS . ' source ON source.id = r.source_revision_id '
			. 'LEFT JOIN ' . self::TABLE_PUBLICATIONS . ' publication ON publication.worksheet_revision_id = r.id '
			. 'WHERE w.public_id = %s AND w.owner_user_id = %d';
		$values         = array( $worksheet_id, $actor_user_id );

		if ( array() !== $cursor_payload ) {
			$this->assert_exact_keys( $cursor_payload, array( 'i', 'n', 'w' ) );
			if ( ( $cursor_payload['w'] ?? null ) !== $worksheet_id ) {
				throw new InvalidArgumentException( 'Worksheet revision cursor scope does not match.' );
			}
			$cursor_id = (string) $cursor_payload['i'];
			$cursor_no = $this->positive_integer( $cursor_payload['n'] ?? null );
			new Public_Id( $cursor_id );
			$sql     .= ' AND (r.revision_no < %d OR (r.revision_no = %d AND r.public_id < %s))';
			$values[] = $cursor_no;
			$values[] = $cursor_no;
			$values[] = $cursor_id;
		}

		$sql     .= ' ORDER BY r.revision_no DESC, r.public_id DESC LIMIT %d';
		$values[] = $limit + 1;
		$rows     = $this->get_results( $this->prepare( $sql, $values ) );
		$has_more = count( $rows ) > $limit;
		if ( $has_more ) {
			array_pop( $rows );
		}

		$items = array_map( fn ( array $row ): array => $this->revision_projection( $row ), $rows );
		$next  = '';
		if ( $has_more && array() !== $items ) {
			$last = $items[ count( $items ) - 1 ];
			$next = $this->cursors->encode(
				'worksheet_revision_list',
				array(
					'i' => $last['id'],
					'n' => $last['revision_no'],
					'w' => $worksheet_id,
				)
			);
		}

		return array(
			'items'       => $items,
			'next_cursor' => $next,
		);
	}

	/**
	 * Find owned revision.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $revision_id Revision id.
	 * @return array|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_owned_revision( int $actor_user_id, string $revision_id ): ?array {
		$sql = 'SELECT r.id AS revision_internal_id, r.public_id, r.revision_no, r.state, source.public_id AS source_revision_public_id, r.editor_profile_version, r.document_schema_version, r.teacher_document_schema, r.teacher_document_version, r.teacher_document_json, r.teacher_document_hash, r.document_hash, r.validation_schema_version, r.validation_hash, r.created_at_utc, w.public_id AS worksheet_public_id, publication.public_id AS publication_public_id, publication.state AS publication_state FROM ' . self::TABLE_REVISIONS . ' r '
			. 'INNER JOIN ' . self::TABLE_WORKSHEETS . ' w ON w.id = r.worksheet_id '
			. 'LEFT JOIN ' . self::TABLE_REVISIONS . ' source ON source.id = r.source_revision_id '
			. 'LEFT JOIN ' . self::TABLE_PUBLICATIONS . ' publication ON publication.worksheet_revision_id = r.id '
			. 'WHERE r.public_id = %s AND w.owner_user_id = %d LIMIT 1';
		$row = $this->get_row( $this->prepare( $sql, array( $revision_id, $actor_user_id ) ) );
		if ( null === $row ) {
			return null;
		}

		$revision = $this->revision_projection( $row );
		if (
			Initial_Document_Factory::DOCUMENT_SCHEMA !== (string) ( $row['teacher_document_schema'] ?? '' )
			|| self::PROFILE_VERSION !== $this->positive_integer( $row['teacher_document_version'] ?? null )
			|| $revision['document_hash'] !== (string) ( $row['teacher_document_hash'] ?? '' )
		) {
			throw Worksheet_Failure::stored_data_invalid();
		}

		$document_json = (string) ( $row['teacher_document_json'] ?? '' );
		$document      = $this->decode_canonical_json( $document_json, $revision['document_hash'] );
		if (
			( $document['schema_id'] ?? null ) !== Initial_Document_Factory::DOCUMENT_SCHEMA
			|| ( $document['schema_version'] ?? null ) !== $revision['document_schema_version']
		) {
			throw Worksheet_Failure::stored_data_invalid();
		}

		$revision_pk = $this->positive_integer( $row['revision_internal_id'] ?? null );
		$questions   = $this->question_projections( $revision_pk );
		$links       = $this->duplicate_link_source( $revision_pk );

		return array(
			'worksheet_id'       => (string) $row['worksheet_public_id'],
			'revision'           => $revision,
			'teacher_document'   => $document,
			'questions'          => $questions,
			'achievement_links'  => $links['achievement_links'],
			'standard_snapshots' => $links['standard_snapshots'],
		);
	}

	/**
	 * Find owned package source.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $revision_id Revision id.
	 * @return array|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_owned_package_source( int $actor_user_id, string $revision_id ): ?array {
		$source = $this->find_owned_revision( $actor_user_id, $revision_id );
		if ( null === $source ) {
			return null;
		}
		$sql = 'SELECT r.id FROM ' . self::TABLE_REVISIONS . ' r '
			. 'INNER JOIN ' . self::TABLE_WORKSHEETS . ' w ON w.id = r.worksheet_id '
			. 'WHERE r.public_id = %s AND w.owner_user_id = %d LIMIT 1';
		$row = $this->get_row( $this->prepare( $sql, array( $revision_id, $actor_user_id ) ) );
		if ( null === $row ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
		$links = $this->duplicate_link_source( $this->positive_integer( $row['id'] ?? null ) );

		return array(
			'achievement_links' => $links['achievement_links'],
			'revision_id'       => $revision_id,
			'source_hash'       => (string) $source['revision']['document_hash'],
			'source_state'      => (string) $source['revision']['state'],
			'teacher_document'  => $source['teacher_document'],
		);
	}

	/**
	 * Authorization snapshot.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $object_type Object type.
	 * @param string $object_id Object id.
	 * @return array|null
	 */
	public function authorization_snapshot( int $actor_user_id, string $object_type, string $object_id ): ?array {
		if ( Object_Scope::TYPE_WORKSHEET === $object_type ) {
			$sql = 'SELECT current_revision_public_id, state FROM ' . self::TABLE_WORKSHEETS
				. ' WHERE public_id = %s AND owner_user_id = %d LIMIT 1';
			$row = $this->get_row( $this->prepare( $sql, array( $object_id, $actor_user_id ) ) );
			if ( null === $row ) {
				return null;
			}
			$current_revision = (string) ( $row['current_revision_public_id'] ?? '' );
			$this->assert_stored_public_id( $current_revision );

			return array(
				'current_revision' => $current_revision,
				'state'            => $this->root_state( $row['state'] ?? null ),
				'relation'         => 'owner',
			);
		}

		if ( Object_Scope::TYPE_WORKSHEET_REVISION === $object_type ) {
			$sql = 'SELECT r.public_id, r.state FROM ' . self::TABLE_REVISIONS . ' r '
				. 'INNER JOIN ' . self::TABLE_WORKSHEETS . ' w ON w.id = r.worksheet_id '
				. 'WHERE r.public_id = %s AND w.owner_user_id = %d LIMIT 1';
			$row = $this->get_row( $this->prepare( $sql, array( $object_id, $actor_user_id ) ) );
			if ( null === $row ) {
				return null;
			}
			$current_revision = (string) ( $row['public_id'] ?? '' );
			$this->assert_stored_public_id( $current_revision );

			return array(
				'current_revision' => $current_revision,
				'state'            => $this->revision_state( $row['state'] ?? null ),
				'relation'         => 'owner',
			);
		}

		return null;
	}

	/**
	 * WordPress worksheet repository.
	 *
	 * @param array<string,mixed> $record
	 */
	/**
	 * Duplicate link source.
	 *
	 * @param int $revision_pk Revision pk.
	 * @return array{achievement_links:array<int,array<string,mixed>>,standard_snapshots:array<int,array<string,mixed>>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function duplicate_link_source( int $revision_pk ): array {
		$worksheet_sql  = 'SELECT standard_public_id, standard_source_version, standard_code_snapshot, standard_label_snapshot, alignment_type, position FROM ' . self::TABLE_WORKSHEET_LINKS
			. ' WHERE worksheet_revision_id = %d ORDER BY position ASC, id ASC';
		$question_sql   = 'SELECT q.question_key AS response_id, l.standard_public_id, l.standard_source_version, l.standard_code_snapshot, l.standard_label_snapshot, l.alignment_type, l.position FROM ' . self::TABLE_QUESTION_LINKS . ' l '
			. 'INNER JOIN ' . self::TABLE_QUESTIONS . ' q ON q.id = l.question_id '
			. 'WHERE q.worksheet_revision_id = %d ORDER BY q.position ASC, l.position ASC, l.id ASC';
		$worksheet_rows = $this->get_results( $this->prepare( $worksheet_sql, array( $revision_pk ) ) );
		$question_rows  = $this->get_results( $this->prepare( $question_sql, array( $revision_pk ) ) );
		$links          = array();
		$snapshots      = array();

		foreach ( $worksheet_rows as $position => $row ) {
			if ( $position !== $this->non_negative_integer( $row['position'] ?? null ) ) {
				throw Worksheet_Failure::stored_data_invalid();
			}
			$links[] = $this->duplicate_link_input( 'worksheet', null, $row, $snapshots );
		}
		$question_positions = array();
		foreach ( $question_rows as $row ) {
			$response_id = (string) ( $row['response_id'] ?? '' );
			$this->assert_stored_public_id( $response_id );
			$position = $question_positions[ $response_id ] ?? 0;
			if ( $position !== $this->non_negative_integer( $row['position'] ?? null ) ) {
				throw Worksheet_Failure::stored_data_invalid();
			}
			$question_positions[ $response_id ] = $position + 1;
			$links[]                            = $this->duplicate_link_input( 'response', $response_id, $row, $snapshots );
		}
		ksort( $snapshots, SORT_STRING );

		return array(
			'achievement_links'  => $links,
			'standard_snapshots' => array_values( $snapshots ),
		);
	}

	/**
	 * Duplicate link input.
	 *
	 * @param string                            $scope Scope.
	 * @param string|null                       $response_id Response id.
	 * @param array<string,mixed>               $row Row.
	 * @param array<string,array<string,mixed>> &$snapshots Snapshots.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function duplicate_link_input(
		string $scope,
		?string $response_id,
		array $row,
		array &$snapshots
	): array {
		$standard_id = (string) ( $row['standard_public_id'] ?? '' );
		$this->assert_stored_public_id( $standard_id );
		$alignment = (string) ( $row['alignment_type'] ?? '' );
		if ( ! in_array( $alignment, Worksheet_Revision_Projector::ALIGNMENT_TYPES, true ) ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
		$snapshot = array(
			'standard_public_id'      => $standard_id,
			'standard_source_version' => (string) ( $row['standard_source_version'] ?? '' ),
			'standard_code_snapshot'  => (string) ( $row['standard_code_snapshot'] ?? '' ),
			'standard_label_snapshot' => (string) ( $row['standard_label_snapshot'] ?? '' ),
		);
		foreach ( array_slice( $snapshot, 1 ) as $value ) {
			if ( '' === $value ) {
				throw Worksheet_Failure::stored_data_invalid();
			}
		}
		if ( isset( $snapshots[ $standard_id ] ) && $snapshots[ $standard_id ] !== $snapshot ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
		$snapshots[ $standard_id ] = $snapshot;

		return array(
			'scope'              => $scope,
			'response_id'        => $response_id,
			'standard_public_id' => $standard_id,
			'alignment_type'     => $alignment,
		);
	}

	/**
	 * Reject invalid duplicate record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_duplicate_record( array $record ): void {
		$extra_keys  = array(
			'source_worksheet_public_id',
			'source_document_hash',
			'asset_count',
			'reissued_id_count',
		);
		$save_record = $record;
		if ( null !== ( $record['assessment_project'] ?? null ) ) {
			$pin      = \OJAI\Dashboard\Assessment\Project\Domain\Project_Configuration::context( $record['assessment_project'] );
			$document = json_decode( $record['document_json'], true, 128, JSON_THROW_ON_ERROR );
			if ( $this->canonical->encode( $pin ) !== $this->canonical->encode( $document['settings']['assessment_project'] ?? null ) ) {
				throw new InvalidArgumentException( 'Assessment membership does not match the copied document.' );
			}
		}
		unset( $save_record['assessment_project'] );
		foreach ( $extra_keys as $key ) {
			if ( ! array_key_exists( $key, $save_record ) ) {
				throw new InvalidArgumentException( 'Duplicate worksheet record is incomplete.' );
			}
			unset( $save_record[ $key ] );
		}
		( new Worksheet_Draft_Save_Record_Validator(
			$this->canonical,
			array( 'draft', 'active', 'archived' )
		) )->assert_valid( $save_record );
		new Public_Id( (string) $record['source_worksheet_public_id'] );
		if (
			(string) $record['source_worksheet_public_id'] === (string) $record['worksheet_public_id']
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) $record['source_document_hash'] )
			|| ! is_int( $record['asset_count'] )
			|| $record['asset_count'] !== count( $record['asset_bindings'] )
			|| ! is_int( $record['reissued_id_count'] ) || $record['reissued_id_count'] < 1
			|| array() !== $record['temporary_id_map']
		) {
			throw new InvalidArgumentException( 'Duplicate worksheet record values are invalid.' );
		}
	}

	/**
	 * Find duplicate idempotent receipt.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $idempotency_storage_key Idempotency storage key.
	 * @param string $request_digest Request digest.
	 * @param bool   $for_update For update.
	 * @return Duplicate_Worksheet_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function find_duplicate_idempotent_receipt(
		int $actor_user_id,
		string $idempotency_storage_key,
		string $request_digest,
		bool $for_update
	): ?Duplicate_Worksheet_Result {
		$sql = 'SELECT aggregate_public_id, event_type, payload_schema, payload_version, payload_json, payload_hash FROM ' . self::TABLE_OUTBOX . ' WHERE idempotency_key = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = $this->get_row( $this->prepare( $sql, array( $idempotency_storage_key ) ) );
		if ( null === $row ) {
			return null;
		}
		if (
			self::DUPLICATE_EVENT_TYPE !== (string) ( $row['event_type'] ?? '' )
			|| self::DUPLICATE_OUTBOX_PAYLOAD_SCHEMA !== (string) ( $row['payload_schema'] ?? '' )
			|| self::PROFILE_VERSION !== $this->positive_integer( $row['payload_version'] ?? null )
		) {
			throw Worksheet_Failure::stored_data_invalid();
		}
		$payload = $this->decode_canonical_json(
			(string) ( $row['payload_json'] ?? '' ),
			(string) ( $row['payload_hash'] ?? '' )
		);
		if (
			$actor_user_id !== (int) ( $payload['owner_user_id'] ?? 0 )
			|| ! hash_equals( $request_digest, (string) ( $payload['request_digest'] ?? '' ) )
		) {
			throw Worksheet_Failure::idempotency_conflict();
		}
		$receipt = $payload['receipt'] ?? null;
		if (
			! is_array( $receipt ) || array_is_list( $receipt )
			|| (string) ( $row['aggregate_public_id'] ?? '' ) !== (string) ( $receipt['worksheet_id'] ?? '' )
		) {
			throw Worksheet_Failure::stored_data_invalid();
		}
		try {
			return new Duplicate_Worksheet_Result(
				(string) ( $receipt['worksheet_id'] ?? '' ),
				(string) ( $receipt['revision_id'] ?? '' ),
				(string) ( $receipt['source_worksheet_id'] ?? '' ),
				(string) ( $receipt['source_revision_id'] ?? '' ),
				(string) ( $receipt['document_id'] ?? '' ),
				(string) ( $receipt['document_hash'] ?? '' ),
				(string) ( $receipt['validation_hash'] ?? '' ),
				(string) ( $receipt['title'] ?? '' ),
				$this->non_negative_integer( $receipt['asset_count'] ?? null ),
				$this->positive_integer( $receipt['reissued_id_count'] ?? null ),
				(string) ( $receipt['created_at_utc'] ?? '' ),
				true
			);
		} catch ( InvalidArgumentException ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
	}

	/**
	 * Insert duplicate audit event.
	 *
	 * @param array<string,mixed>            $record Record.
	 * @param array<string,mixed>            $source_revision Source revision.
	 * @param array<int,array<string,mixed>> $asset_receipts Asset receipts.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function insert_duplicate_audit_event(
		array $record,
		array $source_revision,
		array $asset_receipts
	): void {
		$previous_hash = $this->get_var(
			'SELECT event_hash FROM ' . self::TABLE_AUDIT . ' ORDER BY id DESC LIMIT 1 FOR UPDATE'
		);
		if ( null !== $previous_hash && 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) $previous_hash ) ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
		$metadata_json = $this->canonical->encode(
			array(
				'asset_receipt_hashes'       => array_column( $asset_receipts, 'content_hash' ),
				'command_id'                 => $record['command_id'],
				'revision_public_id'         => $record['revision_public_id'],
				'source_revision_public_id'  => $record['expected_revision_public_id'],
				'source_worksheet_public_id' => $record['source_worksheet_public_id'],
			)
		);
		$metadata_hash = hash( 'sha256', $metadata_json );
		$event_hash    = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'action_key'          => self::DUPLICATE_EVENT_TYPE,
					'actor_user_id'       => $record['actor_user_id'],
					'after_digest'        => $record['content_hash'],
					'before_digest'       => $source_revision['content_hash'],
					'correlation_id'      => $record['correlation_id'],
					'metadata_hash'       => $metadata_hash,
					'object_public_id'    => $record['worksheet_public_id'],
					'object_type'         => 'worksheet',
					'occurred_at_utc'     => $record['saved_at_utc'],
					'previous_event_hash' => null === $previous_hash ? null : (string) $previous_hash,
				)
			)
		);
		$this->insert_or_fail(
			self::TABLE_AUDIT,
			array(
				'public_id'           => $record['audit_public_id'],
				'occurred_at_utc'     => $record['saved_at_utc'],
				'actor_user_id'       => $record['actor_user_id'],
				'action_key'          => self::DUPLICATE_EVENT_TYPE,
				'object_type'         => 'worksheet',
				'object_public_id'    => $record['worksheet_public_id'],
				'context_type'        => null,
				'context_id'          => null,
				'context_revision'    => null,
				'correlation_id'      => $record['correlation_id'],
				'before_digest'       => $source_revision['content_hash'],
				'after_digest'        => $record['content_hash'],
				'metadata_schema'     => self::AUDIT_METADATA_SCHEMA,
				'metadata_version'    => self::PROFILE_VERSION,
				'metadata_json'       => $metadata_json,
				'metadata_hash'       => $metadata_hash,
				'previous_event_hash' => null === $previous_hash ? null : (string) $previous_hash,
				'event_hash'          => $event_hash,
			),
			array(
				'%s',
				'%s',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
			)
		);
	}

	/**
	 * Insert duplicate outbox event.
	 *
	 * @param array<string,mixed>            $record Record.
	 * @param array<int,array<string,mixed>> $asset_receipts Asset receipts.
	 * @return void
	 */
	private function insert_duplicate_outbox_event( array $record, array $asset_receipts ): void {
		$payload_json = $this->canonical->encode(
			array(
				'command_id'           => $record['command_id'],
				'event'                => self::DUPLICATE_EVENT_TYPE,
				'owner_user_id'        => $record['actor_user_id'],
				'request_digest'       => $record['request_digest'],
				'receipt'              => array(
					'asset_count'         => $record['asset_count'],
					'created_at_utc'      => $record['saved_at_utc'],
					'document_hash'       => $record['document_hash'],
					'document_id'         => $record['document_id'],
					'reissued_id_count'   => $record['reissued_id_count'],
					'revision_id'         => $record['revision_public_id'],
					'source_revision_id'  => $record['expected_revision_public_id'],
					'source_worksheet_id' => $record['source_worksheet_public_id'],
					'title'               => $record['title'],
					'validation_hash'     => $record['validation_hash'],
					'worksheet_id'        => $record['worksheet_public_id'],
				),
				'asset_receipt_hashes' => array_column( $asset_receipts, 'content_hash' ),
			)
		);
		$this->insert_or_fail(
			self::TABLE_OUTBOX,
			array(
				'public_id'            => $record['outbox_public_id'],
				'lock_version'         => 0,
				'created_by_user_id'   => $record['actor_user_id'],
				'created_at_utc'       => $record['saved_at_utc'],
				'updated_by_user_id'   => $record['actor_user_id'],
				'updated_at_utc'       => $record['saved_at_utc'],
				'aggregate_type'       => 'worksheet',
				'aggregate_public_id'  => $record['worksheet_public_id'],
				'event_type'           => self::DUPLICATE_EVENT_TYPE,
				'idempotency_key'      => $record['idempotency_storage_key'],
				'payload_schema'       => self::DUPLICATE_OUTBOX_PAYLOAD_SCHEMA,
				'payload_version'      => self::PROFILE_VERSION,
				'payload_json'         => $payload_json,
				'payload_hash'         => hash( 'sha256', $payload_json ),
				'available_at_utc'     => $record['saved_at_utc'],
				'published_at_utc'     => null,
				'lease_token'          => null,
				'lease_expires_at_utc' => null,
				'attempt_count'        => 0,
				'last_error_code'      => null,
			),
			array(
				'%s',
				'%d',
				'%d',
				'%s',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
				'%s',
			)
		);
	}

	/**
	 * Duplicate result from record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param bool                $replayed Replayed.
	 * @return Duplicate_Worksheet_Result
	 */
	private function duplicate_result_from_record( array $record, bool $replayed ): Duplicate_Worksheet_Result {
		return new Duplicate_Worksheet_Result(
			(string) $record['worksheet_public_id'],
			(string) $record['revision_public_id'],
			(string) $record['source_worksheet_public_id'],
			(string) $record['expected_revision_public_id'],
			(string) $record['document_id'],
			(string) $record['document_hash'],
			(string) $record['validation_hash'],
			(string) $record['title'],
			(int) $record['asset_count'],
			(int) $record['reissued_id_count'],
			(string) $record['saved_at_utc'],
			$replayed
		);
	}

	/**
	 * Reject invalid save record.
	 *
	 * @param array $record Record.
	 * @return void
	 */
	private function assert_save_record( array $record ): void {
		( new Worksheet_Draft_Save_Record_Validator( $this->canonical ) )->assert_valid( $record );
	}

	/**
	 * Lock owned root.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $worksheet_public_id Worksheet public id.
	 * @return array<string,mixed>|null
	 */
	private function lock_owned_root( int $actor_user_id, string $worksheet_public_id ): ?array {
		$sql = 'SELECT id, state, current_revision_public_id, lock_version FROM ' . self::TABLE_WORKSHEETS
			. ' WHERE public_id = %s AND owner_user_id = %d LIMIT 1 FOR UPDATE';
		$row = $this->get_row( $this->prepare( $sql, array( $worksheet_public_id, $actor_user_id ) ) );
		if ( null === $row ) {
			return null;
		}

		$row['id']                         = $this->positive_integer( $row['id'] ?? null );
		$row['state']                      = $this->root_state( $row['state'] ?? null );
		$row['lock_version']               = $this->non_negative_integer( $row['lock_version'] ?? null );
		$row['current_revision_public_id'] = (string) ( $row['current_revision_public_id'] ?? '' );
		$this->assert_stored_public_id( $row['current_revision_public_id'] );

		return $row;
	}

	/**
	 * Lock current revision.
	 *
	 * @param int    $worksheet_pk Worksheet pk.
	 * @param string $revision_public_id Revision public id.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function lock_current_revision( int $worksheet_pk, string $revision_public_id ): ?array {
		$sql = 'SELECT id, public_id, revision_no, state, content_hash, document_hash FROM ' . self::TABLE_REVISIONS
			. ' WHERE worksheet_id = %d AND public_id = %s LIMIT 1 FOR UPDATE';
		$row = $this->get_row( $this->prepare( $sql, array( $worksheet_pk, $revision_public_id ) ) );
		if ( null === $row ) {
			return null;
		}

		$row['id']          = $this->positive_integer( $row['id'] ?? null );
		$row['revision_no'] = $this->positive_integer( $row['revision_no'] ?? null );
		$row['public_id']   = (string) ( $row['public_id'] ?? '' );
		$this->assert_stored_public_id( $row['public_id'] );
		$row['state'] = $this->revision_state( $row['state'] ?? null );
		if ( 'retired' === $row['state'] ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
		foreach ( array( 'content_hash', 'document_hash' ) as $key ) {
			$row[ $key ] = (string) ( $row[ $key ] ?? '' );
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $row[ $key ] ) ) {
				throw Worksheet_Failure::stored_data_invalid();
			}
		}

		return $row;
	}

	/**
	 * Insert revision questions.
	 *
	 * @param int                 $revision_pk Revision pk.
	 * @param array<string,mixed> $record Record.
	 * @return array<string,int>
	 */
	private function insert_revision_questions( int $revision_pk, array $record ): array {
		$question_ids = array();
		foreach ( $record['questions'] as $question ) {
			$this->insert_or_fail(
				self::TABLE_QUESTIONS,
				array(
					'public_id'                => $question['public_id'],
					'content_hash'             => $question['content_hash'],
					'created_by_user_id'       => $record['actor_user_id'],
					'created_at_utc'           => $record['saved_at_utc'],
					'worksheet_revision_id'    => $revision_pk,
					'question_key'             => $question['question_key'],
					'position'                 => $question['position'],
					'response_type'            => $question['response_type'],
					'question_schema'          => $question['question_schema'],
					'question_version'         => $question['question_version'],
					'question_json'            => $question['question_json'],
					'question_hash'            => $question['question_hash'],
					'private_metadata_schema'  => $question['private_metadata_schema'],
					'private_metadata_version' => $question['private_metadata_version'],
					'private_metadata_json'    => $question['private_metadata_json'],
					'private_metadata_hash'    => $question['private_metadata_hash'],
				),
				array( '%s', '%s', '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
			);
			$question_ids[ $question['public_id'] ] = $this->insert_id( $this->database() );
		}

		return $question_ids;
	}

	/**
	 * Insert revision achievement links.
	 *
	 * @param int                 $revision_pk Revision pk.
	 * @param array<string,int>   $question_ids Question ids.
	 * @param array<string,mixed> $record Record.
	 * @return void
	 */
	private function insert_revision_achievement_links( int $revision_pk, array $question_ids, array $record ): void {
		foreach ( $record['worksheet_achievement_links'] as $link ) {
			$this->insert_or_fail(
				self::TABLE_WORKSHEET_LINKS,
				array(
					'content_hash'            => $link['content_hash'],
					'created_by_user_id'      => $record['actor_user_id'],
					'created_at_utc'          => $record['saved_at_utc'],
					'worksheet_revision_id'   => $revision_pk,
					'standard_public_id'      => $link['standard_public_id'],
					'standard_source_version' => $link['standard_source_version'],
					'standard_code_snapshot'  => $link['standard_code_snapshot'],
					'standard_label_snapshot' => $link['standard_label_snapshot'],
					'alignment_type'          => $link['alignment_type'],
					'position'                => $link['position'],
				),
				array( '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d' )
			);
		}
		foreach ( $record['question_achievement_links'] as $link ) {
			$this->insert_or_fail(
				self::TABLE_QUESTION_LINKS,
				array(
					'content_hash'            => $link['content_hash'],
					'created_by_user_id'      => $record['actor_user_id'],
					'created_at_utc'          => $record['saved_at_utc'],
					'question_id'             => $question_ids[ $link['question_public_id'] ],
					'standard_public_id'      => $link['standard_public_id'],
					'standard_source_version' => $link['standard_source_version'],
					'standard_code_snapshot'  => $link['standard_code_snapshot'],
					'standard_label_snapshot' => $link['standard_label_snapshot'],
					'alignment_type'          => $link['alignment_type'],
					'position'                => $link['position'],
				),
				array( '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d' )
			);
		}
	}

	/**
	 * Reject invalid save postcondition.
	 *
	 * @param int    $worksheet_pk Worksheet pk.
	 * @param string $revision_public_id Revision public id.
	 * @param int    $lock_version Lock version.
	 * @param int    $revision_pk Revision pk.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_save_postcondition(
		int $worksheet_pk,
		string $revision_public_id,
		int $lock_version,
		int $revision_pk
	): void {
		$sql = 'SELECT w.current_revision_public_id, w.lock_version, r.public_id AS revision_public_id, r.worksheet_id FROM ' . self::TABLE_WORKSHEETS . ' w INNER JOIN ' . self::TABLE_REVISIONS
			. ' r ON r.id = %d WHERE w.id = %d LIMIT 1 FOR UPDATE';
		$row = $this->get_row( $this->prepare( $sql, array( $revision_pk, $worksheet_pk ) ) );
		if (
			null === $row
			|| $revision_public_id !== (string) ( $row['current_revision_public_id'] ?? '' )
			|| $revision_public_id !== (string) ( $row['revision_public_id'] ?? '' )
			|| $lock_version !== $this->non_negative_integer( $row['lock_version'] ?? null )
			|| $worksheet_pk !== $this->positive_integer( $row['worksheet_id'] ?? null )
		) {
			throw new RuntimeException( 'SaveWorksheetDraft postcondition failed.' );
		}
	}

	/**
	 * Save result.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param string              $revision_id Revision id.
	 * @param int                 $revision_no Revision no.
	 * @param string              $revision_state Revision state.
	 * @param string              $source_revision_id Source revision id.
	 * @param string              $worksheet_state Worksheet state.
	 * @param bool                $unchanged Unchanged.
	 * @return Save_Worksheet_Draft_Result
	 */
	private function save_result(
		array $record,
		string $revision_id,
		int $revision_no,
		string $revision_state,
		string $source_revision_id,
		string $worksheet_state,
		bool $unchanged
	): Save_Worksheet_Draft_Result {
		return new Save_Worksheet_Draft_Result(
			$record['worksheet_public_id'],
			$revision_id,
			$revision_no,
			$revision_state,
			$source_revision_id,
			$record['document_id'],
			$record['document_hash'],
			$record['validation_hash'],
			$record['title'],
			$worksheet_state,
			$record['saved_at_utc'],
			$record['temporary_id_map'],
			false,
			$unchanged
		);
	}

	/**
	 * Bind asset receipts.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return array<int,array<string,mixed>>
	 */
	private function bind_asset_receipts( array $record ): array {
		$receipts = $this->assets->bind_for_revision(
			(int) $record['actor_user_id'],
			(string) $record['worksheet_public_id'],
			$record['asset_bindings'],
			$record['staged_asset_receipt_ids'],
			(string) $record['saved_at_utc']
		);
		return $this->normalize_asset_receipts( $record, $receipts );
	}

	/**
	 * Normalize asset receipts.
	 *
	 * @param array<string,mixed>    $record Save command.
	 * @param array<array-key,mixed> $receipts Receipts to verify before persistence.
	 * @return list<array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function normalize_asset_receipts( array $record, array $receipts ): array {
		if ( ! array_is_list( $receipts ) || count( $receipts ) !== count( $record['asset_bindings'] ) ) {
			throw Worksheet_Failure::stored_data_invalid();
		}

		$result = array();
		foreach ( $receipts as $position => $receipt ) {
			$binding = $record['asset_bindings'][ $position ];
			if ( ! is_array( $receipt ) || array_is_list( $receipt ) ) {
				throw Worksheet_Failure::stored_data_invalid();
			}
			$keys          = array_keys( $receipt );
			$expected_keys = array( 'asset_id', 'binding_hash', 'byte_size', 'file_hash', 'mime_type' );
			sort( $keys, SORT_STRING );
			sort( $expected_keys, SORT_STRING );
			if ( $keys !== $expected_keys ) {
				throw Worksheet_Failure::stored_data_invalid();
			}

			$asset_id = is_string( $receipt['asset_id'] ) ? $receipt['asset_id'] : '';
			$this->assert_stored_public_id( $asset_id );
			if (
				$asset_id !== (string) $binding['asset_id']
				|| (string) $receipt['binding_hash'] !== (string) $binding['content_hash']
				|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) $receipt['file_hash'] )
				|| ! is_string( $receipt['mime_type'] )
				|| '' === $receipt['mime_type']
			) {
				throw Worksheet_Failure::stored_data_invalid();
			}
			$normalized                 = array(
				'asset_id'     => $asset_id,
				'binding_hash' => (string) $receipt['binding_hash'],
				'file_hash'    => (string) $receipt['file_hash'],
				'mime_type'    => $receipt['mime_type'],
				'byte_size'    => $this->positive_integer( $receipt['byte_size'] ),
			);
			$normalized['content_hash'] = hash( 'sha256', $this->canonical->encode( $normalized ) );
			$result[]                   = $normalized;
		}

		return $result;
	}

	/**
	 * Insert save audit event.
	 *
	 * @param array<string,mixed>            $record Record.
	 * @param array<string,mixed>            $current Current.
	 * @param array<int,array<string,mixed>> $asset_receipts Asset receipts.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function insert_save_audit_event( array $record, array $current, array $asset_receipts ): void {
		$previous_hash = $this->get_var(
			'SELECT event_hash FROM ' . self::TABLE_AUDIT . ' ORDER BY id DESC LIMIT 1 FOR UPDATE'
		);
		if ( null !== $previous_hash && 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) $previous_hash ) ) {
			throw Worksheet_Failure::stored_data_invalid();
		}

		$metadata_json = $this->canonical->encode(
			array(
				'asset_receipt_hashes'      => array_column( $asset_receipts, 'content_hash' ),
				'command_id'                => $record['command_id'],
				'document_hash'             => $record['document_hash'],
				'projection_hash'           => $record['projection_hash'],
				'revision_public_id'        => $record['revision_public_id'],
				'source_revision_public_id' => $current['public_id'],
				'validation_hash'           => $record['validation_hash'],
			)
		);
		$metadata_hash = hash( 'sha256', $metadata_json );
		$event_hash    = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'action_key'          => self::SAVE_EVENT_TYPE,
					'actor_user_id'       => $record['actor_user_id'],
					'after_digest'        => $record['content_hash'],
					'before_digest'       => $current['content_hash'],
					'correlation_id'      => $record['correlation_id'],
					'metadata_hash'       => $metadata_hash,
					'object_public_id'    => $record['worksheet_public_id'],
					'object_type'         => 'worksheet',
					'occurred_at_utc'     => $record['saved_at_utc'],
					'previous_event_hash' => null === $previous_hash ? null : (string) $previous_hash,
				)
			)
		);

		$this->insert_or_fail(
			self::TABLE_AUDIT,
			array(
				'public_id'           => $record['audit_public_id'],
				'occurred_at_utc'     => $record['saved_at_utc'],
				'actor_user_id'       => $record['actor_user_id'],
				'action_key'          => self::SAVE_EVENT_TYPE,
				'object_type'         => 'worksheet',
				'object_public_id'    => $record['worksheet_public_id'],
				'context_type'        => null,
				'context_id'          => null,
				'context_revision'    => null,
				'correlation_id'      => $record['correlation_id'],
				'before_digest'       => $current['content_hash'],
				'after_digest'        => $record['content_hash'],
				'metadata_schema'     => self::AUDIT_METADATA_SCHEMA,
				'metadata_version'    => self::PROFILE_VERSION,
				'metadata_json'       => $metadata_json,
				'metadata_hash'       => $metadata_hash,
				'previous_event_hash' => null === $previous_hash ? null : (string) $previous_hash,
				'event_hash'          => $event_hash,
			),
			array(
				'%s',
				'%s',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
			)
		);
	}

	/**
	 * Insert save outbox event.
	 *
	 * @param array<string,mixed>            $record Record.
	 * @param Save_Worksheet_Draft_Result    $result Result.
	 * @param array<int,array<string,mixed>> $asset_receipts Asset receipts.
	 * @return void
	 */
	private function insert_save_outbox_event(
		array $record,
		Save_Worksheet_Draft_Result $result,
		array $asset_receipts
	): void {
		$payload_json = $this->canonical->encode(
			array(
				'aggregate_content_hash' => $record['content_hash'],
				'asset_receipt_hashes'   => array_column( $asset_receipts, 'content_hash' ),
				'command_id'             => $record['command_id'],
				'event'                  => self::SAVE_EVENT_TYPE,
				'owner_user_id'          => $record['actor_user_id'],
				'projection_hash'        => $record['projection_hash'],
				'request_digest'         => $record['request_digest'],
				'receipt'                => array(
					'document_hash'      => $result->document_hash(),
					'document_id'        => $result->document_id(),
					'revision_id'        => $result->revision_id(),
					'revision_no'        => $result->revision_no(),
					'revision_state'     => $result->revision_state(),
					'saved_at_utc'       => $result->saved_at_utc(),
					'source_revision_id' => $result->source_revision_id(),
					'temporary_id_map'   => $result->temporary_id_map(),
					'title'              => $result->title(),
					'unchanged'          => $result->unchanged(),
					'validation_hash'    => $result->validation_hash(),
					'worksheet_id'       => $result->worksheet_id(),
					'worksheet_state'    => $result->worksheet_state(),
				),
			)
		);

		$this->insert_or_fail(
			self::TABLE_OUTBOX,
			array(
				'public_id'            => $record['outbox_public_id'],
				'lock_version'         => 0,
				'created_by_user_id'   => $record['actor_user_id'],
				'created_at_utc'       => $record['saved_at_utc'],
				'updated_by_user_id'   => $record['actor_user_id'],
				'updated_at_utc'       => $record['saved_at_utc'],
				'aggregate_type'       => 'worksheet',
				'aggregate_public_id'  => $record['worksheet_public_id'],
				'event_type'           => self::SAVE_EVENT_TYPE,
				'idempotency_key'      => $record['idempotency_storage_key'],
				'payload_schema'       => self::SAVE_OUTBOX_PAYLOAD_SCHEMA,
				'payload_version'      => self::PROFILE_VERSION,
				'payload_json'         => $payload_json,
				'payload_hash'         => hash( 'sha256', $payload_json ),
				'available_at_utc'     => $record['saved_at_utc'],
				'published_at_utc'     => null,
				'lease_token'          => null,
				'lease_expires_at_utc' => null,
				'attempt_count'        => 0,
				'last_error_code'      => null,
			),
			array(
				'%s',
				'%d',
				'%d',
				'%s',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
				'%s',
			)
		);
	}

	/**
	 * Find save idempotent receipt.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param bool                $for_update For update.
	 * @return Save_Worksheet_Draft_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function find_save_idempotent_receipt( array $record, bool $for_update ): ?Save_Worksheet_Draft_Result {
		$sql = 'SELECT aggregate_public_id, event_type, payload_schema, payload_version, payload_json, payload_hash FROM ' . self::TABLE_OUTBOX . ' WHERE idempotency_key = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = $this->get_row( $this->prepare( $sql, array( $record['idempotency_storage_key'] ) ) );
		if ( null === $row ) {
			return null;
		}
		if (
			self::SAVE_EVENT_TYPE !== (string) ( $row['event_type'] ?? '' )
			|| self::SAVE_OUTBOX_PAYLOAD_SCHEMA !== (string) ( $row['payload_schema'] ?? '' )
			|| self::PROFILE_VERSION !== $this->positive_integer( $row['payload_version'] ?? null )
		) {
			throw Worksheet_Failure::stored_data_invalid();
		}

		$payload = $this->decode_canonical_json(
			(string) ( $row['payload_json'] ?? '' ),
			(string) ( $row['payload_hash'] ?? '' )
		);
		if ( ! hash_equals( (string) ( $payload['request_digest'] ?? '' ), $record['request_digest'] ) ) {
			throw Worksheet_Failure::idempotency_conflict();
		}
		$receipt = $payload['receipt'] ?? null;
		if (
			! is_array( $receipt )
			|| (string) ( $row['aggregate_public_id'] ?? '' ) !== (string) ( $receipt['worksheet_id'] ?? '' )
			|| (int) ( $payload['owner_user_id'] ?? 0 ) !== (int) $record['actor_user_id']
			|| ! is_bool( $receipt['unchanged'] ?? null )
			|| ! is_array( $receipt['temporary_id_map'] ?? null )
		) {
			throw Worksheet_Failure::stored_data_invalid();
		}

		try {
			return new Save_Worksheet_Draft_Result(
				(string) ( $receipt['worksheet_id'] ?? '' ),
				(string) ( $receipt['revision_id'] ?? '' ),
				$this->positive_integer( $receipt['revision_no'] ?? null ),
				(string) ( $receipt['revision_state'] ?? '' ),
				(string) ( $receipt['source_revision_id'] ?? '' ),
				(string) ( $receipt['document_id'] ?? '' ),
				(string) ( $receipt['document_hash'] ?? '' ),
				(string) ( $receipt['validation_hash'] ?? '' ),
				(string) ( $receipt['title'] ?? '' ),
				(string) ( $receipt['worksheet_state'] ?? '' ),
				(string) ( $receipt['saved_at_utc'] ?? '' ),
				$receipt['temporary_id_map'],
				true,
				$receipt['unchanged']
			);
		} catch ( InvalidArgumentException ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
	}

	/**
	 * Insert audit event.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function insert_audit_event( array $record ): void {
		$previous_hash = $this->get_var(
			'SELECT event_hash FROM ' . self::TABLE_AUDIT . ' ORDER BY id DESC LIMIT 1 FOR UPDATE'
		);
		if ( null !== $previous_hash && 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) $previous_hash ) ) {
			throw Worksheet_Failure::stored_data_invalid();
		}

		$metadata_json = $this->canonical->encode(
			array(
				'command_id'         => $record['command_id'],
				'document_hash'      => $record['document_hash'],
				'revision_public_id' => $record['revision_public_id'],
			)
		);
		$metadata_hash = hash( 'sha256', $metadata_json );
		$event_hash    = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'action_key'          => 'worksheet.created',
					'actor_user_id'       => $record['actor_user_id'],
					'after_digest'        => $record['document_hash'],
					'before_digest'       => null,
					'correlation_id'      => $record['correlation_id'],
					'metadata_hash'       => $metadata_hash,
					'object_public_id'    => $record['worksheet_public_id'],
					'object_type'         => 'worksheet',
					'occurred_at_utc'     => $record['created_at_utc'],
					'previous_event_hash' => null === $previous_hash ? null : (string) $previous_hash,
				)
			)
		);

		$this->insert_or_fail(
			self::TABLE_AUDIT,
			array(
				'public_id'           => $record['audit_public_id'],
				'occurred_at_utc'     => $record['created_at_utc'],
				'actor_user_id'       => $record['actor_user_id'],
				'action_key'          => 'worksheet.created',
				'object_type'         => 'worksheet',
				'object_public_id'    => $record['worksheet_public_id'],
				'context_type'        => null,
				'context_id'          => null,
				'context_revision'    => null,
				'correlation_id'      => $record['correlation_id'],
				'before_digest'       => null,
				'after_digest'        => $record['document_hash'],
				'metadata_schema'     => self::AUDIT_METADATA_SCHEMA,
				'metadata_version'    => self::PROFILE_VERSION,
				'metadata_json'       => $metadata_json,
				'metadata_hash'       => $metadata_hash,
				'previous_event_hash' => null === $previous_hash ? null : (string) $previous_hash,
				'event_hash'          => $event_hash,
			),
			array(
				'%s',
				'%s',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
			)
		);
	}

	/**
	 * Insert outbox event.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 */
	private function insert_outbox_event( array $record ): void {
		$payload_json = $this->canonical->encode(
			array(
				'command_id'     => $record['command_id'],
				'event'          => 'worksheet.created',
				'owner_user_id'  => $record['actor_user_id'],
				'request_digest' => $record['request_digest'],
				'receipt'        => array(
					'created_at_utc' => $record['created_at_utc'],
					'document_hash'  => $record['document_hash'],
					'document_id'    => $record['document_id'],
					'revision_id'    => $record['revision_public_id'],
					'title'          => $record['title'],
					'worksheet_id'   => $record['worksheet_public_id'],
				),
			)
		);

		$this->insert_or_fail(
			self::TABLE_OUTBOX,
			array(
				'public_id'            => $record['outbox_public_id'],
				'lock_version'         => 0,
				'created_by_user_id'   => $record['actor_user_id'],
				'created_at_utc'       => $record['created_at_utc'],
				'updated_by_user_id'   => $record['actor_user_id'],
				'updated_at_utc'       => $record['created_at_utc'],
				'aggregate_type'       => 'worksheet',
				'aggregate_public_id'  => $record['worksheet_public_id'],
				'event_type'           => 'worksheet.created',
				'idempotency_key'      => $record['idempotency_storage_key'],
				'payload_schema'       => self::OUTBOX_PAYLOAD_SCHEMA,
				'payload_version'      => self::PROFILE_VERSION,
				'payload_json'         => $payload_json,
				'payload_hash'         => hash( 'sha256', $payload_json ),
				'available_at_utc'     => $record['created_at_utc'],
				'published_at_utc'     => null,
				'lease_token'          => null,
				'lease_expires_at_utc' => null,
				'attempt_count'        => 0,
				'last_error_code'      => null,
			),
			array(
				'%s',
				'%d',
				'%d',
				'%s',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
				'%s',
			)
		);
	}

	/**
	 * Find idempotent receipt.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param bool                $for_update For update.
	 * @return Create_Worksheet_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function find_idempotent_receipt( array $record, bool $for_update ): ?Create_Worksheet_Result {
		$sql = 'SELECT aggregate_public_id, event_type, payload_schema, payload_version, payload_json, payload_hash FROM ' . self::TABLE_OUTBOX . ' WHERE idempotency_key = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = $this->get_row( $this->prepare( $sql, array( $record['idempotency_storage_key'] ) ) );
		if ( null === $row ) {
			return null;
		}
		if (
			'worksheet.created' !== (string) ( $row['event_type'] ?? '' )
			|| self::OUTBOX_PAYLOAD_SCHEMA !== (string) ( $row['payload_schema'] ?? '' )
			|| self::PROFILE_VERSION !== $this->positive_integer( $row['payload_version'] ?? null )
		) {
			throw Worksheet_Failure::stored_data_invalid();
		}

		$payload = $this->decode_canonical_json(
			(string) ( $row['payload_json'] ?? '' ),
			(string) ( $row['payload_hash'] ?? '' )
		);
		if ( ! hash_equals( (string) ( $payload['request_digest'] ?? '' ), $record['request_digest'] ) ) {
			throw Worksheet_Failure::idempotency_conflict();
		}
		$receipt = $payload['receipt'] ?? null;
		if ( ! is_array( $receipt ) || (string) ( $row['aggregate_public_id'] ?? '' ) !== (string) ( $receipt['worksheet_id'] ?? '' ) ) {
			throw Worksheet_Failure::stored_data_invalid();
		}

		try {
			return new Create_Worksheet_Result(
				(string) ( $receipt['worksheet_id'] ?? '' ),
				(string) ( $receipt['revision_id'] ?? '' ),
				(string) ( $receipt['document_id'] ?? '' ),
				(string) ( $receipt['document_hash'] ?? '' ),
				(string) ( $receipt['title'] ?? '' ),
				(string) ( $receipt['created_at_utc'] ?? '' ),
				true
			);
		} catch ( InvalidArgumentException ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
	}

	/**
	 * Reject invalid initial record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_initial_record( array $record ): void {
		$required_strings = array(
			'command_id',
			'correlation_id',
			'created_at_utc',
			'idempotency_storage_key',
			'request_digest',
			'worksheet_public_id',
			'revision_public_id',
			'audit_public_id',
			'outbox_public_id',
			'title',
			'locale',
			'document_id',
			'document_json',
			'document_hash',
			'validation_hash',
		);
		foreach ( $required_strings as $key ) {
			if ( ! isset( $record[ $key ] ) || ! is_string( $record[ $key ] ) || '' === $record[ $key ] ) {
				throw new InvalidArgumentException( 'Initial worksheet record is incomplete.' );
			}
		}
		if ( ! isset( $record['actor_user_id'] ) || ! is_int( $record['actor_user_id'] ) || $record['actor_user_id'] < 1 ) {
			throw new InvalidArgumentException( 'Initial worksheet actor is invalid.' );
		}
		foreach ( array( 'worksheet_public_id', 'revision_public_id', 'audit_public_id', 'outbox_public_id', 'document_id' ) as $key ) {
			new Public_Id( $record[ $key ] );
		}
		foreach ( array( 'idempotency_storage_key', 'request_digest', 'document_hash', 'validation_hash' ) as $key ) {
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $record[ $key ] ) ) {
				throw new InvalidArgumentException( 'Initial worksheet digest is invalid.' );
			}
		}
		$this->assert_utc( $record['created_at_utc'] );
		if ( strlen( $record['document_json'] ) > 2 * 1024 * 1024 ) {
			throw new InvalidArgumentException( 'Initial worksheet document exceeds the profile limit.' );
		}

		$document = $this->decode_canonical_json( $record['document_json'], $record['document_hash'] );
		if (
			( $document['schema_id'] ?? null ) !== Initial_Document_Factory::DOCUMENT_SCHEMA
			|| ( $document['schema_version'] ?? null ) !== Initial_Document_Factory::DOCUMENT_SCHEMA_VERSION
			|| ( $document['editor_profile'] ?? null ) !== Initial_Document_Factory::EDITOR_PROFILE
			|| ( $document['document_id'] ?? null ) !== $record['document_id']
			|| ( $document['title'] ?? null ) !== $record['title']
		) {
			throw new InvalidArgumentException( 'Initial worksheet document does not match its record.' );
		}
		$expected_validation_hash = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'schema_id'      => Initial_Document_Factory::VALIDATION_SCHEMA,
					'schema_version' => Initial_Document_Factory::VALIDATION_VERSION,
					'status'         => 'valid',
					'errors'         => array(),
					'warnings'       => array(),
				)
			)
		);
		if ( ! hash_equals( $expected_validation_hash, $record['validation_hash'] ) ) {
			throw new InvalidArgumentException( 'Initial worksheet validation hash is invalid.' );
		}
	}

	/**
	 * Reject invalid initial postcondition.
	 *
	 * @param int                 $worksheet_pk Worksheet pk.
	 * @param int                 $revision_pk Revision pk.
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_initial_postcondition( int $worksheet_pk, int $revision_pk, array $record ): void {
		$sql = 'SELECT w.current_revision_public_id, r.public_id AS revision_public_id, r.worksheet_id FROM ' . self::TABLE_WORKSHEETS . ' w INNER JOIN ' . self::TABLE_REVISIONS . ' r ON r.id = %d '
			. 'WHERE w.id = %d LIMIT 1 FOR UPDATE';
		$row = $this->get_row( $this->prepare( $sql, array( $revision_pk, $worksheet_pk ) ) );
		if (
			null === $row
			|| (string) ( $row['current_revision_public_id'] ?? '' ) !== $record['revision_public_id']
			|| (string) ( $row['revision_public_id'] ?? '' ) !== $record['revision_public_id']
			|| $this->positive_integer( $row['worksheet_id'] ?? null ) !== $worksheet_pk
		) {
			throw new RuntimeException( 'Initial worksheet postcondition failed.' );
		}
	}

	/**
	 * Question projections.
	 *
	 * @param int $revision_pk Revision pk.
	 * @return array<int,array<string,mixed>>
	 */
	private function question_projections( int $revision_pk ): array {
		$sql   = 'SELECT public_id, question_key, position, response_type, question_schema, question_version, question_json, question_hash, private_metadata_schema, private_metadata_version, private_metadata_json, private_metadata_hash FROM ' . self::TABLE_QUESTIONS
			. ' WHERE worksheet_revision_id = %d ORDER BY position ASC, public_id ASC';
		$rows  = $this->get_results( $this->prepare( $sql, array( $revision_pk ) ) );
		$items = array();
		foreach ( $rows as $row ) {
			$id = (string) ( $row['public_id'] ?? '' );
			$this->assert_stored_public_id( $id );
			$question = $this->decode_canonical_json(
				(string) ( $row['question_json'] ?? '' ),
				(string) ( $row['question_hash'] ?? '' )
			);
			$private  = null;
			if ( null !== ( $row['private_metadata_json'] ?? null ) ) {
				$private = $this->decode_canonical_json(
					(string) $row['private_metadata_json'],
					(string) ( $row['private_metadata_hash'] ?? '' )
				);
			}
			$items[] = array(
				'id'               => $id,
				'question_key'     => (string) ( $row['question_key'] ?? '' ),
				'position'         => $this->non_negative_integer( $row['position'] ?? null ),
				'response_type'    => (string) ( $row['response_type'] ?? '' ),
				'question_schema'  => (string) ( $row['question_schema'] ?? '' ),
				'question_version' => $this->positive_integer( $row['question_version'] ?? null ),
				'question'         => $question,
				'private_metadata' => $private,
			);
		}

		return $items;
	}

	/**
	 * Worksheet projection.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function worksheet_projection( array $row ): array {
		$id = (string) ( $row['public_id'] ?? '' );
		$this->assert_stored_public_id( $id );
		$current_revision        = $this->optional_public_id( $row['current_revision_public_id'] ?? null );
		$latest_publication      = $this->optional_public_id( $row['latest_publication_public_id'] ?? null );
		$latest_publication_hash = $row['latest_publication_hash'] ?? null;
		if ( null === $latest_publication ) {
			if ( null !== $latest_publication_hash ) {
				throw Worksheet_Failure::stored_data_invalid();
			}
			$latest_publication_hash = null;
		} elseif ( ! is_string( $latest_publication_hash ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $latest_publication_hash ) ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
		$created_at = (string) ( $row['created_at_utc'] ?? '' );
		$updated_at = (string) ( $row['updated_at_utc'] ?? '' );
		$this->assert_stored_utc( $created_at );
		$this->assert_stored_utc( $updated_at );
		$state = $this->root_state( $row['state'] ?? null );

		return array(
			'id'                      => $id,
			'title'                   => (string) ( $row['title'] ?? '' ),
			'state'                   => $state,
			'current_revision_id'     => $current_revision,
			'latest_publication_id'   => $latest_publication,
			'latest_publication_hash' => $latest_publication_hash,
			'lock_version'            => $this->non_negative_integer( $row['lock_version'] ?? null ),
			'created_at_utc'          => $created_at,
			'updated_at_utc'          => $updated_at,
			'relation'                => 'owner',
			'todo'                    => $this->todo_for_state( $state, $latest_publication ),
		);
	}

	/**
	 * Revision projection.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function revision_projection( array $row ): array {
		$id = (string) ( $row['public_id'] ?? '' );
		$this->assert_stored_public_id( $id );
		$document_hash   = (string) ( $row['document_hash'] ?? '' );
		$validation_hash = (string) ( $row['validation_hash'] ?? '' );
		foreach ( array( $document_hash, $validation_hash ) as $hash ) {
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
				throw Worksheet_Failure::stored_data_invalid();
			}
		}
		$created_at = (string) ( $row['created_at_utc'] ?? '' );
		$this->assert_stored_utc( $created_at );

		return array(
			'id'                        => $id,
			'revision_no'               => $this->positive_integer( $row['revision_no'] ?? null ),
			'state'                     => $this->revision_state( $row['state'] ?? null ),
			'source_revision_id'        => $this->optional_public_id( $row['source_revision_public_id'] ?? null ),
			'editor_profile'            => (string) ( $row['editor_profile_version'] ?? '' ),
			'document_schema_version'   => (string) ( $row['document_schema_version'] ?? '' ),
			'document_hash'             => $document_hash,
			'validation_schema_version' => (string) ( $row['validation_schema_version'] ?? '' ),
			'validation_hash'           => $validation_hash,
			'publication_id'            => $this->optional_public_id( $row['publication_public_id'] ?? null ),
			'publication_state'         => null === ( $row['publication_state'] ?? null )
				? null
				: (string) $row['publication_state'],
			'created_at_utc'            => $created_at,
		);
	}

	/**
	 * Decode canonical json.
	 *
	 * @param string $json Json.
	 * @param string $expected_hash Expected hash.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode_canonical_json( string $json, string $expected_hash ): array {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $expected_hash ) || ! hash_equals( $expected_hash, hash( 'sha256', $json ) ) ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
		try {
			$value = json_decode( $json, true, 128, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
		if ( ! is_array( $value ) || ! hash_equals( $json, $this->canonical->encode( $value ) ) ) {
			throw Worksheet_Failure::stored_data_invalid();
		}

		return $value;
	}

	/**
	 * Result from record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param bool                $replayed Replayed.
	 * @return Create_Worksheet_Result
	 */
	private function result_from_record( array $record, bool $replayed ): Create_Worksheet_Result {
		return new Create_Worksheet_Result(
			$record['worksheet_public_id'],
			$record['revision_public_id'],
			$record['document_id'],
			$record['document_hash'],
			$record['title'],
			$record['created_at_utc'],
			$replayed
		);
	}

	/**
	 * Reject invalid exact keys.
	 *
	 * @param array<string,mixed> $values Values.
	 * @param array<int,string>   $expected Expected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_exact_keys( array $values, array $expected ): void {
		$keys = array_keys( $values );
		sort( $keys, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $keys !== $expected ) {
			throw new InvalidArgumentException( 'Worksheet cursor contains unknown fields.' );
		}
	}

	/**
	 * Root state.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function root_state( $value ): string {
		$state = is_string( $value ) ? $value : '';
		if ( ! in_array( $state, array( 'draft', 'active', 'archived' ), true ) ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
		return $state;
	}

	/**
	 * Revision state.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function revision_state( $value ): string {
		$state = is_string( $value ) ? $value : '';
		if ( ! in_array( $state, array( 'draft', 'approved', 'retired' ), true ) ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
		return $state;
	}

	/**
	 * Todo for state.
	 *
	 * @param string      $state State.
	 * @param string|null $publication_id Publication id.
	 * @return string
	 */
	private function todo_for_state( string $state, ?string $publication_id ): string {
		if ( 'archived' === $state ) {
			return 'restore';
		}
		if ( null !== $publication_id ) {
			return 'review_latest_version';
		}

		return 'continue_editing';
	}

	/**
	 * Optional public id.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private function optional_public_id( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}
		$id = (string) $value;
		$this->assert_stored_public_id( $id );
		return $id;
	}

	/**
	 * Reject invalid stored public id.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_stored_public_id( string $value ): void {
		try {
			new Public_Id( $value );
		} catch ( InvalidArgumentException ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
	}


	/**
	 * Positive integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function positive_integer( $value ): int {
		if ( ! is_int( $value ) && ! ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ) ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
		$value = (int) $value;
		if ( $value < 1 ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
		return $value;
	}

	/**
	 * Non negative integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function non_negative_integer( $value ): int {
		if ( ! is_int( $value ) && ! ( is_string( $value ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
		$value = (int) $value;
		if ( $value < 0 ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
		return $value;
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
			throw new InvalidArgumentException( 'Worksheet UTC timestamp is invalid.' );
		}
	}

	/**
	 * Reject invalid stored utc.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_stored_utc( string $value ): void {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
	}

	/**
	 * Insert or fail.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data Data.
	 * @param array<int,string>   $formats Formats.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function insert_or_fail( string $table, array $data, array $formats ): void {
		$result = $this->database()->insert( $table, $data, $formats );
		if ( false === $result ) {
			throw new RuntimeException( 'Worksheet insert failed.' );
		}
	}

	/**
	 * Insert id.
	 *
	 * @param object $database Database.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function insert_id( object $database ): int {
		$id = $database->insert_id ?? null;
		if ( ! is_int( $id ) && ! is_numeric( $id ) ) {
			throw new RuntimeException( 'Worksheet insert ID is unavailable.' );
		}
		$id = (int) $id;
		if ( $id < 1 ) {
			throw new RuntimeException( 'Worksheet insert ID is invalid.' );
		}
		return $id;
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
			throw Worksheet_Failure::storage_unavailable();
		}
		return $prepared;
	}

	/**
	 * Get row.
	 *
	 * @param string $sql Sql.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function get_row( string $sql ): ?array {
		$database = $this->database();
		$this->reset_database_error( $database );
		$row = $database->get_row( $sql, $this->array_output() );
		$this->assert_database_read_succeeded( $database );
		if ( null === $row ) {
			return null;
		}
		if ( ! is_array( $row ) ) {
			throw Worksheet_Failure::storage_unavailable();
		}
		return $row;
	}

	/**
	 * Get results.
	 *
	 * @param string $sql Sql.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function get_results( string $sql ): array {
		$database = $this->database();
		$this->reset_database_error( $database );
		$rows = $database->get_results( $sql, $this->array_output() );
		$this->assert_database_read_succeeded( $database );
		if ( ! is_array( $rows ) ) {
			throw Worksheet_Failure::storage_unavailable();
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				throw Worksheet_Failure::storage_unavailable();
			}
		}
		return $rows;
	}

	/**
	 * Get var.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 */
	private function get_var( string $sql ) {
		$database = $this->database();
		$this->reset_database_error( $database );
		$value = $database->get_var( $sql );
		$this->assert_database_read_succeeded( $database );

		return $value;
	}

	/**
	 * Reset database error.
	 *
	 * @param object $database Database.
	 * @return void
	 */
	private function reset_database_error( object $database ): void {
		if ( property_exists( $database, 'last_error' ) ) {
			$database->last_error = '';
		}
	}

	/**
	 * Reject invalid database read succeeded.
	 *
	 * @param object $database Database.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_database_read_succeeded( object $database ): void {
		if (
			property_exists( $database, 'last_error' )
			&& '' !== trim( (string) $database->last_error )
		) {
			throw Worksheet_Failure::storage_unavailable();
		}
	}

	/**
	 * Query exactly one or fail.
	 *
	 * @param string $sql Sql.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function query_exactly_one_or_fail( string $sql ): void {
		$result = $this->database()->query( $sql );
		if ( 1 !== $result ) {
			throw new RuntimeException( 'Worksheet compare-and-swap update failed.' );
		}
	}

	/**
	 * Query or fail.
	 *
	 * @param string $sql Sql.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function query_or_fail( string $sql ): void {
		if ( false === $this->database()->query( $sql ) ) {
			throw new RuntimeException( 'Worksheet transaction query failed.' );
		}
	}

	/**
	 * Rollback.
	 *
	 * @return void
	 */
	private function rollback(): void {
		try {
			$this->database()->query( 'ROLLBACK' );
		} catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Best-effort recovery must not replace the primary failure.
			// The original failure remains authoritative.
		}
	}

	/**
	 * Database.
	 *
	 * @return object
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function database(): object {
		$database = $this->database ?? ( $GLOBALS['wpdb'] ?? null );
		if ( ! is_object( $database ) ) {
			throw Worksheet_Failure::storage_unavailable();
		}
		foreach ( array( 'prepare', 'get_row', 'get_results', 'get_var', 'insert', 'query' ) as $method ) {
			if ( ! method_exists( $database, $method ) ) {
				throw Worksheet_Failure::storage_unavailable();
			}
		}

		return $database;
	}

	/**
	 * Array output.
	 *
	 * @return mixed
	 */
	private function array_output() {
		return defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A';
	}
}
