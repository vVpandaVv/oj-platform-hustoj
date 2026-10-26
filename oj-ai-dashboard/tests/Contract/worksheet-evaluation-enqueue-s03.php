<?php
/**
 * G2-IMP-05-S03 worksheet evaluation enqueue contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader as AddonAutoloader;
use OJAI\Dashboard\Platform\Addon_Manifest;
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
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Canonicalizer;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Profile;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Revision_Projector;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Enqueue_Worksheet_Evaluation;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Enqueue_Worksheet_Evaluation_Command;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Enqueue_Worksheet_Evaluation_Result;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Worksheet_Evaluation_Failure;
use OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Input_Builder;
use OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Routing_Snapshot;
use OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Source;
use OJAI\Dashboard\Worksheet\Evaluation\Infrastructure\WordPress_Worksheet_Evaluation_Repository;
use OJAI\Dashboard\Worksheet\Evaluation\Port\Worksheet_Evaluation_Configuration_Port_Interface;
use OJAI\Dashboard\Worksheet\Evaluation\Port\Worksheet_Evaluation_Queue_Wakeup_Port_Interface;
use OJAI\Dashboard\Worksheet\Evaluation\Port\Worksheet_Evaluation_Routing_Port_Interface;
use OJAI\Dashboard\Worksheet\Evaluation\Port\Worksheet_Evaluation_Source_Port_Interface;
use OJAI\Dashboard\Worksheet\Evaluation\Presentation\Worksheet_Evaluation_Rest_Controller;

$addon_root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

if ( ! class_exists( 'Normalizer' ) ) {
	/**
	 * G2-IMP-05-S03 worksheet evaluation enqueue contract.
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
	 * G2-IMP-05-S03 worksheet evaluation enqueue contract.
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
	 * G2-IMP-05-S03 worksheet evaluation enqueue contract.
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

$GLOBALS['eval_s03_actor']       = 42;
$GLOBALS['eval_s03_nonce_calls'] = array();
$GLOBALS['eval_s03_routes']      = array();

if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Get current user id.
	 *
	 * @return int
	 */
	function get_current_user_id(): int {
		return (int) $GLOBALS['eval_s03_actor'];
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
		$GLOBALS['eval_s03_nonce_calls'][] = array( $nonce, $action );
		return 'valid-s03-nonce' === $nonce ? 1 : false;
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
		$GLOBALS['eval_s03_routes'][] = array( $namespace, $route, $arguments );
		return true;
	}
}

require_once $addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core'
	. DIRECTORY_SEPARATOR . 'Autoloader.php';
( new AddonAutoloader( $addon_root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * Eval s03 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function eval_s03_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Eval s03 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function eval_s03_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . "\nExpected: " . var_export( $expected, true )
			. "\nActual: " . var_export( $actual, true )
		);
	}
}

/**
 * Eval s03 fails.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $reason Reason.
 * @param string           $message Message.
 * @return Worksheet_Evaluation_Failure
 * @throws \Throwable Propagates validation or dependency failures.
 */
function eval_s03_fails( callable $callback, string $reason, string $message ): Worksheet_Evaluation_Failure {
	try {
		$callback();
	} catch ( Worksheet_Evaluation_Failure $failure ) {
		eval_s03_same( $reason, $failure->reason(), $message . ' Stable reason mismatch.' );
		return $failure;
	}
	throw new RuntimeException( $message );
}

/**
 * Eval s03 id.
 *
 * @return string
 */
function eval_s03_id(): string {
	static $suffix = 3000;
	++$suffix;
	$id = '01K' . str_pad( (string) $suffix, 23, '0', STR_PAD_LEFT );
	eval_s03_assert( Public_Id::is_valid( $id ), 'S03 fixture ID must satisfy PublicIdV1.' );
	return $id;
}

/**
 * Eval s03 generator.
 *
 * @param int $seed Seed.
 * @return Public_Id_Generator
 */
function eval_s03_generator( int $seed ): Public_Id_Generator {
	$counter = $seed;
	return new Public_Id_Generator(
		static function () use ( &$counter ): string {
			++$counter;
			return pack( 'N4', 0, 0, 0, $counter );
		}
	);
}

/**
 * Eval s03 node.
 *
 * @param string     $type Type.
 * @param array      $properties Properties.
 * @param array|null $children Children.
 * @return array<string,mixed>
 */
function eval_s03_node( string $type, array $properties = array(), ?array $children = null ): array {
	return array_merge(
		array(
			'type'     => $type,
			'node_id'  => eval_s03_id(),
			'children' => $children ?? array( array( 'text' => '' ) ),
		),
		$properties
	);
}

/**
 * Eval s03 dimensions.
 *
 * @return array<int,array<string,mixed>>
 */
function eval_s03_dimensions(): array {
	$result = array();
	foreach ( Worksheet_Evaluation_Input_Builder::CORE_DIMENSIONS as $dimension ) {
		$result[] = array(
			'dimension_key'     => $dimension,
			'label'             => ucwords( str_replace( '_', ' ', $dimension ) ),
			'description'       => 'Assess this dimension using explicit worksheet evidence.',
			'evidence_required' => true,
		);
	}
	return $result;
}

/**
 * Eval s03 configuration.
 *
 * @param string $policy_hash Policy hash.
 * @return array<string,mixed>
 */
