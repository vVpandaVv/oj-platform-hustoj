<?php
/**
 * G4-IMP-01-S05 C29/C30 idempotency and authorization freeze contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Infrastructure\WordPress_Current_Actor;
use OJAI\Dashboard\Rest\Infrastructure\WordPress_Rest_Nonce_Verifier;
use OJAI\Dashboard\Rest\Infrastructure\WordPress_Rest_Request_Adapter;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Rest\Rest_Request_Authorizer;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Context_Scope;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Application\Enqueue_Assignment_Evaluations;
use OJAI\Dashboard\Submission\Assessment\Application\Enqueue_Assignment_Evaluations_Command;
use OJAI\Dashboard\Submission\Assessment\Application\Enqueue_Submission_Evaluation_Command;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Source;
use OJAI\Dashboard\Submission\Assessment\Presentation\Assignment_Evaluation_Rest_Controller;
use OJAI\Dashboard\Submission\Assessment\Presentation\Submission_Evaluation_Rest_Controller;

require __DIR__ . '/assignment-evaluation-enqueue-g4-imp-01-s04.php';

/**
 * G4 s05 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s05_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G4 s05 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s05_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G4 s05 problem.
 *
 * @param mixed  $problem Problem.
 * @param int    $status Status.
 * @param string $message Message.
 * @return WP_Error
 */
function g4_s05_problem( $problem, int $status, string $message ): WP_Error {
	g4_s05_assert( $problem instanceof WP_Error, $message . ' A WP_Error was required.' );
	g4_s05_same( $status, $problem->get_error_data()['status'] ?? null, $message . ' HTTP status drifted.' );
	return $problem;
}

/**
 * G4 s05 permissions.
 *
 * @param Access_Policy_Port_Interface $policy Policy.
 * @return Rest_Permission_Handoff
 */
function g4_s05_permissions( Access_Policy_Port_Interface $policy ): Rest_Permission_Handoff {
	$correlations = new Correlation_Id_Factory();
	return new Rest_Permission_Handoff(
		new WordPress_Rest_Request_Adapter( new WordPress_Current_Actor() ),
		new Rest_Request_Authorizer( $policy, new WordPress_Rest_Nonce_Verifier(), $correlations ),
		new Rest_Envelope_Factory(),
		$correlations
	);
}

/**
 * Allows two current teachers only for one exact C29 scope.
 */
final class G4_S05_Submission_Policy implements Access_Policy_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Initialize g4 s05 submission policy dependencies and state.
	 *
	 * @param array<int,int> $actors Actors.
	 * @param string         $submission_id Submission public identifier.
	 * @param string         $context_id Context id.
	 * @param string         $context_revision Context revision.
	 */
	public function __construct(
		private array $actors,
		private string $submission_id,
		private string $context_id,
		private string $context_revision
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
		$context = $request->context();
		$object  = $request->object();
		if (
			! $request->request_integrity_verified()
			|| ! in_array( $request->actor_user_id(), $this->actors, true )
			|| Submission_Evaluation_Rest_Controller::ACTION_ENQUEUE !== $request->action_key()
			|| array( Addon_Capabilities::ASSESS_SUBMISSIONS ) !== $request->required_capabilities()
			|| ! $context instanceof Context_Scope
			|| 'class' !== $context->type()
			|| $this->context_id !== $context->id()
			|| Context_Scope::OPERATION_EVALUATE !== $context->operation()
			|| $this->context_revision !== $context->expected_revision()
			|| ! $object instanceof Object_Scope
			|| Object_Scope::TYPE_SUBMISSION !== $object->type()
			|| $this->submission_id !== $object->id()
			|| 'evaluate' !== $object->action()
		) {
			return Access_Policy_Decision::denied( 403, 'submission_evaluation_scope_denied', $request->correlation_id() );
		}
		return Access_Policy_Decision::allowed(
			$request->correlation_id(),
			Context_Authorization_Result::allowed( 'class', $this->context_id, 'active', $this->context_revision, 'educator' ),
			Object_Authorization_Result::allowed( '3', 'submitted', 'context_staff' )
		);
	}
}

