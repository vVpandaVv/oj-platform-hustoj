<?php
/**
 * G3-IMP-08-S03 C67 Q23/Q24 descriptor and student REST contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

ob_start();
require __DIR__ . '/own-submission-pdf-enqueue-worker-g3-imp-08-s03.php';
ob_end_clean();

use OJAI\Dashboard\Reporting\Export\Application\Export_Access_Guard;
use OJAI\Dashboard\Reporting\Export\Infrastructure\WordPress_Export_Query;
use OJAI\Dashboard\Reporting\SubmissionPdf\Domain\Submission_Pdf_Request_Snapshot;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use OJAI\Dashboard\Submission\Student\Presentation\Student_Assignment_Rest_Controller;

/**
 * G3-IMP-08-S03 C67 Q23/Q24 descriptor and student REST contract.
 */
final class G3S03_Json_Request {
	/**
	 * Initialize g3 s03 json request dependencies and state.
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

/**
 * G3-IMP-08-S03 C67 Q23/Q24 descriptor and student REST contract.
 */
final class G3S03_Deny_Policy implements Access_Policy_Port_Interface {
	/**
	 * Authorize.
	 *
	 * @param Access_Policy_Request $request Request.
	 * @return Access_Policy_Decision
	 */
	public function authorize( Access_Policy_Request $request ): Access_Policy_Decision {
		return Access_Policy_Decision::denied( 403, 'test_revoked', $request->correlation_id() );
	}
}

$query                = new WordPress_Export_Query( null, $canonical );
$authorization_method = new ReflectionMethod( $query, 'authorization' );
$row                  = array(
	'context_type'             => 'class',
	'context_id'               => 'class-21',
	'context_revision'         => 'class-r3',
	'print_profile_version'    => $snapshot->profile_version(),
	'request_contract_version' => Submission_Pdf_Request_Snapshot::OWN_VERSION,
	'request_snapshot_schema'  => Submission_Pdf_Request_Snapshot::SCHEMA,
	'request_snapshot_version' => Submission_Pdf_Request_Snapshot::DATABASE_VERSION,
	'request_snapshot_json'    => $snapshot->json(),
	'request_snapshot_hash'    => $snapshot->hash(),
	'request_hash'             => $snapshot->hash(),
	'source_public_id'         => $snapshot->source_id(),
	'source_hash'              => $snapshot->source_hash(),
);
$authorization        = $authorization_method->invoke( $query, $row, 'submission_revision', 'submission_pdf' );
g3s03_same( 'self_submission', $authorization['object']['relation'] ?? null, 'C67 Q23/Q24 descriptor must remain self-only.' );
g3s03_same( 'export_pdf', $authorization['object']['action'] ?? null, 'C67 Q23/Q24 object action drifted.' );
g3s03_same( $submission_id, $authorization['object']['id'] ?? null, 'C67 Q23/Q24 descriptor lost the submission root.' );
g3s03_same( '2', $authorization['object']['revision'] ?? null, 'C67 Q23/Q24 descriptor lost the exact lock version.' );

$guard_policy = new G3S03_Self_Policy();
$guard        = new Export_Access_Guard( $guard_policy );
g3s03_assert(
	$guard->allows( 42, array( 'authorization' => $authorization ), Access_Policy_Request::SURFACE_REST, 'export.read', 'g3-s03-read' ),
	'Current C67 owner must be able to read Q23/Q24.'
);
$tampered                       = $authorization;
$tampered['object']['relation'] = 'context_staff';
g3s03_assert(
	! $guard->allows( 42, array( 'authorization' => $tampered ), Access_Policy_Request::SURFACE_REST, 'export.read', 'g3-s03-tampered' ),
	'C67 must not fall back to the teacher descriptor.'
);
g3s03_assert(
	! ( new Export_Access_Guard( new G3S03_Deny_Policy() ) )->allows( 42, array( 'authorization' => $authorization ), Access_Policy_Request::SURFACE_REST, 'export.read', 'g3-s03-revoked' ),
	'Revoked C67 access must deny private artifact bytes.'
);
g3s03_assert(
	! $guard->allows(
		42,
		array(
			'artifact_kind' => 'submission_pdf',
			'source_type'   => 'submission_revision',
			'source_id'     => $submission_revision_id,
		),
		Access_Policy_Request::SURFACE_REST,
		'export.read',
		'g3-s03-no-descriptor'
	),
	'C67 rows without a restored descriptor must fail closed.'
);

$controller   = ( new ReflectionClass( Student_Assignment_Rest_Controller::class ) )->newInstanceWithoutConstructor();
$input_method = new ReflectionMethod( $controller, 'submission_pdf_input' );
$body         = array(
	'command_id' => 'command-g3-s03-rest',
	'expected'   => array(
		'expected_assignment_id'           => $assignment_id,
		'expected_assignment_state'        => 'open',
		'expected_attachment_set_hash'     => $attachment_set_hash,
		'expected_context_revision'        => 'class-r3',
		'expected_official_revision_hash'  => $revision_hash,
		'expected_official_revision_id'    => $submission_revision_id,
		'expected_publication_hash'        => $publication_hash,
		'expected_publication_id'          => $publication_id,
		'expected_submission_lock_version' => 2,
		'expected_submission_state'        => 'submitted',
		'expected_target_state'            => 'active',
	),
	'payload'    => array( 'profile' => 'teacher_submission' ),
);
$parsed       = $input_method->invoke( $controller, new G3S03_Json_Request( $body ) );
g3s03_same( $submission_revision_id, $parsed['expected_official_revision_id'] ?? null, 'C67 REST parser lost the official revision.' );
g3s03_same( $attachment_set_hash, $parsed['expected_attachment_set_hash'] ?? null, 'C67 REST parser lost the attachment set.' );
$unknown                  = $body;
$unknown['actor_user_id'] = 42;
try {
	$input_method->invoke( $controller, new G3S03_Json_Request( $unknown ) ); } catch ( ReflectionException | InvalidArgumentException ) {
	$unknown = null;
	}
	g3s03_same( null, $unknown, 'C67 REST input must reject body-supplied actor identity.' );

