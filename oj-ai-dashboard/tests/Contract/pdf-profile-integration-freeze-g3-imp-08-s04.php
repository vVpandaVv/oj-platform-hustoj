<?php
/**
 * G3-IMP-08-S04 cross-profile PDF integration freeze.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

ob_start();
require __DIR__ . '/own-submission-pdf-query-download-g3-imp-08-s03.php';
ob_end_clean();

use OJAI\Dashboard\Reporting\Export\Application\Export_Access_Guard;
use OJAI\Dashboard\Reporting\Export\Infrastructure\WordPress_Export_Query;
use OJAI\Dashboard\Reporting\SubmissionPdf\Domain\Submission_Pdf_Request_Snapshot;
use OJAI\Dashboard\Reporting\SubmissionPdf\Domain\Submission_Print_Html_Serializer;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;

/**
 * G3s04 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g3s04_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G3s04 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g3s04_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G3-IMP-08-S04 cross-profile PDF integration freeze.
 */
final class G3S04_Profile_Policy implements Access_Policy_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Last request.
	 *
	 * @var Access_Policy_Request|null
	 */
	public ?Access_Policy_Request $last_request = null;

	/**
	 * Initialize g3 s04 profile policy dependencies and state.
	 *
	 * @param string $relation Relation.
	 * @param string $current_revision Current revision.
	 * @param string $current_state Current state.
	 */
	public function __construct(
		private string $relation,
		private string $current_revision,
		private string $current_state
	) {
	}

	/**
	 * Authorize.
	 *
	 * @param Access_Policy_Request $request Request.
	 * @return Access_Policy_Decision
	 */
	public function authorize( Access_Policy_Request $request ): Access_Policy_Decision {
		++$this->calls;
		$this->last_request = $request;
		$object             = $request->object();
		if ( null === $object ) {
			return Access_Policy_Decision::denied( 403, 'test_object_missing', $request->correlation_id() );
		}
		$context        = $request->context();
		$context_result = null === $context ? null : Context_Authorization_Result::allowed(
			$context->type(),
			$context->id(),
			'active',
			(string) $context->expected_revision(),
			str_starts_with( $this->relation, 'self_' ) ? 'learner' : 'educator'
		);
		return Access_Policy_Decision::allowed(
			$request->correlation_id(),
			$context_result,
			Object_Authorization_Result::allowed( $this->current_revision, $this->current_state, $this->relation )
		);
	}
}

/**
 * G3s04 submission row.
 *
 * @param Submission_Pdf_Request_Snapshot $value Value.
 * @return array<string,mixed>
 */
function g3s04_submission_row( Submission_Pdf_Request_Snapshot $value ): array {
	$context = $value->context();
	return array(
		'context_type'             => $context['type'],
		'context_id'               => $context['id'],
		'context_revision'         => $context['revision'],
		'print_profile_version'    => $value->profile_version(),
		'request_contract_version' => $value->version(),
		'request_snapshot_schema'  => Submission_Pdf_Request_Snapshot::SCHEMA,
		'request_snapshot_version' => Submission_Pdf_Request_Snapshot::DATABASE_VERSION,
		'request_snapshot_json'    => $value->json(),
		'request_snapshot_hash'    => $value->hash(),
		'request_hash'             => $value->hash(),
		'source_public_id'         => $value->source_id(),
		'source_hash'              => $value->source_hash(),
	);
}