g4_s05_assert( isset( $source, $canonical, $builder, $page ), 'S03/S04 fixtures did not load.' );
g4_s05_assert( $source instanceof Submission_Evaluation_Source, 'S05 requires the exact C29 source fixture.' );

$submission_query    = array(
	'context_type'     => $source->context_type(),
	'context_id'       => $source->context_id(),
	'context_revision' => $source->context_revision(),
);
$submission_body     = g4_s03_body( 'command-g4-s05-negative', $source->official_revision_id(), $source->source_hash() );
$s05_ids             = g4_s03_generator( 16000 );
$other_submission_id = $s05_ids->create()->value();
$other_assignment_id = $s05_ids->create()->value();

// C29 permission failures must never reach source, routing, queue, or storage.
$negative_database = new G4_S03_Database();
[ $negative_enqueue, $negative_sources, $negative_routing, $negative_queue ] = g4_s03_application(
	$negative_database,
	$source,
	$canonical,
	$builder,
	16100
);
$negative_correlations = new Correlation_Id_Factory();
$negative_envelopes    = new Rest_Envelope_Factory();
$negative_controller   = new Submission_Evaluation_Rest_Controller(
	new Rest_Permission_Handoff(
		new WordPress_Rest_Request_Adapter( new WordPress_Current_Actor() ),
		new Rest_Request_Authorizer(
			new G4_S03_Policy( $source->submission_id(), $source->context_id(), $source->context_revision() ),
			new WordPress_Rest_Nonce_Verifier(),
			$negative_correlations
		),
		$negative_envelopes,
		$negative_correlations
	),
	$negative_enqueue,
	$canonical,
	$negative_envelopes,
	$negative_correlations
);

$invalid_nonce = new G4_S03_Request(
	array(
		'X-WP-Nonce'      => 'invalid-nonce',
		'Idempotency-Key' => 'freeze-negative-key',
	),
	$submission_body,
	$source->submission_id(),
	$submission_query
);
g4_s05_problem( $negative_controller->permissions_evaluate( $invalid_nonce ), 403, 'C29 invalid nonce must fail closed.' );

$missing_key = new G4_S03_Request(
	array( 'X-WP-Nonce' => 'valid-g4-s03-nonce' ),
	$submission_body,
	$source->submission_id(),
	$submission_query
);
g4_s05_problem( $negative_controller->permissions_evaluate( $missing_key ), 422, 'C29 missing Idempotency-Key must fail closed.' );

$invalid_key = new G4_S03_Request(
	array(
		'X-WP-Nonce'      => 'valid-g4-s03-nonce',
		'Idempotency-Key' => 'short',
	),
	$submission_body,
	$source->submission_id(),
	$submission_query
);
g4_s05_problem( $negative_controller->permissions_evaluate( $invalid_key ), 422, 'C29 invalid Idempotency-Key must fail closed.' );

$GLOBALS['g4_s03_actor'] = 99;
$cross_user              = new G4_S03_Request(
	array(
		'X-WP-Nonce'      => 'valid-g4-s03-nonce',
		'Idempotency-Key' => 'freeze-cross-user-key',
	),
	$submission_body,
	$source->submission_id(),
	$submission_query
);
g4_s05_problem( $negative_controller->permissions_evaluate( $cross_user ), 403, 'C29 cross-user request must be denied.' );
$GLOBALS['g4_s03_actor'] = 42;

$context_drift_query                     = $submission_query;
$context_drift_query['context_revision'] = $source->context_revision() . '-changed';
$context_drift                           = new G4_S03_Request(
	array(
		'X-WP-Nonce'      => 'valid-g4-s03-nonce',
		'Idempotency-Key' => 'freeze-context-drift-key',
	),
	$submission_body,
	$source->submission_id(),
	$context_drift_query
);
g4_s05_problem( $negative_controller->permissions_evaluate( $context_drift ), 403, 'C29 Context revision drift must be denied.' );