function eval_s03_configuration( string $policy_hash = '' ): array {
	return array(
		'policy'         => array(
			'revision_id'  => eval_s03_id(),
			'content_hash' => '' === $policy_hash ? str_repeat( 'b', 64 ) : $policy_hash,
			'subject_type' => 'worksheet_quality',
		),
		'rubric'         => array(
			'revision_id'  => eval_s03_id(),
			'content_hash' => str_repeat( 'c', 64 ),
			'dimensions'   => eval_s03_dimensions(),
		),
		'grading_rubric' => array(
			'assessment_enabled' => true,
			'revision_id'        => eval_s03_id(),
			'content_hash'       => str_repeat( 'a', 64 ),
			'total_points'       => '10',
			'criteria'           => array(
				array(
					'criterion_id'   => eval_s03_id(),
					'label'          => 'Evidence and reasoning',
					'description'    => 'Check whether the submitted work uses relevant evidence and explains the reasoning.',
					'maximum_points' => '10',
					'position'       => 0,
					'scope'          => 'submission',
					'levels'         => array(
						array(
							'label'     => 'Uses relevant evidence and explains the reasoning.',
							'level_key' => eval_s03_id(),
							'points'    => '10',
							'position'  => 0,
						),
						array(
							'label'     => 'Evidence or reasoning is missing.',
							'level_key' => eval_s03_id(),
							'points'    => '0',
							'position'  => 1,
						),
					),
				),
			),
		),
		'review_rule'    => array(
			'revision_id'               => eval_s03_id(),
			'content_hash'              => str_repeat( 'd', 64 ),
			'review_required'           => true,
			'explicit_release_required' => true,
			'confidence_threshold'      => '0.8000',
			'abstain_handling'          => 'require_review',
			'safety_handling'           => 'require_explicit_review',
		),
		'prompt'         => array(
			'contract_version' => Worksheet_Evaluation_Input_Builder::PROMPT_CONTRACT,
			'content_hash'     => Worksheet_Evaluation_Input_Builder::PROMPT_CONTENT_HASH,
		),
		'input_schema'   => array(
			'schema_id'      => Worksheet_Evaluation_Input_Builder::REQUEST_SCHEMA,
			'schema_version' => Worksheet_Evaluation_Input_Builder::REQUEST_VERSION,
			'content_hash'   => str_repeat( 'e', 64 ),
		),
		'output_schema'  => array(
			'schema_id'      => Worksheet_Evaluation_Input_Builder::OUTPUT_SCHEMA,
			'schema_version' => Worksheet_Evaluation_Input_Builder::OUTPUT_VERSION,
			'content_hash'   => str_repeat( 'f', 64 ),
		),
	);
}

/**
 * G2-IMP-05-S03 worksheet evaluation enqueue contract.
 */
final class Eval_S03_Database {
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Insert id.
	 *
	 * @var int
	 */
	public int $insert_id = 0;
	/**
	 * In transaction.
	 *
	 * @var bool
	 */
	public bool $in_transaction = false;
	/**
	 * Tables.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public array $tables = array(
		'oj_ai_worksheet_evaluations'       => array(),
		'oj_ai_worksheet_evaluation_inputs' => array(),
		'oj_ai_evaluation_runs'             => array(),
		'oj_ai_audit_events'                => array(),
		'oj_ai_outbox_events'               => array(),
	);
	/**
	 * Transaction log.
	 *
	 * @var array<int,string>
	 */
	public array $transaction_log = array();
	/**
	 * Next ids.
	 *
	 * @var array<string,int>
	 */
	private array $next_ids = array();
	/**
	 * Snapshot.
	 *
	 * @var array<string,array<int,array<string,mixed>>>|null
	 */
	private ?array $snapshot = null;

	/**
	 * Initialize eval s03 database dependencies and state.
	 */
	public function __construct() {
		foreach ( array_keys( $this->tables ) as $table ) {
			$this->next_ids[ $table ] = 1;
		}
	}

	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$values Values.
	 * @return string
	 */
	public function prepare( string $sql, ...$values ): string {
		foreach ( $values as $value ) {
			if ( 1 !== preg_match( '/%(s|d)/', $sql, $placeholder ) ) {
				return '';
			}
			$is_integer  = 'd' === $placeholder[1];
			$replacement = $is_integer
				? (string) (int) $value
				: "'" . str_replace( "'", "''", (string) $value ) . "'";
			$sql         = preg_replace( '/%' . $placeholder[1] . '/', $replacement, $sql, 1 ) ?? '';
		}
		return $sql;
	}

	/**
	 * Get row.
	 *
	 * @param string $sql Sql.
	 * @param string $format Format.
	 * @return array<string,mixed>|null
	 */
	public function get_row( string $sql, string $format ): ?array {
		unset( $format );
		if ( str_contains( $sql, 'FROM oj_ai_outbox_events' ) ) {
			$key = $this->where_string( $sql, 'idempotency_key' );
			foreach ( $this->tables['oj_ai_outbox_events'] as $row ) {
				if ( $key === ( $row['idempotency_key'] ?? null ) ) {
					return array_intersect_key(
						$row,
						array_flip( array( 'aggregate_public_id', 'event_type', 'payload_schema', 'payload_version', 'payload_json', 'payload_hash' ) )
					);
				}
			}
			return null;
		}
		if ( str_contains( $sql, 'WHERE e.idempotency_key' ) ) {
			$key = $this->where_string( $sql, 'e.idempotency_key' );
			foreach ( $this->tables['oj_ai_worksheet_evaluations'] as $evaluation ) {
				if ( $key !== ( $evaluation['idempotency_key'] ?? null ) ) {
					continue;
				}
				return $this->joined_evaluation( $evaluation );
			}
			return null;
		}
		if ( preg_match( '/WHERE e\.id = ([0-9]+)/', $sql, $matches ) ) {
			foreach ( $this->tables['oj_ai_worksheet_evaluations'] as $evaluation ) {
				if ( (int) $matches[1] !== (int) $evaluation['id'] ) {
					continue;
				}
				$joined = $this->joined_evaluation( $evaluation );
				return null === $joined ? null : array(
					'evaluation_public_id' => $joined['evaluation_public_id'],
					'input_public_id'      => $joined['input_public_id'],
					'run_public_id'        => $joined['run_public_id'],
				);
			}
		}
		return null;
	}

