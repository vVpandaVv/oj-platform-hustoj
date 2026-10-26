<?php
/**
 * G5-IMP-07-S03-D3-D5 submission aggregate and response projection contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Approve_Legacy_Migration;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Migration_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Target_Transformation_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Mapping_Manifest;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Migration_Plan;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Source_Item;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Source_Snapshot;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Target_Write_Result;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\Legacy_Migration_Submission_Response_Projector;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\Legacy_Source_Profile_0_1_2;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Submission_Writer;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Target_Writer;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

$root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
require_once $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Autoloader.php';
( new Autoloader( $root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * G5 d3d5 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_d3d5_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G5 d3d5 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_d3d5_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G5 d3d5 invalid.
 *
 * @param callable():void $callback Callback.
 * @param string          $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_d3d5_invalid( callable $callback, string $message ): void {
	try {
		$callback();
	} catch ( InvalidArgumentException ) {
		return;
	}
	throw new RuntimeException( $message . ': no invalid argument was raised.' );
}

/**
 * G5 d3d5 storage failure.
 *
 * @param callable():void $callback Callback.
 * @param string          $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_d3d5_storage_failure( callable $callback, string $message ): void {
	try {
		$callback(); } catch ( Legacy_Migration_Failure $failure ) {
		g5_d3d5_same( Legacy_Migration_Failure::STORAGE_UNAVAILABLE, $failure->reason(), $message );
		return;
		}
		throw new RuntimeException( $message . ': no storage failure was raised.' );
}

/**
 * G5 d3d5 source failure.
 *
 * @param callable():void $callback Callback.
 * @param string          $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_d3d5_source_failure( callable $callback, string $message ): void {
	try {
		$callback(); } catch ( Legacy_Target_Transformation_Failure $failure ) {
		g5_d3d5_same( Legacy_Target_Transformation_Failure::SOURCE_INVALID, $failure->reason(), $message );
		return;
		}
		throw new RuntimeException( $message . ': no source failure was raised.' );
}

/**
 * G5-IMP-07-S03-D3-D5 submission aggregate and response projection contract.
 */
final class G5_D3D5_Database {
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Insert id.
	 *
	 * @var int
	 */
	public int $insert_id = 0;
	/**
	 * Fail table.
	 *
	 * @var string|null
	 */
	public ?string $fail_table = null;
	/**
	 * Queries.
	 *
	 * @var array<int,string>
	 */
	public array $queries = array();
	/**
	 * Tables.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public array $tables = array();
	/**
	 * Source.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public array $source = array();
	/**
	 * Next ids.
	 *
	 * @var array<string,int>
	 */
	private array $next_ids = array();
	/**
	 * Snapshot.
	 *
	 * @var array<string,array<int,array<string,mixed>>>|null
	 */
	private ?array $snapshot = null;

	/**
	 * Initialize g5 d3 d5 database dependencies and state.
	 */
	public function __construct() {
		foreach ( array(
			'oj_ai_import_runs',
			'oj_ai_import_items',
			'oj_ai_assignments',
			'oj_ai_assignment_targets',
			'oj_ai_worksheet_publications',
			'oj_ai_worksheet_revisions',
			'oj_ai_worksheet_revision_questions',
			'oj_ai_submissions',
			'oj_ai_submission_revisions',
			'oj_ai_submission_receipts',
			'oj_ai_submission_attachments',
		) as $table ) {
			$this->tables[ $table ] = array();
		}
	}

	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$values Values.
	 * @return string
	 */
	public function prepare( string $sql, ...$values ): string {
		$this->queries[] = $sql;
		return 'G5D3D5:' . base64_encode( serialize( array( $sql, $values ) ) );
	}

