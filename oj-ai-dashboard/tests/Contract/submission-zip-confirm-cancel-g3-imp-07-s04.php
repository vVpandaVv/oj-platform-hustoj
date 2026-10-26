<?php
/**
 * G3-IMP-07-S04 C20/C21 submission ZIP finalization contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/Support/Php_Source_Contract.php';

require __DIR__ . '/submission-zip-mapping-g3-imp-07-s03.php';

use OJAI\Dashboard\Submission\ZipImport\Application\Cancel_Submission_Zip;
use OJAI\Dashboard\Submission\ZipImport\Application\Cancel_Submission_Zip_Command;
use OJAI\Dashboard\Submission\ZipImport\Application\Cancel_Submission_Zip_Result;
use OJAI\Dashboard\Submission\ZipImport\Application\Confirm_Submission_Zip;
use OJAI\Dashboard\Submission\ZipImport\Application\Confirm_Submission_Zip_Command;
use OJAI\Dashboard\Submission\ZipImport\Application\Confirm_Submission_Zip_Result;
use OJAI\Dashboard\Submission\ZipImport\Application\Zip_Import_Failure;
use OJAI\Dashboard\Submission\ZipImport\Infrastructure\WordPress_Submission_Zip_Finalization_Repository;
use OJAI\Dashboard\Submission\ZipImport\Infrastructure\WordPress_Submission_Zip_Mapping_Repository;
use OJAI\Dashboard\Submission\ZipImport\Infrastructure\WordPress_Submission_Zip_Preview_Query;
use OJAI\Dashboard\Submission\ZipImport\Port\Submission_Zip_Finalization_Repository_Port_Interface;
use OJAI\Dashboard\Submission\ZipImport\Presentation\Submission_Zip_Rest_Controller;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

/**
 * G3-IMP-07-S04 C20/C21 submission ZIP finalization contract.
 */
final class C20_Finalization_Port implements Submission_Zip_Finalization_Repository_Port_Interface {
	/**
	 * Confirmation receipt.
	 *
	 * @var Confirm_Submission_Zip_Result|null
	 */
	public ?Confirm_Submission_Zip_Result $confirmation_receipt = null;
	/**
	 * Cancellation receipt.
	 *
	 * @var Cancel_Submission_Zip_Result|null
	 */
	public ?Cancel_Submission_Zip_Result $cancellation_receipt = null;
	/**
	 * Calls.
	 *
	 * @var array<int,string>
	 */
	public array $calls = array();
	/**
	 * Base.
	 *
	 * @var array<string,mixed>
	 */
	public array $base = array();
	/**
	 * Item records.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $item_records = array();
	/**
	 * Completion.
	 *
	 * @var array<string,mixed>
	 */
	public array $completion = array();
	/**
	 * Cancellation.
	 *
	 * @var array<string,mixed>
	 */
	public array $cancellation = array();

	/**
	 * Initialize c20 finalization port dependencies and state.
	 *
	 * @param string $assignment_id Distribution public identifier.
	 */
	public function __construct( private string $assignment_id ) {
	}

	/**
	 * Find confirmation receipt.
	 *
	 * @param string $idempotency_storage_key Idempotency storage key.
	 * @param string $request_digest Request digest.
	 * @return Confirm_Submission_Zip_Result|null
	 */
	public function find_confirmation_receipt( string $idempotency_storage_key, string $request_digest ): ?Confirm_Submission_Zip_Result {
		unset( $idempotency_storage_key, $request_digest );
		$this->calls[] = 'find_confirmation';
		return $this->confirmation_receipt;
	}

	/**
	 * Begin confirmation.
	 *
	 * @param array $record Record.
	 * @return array|Confirm_Submission_Zip_Result
	 */
	public function begin_confirmation( array $record ): array|Confirm_Submission_Zip_Result {
		$this->calls[] = 'begin';
		$this->base    = $record;
		return array( 1, 2 );
	}

