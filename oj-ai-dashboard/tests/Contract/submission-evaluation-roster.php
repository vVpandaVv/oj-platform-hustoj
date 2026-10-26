<?php
/**
 * Roster status projection and fail-closed pagination contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
require ABSPATH . 'vendor/autoload.php';

use OJAI\Dashboard\Submission\Assessment\Application\Read_Submission_Evaluation_Roster;
use OJAI\Dashboard\Submission\Assignment\Domain\Assignment_Roster_Snapshot;
use OJAI\Dashboard\Submission\Assignment\Port\Assignment_Roster_Port_Interface;
use OJAI\Dashboard\Submission\Assignment\Port\Learner_Display_Snapshot_Port_Interface;
use OJAI\Dashboard\Submission\Teacher\Application\Read_Teacher_Submissions;
use OJAI\Dashboard\Submission\Teacher\Port\Teacher_Submission_Query_Port_Interface;

/**
 * Assert the roster contract.
 *
 * @param bool   $ok Assertion result.
 * @param string $message Failure description.
 * @return void
 * @throws RuntimeException On a violated contract.
 */
function roster_assert( bool $ok, string $message ): void {
	if ( ! $ok ) {
		throw new RuntimeException( $message );
	}
}

$assignment_id = '01K5A000000000000000000003';
$source        = new class() implements Teacher_Submission_Query_Port_Interface {
	/**
	 * Fixture pages.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $pages = array();
	/**
	 * Read count.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Read fixture submission pages.
	 *
	 * @param string $assignment_id Assignment identifier.
	 * @param string $context_type Context type.
	 * @param string $context_id Context identifier.
	 * @param string $context_revision Context revision.
	 * @param string $cursor Cursor.
	 * @param int    $limit Page size.
	 * @return array<string,mixed>|null
	 */
	public function list_assignment_submissions( string $assignment_id, string $context_type, string $context_id, string $context_revision, string $cursor, int $limit ): ?array {
		++$this->calls;
		roster_assert( 100 === $limit, 'Every page must use the validated maximum limit.' );
		return 'group' === $context_type && 'group:test' === $context_id && 'context-r1' === $context_revision ? ( $this->pages[ $cursor ] ?? null ) : null;
	}
	/**
	 * Return no fixture detail.
	 *
	 * @param string $submission_id Submission identifier.
	 * @param string $context_type Context type.
	 * @param string $context_id Context identifier.
	 * @param string $context_revision Context revision.
	 * @return array<string,mixed>|null
	 */
	public function find_submission( string $submission_id, string $context_type, string $context_id, string $context_revision ): ?array {
		return null; }
	/**
	 * Return no fixture history.
	 *
	 * @param string $submission_id Submission identifier.
	 * @param string $context_type Context type.
	 * @param string $context_id Context identifier.
	 * @param string $context_revision Context revision.
	 * @param string $cursor Cursor.
	 * @param int    $limit Page size.
	 * @return array<string,mixed>|null
	 */
	public function list_submission_history( string $submission_id, string $context_type, string $context_id, string $context_revision, string $cursor, int $limit ): ?array {
		return null; }
	/**
	 * Return no fixture authorization snapshot.
	 *
	 * @param string $submission_id Submission identifier.
	 * @return array<string,mixed>|null
	 */
	public function authorization_snapshot( string $submission_id ): ?array {
		return null; }
};
$platform      = new class() implements Assignment_Roster_Port_Interface {
	/**
	 * Failure injection.
	 *
	 * @var bool
	 */
	public bool $fail = false;
	/**
	 * Read count.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Read a stable fixture roster.
	 *
	 * @param int    $actor_user_id Actor identifier.
	 * @param string $context_type Context type.
	 * @param string $context_id Context identifier.
	 * @param string $expected_context_revision Context revision.
	 * @return Assignment_Roster_Snapshot
	 * @throws RuntimeException For injected failures or invalid scope.
	 */
	public function collect( int $actor_user_id, string $context_type, string $context_id, string $expected_context_revision ): Assignment_Roster_Snapshot {
		++$this->calls;
		roster_assert( 42 === $actor_user_id && 'group:test' === $context_id, 'Roster must receive authenticated actor and exact context.' );
		if ( $this->fail ) {
			throw new RuntimeException( 'Private platform failure.' ); }
		return new Assignment_Roster_Snapshot(
			$context_type,
			$context_id,
			$expected_context_revision,
			'roster-r1',
			array_map(
				static fn ( $id ) => array(
					'learner_user_id'     => $id,
					'membership_revision' => 'member-r1',
				),
				array( 1, 2, 3, 6 )
			)
		);
	}
};
$displays      = new class() implements Learner_Display_Snapshot_Port_Interface {
	/**
	 * Read a fixture display name.
	 *
	 * @param int $learner_user_id Learner identifier.
	 * @return array<string,string>
	 */
	public function snapshot( int $learner_user_id ): array {
		return array( 'display_name' => 'Student ' . $learner_user_id ); }
};
$target        = static fn ( int $id, string $status, ?string $official = null, string $state = 'active' ): array => array(
	'target'     => array(
		'id'                  => 'target-' . $id,
		'learner_user_id'     => $id,
		'display_name'        => 'Student ' . $id,
		'state'               => $state,
		'membership_revision' => 'private-membership',
	),
	'status'     => $status,
	'submission' => 'not_started' === $status ? null : array(
		'id'                   => 'submission-' . $id,
		'official_revision_id' => $official,
		'submitted_at_utc'     => $official ? '2026-09-14 01:00:00' : null,
		'teacher_memo'         => 'secret',
		'answer'               => 'private draft',
	),
);
$source->pages = array(
	''      => array(
		'assignment'  => array( 'id' => $assignment_id ),
		'items'       => array( $target( 1, 'submitted', 'r1' ), $target( 2, 'draft' ), $target( 3, 'not_started' ) ),
		'next_cursor' => 'page2',
	),
	'page2' => array(
		'assignment'  => array( 'id' => $assignment_id ),
		'items'       => array( $target( 4, 'submitted', 'r4', 'revoked' ), $target( 5, 'auto_submitted', 'r5' ) ),
		'next_cursor' => '',
	),
);
$reader        = new Read_Submission_Evaluation_Roster( new Read_Teacher_Submissions( $source ), $platform, $displays );
$read          = static fn () => $reader->read( 42, $assignment_id, 'group', 'group:test', 'context-r1' );
$rows          = $read()['items'];
roster_assert( 6 === count( $rows ) && 2 === $source->calls, 'Must include all pages plus newly joined students.' );
roster_assert( $rows[0]['current_member'] && ! $rows[4]['current_member'], 'Former targets must remain explicitly distinguishable.' );
roster_assert( ! $rows[3]['assigned'] && 'revoked' === $rows[3]['target_state'], 'Revoked targets cannot be marked missing submissions.' );
roster_assert( ! $rows[5]['assigned'] && $rows[5]['current_member'] && null === $rows[5]['submission'], 'New members must not be treated as assigned.' );
roster_assert( 'draft' === $rows[1]['status'] && null === $rows[1]['submission']['official_revision_id'], 'Draft remains unsubmitted.' );
roster_assert( ! str_contains( json_encode( $rows ), 'secret' ) && ! str_contains( json_encode( $rows ), 'private' ), 'Roster must not expose answers, notes or membership metadata.' );
$fails = static function ( callable $action ): void {
	try {
		$action();
	} catch ( Throwable ) {
		return; }
	throw new RuntimeException( 'Partial or unauthorized roster must fail.' );
};
$fails( static fn () => $reader->read( 42, $assignment_id, 'group', 'other', 'context-r1' ) );
$platform->fail = true;
$fails( $read );
$platform->fail                        = false;
$source->pages['page2']['next_cursor'] = 'page2';
$fails( $read );
$source->pages['page2']['next_cursor'] = '';
$source->pages['page2']['items'][]     = $target( 1, 'submitted', 'r1' );
$fails( $read );
echo "PASS submission roster: full membership, all pages, removed and new members, no answer leakage, scope and partial-read failures\n";