	$runtime_files = array(
		$addon_root . '/src/Reporting/Export/Application/Export_Access_Guard.php',
		$addon_root . '/src/Reporting/Export/Infrastructure/WordPress_Export_Query.php',
		$addon_root . '/src/Reporting/Export/Presentation/Export_Rest_Controller.php',
		$addon_root . '/src/Submission/Student/Infrastructure/Submission_Object_Authorization.php',
		$addon_root . '/src/Submission/Student/Infrastructure/WordPress_Student_Submission_Query.php',
		$addon_root . '/src/Submission/Student/Presentation/Student_Assignment_Rest_Controller.php',
		$addon_root . '/resources/js/student-assignments.js',
	);
	foreach ( $runtime_files as $runtime_file ) {
		$runtime_source = (string) file_get_contents( $runtime_file );
		g3s03_assert( ! str_contains( $runtime_source, '$wpdb->prefix' ) && ! str_contains( $runtime_source, 'wp_oj_ai_' ), 'C67 query/download runtime used a forbidden table prefix.' );
		g3s03_assert( ! str_contains( $runtime_source, 'CodingPanda\\OjPlatform\\Kernel\\' ), 'C67 query/download runtime depends on a platform internal namespace.' );
	}
	$query_source = (string) file_get_contents( $runtime_files[1] );
	foreach ( array( 'requested_by_user_id = %d', "'self_submission'", "'export_pdf'", '$snapshot->is_own()' ) as $marker ) {
		g3s03_assert( str_contains( $query_source, $marker ), 'C67 export query marker is missing: ' . $marker );
	}
	$controller_source = (string) file_get_contents( $runtime_files[5] );
	foreach ( array( 'ROUTE_SUBMISSION_EXPORT_PDF', 'ACTION_SUBMISSION_EXPORT_PDF', 'permissions_export_submission_pdf', 'export_submission_pdf', 'submission_pdf_input' ) as $marker ) {
		g3s03_assert( str_contains( $controller_source, $marker ), 'C67 student REST marker is missing: ' . $marker );
	}
	$student_query_source = (string) file_get_contents( $runtime_files[4] );
	g3s03_assert( str_contains( $student_query_source, "'submission.export_pdf'" ), 'Q32 does not expose the current-official PDF action.' );
	$ui_source = (string) file_get_contents( $runtime_files[6] );
	foreach ( array( '작성 결과 PDF 만들기', 'export-submission-pdf', 'download-submission-pdf', 'expected_official_revision_hash', 'expected_attachment_set_hash' ) as $marker ) {
		g3s03_assert( str_contains( $ui_source, $marker ), 'C67 student UI marker is missing: ' . $marker );
	}

	echo "G3-IMP-08-S03 query/download passed: own 1.1 descriptor, current Context and submission CAS, private Q23/Q24 fail-closed, and strict student REST/UI.\n";