	/**
	 * Confirm item.
	 *
	 * @param array $record Record.
	 * @return array
	 */
	public function confirm_item( array $record ): array {
		$this->calls[]                            = 'item:' . $record['item_no'];
		$this->item_records[ $record['item_no'] ] = $record;
		if ( 1 === $record['item_no'] ) {
			return array(
				'error_code'           => null,
				'item_no'              => 1,
				'result_submission_id' => $record['submission_public_id'],
				'state'                => 'imported',
			);
		}
		return array(
			'error_code'           => Zip_Import_Failure::OFFICIAL_EXISTS,
			'item_no'              => 2,
			'result_submission_id' => null,
			'state'                => 'failed',
		);
	}

	/**
	 * Complete confirmation.
	 *
	 * @param array $record Record.
	 * @return Confirm_Submission_Zip_Result
	 */
	public function complete_confirmation( array $record ): Confirm_Submission_Zip_Result {
		$this->calls[]    = 'complete';
		$this->completion = $record;
		return new Confirm_Submission_Zip_Result(
			array(
				'assignment_id'   => $this->assignment_id,
				'excluded_total'  => 1,
				'failed_total'    => 1,
				'id'              => $record['upload_id'],
				'imported_total'  => 1,
				'item_total'      => 3,
				'mapping_version' => $record['expected_mapping_version'],
				'state'           => 'completed',
			),
			array(
				array(
					'error_code'           => null,
					'item_no'              => 1,
					'result_submission_id' => $this->item_records[1]['submission_public_id'],
					'state'                => 'imported',
				),
				array(
					'error_code'           => Zip_Import_Failure::OFFICIAL_EXISTS,
					'item_no'              => 2,
					'result_submission_id' => null,
					'state'                => 'failed',
				),
				array(
					'error_code'           => 'zip_item_type_not_allowed',
					'item_no'              => 3,
					'result_submission_id' => null,
					'state'                => 'excluded',
				),
			),
			$record['actor_user_id'],
			$record['occurred_at_utc'],
			false
		);
	}

	/**
	 * Find cancellation receipt.
	 *
	 * @param string $idempotency_storage_key Idempotency storage key.
	 * @param string $request_digest Request digest.
	 * @return Cancel_Submission_Zip_Result|null
	 */
	public function find_cancellation_receipt( string $idempotency_storage_key, string $request_digest ): ?Cancel_Submission_Zip_Result {
		unset( $idempotency_storage_key, $request_digest );
		$this->calls[] = 'find_cancellation';
		return $this->cancellation_receipt;
	}

	/**
	 * Cancel.
	 *
	 * @param array $record Record.
	 * @return Cancel_Submission_Zip_Result
	 */
	public function cancel( array $record ): Cancel_Submission_Zip_Result {
		$this->calls[]      = 'cancel';
		$this->cancellation = $record;
		return new Cancel_Submission_Zip_Result(
			array(
				'assignment_id'     => $this->assignment_id,
				'cleanup_after_utc' => $record['cleanup_after_utc'],
				'id'                => $record['upload_id'],
				'item_total'        => 3,
				'mapping_version'   => $record['expected_mapping_version'],
				'state'             => 'canceled',
			),
			$record['actor_user_id'],
			$record['occurred_at_utc'],
			false
		);
	}
}

/**
 * G3-IMP-07-S04 C20/C21 submission ZIP finalization contract.
 */
final class C20_Request {
	/**
	 * Initialize c20 request dependencies and state.
	 *
	 * @param array<string,mixed> $body Body.
	 */
	public function __construct( private array $body ) {
	}
	/**
	 * Get json params.
	 *
	 * @return array<string,mixed>
	 */
	public function get_json_params(): array {
		return $this->body;
	}
}

