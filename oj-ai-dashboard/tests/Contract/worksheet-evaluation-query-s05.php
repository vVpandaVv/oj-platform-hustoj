<?php
/**
 * G2-IMP-05-S05 worksheet evaluation Q09 and Q10 query contract.
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
use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Read_Worksheet_Evaluations;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Worksheet_Evaluation_Failure;
use OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Input_Builder;
use OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Output_Normalizer;
use OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Query_Projector;
use OJAI\Dashboard\Worksheet\Evaluation\Infrastructure\WordPress_Worksheet_Evaluation_Query;
use OJAI\Dashboard\Worksheet\Evaluation\Infrastructure\Worksheet_Evaluation_Object_Authorization;
use OJAI\Dashboard\Worksheet\Evaluation\Port\Worksheet_Evaluation_Query_Port_Interface;
use OJAI\Dashboard\Worksheet\Evaluation\Presentation\Worksheet_Evaluation_Query_Rest_Controller;

$addon_root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

if ( ! class_exists( 'Normalizer' ) ) {
	/**
	 * G2-IMP-05-S05 worksheet evaluation Q09 and Q10 query contract.
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
	 * G2-IMP-05-S05 worksheet evaluation Q09 and Q10 query contract.
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
	 * G2-IMP-05-S05 worksheet evaluation Q09 and Q10 query contract.
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
		 * Get error message.
		 *
		 * @return string
		 */
		public function get_error_message(): string {
			return $this->message;
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

$GLOBALS['eval_s05_actor']       = 42;
$GLOBALS['eval_s05_nonce_calls'] = 0;
$GLOBALS['eval_s05_routes']      = array();

if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Get current user id.
	 *
	 * @return int
	 */
	function get_current_user_id(): int {
		return (int) $GLOBALS['eval_s05_actor'];
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
		unset( $nonce, $action );
		++$GLOBALS['eval_s05_nonce_calls'];
		return false;
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
		$GLOBALS['eval_s05_routes'][] = array( $namespace, $route, $arguments );
		return true;
	}
}

require_once $addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core'
	. DIRECTORY_SEPARATOR . 'Autoloader.php';
( new AddonAutoloader( $addon_root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * Eval s05 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function eval_s05_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Eval s05 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function eval_s05_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . "\nExpected: " . var_export( $expected, true )
			. "\nActual: " . var_export( $actual, true )
		);
	}
}

/**
 * Eval s05 invalid argument.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function eval_s05_invalid_argument( callable $callback, string $message ): void {
	try {
		$callback();
	} catch ( InvalidArgumentException ) {
		return;
	}
	throw new RuntimeException( $message );
}

/**
 * Eval s05 stored invalid.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $message Message.
 * @return void
 */
function eval_s05_stored_invalid( callable $callback, string $message ): void {
	eval_s05_evaluation_failure( $callback, Worksheet_Evaluation_Failure::STORED_DATA_INVALID, $message );
}

/**
 * Eval s05 evaluation failure.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $expected_reason Expected reason.
 * @param string           $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function eval_s05_evaluation_failure( callable $callback, string $expected_reason, string $message ): void {
	try {
		$callback();
	} catch ( Worksheet_Evaluation_Failure $failure ) {
		eval_s05_same( $expected_reason, $failure->reason(), $message );
		return;
	}
	throw new RuntimeException( $message );
}

/**
 * Eval s05 id.
 *
 * @return string
 */
function eval_s05_id(): string {
	static $suffix = 5000;
	++$suffix;
	$id = '01K' . str_pad( (string) $suffix, 23, '0', STR_PAD_LEFT );
	eval_s05_assert( Public_Id::is_valid( $id ), 'S05 fixture ID must satisfy PublicIdV1.' );
	return $id;
}

/**
 * Eval s05 fixture.
 *
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @return array<string,mixed>
 */