$direct_url = new G4_S03_Request(
	array(
		'X-WP-Nonce'      => 'valid-g4-s03-nonce',
		'Idempotency-Key' => 'freeze-direct-url-key',
	),
	$submission_body,
	$other_submission_id,
	$submission_query
);
g4_s05_problem( $negative_controller->permissions_evaluate( $direct_url ), 403, 'C29 direct URL object substitution must be denied.' );
g4_s05_same( 0, $negative_sources->calls, 'C29 denied requests must not read submission source.' );
g4_s05_same( 0, $negative_routing->calls, 'C29 denied requests must not inspect AI routing.' );
g4_s05_same( 0, $negative_queue->calls, 'C29 denied requests must not wake the queue.' );
g4_s05_same( 0, array_sum( $negative_database->counts() ), 'C29 denied requests must add zero rows.' );

// The permission grant is bound to the exact request object and consumed once.
$grant_request = new G4_S03_Request(
	array(
		'X-WP-Nonce'      => 'valid-g4-s03-nonce',
		'Idempotency-Key' => 'freeze-one-use-key',
	),
	g4_s03_body( 'command-g4-s05-one-use', $source->official_revision_id(), $source->source_hash() ),
	$source->submission_id(),
	$submission_query
);
$grant_clone   = new G4_S03_Request(
	array(
		'X-WP-Nonce'      => 'valid-g4-s03-nonce',
		'Idempotency-Key' => 'freeze-one-use-key',
	),
	g4_s03_body( 'command-g4-s05-one-use', $source->official_revision_id(), $source->source_hash() ),
	$source->submission_id(),
	$submission_query
);
g4_s05_same( true, $negative_controller->permissions_evaluate( $grant_request ), 'C29 exact request must receive a permission grant.' );
g4_s05_problem( $negative_controller->evaluate( $grant_clone ), 503, 'C29 grant must not transfer to an equivalent request object.' );
g4_s05_same( 0, $negative_sources->calls, 'C29 stolen grant attempt must not read source.' );
$grant_success = $negative_controller->evaluate( $grant_request );
g4_s05_assert( $grant_success instanceof WP_REST_Response, 'C29 granted request must enqueue successfully.' );
g4_s05_same( 202, $grant_success->get_status(), 'C29 granted request status drifted.' );
$grant_counts = $negative_database->counts();
g4_s05_problem( $negative_controller->evaluate( $grant_request ), 503, 'C29 grant must be one-use.' );
g4_s05_same( $grant_counts, $negative_database->counts(), 'C29 reused grant must add zero rows.' );

// A state change after permission must be caught by the application CAS.
$race_database = new G4_S03_Database();
[ $race_enqueue, $race_sources, $race_routing, $race_queue ] = g4_s03_application(
	$race_database,
	$source,
	$canonical,
	$builder,
	16200
);
$race_correlations = new Correlation_Id_Factory();
$race_envelopes    = new Rest_Envelope_Factory();
$race_controller   = new Submission_Evaluation_Rest_Controller(
	new Rest_Permission_Handoff(
		new WordPress_Rest_Request_Adapter( new WordPress_Current_Actor() ),
		new Rest_Request_Authorizer(
			new G4_S03_Policy( $source->submission_id(), $source->context_id(), $source->context_revision() ),
			new WordPress_Rest_Nonce_Verifier(),
			$race_correlations
		),
		$race_envelopes,
		$race_correlations
	),
	$race_enqueue,
	$canonical,
	$race_envelopes,
	$race_correlations
);
$race_request      = new G4_S03_Request(
	array(
		'X-WP-Nonce'      => 'valid-g4-s03-nonce',
		'Idempotency-Key' => 'freeze-source-race-key',
	),
	g4_s03_body( 'command-g4-s05-source-race', $source->official_revision_id(), $source->source_hash() ),
	$source->submission_id(),
	$submission_query
);
g4_s05_same( true, $race_controller->permissions_evaluate( $race_request ), 'C29 race request must pass the initial policy snapshot.' );
$race_sources->source = new Submission_Evaluation_Source(
	$source->submission_id(),
	$source->lock_version() + 1,
	$source->state(),
	$source->context_type(),
	$source->context_id(),
	$source->context_revision(),
	$source->official_revision(),
	$source->publication(),
	$source->configuration(),
	$source->artifact_representations()
);
g4_s05_problem( $race_controller->evaluate( $race_request ), 409, 'C29 post-permission source race must fail with SOURCE_STALE.' );
g4_s05_same( 1, $race_sources->calls, 'C29 source race must re-read the official source once.' );
g4_s05_same( 0, $race_routing->calls, 'C29 source race must fail before routing.' );
g4_s05_same( 0, $race_queue->calls, 'C29 source race must not wake the queue.' );
g4_s05_same( 0, array_sum( $race_database->counts() ), 'C29 source race must add zero rows.' );