$c20_seed          = 20;
$c20_ids           = new Public_Id_Generator(
	static function ( int $length ) use ( &$c20_seed ): string {
		++$c20_seed;
		return str_repeat( chr( $c20_seed ), $length );
	}
);
$c20_canonical     = new Canonical_Json();
$c20_assignment_id = $c20_ids->create()->value();
$c20_upload_id     = $c20_ids->create()->value();
$c20_fingerprint   = hash( 'sha256', 'c20-idempotency' );
$c20_command       = new Confirm_Submission_Zip_Command(
	42,
	$c20_upload_id,
	'zip-confirm-command-0001',
	2,
	'Scanned student work submitted by the teacher.',
	'class',
	'class-21',
	'class-r4',
	$c20_fingerprint,
	'zip-confirm-correlation-0001',
	$c20_canonical
);
$c20_same_command  = new Confirm_Submission_Zip_Command(
	42,
	$c20_upload_id,
	'zip-confirm-command-0001',
	2,
	'Scanned student work submitted by the teacher.',
	'class',
	'class-21',
	'class-r4',
	$c20_fingerprint,
	'zip-confirm-correlation-0001',
	$c20_canonical
);
g3_same( $c20_command->request_digest(), $c20_same_command->request_digest(), 'C20 request digest must be deterministic.' );
g3_same( $c20_command->idempotency_storage_key(), $c20_same_command->idempotency_storage_key(), 'C20 storage key must be deterministic.' );
g3_invalid(
	static fn () => new Confirm_Submission_Zip_Command(
		42,
		$c20_upload_id,
		'zip-confirm-command-invalid',
		1,
		'Reason',
		'class',
		'class-21',
		'class-r4',
		$c20_fingerprint,
		'zip-confirm-correlation-invalid',
		$c20_canonical
	),
	'C20 must reject a pre-mapping revision.'
);

$c20_port     = new C20_Finalization_Port( $c20_assignment_id );
$c20_use_case = new Confirm_Submission_Zip( $c20_port, $c20_ids, static fn (): string => '2026-08-22 14:00:00' );
$c20_result   = $c20_use_case->execute( $c20_command );
g3_same( array( 'find_confirmation', 'begin', 'item:1', 'item:2', 'complete' ), $c20_port->calls, 'C20 orchestration order drifted.' );
g3_same( 'completed', $c20_result->upload()['state'] ?? null, 'C20 must seal the root after independent item transactions.' );
g3_same( 1, $c20_result->upload()['imported_total'] ?? null, 'C20 must preserve successful proxy submissions.' );
g3_same( 1, $c20_result->upload()['failed_total'] ?? null, 'C20 must expose item-level conflicts without rolling back successful items.' );
g3_same( Zip_Import_Failure::OFFICIAL_EXISTS, $c20_result->items()[1]['error_code'] ?? null, 'C20 official submissions must be reported as an item failure.' );
g3_same( $c20_port->base['lease_outbox_public_id'] ?? null, $c20_port->item_records[1]['lease_outbox_public_id'] ?? null, 'C20 item transactions must share one confirmation lease.' );
g3_same( $c20_port->base['lease_outbox_public_id'] ?? null, $c20_port->completion['lease_outbox_public_id'] ?? null, 'C20 completion must own the same confirmation lease.' );
g3_assert( ( $c20_port->item_records[1]['revision_public_id'] ?? null ) !== ( $c20_port->item_records[2]['revision_public_id'] ?? null ), 'C20 item revisions must have distinct public IDs.' );
g3_same(
	'submission-zip-item:' . hash( 'sha256', $c20_command->idempotency_storage_key() . '|item|1' ),
	$c20_port->item_records[1]['item_idempotency_key'] ?? null,
	'C20 item idempotency must be derived from command storage identity and item number.'
);

$c20_port->confirmation_receipt = new Confirm_Submission_Zip_Result(
	$c20_result->upload(),
	$c20_result->items(),
	$c20_result->confirmed_by_user_id(),
	$c20_result->confirmed_at_utc(),
	true
);
$c20_item_call_count            = count( $c20_port->item_records );
$c20_replay                     = $c20_use_case->execute( $c20_command );
g3_same( true, $c20_replay->replayed(), 'An exact C20 retry must return the durable root receipt.' );
g3_same( $c20_item_call_count, count( $c20_port->item_records ), 'C20 replay must not create another item revision.' );