function eval_s05_fixture( Canonical_Json $canonical ): array {
	$evaluation_id   = eval_s05_id();
	$worksheet_id    = eval_s05_id();
	$revision_id     = eval_s05_id();
	$run_id          = eval_s05_id();
	$result_id       = eval_s05_id();
	$node_id         = eval_s05_id();
	$review_id       = eval_s05_id();
	$source_hash     = str_repeat( 'a', 64 );
	$provider_digest = str_repeat( 'b', 64 );

	$dimensions = array();
	foreach ( Worksheet_Evaluation_Input_Builder::CORE_DIMENSIONS as $dimension ) {
		$dimensions[] = array(
			'dimension_key' => $dimension,
			'rating'        => 'instruction_clarity' === $dimension ? 'minor_issue' : 'meets',
			'confidence'    => '0.9000',
			'summary'       => 'The dimension was checked against the worksheet.',
		);
	}
	$evidence            = array(
		array(
			'anchor_type' => 'node',
			'anchor_id'   => $node_id,
			'field'       => 'text',
			'quote'       => 'Explain the answer with evidence.',
		),
	);
	$finding_base        = array(
		'dimension_key'  => 'instruction_clarity',
		'severity'       => 'minor',
		'title'          => 'Clarify the evidence requirement',
		'rationale'      => 'The direction does not identify the evidence form.',
		'evidence'       => $evidence,
		'recommendation' => 'Name the expected evidence type.',
		'confidence'     => '0.8800',
	);
	$finding_key         = hash( 'sha256', $canonical->encode( $finding_base ) );
	$finding             = array_merge(
		$finding_base,
		array(
			'finding_key' => $finding_key,
			'position'    => 0,
		)
	);
	$summary             = 'The worksheet is usable after one clarity improvement.';
	$normalized          = array(
		'schema_id'          => Worksheet_Evaluation_Output_Normalizer::RESULT_SCHEMA,
		'schema_version'     => Worksheet_Evaluation_Output_Normalizer::RESULT_VERSION,
		'source_hash'        => $source_hash,
		'provider_status'    => 'completed',
		'overall_status'     => 'needs_attention',
		'overall_confidence' => '0.9000',
		'abstained'          => false,
		'safety_flagged'     => false,
		'summary'            => $summary,
		'abstain_reason'     => null,
		'dimensions'         => $dimensions,
		'findings'           => array( $finding ),
		'limitations'        => array(),
	);
	$normalized_json     = $canonical->encode( $normalized );
	$normalized_hash     = hash( 'sha256', $normalized_json );
	$result_content_hash = hash(
		'sha256',
		$canonical->encode(
			array(
				'evaluation_id'          => $evaluation_id,
				'normalized_hash'        => $normalized_hash,
				'provider_output_digest' => $provider_digest,
				'run_id'                 => $run_id,
			)
		)
	);
	$evidence_payload    = array(
		'schema_id'      => 'oj_ai.worksheet_quality_finding_evidence',
		'schema_version' => '1.0.0',
		'finding_key'    => $finding_key,
		'title'          => $finding['title'],
		'rationale'      => $finding['rationale'],
		'evidence'       => $evidence,
	);
	$evidence_json       = $canonical->encode( $evidence_payload );
	$evidence_hash       = hash( 'sha256', $evidence_json );
	$item_content_hash   = hash(
		'sha256',
		$canonical->encode(
			array(
				'dimension_key'  => $finding['dimension_key'],
				'evidence_hash'  => $evidence_hash,
				'position'       => 0,
				'recommendation' => $finding['recommendation'],
				'severity'       => $finding['severity'],
			)
		)
	);
	$review_items        = array(
		'schema_id'      => Worksheet_Evaluation_Query_Projector::REVIEW_ITEMS_SCHEMA,
		'schema_version' => '1.0.0',
		'items'          => array(
			array(
				'finding_key'            => $finding_key,
				'decision'               => 'dismissed',
				'reason_code'            => 'context_verified',
				'note'                   => 'The source was checked by the teacher.',
				'resolution_revision_id' => null,
				'resolution_source_hash' => null,
			),
		),
	);
	$review_json         = $canonical->encode( $review_items );

	$root   = array(
		'evaluation_pk'             => '11',
		'evaluation_id'             => $evaluation_id,
		'evaluation_state'          => 'review_required',
		'worksheet_revision_id'     => $revision_id,
		'worksheet_revision_hash'   => $source_hash,
		'requested_at_utc'          => '2026-08-21 01:00:00',
		'evaluation_updated_at_utc' => '2026-08-21 01:02:00',
		'worksheet_id'              => $worksheet_id,
		'worksheet_title'           => 'S05 evaluation worksheet',
		'current_revision_id'       => $revision_id,
		'revision_no'               => '2',
		'revision_state'            => 'draft',
		'latest_run_id'             => $run_id,
		'latest_run_no'             => '1',
		'latest_run_state'          => 'succeeded',
		'latest_run_queued_at_utc'  => '2026-08-21 01:00:00',
		'latest_run_updated_at_utc' => '2026-08-21 01:02:00',
		'result_id'                 => $result_id,
		'overall_status'            => 'needs_attention',
		'finding_count'             => '1',
		'attention_count'           => '1',
		'safety_count'              => '0',
		'latest_review_no'          => '1',
	);
	$result = array(
		'result_pk'                 => '21',
		'result_id'                 => $result_id,
		'result_content_hash'       => $result_content_hash,
		'result_created_at_utc'     => '2026-08-21 01:02:00',
		'output_schema_version'     => Worksheet_Evaluation_Input_Builder::OUTPUT_VERSION,
		'overall_status'            => 'needs_attention',
		'overall_confidence'        => '0.9000',
		'summary'                   => $summary,
		'normalized_result_schema'  => Worksheet_Evaluation_Output_Normalizer::RESULT_SCHEMA,
		'normalized_result_version' => '1',
		'normalized_result_json'    => $normalized_json,
		'normalized_result_hash'    => $normalized_hash,
		'provider_output_digest'    => $provider_digest,
		'result_run_id'             => $run_id,
	);
	$item   = array(
		'item_content_hash'   => $item_content_hash,
		'item_dimension_key'  => $finding['dimension_key'],
		'item_position'       => '0',
		'item_severity'       => $finding['severity'],
		'item_confidence'     => $finding['confidence'],
		'evidence_schema'     => 'oj_ai.worksheet_quality_finding_evidence',
		'evidence_version'    => '1',
		'evidence_json'       => $evidence_json,
		'evidence_hash'       => $evidence_hash,
		'item_recommendation' => $finding['recommendation'],
	);
	$run    = array(
		'run_id'               => $run_id,
		'run_no'               => '1',
		'run_state'            => 'succeeded',
		'run_result_id'        => $result_id,
		'attempt_count'        => '1',
		'run_queued_at_utc'    => '2026-08-21 01:00:00',
		'run_available_at_utc' => '2026-08-21 01:00:00',
		'run_started_at_utc'   => '2026-08-21 01:01:00',
		'run_finished_at_utc'  => '2026-08-21 01:02:00',
		'run_last_error_code'  => null,
	);
	$review = array(
		'review_id'                 => $review_id,
		'review_no'                 => '1',
		'review_decision'           => 'completed',
		'review_note'               => 'Teacher review completed.',
		'item_decisions_schema'     => Worksheet_Evaluation_Query_Projector::REVIEW_ITEMS_SCHEMA,
		'item_decisions_version'    => '1',
		'item_decisions_json'       => $review_json,
		'item_decisions_hash'       => hash( 'sha256', $review_json ),
		'review_created_at_utc'     => '2026-08-21 01:05:00',
		'previous_review_public_id' => null,
		'review_result_id'          => $result_id,
	);

	return compact(
		'evaluation_id',
		'worksheet_id',
		'revision_id',
		'run_id',
		'result_id',
		'source_hash',
		'finding_key',
		'root',
		'result',
		'item',
		'run',
		'review'
	);
}

