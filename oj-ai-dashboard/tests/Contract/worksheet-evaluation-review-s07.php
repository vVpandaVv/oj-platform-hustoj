<?php
/**
 * G2-IMP-05-S07 C33 worksheet evaluation teacher review contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader as AddonAutoloader;
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
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Review_Worksheet_Evaluation;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Review_Worksheet_Evaluation_Command;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Worksheet_Evaluation_Failure;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Worksheet_Evaluation_Review_Result;
use OJAI\Dashboard\Worksheet\Evaluation\Port\Worksheet_Evaluation_Review_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Evaluation\Presentation\Worksheet_Evaluation_Review_Rest_Controller;

$addon_root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );

if ( ! class_exists( 'Normalizer' ) ) {
	/**
	 * G2-IMP-05-S07 C33 worksheet evaluation teacher review contract.
	 */
	final class Normalizer {
		public const FORM_C = 4;
		/**
		 * Normalize.
		 *
		 * @param string $value Value.
		 * @param int    $form Form.
		 * @return string
		 */
		public static function normalize( string $value, int $form = self::FORM_C ): string {
			unset( $form );
			return $value;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * G2-IMP-05-S07 C33 worksheet evaluation teacher review contract.
	 */
	final class WP_REST_Response {
		/**
		 * Initialize wp rest response dependencies and state.
		 *
		 * @param mixed $data Data.
		 * @param int   $status Status.
		 */
		public function __construct( private $data = null, private int $status = 200 ) {
		}
		/**
		 * Get data.
		 *
		 * @return mixed
		 */
		public function get_data() {
			return $this->data;
		}
		/**
		 * Get status.
		 *
		 * @return int
		 */
		public function get_status(): int {
			return $this->status;
		}
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * G2-IMP-05-S07 C33 worksheet evaluation teacher review contract.
	 */
	final class WP_Error {
		/**
		 * Initialize wp error dependencies and state.
		 *
		 * @param string $code Code.
		 * @param string $message Message.
		 * @param mixed  $data Data.
		 */
		public function __construct( private string $code, private string $message, private $data = array() ) {
		}
		/**
		 * Get error code.
		 *
		 * @return string
		 */
		public function get_error_code(): string {
			return $this->code;
		}
		/**
		 * Get error data.
		 *
		 * @return mixed
		 */
		public function get_error_data() {
			return $this->data;
		}
	}
}

$GLOBALS['eval_s07_actor']       = 42;
$GLOBALS['eval_s07_nonce_calls'] = 0;
$GLOBALS['eval_s07_routes']      = array();

if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Get current user id.
	 *
	 * @return int
	 */
	function get_current_user_id(): int {
		return (int) $GLOBALS['eval_s07_actor'];
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	/**
	 * Wp verify nonce.
	 *
	 * @param string $nonce Nonce.
	 * @param string $action Action.
	 * @return mixed
	 */
	function wp_verify_nonce( string $nonce, string $action ) {
		++$GLOBALS['eval_s07_nonce_calls'];
		return 'valid-s07-nonce' === $nonce && 'wp_rest' === $action ? 1 : false;
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	/**
	 * Register rest route.
	 *
	 * @param string              $namespace Namespace.
	 * @param string              $route Route.
	 * @param array<string,mixed> $arguments Arguments.
	 * @return bool
	 */
	function register_rest_route( string $namespace, string $route, array $arguments ): bool {
		$GLOBALS['eval_s07_routes'][] = array( $namespace, $route, $arguments );
		return true;
	}
}

require_once $addon_root . '/src/Core/Autoloader.php';
( new AddonAutoloader( $addon_root . '/src' ) )->register();

/**
 * Eval s07 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function eval_s07_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Eval s07 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function eval_s07_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * Eval s07 failure.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $reason Reason.
 * @param string           $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function eval_s07_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback(); } catch ( Worksheet_Evaluation_Failure $failure ) {
		eval_s07_same( $reason, $failure->reason(), $message );
		return;
		}
		throw new RuntimeException( $message );
}

/**
 * Eval s07 invalid.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function eval_s07_invalid( callable $callback, string $message ): void {
	try {
		$callback(); } catch ( InvalidArgumentException ) {
		return;
		}
		throw new RuntimeException( $message );
}

/**
 * Eval s07 id.
 *
 * @return string
 */
function eval_s07_id(): string {
	static $suffix = 7000;
	++$suffix;
	$id = '01K' . str_pad( (string) $suffix, 23, '0', STR_PAD_LEFT );
	eval_s07_assert( Public_Id::is_valid( $id ), 'S07 fixture ID must satisfy PublicIdV1.' );
	return $id;
}

/**
 * Eval s07 item.
 *
 * @param string      $key Key.
 * @param string      $decision Decision.
 * @param string|null $reason Reason.
 * @param string|null $note Note.
 * @param string|null $revision Revision.
 * @param string|null $hash Hash.
 * @return array<string,mixed>
 */
function eval_s07_item( string $key, string $decision, ?string $reason = null, ?string $note = null, ?string $revision = null, ?string $hash = null ): array {
	return array(
		'finding_key'            => $key,
		'decision'               => $decision,
		'reason_code'            => $reason,
		'note'                   => $note,
		'resolution_revision_id' => $revision,
		'resolution_source_hash' => $hash,
	);
}

/**
 * Eval s07 snapshot.
 *
 * @return array<string,mixed>
 */
function eval_s07_snapshot(): array {
	return array(
		'evaluation_id'           => eval_s07_id(),
		'evaluation_state'        => 'review_required',
		'evaluation_lock_version' => 5,
		'worksheet_revision_id'   => eval_s07_id(),
		'source_hash'             => str_repeat( 'a', 64 ),
		'result_id'               => eval_s07_id(),
		'finding_keys'            => array( str_repeat( '1', 64 ), str_repeat( '2', 64 ) ),
		'latest_review_id'        => null,
		'latest_review_no'        => 0,
		'latest_review_items'     => array(),
	);
}

/**
 * G2-IMP-05-S07 C33 worksheet evaluation teacher review contract.
 */
final class Eval_S07_Repository implements Worksheet_Evaluation_Review_Repository_Port_Interface {
	/**
	 * Snapshot.
	 *
	 * @var array<string,mixed>
	 */
	public array $snapshot;
	/**
	 * Records.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $records = array();
	/**
	 * Receipts.
	 *
	 * @var array<string,array{digest:string,result:Worksheet_Evaluation_Review_Result}>
	 */
	private array $receipts = array();

	/**
	 * Initialize eval s07 repository dependencies and state.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 */
	public function __construct( array $snapshot ) {
		$this->snapshot = $snapshot;
	}

	/**
	 * Find command receipt.
	 *
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @return Worksheet_Evaluation_Review_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_command_receipt( string $key, string $digest ): ?Worksheet_Evaluation_Review_Result {
		if ( ! isset( $this->receipts[ $key ] ) ) {
			return null;
		}
		$receipt = $this->receipts[ $key ];
		if ( ! hash_equals( $digest, $receipt['digest'] ) ) {
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::IDEMPOTENCY_CONFLICT );
		}
		$result = $receipt['result'];
		return new Worksheet_Evaluation_Review_Result(
			$result->evaluation_id(),
			$result->evaluation_state(),
			$result->worksheet_revision_id(),
			$result->source_hash(),
			$result->result_id(),
			$result->review_id(),
			$result->review_no(),
			$result->decision(),
			$result->item_decisions(),
			$result->occurred_at_utc(),
			true
		);
	}

	/**
	 * Find owned snapshot.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $evaluation_id Evaluation id.
	 * @return array|null
	 */
	public function find_owned_snapshot( int $actor_user_id, string $evaluation_id ): ?array {
		return 42 === $actor_user_id && $evaluation_id === $this->snapshot['evaluation_id'] ? $this->snapshot : null;
	}

	/**
	 * Append review.
	 *
	 * @param array $record Record.
	 * @return Worksheet_Evaluation_Review_Result
	 */
	public function append_review( array $record ): Worksheet_Evaluation_Review_Result {
		$this->records[] = $record;
		$state           = 'completed' === $record['decision'] ? 'reviewed' : 'review_required';
		$result          = new Worksheet_Evaluation_Review_Result(
			$record['evaluation_public_id'],
			$state,
			$record['worksheet_revision_public_id'],
			$record['worksheet_revision_hash'],
			$record['expected_result_public_id'],
			$record['review_public_id'],
			$record['review_no'],
			$record['decision'],
			$record['item_decisions'],
			$record['occurred_at_utc'],
			false
		);
		$this->receipts[ $record['command_storage_key'] ] = array(
			'digest' => $record['request_digest'],
			'result' => $result,
		);
		$this->snapshot['evaluation_state']               = $state;
		++$this->snapshot['evaluation_lock_version'];
		$this->snapshot['latest_review_id']    = $record['review_public_id'];
		$this->snapshot['latest_review_no']    = $record['review_no'];
		$this->snapshot['latest_review_items'] = $record['item_decisions'];
		return $result;
	}
}

/**
 * Eval s07 command.
 *
 * @param array          $snapshot Snapshot.
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @param string         $command_id Command identifier used for idempotency.
 * @param string         $fingerprint Fingerprint.
 * @param array          $items Items.
 * @param bool           $complete Complete.
 * @return Review_Worksheet_Evaluation_Command
 */
function eval_s07_command(
	array $snapshot,
	Canonical_Json $canonical,
	string $command_id,
	string $fingerprint,
	array $items,
	bool $complete
): Review_Worksheet_Evaluation_Command {
	return new Review_Worksheet_Evaluation_Command(
		42,
		$snapshot['evaluation_id'],
		$command_id,
		$snapshot['evaluation_state'],
		$snapshot['result_id'],
		$snapshot['latest_review_no'],
		$items,
		$complete,
		$fingerprint,
		's07-correlation-0001',
		$snapshot['worksheet_revision_id'],
		$snapshot['evaluation_state'],
		'owner',
		$canonical
	);
}

/**
 * G2-IMP-05-S07 C33 worksheet evaluation teacher review contract.
 */
final class Eval_S07_Policy implements Access_Policy_Port_Interface {
	/**
	 * Last request.
	 *
	 * @var Access_Policy_Request|null
	 */
	public ?Access_Policy_Request $last_request = null;
	/**
	 * Initialize eval s07 policy dependencies and state.
	 *
	 * @param string $evaluation_id Evaluation id.
	 * @param string $revision_id Revision id.
	 * @param string $state State.
	 */
	public function __construct( private string $evaluation_id, private string $revision_id, public string $state ) {
	}
	/**
	 * Authorize.
	 *
	 * @param Access_Policy_Request $request Request.
	 * @return Access_Policy_Decision
	 */
	public function authorize( Access_Policy_Request $request ): Access_Policy_Decision {
		$this->last_request = $request;
		$object             = $request->object();
		if (
			! $request->request_integrity_verified() || 42 !== $request->actor_user_id()
			|| Worksheet_Evaluation_Review_Rest_Controller::ACTION_REVIEW !== $request->action_key()
			|| array( Addon_Capabilities::REVIEW_ASSESSMENTS ) !== $request->required_capabilities()
			|| ! $object instanceof Object_Scope || Object_Scope::TYPE_EVALUATION !== $object->type()
			|| $this->evaluation_id !== $object->id() || 'edit' !== $object->action()
		) {
			return Access_Policy_Decision::denied( 404, 'worksheet_evaluation_scope_denied', $request->correlation_id() );
		}
		return Access_Policy_Decision::allowed(
			$request->correlation_id(),
			null,
			Object_Authorization_Result::allowed( $this->revision_id, $this->state, 'owner' )
		);
	}
}

/**
 * G2-IMP-05-S07 C33 worksheet evaluation teacher review contract.
 */
final class Eval_S07_Request {
	/**
	 * Headers.
	 *
	 * @var array<string,mixed>
	 */
	private array $headers = array();
	/**
	 * Initialize eval s07 request dependencies and state.
	 *
	 * @param array<string,mixed> $headers Headers.
	 * @param array<string,mixed> $json Json.
	 * @param string              $evaluation_id Evaluation id.
	 */
	public function __construct( array $headers, private array $json, private string $evaluation_id ) {
		foreach ( $headers as $name => $value ) {
			$this->headers[ strtolower( $name ) ] = $value;
		}
	}
	/**
	 * Get method.
	 *
	 * @return string
	 */
	public function get_method(): string {
		return 'POST';
	}
	/**
	 * Get header.
	 *
	 * @param string $name Name.
	 * @return mixed
	 */
	public function get_header( string $name ) {
		return $this->headers[ strtolower( $name ) ] ?? null;
	}
	/**
	 * Get json params.
	 *
	 * @return array<string,mixed>
	 */
	public function get_json_params(): array {
		return $this->json;
	}
	/**
	 * Get url params.
	 *
	 * @return array<string,string>
	 */
	public function get_url_params(): array {
		return array( 'evaluation_id' => $this->evaluation_id );
	}
}

/**
 * Eval s07 body.
 *
 * @param array                          $snapshot Snapshot.
 * @param string                         $command_id Command identifier used for idempotency.
 * @param array<int,array<string,mixed>> $items Items.
 * @param bool                           $complete Complete.
 * @return array<string,mixed>
 */
function eval_s07_body( array $snapshot, string $command_id, array $items, bool $complete ): array {
	$wire_items = array();
	foreach ( $items as $item ) {
		$wire = array(
			'finding_key' => $item['finding_key'],
			'decision'    => $item['decision'],
		);
		foreach ( array( 'reason_code', 'note', 'resolution_revision_id', 'resolution_source_hash' ) as $field ) {
			if ( null !== $item[ $field ] ) {
				$wire[ $field ] = $item[ $field ];
			}
		}
		$wire_items[] = $wire;
	}
	return array(
		'command_id' => $command_id,
		'expected'   => array(
			'expected_state'     => $snapshot['evaluation_state'],
			'expected_result_id' => $snapshot['result_id'],
			'expected_review_no' => $snapshot['latest_review_no'],
		),
		'payload'    => array(
			'item_decisions'  => $wire_items,
			'complete_review' => $complete,
		),
	);
}

$canonical        = new Canonical_Json();
$ids              = new Public_Id_Generator();
$snapshot         = eval_s07_snapshot();
$repository       = new Eval_S07_Repository( $snapshot );
$application      = new Review_Worksheet_Evaluation(
	$repository,
	$ids,
	$canonical,
	static fn (): string => '2026-08-21 15:00:00'
);
$first            = eval_s07_item( $snapshot['finding_keys'][0], 'deferred', 'needs_revision', 'Revise this item before completion.' );
$progress_command = eval_s07_command(
	$snapshot,
	$canonical,
	's07-review-command-0001',
	str_repeat( '7', 64 ),
	array( $first ),
	false
);
$progress         = $application->execute( $progress_command );
eval_s07_same( 'review_required', $progress->evaluation_state(), 'A partial review must remain review_required.' );
eval_s07_same( 'in_progress', $progress->decision(), 'A partial review must append an in_progress review.' );
eval_s07_same( 1, $progress->review_no(), 'The first review number must be one.' );
eval_s07_same( 1, count( $repository->records ), 'A review command must append one durable record.' );
$progress_replay = $application->execute( $progress_command );
eval_s07_same( true, $progress_replay->replayed(), 'An exact review retry must replay its durable receipt.' );
eval_s07_same( 1, count( $repository->records ), 'An exact review retry must append zero review rows.' );

$second_snapshot  = $repository->snapshot;
$completed_items  = array(
	eval_s07_item( $snapshot['finding_keys'][0], 'dismissed', 'context_verified', 'The source context confirms the current wording.' ),
	eval_s07_item( $snapshot['finding_keys'][1], 'dismissed', 'teacher_verified', 'The teacher verified this recommendation.' ),
);
$complete_command = eval_s07_command(
	$second_snapshot,
	$canonical,
	's07-review-command-0002',
	str_repeat( '8', 64 ),
	$completed_items,
	true
);
$completed        = $application->execute( $complete_command );
eval_s07_same( 'reviewed', $completed->evaluation_state(), 'A complete review must move the root to reviewed.' );
eval_s07_same( 'completed', $completed->decision(), 'A complete review must append a completed review.' );
eval_s07_same( 2, $completed->review_no(), 'Review history numbers must remain contiguous.' );
eval_s07_same( 2, count( $completed->item_decisions() ), 'The appended review must contain the cumulative decision snapshot.' );
eval_s07_same( $completed->review_id(), $repository->snapshot['latest_review_id'], 'The newest review must link as the next previous review.' );

$recheck_repository          = new Eval_S07_Repository( $repository->snapshot );
$recheck_repository->records = $repository->records;
$before_recheck              = $recheck_repository->records;
$recheck_application         = new Review_Worksheet_Evaluation( $recheck_repository, $ids, $canonical );
$recheck_items               = $completed_items;
$recheck_items[0]['note']    = 'Rechecked the original source and recorded a new judgment.';
$recheck_command             = eval_s07_command( $recheck_repository->snapshot, $canonical, 's07-recheck-command-0003', str_repeat( 'b', 64 ), $recheck_items, true );
$rechecked                   = $recheck_application->execute( $recheck_command );
eval_s07_same( 3, $rechecked->review_no(), 'Re-review of a completed result must append the next review.' );
eval_s07_same( $before_recheck, array_slice( $recheck_repository->records, 0, 2 ), 'Re-review must preserve all earlier records byte-for-byte.' );
eval_s07_same( $completed->review_id(), $recheck_repository->records[2]['expected_previous_review_public_id'], 'Re-review must preserve its predecessor link.' );
eval_s07_same( true, $recheck_application->execute( $recheck_command )->replayed(), 'Replayed re-review must not append again.' );
eval_s07_same( 3, count( $recheck_repository->records ), 'One re-review action must create only one record.' );

$empty_snapshot                 = eval_s07_snapshot();
$empty_snapshot['finding_keys'] = array();
$empty_repository               = new Eval_S07_Repository( $empty_snapshot );
$empty_application              = new Review_Worksheet_Evaluation( $empty_repository, $ids, $canonical );
$empty_command                  = eval_s07_command( $empty_snapshot, $canonical, 's07-empty-review-complete', str_repeat( '2', 64 ), array(), true );
$empty_completed                = $empty_application->execute( $empty_command );
eval_s07_same( 'reviewed', $empty_completed->evaluation_state(), 'Zero findings still require an explicit review completion.' );
eval_s07_same( array(), $empty_completed->item_decisions(), 'A zero-finding review must not invent findings.' );
eval_s07_same( true, $empty_application->execute( $empty_command )->replayed(), 'Zero-finding completion must replay its receipt.' );
eval_s07_same( 1, count( $empty_repository->records ), 'Repeated zero-finding completion must not append another review.' );

$incomplete_snapshot    = eval_s07_snapshot();
$incomplete_repository  = new Eval_S07_Repository( $incomplete_snapshot );
$incomplete_application = new Review_Worksheet_Evaluation( $incomplete_repository, $ids, $canonical );
eval_s07_failure(
	static fn () => $incomplete_application->execute(
		eval_s07_command(
			$incomplete_snapshot,
			$canonical,
			's07-review-command-0003',
			str_repeat( '9', 64 ),
			array( eval_s07_item( $incomplete_snapshot['finding_keys'][0], 'dismissed', 'verified', 'Verified.' ) ),
			true
		)
	),
	Worksheet_Evaluation_Failure::REVIEW_INCOMPLETE,
	'complete_review must fail while a finding has no decision.'
);
eval_s07_same( 0, count( $incomplete_repository->records ), 'An incomplete review must append zero rows.' );

$unknown_snapshot    = eval_s07_snapshot();
$unknown_repository  = new Eval_S07_Repository( $unknown_snapshot );
$unknown_application = new Review_Worksheet_Evaluation( $unknown_repository, $ids, $canonical );
eval_s07_failure(
	static fn () => $unknown_application->execute(
		eval_s07_command(
			$unknown_snapshot,
			$canonical,
			's07-review-command-0004',
			str_repeat( 'a', 64 ),
			array( eval_s07_item( str_repeat( 'f', 64 ), 'dismissed', 'verified', 'Verified.' ) ),
			false
		)
	),
	Worksheet_Evaluation_Failure::REVIEW_CONFLICT,
	'C33 must reject a decision for an unknown finding key.'
);

eval_s07_invalid(
	static fn () => eval_s07_command(
		$snapshot,
		$canonical,
		's07-review-invalid-0001',
		str_repeat( 'b', 64 ),
		array( eval_s07_item( $snapshot['finding_keys'][0], 'dismissed' ) ),
		false
	),
	'A dismissed decision must require a reason and note.'
);
eval_s07_invalid(
	static fn () => eval_s07_command(
		$snapshot,
		$canonical,
		's07-review-invalid-0002',
		str_repeat( 'c', 64 ),
		array( eval_s07_item( $snapshot['finding_keys'][0], 'resolved', null, null, eval_s07_id(), null ) ),
		false
	),
	'A resolved decision must require the descendant revision hash.'
);

$rest_snapshot    = eval_s07_snapshot();
$rest_repository  = new Eval_S07_Repository( $rest_snapshot );
$rest_application = new Review_Worksheet_Evaluation(
	$rest_repository,
	$ids,
	$canonical,
	static fn (): string => '2026-08-21 15:10:00'
);
$policy           = new Eval_S07_Policy( $rest_snapshot['evaluation_id'], $rest_snapshot['worksheet_revision_id'], 'review_required' );
$correlations     = new Correlation_Id_Factory( static fn (): string => 's07-rest-correlation-0001' );
$envelopes        = new Rest_Envelope_Factory();
$permissions      = new Rest_Permission_Handoff(
	new WordPress_Rest_Request_Adapter( new WordPress_Current_Actor() ),
	new Rest_Request_Authorizer( $policy, new WordPress_Rest_Nonce_Verifier(), $correlations ),
	$envelopes,
	$correlations
);
$controller       = new Worksheet_Evaluation_Review_Rest_Controller(
	$permissions,
	$rest_application,
	$canonical,
	$envelopes,
	$correlations
);
$controller->register_routes();
$controller->register_routes();
eval_s07_same( 1, count( $GLOBALS['eval_s07_routes'] ), 'S07 must register C33 exactly once.' );
eval_s07_same( 'POST', $GLOBALS['eval_s07_routes'][0][2]['methods'], 'C33 must be POST.' );
$rest_items   = array(
	eval_s07_item( $rest_snapshot['finding_keys'][0], 'dismissed', 'verified', 'Verified by the teacher.' ),
	eval_s07_item( $rest_snapshot['finding_keys'][1], 'dismissed', 'verified', 'Verified by the teacher.' ),
);
$rest_request = new Eval_S07_Request(
	array(
		'X-WP-Nonce'      => 'valid-s07-nonce',
		'Idempotency-Key' => 's07-rest-idempotency-0001',
	),
	eval_s07_body( $rest_snapshot, 's07-rest-review-command-0001', $rest_items, true ),
	$rest_snapshot['evaluation_id']
);
eval_s07_same( true, $controller->permissions_review( $rest_request ), 'C33 permission must use the common REST handoff.' );
$rest_response = $controller->review( $rest_request );
eval_s07_assert( $rest_response instanceof WP_REST_Response, 'C33 success must return a REST response.' );
eval_s07_same( 201, $rest_response->get_status(), 'A newly appended review must return 201.' );
$rest_envelope = $rest_response->get_data();
eval_s07_same( 'reviewed', $rest_envelope['data']['evaluation']['state'], 'C33 REST must expose the reviewed state.' );
eval_s07_same( false, $rest_envelope['meta']['idempotent_replay'], 'A new C33 response must not report replay.' );
eval_s07_same( 1, $GLOBALS['eval_s07_nonce_calls'], 'C33 permission must verify wp_rest nonce exactly once.' );
eval_s07_same( Addon_Capabilities::REVIEW_ASSESSMENTS, $policy->last_request?->required_capabilities()[0], 'C33 capability drifted.' );

$invalid_body = eval_s07_body( $rest_snapshot, 's07-rest-review-command-0002', $rest_items, true );
$invalid_body['payload']['item_decisions'][0]['unexpected'] = true;
$invalid_request = new Eval_S07_Request(
	array(
		'X-WP-Nonce'      => 'valid-s07-nonce',
		'Idempotency-Key' => 's07-rest-idempotency-0002',
	),
	$invalid_body,
	$rest_snapshot['evaluation_id']
);
$policy->state   = 'reviewed';
eval_s07_same( true, $controller->permissions_review( $invalid_request ), 'Invalid C33 body must still pass independent permission checks.' );
$invalid_response = $controller->review( $invalid_request );
eval_s07_assert( $invalid_response instanceof WP_Error, 'Unknown C33 item fields must fail.' );
eval_s07_same( 422, $invalid_response->get_error_data()['status'], 'Unknown C33 item fields must return 422.' );

$repository_source = file_get_contents( $addon_root . '/src/Worksheet/Evaluation/Infrastructure/WordPress_Worksheet_Evaluation_Review_Repository.php' );
$root_source       = file_get_contents( $addon_root . '/src/Core/Composition_Root.php' );
eval_s07_assert( is_string( $repository_source ) && is_string( $root_source ), 'S07 source files must be readable.' );
eval_s07_assert( str_contains( $repository_source, "private const TABLE_REVIEWS     = 'oj_ai_worksheet_evaluation_reviews';" ), 'S07 must use the literal review table.' );
eval_s07_assert( ! str_contains( $repository_source, '$wpdb->prefix' ) && ! str_contains( $repository_source, 'wp_oj_ai_' ), 'S07 must not prefix add-on tables.' );
eval_s07_assert( ! str_contains( $repository_source, 'UPDATE ' . "' . self::TABLE_REVIEWS" ), 'C33 review rows must remain append-only.' );
eval_s07_assert( str_contains( $repository_source, 'START TRANSACTION' ) && str_contains( $repository_source, 'FOR UPDATE' ), 'C33 must use a transaction and row locks.' );
eval_s07_assert( str_contains( $repository_source, 'expected_evaluation_lock_version' ), 'C33 must carry an internal root CAS version.' );
eval_s07_assert( str_contains( $repository_source, 'source_revision_id' ) && str_contains( $repository_source, 'current_revision_id' ), 'Resolved findings must validate the current descendant revision.' );
eval_s07_assert( str_contains( $repository_source, 'insert_audit' ) && str_contains( $repository_source, 'insert_outbox' ), 'C33 must write audit and outbox evidence in the transaction.' );
eval_s07_assert( str_contains( $root_source, 'new Worksheet_Evaluation_Review_Rest_Module' ), 'Composition Root must register the S07 REST module.' );
eval_s07_assert( ! str_contains( $repository_source, 'OJ\\Platform\\' ), 'S07 must not depend on platform internals.' );

echo "G2-IMP-05-S07 worksheet evaluation teacher review contract passed.\n";