$c21_command  = new Cancel_Submission_Zip_Command(
	42,
	$c20_upload_id,
	'zip-cancel-command-0001',
	2,
	'class',
	'class-21',
	'class-r4',
	hash( 'sha256', 'c21-idempotency' ),
	'zip-cancel-correlation-0001',
	$c20_canonical
);
$c21_use_case = new Cancel_Submission_Zip( $c20_port, $c20_ids, static fn (): string => '2026-08-22 14:30:00' );
$c21_result   = $c21_use_case->execute( $c21_command );
g3_same( 'canceled', $c21_result->upload()['state'] ?? null, 'C21 must seal an unimported staging root.' );
g3_same( '2026-08-23 14:30:00', $c21_result->upload()['cleanup_after_utc'] ?? null, 'C21 cleanup must be deferred by exactly 24 hours.' );
g3_same( '2026-08-23 14:30:00', $c20_port->cancellation['cleanup_after_utc'] ?? null, 'C21 repository record must carry the deferred cleanup time.' );
$c20_port->cancellation_receipt = new Cancel_Submission_Zip_Result(
	$c21_result->upload(),
	$c21_result->canceled_by_user_id(),
	$c21_result->canceled_at_utc(),
	true
);
$c21_replay                     = $c21_use_case->execute( $c21_command );
g3_same( true, $c21_replay->replayed(), 'An exact C21 retry must return the durable cancellation receipt.' );

$c20_repository      = new WordPress_Submission_Zip_Finalization_Repository( null, $c20_canonical );
$c20_business_method = ( new ReflectionClass( $c20_repository ) )->getMethod( 'submission_business_error' );
$c20_submission_id   = $c20_ids->create()->value();
$c20_revision_id     = $c20_ids->create()->value();
g3_same(
	null,
	$c20_business_method->invoke(
		$c20_repository,
		array( 'assignment_state' => 'open' ),
		array(
			'public_id'                   => $c20_submission_id,
			'state'                       => 'draft',
			'official_revision_public_id' => null,
			'current_revision_public_id'  => null,
			'official_attempt_no'         => 0,
		),
		array()
	),
	'C20 may turn a draft without an official pointer into a proxy submission.'
);
g3_same(
	Zip_Import_Failure::OFFICIAL_EXISTS,
	$c20_business_method->invoke(
		$c20_repository,
		array( 'assignment_state' => 'open' ),
		array(
			'public_id'                   => $c20_submission_id,
			'state'                       => 'submitted',
			'official_revision_public_id' => $c20_revision_id,
			'current_revision_public_id'  => $c20_revision_id,
			'official_attempt_no'         => 1,
		),
		array()
	),
	'C20 must never overwrite an existing official submission.'
);
g3_same(
	null,
	$c20_business_method->invoke(
		$c20_repository,
		array( 'assignment_state' => 'open' ),
		array(
			'public_id'                   => $c20_submission_id,
			'state'                       => 'reopened',
			'official_revision_public_id' => $c20_revision_id,
			'current_revision_public_id'  => $c20_ids->create()->value(),
			'official_attempt_no'         => 1,
		),
		array()
	),
	'C20 may proxy-submit only after the teacher explicitly reopens the submission.'
);