	/**
	 * Get row.
	 *
	 * @param string $prepared Prepared.
	 * @param string $output Output.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function get_row( string $prepared, string $output ): ?array {
		unset( $output );
		list( $sql, $values ) = $this->decode( $prepared );
		if ( str_contains( $sql, 'FROM oj_ai_import_runs WHERE id = %d' ) ) {
			return $this->find( 'oj_ai_import_runs', 'id', $values[0] ?? null );
		}
		if ( str_contains( $sql, 'FROM oj_ai_import_items WHERE import_run_id = %d' ) ) {
			foreach ( $this->tables['oj_ai_import_items'] as $row ) {
				if ( (string) $row['import_run_id'] === (string) ( $values[0] ?? '' ) && $row['source_type'] === ( $values[1] ?? null ) && $row['source_key'] === ( $values[2] ?? null ) ) {
					return $row;
				}
			}
			return null;
		}
		if ( preg_match( '/FROM `([^`]+)` WHERE `id` = %d/', $sql, $matches ) ) {
			return $this->source_find( $matches[1], 'id', $values[0] ?? null );
		}
		if ( str_contains( $sql, 'FROM oj_ai_assignments a INNER JOIN oj_ai_assignment_targets' ) ) {
			$assignment = $this->find( 'oj_ai_assignments', 'public_id', $values[0] ?? null );
			if ( null === $assignment ) {
				return null;
			}
			$target = null;
			foreach ( $this->tables['oj_ai_assignment_targets'] as $candidate ) {
				if ( (string) $candidate['assignment_id'] === (string) $assignment['id'] && (string) $candidate['learner_user_id'] === (string) ( $values[1] ?? '' ) ) {
					$target = $candidate;
					break;
				}
			}
			if ( null === $target ) {
				return null;
			}
			return array(
				'assignment_database_id'          => $assignment['id'],
				'assignment_public_id'            => $assignment['public_id'],
				'context_type'                    => $assignment['context_type'],
				'context_id'                      => $assignment['context_id'],
				'context_revision'                => $assignment['context_revision'],
				'assignment_state'                => $assignment['state'],
				'worksheet_publication_public_id' => $assignment['worksheet_publication_public_id'],
				'publication_hash'                => $assignment['publication_hash'],
				'due_at_utc'                      => $assignment['due_at_utc'],
				'schedule_version'                => $assignment['schedule_version'],
				'schedule_hash'                   => $assignment['schedule_hash'],
				'resubmission_policy_hash'        => $assignment['resubmission_policy_hash'],
				'target_type'                     => $target['target_type'],
				'target_context_id'               => $target['target_context_id'],
				'target_context_revision'         => $target['target_context_revision'],
				'membership_revision'             => $target['membership_revision'],
				'learner_user_id'                 => $target['learner_user_id'],
				'target_state'                    => $target['state'],
			);
		}
		if ( str_contains( $sql, 'FROM oj_ai_worksheet_publications p INNER JOIN oj_ai_worksheet_revisions' ) ) {
			$publication = $this->find( 'oj_ai_worksheet_publications', 'public_id', $values[0] ?? null );
			if ( null === $publication ) {
				return null;
			}
			$revision = $this->find( 'oj_ai_worksheet_revisions', 'id', $publication['worksheet_revision_id'] );
			if ( null === $revision ) {
				return null;
			}
			return array(
				'publication_database_id'  => $publication['id'],
				'publication_public_id'    => $publication['public_id'],
				'publication_content_hash' => $publication['content_hash'],
				'publication_hash'         => $publication['publication_hash'],
				'publication_state'        => $publication['state'],
				'worksheet_revision_id'    => $publication['worksheet_revision_id'],
				'response_schema_schema'   => $publication['response_schema_schema'],
				'response_schema_version'  => $publication['response_schema_version'],
				'response_schema_json'     => $publication['response_schema_json'],
				'response_schema_hash'     => $publication['response_schema_hash'],
				'revision_database_id'     => $revision['id'],
				'revision_public_id'       => $revision['public_id'],
				'document_hash'            => $revision['document_hash'],
			);
		}
		if ( str_contains( $sql, 'FROM oj_ai_worksheet_revision_questions WHERE public_id = %s' ) ) {
			return $this->find( 'oj_ai_worksheet_revision_questions', 'public_id', $values[0] ?? null );
		}
		if ( str_contains( $sql, 'FROM oj_ai_submissions s WHERE s.id = %d' ) ) {
			$submission = $this->find( 'oj_ai_submissions', 'id', $values[0] ?? null );
			if ( null === $submission ) {
				return null;
			}
			$revision_count = count( array_filter( $this->tables['oj_ai_submission_revisions'], static fn ( array $row ): bool => (string) $row['submission_id'] === (string) $submission['id'] ) );
			$receipt_count  = count( array_filter( $this->tables['oj_ai_submission_receipts'], static fn ( array $row ): bool => (string) $row['submission_id'] === (string) $submission['id'] ) );
			return array(
				'public_id'                   => $submission['public_id'],
				'current_revision_public_id'  => $submission['current_revision_public_id'],
				'official_revision_public_id' => $submission['official_revision_public_id'],
				'revision_count'              => $revision_count,
				'receipt_count'               => $receipt_count,
			);
		}
		if ( str_contains( $sql, 'FROM oj_ai_submission_revisions r INNER JOIN oj_ai_submissions s' ) ) {
			$revision = $this->find( 'oj_ai_submission_revisions', 'public_id', $values[0] ?? null );
			if ( null === $revision ) {
				return null;
			}
			$submission = $this->find( 'oj_ai_submissions', 'id', $revision['submission_id'] );
			return null === $submission ? null : array(
				'public_id'            => $revision['public_id'],
				'content_hash'         => $revision['content_hash'],
				'submission_public_id' => $submission['public_id'],
			);
		}
		if ( str_contains( $sql, 'SELECT public_id FROM oj_ai_submissions WHERE public_id = %s' ) ) {
			$row = $this->find( 'oj_ai_submissions', 'public_id', $values[0] ?? null );
			return null === $row ? null : array( 'public_id' => $row['public_id'] );
		}
		throw new RuntimeException( 'Unexpected D3-D5 row query: ' . $sql );
	}

	/**
	 * Get results.
	 *
	 * @param string $prepared Prepared.
	 * @param string $output Output.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function get_results( string $prepared, string $output ): array {
		unset( $output );
		list( $sql, $values ) = $this->decode( $prepared );
		if ( ! preg_match( '/FROM `([^`]+)` WHERE `([^`]+)` = %d/', $sql, $matches ) ) {
			throw new RuntimeException( 'Unexpected D3-D5 result query: ' . $sql );
		}
		$rows = array_values( array_filter( $this->source[ $matches[1] ] ?? array(), static fn ( array $row ): bool => (string) ( $row[ $matches[2] ] ?? '' ) === (string) ( $values[0] ?? '' ) ) );
		if ( str_contains( $sql, 'ORDER BY `sort_order`' ) ) {
			usort( $rows, static fn ( array $a, array $b ): int => array( (int) $a['sort_order'], (int) $a['id'] ) <=> array( (int) $b['sort_order'], (int) $b['id'] ) ); } elseif ( str_contains( $sql, 'ORDER BY revision' ) ) {
			usort( $rows, static fn ( array $a, array $b ): int => array( (int) $a['revision'], (int) $a['id'] ) <=> array( (int) $b['revision'], (int) $b['id'] ) );
			} else {
				usort( $rows, static fn ( array $a, array $b ): int => (int) $a['id'] <=> (int) $b['id'] );
			}
			return $rows;
	}

	/**
	 * Insert.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data Data.
	 * @return bool
	 */
	public function insert( string $table, array $data ): bool {
		if ( ! array_key_exists( $table, $this->tables ) || $this->fail_table === $table ) {
			$this->last_error = 'forced D3-D5 insert failure';
			return false;
		}
		$id                       = ( $this->next_ids[ $table ] ?? 0 ) + 1;
		$this->next_ids[ $table ] = $id;
		$this->insert_id          = $id;
		$data['id']               = $id;
		$this->tables[ $table ][] = $data;
		return true;
	}