$teacher_snapshot = Submission_Pdf_Request_Snapshot::freeze( $result, 'Asia/Seoul', $canonical );
g3s04_same( Submission_Pdf_Request_Snapshot::VERSION, $teacher_snapshot->version(), 'C49 snapshot version drifted.' );
g3s04_same( Submission_Pdf_Request_Snapshot::OWN_VERSION, $snapshot->version(), 'C67 snapshot version drifted.' );
g3s04_same( null, $teacher_snapshot->ownership(), 'C49 snapshot acquired learner ownership.' );
g3s04_assert( is_array( $snapshot->ownership() ), 'C67 snapshot lost learner ownership.' );
g3s04_same( $teacher_snapshot->source_id(), $snapshot->source_id(), 'C49 and C67 source IDs diverged.' );
g3s04_same( $teacher_snapshot->source_hash(), $snapshot->source_hash(), 'C49 and C67 source hashes diverged.' );
g3s04_same( $teacher_snapshot->attachment_set_hash(), $snapshot->attachment_set_hash(), 'C49 and C67 attachment sets diverged.' );
g3s04_same( $canonical->encode( $teacher_snapshot->projection() ), $canonical->encode( $snapshot->projection() ), 'C49 and C67 canonical print projections must be byte-equivalent.' );
g3s04_assert( ! hash_equals( $teacher_snapshot->hash(), $snapshot->hash() ), 'Teacher and learner authorization snapshots must not share a request hash.' );

$serializer   = new Submission_Print_Html_Serializer( $canonical );
$document_id  = g3s01_id( 240 );
$teacher_html = $serializer->serialize( $teacher_snapshot->projection(), $document_id );
$learner_html = $serializer->serialize( $snapshot->projection(), $document_id );
g3s04_same( $teacher_html, $learner_html, 'C49 and C67 must render the same official answers.' );
foreach ( array( $assignment_id, $target_id, 'ownership', 'requested_by_user_id', 'storage_key' ) as $private_marker ) {
	g3s04_assert( ! str_contains( $learner_html, (string) $private_marker ), 'Submission PDF HTML leaked ownership or storage metadata.' );
}

$query                 = new WordPress_Export_Query( null, $canonical );
$authorization_method  = new ReflectionMethod( $query, 'authorization' );
$teacher_authorization = $authorization_method->invoke( $query, g3s04_submission_row( $teacher_snapshot ), 'submission_revision', 'submission_pdf' );
$learner_authorization = $authorization_method->invoke( $query, g3s04_submission_row( $snapshot ), 'submission_revision', 'submission_pdf' );
g3s04_same( 'context_staff', $teacher_authorization['object']['relation'] ?? null, 'C49 relation drifted.' );
g3s04_same( 'teacher_view', $teacher_authorization['object']['action'] ?? null, 'C49 action drifted.' );
g3s04_same( 'self_submission', $learner_authorization['object']['relation'] ?? null, 'C67 relation drifted.' );
g3s04_same( 'export_pdf', $learner_authorization['object']['action'] ?? null, 'C67 action drifted.' );

$worksheet_authorization = array(
	'context' => array(
		'id'       => 'class-21',
		'revision' => 'class-r3',
		'type'     => 'class',
	),
	'object'  => array(
		'action'   => 'export_pdf',
		'id'       => $assignment_id,
		'relation' => 'self_target',
		'revision' => null,
		'state'    => null,
		'type'     => 'assignment',
	),
);
$teacher_policy          = new G3S04_Profile_Policy( 'context_staff', '2', 'submitted' );
$worksheet_policy        = new G3S04_Profile_Policy( 'self_target', '4', 'open' );
$submission_policy       = new G3S04_Profile_Policy( 'self_submission', '2', 'submitted' );
g3s04_assert( ( new Export_Access_Guard( $teacher_policy ) )->allows( 7, array( 'authorization' => $teacher_authorization ), Access_Policy_Request::SURFACE_REST, 'export.read', 'g3-s04-c49' ), 'C49 profile was denied.' );
g3s04_assert( ( new Export_Access_Guard( $worksheet_policy ) )->allows( 42, array( 'authorization' => $worksheet_authorization ), Access_Policy_Request::SURFACE_REST, 'export.read', 'g3-s04-c51' ), 'C51 profile was denied.' );
g3s04_assert( ( new Export_Access_Guard( $submission_policy ) )->allows( 42, array( 'authorization' => $learner_authorization ), Access_Policy_Request::SURFACE_REST, 'export.read', 'g3-s04-c67' ), 'C67 profile was denied.' );
g3s04_same( Access_Policy_Request::SHELL_CONSOLE, $teacher_policy->last_request?->shell(), 'C49 must remain in OJ Console.' );
g3s04_assert( in_array( Addon_Capabilities::EXPORT_REPORTS, $teacher_policy->last_request?->required_capabilities() ?? array(), true ), 'C49 lost export_reports.' );
g3s04_same( Access_Policy_Request::SHELL_PUBLIC, $worksheet_policy->last_request?->shell(), 'C51 must remain in PublicUI.' );
g3s04_same( Access_Policy_Request::SHELL_PUBLIC, $submission_policy->last_request?->shell(), 'C67 must remain in PublicUI.' );
g3s04_assert( in_array( Addon_Capabilities::PARTICIPATE, $worksheet_policy->last_request?->required_capabilities() ?? array(), true ), 'C51 lost participate.' );
g3s04_assert( in_array( Addon_Capabilities::PARTICIPATE, $submission_policy->last_request?->required_capabilities() ?? array(), true ), 'C67 lost participate.' );