	/**
	 * Get var.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 */
	public function get_var( string $sql ) {
		if ( ! str_contains( $sql, 'FROM oj_ai_audit_events' ) ) {
			return null;
		}
		$rows = $this->tables['oj_ai_audit_events'];
		return array() === $rows ? null : $rows[ array_key_last( $rows ) ]['event_hash'];
	}

	/**
	 * Insert.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data Data.
	 * @return mixed
	 */
	public function insert( string $table, array $data ) {
		$this->last_error = '';
		if ( ! isset( $this->tables[ $table ] ) ) {
			$this->last_error = 'unknown table';
			return false;
		}
		foreach ( $this->tables[ $table ] as $row ) {
			if ( isset( $data['public_id'] ) && ( $row['public_id'] ?? null ) === $data['public_id'] ) {
				$this->last_error = 'duplicate public id';
				return false;
			}
			if (
				isset( $data['idempotency_key'] )
				&& isset( $row['idempotency_key'] )
				&& $row['idempotency_key'] === $data['idempotency_key']
			) {
				$this->last_error = 'duplicate idempotency key';
				return false;
			}
		}
		$id                       = $this->next_ids[ $table ]++;
		$this->insert_id          = $id;
		$data['id']               = $id;
		$this->tables[ $table ][] = $data;
		return 1;
	}

	/**
	 * Query.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 */
	public function query( string $sql ) {
		$this->last_error        = '';
		$this->transaction_log[] = $sql;
		if ( 'START TRANSACTION' === $sql ) {
			if ( $this->in_transaction ) {
				$this->last_error = 'nested transaction';
				return false;
			}
			$this->snapshot       = unserialize( serialize( $this->tables ) );
			$this->in_transaction = true;
			return 1;
		}
		if ( 'COMMIT' === $sql ) {
			if ( ! $this->in_transaction ) {
				$this->last_error = 'missing transaction';
				return false;
			}
			$this->snapshot       = null;
			$this->in_transaction = false;
			return 1;
		}
		if ( 'ROLLBACK' === $sql ) {
			if ( $this->in_transaction && is_array( $this->snapshot ) ) {
				$this->tables = $this->snapshot;
			}
			$this->snapshot       = null;
			$this->in_transaction = false;
			return 1;
		}
		return 1;
	}

	/**
	 * Counts.
	 *
	 * @return array<string,int>
	 */
	public function counts(): array {
		return array_map( 'count', $this->tables );
	}

	/**
	 * Where string.
	 *
	 * @param string $sql Sql.
	 * @param string $column Column.
	 * @return string
	 */
	private function where_string( string $sql, string $column ): string {
		$pattern = '/WHERE ' . preg_quote( $column, '/' ) . " = '([^']+)'/";
		return preg_match( $pattern, $sql, $matches ) ? $matches[1] : '';
	}

	/**
	 * Joined evaluation.
	 *
	 * @param array<string,mixed> $evaluation Evaluation.
	 * @return array<string,mixed>|null
	 */
	private function joined_evaluation( array $evaluation ): ?array {
		$input = null;
		foreach ( $this->tables['oj_ai_worksheet_evaluation_inputs'] as $candidate ) {
			if ( (int) $candidate['worksheet_evaluation_id'] === (int) $evaluation['id'] ) {
				$input = $candidate;
				break;
			}
		}
		$run = null;
		foreach ( $this->tables['oj_ai_evaluation_runs'] as $candidate ) {
			if (
				'worksheet_evaluation' === ( $candidate['subject_type'] ?? null )
				&& ( $candidate['evaluation_public_id'] ?? null ) === $evaluation['public_id']
				&& 1 === (int) ( $candidate['run_no'] ?? 0 )
			) {
				$run = $candidate;
				break;
			}
		}
		if ( null === $input || null === $run ) {
			return null;
		}
		return array(
			'id'                           => $evaluation['id'],
			'evaluation_public_id'         => $evaluation['public_id'],
			'evaluation_state'             => $evaluation['state'],
			'worksheet_revision_public_id' => $evaluation['worksheet_revision_public_id'],
			'worksheet_revision_hash'      => $evaluation['worksheet_revision_hash'],
			'requested_at_utc'             => $evaluation['requested_at_utc'],
			'input_hash'                   => $input['input_hash'],
			'input_public_id'              => $input['public_id'],
			'run_public_id'                => $run['public_id'],
			'run_no'                       => $run['run_no'],
			'run_state'                    => $run['state'],
		);
	}
}

/**
 * G2-IMP-05-S03 worksheet evaluation enqueue contract.
 */