// C30 permission failures have the same pre-application isolation guarantees.
$assignment_page         = $page;
$assignment_query        = array(
	'context_type'     => (string) $assignment_page['context_type'],
	'context_id'       => (string) $assignment_page['context_id'],
	'context_revision' => (string) $assignment_page['context_revision'],
);
$assignment_ids          = g4_s03_generator( 16300 );
$assignment_sources      = new G4_S04_Source_Port( $assignment_page );
$assignment_enqueuer     = new G4_S04_Submission_Enqueuer( $submission_ids[0], $submission_ids[2], $publication_id, $assignment_ids );
$assignment_receipts     = new G4_S04_Receipt_Port();
$assignment_application  = new Enqueue_Assignment_Evaluations(
	$assignment_sources,
	$assignment_enqueuer,
	$assignment_receipts,
	$assignment_ids,
	$canonical,
	static fn (): string => '2026-08-23 02:00:00'
);
$assignment_correlations = new Correlation_Id_Factory();
$assignment_envelopes    = new Rest_Envelope_Factory();
$assignment_controller   = new Assignment_Evaluation_Rest_Controller(
	new Rest_Permission_Handoff(
		new WordPress_Rest_Request_Adapter( new WordPress_Current_Actor() ),
		new Rest_Request_Authorizer(
			new G4_S04_Policy(
				(string) $assignment_page['assignment_id'],
				(string) $assignment_page['context_id'],
				(string) $assignment_page['context_revision']
			),
			new WordPress_Rest_Nonce_Verifier(),
			$assignment_correlations
		),
		$assignment_envelopes,
		$assignment_correlations
	),
	$assignment_application,
	$canonical,
	$assignment_envelopes,
	$assignment_correlations
);
$assignment_body         = g4_s04_body( 'command-g4-s05-assignment-negative', (string) $assignment_page['source_cutoff_utc'] );

$assignment_invalid_nonce = new G4_S04_Request(
	array(
		'X-WP-Nonce'      => 'invalid-nonce',
		'Idempotency-Key' => 'freeze-assignment-nonce',
	),
	$assignment_body,
	(string) $assignment_page['assignment_id'],
	$assignment_query
);
g4_s05_problem( $assignment_controller->permissions_evaluate( $assignment_invalid_nonce ), 403, 'C30 invalid nonce must fail closed.' );

$assignment_missing_key = new G4_S04_Request(
	array( 'X-WP-Nonce' => 'valid-g4-s03-nonce' ),
	$assignment_body,
	(string) $assignment_page['assignment_id'],
	$assignment_query
);
g4_s05_problem( $assignment_controller->permissions_evaluate( $assignment_missing_key ), 422, 'C30 missing Idempotency-Key must fail closed.' );

