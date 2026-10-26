<?php
/**
 * G4-IMP-01-S03 C29 single submission evaluation enqueue contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

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
use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Context_Scope;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Application\Enqueue_Submission_Evaluation;
use OJAI\Dashboard\Submission\Assessment\Application\Enqueue_Submission_Evaluation_Command;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Routing_Snapshot;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Source;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Score_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Infrastructure\WordPress_Submission_Evaluation_Repository;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Queue_Wakeup_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Routing_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Source_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Presentation\Submission_Evaluation_Rest_Controller;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

require __DIR__ . '/submission-assessment-input-g4-imp-01-s02.php';

defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * G4-IMP-01-S03 C29 single submission evaluation enqueue contract.
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
	 * G4-IMP-01-S03 C29 single submission evaluation enqueue contract.
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

$GLOBALS['g4_s03_actor']       = 42;
$GLOBALS['g4_s03_routes']      = array();
$GLOBALS['g4_s03_nonce_calls'] = array();

if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Get current user id.
	 *
	 * @return int
	 */
	function get_current_user_id(): int {
		return (int) $GLOBALS['g4_s03_actor'];
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
		$GLOBALS['g4_s03_nonce_calls'][] = array( $nonce, $action );
		return 'valid-g4-s03-nonce' === $nonce ? 1 : false;
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
		$GLOBALS['g4_s03_routes'][] = array( $namespace, $route, $arguments );
		return true;
	}
}

/**
 * G4 s03 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s03_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G4 s03 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s03_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G4 s03 failure.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $reason Reason.
 * @param string           $message Message.
 * @return Submission_Evaluation_Failure
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s03_failure( callable $callback, string $reason, string $message ): Submission_Evaluation_Failure {
	try {
		$callback();
	} catch ( Submission_Evaluation_Failure $failure ) {
		g4_s03_same( $reason, $failure->reason(), $message . ' Stable reason mismatch.' );
		return $failure;
	}
	throw new RuntimeException( $message );
}

/**
 * G4 s03 generator.
 *
 * @param int $seed Seed.
 * @return Public_Id_Generator
 */
function g4_s03_generator( int $seed ): Public_Id_Generator {
	$counter = $seed;
	return new Public_Id_Generator(
		static function () use ( &$counter ): string {
			++$counter;
			return pack( 'N4', 0, 0, 0, $counter );
		}
	);
}

/**
 * G4-IMP-01-S03 C29 single submission evaluation enqueue contract.
 */