/**
 * G2-IMP-05-S05 worksheet evaluation Q09 and Q10 query contract.
 */
final class Eval_S05_Database {
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Prepared.
	 *
	 * @var array<int,array{sql:string,values:array<int,mixed>}>
	 */
	public array $prepared = array();
	/**
	 * List pages.
	 *
	 * @var array<int,array<int,array<string,mixed>>>
	 */
	public array $list_pages = array();
	/**
	 * Initialize eval s05 database dependencies and state.
	 *
	 * @param array<string,mixed> $fixture Fixture.
	 */
	public function __construct( private array $fixture ) {
	}
	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$values Values.
	 * @return string
	 */
	public function prepare( string $sql, ...$values ): string {
		$this->prepared[] = compact( 'sql', 'values' );
		return $sql;
	}
	/**
	 * Get row.
	 *
	 * @param string $sql Sql.
	 * @param string $mode Mode.
	 * @return array<string,mixed>|null
	 */
	public function get_row( string $sql, string $mode ): ?array {
		unset( $mode );
		if ( str_contains( $sql, 'SELECT x.id AS result_pk' ) ) {
			return $this->fixture['result'];
		}
		if ( str_contains( $sql, 'SELECT e.worksheet_revision_public_id AS current_revision' ) ) {
			return array(
				'current_revision' => $this->fixture['revision_id'],
				'state'            => 'review_required',
			);
		}
		if ( str_contains( $sql, 'WHERE e.public_id = %s AND w.owner_user_id = %d' ) ) {
			return $this->fixture['root'];
		}
		return null;
	}
	/**
	 * Get results.
	 *
	 * @param string $sql Sql.
	 * @param string $mode Mode.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_results( string $sql, string $mode ): array {
		unset( $mode );
		if ( str_contains( $sql, 'ORDER BY e.updated_at_utc DESC' ) ) {
			return array_shift( $this->list_pages ) ?? array();
		}
		if ( str_contains( $sql, 'FROM oj_ai_worksheet_evaluation_items' ) && str_contains( $sql, 'ORDER BY position ASC' ) ) {
			return array( $this->fixture['item'] );
		}
		if ( str_contains( $sql, 'FROM oj_ai_evaluation_runs run WHERE' ) ) {
			return array( $this->fixture['run'] );
		}
		if ( str_contains( $sql, 'FROM oj_ai_worksheet_evaluation_reviews review' ) ) {
			return array( $this->fixture['review'] );
		}
		return array();
	}
}

/**
 * G2-IMP-05-S05 worksheet evaluation Q09 and Q10 query contract.
 */
final class Eval_S05_Query_Port implements Worksheet_Evaluation_Query_Port_Interface {
	/**
	 * List calls.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	public array $list_calls = array();
	/**
	 * Initialize eval s05 query port dependencies and state.
	 *
	 * @param array<string,mixed>            $detail Detail.
	 * @param array<int,array<string,mixed>> $items Items.
	 */
	public function __construct( private array $detail, private array $items ) {
	}
	/**
	 * List owned.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $state State.
	 * @param string $worksheet_id Worksheet public identifier.
	 * @param string $revision_id Revision id.
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @param bool   $latest_only Latest only.
	 * @return array
	 */
	public function list_owned(
		int $actor_user_id,
		string $state,
		string $worksheet_id,
		string $revision_id,
		string $cursor,
		int $limit,
		bool $latest_only = false
	): array {
		$this->list_calls[] = func_get_args();
		return array(
			'items'       => $this->items,
			'next_cursor' => '',
		);
	}
	/**
	 * Find owned.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $evaluation_id Evaluation id.
	 * @return array|null
	 */
	public function find_owned( int $actor_user_id, string $evaluation_id ): ?array {
		return 42 === $actor_user_id && $evaluation_id === $this->detail['evaluation']['id'] ? $this->detail : null;
	}
	/**
	 * Authorization snapshot.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $evaluation_id Evaluation id.
	 * @return array|null
	 */
	public function authorization_snapshot( int $actor_user_id, string $evaluation_id ): ?array {
		if ( 42 !== $actor_user_id || $evaluation_id !== $this->detail['evaluation']['id'] ) {
			return null;
		}
		return array(
			'current_revision' => $this->detail['evaluation']['revision']['id'],
			'state'            => $this->detail['evaluation']['state'],
			'relation'         => 'owner',
		);
	}
}

/**
 * G2-IMP-05-S05 worksheet evaluation Q09 and Q10 query contract.
 */
final class Eval_S05_Policy implements Access_Policy_Port_Interface {
	/**
	 * Last request.
	 *
	 * @var Access_Policy_Request|null
	 */
	public ?Access_Policy_Request $last_request = null;
	/**
	 * Deny.
	 *
	 * @var bool
	 */
	public bool $deny = false;
	/**
	 * Initialize eval s05 policy dependencies and state.
	 *
	 * @param string $evaluation_id Evaluation id.
	 * @param string $revision_id Revision id.
	 */
	public function __construct( private string $evaluation_id, private string $revision_id ) {
	}
	/**
	 * Authorize.
	 *
	 * @param Access_Policy_Request $request Request.
	 * @return Access_Policy_Decision
	 */
	public function authorize( Access_Policy_Request $request ): Access_Policy_Decision {
		$this->last_request = $request;
		if ( $this->deny ) {
			return Access_Policy_Decision::denied( 404, 'worksheet_evaluation_scope_denied', $request->correlation_id() );
		}
		if (
			42 !== $request->actor_user_id()
			|| array( Addon_Capabilities::ASSESS_WORKSHEETS ) !== $request->required_capabilities()
		) {
			return Access_Policy_Decision::denied( 403, 'worksheet_evaluation_capability_denied', $request->correlation_id() );
		}
		if ( Worksheet_Evaluation_Query_Rest_Controller::ACTION_LIST === $request->action_key() ) {
			return null === $request->object()
				? Access_Policy_Decision::allowed( $request->correlation_id() )
				: Access_Policy_Decision::denied( 404, 'worksheet_evaluation_list_scope_invalid', $request->correlation_id() );
		}
		$object = $request->object();
		if (
			Worksheet_Evaluation_Query_Rest_Controller::ACTION_VIEW !== $request->action_key()
			|| ! $object instanceof Object_Scope
			|| Object_Scope::TYPE_EVALUATION !== $object->type()
			|| $this->evaluation_id !== $object->id()
			|| 'view' !== $object->action()
		) {
			return Access_Policy_Decision::denied( 404, 'worksheet_evaluation_object_denied', $request->correlation_id() );
		}
		return Access_Policy_Decision::allowed(
			$request->correlation_id(),
			null,
			Object_Authorization_Result::allowed( $this->revision_id, 'review_required', 'owner' )
		);
	}
}

/**
 * G2-IMP-05-S05 worksheet evaluation Q09 and Q10 query contract.
 */
final class Eval_S05_Request {
	/**
	 * Initialize eval s05 request dependencies and state.
	 *
	 * @param array<string,mixed>  $query Query.
	 * @param array<string,string> $url Url.
	 */
	public function __construct( private array $query = array(), private array $url = array() ) {
	}
	/**
	 * Get method.
	 *
	 * @return string
	 */
	public function get_method(): string {
		return 'GET';
	}
	/**
	 * Get header.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	public function get_header( string $name ): string {
		unset( $name );
		return '';
	}
	/**
	 * Get query params.
	 *
	 * @return array<string,mixed>
	 */
	public function get_query_params(): array {
		return $this->query;
	}
	/**
	 * Get url params.
	 *
	 * @return array<string,string>
	 */
	public function get_url_params(): array {
		return $this->url;
	}
}

/**
 * Eval s05 route.
 *
 * @param string $path Path.
 * @return array<string,mixed>
 * @throws \Throwable Propagates validation or dependency failures.
 */
function eval_s05_route( string $path ): array {
	foreach ( $GLOBALS['eval_s05_routes'] as $registration ) {
		if (
			Worksheet_Evaluation_Query_Rest_Controller::REST_NAMESPACE === $registration[0]
			&& $path === $registration[1]
		) {
			return $registration[2];
		}
	}
	throw new RuntimeException( 'S05 REST route was not registered: ' . $path );
}

$canonical                          = new Canonical_Json();
$fixture                            = eval_s05_fixture( $canonical );
$database                           = new Eval_S05_Database( $fixture );
$older                              = $fixture['root'];
$older['evaluation_id']             = eval_s05_id();
$older['evaluation_updated_at_utc'] = '2026-08-21 00:59:00';
$database->list_pages               = array( array( $fixture['root'], $older ), array() );
$repository                         = new WordPress_Worksheet_Evaluation_Query( $canonical, null, $database );

$page = $repository->list_owned(
	42,
	'review_required',
	$fixture['worksheet_id'],
	$fixture['revision_id'],
	'',
	1
);
eval_s05_same( 1, count( $page['items'] ), 'Q09 must honor the requested page limit.' );
eval_s05_same( $fixture['evaluation_id'], $page['items'][0]['id'], 'Q09 must project the owner evaluation.' );
eval_s05_same( 'review', $page['items'][0]['status']['next_action'], 'Q09 must derive the review action.' );
eval_s05_assert( '' !== $page['next_cursor'], 'Q09 must return a cursor when another row exists.' );
eval_s05_assert( strlen( $page['next_cursor'] ) <= Read_Worksheet_Evaluations::MAXIMUM_CURSOR, 'Q09 cursor exceeds QueryInputV1.' );
$first_prepare = $database->prepared[0];
eval_s05_assert( str_contains( $first_prepare['sql'], 'WHERE w.owner_user_id = %d' ), 'Q09 must filter worksheet ownership in SQL.' );
eval_s05_same( 42, $first_prepare['values'][4], 'Q09 owner actor must bind after fixed projection values.' );
eval_s05_same( 'review_required', $first_prepare['values'][5], 'Q09 state binding order drifted.' );
eval_s05_same( $fixture['worksheet_id'], $first_prepare['values'][6], 'Q09 worksheet binding order drifted.' );
eval_s05_same( $fixture['revision_id'], $first_prepare['values'][7], 'Q09 revision binding order drifted.' );
eval_s05_invalid_argument(
	static fn () => $repository->list_owned( 42, 'failed', $fixture['worksheet_id'], $fixture['revision_id'], $page['next_cursor'], 1 ),
	'A Q09 cursor must be bound to its exact filter scope.'
);
$next_page = $repository->list_owned(
	42,
	'review_required',
	$fixture['worksheet_id'],
	$fixture['revision_id'],
	$page['next_cursor'],
	1
);
eval_s05_same( array(), $next_page['items'], 'A valid Q09 cursor must be accepted.' );

eval_s05_invalid_argument(
	static fn () => $repository->list_owned( 42, 'review_required', $fixture['worksheet_id'], $fixture['revision_id'], $page['next_cursor'], 1, true ),
	'History cursors must not be accepted by the latest-per-worksheet view.'
);
$database->list_pages = array( array( $fixture['root'], $older ) );
$latest_page          = $repository->list_owned( 42, '', '', '', '', 1, true );
$latest_sql           = $database->prepared[ array_key_last( $database->prepared ) ]['sql'];
eval_s05_assert( str_contains( $latest_sql, 'AND NOT EXISTS (SELECT 1 FROM oj_ai_worksheet_evaluations newer' ), 'Latest view must group in SQL before pagination.' );
eval_s05_assert( str_contains( $latest_sql, 'newer_revision.worksheet_id = w.id' ), 'Grouping must stay inside the owned worksheet.' );
eval_s05_assert( str_contains( $latest_sql, 'newer_revision.revision_no > revision.revision_no' ), 'Old review edits must not outrank a new worksheet revision.' );
eval_s05_assert( str_contains( $latest_sql, 'newer.id > e.id' ), 'Same-revision policy updates must select the newest request.' );
eval_s05_invalid_argument(
	static fn () => $repository->list_owned( 42, '', '', '', $latest_page['next_cursor'], 1 ),
	'Latest-view cursors must not be accepted by history.'
);

$detail = $repository->find_owned( 42, $fixture['evaluation_id'] );
eval_s05_assert( is_array( $detail ), 'Q10 owner detail must exist.' );
eval_s05_same( $fixture['result_id'], $detail['result']['id'], 'Q10 result identity drifted.' );
eval_s05_same( $fixture['finding_key'], $detail['result']['findings'][0]['finding_key'], 'Q10 finding identity drifted.' );
eval_s05_same( 'dismissed', $detail['reviews'][0]['item_decisions'][0]['decision'], 'Q10 review history drifted.' );
eval_s05_same( $fixture['result_id'], $detail['runs'][0]['result_id'], 'Q10 run/result link drifted.' );
$safe_detail_json = json_encode( $detail, JSON_THROW_ON_ERROR );
foreach ( array( 'provider_output_digest', 'normalized_result_json', 'profile_fingerprint', 'routing_revision', 'correlation_id', 'raw_response', 'system_prompt' ) as $forbidden ) {
	eval_s05_assert( ! str_contains( $safe_detail_json, $forbidden ), 'Q10 leaked forbidden field: ' . $forbidden );
}
$authorization = $repository->authorization_snapshot( 42, $fixture['evaluation_id'] );
eval_s05_same( 'owner', $authorization['relation'] ?? null, 'Evaluation object authorization must retain only owner evidence.' );

$projector                                = new Worksheet_Evaluation_Query_Projector( $canonical );
$corrupt_result                           = $fixture['result'];
$corrupt_result['normalized_result_hash'] = str_repeat( '0', 64 );
eval_s05_evaluation_failure(
	static fn () => $projector->detail(
		$fixture['root'],
		$corrupt_result,
		array( $fixture['item'] ),
		array( $fixture['run'] ),
		array( $fixture['review'] )
	),
	Worksheet_Evaluation_Failure::RESULT_SCHEMA_INVALID,
	'Q10 must fail closed when normalized JSON integrity is broken.'
);
$corrupt_run                  = $fixture['run'];
$corrupt_run['run_result_id'] = eval_s05_id();
eval_s05_evaluation_failure(
	static fn () => $projector->detail(
		$fixture['root'],
		$fixture['result'],
		array( $fixture['item'] ),
		array( $corrupt_run ),
		array( $fixture['review'] )
	),
	Worksheet_Evaluation_Failure::DETAIL_LINKS_INVALID,
	'Q10 must fail closed when run and result identities drift.'
);

$query_port = new Eval_S05_Query_Port( $detail, $page['items'] );
$reader     = new Read_Worksheet_Evaluations( $query_port );
eval_s05_same( $page['items'], $reader->list( 42, '', '', '', '', 30 )['items'], 'Q09 application projection drifted.' );
eval_s05_same( $detail, $reader->detail( 42, $fixture['evaluation_id'] ), 'Q10 application projection drifted.' );
eval_s05_invalid_argument(
	static fn () => $reader->list( 42, 'unknown', '', '', '', 30 ),
	'Q09 must reject unknown state filters.'
);
eval_s05_invalid_argument(
	static fn () => $reader->list( 42, '', '', '', str_repeat( 'x', 192 ), 30 ),
	'Q09 must reject an oversized cursor.'
);
eval_s05_invalid_argument(
	static fn () => $reader->list( 42, '', '', '', '', 101 ),
	'Q09 must reject an oversized page.'
);
try {
	$reader->detail( 7, $fixture['evaluation_id'] );
} catch ( Worksheet_Evaluation_Failure $failure ) {
	eval_s05_same( Worksheet_Evaluation_Failure::NOT_FOUND, $failure->reason(), 'Q10 must hide an unowned evaluation as not found.' );
}

$object_authorization = new Worksheet_Evaluation_Object_Authorization( $query_port );
$evaluation_scope     = new Object_Scope( Object_Scope::TYPE_EVALUATION, $fixture['evaluation_id'], 'view' );
eval_s05_assert( ! $evaluation_scope->requires_context(), 'Worksheet evaluation scope must use worksheet ownership without a Platform Context.' );
eval_s05_assert( $object_authorization->authorize( 42, 'worksheet.evaluation.view', null, $evaluation_scope )->is_allowed(), 'Owner evaluation scope must be allowed.' );
eval_s05_assert( $object_authorization->authorize( 7, 'worksheet.evaluation.view', null, $evaluation_scope )->is_denied(), 'Unowned evaluation scope must be denied.' );
eval_s05_assert(
	$object_authorization->authorize(
		42,
		'worksheet.evaluation.view',
		Context_Authorization_Result::unavailable(),
		$evaluation_scope
	)->is_unavailable(),
	'Worksheet evaluation authorization must reject an unexpected Platform Context.'
);

$policy       = new Eval_S05_Policy( $fixture['evaluation_id'], $fixture['revision_id'] );
$sequence     = 0;
$correlations = new Correlation_Id_Factory(
	static function () use ( &$sequence ): string {
		return 's05-rest-correlation-' . ( ++$sequence );
	}
);
$envelopes    = new Rest_Envelope_Factory();
$permissions  = new Rest_Permission_Handoff(
	new WordPress_Rest_Request_Adapter( new WordPress_Current_Actor() ),
	new Rest_Request_Authorizer( $policy, new WordPress_Rest_Nonce_Verifier(), $correlations ),
	$envelopes,
	$correlations
);
$controller   = new Worksheet_Evaluation_Query_Rest_Controller( $permissions, $reader, $envelopes, $correlations );
$controller->register_routes();
$controller->register_routes();
eval_s05_same( 2, count( $GLOBALS['eval_s05_routes'] ), 'Q09 and Q10 must each register exactly once.' );
$list_route   = eval_s05_route( Worksheet_Evaluation_Query_Rest_Controller::ROUTE_EVALUATIONS );
$detail_route = eval_s05_route( Worksheet_Evaluation_Query_Rest_Controller::ROUTE_EVALUATION );
eval_s05_same( 'GET', $list_route['methods'], 'Q09 must be read-only GET.' );
eval_s05_same( 'GET', $detail_route['methods'], 'Q10 must be read-only GET.' );

$list_request = new Eval_S05_Request(
	array(
		'state' => 'review_required',
		'limit' => '20',
	)
);
eval_s05_same( true, call_user_func( $list_route['permission_callback'], $list_request ), 'Authorized Q09 must pass the common handoff.' );
eval_s05_same( Worksheet_Evaluation_Query_Rest_Controller::ACTION_LIST, $policy->last_request?->action_key(), 'Q09 action key drifted.' );
eval_s05_same( null, $policy->last_request?->object(), 'Q09 must apply owner filtering in the query instead of accepting an object ID.' );
$list_response = call_user_func( $list_route['callback'], $list_request );
eval_s05_assert( $list_response instanceof WP_REST_Response, 'Q09 success must return WP_REST_Response.' );
$list_envelope = $list_response->get_data();
eval_s05_same( 0, $list_envelope['meta']['mutation_count'], 'Q09 must report zero mutations.' );
eval_s05_same( 'worksheet_owner', $list_envelope['meta']['relation_scope'], 'Q09 owner relation metadata drifted.' );
eval_s05_same( 20, $query_port->list_calls[ array_key_last( $query_port->list_calls ) ][5], 'Q09 REST limit was not normalized.' );
eval_s05_same( false, $query_port->list_calls[ array_key_last( $query_port->list_calls ) ][6], 'Existing callers must retain history semantics.' );
$latest_request = new Eval_S05_Request(
	array(
		'view'  => 'latest',
		'limit' => '30',
	)
);
eval_s05_same( true, $controller->permissions_list( $latest_request ), 'Latest view uses the same authorization.' );
eval_s05_assert( $controller->index( $latest_request ) instanceof WP_REST_Response, 'Latest view should be queryable.' );
eval_s05_same( true, $query_port->list_calls[ array_key_last( $query_port->list_calls ) ][6], 'REST must pass the latest selector to the repository.' );
foreach ( array( 'unknown', array( 'latest' ), true ) as $invalid_view ) {
	$request = new Eval_S05_Request( array( 'view' => $invalid_view ) );
	$controller->permissions_list( $request );
	$error = $controller->index( $request );
	eval_s05_assert( $error instanceof WP_Error && 422 === $error->get_error_data()['status'], 'Invalid views must fail closed.' );
}

$detail_request = new Eval_S05_Request( array(), array( 'evaluation_id' => $fixture['evaluation_id'] ) );
eval_s05_same( true, call_user_func( $detail_route['permission_callback'], $detail_request ), 'Authorized Q10 must pass the common handoff.' );
eval_s05_same( Object_Scope::TYPE_EVALUATION, $policy->last_request?->object()?->type(), 'Q10 object type drifted.' );
eval_s05_same( 'view', $policy->last_request?->object()?->action(), 'Q10 object action drifted.' );
$detail_response = call_user_func( $detail_route['callback'], $detail_request );
eval_s05_assert( $detail_response instanceof WP_REST_Response, 'Q10 success must return WP_REST_Response.' );
$detail_envelope = $detail_response->get_data();
eval_s05_same( 0, $detail_envelope['meta']['mutation_count'], 'Q10 must report zero mutations.' );
eval_s05_same( false, $detail_envelope['meta']['raw_provider_output'], 'Q10 must never expose raw provider output.' );
eval_s05_same( false, $detail_envelope['meta']['raw_prompt'], 'Q10 must never expose a raw prompt.' );
eval_s05_same( $fixture['finding_key'], $detail_envelope['data']['result']['findings'][0]['finding_key'], 'Q10 REST finding projection drifted.' );

$unknown_query = new Eval_S05_Request( array( 'debug' => '1' ) );
eval_s05_same( true, call_user_func( $list_route['permission_callback'], $unknown_query ), 'Q09 unknown-query test must pass authorization first.' );
$unknown_error = call_user_func( $list_route['callback'], $unknown_query );
eval_s05_assert( $unknown_error instanceof WP_Error, 'Q09 unknown query key must return a problem.' );
eval_s05_same( 422, $unknown_error->get_error_data()['status'] ?? null, 'Q09 unknown query key must return 422.' );

$policy->deny   = true;
$denied_request = new Eval_S05_Request( array(), array( 'evaluation_id' => $fixture['evaluation_id'] ) );
$denied         = call_user_func( $detail_route['permission_callback'], $denied_request );
eval_s05_assert( $denied instanceof WP_Error, 'Denied Q10 must return the common authorization problem.' );
eval_s05_same( 404, $denied->get_error_data()['status'] ?? null, 'Denied Q10 must hide object existence.' );
eval_s05_same( 0, $GLOBALS['eval_s05_nonce_calls'], 'GET Q09 and Q10 must not invoke mutation nonce verification.' );

echo "G2-IMP-05-S05 worksheet evaluation Q09/Q10 contract passed.\n";