$GLOBALS['g4_s03_actor'] = 99;
$assignment_cross_user   = new G4_S04_Request(
	array(
		'X-WP-Nonce'      => 'valid-g4-s03-nonce',
		'Idempotency-Key' => 'freeze-assignment-user',
	),
	$assignment_body,
	(string) $assignment_page['assignment_id'],
	$assignment_query
);
g4_s05_problem( $assignment_controller->permissions_evaluate( $assignment_cross_user ), 403, 'C30 cross-user request must be denied.' );
$GLOBALS['g4_s03_actor'] = 42;

$assignment_drift_query                      = $assignment_query;
$assignment_drift_query['context_revision'] .= '-changed';
$assignment_context_drift                    = new G4_S04_Request(
	array(
		'X-WP-Nonce'      => 'valid-g4-s03-nonce',
		'Idempotency-Key' => 'freeze-assignment-context',
	),
	$assignment_body,
	(string) $assignment_page['assignment_id'],
	$assignment_drift_query
);
g4_s05_problem( $assignment_controller->permissions_evaluate( $assignment_context_drift ), 403, 'C30 Context revision drift must be denied.' );

$assignment_direct_url = new G4_S04_Request(
	array(
		'X-WP-Nonce'      => 'valid-g4-s03-nonce',
		'Idempotency-Key' => 'freeze-assignment-url',
	),
	$assignment_body,
	$other_assignment_id,
	$assignment_query
);
g4_s05_problem( $assignment_controller->permissions_evaluate( $assignment_direct_url ), 403, 'C30 direct URL object substitution must be denied.' );
g4_s05_same( 0, $assignment_sources->calls, 'C30 denied requests must not read assignment source.' );
g4_s05_same( 0, count( $assignment_enqueuer->commands ), 'C30 denied requests must not enqueue child evaluations.' );
g4_s05_same( 0, count( $assignment_receipts->rows ), 'C30 denied requests must not store a receipt.' );

$assignment_race_ids          = g4_s03_generator( 16400 );
$assignment_race_sources      = new G4_S04_Source_Port( $assignment_page );
$assignment_race_enqueuer     = new G4_S04_Submission_Enqueuer( $submission_ids[0], $submission_ids[2], $publication_id, $assignment_race_ids );
$assignment_race_receipts     = new G4_S04_Receipt_Port();
$assignment_race_application  = new Enqueue_Assignment_Evaluations(
	$assignment_race_sources,
	$assignment_race_enqueuer,
	$assignment_race_receipts,
	$assignment_race_ids,
	$canonical,
	static fn (): string => '2026-08-23 02:00:00'
);
$assignment_race_correlations = new Correlation_Id_Factory();
$assignment_race_envelopes    = new Rest_Envelope_Factory();
$assignment_race_controller   = new Assignment_Evaluation_Rest_Controller(
	new Rest_Permission_Handoff(
		new WordPress_Rest_Request_Adapter( new WordPress_Current_Actor() ),
		new Rest_Request_Authorizer(
			new G4_S04_Policy(
				(string) $assignment_page['assignment_id'],
				(string) $assignment_page['context_id'],
				(string) $assignment_page['context_revision']
			),
			new WordPress_Rest_Nonce_Verifier(),
			$assignment_race_correlations
		),
		$assignment_race_envelopes,
		$assignment_race_correlations
	),
	$assignment_race_application,
	$canonical,
	$assignment_race_envelopes,
	$assignment_race_correlations
);
$assignment_race_request      = new G4_S04_Request(
	array(
		'X-WP-Nonce'      => 'valid-g4-s03-nonce',
		'Idempotency-Key' => 'freeze-assignment-race',
	),
	g4_s04_body( 'command-g4-s05-assignment-race', (string) $assignment_page['source_cutoff_utc'] ),
	(string) $assignment_page['assignment_id'],
	$assignment_query
);
g4_s05_same( true, $assignment_race_controller->permissions_evaluate( $assignment_race_request ), 'C30 race request must pass the initial policy snapshot.' );
$assignment_race_sources->page['state'] = 'closed';
g4_s05_problem( $assignment_race_controller->evaluate( $assignment_race_request ), 409, 'C30 post-permission state race must fail with SOURCE_STALE.' );
g4_s05_same( 1, $assignment_race_sources->calls, 'C30 state race must re-read assignment source once.' );
g4_s05_same( 0, count( $assignment_race_enqueuer->commands ), 'C30 state race must fail before child enqueue.' );
g4_s05_same( 0, count( $assignment_race_receipts->rows ), 'C30 state race must not store a receipt.' );