final class G4_S03_Database {
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
	 * Fail table.
	 *
	 * @var string|null
	 */
	public ?string $fail_table = null;
	/**
	 * Tables.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public array $tables = array(
		'oj_ai_submission_evaluations'       => array(),
		'oj_ai_submission_evaluation_inputs' => array(),
		'oj_ai_evaluation_runs'              => array(),
		'oj_ai_audit_events'                 => array(),
		'oj_ai_outbox_events'                => array(),
	);
	/**
	 * Transaction log.
	 *
	 * @var array<int,string>
	 */
	public array $transaction_log = array();
	/** @var array<string,mixed>|null Current official submission for manual preparation. */
	public ?array $manual_source = null;
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
	 * Initialize g4 s03 database dependencies and state.
	 */
	public function __construct( bool $manual = false ) {
		if ( $manual ) {
			$this->tables['oj_ai_submission_evaluation_results'] = array();
			$this->tables['oj_ai_submission_evaluation_items'] = array();
		}
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
			$replacement = 'd' === $placeholder[1]
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
		if ( str_starts_with( $sql, 'SELECT s.official_revision_public_id' ) ) {
			return $this->manual_source;
		}
		if ( str_contains( $sql, 'FROM oj_ai_outbox_events' ) ) {
			$key = $this->where_string( $sql, 'idempotency_key' );
			foreach ( $this->tables['oj_ai_outbox_events'] as $row ) {
				if ( $key === ( $row['idempotency_key'] ?? null ) ) {
					return array_intersect_key( $row, array_flip( array( 'aggregate_public_id', 'event_type', 'payload_schema', 'payload_version', 'payload_json', 'payload_hash' ) ) );
				}
			}
			return null;
		}
		if ( str_contains( $sql, 'WHERE e.idempotency_key' ) ) {
			$key = $this->where_string( $sql, 'e.idempotency_key' );
			foreach ( $this->tables['oj_ai_submission_evaluations'] as $evaluation ) {
				if ( $key === ( $evaluation['idempotency_key'] ?? null ) ) {
					return $this->joined_evaluation( $evaluation );
				}
			}
			return null;
		}
		if ( preg_match( '/WHERE e\.id = ([0-9]+)/', $sql, $matches ) ) {
			foreach ( $this->tables['oj_ai_submission_evaluations'] as $evaluation ) {
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
		if ( $this->fail_table === $table ) {
			$this->fail_table = null;
			$this->last_error = 'injected insert failure';
			return false;
		}
		if ( ! isset( $this->tables[ $table ] ) ) {
			$this->last_error = 'unknown table';
			return false;
		}
		foreach ( $this->tables[ $table ] as $row ) {
			if ( isset( $data['public_id'] ) && ( $row['public_id'] ?? null ) === $data['public_id'] ) {
				$this->last_error = 'duplicate public id';
				return false;
			}
			if ( isset( $data['idempotency_key'], $row['idempotency_key'] ) && $row['idempotency_key'] === $data['idempotency_key'] ) {
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
		foreach ( $this->tables['oj_ai_submission_evaluation_inputs'] as $candidate ) {
			if ( (int) $candidate['submission_evaluation_id'] === (int) $evaluation['id'] ) {
				$input = $candidate;
				break;
			}
		}
		$run = null;
		foreach ( $this->tables['oj_ai_evaluation_runs'] as $candidate ) {
			if (
				'submission_evaluation' === ( $candidate['subject_type'] ?? null )
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
			'id'                              => $evaluation['id'],
			'evaluation_public_id'            => $evaluation['public_id'],
			'evaluation_state'                => $evaluation['state'],
			'submission_revision_public_id'   => $evaluation['submission_revision_public_id'],
			'submission_revision_hash'        => $evaluation['submission_revision_hash'],
			'worksheet_publication_public_id' => $evaluation['worksheet_publication_public_id'],
			'publication_hash'                => $evaluation['publication_hash'],
			'requested_at_utc'                => $evaluation['requested_at_utc'],
			'input_hash'                      => $input['input_hash'],
			'artifact_references_hash'        => $input['artifact_references_hash'],
			'input_public_id'                 => $input['public_id'],
			'run_public_id'                   => $run['public_id'],
			'run_no'                          => $run['run_no'],
			'run_state'                       => $run['state'],
		);
	}
}

/**
 * G4-IMP-01-S03 C29 single submission evaluation enqueue contract.
 */
final class G4_S03_Source_Port implements Submission_Evaluation_Source_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Initialize g4 s03 source port dependencies and state.
	 *
	 * @param Submission_Evaluation_Source $source Source.
	 */
	public function __construct( public Submission_Evaluation_Source $source ) {
	}
	/**
	 * Find official.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param string $official_revision_id Official revision id.
	 * @return Submission_Evaluation_Source|null
	 */
	public function find_official( string $submission_id, string $official_revision_id, bool $manual = false ): ?Submission_Evaluation_Source {
		++$this->calls;
		return $submission_id === $this->source->submission_id() && $official_revision_id === $this->source->official_revision_id()
			? $this->source
			: null;
	}
}

/**
 * G4-IMP-01-S03 C29 single submission evaluation enqueue contract.
 */
final class G4_S03_Routing_Port implements Submission_Evaluation_Routing_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Initialize g4 s03 routing port dependencies and state.
	 *
	 * @param Submission_Evaluation_Routing_Snapshot $snapshot Snapshot.
	 */
	public function __construct( private Submission_Evaluation_Routing_Snapshot $snapshot ) {
	}
	/**
	 * Plan.
	 *
	 * @return Submission_Evaluation_Routing_Snapshot
	 */
	public function plan(): Submission_Evaluation_Routing_Snapshot {
		++$this->calls;
		return $this->snapshot;
	}
}

/**
 * G4-IMP-01-S03 C29 single submission evaluation enqueue contract.
 */
final class G4_S03_Queue_Port implements Submission_Evaluation_Queue_Wakeup_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Initialize g4 s03 queue port dependencies and state.
	 *
	 * @param G4_S03_Database $database Database.
	 */
	public function __construct( private G4_S03_Database $database ) {
	}
	/**
	 * Wake.
	 *
	 * @return void
	 */
	public function wake(): void {
		g4_s03_assert( ! $this->database->in_transaction, 'C29 queue wakeup must happen after commit.' );
		g4_s03_assert( count( $this->database->tables['oj_ai_evaluation_runs'] ) > 0, 'C29 queue wakeup requires a durable run.' );
		++$this->calls;
	}
}

/**
 * G4-IMP-01-S03 C29 single submission evaluation enqueue contract.
 */
final class G4_S03_Policy implements Access_Policy_Port_Interface {
	/**
	 * Last request.
	 *
	 * @var Access_Policy_Request|null
	 */
	public ?Access_Policy_Request $last_request = null;
	/**
	 * Initialize g4 s03 policy dependencies and state.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 */
	public function __construct( private string $submission_id, private string $context_id, private string $context_revision ) {
	}
	/**
	 * Authorize.
	 *
	 * @param Access_Policy_Request $request Request.
	 * @return Access_Policy_Decision
	 */
	public function authorize( Access_Policy_Request $request ): Access_Policy_Decision {
		$this->last_request = $request;
		$context            = $request->context();
		$object             = $request->object();
		$manual             = 'submission.evaluation.manual' === $request->action_key();
		if (
			! $request->request_integrity_verified()
			|| 42 !== $request->actor_user_id()
			|| ( ! $manual && Submission_Evaluation_Rest_Controller::ACTION_ENQUEUE !== $request->action_key() )
			|| array( $manual ? Addon_Capabilities::REVIEW_ASSESSMENTS : Addon_Capabilities::ASSESS_SUBMISSIONS ) !== $request->required_capabilities()
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

/**
 * G4-IMP-01-S03 C29 single submission evaluation enqueue contract.
 */
final class G4_S03_Request {
	/**
	 * Headers.
	 *
	 * @var array<string,mixed>
	 */
	private array $headers = array();
	/**
	 * Initialize g4 s03 request dependencies and state.
	 *
	 * @param array<string,mixed> $headers Headers.
	 * @param array<string,mixed> $json Json.
	 * @param string              $submission_id Submission public identifier.
	 * @param array<string,mixed> $query Query.
	 */
	public function __construct( array $headers, private array $json, private string $submission_id, private array $query ) {
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
		return array( 'submission_id' => $this->submission_id );
	}
	/**
	 * Get query params.
	 *
	 * @return array<string,mixed>
	 */
	public function get_query_params(): array {
		return $this->query;
	}
}

/**
 * G4 s03 body.
 *
 * @param string $command_id Command identifier used for idempotency.
 * @param string $revision_id Revision id.
 * @param string $revision_hash Revision hash.
 * @return array<string,mixed>
 */
function g4_s03_body( string $command_id, string $revision_id, string $revision_hash ): array {
	return array(
		'command_id' => $command_id,
		'expected'   => array(
			'expected_official_revision_hash' => $revision_hash,
			'expected_official_revision_id'   => $revision_id,
		),
		'payload'    => array(),
	);
}

/**
 * G4 s03 application.
 *
 * @param G4_S03_Database                $database Database.
 * @param Submission_Evaluation_Source   $source Source.
 * @param Canonical_Json                 $canonical Canonical JSON encoder used for stable digests.
 * @param Submission_Score_Input_Builder $builder Builder.
 * @param int                            $seed Seed.
 * @return array{0:Enqueue_Submission_Evaluation,1:G4_S03_Source_Port,2:G4_S03_Routing_Port,3:G4_S03_Queue_Port}
 */
function g4_s03_application(
	G4_S03_Database $database,
	Submission_Evaluation_Source $source,
	Canonical_Json $canonical,
	Submission_Score_Input_Builder $builder,
	int $seed
): array {
	$sources = new G4_S03_Source_Port( $source );
	$routing = new G4_S03_Routing_Port(
		new Submission_Evaluation_Routing_Snapshot(
			Addon_Manifest::TASK_STUDENT_ASSESSMENT,
			'provider-g4',
			'model-g4',
			hash( 'sha256', 'g4-s03-profile' ),
			'route-g4-s03-r1'
		)
	);
	$queue   = new G4_S03_Queue_Port( $database );
	return array(
		new Enqueue_Submission_Evaluation(
			$sources,
			$routing,
			new WordPress_Submission_Evaluation_Repository( $canonical, $database ),
			$queue,
			$builder,
			g4_s03_generator( $seed ),
			$canonical,
			static fn (): string => '2026-08-23 01:02:03'
		),
		$sources,
		$routing,
		$queue,
	);
}

g4_s03_assert( isset( $canonical, $builder, $official_revision, $publication, $configuration, $artifacts ), 'S02 fixture did not load.' );
g4_s03_assert( $canonical instanceof Canonical_Json, 'S02 canonical fixture is unavailable.' );
g4_s03_assert( $builder instanceof Submission_Score_Input_Builder, 'S02 input builder fixture is unavailable.' );
g4_s03_assert( Public_Id::is_valid( $submission_id ) && Public_Id::is_valid( $revision_id ), 'S03 source IDs must remain public IDs.' );

$context_id                              = 'class-g4-s03';
$context_revision                        = 'class-g4-s03-r7';
$source                                  = new Submission_Evaluation_Source(
	$submission_id,
	3,
	'submitted',
	'class',
	$context_id,
	$context_revision,
	$official_revision,
	$publication,
	$configuration,
	$artifacts
);
$database                                = new G4_S03_Database();
[ $enqueue, $sources, $routing, $queue ] = g4_s03_application( $database, $source, $canonical, $builder, 8000 );

$command_one = new Enqueue_Submission_Evaluation_Command(
	42,
	$submission_id,
	'command-g4-s03-001',
	$revision_id,
	$revision_hash,
	hash( 'sha256', 'g4-s03-key-1' ),
	'correlation-g4-s03-1',
	'class',
	$context_id,
	$context_revision,
	'3',
	'submitted',
	'context_staff',
	$canonical
);
$first       = $enqueue->execute( $command_one );
g4_s03_same( false, $first->replayed(), 'First C29 enqueue must create a new evaluation.' );
g4_s03_same( $submission_id, $first->submission_id(), 'C29 receipt submission drifted.' );
g4_s03_same( $revision_id, $first->submission_revision_id(), 'C29 receipt official revision drifted.' );
g4_s03_same( $publication_id, $first->publication_id(), 'C29 receipt publication drifted.' );
g4_s03_same(
	array(
		'oj_ai_submission_evaluations'       => 1,
		'oj_ai_submission_evaluation_inputs' => 1,
		'oj_ai_evaluation_runs'              => 1,
		'oj_ai_audit_events'                 => 1,
		'oj_ai_outbox_events'                => 1,
	),
	$database->counts(),
	'C29 first enqueue must create exactly root/input/run/audit/outbox.'
);
g4_s03_same( array( 'START TRANSACTION', 'COMMIT' ), $database->transaction_log, 'C29 first enqueue transaction order drifted.' );
g4_s03_same( 1, $queue->calls, 'C29 first enqueue must wake the queue once after commit.' );

$root      = $database->tables['oj_ai_submission_evaluations'][0];
$input_row = $database->tables['oj_ai_submission_evaluation_inputs'][0];
$run       = $database->tables['oj_ai_evaluation_runs'][0];
$audit     = $database->tables['oj_ai_audit_events'][0];
$outbox    = $database->tables['oj_ai_outbox_events'][0];
g4_s03_same( $revision_id, $root['submission_revision_public_id'], 'Evaluation root must pin the official revision.' );
g4_s03_same( $publication['publication_hash'], $root['publication_hash'], 'Evaluation root must pin the publication hash.' );
g4_s03_same( 'queued', $root['state'], 'Evaluation root must start queued.' );
g4_s03_same( Submission_Score_Input_Builder::OPERATION_CONTRACT, $input_row['projection_schema_version'], 'Input projection contract drifted.' );
g4_s03_same( 'oj_ai.submission_artifact_references', $input_row['artifact_references_schema'], 'Private artifact reference schema drifted.' );
g4_s03_same( Submission_Score_Input_Builder::REDACTION_VERSION, $input_row['redaction_version'], 'Input redaction version drifted.' );
g4_s03_same( 'submission_evaluation', $run['subject_type'], 'First run subject type drifted.' );
g4_s03_same( Addon_Manifest::TASK_STUDENT_ASSESSMENT, $run['task_key'], 'First run task drifted.' );
g4_s03_same( Submission_Score_Input_Builder::OUTER_INPUT_CONTRACT, $run['contract_version'], 'First run outer contract drifted.' );
g4_s03_same( Submission_Score_Input_Builder::OPERATION, $run['operation_key'], 'First run operation drifted.' );
g4_s03_same( 'class', $audit['context_type'], 'Audit must retain the authorized Context type.' );
g4_s03_same( $context_revision, $audit['context_revision'], 'Audit must retain the authorized Context revision.' );
g4_s03_same( 'submission_evaluation.queued', $outbox['event_type'], 'C29 queue event drifted.' );
g4_s03_same( null, $outbox['published_at_utc'], 'First queue event must remain dispatchable.' );
g4_s03_assert( ! str_contains( $input_row['input_json'], $submission_id ), 'Gateway input must not expose the submission ID.' );
g4_s03_assert( str_contains( $input_row['artifact_references_json'], $attachment_ids[0] ), 'Private artifact provenance must stay outside Gateway input.' );

$calls_before_exact  = array( $sources->calls, $routing->calls );
$counts_before_exact = $database->counts();
$exact               = $enqueue->execute( $command_one );
g4_s03_same( true, $exact->replayed(), 'Exact C29 retry must replay its durable receipt.' );
g4_s03_same( $counts_before_exact, $database->counts(), 'Exact C29 retry must add zero rows.' );
g4_s03_same( $calls_before_exact, array( $sources->calls, $routing->calls ), 'Exact C29 retry must short-circuit source and routing.' );

$command_two = new Enqueue_Submission_Evaluation_Command(
	42,
	$submission_id,
	'command-g4-s03-002',
	$revision_id,
	$revision_hash,
	hash( 'sha256', 'g4-s03-key-2' ),
	'correlation-g4-s03-2',
	'class',
	$context_id,
	$context_revision,
	'3',
	'submitted',
	'context_staff',
	$canonical
);
$semantic    = $enqueue->execute( $command_two );
g4_s03_same( true, $semantic->replayed(), 'Equivalent official meaning must reuse the existing evaluation root.' );
g4_s03_same( $first->evaluation_id(), $semantic->evaluation_id(), 'Semantic replay must return the winning evaluation root.' );
$semantic_counts = $database->counts();
g4_s03_same( 1, $semantic_counts['oj_ai_submission_evaluations'], 'Semantic replay must add zero evaluation roots.' );
g4_s03_same( 1, $semantic_counts['oj_ai_submission_evaluation_inputs'], 'Semantic replay must add zero input rows.' );
g4_s03_same( 1, $semantic_counts['oj_ai_evaluation_runs'], 'Semantic replay must add zero first runs.' );
g4_s03_same( 1, $semantic_counts['oj_ai_audit_events'], 'Semantic replay must add zero audit events.' );
g4_s03_same( 2, $semantic_counts['oj_ai_outbox_events'], 'Semantic replay must bind the second command to one receipt.' );
$semantic_outbox = $database->tables['oj_ai_outbox_events'][1];
g4_s03_same( 'submission_evaluation.enqueue_replayed', $semantic_outbox['event_type'], 'Semantic receipt event drifted.' );
g4_s03_same( '2026-08-23 01:02:03', $semantic_outbox['published_at_utc'], 'Receipt-only event must not enter the dispatch queue.' );

$semantic_calls = array( $sources->calls, $routing->calls );
$semantic_exact = $enqueue->execute( $command_two );
g4_s03_same( true, $semantic_exact->replayed(), 'Semantic command retry must become an exact receipt replay.' );
g4_s03_same( $semantic_counts, $database->counts(), 'Semantic command exact retry must add zero rows.' );
g4_s03_same( $semantic_calls, array( $sources->calls, $routing->calls ), 'Bound semantic receipt must short-circuit source and routing.' );

$conflicting_command   = new Enqueue_Submission_Evaluation_Command(
	42,
	$submission_id,
	'command-g4-s03-conflict',
	$revision_id,
	$revision_hash,
	hash( 'sha256', 'g4-s03-key-1' ),
	'correlation-g4-s03-3',
	'class',
	$context_id,
	$context_revision,
	'3',
	'submitted',
	'context_staff',
	$canonical
);
$calls_before_conflict = array( $sources->calls, $routing->calls );
g4_s03_failure(
	static fn () => $enqueue->execute( $conflicting_command ),
	Submission_Evaluation_Failure::IDEMPOTENCY_CONFLICT,
	'Same Idempotency-Key fingerprint with a different command body must fail before source access.'
);
g4_s03_same( $calls_before_conflict, array( $sources->calls, $routing->calls ), 'Idempotency conflict must not access source or routing.' );

$stale_command        = new Enqueue_Submission_Evaluation_Command(
	42,
	$submission_id,
	'command-g4-s03-stale',
	$revision_id,
	hash( 'sha256', 'stale-source' ),
	hash( 'sha256', 'g4-s03-key-stale' ),
	'correlation-g4-s03-4',
	'class',
	$context_id,
	$context_revision,
	'3',
	'submitted',
	'context_staff',
	$canonical
);
$routing_before_stale = $routing->calls;
$stale_failure        = g4_s03_failure(
	static fn () => $enqueue->execute( $stale_command ),
	Submission_Evaluation_Failure::SOURCE_STALE,
	'C29 expected official hash CAS must fail closed.'
);
g4_s03_same( $revision_hash, $stale_failure->safe_context()['current_official_revision_hash'] ?? null, 'Stale recovery data must expose only the current official hash.' );
g4_s03_same( $routing_before_stale, $routing->calls, 'Stale official source must fail before routing.' );
g4_s03_same( $semantic_counts, $database->counts(), 'Stale C29 request must add zero rows.' );

$rollback_database                         = new G4_S03_Database();
$rollback_database->fail_table             = 'oj_ai_audit_events';
[ $rollback_enqueue, , , $rollback_queue ] = g4_s03_application( $rollback_database, $source, $canonical, $builder, 9000 );
$rollback_command                          = new Enqueue_Submission_Evaluation_Command(
	42,
	$submission_id,
	'command-g4-s03-rollback',
	$revision_id,
	$revision_hash,
	hash( 'sha256', 'g4-s03-key-rollback' ),
	'correlation-g4-s03-5',
	'class',
	$context_id,
	$context_revision,
	'3',
	'submitted',
	'context_staff',
	$canonical
);
g4_s03_failure(
	static fn () => $rollback_enqueue->execute( $rollback_command ),
	Submission_Evaluation_Failure::STORAGE_UNAVAILABLE,
	'Any C29 write failure must roll back all five resources.'
);
g4_s03_same( array( 0, 0, 0, 0, 0 ), array_values( $rollback_database->counts() ), 'C29 rollback left a partial resource.' );
g4_s03_same( 'ROLLBACK', $rollback_database->transaction_log[ array_key_last( $rollback_database->transaction_log ) ], 'C29 failure must end in rollback.' );
g4_s03_same( 0, $rollback_queue->calls, 'C29 rollback must not wake the queue.' );

$policy       = new G4_S03_Policy( $submission_id, $context_id, $context_revision );
$correlations = new Correlation_Id_Factory();
$envelopes    = new Rest_Envelope_Factory();
$permissions  = new Rest_Permission_Handoff(
	new WordPress_Rest_Request_Adapter( new WordPress_Current_Actor() ),
	new Rest_Request_Authorizer( $policy, new WordPress_Rest_Nonce_Verifier(), $correlations ),
	$envelopes,
	$correlations
);
$controller   = new Submission_Evaluation_Rest_Controller( $permissions, $enqueue, $canonical, $envelopes, $correlations );
$controller->register_routes();
g4_s03_same( 2, count( $GLOBALS['g4_s03_routes'] ), 'AI and teacher preparation routes must register exactly once.' );
g4_s03_same( Submission_Evaluation_Rest_Controller::ROUTE_EVALUATE, $GLOBALS['g4_s03_routes'][1][1], 'C29 route path drifted.' );

$rest_request = new G4_S03_Request(
	array(
		'X-WP-Nonce'      => 'valid-g4-s03-nonce',
		'Idempotency-Key' => 'rest-g4-s03-key-001',
	),
	g4_s03_body( 'command-g4-s03-rest', $revision_id, $revision_hash ),
	$submission_id,
	array(
		'context_type'     => 'class',
		'context_id'       => $context_id,
		'context_revision' => $context_revision,
	)
);
g4_s03_same( true, $controller->permissions_evaluate( $rest_request ), 'C29 permission callback must accept exact nonce, capability, Context, and object scope.' );
$rest_response = $controller->evaluate( $rest_request );
g4_s03_assert( $rest_response instanceof WP_REST_Response, 'C29 success must use the common REST response envelope.' );
g4_s03_same( 202, $rest_response->get_status(), 'C29 success status drifted.' );
$rest_data = $rest_response->get_data();
g4_s03_same( $first->evaluation_id(), $rest_data['data']['evaluation']['id'] ?? null, 'C29 REST semantic replay returned the wrong evaluation.' );
g4_s03_same( $submission_id, $rest_data['data']['evaluation']['submission_id'] ?? null, 'C29 REST response submission drifted.' );
g4_s03_same( true, $rest_data['meta']['idempotent_replay'] ?? null, 'C29 REST response must identify semantic replay.' );
g4_s03_assert( $policy->last_request instanceof Access_Policy_Request, 'C29 REST did not execute the access policy.' );

$bad_body               = g4_s03_body( 'command-g4-s03-bad', $revision_id, $revision_hash );
$bad_body['unexpected'] = true;
$bad_request            = new G4_S03_Request(
	array(
		'X-WP-Nonce'      => 'valid-g4-s03-nonce',
		'Idempotency-Key' => 'rest-g4-s03-key-002',
	),
	$bad_body,
	$submission_id,
	array(
		'context_type'     => 'class',
		'context_id'       => $context_id,
		'context_revision' => $context_revision,
	)
);
g4_s03_same( true, $controller->permissions_evaluate( $bad_request ), 'C29 permission must not parse command body.' );
$bad_response = $controller->evaluate( $bad_request );
g4_s03_assert( $bad_response instanceof WP_Error, 'C29 unknown body fields must return a problem.' );
g4_s03_same( 422, $bad_response->get_error_data()['status'] ?? null, 'C29 unknown body field status drifted.' );

$bad_query_request = new G4_S03_Request(
	array(
		'X-WP-Nonce'      => 'valid-g4-s03-nonce',
		'Idempotency-Key' => 'rest-g4-s03-key-003',
	),
	g4_s03_body( 'command-g4-s03-bad-query', $revision_id, $revision_hash ),
	$submission_id,
	array(
		'context_type'     => 'class',
		'context_id'       => $context_id,
		'context_revision' => $context_revision,
		'extra'            => 'no',
	)
);
$bad_query_problem = $controller->permissions_evaluate( $bad_query_request );
g4_s03_assert( $bad_query_problem instanceof WP_Error, 'C29 unknown Context query fields must fail closed.' );
g4_s03_same( 422, $bad_query_problem->get_error_data()['status'] ?? null, 'C29 unknown Context query status drifted.' );

$runtime_files  = array(
	$addon_root . '/src/Submission/Assessment/Application/Enqueue_Submission_Evaluation.php',
	$addon_root . '/src/Submission/Assessment/Infrastructure/Platform_Submission_Evaluation_Routing.php',
	$addon_root . '/src/Submission/Assessment/Infrastructure/WordPress_Submission_Evaluation_Source.php',
	$addon_root . '/src/Submission/Assessment/Infrastructure/WordPress_Submission_Evaluation_Repository.php',
	$addon_root . '/src/Submission/Assessment/Presentation/Submission_Evaluation_Rest_Controller.php',
	$addon_root . '/src/Submission/Student/Infrastructure/Submission_Object_Authorization.php',
	$addon_root . '/src/Core/Composition_Root.php',
);
$joined_runtime = '';
foreach ( $runtime_files as $file ) {
	$source_text = file_get_contents( $file );
	g4_s03_assert( is_string( $source_text ), 'C29 runtime file is unreadable: ' . $file );
	$joined_runtime .= "\n" . $source_text;
	foreach ( array( '$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\\OjPlatform\\Kernel\\' ) as $forbidden ) {
		g4_s03_assert( ! str_contains( $source_text, $forbidden ), 'C29 runtime crossed an add-on boundary: ' . $forbidden );
	}
}
foreach ( array(
	"'oj_ai_submission_evaluations'",
	"'oj_ai_submission_evaluation_inputs'",
	"'oj_ai_evaluation_runs'",
	"'oj_ai_audit_events'",
	"'oj_ai_outbox_events'",
	'Context_Scope::OPERATION_EVALUATE',
	'Addon_Capabilities::ASSESS_SUBMISSIONS',
	"'evaluate'",
	'Addon_Manifest::TASK_STUDENT_ASSESSMENT',
) as $marker ) {
	g4_s03_assert( str_contains( $joined_runtime, $marker ), 'C29 runtime marker is missing: ' . $marker );
}
$routing_source = (string) file_get_contents( $runtime_files[1] );
g4_s03_assert( str_contains( $routing_source, '->canRoute(' ) && str_contains( $routing_source, '->snapshot(' ), 'C29 must use the public platform routing API.' );
g4_s03_assert( ! str_contains( $routing_source, '->execute(' ), 'C29 enqueue must not execute the AI Gateway synchronously.' );

echo "G4-IMP-01-S03 passed: strict C29, official CAS, evaluate policy, atomic root/input/run/audit/outbox, exact and semantic replay, rollback, and public routing.\n";

$manual_db = new G4_S03_Database( true );
$manual_db->manual_source = array( 'official_revision_public_id' => $revision_id, 'content_hash' => $revision_hash, 'official_review_public_id' => null );
[ $manual_app, $manual_sources, $manual_routing, $manual_queue ] = g4_s03_application( $manual_db, $source, $canonical, $builder, 20000 );
$manual_command = new Enqueue_Submission_Evaluation_Command( 42, $submission_id, 'manual-command-001', $revision_id, $revision_hash, hash( 'sha256', 'manual-001' ), 'manual-correlation', 'class', $context_id, $context_revision, '3', 'submitted', 'context_staff', $canonical, true );
$manual_result = $manual_app->execute( $manual_command );
g4_s03_same( 'review_required', $manual_result->evaluation_state(), 'Manual preparation must open teacher scoring immediately.' );
g4_s03_same( 0, $manual_routing->calls, 'Manual preparation must never resolve AI routing.' );
g4_s03_same( 0, $manual_queue->calls, 'Manual preparation must never enqueue AI work.' );
$prepared_run = $manual_db->tables['oj_ai_evaluation_runs'][0];
g4_s03_same( 'manual_prepare', $prepared_run['operation_key'], 'Local preparation must be distinguishable from AI scoring.' );
g4_s03_same( null, $prepared_run['provider_key'], 'No invented AI provider for manual scoring.' );
$prepared = $manual_db->tables['oj_ai_submission_evaluation_results'][0];
g4_s03_same( null, $prepared['candidate_score'], 'An empty score sheet must not fabricate an AI score.' );
g4_s03_same( 'not_requested', json_decode( $prepared['normalized_result_json'], true )['provider_status'], 'Manual provenance must survive persistence.' );
g4_s03_same( $prepared['public_id'], $prepared_run['result_public_id'], 'Preparation and score sheet must be linked.' );
g4_s03_assert( count( $manual_db->tables['oj_ai_submission_evaluation_items'] ) > 0, 'Manual sheet must retain every rubric binding.' );
$before = $manual_db->tables;
g4_s03_same( true, $manual_app->execute( $manual_command )->replayed(), 'Manual retry must replay.' );
g4_s03_same( $before, $manual_db->tables, 'Manual retry must not duplicate a score sheet.' );
g4_s03_same( 0, $manual_queue->calls, 'Manual retry must not wake an AI worker.' );
$rollback_db = new G4_S03_Database( true );
$rollback_db->manual_source = $manual_db->manual_source;
$rollback_db->fail_table = 'oj_ai_submission_evaluation_items';
[ $rollback_app ] = g4_s03_application( $rollback_db, $source, $canonical, $builder, 21000 );
g4_s03_failure( fn () => $rollback_app->execute( $manual_command ), Submission_Evaluation_Failure::STORAGE_UNAVAILABLE, 'Manual item failure must roll back.' );
g4_s03_same( 0, array_sum( array_map( 'count', $rollback_db->tables ) ), 'No partial manual roots, runs, scores, or audit on failure.' );
$rollback_db->manual_source['official_review_public_id'] = $manual_result->evaluation_id();
g4_s03_failure( fn () => $rollback_app->execute( $manual_command ), Submission_Evaluation_Failure::SOURCE_STALE, 'Existing approval must be withdrawn before a new manual assessment.' );

$manual_controller = new Submission_Evaluation_Rest_Controller( $permissions, $manual_app, $canonical, $envelopes, $correlations );
$manual_request = new G4_S03_Request( array( 'X-WP-Nonce' => 'valid-g4-s03-nonce', 'Idempotency-Key' => 'manual-rest-001' ), g4_s03_body( 'manual-rest-001', $revision_id, $revision_hash ), $submission_id, array( 'context_type' => 'class', 'context_id' => $context_id, 'context_revision' => $context_revision ) );
g4_s03_same( true, $manual_controller->permissions_manual( $manual_request ), 'Manual REST allows teacher review capability with submission/context scope.' );
g4_s03_same( array( Addon_Capabilities::REVIEW_ASSESSMENTS ), $policy->last_request->required_capabilities(), 'Manual grading does not require AI assessment capability.' );
$manual_response = $manual_controller->manual( $manual_request );
g4_s03_assert( $manual_response instanceof WP_REST_Response && 200 === $manual_response->get_status(), 'Manual REST semantic replay responds immediately, not as a queued AI job.' );
g4_s03_same( 0, $manual_routing->calls, 'Manual REST does not resolve AI routing.' );
g4_s03_same( 0, $manual_queue->calls, 'Manual REST does not wake AI workers.' );
$invalid_nonce = new G4_S03_Request( array( 'X-WP-Nonce' => 'invalid', 'Idempotency-Key' => 'manual-rest-invalid' ), g4_s03_body( 'manual-rest-invalid', $revision_id, $revision_hash ), $submission_id, array( 'context_type' => 'class', 'context_id' => $context_id, 'context_revision' => $context_revision ) );
g4_s03_assert( $manual_controller->permissions_manual( $invalid_nonce ) instanceof WP_Error, 'Manual grading retains nonce enforcement.' );

// Exercise the real attachment adapter without any AI representations or private-file reads.
$expected_attachments = $validator->validate_complete( $publication['response_schema'], $official_revision['response']['answers'] )['attachments'];
$attachment_db = new class {
	public string $last_error = '';
	public array $rows = array();
	public function prepare( string $sql, ...$args ): string { return $sql; }
	public function get_row( string $sql, $mode ): ?array { throw new RuntimeException( 'Manual preparation must not read AI-derived files.' ); }
	public function get_results( string $sql, $mode ): array { return $this->rows; }
};
foreach ( $expected_attachments as $attachment_id => $definition ) {
	$attachment_db->rows[] = array( 'public_id' => $attachment_id, 'response_key' => $definition['response_id'], 'state' => 'bound', 'mime_type' => $definition['mime_types'][0], 'byte_size' => 100, 'sha256' => hash( 'sha256', 'source-' . $attachment_id ), 'ai_representation_json' => null );
}
$source_class = new ReflectionClass( \OJAI\Dashboard\Submission\Assessment\Infrastructure\WordPress_Submission_Evaluation_Source::class );
$attachment_adapter = $source_class->newInstanceWithoutConstructor();
foreach ( array( 'canonical' => $canonical, 'responses' => $validator, 'database' => $attachment_db ) as $property => $value ) {
	$source_class->getProperty( $property )->setValue( $attachment_adapter, $value );
}
$attachment_method = $source_class->getMethod( 'artifacts' );
$manual_artifacts = $attachment_method->invoke( $attachment_adapter, $submission_id, $revision_id, $official_revision, $publication, true );
g4_s03_same( count( $expected_attachments ), count( $manual_artifacts ), 'Manual preparation retains each original attachment.' );
g4_s03_same( 'teacher_original_review', $manual_artifacts[0]['representation']['reason_code'], 'Manual provenance must not masquerade as AI extraction.' );
$attachment_input = $builder->build( $evaluation_ref, $submission_id, $revision_id, $official_revision, $publication, $configuration, $manual_artifacts );
g4_s03_same( count( $expected_attachments ), count( $attachment_input->artifact_references() ), 'Manual source can be pinned without AI extraction.' );
g4_s03_failure( fn () => $attachment_method->invoke( $attachment_adapter, $submission_id, $revision_id, $official_revision, $publication, false ), Submission_Evaluation_Failure::ARTIFACT_NOT_READY, 'AI mode must still require representations.' );
$attachment_db->rows[0]['state'] = 'pending';
try {
	$attachment_method->invoke( $attachment_adapter, $submission_id, $revision_id, $official_revision, $publication, true );
	throw new RuntimeException( 'Manual grading must reject an unbound attachment.' );
} catch ( Submission_Evaluation_Failure $failure ) {
	g4_s03_same( Submission_Evaluation_Failure::SOURCE_INVALID, $failure->reason(), 'Manual scoring preserves original-file integrity checks.' );
}
echo "Teacher-only preparation passed: no AI routing/queue, pinned rubric, no fabricated score, replay, atomic rollback, approval protection.\n";