/** WordPress error stand-in for the real REST permission handoff. */
class WP_Error {
	/**
	 * Store a REST problem.
	 *
	 * @param string              $code Error code.
	 * @param string              $message Safe description.
	 * @param array<string,mixed> $data Error metadata.
	 */
	public function __construct( public string $code, public string $message, public array $data ) {}
}
/** WordPress response stand-in. */
class WP_REST_Response {
	/**
	 * Store a response envelope.
	 *
	 * @param array<string,mixed> $data Envelope.
	 * @param int                 $status HTTP status.
	 */
	public function __construct( public array $data, public int $status ) {}
}
$actor                         = new class() implements \OJAI\Dashboard\Rest\Port\Current_Actor_Port_Interface {
	/** {@inheritDoc} */
	public function current_actor_user_id(): int {
		return 42; }
};
$nonce                         = new class() implements \OJAI\Dashboard\Rest\Port\Rest_Nonce_Verifier_Port_Interface {
	/**
	 * Verify the fixture nonce.
	 *
	 * @param string $nonce Nonce.
	 * @return bool
	 */
	public function verify( string $nonce ): bool {
		return 'fixture-nonce' === $nonce; }
};
$policy                        = new class() implements \OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface {
	/**
	 * Denial injection.
	 *
	 * @var bool
	 */
	public bool $deny = false;
	/**
	 * Context drift injection.
	 *
	 * @var bool
	 */
	public bool $drift = false;
	/**
	 * Authorization requests.
	 *
	 * @var array<object>
	 */
	public array $calls = array();
	/**
	 * Authorize or deny the fixture request.
	 *
	 * @param \OJAI\Dashboard\Security\Policy\Access_Policy_Request $request Request.
	 * @return \OJAI\Dashboard\Security\Policy\Access_Policy_Decision
	 */
	public function authorize( \OJAI\Dashboard\Security\Policy\Access_Policy_Request $request ): \OJAI\Dashboard\Security\Policy\Access_Policy_Decision {
		$this->calls[] = $request;
		if ( $this->deny ) {
			return \OJAI\Dashboard\Security\Policy\Access_Policy_Decision::denied( 403, 'addon_capability_denied', $request->correlation_id() ); }
		$context = $request->context();
		return \OJAI\Dashboard\Security\Policy\Access_Policy_Decision::allowed( $request->correlation_id(), \OJAI\Dashboard\Security\Policy\Context_Authorization_Result::allowed( $context->type(), $context->id(), 'active', $this->drift ? 'other-revision' : 'context-r1', 'context_staff' ) );
	}
};
$correlations                  = new \OJAI\Dashboard\Rest\Correlation_Id_Factory();
$envelopes                     = new \OJAI\Dashboard\Rest\Rest_Envelope_Factory();
$permissions                   = new \OJAI\Dashboard\Rest\Rest_Permission_Handoff( new \OJAI\Dashboard\Rest\Infrastructure\WordPress_Rest_Request_Adapter( $actor ), new \OJAI\Dashboard\Rest\Rest_Request_Authorizer( $policy, $nonce, $correlations ), $envelopes, $correlations );
$unused_evaluations            = ( new ReflectionClass( \OJAI\Dashboard\Submission\Assessment\Application\Read_Submission_Evaluations::class ) )->newInstanceWithoutConstructor();
$controller                    = new \OJAI\Dashboard\Submission\Assessment\Presentation\Submission_Evaluation_Query_Rest_Controller( $permissions, $unused_evaluations, new Read_Teacher_Submissions( $source ), $envelopes, $correlations, $reader, new \OJAI\Dashboard\Submission\Teacher\Infrastructure\WordPress_Teacher_Learner_Labels() );
$GLOBALS['roster_label_reads'] = 0;
/**
 * Read authorized account fields through the public WordPress API.
 *
 * @param int $id Account identifier.
 * @return object
 */
