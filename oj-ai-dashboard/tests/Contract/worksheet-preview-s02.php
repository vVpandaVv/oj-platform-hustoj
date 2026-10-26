<?php
/**
 * G2-IMP-04-S02 student and print preview contract.
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
use OJAI\Dashboard\Worksheet\Authoring\Application\Create_Worksheet;
use OJAI\Dashboard\Worksheet\Authoring\Application\Create_Worksheet_Result;
use OJAI\Dashboard\Worksheet\Authoring\Application\Read_Worksheet_Preview;
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
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Asset_Readiness_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Presentation\Worksheet_Rest_Controller;

$addon_root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * G2-IMP-04-S02 student and print preview contract.
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
	 * G2-IMP-04-S02 student and print preview contract.
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
if ( ! class_exists( 'Normalizer' ) ) {
	/**
	 * G2-IMP-04-S02 student and print preview contract.
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
			return $value;
		}
	}
}

$GLOBALS['s18_actor']  = 42;
$GLOBALS['s18_routes'] = array();
if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Get current user id.
	 *
	 * @return int
	 */
	function get_current_user_id(): int {
		return (int) $GLOBALS['s18_actor'];
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
		return 's18-valid-nonce' === $nonce && 'wp_rest' === $action ? 1 : false;
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
		$GLOBALS['s18_routes'][] = array( $namespace, $route, $arguments );
		return true;
	}
}

require_once $addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core'
	. DIRECTORY_SEPARATOR . 'Autoloader.php';
( new AddonAutoloader( $addon_root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * S18 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function s18_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * S18 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function s18_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * S18 failure.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $message Message.
 * @return Throwable
 * @throws \Throwable Propagates validation or dependency failures.
 */
function s18_failure( callable $callback, string $message ): Throwable {
	try {
		$callback();
	} catch ( Throwable $failure ) {
		return $failure;
	}
	throw new RuntimeException( $message );
}

/**
 * S18 id.
 *
 * @param int $suffix Suffix.
 * @return string
 */
function s18_id( int $suffix ): string {
	$id = '01K' . str_pad( (string) $suffix, 23, '0', STR_PAD_LEFT );
	s18_assert( Public_Id::is_valid( $id ), 'S02 fixture ID must be PublicIdV1.' );
	return $id;
}

/**
 * S18 element.
 *
 * @param string                              $type Type.
 * @param int                                 $id Id.
 * @param array<string,mixed>                 $properties Properties.
 * @param array<int,array<string,mixed>>|null $children Children.
 * @return array<string,mixed>
 */
function s18_element( string $type, int $id, array $properties = array(), ?array $children = null ): array {
	return array_merge(
		array(
			'type'     => $type,
			'node_id'  => s18_id( $id ),
			'children' => $children ?? array( array( 'text' => '' ) ),
		),
		$properties
	);
}

/**
 * G2-IMP-04-S02 student and print preview contract.
 */
final class S18_Repository implements Worksheet_Repository_Port_Interface {
	/**
	 * Initialize s18 repository dependencies and state.
	 *
	 * @param array<string,mixed>|null $source Source.
	 */
	public function __construct( public ?array $source ) {
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
		if ( 42 !== $actor_user_id || ! is_array( $this->source ) ) {
			return null;
		}
		return $revision_id === (string) ( $this->source['revision']['id'] ?? '' ) ? $this->source : null;
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
		return array(
			'current_revision' => $object_id,
			'state'            => 'draft',
			'relation'         => 'owner',
		);
	}
}

/**
 * G2-IMP-04-S02 student and print preview contract.
 */
final class S18_Asset_Readiness implements Worksheet_Asset_Readiness_Port_Interface {
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
				'total_asset_bytes' => 1200,
			),
		);
	}
}

/**
 * G2-IMP-04-S02 student and print preview contract.
 */
final class S18_Policy implements Access_Policy_Port_Interface {
	/**
	 * Last request.
	 *
	 * @var Access_Policy_Request|null
	 */
	public ?Access_Policy_Request $last_request = null;
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
			42 !== $request->actor_user_id() || ! $request->request_integrity_verified()
			|| array( Addon_Capabilities::VIEW_WORKSHEETS ) !== $request->required_capabilities()
			|| ! $object instanceof Object_Scope
			|| Object_Scope::TYPE_WORKSHEET_REVISION !== $object->type()
			|| 'view' !== $object->action()
		) {
			return Access_Policy_Decision::denied( 403, 's18_denied', $request->correlation_id() );
		}
		return Access_Policy_Decision::allowed(
			$request->correlation_id(),
			null,
			Object_Authorization_Result::allowed( $object->id(), 'draft', 'owner' )
		);
	}
}