final class Eval_S03_Source_Port implements Worksheet_Evaluation_Source_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Initialize eval s03 source port dependencies and state.
	 *
	 * @param Worksheet_Evaluation_Source $source Source.
	 */
	public function __construct( public Worksheet_Evaluation_Source $source ) {
	}
	/**
	 * Find owned.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $revision_id Revision id.
	 * @return Worksheet_Evaluation_Source|null
	 */
	public function find_owned( int $actor_user_id, string $revision_id ): ?Worksheet_Evaluation_Source {
		++$this->calls;
		return 42 === $actor_user_id && $revision_id === $this->source->revision_id() ? $this->source : null;
	}
}

/**
 * G2-IMP-05-S03 worksheet evaluation enqueue contract.
 */
final class Eval_S03_Configuration_Port implements Worksheet_Evaluation_Configuration_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Initialize eval s03 configuration port dependencies and state.
	 *
	 * @param array<string,mixed> $configuration Configuration.
	 */
	public function __construct( public array $configuration ) {
	}
	/**
	 * Active.
	 *
	 * @return array<string,mixed>
	 */
	public function active(): array {
		++$this->calls;
		return $this->configuration;
	}
}

/**
 * G2-IMP-05-S03 worksheet evaluation enqueue contract.
 */
final class Eval_S03_Routing_Port implements Worksheet_Evaluation_Routing_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Initialize eval s03 routing port dependencies and state.
	 *
	 * @param Worksheet_Evaluation_Routing_Snapshot $snapshot Snapshot.
	 */
	public function __construct( private Worksheet_Evaluation_Routing_Snapshot $snapshot ) {
	}
	/**
	 * Plan.
	 *
	 * @return Worksheet_Evaluation_Routing_Snapshot
	 */
	public function plan(): Worksheet_Evaluation_Routing_Snapshot {
		++$this->calls;
		return $this->snapshot;
	}
}

/**
 * G2-IMP-05-S03 worksheet evaluation enqueue contract.
 */
final class Eval_S03_Queue_Port implements Worksheet_Evaluation_Queue_Wakeup_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Initialize eval s03 queue port dependencies and state.
	 *
	 * @param Eval_S03_Database $database Database.
	 */
	public function __construct( private Eval_S03_Database $database ) {
	}
	/**
	 * Wake.
	 *
	 * @return void
	 */
	public function wake(): void {
		eval_s03_assert( ! $this->database->in_transaction, 'Queue wakeup must happen only after commit.' );
		eval_s03_assert( count( $this->database->tables['oj_ai_evaluation_runs'] ) > 0, 'Queue wakeup requires a durable run.' );
		++$this->calls;
	}
}

/**
 * G2-IMP-05-S03 worksheet evaluation enqueue contract.
 */
final class Eval_S03_Policy implements Access_Policy_Port_Interface {
	/**
	 * Last request.
	 *
	 * @var Access_Policy_Request|null
	 */
	public ?Access_Policy_Request $last_request = null;
	/**
	 * Initialize eval s03 policy dependencies and state.
	 *
	 * @param string $revision_id Revision id.
	 */
	public function __construct( private string $revision_id ) {
	}
	/**
	 * Authorize.
	 *
	 * @param Access_Policy_Request $request Request.
	 * @return Access_Policy_Decision
	 */
	public function authorize( Access_Policy_Request $request ): Access_Policy_Decision {
		$this->last_request = $request;
		if ( ! $request->request_integrity_verified() ) {
			return Access_Policy_Decision::denied( 403, 'worksheet_evaluation_nonce_denied', $request->correlation_id() );
		}
		$object = $request->object();
		if (
			42 !== $request->actor_user_id()
			|| Worksheet_Evaluation_Rest_Controller::ACTION_ENQUEUE !== $request->action_key()
			|| array( Addon_Capabilities::ASSESS_WORKSHEETS ) !== $request->required_capabilities()
			|| ! $object instanceof Object_Scope
			|| Object_Scope::TYPE_WORKSHEET_REVISION !== $object->type()
			|| $this->revision_id !== $object->id()
			|| 'edit' !== $object->action()
		) {
			return Access_Policy_Decision::denied( 404, 'worksheet_evaluation_scope_denied', $request->correlation_id() );
		}
		return Access_Policy_Decision::allowed(
			$request->correlation_id(),
			null,
			Object_Authorization_Result::allowed( $this->revision_id, 'draft', 'owner' )
		);
	}
}

/**
 * G2-IMP-05-S03 worksheet evaluation enqueue contract.
 */
final class Eval_S03_Request {
	/**
	 * Headers.
	 *
	 * @var array<string,mixed>
	 */
	private array $headers = array();
	/**
	 * Initialize eval s03 request dependencies and state.
	 *
	 * @param array<string,mixed> $headers Headers.
	 * @param array<string,mixed> $json Json.
	 * @param string              $revision_id Revision id.
	 */
	public function __construct( array $headers, private array $json, private string $revision_id ) {
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
		return array( 'revision_id' => $this->revision_id );
	}
}

/**
 * Eval s03 body.
 *
 * @param string $command_id Command identifier used for idempotency.
 * @param string $revision_id Revision id.
 * @param string $source_hash Source hash.
 * @return array<string,mixed>
 */
