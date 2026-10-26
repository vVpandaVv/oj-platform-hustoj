<?php
/**
 * G2-IMP-06-S02 C06 worksheet approval command contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader as Addon_Autoloader;
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
use OJAI\Dashboard\Worksheet\Authoring\Application\Approve_Worksheet_Revision;
use OJAI\Dashboard\Worksheet\Authoring\Application\Approve_Worksheet_Revision_Command;
use OJAI\Dashboard\Worksheet\Authoring\Application\Approve_Worksheet_Revision_Result;
use OJAI\Dashboard\Worksheet\Authoring\Application\Create_Worksheet;
use OJAI\Dashboard\Worksheet\Authoring\Application\Create_Worksheet_Result;
use OJAI\Dashboard\Worksheet\Authoring\Application\Read_Worksheets;
use OJAI\Dashboard\Worksheet\Authoring\Application\Save_Worksheet_Draft_Result;
use OJAI\Dashboard\Worksheet\Authoring\Application\Validate_Worksheet_Revision;
use OJAI\Dashboard\Worksheet\Authoring\Application\Validate_Worksheet_Revision_Command;
use OJAI\Dashboard\Worksheet\Authoring\Application\Worksheet_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Initial_Document_Factory;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Approval_Policy;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Approval_Policy_Gate;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Canonicalizer;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Id_Rewriter;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Preview_Projector;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Projection_Safety_Inspector;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Approval_Evidence_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Approval_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Asset_Readiness_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Presentation\Worksheet_Rest_Controller;

$addon_root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );

if ( ! class_exists( 'Normalizer' ) ) {
	/**
	 * G2-IMP-06-S02 C06 worksheet approval command contract.
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
	 * G2-IMP-06-S02 C06 worksheet approval command contract.
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
	 * G2-IMP-06-S02 C06 worksheet approval command contract.
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

$GLOBALS['approval_s02_actor']  = 42;
$GLOBALS['approval_s02_routes'] = array();
if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Get current user id.
	 *
	 * @return int
	 */
	function get_current_user_id(): int {
		return (int) $GLOBALS['approval_s02_actor'];
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
		return 'approval-s02-nonce' === $nonce && 'wp_rest' === $action ? 1 : false;
	}
}
if ( ! function_exists( 'rest_url' ) ) {
	/**
	 * Rest url.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	function rest_url( string $path = '' ): string {
		return 'https://example.test/wp-json/' . ltrim( $path, '/' ) . '/';
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
		$GLOBALS['approval_s02_routes'][] = array( $namespace, $route, $arguments );
		return true;
	}
}

require_once $addon_root . '/src/Core/Autoloader.php';
( new Addon_Autoloader( $addon_root . '/src' ) )->register();

/**
 * Approval s02 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function approval_s02_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Approval s02 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function approval_s02_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * Approval s02 failure.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $reason Reason.
 * @param string           $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function approval_s02_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback(); } catch ( Worksheet_Failure $failure ) {
		approval_s02_same( $reason, $failure->reason(), $message );
		return;
		}
		throw new RuntimeException( $message );
}

/**
 * Approval s02 id.
 *
 * @param int $suffix Suffix.
 * @return string
 */
function approval_s02_id( int $suffix ): string {
	$id = '01K' . str_pad( (string) $suffix, 23, '0', STR_PAD_LEFT );
	approval_s02_assert( Public_Id::is_valid( $id ), 'S02 fixture ID must satisfy PublicIdV1.' );
	return $id;
}

/**
 * G2-IMP-06-S02 C06 worksheet approval command contract.
 */
final class Approval_S02_Worksheet_Repository implements Worksheet_Repository_Port_Interface {
	/**
	 * Initialize approval s02 worksheet repository dependencies and state.
	 *
	 * @param array<string,mixed> $source Source.
	 */
	public function __construct( public array $source ) {
	}
	/**
	 * Create initial.
	 *
	 * @param array $record Record.
	 * @return Create_Worksheet_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function create_initial( array $record ): Create_Worksheet_Result {
		throw new RuntimeException( 'Not used.' );
	}
	/**
	 * Append draft revision.
	 *
	 * @param array $record Record.
	 * @return Save_Worksheet_Draft_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function append_draft_revision( array $record ): Save_Worksheet_Draft_Result {
		throw new RuntimeException( 'Not used.' );
	}
	/**
	 * List owned.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $state State.
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array
	 */
	public function list_owned( int $actor_user_id, string $state, string $cursor, int $limit ): array {
		return array(
			'items'       => array(),
			'next_cursor' => '',
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
		return null;
	}
	/**
	 * List owned revisions.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $worksheet_id Worksheet public identifier.
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array|null
	 */
	public function list_owned_revisions( int $actor_user_id, string $worksheet_id, string $cursor, int $limit ): ?array {
		return null;
	}
	/**
	 * Find owned revision.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $revision_id Revision id.
	 * @return array|null
	 */
	public function find_owned_revision( int $actor_user_id, string $revision_id ): ?array {
		return 42 === $actor_user_id && $revision_id === ( $this->source['revision']['id'] ?? null ) ? $this->source : null;
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
		return 42 === $actor_user_id ? array(
			'current_revision' => $object_id,
			'state'            => 'draft',
			'relation'         => 'owner',
		) : null;
	}
}

/**
 * G2-IMP-06-S02 C06 worksheet approval command contract.
 */
final class Approval_S02_Assets implements Worksheet_Asset_Readiness_Port_Interface {
	/**
	 * Inspect.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $worksheet_id Worksheet public identifier.
	 * @param array  $asset_ids Asset ids.
	 * @return array
	 */
	public function inspect( int $actor_user_id, string $worksheet_id, array $asset_ids ): array {
		return array(
			'ready'      => true,
			'blockers'   => array(),
			'statistics' => array(
				'asset_count'       => count( $asset_ids ),
				'ready_asset_count' => count( $asset_ids ),
				'asset_total_bytes' => 0,
			),
		);
	}
}

/**
 * G2-IMP-06-S02 C06 worksheet approval command contract.
 */
final class Approval_S02_Evidence implements Worksheet_Approval_Evidence_Port_Interface {
	/**
	 * Include evaluation.
	 *
	 * @var bool
	 */
	public bool $include_evaluation = true;
	/**
	 * Initialize approval s02 evidence dependencies and state.
	 *
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 * @param string         $worksheet_id Worksheet public identifier.
	 * @param string         $revision_id Revision id.
	 * @param string         $document_hash Document hash.
	 */
	public function __construct( private Canonical_Json $canonical, private string $worksheet_id, private string $revision_id, private string $document_hash ) {
	}
	/**
	 * Current.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $worksheet_id Worksheet public identifier.
	 * @param string $revision_id Revision id.
	 * @return array
	 */
	public function current( int $actor_user_id, string $worksheet_id, string $revision_id ): array {
		$policy_id = approval_s02_id( 20 );
		$policy    = new Worksheet_Approval_Policy( $policy_id, Worksheet_Approval_Policy::EVALUATION_REQUIRED, true, $this->canonical );
		return array(
			'policy'              => $policy,
			'evaluation_detail'   => $this->include_evaluation ? $this->detail() : null,
			'configuration_guard' => array(
				'policy_revision_id'       => $policy_id,
				'policy_content_hash'      => hash( 'sha256', 'config-policy' ),
				'rubric_revision_id'       => approval_s02_id( 21 ),
				'rubric_content_hash'      => hash( 'sha256', 'config-rubric' ),
				'review_rule_revision_id'  => approval_s02_id( 22 ),
				'review_rule_content_hash' => hash( 'sha256', 'config-review' ),
			),
			'evaluation_guard'    => array(
				'evaluation_id'            => approval_s02_id( 23 ),
				'lock_version'             => 4,
				'state'                    => 'reviewed',
				'updated_at_utc'           => '2026-08-21 18:10:00',
				'source_hash'              => $this->document_hash,
				'policy_revision_id'       => $policy_id,
				'policy_content_hash'      => hash( 'sha256', 'config-policy' ),
				'rubric_revision_id'       => approval_s02_id( 21 ),
				'rubric_content_hash'      => hash( 'sha256', 'config-rubric' ),
				'review_rule_revision_id'  => approval_s02_id( 22 ),
				'review_rule_content_hash' => hash( 'sha256', 'config-review' ),
				'projection_hash'          => $this->include_evaluation ? hash( 'sha256', $this->canonical->encode( $this->detail() ) ) : null,
			),
		);
	}

	/**
	 * Detail.
	 *
	 * @return array<string,mixed>
	 */
	public function detail(): array {
		return array(
			'evaluation' => array(
				'id'               => approval_s02_id( 23 ),
				'state'            => 'reviewed',
				'is_stale'         => false,
				'worksheet'        => array(
					'id'    => $this->worksheet_id,
					'title' => 'Approval fixture',
				),
				'revision'         => array(
					'id'          => $this->revision_id,
					'revision_no' => 1,
					'state'       => 'draft',
					'source_hash' => $this->document_hash,
					'is_current'  => true,
				),
				'status'           => array(
					'overall_status'        => 'ready',
					'finding_count'         => 0,
					'needs_attention_count' => 0,
					'safety_flagged'        => false,
					'latest_review_no'      => 1,
					'next_action'           => 'none',
				),
				'latest_run'       => array(
					'id'    => approval_s02_id( 24 ),
					'state' => 'succeeded',
				),
				'requested_at_utc' => '2026-08-21 18:00:00',
				'updated_at_utc'   => '2026-08-21 18:10:00',
			),
			'result'     => array(
				'id'                 => approval_s02_id( 25 ),
				'run_id'             => approval_s02_id( 24 ),
				'overall_status'     => 'ready',
				'overall_confidence' => '0.9500',
				'summary'            => 'Ready',
				'abstained'          => false,
				'safety_flagged'     => false,
				'abstain_reason'     => null,
				'dimensions'         => array(),
				'findings'           => array(),
				'limitations'        => array(),
				'created_at_utc'     => '2026-08-21 18:05:00',
			),
			'runs'       => array(
				array(
					'id'    => approval_s02_id( 24 ),
					'state' => 'succeeded',
				),
			),
			'reviews'    => array(
				array(
					'id'             => approval_s02_id( 26 ),
					'review_no'      => 1,
					'decision'       => 'completed',
					'note'           => null,
					'item_decisions' => array(),
					'previous_id'    => null,
					'created_at_utc' => '2026-08-21 18:10:00',
				),
			),
		);
	}
}

/**
 * G2-IMP-06-S02 C06 worksheet approval command contract.
 */
final class Approval_S02_Approval_Repository implements Worksheet_Approval_Repository_Port_Interface {
	/**
	 * Records.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $records = array();
	/**
	 * Receipts.
	 *
	 * @var array<string,array{digest:string,result:Approve_Worksheet_Revision_Result}>
	 */
	private array $receipts = array();
	/**
	 * Find command receipt.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @return Approve_Worksheet_Revision_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_command_receipt( int $actor_user_id, string $key, string $digest ): ?Approve_Worksheet_Revision_Result {
		if ( ! isset( $this->receipts[ $key ] ) ) {
			return null;
		}
		$receipt = $this->receipts[ $key ];
		if ( ! hash_equals( $digest, $receipt['digest'] ) ) {
			throw Worksheet_Failure::idempotency_conflict();
		}
		$result = $receipt['result'];
		return new Approve_Worksheet_Revision_Result(
			$result->worksheet_id(),
			$result->revision_id(),
			$result->revision_no(),
			$result->document_hash(),
			$result->validation_projection_hash(),
			$result->policy_revision_id(),
			$result->policy_hash(),
			$result->evaluation_projection_hash(),
			$result->approval_evidence_hash(),
			$result->approval_decision_hash(),
			$result->approved_by_user_id(),
			$result->approved_at_utc(),
			true
		);
	}
	/**
	 * Approve revision.
	 *
	 * @param array $record Record.
	 * @return Approve_Worksheet_Revision_Result
	 */
	public function approve_revision( array $record ): Approve_Worksheet_Revision_Result {
		$this->records[] = $record;
		$result          = new Approve_Worksheet_Revision_Result(
			$record['worksheet_public_id'],
			$record['revision_public_id'],
			1,
			$record['expected_document_hash'],
			$record['validation_projection_hash'],
			$record['policy_revision_public_id'],
			$record['policy_hash'],
			$record['evaluation_projection_hash'],
			$record['approval_evidence_hash'],
			$record['approval_decision_hash'],
			$record['actor_user_id'],
			$record['approved_at_utc'],
			false
		);
		$this->receipts[ $record['idempotency_storage_key'] ] = array(
			'digest' => $record['request_digest'],
			'result' => $result,
		);
		return $result;
	}
}

/**
 * G2-IMP-06-S02 C06 worksheet approval command contract.
 */
final class Approval_S02_Policy implements Access_Policy_Port_Interface {
	/**
	 * Last.
	 *
	 * @var Access_Policy_Request|null
	 */
	public ?Access_Policy_Request $last = null;
	/**
	 * Authorize.
	 *
	 * @param Access_Policy_Request $request Request.
	 * @return Access_Policy_Decision
	 */
	public function authorize( Access_Policy_Request $request ): Access_Policy_Decision {
		$this->last = $request;
		$object     = $request->object();
		if (
			! $request->request_integrity_verified() || 42 !== $request->actor_user_id()
			|| Worksheet_Rest_Controller::ACTION_APPROVE !== $request->action_key()
			|| array( Addon_Capabilities::MANAGE_WORKSHEETS ) !== $request->required_capabilities()
			|| ! $object instanceof Object_Scope || Object_Scope::TYPE_WORKSHEET_REVISION !== $object->type()
			|| 'edit' !== $object->action()
		) {
			return Access_Policy_Decision::denied( 403, 'approval_s02_denied', $request->correlation_id() );
		}
		return Access_Policy_Decision::allowed( $request->correlation_id(), null, Object_Authorization_Result::allowed( $object->id(), 'draft', 'owner' ) );
	}
}

/**
 * G2-IMP-06-S02 C06 worksheet approval command contract.
 */
final class Approval_S02_Request {
	/**
	 * Headers.
	 *
	 * @var array<string,string>
	 */
	private array $headers = array();
	/**
	 * Initialize approval s02 request dependencies and state.
	 *
	 * @param array<string,string> $headers Headers.
	 * @param array<string,mixed>  $json Json.
	 * @param string               $revision_id Revision id.
	 */
	public function __construct( array $headers, private array $json, private string $revision_id ) {
		foreach ( $headers as $key => $value ) {
			$this->headers[ strtolower( $key ) ] = $value;
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
	 * @return string
	 */
	public function get_header( string $name ): string {
		return $this->headers[ strtolower( $name ) ] ?? '';
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
		return array( 'revision_id' => $this->revision_id );
	}
	/**
	 * Get query params.
	 *
	 * @return array<string,mixed>
	 */
	public function get_query_params(): array {
		return array();
	}
}

$canonical    = new Canonical_Json();
$ids          = new Public_Id_Generator();
$worksheet_id = approval_s02_id( 1 );
$revision_id  = approval_s02_id( 2 );
$initial      = ( new Initial_Document_Factory( $ids, $canonical ) )->create( 'Approval fixture', 'ko-KR' );
$initial['document']['settings']['learning_goals']      = array(
	array(
		'goal_id'     => approval_s02_id( 91 ),
		'text'        => 'Explain using evidence.',
		'scope'       => 'worksheet',
		'response_id' => null,
	),
);
$initial['document']['settings']['assessment_bindings'] = array(
	array(
		'criterion_id' => approval_s02_id( 92 ),
		'scope'        => 'worksheet',
		'response_id'  => null,
	),
);
$initial['document']['settings']['preparation']         = array(
	'state'               => 'confirmed',
	'rubric_revision_id'  => approval_s02_id( 93 ),
	'rubric_content_hash' => str_repeat( 'a', 64 ),
);
$documents         = new Worksheet_Document_Canonicalizer( $canonical, $ids );
$document          = $documents->canonicalize( $initial['document'] );
$source            = array(
	'worksheet_id'     => $worksheet_id,
	'revision'         => array(
		'id'              => $revision_id,
		'state'           => 'draft',
		'document_hash'   => $document->document_hash(),
		'validation_hash' => $document->validation_hash(),
	),
	'teacher_document' => $document->document(),
	'questions'        => array(),
);
$worksheets        = new Approval_S02_Worksheet_Repository( $source );
$validator         = new Validate_Worksheet_Revision(
	$worksheets,
	$documents,
	new Worksheet_Document_Id_Rewriter(),
	new Approval_S02_Assets(),
	$canonical,
	static fn (): string => '2026-08-21 18:15:00',
	new Worksheet_Preview_Projector( $canonical ),
	new Worksheet_Projection_Safety_Inspector( $canonical )
);
$evidence          = new Approval_S02_Evidence( $canonical, $worksheet_id, $revision_id, $document->document_hash() );
$gate              = new Worksheet_Approval_Policy_Gate( $canonical );
$validation        = $validator->execute(
	new Validate_Worksheet_Revision_Command(
		42,
		$revision_id,
		'approval-s02-validation-0001',
		'draft',
		$revision_id,
		hash( 'sha256', 'validation-key' ),
		'approval-s02-correlation-1',
		$canonical
	)
);
$evidence_snapshot = $evidence->current( 42, $worksheet_id, $revision_id );
$decision          = $gate->decide( $validation, $evidence_snapshot['policy'], $evidence_snapshot['evaluation_detail'] );
approval_s02_same( true, $validation->release_ready(), 'The C06 fixture must pass exact C05 validation.' );
approval_s02_same( true, $decision->approval_ready(), 'The C06 fixture must pass S01 approval policy.' );

$approval_repository = new Approval_S02_Approval_Repository();
$application         = new Approve_Worksheet_Revision(
	$worksheets,
	$approval_repository,
	$validator,
	$evidence,
	$gate,
	new Worksheet_Document_Id_Rewriter(),
	$ids,
	$canonical,
	static fn (): string => '2026-08-21 18:20:00'
);
$readiness           = $application->readiness( 42, $validation );
approval_s02_same( true, $readiness->approval_ready(), 'C05 approval readiness must reuse the exact C06 policy decision.' );
approval_s02_same( $decision->decision_hash(), $readiness->decision_hash(), 'C05 must expose the exact decision hash required by C06.' );
$command = new Approve_Worksheet_Revision_Command(
	42,
	$revision_id,
	'approval-s02-command-0001',
	'draft',
	$revision_id,
	$validation->document_hash(),
	$validation->projection_hash(),
	$decision->decision_hash(),
	hash( 'sha256', 'approval-s02-key-1' ),
	'approval-s02-correlation-2',
	$canonical
);
$result  = $application->execute( $command );
approval_s02_same( 'approved', $result->revision_state(), 'C06 must transition the exact draft revision to approved.' );
approval_s02_same( $document->document_hash(), $result->document_hash(), 'C06 must not change the document hash.' );
approval_s02_same( $decision->decision_hash(), $result->approval_decision_hash(), 'C06 must pin the S01 decision hash.' );
approval_s02_same( 1, count( $approval_repository->records ), 'C06 must write one approval transaction record.' );
approval_s02_same( array(), $approval_repository->records[0]['asset_public_ids'], 'C06 must bind the exact referenced asset set.' );
approval_s02_assert( is_string( $approval_repository->records[0]['evaluation_projection_hash'] ), 'C06 must pin Q10 projection hash.' );

$replay = $application->execute( $command );
approval_s02_same( true, $replay->replayed(), 'An exact C06 retry must replay its durable receipt before revalidation.' );
approval_s02_same( 1, count( $approval_repository->records ), 'An exact C06 retry must not append another approval.' );

$stale_command = new Approve_Worksheet_Revision_Command(
	42,
	$revision_id,
	'approval-s02-command-0002',
	'draft',
	$revision_id,
	$validation->document_hash(),
	$validation->projection_hash(),
	hash( 'sha256', 'stale-decision' ),
	hash( 'sha256', 'approval-s02-key-2' ),
	'approval-s02-correlation-3',
	$canonical
);
approval_s02_failure( static fn () => $application->execute( $stale_command ), Worksheet_Failure::APPROVAL_CONFLICT, 'A stale approval decision must return a safe conflict.' );

$evidence->include_evaluation = false;
$blocked_policy               = new Worksheet_Approval_Policy( approval_s02_id( 20 ), Worksheet_Approval_Policy::EVALUATION_REQUIRED, true, $canonical );
$blocked_decision             = $gate->decide( $validation, $blocked_policy, null );
$blocked_command              = new Approve_Worksheet_Revision_Command(
	42,
	$revision_id,
	'approval-s02-command-0003',
	'draft',
	$revision_id,
	$validation->document_hash(),
	$validation->projection_hash(),
	$blocked_decision->decision_hash(),
	hash( 'sha256', 'approval-s02-key-3' ),
	'approval-s02-correlation-4',
	$canonical
);
approval_s02_failure( static fn () => $application->execute( $blocked_command ), Worksheet_Failure::APPROVAL_BLOCKED, 'Missing required evaluation must block C06.' );
$evidence->include_evaluation = true;

$policy       = new Approval_S02_Policy();
$sequence     = 0;
$correlations = new Correlation_Id_Factory(
	static function () use ( &$sequence ): string {
		return 'approval-s02-rest-' . str_pad( (string) ++$sequence, 3, '0', STR_PAD_LEFT );
	}
);
$envelopes    = new Rest_Envelope_Factory();
$permissions  = new Rest_Permission_Handoff(
	new WordPress_Rest_Request_Adapter( new WordPress_Current_Actor() ),
	new Rest_Request_Authorizer( $policy, new WordPress_Rest_Nonce_Verifier(), $correlations ),
	$envelopes,
	$correlations
);
$controller   = new Worksheet_Rest_Controller(
	$permissions,
	new Create_Worksheet( $worksheets, $ids, new Initial_Document_Factory( $ids, $canonical ) ),
	new Read_Worksheets( $worksheets ),
	$canonical,
	$envelopes,
	$correlations,
	null,
	null,
	$validator,
	null,
	$application
);
$controller->register_routes();
$controller->register_routes();
$routes = array_values( array_filter( $GLOBALS['approval_s02_routes'], static fn ( array $route ): bool => Worksheet_Rest_Controller::ROUTE_APPROVE === $route[1] ) );
approval_s02_same( 1, count( $routes ), 'C06 REST route must register exactly once.' );
$body    = array(
	'command_id' => 'approval-s02-rest-command-1',
	'expected'   => array(
		'expected_state'                      => 'draft',
		'expected_revision_id'                => $revision_id,
		'expected_document_hash'              => $validation->document_hash(),
		'expected_validation_projection_hash' => $validation->projection_hash(),
		'expected_approval_decision_hash'     => $decision->decision_hash(),
	),
	'payload'    => array(),
);
$request = new Approval_S02_Request(
	array(
		'X-WP-Nonce'      => 'approval-s02-nonce',
		'Idempotency-Key' => 'approval-s02-rest-key-1',
	),
	$body,
	$revision_id
);
$route   = $routes[0][2];
approval_s02_same( true, ( $route['permission_callback'] )( $request ), 'C06 owner manage permission and nonce must pass.' );
$response = ( $route['callback'] )( $request );
approval_s02_assert( $response instanceof WP_REST_Response, 'C06 success must use the common REST envelope.' );
approval_s02_same( 200, $response->get_status(), 'C06 must return 200.' );
approval_s02_same( 'approved', $response->get_data()['data']['revision']['state'], 'C06 REST must expose approved state.' );
approval_s02_same( Worksheet_Rest_Controller::ACTION_APPROVE, $policy->last?->action_key(), 'C06 permission action drifted.' );

$invalid = new Approval_S02_Request(
	array(
		'X-WP-Nonce'      => 'approval-s02-nonce',
		'Idempotency-Key' => 'approval-s02-rest-key-2',
	),
	array_replace( $body, array( 'payload' => array( 'unexpected' => true ) ) ),
	$revision_id
);
approval_s02_same( true, ( $route['permission_callback'] )( $invalid ), 'C06 policy must run before body validation.' );
$invalid_response = ( $route['callback'] )( $invalid );
approval_s02_assert( $invalid_response instanceof WP_Error, 'Invalid C06 body must use the common problem envelope.' );
approval_s02_same( 422, $invalid_response->get_error_data()['status'], 'Invalid C06 body must return 422.' );

$repository_source = file_get_contents( $addon_root . '/src/Worksheet/Authoring/Infrastructure/WordPress_Worksheet_Approval_Repository.php' );
$evidence_source   = file_get_contents( $addon_root . '/src/Worksheet/Authoring/Infrastructure/WordPress_Worksheet_Approval_Evidence.php' );
approval_s02_assert( is_string( $repository_source ) && is_string( $evidence_source ), 'C06 infrastructure source must be readable.' );
foreach ( array( 'START TRANSACTION', 'FOR UPDATE', 'approved_by_user_id', 'oj_ai_audit_events', 'oj_ai_outbox_events', 'worksheet.revision_approved' ) as $marker ) {
	approval_s02_assert( str_contains( $repository_source, $marker ), 'C06 transaction marker is missing: ' . $marker );
}
approval_s02_assert( str_contains( $evidence_source, "'oj_ai_worksheet_evaluations'" ), 'C06 evidence must use the literal evaluation table.' );
foreach ( array( $repository_source, $evidence_source ) as $source_code ) {
	approval_s02_assert( ! str_contains( $source_code, '$wpdb->prefix' ), 'C06 must never use a WordPress table prefix.' );
	approval_s02_assert( ! str_contains( $source_code, 'wp_oj_ai_' ), 'C06 must never use a legacy prefixed table.' );
	approval_s02_assert( ! str_contains( $source_code, 'OJ\Platform\\' ), 'C06 must not depend on platform internals.' );
}

echo "G2-IMP-06-S02 passed: C06 exact approval, decision CAS, permission, idempotency, transaction, audit and outbox.\n";