/**
 * G2-IMP-04-S02 student and print preview contract.
 */
final class S18_Request {
	/**
	 * Headers.
	 *
	 * @var array<string,string>
	 */
	private array $headers = array();
	/**
	 * Initialize s18 request dependencies and state.
	 *
	 * @param string               $method Method.
	 * @param array<string,string> $headers Headers.
	 * @param array<string,mixed>  $query Query.
	 * @param array<string,mixed>  $url Url.
	 */
	public function __construct( private string $method, array $headers, private array $query, private array $url ) {
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
		return $this->method;
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
	 * @return array
	 */
	public function get_json_params(): array {
		return array();
	}
	/**
	 * Get url params.
	 *
	 * @return array
	 */
	public function get_url_params(): array {
		return $this->url;
	}
	/**
	 * Get query params.
	 *
	 * @return array
	 */
	public function get_query_params(): array {
		return $this->query;
	}
}

$worksheet_id     = s18_id( 1 );
$revision_id      = s18_id( 2 );
$asset_id         = s18_id( 3 );
$response_one     = s18_id( 20 );
$response_two     = s18_id( 21 );
$option_one       = s18_id( 30 );
$option_two       = s18_id( 31 );
$rubric_id        = s18_id( 40 );
$teacher_document = array(
	'schema_id'      => Worksheet_Document_Profile::SCHEMA_ID,
	'schema_version' => Worksheet_Document_Profile::SCHEMA_VERSION,
	'editor_profile' => Worksheet_Document_Profile::EDITOR_PROFILE,
	'document_id'    => s18_id( 4 ),
	'locale'         => 'ko-KR',
	'title'          => '학생 안전 미리보기',
	'nodes'          => array(
		s18_element(
			'h1',
			10,
			array(),
			array(
				array(
					'text' => '학생 안전 미리보기',
					'bold' => true,
				),
			)
		),
		s18_element( 'p', 11, array(), array( array( 'text' => '질문을 읽고 답하세요.' ) ) ),
		s18_element(
			'img',
			12,
			array(
				'asset_id' => $asset_id,
				'alt'      => '관찰 그림',
				'width'    => 80,
			)
		),
		s18_element(
			'oj_ai_response',
			13,
			array(
				'response_id' => $response_one,
				'kind'        => 'single_choice',
				'label'       => '가장 알맞은 답',
				'required'    => true,
				'help_text'   => '하나만 고르세요.',
				'constraints' => array(
					'options' => array(
						array(
							'option_id' => $option_one,
							'value'     => 'A',
							'label'     => '첫 번째',
						),
						array(
							'option_id' => $option_two,
							'value'     => 'B',
							'label'     => '두 번째',
						),
					),
				),
				'grading'     => array(
					'grading_enabled'      => true,
					'max_points'           => '5',
					'correct_answer'       => $option_two,
					'example_answer'       => $option_two,
					'rubric_criterion_ids' => array( $rubric_id ),
					'teacher_note'         => '학생에게 보이면 안 되는 메모',
				),
			)
		),
		s18_element( 'page_break', 14 ),
		s18_element(
			'oj_ai_response',
			15,
			array(
				'response_id' => $response_two,
				'kind'        => 'long_text',
				'label'       => '생각을 설명하세요.',
				'required'    => false,
				'constraints' => array(
					'min_chars' => 10,
					'max_chars' => 1000,
				),
				'grading'     => array( 'example_answer' => '교사용 예시 답안' ),
			)
		),
	),
	'settings'       => array(
		'student_navigation' => 'document',
		'print_profile'      => 'worksheet_a4@1',
	),
);

$canonical     = new Canonical_Json();
$ids           = new Public_Id_Generator( static fn ( int $length ): string => str_repeat( "\x18", $length ) );
$receipt       = ( new Worksheet_Document_Canonicalizer( $canonical, $ids ) )->canonicalize( $teacher_document );
$source        = array(
	'worksheet_id'     => $worksheet_id,
	'revision'         => array(
		'id'              => $revision_id,
		'state'           => 'draft',
		'document_hash'   => $receipt->document_hash(),
		'validation_hash' => $receipt->validation_hash(),
	),
	'teacher_document' => $receipt->document(),
	'questions'        => array(),
);
$repository    = new S18_Repository( $source );
$projector     = new Worksheet_Preview_Projector( $canonical );
$student       = $projector->student( $receipt );
$student_again = $projector->student( $receipt );
$student_json  = $canonical->encode( $student->projection() );
s18_same( $student->projection_hash(), $student_again->projection_hash(), 'Student projection hash must be deterministic.' );
s18_same( 'student', $student->kind(), 'Student preview kind drifted.' );
s18_assert( str_contains( $student_json, $response_one ) && str_contains( $student_json, $response_two ), 'P01 and P02 must preserve stable response IDs.' );
s18_assert( str_contains( $student_json, $asset_id ), 'Student projection must retain private asset ID references.' );
foreach ( array( 'grading', 'correct_answer', 'example_answer', 'teacher_note', 'max_points', 'rubric_criterion_ids' ) as $forbidden ) {
	s18_assert( ! str_contains( $student_json, '"' . $forbidden . '"' ), 'Student projection leaked forbidden key: ' . $forbidden );
}
s18_assert( ! str_contains( $student_json, 'https://' ) && ! str_contains( $student_json, 'blob:' ) && ! str_contains( $student_json, 'data:' ), 'Student projection must not contain asset URLs.' );
s18_same( Worksheet_Preview_Projector::STUDENT_DOCUMENT_SCHEMA, $student->projection()['student_document']['schema_id'], 'P01 schema drifted.' );
s18_same( Worksheet_Preview_Projector::RESPONSE_SCHEMA, $student->projection()['response_schema']['schema_id'], 'P02 schema drifted.' );
s18_same( 2, count( $student->projection()['response_schema']['payload']['items'] ), 'P02 response count drifted.' );

foreach ( Worksheet_Preview_Projector::PRINT_PROFILES as $profile ) {
	$print      = $projector->print( $receipt, $profile );
	$print_json = $canonical->encode( $print->projection() );
	s18_same( $profile, $print->profile(), 'P05 profile drifted.' );
	s18_same( Worksheet_Preview_Projector::PRINT_SCHEMA, $print->projection()['print_projection']['schema_id'], 'P05 schema drifted.' );
	s18_assert( str_contains( $print_json, 'page_break' ), 'P05 must preserve explicit page breaks.' );
	s18_assert( ! str_contains( $print_json, '"grading"' ) && ! str_contains( $print_json, '"teacher_note"' ), 'P05 blank preview leaked private grading.' );
	s18_same( $print->projection_hash(), $projector->print( $receipt, $profile )->projection_hash(), 'P05 hash must be deterministic.' );
}
$invalid_profile = s18_failure( static fn () => $projector->print( $receipt, 'answer_key' ), 'Unknown print profile must fail.' );
s18_assert( $invalid_profile instanceof InvalidArgumentException, 'Unknown print profile must fail at the domain boundary.' );

$reader       = new Read_Worksheet_Preview(
	$repository,
	new Worksheet_Document_Canonicalizer( $canonical, $ids ),
	$projector
);
$read_student = $reader->read( 42, $revision_id, 'student', 'student' );
s18_same( $student->projection_hash(), $read_student['preview']['projection_hash'], 'Q08 Application must return the exact domain projection.' );
$read_print = $reader->read( 42, $revision_id, 'print', 'teacher_blank' );
s18_same( 'teacher_blank', $read_print['preview']['profile'], 'Q08 Application print profile drifted.' );
$repository->source['revision']['document_hash'] = hash( 'sha256', 'tampered' );
$tampered                                        = s18_failure( static fn () => $reader->read( 42, $revision_id, 'student', 'student' ), 'Tampered Q08 source must fail.' );
s18_assert( $tampered instanceof Worksheet_Failure, 'Tampered Q08 source must use stable Worksheet failure.' );
s18_same( Worksheet_Failure::STORED_DATA_INVALID, $tampered->reason(), 'Tampered Q08 source must fail closed.' );
$repository->source = $source;

$validator  = new Validate_Worksheet_Revision(
	$repository,
	new Worksheet_Document_Canonicalizer( $canonical, $ids ),
	new Worksheet_Document_Id_Rewriter(),
	new S18_Asset_Readiness(),
	$canonical,
	static fn (): string => '2026-08-21 15:00:00',
	$projector
);
$validation = $validator->execute(
	new Validate_Worksheet_Revision_Command(
		42,
		$revision_id,
		'command-s18-validate-0001',
		'draft',
		$revision_id,
		hash( 'sha256', 's18-idempotency' ),
		's18-validation-correlation',
		$canonical
	)
);
s18_same( 'passed', $validation->checks()[4]['status'], 'C05 must mark student projection passed after S02.' );
s18_same( 'passed', $validation->checks()[5]['status'], 'C05 must mark print projection passed after S02.' );
s18_same( 'pending', $validation->checks()[6]['status'], 'Renderer parity must remain pending until S03.' );
s18_same( 'pending', $validation->checks()[7]['status'], 'Private field scan must remain pending until S03.' );
s18_same( false, $validation->release_ready(), 'S02 must remain fail-closed before S03.' );
s18_same( 2, $validation->statistics()['preview_projection_count'], 'C05 must count the two S02 projections.' );

$policy       = new S18_Policy();
$sequence     = 0;
$correlations = new Correlation_Id_Factory(
	static function () use ( &$sequence ): string {
		return 's18-rest-correlation-' . str_pad( (string) ++$sequence, 3, '0', STR_PAD_LEFT );
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
	new Create_Worksheet( $repository, $ids, new Initial_Document_Factory( $ids, $canonical ) ),
	new Read_Worksheets( $repository ),
	$canonical,
	$envelopes,
	$correlations,
	null,
	null,
	$validator,
	$reader
);
$controller->register_routes();
$controller->register_routes();
$preview_routes = array_values(
	array_filter( $GLOBALS['s18_routes'], static fn ( array $route ): bool => Worksheet_Rest_Controller::ROUTE_PREVIEW === $route[1] )
);
s18_same( 1, count( $preview_routes ), 'Q08 route must register exactly once.' );
$route   = $preview_routes[0][2];
$request = new S18_Request(
	'GET',
	array( 'X-WP-Nonce' => 's18-valid-nonce' ),
	array( 'projection' => 'student' ),
	array( 'revision_id' => $revision_id )
);
s18_same( true, ( $route['permission_callback'] )( $request ), 'Q08 must accept owner view + nonce.' );
$response = ( $route['callback'] )( $request );
s18_assert( $response instanceof WP_REST_Response, 'Q08 success must use the common response envelope.' );
s18_same( 200, $response->get_status(), 'Q08 must return 200.' );
$response_data = $response->get_data();
s18_same( 'student', $response_data['data']['preview']['kind'], 'Q08 REST student kind drifted.' );
s18_same( 0, $response_data['meta']['mutation_count'], 'Q08 must be mutation-free.' );
s18_same( 0, $response_data['meta']['private_asset_urls'], 'Q08 must not issue private asset URLs.' );
s18_same( Worksheet_Rest_Controller::ACTION_PREVIEW, $policy->last_request?->action_key(), 'Q08 permission action drifted.' );

$print_request = new S18_Request(
	'GET',
	array( 'X-WP-Nonce' => 's18-valid-nonce' ),
	array( 'projection' => 'print' ),
	array( 'revision_id' => $revision_id )
);
s18_same( true, ( $route['permission_callback'] )( $print_request ), 'Q08 print permission must pass.' );
$print_response = ( $route['callback'] )( $print_request );
s18_same( 'student_blank', $print_response->get_data()['data']['preview']['profile'], 'Q08 print default profile drifted.' );

foreach (
	array(
		array( 'projection' => 'teacher' ),
		array(
			'projection' => 'student',
			'profile'    => 'teacher_blank',
		),
		array(
			'projection' => 'print',
			'profile'    => 'answer_key',
		),
		array(
			'projection' => 'student',
			'unexpected' => 'value',
		),
	) as $invalid_query
) {
	$invalid_request = new S18_Request(
		'GET',
		array( 'X-WP-Nonce' => 's18-valid-nonce' ),
		$invalid_query,
		array( 'revision_id' => $revision_id )
	);
	s18_same( true, ( $route['permission_callback'] )( $invalid_request ), 'Q08 policy must run before query validation.' );
	$invalid_response = ( $route['callback'] )( $invalid_request );
	s18_assert( $invalid_response instanceof WP_Error, 'Invalid Q08 query must use the common problem envelope.' );
	s18_same( 422, $invalid_response->get_error_data()['status'], 'Invalid Q08 query must return 422.' );
}

$projector_source = file_get_contents( $addon_root . '/src/Worksheet/Authoring/Domain/Worksheet_Preview_Projector.php' );
s18_assert( is_string( $projector_source ), 'Q08 projector source must be readable.' );
s18_assert( ! str_contains( $projector_source, '$wpdb->prefix' ), 'Q08 must never use a WordPress table prefix.' );
s18_assert( ! str_contains( $projector_source, 'unset(' ), 'Q08 must build allowlisted trees instead of deleting private keys.' );

echo "G2-IMP-04-S02 passed: Q08 exact student/print projections, allowlisted response schema, deterministic hashes, view Policy and mutation-free REST.\n";