$invalid_profiles                   = array();
$invalid_profiles[]                 = array_replace_recursive( $teacher_authorization, array( 'object' => array( 'action' => 'export_pdf' ) ) );
$invalid_profiles[]                 = array_replace_recursive( $learner_authorization, array( 'object' => array( 'relation' => 'self_target' ) ) );
$invalid_profiles[]                 = array_replace_recursive( $worksheet_authorization, array( 'object' => array( 'relation' => 'self_submission' ) ) );
$missing_teacher_context            = $teacher_authorization;
$missing_teacher_context['context'] = null;
$invalid_profiles[]                 = $missing_teacher_context;
foreach ( $invalid_profiles as $index => $invalid_profile ) {
	$policy = new G3S04_Profile_Policy( (string) $invalid_profile['object']['relation'], '2', 'submitted' );
	g3s04_assert(
		! ( new Export_Access_Guard( $policy ) )->allows( 42, array( 'authorization' => $invalid_profile ), Access_Policy_Request::SURFACE_REST, 'export.read', 'g3-s04-invalid-' . $index ),
		'Cross-profile descriptor was accepted at index ' . $index . '.'
	);
	g3s04_same( 0, $policy->calls, 'Invalid profile reached the common Policy.' );
}

$downgraded                   = $snapshot->value();
$downgraded['schema_version'] = Submission_Pdf_Request_Snapshot::VERSION;
$downgraded_json              = $canonical->encode( $downgraded );
try {
	Submission_Pdf_Request_Snapshot::restore( $downgraded_json, hash( 'sha256', $downgraded_json ), $canonical ); } catch ( InvalidArgumentException ) {
	$downgraded = null;
	}
	g3s04_same( null, $downgraded, 'C67 ownership must not be removable by a version downgrade.' );

	$upgraded                   = $teacher_snapshot->value();
	$upgraded['schema_version'] = Submission_Pdf_Request_Snapshot::OWN_VERSION;
	$upgraded_json              = $canonical->encode( $upgraded );
	try {
		Submission_Pdf_Request_Snapshot::restore( $upgraded_json, hash( 'sha256', $upgraded_json ), $canonical ); } catch ( InvalidArgumentException ) {
		$upgraded = null;
		}
		g3s04_same( null, $upgraded, 'C49 snapshot must not become self-authorized without ownership evidence.' );

		$guard_source = (string) file_get_contents( $addon_root . '/src/Reporting/Export/Application/Export_Access_Guard.php' );
		foreach ( array( 'matches_profile', "'context_staff'", "'self_target'", "'self_submission'", 'TYPE_WORKSHEET_REVISION', 'TYPE_ASSIGNMENT', 'TYPE_SUBMISSION' ) as $marker ) {
			g3s04_assert( str_contains( $guard_source, $marker ), 'S04 access profile marker is missing: ' . $marker );
		}
		g3s04_assert( ! str_contains( $guard_source, '$wpdb->prefix' ) && ! str_contains( $guard_source, 'wp_oj_ai_' ), 'S04 access guard introduced a forbidden table prefix.' );

		echo "G3-IMP-08-S04 profile freeze passed: C49/C51/C67 exact relation-action-type mapping, teacher/self separation, snapshot downgrade resistance, and C49/C67 render parity.\n";