$c20_response_id = $c20_ids->create()->value();
$c20_file_id     = $c20_ids->create()->value();
$c20_sha         = hash( 'sha256', 'c20-file' );
$c20_file        = array(
	'id'                   => 11,
	'public_id'            => $c20_file_id,
	'lock_version'         => 0,
	'owner_type'           => 'submission_zip',
	'owner_public_id'      => $c20_upload_id,
	'purpose'              => 'submission_zip_item',
	'original_name'        => 'answer.pdf',
	'mime_type'            => 'application/pdf',
	'byte_size'            => 200,
	'sha256'               => $c20_sha,
	'state'                => 'quarantined',
	'scan_state'           => 'clean',
	'retention_class'      => 'submission_zip_staging',
	'expires_at_utc'       => null,
	'legal_hold_until_utc' => null,
	'purged_at_utc'        => null,
);
$c20_file_method = ( new ReflectionClass( $c20_repository ) )->getMethod( 'valid_item_file' );
$c20_file_valid  = $c20_file_method->invoke(
	$c20_repository,
	$c20_file,
	array( 'upload_id' => $c20_upload_id ),
	array(
		'byte_size'     => 200,
		'sha256'        => $c20_sha,
		'relative_path' => 'student-101/answer.pdf',
	),
	array(
		'detected_mime_type'      => 'application/pdf',
		'compatible_response_ids' => array( $c20_response_id ),
	),
	array(
		'id'         => $c20_response_id,
		'mime_types' => array( 'application/pdf' ),
		'max_bytes'  => 1024,
	)
);
g3_same( true, $c20_file_valid, 'C20 must accept only the clean private file owned by this ZIP root.' );
$c20_file['owner_public_id'] = $c20_ids->create()->value();
g3_same(
	false,
	$c20_file_method->invoke(
		$c20_repository,
		$c20_file,
		array( 'upload_id' => $c20_upload_id ),
		array(
			'byte_size'     => 200,
			'sha256'        => $c20_sha,
			'relative_path' => 'student-101/answer.pdf',
		),
		array(
			'detected_mime_type'      => 'application/pdf',
			'compatible_response_ids' => array( $c20_response_id ),
		),
		array(
			'id'         => $c20_response_id,
			'mime_types' => array( 'application/pdf' ),
			'max_bytes'  => 1024,
		)
	),
	'C20 must reject a clean file owned by another ZIP root.'
);

$c20_preview_reason = ( new ReflectionClass( WordPress_Submission_Zip_Preview_Query::class ) )->getMethod( 'reason' );
$c20_preview_query  = new WordPress_Submission_Zip_Preview_Query( null, $c20_canonical );
g3_same( Zip_Import_Failure::OFFICIAL_EXISTS, $c20_preview_reason->invoke( $c20_preview_query, Zip_Import_Failure::OFFICIAL_EXISTS ), 'Q35 must expose the safe official-exists item reason.' );
$c20_mapping_reason     = ( new ReflectionClass( WordPress_Submission_Zip_Mapping_Repository::class ) )->getMethod( 'reason' );
$c20_mapping_repository = new WordPress_Submission_Zip_Mapping_Repository( null, $c20_canonical );
g3_same( Zip_Import_Failure::OFFICIAL_EXISTS, $c20_mapping_reason->invoke( $c20_mapping_repository, Zip_Import_Failure::OFFICIAL_EXISTS ), 'C68 remapping must accept the safe official-exists item reason.' );

$c20_controller    = ( new ReflectionClass( Submission_Zip_Rest_Controller::class ) )->newInstanceWithoutConstructor();
$c20_confirm_input = ( new ReflectionClass( Submission_Zip_Rest_Controller::class ) )->getMethod( 'confirm_input' );
$c20_cancel_input  = ( new ReflectionClass( Submission_Zip_Rest_Controller::class ) )->getMethod( 'cancel_input' );
$c20_confirm_body  = array(
	'command_id'               => 'zip-confirm-rest-0001',
	'expected_mapping_version' => 2,
	'reason'                   => 'Teacher proxy submission.',
);
$c20_parsed        = $c20_confirm_input->invoke( $c20_controller, new C20_Request( $c20_confirm_body ) );
g3_same( 'Teacher proxy submission.', $c20_parsed['reason'] ?? null, 'C20 REST must preserve the teacher reason.' );
$c20_cancel_body = array(
	'command_id'               => 'zip-cancel-rest-0001',
	'expected_mapping_version' => 2,
);
$c21_parsed      = $c20_cancel_input->invoke( $c20_controller, new C20_Request( $c20_cancel_body ) );
g3_same( 2, $c21_parsed['expected_mapping_version'] ?? null, 'C21 REST must preserve an integer mapping version.' );
$c20_confirm_body['unknown'] = true;
g3_invalid( static fn () => $c20_confirm_input->invoke( $c20_controller, new C20_Request( $c20_confirm_body ) ), 'C20 REST must reject unknown body fields.' );
$c20_cancel_body['unknown'] = true;
g3_invalid( static fn () => $c20_cancel_input->invoke( $c20_controller, new C20_Request( $c20_cancel_body ) ), 'C21 REST must reject unknown body fields.' );