// Actor-scoped storage keys isolate receipts while actor-neutral request digests preserve semantic comparison.
$shared_fingerprint    = hash( 'sha256', 'g4-s05-shared-fingerprint' );
$submission_command_42 = new Enqueue_Submission_Evaluation_Command(
	42,
	$source->submission_id(),
	'command-g4-s05-storage',
	$source->official_revision_id(),
	$source->source_hash(),
	$shared_fingerprint,
	'correlation-g4-s05-storage',
	$source->context_type(),
	$source->context_id(),
	$source->context_revision(),
	'3',
	'submitted',
	'context_staff',
	$canonical
);
$submission_command_43 = new Enqueue_Submission_Evaluation_Command(
	43,
	$source->submission_id(),
	'command-g4-s05-storage',
	$source->official_revision_id(),
	$source->source_hash(),
	$shared_fingerprint,
	'correlation-g4-s05-storage',
	$source->context_type(),
	$source->context_id(),
	$source->context_revision(),
	'3',
	'submitted',
	'context_staff',
	$canonical
);
g4_s05_same( $submission_command_42->request_digest(), $submission_command_43->request_digest(), 'C29 request digest must not depend on actor identity.' );
g4_s05_assert( $submission_command_42->idempotency_storage_key() !== $submission_command_43->idempotency_storage_key(), 'C29 storage key must isolate actors.' );

$assignment_command_42 = new Enqueue_Assignment_Evaluations_Command(
	42,
	(string) $assignment_page['assignment_id'],
	'command-g4-s05-storage',
	'open',
	7,
	(string) $assignment_page['source_cutoff_utc'],
	'',
	50,
	$shared_fingerprint,
	'correlation-g4-s05-storage',
	(string) $assignment_page['context_type'],
	(string) $assignment_page['context_id'],
	(string) $assignment_page['context_revision'],
	'7',
	'open',
	'context_staff',
	$canonical
);
$assignment_command_43 = new Enqueue_Assignment_Evaluations_Command(
	43,
	(string) $assignment_page['assignment_id'],
	'command-g4-s05-storage',
	'open',
	7,
	(string) $assignment_page['source_cutoff_utc'],
	'',
	50,
	$shared_fingerprint,
	'correlation-g4-s05-storage',
	(string) $assignment_page['context_type'],
	(string) $assignment_page['context_id'],
	(string) $assignment_page['context_revision'],
	'7',
	'open',
	'context_staff',
	$canonical
);
g4_s05_same( $assignment_command_42->request_digest(), $assignment_command_43->request_digest(), 'C30 request digest must not depend on actor identity.' );
g4_s05_assert( $assignment_command_42->idempotency_storage_key() !== $assignment_command_43->idempotency_storage_key(), 'C30 storage key must isolate actors.' );
g4_s05_assert( $submission_command_42->idempotency_storage_key() !== $assignment_command_42->idempotency_storage_key(), 'C29 and C30 storage key namespaces must remain isolated.' );