function eval_s03_body( string $command_id, string $revision_id, string $source_hash ): array {
	return array(
		'command_id' => $command_id,
		'expected'   => array(
			'expected_revision_id' => $revision_id,
			'expected_source_hash' => $source_hash,
		),
		'payload'    => array(),
	);
}

/**
 * Eval s03 request.
 *
 * @param string $key Key.
 * @param array  $body Body.
 * @param string $revision_id Revision id.
 * @param string $nonce Nonce.
 * @return Eval_S03_Request
 */
function eval_s03_request( string $key, array $body, string $revision_id, string $nonce = 'valid-s03-nonce' ): Eval_S03_Request {
	$headers = array( 'X-WP-Nonce' => $nonce );
	if ( '' !== $key ) {
		$headers['Idempotency-Key'] = $key;
	}
	return new Eval_S03_Request( $headers, $body, $revision_id );
}

/**
 * Eval s03 route.
 *
 * @return array<string,mixed>
 * @throws \Throwable Propagates validation or dependency failures.
 */
function eval_s03_route(): array {
	foreach ( $GLOBALS['eval_s03_routes'] as $registration ) {
		if (
			Worksheet_Evaluation_Rest_Controller::REST_NAMESPACE === $registration[0]
			&& Worksheet_Evaluation_Rest_Controller::ROUTE_EVALUATE === $registration[1]
		) {
			return $registration[2];
		}
	}
	throw new RuntimeException( 'C10 evaluate route was not registered.' );
}

$canonical     = new Canonical_Json();
$worksheet_id  = eval_s03_id();
$revision_id   = eval_s03_id();
$response_id   = eval_s03_id();
$document      = ( new Worksheet_Document_Canonicalizer( $canonical, eval_s03_generator( 10 ) ) )->canonicalize(
	array(
		'schema_id'      => Worksheet_Document_Profile::SCHEMA_ID,
		'schema_version' => Worksheet_Document_Profile::SCHEMA_VERSION,
		'editor_profile' => Worksheet_Document_Profile::EDITOR_PROFILE,
		'document_id'    => eval_s03_id(),
		'locale'         => 'ko-KR',
		'title'          => 'S03 evaluation worksheet',
		'nodes'          => array(
			eval_s03_node( 'h1', array(), array( array( 'text' => 'S03 evaluation worksheet' ) ) ),
			eval_s03_node( 'p', array(), array( array( 'text' => 'Read the evidence and explain your answer.' ) ) ),
			eval_s03_node(
				'oj_ai_response',
				array(
					'response_id' => $response_id,
					'kind'        => 'long_text',
					'label'       => 'Explain your reasoning.',
					'required'    => true,
					'constraints' => array(
						'min_chars' => 20,
						'max_chars' => 1000,
					),
					'grading'     => array(
						'grading_enabled'      => true,
						'max_points'           => '5',
						'correct_answer'       => 'The answer cites worksheet evidence.',
						'example_answer'       => 'The stated evidence supports the conclusion.',
						'rubric_criterion_ids' => array(),
						'teacher_note'         => 'Must remain private.',
					),
				)
			),
		),
		'settings'       => array(
			'student_navigation' => 'document',
			'print_profile'      => 'worksheet_a4@1',
		),
	)
);
$projection    = ( new Worksheet_Revision_Projector( $canonical, eval_s03_generator( 100 ) ) )->project( $document, array(), array() );
$source        = new Worksheet_Evaluation_Source(
	$worksheet_id,
	$revision_id,
	'draft',
	$document->document_hash(),
	$document,
	$projection,
	array()
);
$database      = new Eval_S03_Database();
$sources       = new Eval_S03_Source_Port( $source );
$configuration = new Eval_S03_Configuration_Port( eval_s03_configuration() );
$routing       = new Eval_S03_Routing_Port(
	new Worksheet_Evaluation_Routing_Snapshot(
		Addon_Manifest::TASK_WORKSHEET_QUALITY,
		'openai',
		'gpt-5-mini',
		str_repeat( '7', 64 ),
		'routing-s03-v1'
	)
);
$queue         = new Eval_S03_Queue_Port( $database );
$repository    = new WordPress_Worksheet_Evaluation_Repository( $canonical, $database );
$enqueue       = new Enqueue_Worksheet_Evaluation(
	$sources,
	$configuration,
	$routing,
	$repository,
	$queue,
	new Worksheet_Evaluation_Input_Builder( $canonical ),
	eval_s03_generator( 1000 ),
	$canonical,
	static fn (): string => '2026-08-21 01:02:03'
);

$command_one = new Enqueue_Worksheet_Evaluation_Command(
	42,
	$revision_id,
	'command-s03-0001',
	$revision_id,
	$document->document_hash(),
	str_repeat( '1', 64 ),
	's03-correlation-001',
	$revision_id,
	'draft',
	'owner',
	$canonical
);
$first       = $enqueue->execute( $command_one );
eval_s03_same( 'queued', $first->evaluation_state(), 'C10 must create a queued evaluation root.' );
eval_s03_same( 'queued', $first->run_state(), 'C10 must create a queued first run.' );
eval_s03_same( 1, $first->run_no(), 'C10 must start at run one.' );
eval_s03_same( false, $first->replayed(), 'The first C10 command is not a replay.' );
eval_s03_same(
	array(
		'oj_ai_worksheet_evaluations'       => 1,
		'oj_ai_worksheet_evaluation_inputs' => 1,
		'oj_ai_evaluation_runs'             => 1,
		'oj_ai_audit_events'                => 1,
		'oj_ai_outbox_events'               => 1,
	),
	$database->counts(),
	'C10 must atomically create exactly five owner rows.'
);
eval_s03_same( array( 'START TRANSACTION', 'COMMIT' ), $database->transaction_log, 'The first C10 write must commit one transaction.' );
eval_s03_same( 1, $queue->calls, 'The first C10 write must wake the queue once after commit.' );
eval_s03_same( 1, $sources->calls, 'The first C10 write must load its exact source once.' );
eval_s03_same( 1, $configuration->calls, 'The first C10 write must load one frozen configuration.' );
eval_s03_same( 1, $routing->calls, 'The first C10 write must plan routing once.' );

