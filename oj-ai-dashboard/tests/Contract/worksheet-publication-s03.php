<?php
/**
 * G2-IMP-06-S03 C07 worksheet publication contract.
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
use OJAI\Dashboard\Worksheet\Authoring\Application\Create_Worksheet;
use OJAI\Dashboard\Worksheet\Authoring\Application\Create_Worksheet_Result;
use OJAI\Dashboard\Worksheet\Authoring\Application\Publish_Worksheet_Revision;
use OJAI\Dashboard\Worksheet\Authoring\Application\Publish_Worksheet_Revision_Command;
use OJAI\Dashboard\Worksheet\Authoring\Application\Publish_Worksheet_Revision_Result;
use OJAI\Dashboard\Worksheet\Authoring\Application\Read_Worksheets;
use OJAI\Dashboard\Worksheet\Authoring\Application\Save_Worksheet_Draft_Result;
use OJAI\Dashboard\Worksheet\Authoring\Application\Validate_Worksheet_Revision;
use OJAI\Dashboard\Worksheet\Authoring\Application\Validate_Worksheet_Revision_Command;
use OJAI\Dashboard\Worksheet\Authoring\Application\Worksheet_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Initial_Document_Factory;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Canonicalizer;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Id_Rewriter;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Profile;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Preview_Projector;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Projection_Safety_Inspector;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Publication_Projector;
use OJAI\Dashboard\Worksheet\Authoring\Infrastructure\WordPress_Worksheet_Publication_Repository;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Asset_Readiness_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Publication_Configuration_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Publication_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Presentation\Worksheet_Rest_Controller;

$addon_root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );

if ( ! class_exists( 'Normalizer' ) ) {
	/**
	 * G2-IMP-06-S03 C07 worksheet publication contract.
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
	 * G2-IMP-06-S03 C07 worksheet publication contract.
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
	 * G2-IMP-06-S03 C07 worksheet publication contract.
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

$GLOBALS['publication_s03_actor']  = 42;
$GLOBALS['publication_s03_routes'] = array();
if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Get current user id.
	 *
	 * @return int
	 */
	function get_current_user_id(): int {
		return (int) $GLOBALS['publication_s03_actor'];
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
		return 'publication-s03-nonce' === $nonce && 'wp_rest' === $action ? 1 : false;
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
		$GLOBALS['publication_s03_routes'][] = array( $namespace, $route, $arguments );
		return true;
	}
}

require_once $addon_root . '/src/Core/Autoloader.php';
( new Addon_Autoloader( $addon_root . '/src' ) )->register();

/**
 * Publication s03 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function publication_s03_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Publication s03 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function publication_s03_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * Publication s03 failure.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $reason Reason.
 * @param string           $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function publication_s03_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback(); } catch ( Worksheet_Failure $failure ) {
		publication_s03_same( $reason, $failure->reason(), $message );
		return;
		}
		throw new RuntimeException( $message );
}

/**
 * Publication s03 id.
 *
 * @param int $suffix Suffix.
 * @return string
 */
function publication_s03_id( int $suffix ): string {
	$id = '01K' . str_pad( (string) $suffix, 23, '0', STR_PAD_LEFT );
	publication_s03_assert( Public_Id::is_valid( $id ), 'S03 fixture ID must satisfy PublicIdV1.' );
	return $id;
}

/**
 * Publication s03 element.
 *
 * @param string                              $type Type.
 * @param int                                 $id Id.
 * @param array<string,mixed>                 $properties Properties.
 * @param array<int,array<string,mixed>>|null $children Children.
 * @return array<string,mixed>
 */
function publication_s03_element( string $type, int $id, array $properties = array(), ?array $children = null ): array {
	return array_merge(
		array(
			'type'     => $type,
			'node_id'  => publication_s03_id( $id ),
			'children' => $children ?? array( array( 'text' => '' ) ),
		),
		$properties
	);
}

/**
 * G2-IMP-06-S03 C07 worksheet publication contract.
 */