// Two currently authorized teachers may share one semantic evaluation, never one command receipt.
$semantic_database = new G4_S03_Database();
[ $semantic_enqueue, $semantic_sources, $semantic_routing, $semantic_queue ] = g4_s03_application(
	$semantic_database,
	$source,
	$canonical,
	$builder,
	16500
);
$semantic_policy         = new G4_S05_Submission_Policy(
	array( 42, 43 ),
	$source->submission_id(),
	$source->context_id(),
	$source->context_revision()
);
$semantic_correlations   = new Correlation_Id_Factory();
$semantic_envelopes      = new Rest_Envelope_Factory();
$semantic_controller     = new Submission_Evaluation_Rest_Controller(
	new Rest_Permission_Handoff(
		new WordPress_Rest_Request_Adapter( new WordPress_Current_Actor() ),
		new Rest_Request_Authorizer( $semantic_policy, new WordPress_Rest_Nonce_Verifier(), $semantic_correlations ),
		$semantic_envelopes,
		$semantic_correlations
	),
	$semantic_enqueue,
	$canonical,
	$semantic_envelopes,
	$semantic_correlations
);
$GLOBALS['g4_s03_actor'] = 42;
$teacher_one_request     = new G4_S03_Request(
	array(
		'X-WP-Nonce'      => 'valid-g4-s03-nonce',
		'Idempotency-Key' => 'freeze-semantic-shared',
	),
	g4_s03_body( 'command-g4-s05-teacher-one', $source->official_revision_id(), $source->source_hash() ),
	$source->submission_id(),
	$submission_query
);
g4_s05_same( true, $semantic_controller->permissions_evaluate( $teacher_one_request ), 'First teacher must be currently authorized.' );
$teacher_one_response = $semantic_controller->evaluate( $teacher_one_request );
g4_s05_assert( $teacher_one_response instanceof WP_REST_Response, 'First teacher enqueue must succeed.' );
$teacher_one_data = $teacher_one_response->get_data();
g4_s05_same( false, $teacher_one_data['meta']['idempotent_replay'] ?? null, 'First teacher must create the semantic evaluation.' );

$GLOBALS['g4_s03_actor'] = 43;
$teacher_two_request     = new G4_S03_Request(
	array(
		'X-WP-Nonce'      => 'valid-g4-s03-nonce',
		'Idempotency-Key' => 'freeze-semantic-shared',
	),
	g4_s03_body( 'command-g4-s05-teacher-two', $source->official_revision_id(), $source->source_hash() ),
	$source->submission_id(),
	$submission_query
);
g4_s05_same( true, $semantic_controller->permissions_evaluate( $teacher_two_request ), 'Second teacher must be independently and currently authorized.' );
$teacher_two_response = $semantic_controller->evaluate( $teacher_two_request );
g4_s05_assert( $teacher_two_response instanceof WP_REST_Response, 'Second teacher semantic enqueue must succeed.' );
$teacher_two_data = $teacher_two_response->get_data();
g4_s05_same( true, $teacher_two_data['meta']['idempotent_replay'] ?? null, 'Second teacher must receive a semantic replay.' );
g4_s05_same(
	$teacher_one_data['data']['evaluation']['id'] ?? null,
	$teacher_two_data['data']['evaluation']['id'] ?? null,
	'Authorized teachers must converge on one semantic evaluation root.'
);
g4_s05_same(
	array(
		'oj_ai_submission_evaluations'       => 1,
		'oj_ai_submission_evaluation_inputs' => 1,
		'oj_ai_evaluation_runs'              => 1,
		'oj_ai_audit_events'                 => 1,
		'oj_ai_outbox_events'                => 2,
	),
	$semantic_database->counts(),
	'Cross-teacher semantic replay must add only the second actor-scoped command receipt.'
);
g4_s05_same( 2, $semantic_sources->calls, 'Cross-teacher semantic comparison must read the current official source for each actor.' );
g4_s05_same( 2, $semantic_routing->calls, 'Cross-teacher semantic comparison must re-check current routing for each actor.' );
g4_s05_same( 2, $semantic_queue->calls, 'Each authorized enqueue must wake the durable queue after completion.' );
$GLOBALS['g4_s03_actor'] = 42;

echo "G4-IMP-01-S05 passed: C29/C30 nonce, idempotency, request-bound grants, Context and URL isolation, source races, actor-scoped receipts, and semantic replay are frozen.\n";