$evaluation_row = $database->tables['oj_ai_worksheet_evaluations'][0];
$run_row        = $database->tables['oj_ai_evaluation_runs'][0];
$outbox_row     = $database->tables['oj_ai_outbox_events'][0];
eval_s03_same( $revision_id, $evaluation_row['worksheet_revision_public_id'], 'Evaluation root must bind the exact revision.' );
eval_s03_same( $document->document_hash(), $evaluation_row['worksheet_revision_hash'], 'Evaluation root must bind the exact source hash.' );
eval_s03_same( Addon_Manifest::TASK_WORKSHEET_QUALITY, $run_row['task_key'], 'Run task key drifted.' );
eval_s03_same( Worksheet_Evaluation_Input_Builder::INPUT_CONTRACT, $run_row['contract_version'], 'Run contract drifted.' );
eval_s03_same( 'worksheet_evaluation.queued', $outbox_row['event_type'], 'First C10 outbox event drifted.' );
eval_s03_same( null, $outbox_row['published_at_utc'], 'The durable queue event must remain unpublished.' );
$authorization = json_decode( $evaluation_row['authorization_envelope_json'], true, 32, JSON_THROW_ON_ERROR );
eval_s03_same( 'worksheet.evaluation.enqueue', $authorization['action_key'], 'Authorization action drifted.' );
eval_s03_same( array( Addon_Capabilities::ASSESS_WORKSHEETS ), $authorization['required_capabilities'], 'Authorization capability drifted.' );
eval_s03_same( $revision_id, $authorization['object']['id'], 'Authorization object revision drifted.' );

$counts_after_first = $database->counts();
$exact              = $enqueue->execute( $command_one );
eval_s03_same( true, $exact->replayed(), 'An exact C10 retry must replay its durable receipt.' );
eval_s03_same( $first->evaluation_id(), $exact->evaluation_id(), 'An exact C10 retry must return the first evaluation.' );
eval_s03_same( $counts_after_first, $database->counts(), 'An exact C10 retry must add zero rows.' );
eval_s03_same( 1, $sources->calls, 'An exact receipt replay must not reload source.' );
eval_s03_same( 1, $configuration->calls, 'An exact receipt replay must not reload configuration.' );
eval_s03_same( 1, $routing->calls, 'An exact receipt replay must not replan routing.' );

$command_two = new Enqueue_Worksheet_Evaluation_Command(
	42,
	$revision_id,
	'command-s03-0002',
	$revision_id,
	$document->document_hash(),
	str_repeat( '2', 64 ),
	's03-correlation-002',
	$revision_id,
	'draft',
	'owner',
	$canonical
);
$semantic    = $enqueue->execute( $command_two );
eval_s03_same( true, $semantic->replayed(), 'Equivalent frozen meaning must return the existing root.' );
eval_s03_same( $first->evaluation_id(), $semantic->evaluation_id(), 'Equivalent frozen meaning must not create a second root.' );
$semantic_counts = $database->counts();
eval_s03_same( 1, $semantic_counts['oj_ai_worksheet_evaluations'], 'Semantic replay must add zero evaluation roots.' );
eval_s03_same( 1, $semantic_counts['oj_ai_worksheet_evaluation_inputs'], 'Semantic replay must add zero inputs.' );
eval_s03_same( 1, $semantic_counts['oj_ai_evaluation_runs'], 'Semantic replay must add zero runs.' );
eval_s03_same( 1, $semantic_counts['oj_ai_audit_events'], 'Semantic replay must add zero audit events.' );
eval_s03_same( 2, $semantic_counts['oj_ai_outbox_events'], 'Semantic replay must bind the new command key to one receipt.' );
$semantic_outbox = $database->tables['oj_ai_outbox_events'][1];
eval_s03_same( 'worksheet_evaluation.enqueue_replayed', $semantic_outbox['event_type'], 'Semantic receipt event drifted.' );
eval_s03_same( $first->evaluation_id(), $semantic_outbox['aggregate_public_id'], 'Semantic receipt must point to the winning root.' );
eval_s03_same( '2026-08-21 01:02:03', $semantic_outbox['published_at_utc'], 'Receipt-only outbox rows must not enter the dispatch queue.' );

$calls_after_semantic = array( $sources->calls, $configuration->calls, $routing->calls );
$semantic_exact       = $enqueue->execute( $command_two );
eval_s03_same( true, $semantic_exact->replayed(), 'A semantic command retry must become an exact receipt replay.' );
eval_s03_same( $semantic_counts, $database->counts(), 'A semantic command exact retry must add zero rows.' );
eval_s03_same( $calls_after_semantic, array( $sources->calls, $configuration->calls, $routing->calls ), 'A bound semantic receipt must short-circuit source, configuration, and routing.' );