final class Publication_S03_Worksheet_Repository implements Worksheet_Repository_Port_Interface {
	/**
	 * Initialize publication s03 worksheet repository dependencies and state.
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
		return 42 === $actor_user_id && $revision_id === $this->source['revision']['id'] ? $this->source : null;
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
			'state'            => 'approved',
			'relation'         => 'owner',
		) : null;
	}
}

/**
 * G2-IMP-06-S03 C07 worksheet publication contract.
 */
final class Publication_S03_Assets implements Worksheet_Asset_Readiness_Port_Interface {
	/**
	 * Inspect.
	 *
	 * @param int               $actor_user_id Authenticated actor from the access context.
	 * @param string            $worksheet_id Worksheet public identifier.
	 * @param array<int,string> $asset_ids Asset ids.
	 * @return array
	 */
	public function inspect( int $actor_user_id, string $worksheet_id, array $asset_ids ): array {
		return array(
			'ready'      => true,
			'blockers'   => array(),
			'statistics' => array(
				'asset_count'       => count( $asset_ids ),
				'ready_asset_count' => count( $asset_ids ),
				'total_asset_bytes' => 0,
			),
		);
	}
}

/**
 * G2-IMP-06-S03 C07 worksheet publication contract.
 */
final class Publication_S03_Configuration implements Worksheet_Publication_Configuration_Port_Interface {
	/**
	 * Initialize publication s03 configuration dependencies and state.
	 *
	 * @param array<string,mixed> $configuration Configuration.
	 */
	public function __construct( public array $configuration ) {
	}
	/**
	 * Active.
	 *
	 * @return array
	 */
	public function active(): array {
		return $this->configuration;
	}
}

/**
 * G2-IMP-06-S03 C07 worksheet publication contract.
 */
final class Publication_S03_Repository implements Worksheet_Publication_Repository_Port_Interface {
	/**
	 * Records.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $records = array();
	/**
	 * Receipts.
	 *
	 * @var array<string,array{digest:string,result:Publish_Worksheet_Revision_Result}>
	 */
	private array $receipts = array();
	/**
	 * Initialize publication s03 repository dependencies and state.
	 *
	 * @param array<string,mixed> $approval Approval.
	 */
	public function __construct( public array $approval ) {
	}
	/**
	 * Find command receipt.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @return Publish_Worksheet_Revision_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_command_receipt( int $actor_user_id, string $key, string $digest ): ?Publish_Worksheet_Revision_Result {
		if ( ! isset( $this->receipts[ $key ] ) ) {
			return null;
		}
		$receipt = $this->receipts[ $key ];
		if ( ! hash_equals( $digest, $receipt['digest'] ) ) {
			throw Worksheet_Failure::idempotency_conflict();
		}
		$result = $receipt['result'];
		return new Publish_Worksheet_Revision_Result(
			$result->worksheet_id(),
			$result->revision_id(),
			$result->publication_id(),
			$result->publication_no(),
			$result->document_hash(),
			$result->approval_decision_hash(),
			$result->projection_manifest_version(),
			$result->publication_hash(),
			$result->profile_hashes(),
			$result->published_by_user_id(),
			$result->published_at_utc(),
			true
		);
	}
	/**
	 * Find approval evidence.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $worksheet_id Worksheet public identifier.
	 * @param string $revision_id Revision id.
	 * @return array|null
	 */
	public function find_approval_evidence( int $actor_user_id, string $worksheet_id, string $revision_id ): ?array {
		return 42 === $actor_user_id ? $this->approval : null;
	}
	/**
	 * Publish revision.
	 *
	 * @param array $record Record.
	 * @return Publish_Worksheet_Revision_Result
	 */
	public function publish_revision( array $record ): Publish_Worksheet_Revision_Result {
		$this->records[] = $record;
		$profile_hashes  = array();
		foreach ( $record['profiles'] as $name => $profile ) {
			$profile_hashes[ $name ] = $profile['hash'];
		}
		$result = new Publish_Worksheet_Revision_Result(
			$record['worksheet_public_id'],
			$record['revision_public_id'],
			$record['publication_public_id'],
			count( $this->records ),
			$record['expected_document_hash'],
			$record['approval_decision_hash'],
			$record['projection_manifest_version'],
			$record['publication_hash'],
			$profile_hashes,
			$record['actor_user_id'],
			$record['published_at_utc'],
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
 * G2-IMP-06-S03 C07 worksheet publication contract.
 */
final class Publication_S03_Policy implements Access_Policy_Port_Interface {
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
			|| Worksheet_Rest_Controller::ACTION_PUBLISH !== $request->action_key()
			|| array( Addon_Capabilities::MANAGE_WORKSHEETS ) !== $request->required_capabilities()
			|| ! $object instanceof Object_Scope || Object_Scope::TYPE_WORKSHEET_REVISION !== $object->type()
			|| 'edit' !== $object->action()
		) {
			return Access_Policy_Decision::denied( 403, 'publication_s03_denied', $request->correlation_id() );
		}
		return Access_Policy_Decision::allowed( $request->correlation_id(), null, Object_Authorization_Result::allowed( $object->id(), 'approved', 'owner' ) );
	}
}

/**
 * G2-IMP-06-S03 C07 worksheet publication contract.
 */
final class Publication_S03_Request {
	/**
	 * Headers.
	 *
	 * @var array<string,string>
	 */
	private array $headers = array();
	/**
	 * Initialize publication s03 request dependencies and state.
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

$canonical               = new Canonical_Json();
$ids                     = new Public_Id_Generator();
$worksheet_id            = publication_s03_id( 1 );
$revision_id             = publication_s03_id( 2 );
$response_id             = publication_s03_id( 3 );
$response_criterion_id   = publication_s03_id( 4 );
$submission_criterion_id = publication_s03_id( 5 );
$teacher_document        = array(
	'schema_id'      => Worksheet_Document_Profile::SCHEMA_ID,
	'schema_version' => Worksheet_Document_Profile::SCHEMA_VERSION,
	'editor_profile' => Worksheet_Document_Profile::EDITOR_PROFILE,
	'document_id'    => publication_s03_id( 6 ),
	'locale'         => 'ko-KR',
	'title'          => 'Publication fixture',
	'nodes'          => array(
		publication_s03_element( 'h1', 10, array(), array( array( 'text' => 'Publication fixture' ) ) ),
		publication_s03_element(
			'oj_ai_response',
			11,
			array(
				'response_id' => $response_id,
				'kind'        => 'long_text',
				'label'       => 'Explain your reasoning.',
				'required'    => true,
				'constraints' => array(
					'min_chars' => 10,
					'max_chars' => 1000,
				),
				'grading'     => array(
					'grading_enabled'      => true,
					'max_points'           => '5',
					'example_answer'       => 'A reasoned answer.',
					'rubric_criterion_ids' => array( $response_criterion_id ),
				),
			)
		),
	),
	'settings'       => array(
		'student_navigation' => 'document',
		'print_profile'      => 'worksheet_a4@1',
	),
);
$documents               = new Worksheet_Document_Canonicalizer( $canonical, $ids );
$document                = $documents->canonicalize( $teacher_document );
$source                  = array(
	'worksheet_id'     => $worksheet_id,
	'revision'         => array(
		'id'              => $revision_id,
		'state'           => 'approved',
		'document_hash'   => $document->document_hash(),
		'validation_hash' => $document->validation_hash(),
	),
	'teacher_document' => $document->document(),
	'questions'        => array(),
);
$configuration           = array(
	'guard'       => array(
		'policy_content_hash'      => hash( 'sha256', 'publication-policy' ),
		'policy_revision_id'       => publication_s03_id( 20 ),
		'review_rule_content_hash' => hash( 'sha256', 'publication-review' ),
		'review_rule_revision_id'  => publication_s03_id( 21 ),
		'rubric_content_hash'      => hash( 'sha256', 'publication-rubric' ),
		'rubric_revision_id'       => publication_s03_id( 22 ),
	),
	'policy'      => array(
		'content_hash' => hash( 'sha256', 'publication-policy' ),
		'revision_id'  => publication_s03_id( 20 ),
		'subject_type' => 'student_submission',
	),
	'review_rule' => array(
		'content_hash'              => hash( 'sha256', 'publication-review' ),
		'explicit_release_required' => true,
		'review_required'           => true,
		'revision_id'               => publication_s03_id( 21 ),
	),
	'rubric'      => array(
		'content_hash' => hash( 'sha256', 'publication-rubric' ),
		'criteria'     => array(
			array(
				'criterion_id'   => $response_criterion_id,
				'label'          => 'Response quality',
				'maximum_points' => '5',
				'position'       => 0,
				'scope'          => 'response',
			),
			array(
				'criterion_id'   => $submission_criterion_id,
				'label'          => 'Overall completeness',
				'maximum_points' => '2',
				'position'       => 1,
				'scope'          => 'submission',
			),
		),
		'revision_id'  => publication_s03_id( 22 ),
		'total_points' => '7',
	),
);

$previews         = new Worksheet_Preview_Projector( $canonical );
$safety           = new Worksheet_Projection_Safety_Inspector( $canonical );
$projector        = new Worksheet_Publication_Projector( $canonical, $previews, $safety );
$projection       = $projector->project( $document, $configuration );
$projection_again = $projector->project( $document, $configuration );
publication_s03_same( $projection->publication_hash(), $projection_again->publication_hash(), 'C07 publication hash must be deterministic.' );
publication_s03_same( '1.0.0', $projection->manifest_version(), 'C07 manifest version drifted.' );
publication_s03_same(
	array( 'grading_schema', 'print_projection', 'response_schema', 'student_document' ),
	array_keys( $projection->profiles() ),
	'C07 must emit exactly P01, P02, P03 and P05.'
);
$grading = $projection->profile( 'grading_schema' );
publication_s03_same( 'oj_ai.grading_schema', $grading['schema_id'], 'P03 schema ID drifted.' );
publication_s03_same( '1.1.0', $grading['schema_version'], 'P03 schema version drifted.' );
publication_s03_same( Worksheet_Publication_Projector::GRADING_PROJECTOR_VERSION, $grading['projector_version'], 'P03 grading projector version drifted.' );
$bindings = $grading['payload']['bindings'];
publication_s03_same( 2, count( $bindings ), 'P03 must bind every rubric criterion exactly once.' );
publication_s03_same( $response_id, $bindings[0]['response_id'], 'Response-scoped criterion must pin the P01/P02 response ID.' );
publication_s03_same( null, $bindings[1]['response_id'], 'Submission-scoped criterion must use a null response ID.' );
publication_s03_same( 'submission', $bindings[1]['scope'], 'Submission-scoped criterion drifted.' );
$expected_response_binding   = hash(
	'sha256',
	$canonical->encode(
		array(
			'criterion_id'         => $response_criterion_id,
			'response_id'          => $response_id,
			'rubric_hash'          => $configuration['rubric']['content_hash'],
			'scope'                => 'response',
			'source_revision_hash' => $document->document_hash(),
		)
	)
);
$expected_submission_binding = hash(
	'sha256',
	$canonical->encode(
		array(
			'criterion_id'         => $submission_criterion_id,
			'response_id'          => null,
			'rubric_hash'          => $configuration['rubric']['content_hash'],
			'scope'                => 'submission',
			'source_revision_hash' => $document->document_hash(),
		)
	)
);
publication_s03_same( $expected_response_binding, $bindings[0]['binding_key'], 'Response binding must pin source, rubric, scope, response and criterion identity.' );
publication_s03_same( $expected_submission_binding, $bindings[1]['binding_key'], 'Submission binding must pin source, rubric, scope, null response and criterion identity.' );
$unused_criterion_id                                        = publication_s03_id( 7 );
$scoped_teacher_document                                    = $teacher_document;
$scoped_teacher_document['settings']['assessment_bindings'] = array(
	array(
		'criterion_id' => $response_criterion_id,
		'scope'        => 'worksheet',
		'response_id'  => null,
	),
	array(
		'criterion_id' => $submission_criterion_id,
		'scope'        => 'response',
		'response_id'  => $response_id,
	),
);
$scoped_document                                = $documents->canonicalize( $scoped_teacher_document );
$scoped_configuration                           = $configuration;
$scoped_configuration['rubric']['criteria'][]   = array(
	'criterion_id'   => $unused_criterion_id,
	'label'          => 'Unused global criterion',
	'maximum_points' => '3',
	'position'       => 2,
	'scope'          => 'submission',
);
$scoped_configuration['rubric']['total_points'] = '10';
$scoped_projection                              = $projector->project( $scoped_document, $scoped_configuration );
$scoped_grading                                 = $scoped_projection->profile( 'grading_schema' )['payload'];
publication_s03_same( 2, count( $scoped_grading['bindings'] ), 'P03 must include only criteria selected by this worksheet.' );
publication_s03_same( '7', $scoped_grading['rubric']['total_points'], 'P03 total must include only selected worksheet criteria.' );
publication_s03_same( $response_criterion_id, $scoped_grading['bindings'][0]['criterion_id'], 'Worksheet selection order must remain stable.' );
publication_s03_same( 'submission', $scoped_grading['bindings'][0]['scope'], 'A selected criterion must support worksheet-wide targeting.' );
publication_s03_same( null, $scoped_grading['bindings'][0]['response_id'], 'Worksheet-wide targeting must not carry a response ID.' );
publication_s03_same( $submission_criterion_id, $scoped_grading['bindings'][1]['criterion_id'], 'The selected response criterion identity drifted.' );
publication_s03_same( 'response', $scoped_grading['bindings'][1]['scope'], 'A selected criterion must support response targeting.' );
publication_s03_same( $response_id, $scoped_grading['bindings'][1]['response_id'], 'Response targeting must pin the selected worksheet response.' );
publication_s03_assert(
	! in_array( $unused_criterion_id, array_column( $scoped_grading['bindings'], 'criterion_id' ), true ),
	'Unselected global criteria must not leak into a worksheet publication.'
);
$public_profiles = $canonical->encode( array( $projection->profile( 'student_document' ), $projection->profile( 'response_schema' ) ) );
foreach ( array( 'grading', 'correct_answer', 'example_answer', 'rubric_criterion_ids', 'teacher_note' ) as $forbidden ) {
	publication_s03_assert( ! str_contains( $public_profiles, '"' . $forbidden . '"' ), 'P01/P02 leaked private field: ' . $forbidden );
}
$bad_configuration                           = $configuration;
$bad_configuration['rubric']['total_points'] = '6';
try {
	$projector->project( $document, $bad_configuration );
	throw new RuntimeException( 'P03 total drift must block publication.' );
} catch ( InvalidArgumentException ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The fixture intentionally contains this expected or cleanup failure.
	// Expected fail-closed projection rejection.
}

$worksheets = new Publication_S03_Worksheet_Repository( $source );
$validator  = new Validate_Worksheet_Revision(
	$worksheets,
	$documents,
	new Worksheet_Document_Id_Rewriter(),
	new Publication_S03_Assets(),
	$canonical,
	static fn (): string => '2026-08-21 20:00:00',
	$previews,
	$safety
);
$validation = $validator->execute(
	new Validate_Worksheet_Revision_Command(
		42,
		$revision_id,
		'publication-s03-validation-1',
		'approved',
		$revision_id,
		hash( 'sha256', 'publication-s03-validation-key' ),
		'publication-s03-validation-correlation',
		$canonical
	)
);
publication_s03_same( true, $validation->release_ready(), 'C07 fixture must pass exact approved-revision validation.' );
$approval_decision_hash = hash( 'sha256', 'publication-approval-decision' );
$publication_repository = new Publication_S03_Repository(
	array(
		'approval_decision_hash'     => $approval_decision_hash,
		'approval_evidence_hash'     => hash( 'sha256', 'publication-approval-evidence' ),
		'document_hash'              => $document->document_hash(),
		'guard'                      => array(
			'audit_public_id' => publication_s03_id( 30 ),
			'event_hash'      => hash( 'sha256', 'publication-approval-event' ),
			'metadata_hash'   => hash( 'sha256', 'publication-approval-metadata' ),
		),
		'validation_projection_hash' => $validation->projection_hash(),
	)
);
$application            = new Publish_Worksheet_Revision(
	$worksheets,
	$publication_repository,
	new Publication_S03_Configuration( $configuration ),
	$validator,
	$documents,
	$projector,
	new Worksheet_Document_Id_Rewriter(),
	$ids,
	$canonical,
	static fn (): string => '2026-08-21 20:05:00'
);
$readiness              = $application->readiness( 42, $validation );
publication_s03_same( true, $readiness['approval_ready'], 'C05 approved-revision readiness must reuse stored C06 evidence.' );
publication_s03_same( 'approved', $readiness['status'], 'An exact approved revision must be publication-ready.' );
publication_s03_same( $approval_decision_hash, $readiness['decision_hash'], 'C05 must expose the exact stored decision hash required by C07.' );
$raw_idempotency_key = 'publication-s03-rest-key-1';
$command             = new Publish_Worksheet_Revision_Command(
	42,
	$revision_id,
	'publication-s03-command-1',
	'approved',
	$revision_id,
	$document->document_hash(),
	$approval_decision_hash,
	hash( 'sha256', $raw_idempotency_key ),
	'publication-s03-command-correlation',
	$canonical
);
$result              = $application->execute( $command );
publication_s03_same( 'active', $result->publication_state(), 'C07 must create an active immutable publication.' );
publication_s03_same( $projection->publication_hash(), $result->publication_hash(), 'C07 must persist the deterministic publication hash.' );
publication_s03_same( 1, count( $publication_repository->records ), 'C07 must write one publication transaction record.' );
publication_s03_same( 'approved', $publication_repository->records[0]['expected_state'], 'C07 must bind the approved state.' );
publication_s03_same( $validation->projection_hash(), $publication_repository->records[0]['validation_projection_hash'], 'C07 must pin exact validation evidence.' );
publication_s03_same( array(), $publication_repository->records[0]['asset_public_ids'], 'C07 must pin the exact referenced asset set.' );

$replay = $application->execute( $command );
publication_s03_same( true, $replay->replayed(), 'Exact C07 retry must replay the durable receipt before source reads.' );
publication_s03_same( 1, count( $publication_repository->records ), 'Exact C07 retry must not append another publication.' );
$stale_command = new Publish_Worksheet_Revision_Command(
	42,
	$revision_id,
	'publication-s03-command-2',
	'approved',
	$revision_id,
	$document->document_hash(),
	hash( 'sha256', 'stale-publication-decision' ),
	hash( 'sha256', 'publication-s03-key-2' ),
	'publication-s03-command-correlation-2',
	$canonical
);
publication_s03_failure(
	static fn () => $application->execute( $stale_command ),
	Worksheet_Failure::PUBLICATION_CONFLICT,
	'Stale C06 decision evidence must block C07 with a safe conflict.'
);

$policy       = new Publication_S03_Policy();
$sequence     = 0;
$correlations = new Correlation_Id_Factory(
	static function () use ( &$sequence ): string {
		return 'publication-s03-rest-' . str_pad( (string) ++$sequence, 3, '0', STR_PAD_LEFT );
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
	null,
	$application
);
$controller->register_routes();
$controller->register_routes();
$routes = array_values( array_filter( $GLOBALS['publication_s03_routes'], static fn ( array $route ): bool => Worksheet_Rest_Controller::ROUTE_PUBLISH === $route[1] ) );
publication_s03_same( 1, count( $routes ), 'C07 REST route must register exactly once.' );
$body    = array(
	'command_id' => 'publication-s03-command-1',
	'expected'   => array(
		'expected_state'                  => 'approved',
		'expected_revision_id'            => $revision_id,
		'expected_document_hash'          => $document->document_hash(),
		'expected_approval_decision_hash' => $approval_decision_hash,
	),
	'payload'    => array(),
);
$request = new Publication_S03_Request(
	array(
		'X-WP-Nonce'      => 'publication-s03-nonce',
		'Idempotency-Key' => $raw_idempotency_key,
	),
	$body,
	$revision_id
);
$route   = $routes[0][2];
publication_s03_same( true, ( $route['permission_callback'] )( $request ), 'C07 owner manage permission and nonce must pass.' );
$response = ( $route['callback'] )( $request );
publication_s03_assert( $response instanceof WP_REST_Response, 'C07 success must use the common REST envelope.' );
publication_s03_same( 201, $response->get_status(), 'C07 must return 201 for the immutable publication resource.' );
publication_s03_same( true, $response->get_data()['meta']['idempotent_replay'], 'C07 REST retry must expose durable replay.' );
publication_s03_same( 0, $response->get_data()['meta']['mutation_count'], 'C07 REST retry must perform zero mutations.' );
publication_s03_same( $result->publication_id(), $response->get_data()['data']['publication']['id'], 'C07 REST publication identity drifted.' );
publication_s03_same( Worksheet_Rest_Controller::ACTION_PUBLISH, $policy->last?->action_key(), 'C07 permission action drifted.' );

$invalid = new Publication_S03_Request(
	array(
		'X-WP-Nonce'      => 'publication-s03-nonce',
		'Idempotency-Key' => 'publication-s03-rest-key-2',
	),
	array_replace( $body, array( 'payload' => array( 'unexpected' => true ) ) ),
	$revision_id
);
publication_s03_same( true, ( $route['permission_callback'] )( $invalid ), 'C07 policy must run before body validation.' );
$invalid_response = ( $route['callback'] )( $invalid );
publication_s03_assert( $invalid_response instanceof WP_Error, 'Invalid C07 body must use the common problem envelope.' );
publication_s03_same( 422, $invalid_response->get_error_data()['status'], 'Invalid C07 body must return 422.' );

$infrastructure_repository = new WordPress_Worksheet_Publication_Repository( $canonical );
$approval_parser           = new ReflectionMethod( $infrastructure_repository, 'validated_approval_evidence_from_row' );
publication_s03_failure(
	static fn () => $approval_parser->invoke(
		$infrastructure_repository,
		array(
			'action_key'       => 'worksheet.revision_approved',
			'object_type'      => 'worksheet_revision',
			'object_public_id' => $revision_id,
			'metadata_schema'  => 'oj_ai.audit_metadata',
			'metadata_version' => 'invalid',
		),
		$revision_id
	),
	Worksheet_Failure::PUBLICATION_APPROVAL_UNAVAILABLE,
	'Malformed approval evidence must use the stable publication approval failure.'
);

$repository_source    = file_get_contents( $addon_root . '/src/Worksheet/Authoring/Infrastructure/WordPress_Worksheet_Publication_Repository.php' );
$configuration_source = file_get_contents( $addon_root . '/src/Worksheet/Authoring/Infrastructure/WordPress_Worksheet_Publication_Configuration.php' );
publication_s03_assert( is_string( $repository_source ) && is_string( $configuration_source ), 'C07 infrastructure source must be readable.' );
foreach ( array( 'START TRANSACTION', 'FOR UPDATE', 'oj_ai_worksheet_publications', 'latest_publication_public_id', 'worksheet.revision_published', 'oj_ai_audit_events', 'oj_ai_outbox_events' ) as $marker ) {
	publication_s03_assert( str_contains( $repository_source, $marker ), 'C07 transaction marker is missing: ' . $marker );
}
publication_s03_assert( ! str_contains( $repository_source, "publication_conflict( str_repeat( '0', 64 )" ), 'C07 CAS conflicts must preserve the current safe hashes.' );
publication_s03_assert( ! str_contains( $repository_source, 'UPDATE \' . self::TABLE_REVISIONS' ), 'C07 must not mutate an approved revision.' );
foreach ( array( $repository_source, $configuration_source ) as $source_code ) {
	publication_s03_assert( ! str_contains( $source_code, '$wpdb->prefix' ), 'C07 must never use a WordPress table prefix.' );
	publication_s03_assert( ! str_contains( $source_code, 'wp_oj_ai_' ), 'C07 must never use a legacy prefixed table.' );
	publication_s03_assert( ! str_contains( $source_code, 'OJ\Platform\\' ), 'C07 must not depend on platform internals.' );
}

echo "G2-IMP-06-S03 passed: C07 deterministic P01/P02/P03/P05 publication, exact approval evidence, permission, idempotency, transaction, audit and outbox.\n";