	/**
	 * Query.
	 *
	 * @param string $sql Sql.
	 * @return bool
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function query( string $sql ): bool {
		$this->queries[] = $sql;
		if ( 'START TRANSACTION' === $sql ) {
			$this->snapshot = unserialize( serialize( $this->tables ), array( 'allowed_classes' => false ) );
			return true;
		}
		if ( 'ROLLBACK' === $sql ) {
			if ( is_array( $this->snapshot ) ) {
				$this->tables = $this->snapshot;
			} $this->snapshot = null;
			return true;
		}
		if ( 'COMMIT' === $sql ) {
			$this->snapshot = null;
			return true;
		}
		throw new RuntimeException( 'Unexpected D3-D5 transaction query: ' . $sql );
	}

	/**
	 * Decode g5 d3 d5 database.
	 *
	 * @param string $prepared Prepared.
	 * @return array{0:string,1:array<int,mixed>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode( string $prepared ): array {
		if ( ! str_starts_with( $prepared, 'G5D3D5:' ) ) {
			throw new RuntimeException( 'D3-D5 query was not prepared.' );
		}
		$value = unserialize( base64_decode( substr( $prepared, 7 ), true ), array( 'allowed_classes' => false ) );
		return array( $value[0], $value[1] );
	}

	/**
	 * Find g5 d3 d5 database.
	 *
	 * @param string $table Table.
	 * @param string $field Field.
	 * @param mixed  $value Value.
	 * @return array<string,mixed>|null
	 */
	private function find( string $table, string $field, mixed $value ): ?array {
		foreach ( $this->tables[ $table ] as $row ) {
			if ( (string) ( $row[ $field ] ?? '' ) === (string) $value ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Source find.
	 *
	 * @param string $table Table.
	 * @param string $field Field.
	 * @param mixed  $value Value.
	 * @return array<string,mixed>|null
	 */
	private function source_find( string $table, string $field, mixed $value ): ?array {
		foreach ( $this->source[ $table ] ?? array() as $row ) {
			if ( (string) ( $row[ $field ] ?? '' ) === (string) $value ) {
				return $row;
			}
		}
		return null;
	}
}

/**
 * G5 d3d5 legacy hash.
 *
 * @param array<string,mixed> $value Value.
 * @return string
 */
function g5_d3d5_legacy_hash( array $value ): string {
	$normalize = function ( mixed $entry ) use ( &$normalize ): mixed {
		if ( null === $entry || is_bool( $entry ) || is_int( $entry ) || is_string( $entry ) ) {
			return $entry;
		}
		if ( is_float( $entry ) ) {
			return floor( $entry ) === $entry ? (int) $entry : $entry;
		}
		$result = array();
		foreach ( $entry as $key => $item ) {
			$result[ $key ] = $normalize( $item );
		}
		if ( ! array_is_list( $result ) ) {
			ksort( $result, SORT_STRING );
		}
		return $result;
	};
	return hash( 'sha256', json_encode( $normalize( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ) );
}

/**
 * G5 d3d5 source item.
 *
 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
 * @param string              $type Type.
 * @param array<string,mixed> $record Record.
 * @param int                 $item_no Item no.
 * @return Legacy_Source_Item
 */
function g5_d3d5_source_item( Canonical_Json $canonical, string $type, array $record, int $item_no ): Legacy_Source_Item {
	$id       = (int) $record['id'];
	$checksum = hash(
		'sha256',
		$canonical->encode(
			array(
				'profile'     => Legacy_Source_Profile_0_1_2::PROFILE,
				'source_key'  => (string) $id,
				'source_type' => $type,
				'record'      => $record,
			)
		)
	);
	return new Legacy_Source_Item( $item_no, $type, $id, $checksum, $record );
}

/**
 * G5 d3d5 add receipt.
 *
 * @param G5_D3D5_Database           $database Database.
 * @param Canonical_Json             $canonical Canonical JSON encoder used for stable digests.
 * @param int                        $run_id Run id.
 * @param Legacy_Source_Item         $source Source.
 * @param Legacy_Target_Write_Result $target Target.
 * @return void
 */
function g5_d3d5_add_receipt( G5_D3D5_Database $database, Canonical_Json $canonical, int $run_id, Legacy_Source_Item $source, Legacy_Target_Write_Result $target ): void {
	$payload                                  = array(
		'profile'    => 'oj_ai.legacy_item_payload@1.0.0',
		'source'     => array(
			'type'     => $source->source_type(),
			'key'      => $source->source_key(),
			'checksum' => $source->source_checksum(),
		),
		'state'      => 'success',
		'target'     => array(
			'type'         => $target->target_type(),
			'public_id'    => $target->target_public_id(),
			'content_hash' => $target->content_hash(),
			'metadata'     => $target->metadata(),
		),
		'error_code' => null,
	);
	$json                                     = $canonical->encode( $payload );
	$database->tables['oj_ai_import_items'][] = array(
		'import_run_id'    => $run_id,
		'source_type'      => $source->source_type(),
		'source_key'       => $source->source_key(),
		'state'            => 'success',
		'target_type'      => $target->target_type(),
		'target_public_id' => $target->target_public_id(),
		'payload_schema'   => 'oj_ai.legacy_item_payload',
		'payload_version'  => 1,
		'payload_json'     => $json,
		'payload_hash'     => hash( 'sha256', $json ),
	);
}

/**
 * G5 d3d5 response profile.
 *
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @param string         $document_hash Document hash.
 * @param array          $items Items.
 * @return array<string,mixed>
 */
function g5_d3d5_response_profile( Canonical_Json $canonical, string $document_hash, array $items ): array {
	$payload = array( 'items' => $items );
	return array(
		'payload'           => $payload,
		'payload_hash'      => hash( 'sha256', $canonical->encode( $payload ) ),
		'projector_version' => '1.0.0',
		'schema_id'         => 'oj_ai.student_response_schema',
		'schema_version'    => '1.0.0',
		'source_hash'       => $document_hash,
	);
}

/**
 * G5 d3d5 fixture.
 *
 * @param bool   $with_attachment With attachment.
 * @param string $status Status.
 * @return array{database:G5_D3D5_Database,canonical:Canonical_Json,profile:Legacy_Source_Profile_0_1_2,writer:WordPress_Legacy_Target_Writer,root:Legacy_Source_Item,revision:Legacy_Source_Item,attachment:?Legacy_Source_Item}
 */
function g5_d3d5_fixture( bool $with_attachment = false, string $status = 'submitted' ): array {
	$canonical         = new Canonical_Json();
	$profile           = new Legacy_Source_Profile_0_1_2();
	$ids               = new Public_Id_Generator();
	$database          = new G5_D3D5_Database();
	$physical          = array(
		'worksheet_assignments'  => 'legacy_oj_ai_worksheet_assignments',
		'worksheet_submissions'  => 'legacy_oj_ai_worksheet_submissions',
		'worksheet_questions'    => 'legacy_oj_ai_worksheet_questions',
		'submission_revisions'   => 'legacy_oj_ai_submission_revisions',
		'submission_attachments' => 'legacy_oj_ai_submission_attachments',
	);
	$assignment_record = array_fill_keys( $profile->columns( 'worksheet_assignments' ), null );
	foreach ( array(
		'id'             => '41',
		'tenant_context' => 'default',
		'template_id'    => '10',
		'academic_year'  => '2026',
		'grade'          => '5',
		'class_name'     => 'class-a',
		'group_id'       => '0',
		'deleted_at'     => null,
	) as $key => $value ) {
		$assignment_record[ $key ] = $value;
	}
	$question_schema = array(
		'question_id' => 'q1',
		'type'        => 'short_text',
	);
	$question_record = array_fill_keys( $profile->columns( 'worksheet_questions' ), null );
	foreach ( array(
		'id'                   => '501',
		'tenant_context'       => 'default',
		'template_id'          => '10',
		'question_id'          => 'q1',
		'sort_order'           => '0',
		'question_schema_json' => json_encode( $question_schema ),
		'deleted_at'           => null,
	) as $key => $value ) {
		$question_record[ $key ] = $value;
	}
	$content           = array(
		'version' => 1,
		'engine'  => 'worksheet_student_answers',
		'answers' => array(
			'q1' => array(
				'question_id' => 'q1',
				'block_id'    => 'block-q1',
				'type'        => 'short_text',
				'value'       => 'Historical answer',
			),
		),
	);
	$content_json      = json_encode( $content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	$submission_record = array_fill_keys( $profile->columns( 'worksheet_submissions' ), null );
	foreach ( array(
		'id'                  => '701',
		'tenant_context'      => 'default',
		'assignment_id'       => '41',
		'student_user_id'     => '77',
		'status'              => $status,
		'server_revision'     => '1',
		'autosave_session_id' => 'session-1',
		'submitted_at'        => '2026-08-20 10:00:00',
		'locked_at'           => 'locked' === $status ? '2026-08-20 10:05:00' : null,
		'content_json'        => $content_json,
		'created_at'          => '2026-08-20 09:00:00',
		'updated_at'          => '2026-08-20 10:05:00',
		'deleted_at'          => null,
	) as $key => $value ) {
		$submission_record[ $key ] = $value;
	}
	$revision_record = array_fill_keys( $profile->columns( 'submission_revisions' ), null );
	foreach ( array(
		'id'              => '801',
		'tenant_context'  => 'default',
		'submission_id'   => '701',
		'revision'        => '1',
		'idempotency_key' => 'legacy-r1',
		'actor_user_id'   => '77',
		'content_json'    => $content_json,
		'created_at'      => '2026-08-20 09:55:00',
		'updated_at'      => '2026-08-20 09:55:00',
		'deleted_at'      => null,
	) as $key => $value ) {
		$revision_record[ $key ] = $value;
	}
	$attachment_record = null;
	if ( $with_attachment ) {
		$attachment_record = array_fill_keys( $profile->columns( 'submission_attachments' ), null );
		foreach ( array(
			'id'              => '901',
			'tenant_context'  => 'default',
			'submission_id'   => '701',
			'block_id'        => 'block-q1',
			'attachment_type' => 'file',
			'status'          => 'uploaded',
			'file_id'         => '12',
			'file_url'        => 'private-source-url',
			'created_at'      => '2026-08-20 09:58:00',
			'updated_at'      => '2026-08-20 09:58:00',
			'deleted_at'      => null,
		) as $key => $value ) {
			$attachment_record[ $key ] = $value;
		}
	}
	$database->source[ $physical['worksheet_assignments'] ]  = array( $assignment_record );
	$database->source[ $physical['worksheet_questions'] ]    = array( $question_record );
	$database->source[ $physical['submission_revisions'] ]   = array( $revision_record );
	$database->source[ $physical['submission_attachments'] ] = null === $attachment_record ? array() : array( $attachment_record );

	$mapping = new Legacy_Mapping_Manifest( $canonical, Legacy_Source_Profile_0_1_2::PROFILE );
	$mapping->observe( 'worksheet_assignments', $assignment_record );
	$mapping->observe( 'worksheet_submissions', $submission_record );
	$manifest  = $mapping->manifest();
	$decisions = array();
	foreach ( $manifest as $requirement ) {
		$target      = 'context' === $requirement['kind']
			? array(
				'context_id'       => 'class-7',
				'context_revision' => 'context-r3',
				'context_type'     => 'class',
				'roster_revision'  => 'roster-r9',
			)
			: array(
				'learner_user_id'     => 707,
				'membership_revision' => 'membership-707',
			);
		$decisions[] = array(
			'kind'       => $requirement['kind'],
			'source_ref' => $requirement['source_ref'],
			'target'     => $target,
		);
	}
	$tables = array();
	foreach ( $physical as $logical => $source_table ) {
		$rows = match ( $logical ) {
			'worksheet_assignments', 'worksheet_submissions', 'worksheet_questions', 'submission_revisions' => 1, 'submission_attachments' => $with_attachment ? 1 : 0 };
		$tables[] = array(
			'logical_table' => $logical,
			'source_table'  => $source_table,
			'disposition'   => 'migrate',
			'rows'          => $rows,
		);
	}
	$snapshot                                = new Legacy_Source_Snapshot( Legacy_Source_Profile_0_1_2::PROFILE, Legacy_Source_Profile_0_1_2::VERSION, hash( 'sha256', 'd3-d5-source-' . ( $with_attachment ? 'file' : 'plain' ) ), $tables, $with_attachment ? 1 : 0, 100, $mapping->requirements(), array(), array(), $manifest );
	$plan                                    = ( new Legacy_Migration_Plan( $canonical ) )->build( '7', $snapshot, $decisions );
	$preview                                 = array(
		'plan'    => $plan,
		'profile' => Approve_Legacy_Migration::PREVIEW_PROFILE,
		'state'   => 'approved',
	);
	$preview_json                            = $canonical->encode( $preview );
	$database->tables['oj_ai_import_runs'][] = array(
		'id'                 => 51,
		'state'              => 'running',
		'source_kind'        => (string) strstr( Legacy_Source_Profile_0_1_2::PROFILE, '@', true ),
		'source_version'     => Legacy_Source_Profile_0_1_2::VERSION,
		'source_fingerprint' => $plan['source']['fingerprint'],
		'preview_schema'     => Approve_Legacy_Migration::PREVIEW_SCHEMA,
		'preview_version'    => Approve_Legacy_Migration::PREVIEW_DATABASE_VERSION,
		'preview_json'       => $preview_json,
		'preview_hash'       => hash( 'sha256', $preview_json ),
	);

	$worksheet_public_id                                = $ids->create()->value();
	$worksheet_revision_public_id                       = $ids->create()->value();
	$question_public_id                                 = $ids->create()->value();
	$publication_public_id                              = $ids->create()->value();
	$assignment_public_id                               = $ids->create()->value();
	$document_hash                                      = hash( 'sha256', 'd3-d5-document' );
	$publication_hash                                   = hash( 'sha256', 'd3-d5-publication' );
	$response_id                                        = $ids->create()->value();
	$response_profile                                   = g5_d3d5_response_profile(
		$canonical,
		$document_hash,
		array(
			array(
				'response_id' => $response_id,
				'kind'        => 'short_text',
				'label'       => 'Answer',
				'required'    => true,
				'position'    => 0,
				'constraints' => array( 'max_chars' => 1000 ),
			),
		)
	);
	$response_json                                      = $canonical->encode( $response_profile );
	$response_hash                                      = hash( 'sha256', $response_json );
	$database->tables['oj_ai_worksheet_revisions'][]    = array(
		'id'            => 61,
		'public_id'     => $worksheet_revision_public_id,
		'document_hash' => $document_hash,
	);
	$database->tables['oj_ai_worksheet_publications'][] = array(
		'id'                      => 71,
		'public_id'               => $publication_public_id,
		'content_hash'            => $publication_hash,
		'publication_hash'        => $publication_hash,
		'state'                   => 'retired',
		'worksheet_revision_id'   => 61,
		'response_schema_schema'  => 'oj_ai.student_response_schema',
		'response_schema_version' => 1,
		'response_schema_json'    => $response_json,
		'response_schema_hash'    => $response_hash,
	);
	$question_content_hash                              = hash( 'sha256', 'd3-d5-question' );
	$database->tables['oj_ai_worksheet_revision_questions'][] = array(
		'id'                    => 81,
		'public_id'             => $question_public_id,
		'content_hash'          => $question_content_hash,
		'worksheet_revision_id' => 61,
		'question_key'          => $response_id,
		'response_type'         => 'short_text',
	);
	$schedule_hash                                  = hash( 'sha256', 'd3-d5-schedule' );
	$policy_hash                                    = hash( 'sha256', 'd3-d5-policy' );
	$database->tables['oj_ai_assignments'][]        = array(
		'id'                              => 91,
		'public_id'                       => $assignment_public_id,
		'context_type'                    => 'class',
		'context_id'                      => 'class-7',
		'context_revision'                => 'context-r3',
		'state'                           => 'closed',
		'worksheet_publication_public_id' => $publication_public_id,
		'publication_hash'                => $publication_hash,
		'due_at_utc'                      => '2026-08-20 10:00:00',
		'schedule_version'                => 1,
		'schedule_hash'                   => $schedule_hash,
		'resubmission_policy_hash'        => $policy_hash,
	);
	$database->tables['oj_ai_assignment_targets'][] = array(
		'id'                      => 101,
		'assignment_id'           => 91,
		'target_type'             => 'class',
		'target_context_id'       => 'class-7',
		'target_context_revision' => 'context-r3',
		'membership_revision'     => 'membership-707',
		'learner_user_id'         => 707,
		'state'                   => 'active',
	);

	$assignment_item   = g5_d3d5_source_item( $canonical, 'worksheet_assignments', $assignment_record, 1 );
	$assignment_result = new Legacy_Target_Write_Result(
		'assignment',
		$assignment_public_id,
		hash( 'sha256', 'assignment-result' ),
		array(
			'assignment_source_key'    => '41',
			'context_snapshot_hash'    => hash( 'sha256', 'context' ),
			'publication_hash'         => $publication_hash,
			'publication_public_id'    => $publication_public_id,
			'resubmission_policy_hash' => $policy_hash,
			'revision_document_hash'   => $document_hash,
			'revision_public_id'       => $worksheet_revision_public_id,
			'schedule_hash'            => $schedule_hash,
			'target_count'             => 1,
			'target_snapshot_hash'     => hash( 'sha256', 'target' ),
			'worksheet_public_id'      => $worksheet_public_id,
		)
	);
	g5_d3d5_add_receipt( $database, $canonical, 51, $assignment_item, $assignment_result );
	$template_record        = array( 'id' => '10' );
	$template_item          = new Legacy_Source_Item( 1, 'worksheet_templates', 10, hash( 'sha256', 'template-source' ), $template_record );
	$source_key             = hash( 'sha256', 'q1' );
	$worksheet_content_hash = hash( 'sha256', 'worksheet-result' );
	$worksheet_result       = new Legacy_Target_Write_Result(
		'worksheet',
		$worksheet_public_id,
		$worksheet_content_hash,
		array(
			'document_hash'          => $document_hash,
			'revision_content_hash'  => $worksheet_content_hash,
			'question_count'         => 1,
			'question_public_ids'    => array( $source_key => $question_public_id ),
			'question_source_hashes' => array( $source_key => g5_d3d5_legacy_hash( $question_schema ) ),
			'worksheet_link_count'   => 0,
			'worksheet_link_hashes'  => array(),
			'question_link_count'    => 0,
			'question_link_hashes'   => array(),
			'revision_public_id'     => $worksheet_revision_public_id,
		)
	);
	g5_d3d5_add_receipt( $database, $canonical, 51, $template_item, $worksheet_result );
	$question_item   = g5_d3d5_source_item( $canonical, 'worksheet_questions', $question_record, 1 );
	$question_result = new Legacy_Target_Write_Result(
		'worksheet_question',
		$question_public_id,
		$question_content_hash,
		array(
			'revision_public_id'  => $worksheet_revision_public_id,
			'template_source_key' => '10',
			'worksheet_public_id' => $worksheet_public_id,
		)
	);
	g5_d3d5_add_receipt( $database, $canonical, 51, $question_item, $question_result );

	$submission_writer = new WordPress_Legacy_Migration_Submission_Writer( $canonical, $database, $ids, $profile );
	$writer            = new WordPress_Legacy_Target_Writer( $canonical, $database, $ids, $profile, null, null, $submission_writer );
	return array(
		'database'   => $database,
		'canonical'  => $canonical,
		'profile'    => $profile,
		'writer'     => $writer,
		'root'       => g5_d3d5_source_item( $canonical, 'worksheet_submissions', $submission_record, 1 ),
		'revision'   => g5_d3d5_source_item( $canonical, 'submission_revisions', $revision_record, 2 ),
		'attachment' => null === $attachment_record ? null : g5_d3d5_source_item( $canonical, 'submission_attachments', $attachment_record, 3 ),
	);
}

// Ten-kind response conversion stays on the current validator contract.
$canonical    = new Canonical_Json();
$ids          = new Public_Id_Generator();
$kinds        = array( 'short_text', 'long_text', 'single_choice', 'multiple_choice', 'checkbox', 'number', 'table', 'image_upload', 'file_upload', 'drawing' );
$items        = array();
$bindings     = array();
$answers      = array();
$response_ids = array();
foreach ( $kinds as $position => $kind ) {
	$response_id           = $ids->create()->value();
	$response_ids[ $kind ] = $response_id;
	$constraints           = array();
	$question              = array(
		'question_id' => 'q' . $position,
		'type'        => $kind,
	);
	if ( in_array( $kind, array( 'single_choice', 'multiple_choice' ), true ) ) {
		$constraints = array(
			'options' => array(
				array(
					'option_id' => $ids->create()->value(),
					'value'     => 'A',
					'label'     => 'A',
				),
				array(
					'option_id' => $ids->create()->value(),
					'value'     => 'B',
					'label'     => 'B',
				),
			),
		);
	}
	if ( 'table' === $kind ) {
		$question['table'] = array(
			'rows'    => array(
				array(
					'key'   => 'r1',
					'label' => 'R1',
				),
			),
			'columns' => array(
				array(
					'key'        => 'c1',
					'label'      => 'C1',
					'input_type' => 'number',
				),
			),
		);
		$constraints       = array(
			'row_mode' => 'fixed',
			'rows'     => array(
				array(
					'row_id' => $ids->create()->value(),
					'label'  => 'R1',
				),
			),
			'columns'  => array(
				array(
					'column_id' => $ids->create()->value(),
					'label'     => 'C1',
					'cell_type' => 'number',
				),
			),
		);
	}
	if ( in_array( $kind, array( 'image_upload', 'file_upload' ), true ) ) {
		$constraints = array(
			'mime_types' => array( 'image/png' ),
			'max_files'  => 2,
			'max_bytes'  => 1024,
		);
	}
	if ( 'drawing' === $kind ) {
		$constraints = array(
			'stroke_schema_version' => 'oj_ai.drawing_strokes@1',
			'preview_mime_type'     => 'image/png',
			'aspect_ratio'          => '1',
			'max_strokes'           => 100,
		);
	}
	$items[]                     = array(
		'response_id' => $response_id,
		'kind'        => $kind,
		'label'       => strtoupper( $kind ),
		'required'    => false,
		'position'    => $position,
		'constraints' => $constraints,
	);
	$bindings[ 'q' . $position ] = array(
		'response_id' => $response_id,
		'kind'        => $kind,
		'question'    => $question,
	);
	$value                       = match ( $kind ) {
		'short_text' => 'short', 'long_text' => 'long', 'single_choice' => 'B', 'multiple_choice' => array( 'B', 'A' ), 'checkbox' => true, 'number' => 1.5, 'table' => array( 'r1' => array( 'c1' => 2.5 ) ), 'image_upload', 'file_upload' => array( 'files' => array() ), 'drawing' => array( 'drawing' => null ) };
	$answers[ 'q' . $position ] = array(
		'question_id' => 'q' . $position,
		'block_id'    => 'b' . $position,
		'type'        => $kind,
		'value'       => $value,
	);
}
$profile   = g5_d3d5_response_profile( $canonical, hash( 'sha256', 'projection-document' ), $items );
$projector = new Legacy_Migration_Submission_Response_Projector( $canonical );
$projected = $projector->project(
	$profile,
	array(
		'version' => 1,
		'engine'  => 'worksheet_student_answers',
		'answers' => $answers,
	),
	$bindings
);
g5_d3d5_assert( $projected['complete'], 'Ten-kind attachment-free response did not pass complete validation.' );
g5_d3d5_same( $items[2]['constraints']['options'][1]['option_id'], $projected['answers'][ $response_ids['single_choice'] ], 'Choice value was not mapped to option ID.' );
g5_d3d5_same( '1.5', $projected['answers'][ $response_ids['number'] ], 'Numeric response was not canonicalized.' );
$answers['q7']['value'] = array( 'files' => array( array( 'attachment_id' => 12 ) ) );
$deferred               = $projector->project(
	$profile,
	array(
		'version' => 1,
		'engine'  => 'worksheet_student_answers',
		'answers' => $answers,
	),
	$bindings
);
g5_d3d5_assert( $deferred['deferred_attachment'] && ! $deferred['complete'], 'Legacy attachment response was not deferred.' );
$bad                = $answers;
$bad['q2']['value'] = 'missing-option';
g5_d3d5_invalid(
	static fn () => $projector->project(
		$profile,
		array(
			'version' => 1,
			'engine'  => 'worksheet_student_answers',
			'answers' => $bad,
		),
		$bindings
	),
	'Unknown legacy choice remained importable'
);
$zip_content = array(
	'answers'    => array(),
	'engine'     => 'zip_upload_submission',
	'files'      => array(),
	'version'    => 1,
	'zip_upload' => array(),
);
$zip         = $projector->project( $profile, $zip_content, $bindings );
g5_d3d5_assert( $zip['deferred_attachment'] && ! $zip['complete'] && array() === $zip['answers'], 'Legacy ZIP response was not deferred.' );
$corrupt_profile                 = $profile;
$corrupt_profile['payload_hash'] = hash( 'sha256', 'corrupt-profile' );
g5_d3d5_invalid(
	static fn () => $projector->project( $corrupt_profile, $zip_content, $bindings ),
	'ZIP deferral bypassed current response profile validation'
);

// Submitted aggregate, child relation verification, and caller-owned transaction.
$fixture = g5_d3d5_fixture();
$db      = $fixture['database'];
$db->query( 'START TRANSACTION' );
$result = $fixture['writer']->write( 51, $fixture['root'], 99, '2026-08-26 09:00:00' );
$db->query( 'COMMIT' );
g5_d3d5_same( 'submission', $result->target_type(), 'Submission root was not dispatched.' );
g5_d3d5_same( 'submitted', $db->tables['oj_ai_submissions'][0]['state'], 'Submitted legacy root was not restored.' );
g5_d3d5_same( 1, count( $db->tables['oj_ai_submission_revisions'] ), 'Submission revision was not restored exactly once.' );
g5_d3d5_same( 1, count( $db->tables['oj_ai_submission_receipts'] ), 'Official receipt was not restored exactly once.' );
g5_d3d5_same( 'learner', $db->tables['oj_ai_submission_receipts'][0]['actor_type'], 'Submit receipt actor is not the mapped learner.' );
g5_d3d5_assert( ! isset( $db->tables['oj_ai_submission_attachments'][0] ), 'D3-D5 fabricated a target attachment.' );
g5_d3d5_add_receipt( $db, $fixture['canonical'], 51, $fixture['root'], $result );
$revision_result = $fixture['writer']->write( 51, $fixture['revision'], 99, '2026-08-26 09:01:00' );
g5_d3d5_same( 'submission_revision', $revision_result->target_type(), 'Revision relation was not verification-only.' );
g5_d3d5_same( 1, count( array_filter( $db->queries, static fn ( string $sql ): bool => 'START TRANSACTION' === $sql ) ), 'Submission writer opened a nested transaction.' );
$db->source['legacy_oj_ai_submission_revisions'][0]['content_json'] = '{"version":1,"engine":"worksheet_student_answers","answers":{}}';
g5_d3d5_source_failure(
	static fn () => $fixture['writer']->write( 51, $fixture['revision'], 99, '2026-08-26 09:01:01' ),
	'Revision relation source drift was accepted'
);

// Locked roots use the runtime-required teacher control receipt.
$locked = g5_d3d5_fixture( false, 'locked' );
$locked['database']->query( 'START TRANSACTION' );
$locked_result = $locked['writer']->write( 51, $locked['root'], 99, '2026-08-26 09:02:00' );
$locked['database']->query( 'COMMIT' );
g5_d3d5_same( 'locked', $locked['database']->tables['oj_ai_submissions'][0]['state'], 'Locked legacy root was not restored.' );
g5_d3d5_same( 'lock', $locked['database']->tables['oj_ai_submission_receipts'][0]['action'], 'Locked root has no control receipt.' );
g5_d3d5_same( 'teacher', $locked['database']->tables['oj_ai_submission_receipts'][0]['actor_type'], 'Locked control receipt violates the runtime teacher contract.' );
g5_d3d5_assert( '' !== trim( (string) $locked['database']->tables['oj_ai_submission_receipts'][0]['reason'] ), 'Locked control receipt reason is empty.' );

// Attachment rows remain manifest-only and force a non-official conflict.
$files = g5_d3d5_fixture( true );
$files['database']->query( 'START TRANSACTION' );
$file_result = $files['writer']->write( 51, $files['root'], 99, '2026-08-26 09:03:00' );
$files['database']->query( 'COMMIT' );
g5_d3d5_same( 'conflict', $files['database']->tables['oj_ai_submissions'][0]['state'], 'Attachment-bearing root became official before D3-D7.' );
g5_d3d5_same( null, $files['database']->tables['oj_ai_submissions'][0]['official_revision_public_id'], 'Deferred attachment root retained an official pointer.' );
g5_d3d5_same( 0, count( $files['database']->tables['oj_ai_submission_receipts'] ), 'Deferred attachment root received an official receipt.' );
g5_d3d5_same( 0, count( $files['database']->tables['oj_ai_submission_attachments'] ), 'Manifest stage created a target attachment row.' );
g5_d3d5_add_receipt( $files['database'], $files['canonical'], 51, $files['root'], $file_result );
$attachment_result = $files['writer']->write( 51, $files['attachment'], 99, '2026-08-26 09:04:00' );
g5_d3d5_same( 'submission_attachment_manifest', $attachment_result->target_type(), 'Attachment relation did not remain a manifest entry.' );
g5_d3d5_same( $file_result->target_public_id(), $attachment_result->target_public_id(), 'Attachment manifest was not bound to its parent submission.' );

// Any SQL failure is left for the caller to roll back as one item.
$rollback                         = g5_d3d5_fixture();
$rollback['database']->fail_table = 'oj_ai_submission_receipts';
$rollback['database']->query( 'START TRANSACTION' );
g5_d3d5_storage_failure( static fn () => $rollback['writer']->write( 51, $rollback['root'], 99, '2026-08-26 09:05:00' ), 'Receipt SQL failure was not surfaced' );
$rollback['database']->query( 'ROLLBACK' );
g5_d3d5_same( 0, count( $rollback['database']->tables['oj_ai_submissions'] ), 'Caller rollback left a submission root.' );
g5_d3d5_same( 0, count( $rollback['database']->tables['oj_ai_submission_revisions'] ), 'Caller rollback left a submission revision.' );

$runtime = file_get_contents( $root . '/src/Operations/LegacyMigration/Infrastructure/WordPress_Legacy_Migration_Submission_Writer.php' );
g5_d3d5_assert( is_string( $runtime ), 'D3-D5 runtime source could not be read.' );
foreach ( array( '$wpdb->prefix', '$wpdb->base_prefix', 'START TRANSACTION', 'COMMIT', 'ROLLBACK', 'oj_ai_audit_events', 'oj_ai_outbox_events' ) as $forbidden ) {
	g5_d3d5_assert( ! str_contains( $runtime, $forbidden ), 'D3-D5 runtime crossed a frozen boundary: ' . $forbidden );
}
foreach ( array( "'oj_ai_submissions'", "'oj_ai_submission_revisions'", "'oj_ai_submission_receipts'" ) as $literal ) {
	g5_d3d5_assert( str_contains( $runtime, $literal ), 'D3-D5 literal target marker is missing: ' . $literal );
}

fwrite( STDOUT, "G5-IMP-07-S03-D3-D5 passed: ten response kinds, official/control receipts, manifest-only attachments, relation verification, and caller rollback are frozen.\n" );