$conflicting_command = new Enqueue_Worksheet_Evaluation_Command(
	42,
	$revision_id,
	'command-s03-conflict',
	$revision_id,
	$document->document_hash(),
	str_repeat( '2', 64 ),
	's03-correlation-003',
	$revision_id,
	'draft',
	'owner',
	$canonical
);
eval_s03_fails(
	static fn () => $enqueue->execute( $conflicting_command ),
	Worksheet_Evaluation_Failure::IDEMPOTENCY_CONFLICT,
	'The same caller key with a different command body must conflict.'
);
eval_s03_same( $calls_after_semantic, array( $sources->calls, $configuration->calls, $routing->calls ), 'Idempotency conflict must fail before source access.' );

$configuration->configuration = eval_s03_configuration( str_repeat( 'a', 64 ) );
$command_three                = new Enqueue_Worksheet_Evaluation_Command(
	42,
	$revision_id,
	'command-s03-0003',
	$revision_id,
	$document->document_hash(),
	str_repeat( '3', 64 ),
	's03-correlation-004',
	$revision_id,
	'draft',
	'owner',
	$canonical
);
$changed                      = $enqueue->execute( $command_three );
eval_s03_assert( $first->evaluation_id() !== $changed->evaluation_id(), 'A changed frozen configuration must create a new root.' );
eval_s03_same( 2, $database->counts()['oj_ai_worksheet_evaluations'], 'A changed configuration must create exactly one new root.' );
eval_s03_same( 2, $database->counts()['oj_ai_evaluation_runs'], 'A changed configuration must create exactly one new first run.' );

$before_stale              = $database->counts();
$route_calls_before_stale  = $routing->calls;
$config_calls_before_stale = $configuration->calls;
$stale_command             = new Enqueue_Worksheet_Evaluation_Command(
	42,
	$revision_id,
	'command-s03-stale',
	$revision_id,
	str_repeat( '0', 64 ),
	str_repeat( '4', 64 ),
	's03-correlation-005',
	$revision_id,
	'draft',
	'owner',
	$canonical
);
$stale_failure             = eval_s03_fails(
	static fn () => $enqueue->execute( $stale_command ),
	Worksheet_Evaluation_Failure::SOURCE_STALE,
	'A stale source hash must fail closed.'
);
eval_s03_same( $revision_id, $stale_failure->safe_context()['current_revision_id'], 'Stale recovery data must expose only the current revision ID.' );
eval_s03_same( $document->document_hash(), $stale_failure->safe_context()['current_source_hash'], 'Stale recovery data must expose only the current source hash.' );
eval_s03_same( $before_stale, $database->counts(), 'A stale request must add zero rows.' );
eval_s03_same( $route_calls_before_stale, $routing->calls, 'A stale request must fail before routing.' );
eval_s03_same( $config_calls_before_stale, $configuration->calls, 'A stale request must fail before configuration.' );

$legacy_configuration = eval_s03_configuration();
unset( $legacy_configuration['grading_rubric']['criteria'][0]['levels'] );
$configuration->configuration             = $legacy_configuration;
$before_configuration_failure             = $database->counts();
$route_calls_before_configuration_failure = $routing->calls;
$configuration_failure_command            = new Enqueue_Worksheet_Evaluation_Command(
	42,
	$revision_id,
	'command-s03-legacy-configuration',
	$revision_id,
	$document->document_hash(),
	str_repeat( '5', 64 ),
	's03-correlation-006',
	$revision_id,
	'draft',
	'owner',
	$canonical
);
eval_s03_fails(
	static fn () => $enqueue->execute( $configuration_failure_command ),
	Worksheet_Evaluation_Failure::CONFIGURATION_UNAVAILABLE,
	'A legacy grading rubric must be reported as configuration, not worksheet, failure.'
);
eval_s03_same( $before_configuration_failure, $database->counts(), 'Invalid grading configuration must add zero rows.' );
eval_s03_same( $route_calls_before_configuration_failure, $routing->calls, 'Invalid grading configuration must fail before routing.' );
$policy               = new Eval_S03_Policy( $revision_id );
$correlation_sequence = 100;
$correlations         = new Correlation_Id_Factory(
	static function () use ( &$correlation_sequence ): string {
		return 's03-rest-correlation-' . ( ++$correlation_sequence );
	}
);
$envelopes            = new Rest_Envelope_Factory();
$permissions          = new Rest_Permission_Handoff(
	new WordPress_Rest_Request_Adapter( new WordPress_Current_Actor() ),
	new Rest_Request_Authorizer( $policy, new WordPress_Rest_Nonce_Verifier(), $correlations ),
	$envelopes,
	$correlations
);
$controller           = new Worksheet_Evaluation_Rest_Controller( $permissions, $enqueue, $canonical, $envelopes, $correlations );
$controller->register_routes();
$controller->register_routes();
eval_s03_same( 1, count( $GLOBALS['eval_s03_routes'] ), 'C10 REST route must register once.' );
$route = eval_s03_route();
eval_s03_same( 'POST', $route['methods'], 'C10 must use POST.' );
eval_s03_assert( is_callable( $route['permission_callback'] ?? null ), 'C10 requires a permission callback.' );
eval_s03_assert( is_callable( $route['callback'] ?? null ), 'C10 requires a route callback.' );