$c20_addon_root         = dirname( __DIR__, 2 );
$c20_repository_source  = (string) file_get_contents( $c20_addon_root . '/src/Submission/ZipImport/Infrastructure/WordPress_Submission_Zip_Finalization_Repository.php' );
$c20_rest_source        = (string) file_get_contents( $c20_addon_root . '/src/Submission/ZipImport/Presentation/Submission_Zip_Rest_Controller.php' );
$c20_composition_source = (string) file_get_contents( $c20_addon_root . '/src/Core/Composition_Root.php' );
$c20_mapping_source     = (string) file_get_contents( $c20_addon_root . '/src/Submission/ZipImport/Infrastructure/WordPress_Submission_Zip_Mapping_Repository.php' );
foreach ( array( $c20_repository_source, $c20_rest_source, $c20_composition_source, $c20_mapping_source ) as $source ) {
	g3_assert( ! str_contains( $source, '$wpdb->prefix' ), 'C20/C21 must never use a WordPress table prefix.' );
	g3_assert( ! str_contains( $source, 'wp_oj_ai_' ), 'C20/C21 must never use a legacy prefixed table.' );
	g3_assert( ! str_contains( $source, 'CodingPanda\\OjPlatform\\Kernel\\' ), 'C20/C21 must consume only the public OJ Platform Addon API.' );
}
foreach ( array(
	"'oj_ai_zip_uploads'",
	"'oj_ai_zip_upload_items'",
	"'oj_ai_submissions'",
	"'oj_ai_submission_revisions'",
	"'oj_ai_submission_receipts'",
	"'oj_ai_submission_attachments'",
	"'oj_ai_private_files'",
	"'oj_ai_audit_events'",
	"'oj_ai_outbox_events'",
	'START TRANSACTION',
	'FOR UPDATE',
	"'proxy_submit'",
	"'proxy_submitted'",
	'submission_official_exists',
	'confirmation_lease',
	'cleanup_after_utc',
	'record_item_failure',
) as $marker ) {
	g3_assert( str_contains( $c20_repository_source, $marker ), 'C20/C21 repository marker is missing: ' . $marker );
}
g3_assert( substr_count( $c20_repository_source, 'START TRANSACTION' ) >= 4, 'C20/C21 must separate lease, item, completion, and cancellation transactions.' );
g3_assert( ! str_contains( $c20_repository_source, 'DELETE FROM' ), 'C20/C21 must defer byte cleanup instead of deleting synchronously.' );
foreach ( array( 'ROUTE_CONFIRM', 'ROUTE_CANCEL', 'ACTION_CONFIRM', 'ACTION_CANCEL', 'Confirm_Submission_Zip_Command', 'Cancel_Submission_Zip_Command', 'MANAGE_SUBMISSIONS', "'import_zip'" ) as $marker ) {
	g3_assert( str_contains( $c20_rest_source, $marker ), 'C20/C21 REST marker is missing: ' . $marker );
}
foreach ( array( 'WordPress_Submission_Zip_Finalization_Repository', 'new Confirm_Submission_Zip(', 'new Cancel_Submission_Zip(' ) as $marker ) {
	g3_assert( str_contains( $c20_composition_source, $marker ), 'The single Composition Root marker is missing: ' . $marker );
}
g3_assert( Ojai_Php_Source_Contract::contains( $c20_mapping_source, "array( 'ready','mapped','completed' )" ), 'C68 must permit failed-item remapping after a partial C20 completion.' );

echo "G3-IMP-07-S04 passed: C20/C21 orchestration, partial success, official-pointer protection, durable replay, private-file ownership, deferred cleanup, REST input, and public API boundaries.\n";