function get_userdata( int $id ): object {
	++$GLOBALS['roster_label_reads'];
	return (object) array(
		'display_name' => 'Display ' . $id,
		'user_login'   => 'login' . $id,
	);
}
/**
 * Read the platform management-name field.
 *
 * @param int    $id Account identifier.
 * @param string $key Metadata key.
 * @param bool   $single Single value.
 * @return string
 */
function get_user_meta( int $id, string $key, bool $single ): string {
	roster_assert( 'nickname' === $key && $single, 'Only management names may be requested.' );
	return 'Management ' . $id;
}
$request = new class( $assignment_id ) {
	/**
	 * Query parameters.
	 *
	 * @var array<string,mixed>
	 */
	public array $query;
	/**
	 * Create a scoped GET request.
	 *
	 * @param string $assignment Assignment identifier.
	 */
	public function __construct( string $assignment ) {
		$this->query = array(
			'context_type'     => 'group',
			'context_id'       => 'group:test',
			'context_revision' => 'context-r1',
			'assignment_id'    => $assignment,
		); }
	/**
	 * Read query parameters.
	 *
	 * @return array<string,mixed>
	 */
	public function get_query_params(): array {
		return $this->query; }
	/**
	 * Read method.
	 *
	 * @return string
	 */
	public function get_method(): string {
		return 'GET'; }
	/**
	 * Read the fixture nonce.
	 *
	 * @param string $name Header name.
	 * @return string
	 */
	public function get_header( string $name ): string {
		return 'x-wp-nonce' === strtolower( $name ) ? 'fixture-nonce' : ''; }
};
array_pop( $source->pages['page2']['items'] );
roster_assert( true === $controller->permissions_roster( $request ), 'Teacher roster permission failed.' );
$authorized = $policy->calls[0];
roster_assert( $authorized->object()->id() === $assignment_id && 'view_submissions' === $authorized->object()->action(), 'Roster must authorize the exact assignment object.' );
roster_assert( $authorized->required_capabilities() === array( \OJAI\Dashboard\Security\Addon_Capabilities::ASSESS_SUBMISSIONS ), 'Roster must require teacher assessment capability.' );
$response = $controller->roster( $request );
roster_assert( $response instanceof WP_REST_Response && 200 === $response->status && false === $response->data['meta']['student_visible'], 'Authorized roster must be staff-only and read-only.' );
$labels = $response->data['data']['items'][0]['learner']['labels'];
roster_assert( 'login1' === $labels['login'] && 'Management 1' === $labels['management_name'], 'Authorized roster must include current teacher labels.' );
$label_reads = $GLOBALS['roster_label_reads'];
roster_assert( $controller->roster( $request ) instanceof WP_Error, 'Permission grants cannot be replayed.' );
roster_assert( $label_reads === $GLOBALS['roster_label_reads'], 'Missing grants must not read account labels.' );
$request->query['actor_user_id'] = 999;
roster_assert( 422 === $controller->permissions_roster( $request )->data['status'], 'Client actor injection must fail.' );
unset( $request->query['actor_user_id'] );
$policy->deny = true;
roster_assert( 403 === $controller->permissions_roster( $request )->data['status'], 'Unauthorized roster must be denied.' );
$policy->deny  = false;
$policy->drift = true;
$before        = $source->calls;
roster_assert( true === $controller->permissions_roster( $request ), 'Fixture policy grant failed.' );
roster_assert( $controller->roster( $request ) instanceof WP_Error && $before === $source->calls, 'Granted context drift must stop before data reads.' );
roster_assert( $label_reads === $GLOBALS['roster_label_reads'], 'Denied or drifted requests must not read account labels.' );
$policy->drift  = false;
$platform->fail = true;
$controller->permissions_roster( $request );
$error = $controller->roster( $request );
roster_assert( $error instanceof WP_Error && 503 === $error->data['status'] && ! str_contains( $error->message, 'Private' ), 'Platform failure must be private and must not return partial counts.' );
echo "PASS roster REST: exact object and teacher capability, grant consumption, actor injection, denial, context drift, private errors\n";