$configuration->configuration = eval_s03_configuration( str_repeat( '9', 64 ) );
$rest_request                 = eval_s03_request(
	's03-rest-idempotency-0001',
	eval_s03_body( 'command-s03-rest-0001', $revision_id, $document->document_hash() ),
	$revision_id
);
eval_s03_same( true, call_user_func( $route['permission_callback'], $rest_request ), 'Authorized C10 request must pass the common handoff.' );
eval_s03_same( Worksheet_Evaluation_Rest_Controller::ACTION_ENQUEUE, $policy->last_request?->action_key(), 'C10 action key drifted.' );
eval_s03_same( array( Addon_Capabilities::ASSESS_WORKSHEETS ), $policy->last_request?->required_capabilities(), 'C10 capability drifted.' );
eval_s03_same( Object_Scope::TYPE_WORKSHEET_REVISION, $policy->last_request?->object()?->type(), 'C10 object scope drifted.' );
eval_s03_same( 'edit', $policy->last_request?->object()?->action(), 'C10 object action drifted.' );
eval_s03_same( array( 'valid-s03-nonce', 'wp_rest' ), $GLOBALS['eval_s03_nonce_calls'][ array_key_last( $GLOBALS['eval_s03_nonce_calls'] ) ], 'C10 must verify the standard REST nonce.' );
$rest_response = call_user_func( $route['callback'], $rest_request );
eval_s03_assert( $rest_response instanceof WP_REST_Response, 'C10 success must return WP_REST_Response.' );
eval_s03_same( 202, $rest_response->get_status(), 'C10 success must return 202.' );
$rest_data = $rest_response->get_data();
eval_s03_same( array( 'data', 'meta', 'links', 'actions' ), array_keys( $rest_data ), 'C10 must use the common success envelope.' );
eval_s03_same( false, $rest_data['meta']['idempotent_replay'], 'A new REST C10 request must not report replay.' );
eval_s03_same( $revision_id, $rest_data['data']['evaluation']['worksheet_revision_id'], 'C10 response revision drifted.' );
eval_s03_same( 1, $rest_data['data']['run']['run_no'], 'C10 response run number drifted.' );

$missing_key       = eval_s03_request(
	'',
	eval_s03_body( 'command-s03-rest-0002', $revision_id, $document->document_hash() ),
	$revision_id
);
$missing_key_error = call_user_func( $route['permission_callback'], $missing_key );
eval_s03_assert( $missing_key_error instanceof WP_Error, 'C10 must reject a missing Idempotency-Key.' );
eval_s03_same( 422, $missing_key_error->get_error_data()['status'], 'Missing C10 Idempotency-Key must return 422.' );

$bad_nonce       = eval_s03_request(
	's03-rest-idempotency-0003',
	eval_s03_body( 'command-s03-rest-0003', $revision_id, $document->document_hash() ),
	$revision_id,
	'invalid-nonce'
);
$bad_nonce_error = call_user_func( $route['permission_callback'], $bad_nonce );
eval_s03_assert( $bad_nonce_error instanceof WP_Error, 'C10 must reject an invalid nonce.' );
eval_s03_same( 403, $bad_nonce_error->get_error_data()['status'], 'Invalid C10 nonce must return 403.' );

$invalid_body               = eval_s03_body( 'command-s03-rest-0004', $revision_id, $document->document_hash() );
$invalid_body['unexpected'] = true;
$invalid_request            = eval_s03_request( 's03-rest-idempotency-0004', $invalid_body, $revision_id );
eval_s03_same( true, call_user_func( $route['permission_callback'], $invalid_request ), 'Structurally invalid C10 body is checked after authorization.' );
$invalid_error = call_user_func( $route['callback'], $invalid_request );
eval_s03_assert( $invalid_error instanceof WP_Error, 'Unknown C10 fields must return a problem.' );
eval_s03_same( 422, $invalid_error->get_error_data()['status'], 'Unknown C10 fields must return 422.' );

$stale_request = eval_s03_request(
	's03-rest-idempotency-0005',
	eval_s03_body( 'command-s03-rest-0005', $revision_id, str_repeat( '0', 64 ) ),
	$revision_id
);
eval_s03_same( true, call_user_func( $route['permission_callback'], $stale_request ), 'Stale C10 evidence must reach the application consistency check.' );
$stale_error = call_user_func( $route['callback'], $stale_request );
eval_s03_assert( $stale_error instanceof WP_Error, 'Stale C10 evidence must return a problem.' );
eval_s03_same( Worksheet_Evaluation_Failure::SOURCE_STALE, $stale_error->get_error_code(), 'Stale C10 problem code drifted.' );
eval_s03_same( 409, $stale_error->get_error_data()['status'], 'Stale C10 evidence must return 409.' );
eval_s03_same( $revision_id, $stale_error->get_error_data()['current_revision_id'], 'Stale C10 recovery revision drifted.' );

$progressed_replay = new Enqueue_Worksheet_Evaluation_Result(
	eval_s03_id(),
	'review_required',
	$revision_id,
	$document->document_hash(),
	eval_s03_id(),
	1,
	'succeeded',
	'2026-08-21 01:02:03',
	true
);
eval_s03_same(
	array( 'review_required', 'succeeded' ),
	array( $progressed_replay->evaluation_state(), $progressed_replay->run_state() ),
	'Semantic replay must safely report a run that progressed after enqueue.'
);

echo "G2-IMP-05-S03 worksheet evaluation enqueue contract passed.\n";
