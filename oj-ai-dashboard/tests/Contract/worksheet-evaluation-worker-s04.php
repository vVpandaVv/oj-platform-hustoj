<?php
/**
 * G2-IMP-05-S04 worksheet evaluation worker and output contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Platform\Addon_Manifest;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Canonicalizer;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Profile;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Revision_Projector;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Run_Next_Worksheet_Evaluation;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Run_Worksheet_Evaluation_Result;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Worksheet_Evaluation_Worker_Failure;
use OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Gateway_Result;
use OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Input_Builder;
use OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Normalized_Result;
use OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Output_Normalizer;
use OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Routing_Snapshot;
use OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Source;
use OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Work_Item;
use OJAI\Dashboard\Worksheet\Evaluation\Infrastructure\WordPress_Worksheet_Evaluation_Execution_Consent;
use OJAI\Dashboard\Worksheet\Evaluation\Infrastructure\Worksheet_Evaluation_Worker_Module;
use OJAI\Dashboard\Worksheet\Evaluation\Port\Worksheet_Evaluation_Execution_Consent_Port_Interface;
use OJAI\Dashboard\Worksheet\Evaluation\Port\Worksheet_Evaluation_Execution_Port_Interface;
use OJAI\Dashboard\Worksheet\Evaluation\Port\Worksheet_Evaluation_Source_Port_Interface;
use OJAI\Dashboard\Worksheet\Evaluation\Port\Worksheet_Evaluation_Work_Repository_Port_Interface;

$addon_root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );

if ( ! class_exists( 'Normalizer' ) ) {
	/**
	 * G2-IMP-05-S04 worksheet evaluation worker and output contract.
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

$GLOBALS['eval_s04_filters']   = array();
$GLOBALS['eval_s04_actions']   = array();
$GLOBALS['eval_s04_scheduled'] = array();
$GLOBALS['eval_s04_cleared']   = array();

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * Add filter.
	 *
	 * @param string $hook Hook.
	 * @param mixed  $callback Callback.
	 * @return bool
	 */
	function add_filter( string $hook, $callback ): bool {
		$GLOBALS['eval_s04_filters'][ $hook ] = $callback;
		return true;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Add action.
	 *
	 * @param string $hook Hook.
	 * @param mixed  $callback Callback.
	 * @return bool
	 */
	function add_action( string $hook, $callback ): bool {
		$GLOBALS['eval_s04_actions'][ $hook ] = $callback;
		return true;
	}
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	/**
	 * Wp next scheduled.
	 *
	 * @param string $hook Hook.
	 * @return mixed
	 */
	function wp_next_scheduled( string $hook ) {
		unset( $hook );
		return false;
	}
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
	/**
	 * Wp schedule event.
	 *
	 * @param int    $timestamp Timestamp.
	 * @param string $schedule Schedule.
	 * @param string $hook Hook.
	 * @return bool
	 */
	function wp_schedule_event( int $timestamp, string $schedule, string $hook ): bool {
		$GLOBALS['eval_s04_scheduled'][] = array( $timestamp, $schedule, $hook );
		return true;
	}
}
if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	/**
	 * Wp clear scheduled hook.
	 *
	 * @param string $hook Hook.
	 * @return int
	 */
	function wp_clear_scheduled_hook( string $hook ): int {
		$GLOBALS['eval_s04_cleared'][] = $hook;
		return 1;
	}
}

require_once $addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core'
	. DIRECTORY_SEPARATOR . 'Autoloader.php';
( new OJAI\Dashboard\Core\Autoloader( $addon_root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * Eval s04 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function eval_s04_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Eval s04 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function eval_s04_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . "\nExpected: " . var_export( $expected, true )
			. "\nActual: " . var_export( $actual, true )
		);
	}
}

/**
 * Eval s04 id.
 *
 * @return string
 */
function eval_s04_id(): string {
	static $suffix = 4000;
	++$suffix;
	$id = '01K' . str_pad( (string) $suffix, 23, '0', STR_PAD_LEFT );
	eval_s04_assert( Public_Id::is_valid( $id ), 'S04 fixture ID must satisfy PublicIdV1.' );
	return $id;
}

/**
 * Eval s04 generator.
 *
 * @param int $seed Seed.
 * @return Public_Id_Generator
 */
function eval_s04_generator( int $seed ): Public_Id_Generator {
	$counter = $seed;
	return new Public_Id_Generator(
		static function ( int $length ) use ( &$counter ): string {
			unset( $length );
			++$counter;
			return pack( 'N4', 0, 0, 0, $counter );
		}
	);
}

/**
 * Eval s04 dimensions.
 *
 * @return array<int,array<string,mixed>>
 */
function eval_s04_dimensions(): array {
	$result = array();
	foreach ( Worksheet_Evaluation_Input_Builder::CORE_DIMENSIONS as $key ) {
		$result[] = array(
			'dimension_key'     => $key,
			'label'             => ucwords( str_replace( '_', ' ', $key ) ),
			'description'       => 'Assess the worksheet using explicit evidence.',
			'evidence_required' => true,
		);
	}
	return $result;
}

/**
 * Eval s04 configuration.
 *
 * @return array<string,mixed>
 */
function eval_s04_configuration(): array {
	return array(
		'policy'         => array(
			'revision_id'  => eval_s04_id(),
			'content_hash' => str_repeat( 'b', 64 ),
			'subject_type' => 'worksheet_quality',
		),
		'rubric'         => array(
			'revision_id'  => eval_s04_id(),
			'content_hash' => str_repeat( 'c', 64 ),
			'dimensions'   => eval_s04_dimensions(),
		),
		'grading_rubric' => array(
			'assessment_enabled' => true,
			'revision_id'        => eval_s04_id(),
			'content_hash'       => str_repeat( 'a', 64 ),
			'total_points'       => '10',
			'criteria'           => array(
				array(
					'criterion_id'   => eval_s04_id(),
					'label'          => 'Evidence and reasoning',
					'description'    => 'Check whether the submitted work uses relevant evidence and explains the reasoning.',
					'maximum_points' => '10',
					'position'       => 0,
					'scope'          => 'submission',
					'levels'         => array(
						array(
							'label'     => 'Uses relevant evidence and explains the reasoning.',
							'level_key' => eval_s04_id(),
							'points'    => '10',
							'position'  => 0,
						),
						array(
							'label'     => 'Evidence or reasoning is missing.',
							'level_key' => eval_s04_id(),
							'points'    => '0',
							'position'  => 1,
						),
					),
				),
			),
		),
		'review_rule'    => array(
			'revision_id'               => eval_s04_id(),
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
 * Eval s04 fixture.
 *
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @param array|null     $content Content.
 * @return array{source:Worksheet_Evaluation_Source,input:array<string,mixed>,input_hash:string,node_id:string}
 */
function eval_s04_fixture( Canonical_Json $canonical, ?array $content = null ): array {
	$worksheet_id = eval_s04_id();
	$revision_id  = eval_s04_id();
	$heading_id   = eval_s04_id();
	$node_id      = eval_s04_id();
	$document     = ( new Worksheet_Document_Canonicalizer( $canonical, eval_s04_generator( 40 ) ) )->canonicalize(
		array(
			'schema_id'      => Worksheet_Document_Profile::SCHEMA_ID,
			'schema_version' => Worksheet_Document_Profile::SCHEMA_VERSION,
			'editor_profile' => Worksheet_Document_Profile::EDITOR_PROFILE,
			'document_id'    => eval_s04_id(),
			'locale'         => 'ko-KR',
			'title'          => 'S04 evaluation worksheet',
			'nodes'          => array(
				array(
					'type'     => 'h1',
					'node_id'  => $heading_id,
					'children' => array( array( 'text' => 'S04 evaluation worksheet' ) ),
				),
				array_merge(
					$content ?? array(
						'type'     => 'p',
						'children' => array( array( 'text' => 'Explain the answer with worksheet evidence.' ) ),
					),
					array( 'node_id' => $node_id )
				),
			),
			'settings'       => array(
				'student_navigation' => 'document',
				'print_profile'      => 'worksheet_a4@1',
			),
		)
	);
	$projection   = ( new Worksheet_Revision_Projector( $canonical, eval_s04_generator( 80 ) ) )->project( $document, array(), array() );
	$source       = new Worksheet_Evaluation_Source(
		$worksheet_id,
		$revision_id,
		'draft',
		$document->document_hash(),
		$document,
		$projection,
		array()
	);
	$input        = ( new Worksheet_Evaluation_Input_Builder( $canonical ) )->build(
		$worksheet_id,
		$revision_id,
		$document,
		$projection,
		array(),
		eval_s04_configuration()
	);
	return array(
		'source'     => $source,
		'input'      => $input->input(),
		'input_hash' => $input->input_hash(),
		'node_id'    => $node_id,
	);
}

/**
 * Eval s04 output.
 *
 * @param array $fixture Fixture.
 * @return array<string,mixed>
 */
function eval_s04_output( array $fixture ): array {
	$dimensions = array();
	foreach ( Worksheet_Evaluation_Input_Builder::CORE_DIMENSIONS as $key ) {
		$dimensions[] = array(
			'dimension_key' => $key,
			'rating'        => 'instruction_clarity' === $key ? 'minor_issue' : 'meets',
			'confidence'    => 0.9,
			'summary'       => 'The dimension was checked against the supplied worksheet.',
		);
	}
	return array(
		'schema_id'      => Worksheet_Evaluation_Input_Builder::OUTPUT_SCHEMA,
		'schema_version' => Worksheet_Evaluation_Input_Builder::OUTPUT_VERSION,
		'source_hash'    => $fixture['source']->source_hash(),
		'status'         => 'completed',
		'summary'        => 'The worksheet is usable after one clarity improvement.',
		'abstain_reason' => null,
		'dimensions'     => $dimensions,
		'findings'       => array(
			array(
				'dimension_key'  => 'instruction_clarity',
				'severity'       => 'minor',
				'title'          => 'Clarify the evidence requirement',
				'rationale'      => 'The direction does not name the expected evidence form.',
				'evidence'       => array(
					array(
						'anchor_type' => 'node',
						'anchor_id'   => $fixture['node_id'],
						'field'       => 'text',
						'quote'       => 'Explain the answer with worksheet evidence.',
					),
				),
				'recommendation' => 'Name the evidence type expected in the response.',
				'confidence'     => 0.88,
			),
		),
		'limitations'    => array(),
	);
}

/**
 * G2-IMP-05-S04 worksheet evaluation worker and output contract.
 */
final class Eval_S04_Repository implements Worksheet_Evaluation_Work_Repository_Port_Interface {
	/**
	 * Idle.
	 *
	 * @var bool
	 */
	public bool $idle = false;
	/**
	 * Stale start.
	 *
	 * @var bool
	 */
	public bool $stale_start = false;
	/**
	 * Stale commit.
	 *
	 * @var bool
	 */
	public bool $stale_commit = false;
	/**
	 * Claim calls.
	 *
	 * @var int
	 */
	public int $claim_calls = 0;
	/**
	 * Start calls.
	 *
	 * @var int
	 */
	public int $start_calls = 0;
	/**
	 * Success.
	 *
	 * @var Worksheet_Evaluation_Normalized_Result|null
	 */
	public ?Worksheet_Evaluation_Normalized_Result $success = null;
	/**
	 * Failure.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $failure = null;
	/**
	 * Evaluation id.
	 *
	 * @var string
	 */
	private string $evaluation_id;
	/**
	 * Run id.
	 *
	 * @var string
	 */
	private string $run_id;

	/**
	 * Initialize eval s04 repository dependencies and state.
	 *
	 * @param array<string,mixed>                   $fixture Fixture.
	 * @param Worksheet_Evaluation_Routing_Snapshot $route Route.
	 * @param int                                   $attempt_count Attempt count.
	 * @param string                                $queued_at_utc Queued at utc.
	 */
	public function __construct(
		private array $fixture,
		private Worksheet_Evaluation_Routing_Snapshot $route,
		private int $attempt_count = 0,
		private string $queued_at_utc = '2026-08-21 11:00:00'
	) {
		$this->evaluation_id = eval_s04_id();
		$this->run_id        = eval_s04_id();
	}

	/**
	 * Claim due.
	 *
	 * @param string $now_utc Now utc.
	 * @param string $lease_token Lease token.
	 * @param string $lease_expires_at_utc Lease expires at utc.
	 * @return Worksheet_Evaluation_Work_Item|null
	 */
	public function claim_due( string $now_utc, string $lease_token, string $lease_expires_at_utc ): ?Worksheet_Evaluation_Work_Item {
		unset( $now_utc, $lease_expires_at_utc );
		++$this->claim_calls;
		if ( $this->idle ) {
			return null;
		}
		$source = $this->fixture['source'];
		return new Worksheet_Evaluation_Work_Item(
			1,
			1,
			$this->evaluation_id,
			$this->run_id,
			42,
			$source->revision_id(),
			$source->source_hash(),
			$source->state(),
			Worksheet_Evaluation_Input_Builder::INPUT_CONTRACT,
			$this->fixture['input_hash'],
			'run:' . str_repeat( 'a', 64 ),
			$this->fixture['input'],
			$this->route,
			$lease_token,
			2,
			$this->attempt_count,
			0,
			'leased',
			$this->queued_at_utc
		);
	}

	/**
	 * Start attempt.
	 *
	 * @param Worksheet_Evaluation_Work_Item $item Item.
	 * @param string                         $attempt_public_id Attempt public id.
	 * @param string                         $started_at_utc Started at utc.
	 * @param string                         $lease_expires_at_utc Lease expires at utc.
	 * @return Worksheet_Evaluation_Work_Item|null
	 */
	public function start_attempt( Worksheet_Evaluation_Work_Item $item, string $attempt_public_id, string $started_at_utc, string $lease_expires_at_utc ): ?Worksheet_Evaluation_Work_Item {
		unset( $attempt_public_id, $started_at_utc, $lease_expires_at_utc );
		++$this->start_calls;
		return $this->stale_start ? null : $item->started( $item->attempt_count() + 1, $item->lock_version() + 1 );
	}

	/**
	 * Complete success.
	 *
	 * @param Worksheet_Evaluation_Work_Item         $item Item.
	 * @param Worksheet_Evaluation_Gateway_Result    $gateway Gateway.
	 * @param Worksheet_Evaluation_Normalized_Result $result Result.
	 * @param string                                 $result_public_id Result public id.
	 * @param string                                 $audit_public_id Audit public id.
	 * @param string                                 $outbox_public_id Outbox public id.
	 * @param string                                 $finished_at_utc Finished at utc.
	 * @return bool
	 */
	public function complete_success(
		Worksheet_Evaluation_Work_Item $item,
		Worksheet_Evaluation_Gateway_Result $gateway,
		Worksheet_Evaluation_Normalized_Result $result,
		string $result_public_id,
		string $audit_public_id,
		string $outbox_public_id,
		string $finished_at_utc
	): bool {
		unset( $item, $gateway, $result_public_id, $audit_public_id, $outbox_public_id, $finished_at_utc );
		$this->success = $result;
		return ! $this->stale_commit;
	}

	/**
	 * Complete failure.
	 *
	 * @param Worksheet_Evaluation_Work_Item             $item Item.
	 * @param string                                     $attempt_state Attempt state.
	 * @param string                                     $error_code Error code.
	 * @param string|null                                $gateway_correlation_id Gateway correlation id.
	 * @param string|null                                $provider_output_digest Provider output digest.
	 * @param int|null                                   $duration_ms Duration ms.
	 * @param Worksheet_Evaluation_Routing_Snapshot|null $actual_routing Actual routing.
	 * @param bool                                       $retry Retry.
	 * @param string                                     $available_at_utc Available at utc.
	 * @param string                                     $audit_public_id Audit public id.
	 * @param string                                     $outbox_public_id Outbox public id.
	 * @param string                                     $finished_at_utc Finished at utc.
	 * @param string|null                                $error_message Error message.
	 * @return bool
	 */
	public function complete_failure(
		Worksheet_Evaluation_Work_Item $item,
		string $attempt_state,
		string $error_code,
		?string $gateway_correlation_id,
		?string $provider_output_digest,
		?int $duration_ms,
		?Worksheet_Evaluation_Routing_Snapshot $actual_routing,
		bool $retry,
		string $available_at_utc,
		string $audit_public_id,
		string $outbox_public_id,
		string $finished_at_utc,
		?string $error_message = null
	): bool {
		unset( $audit_public_id, $outbox_public_id );
		$this->failure = compact(
			'item',
			'attempt_state',
			'error_code',
			'gateway_correlation_id',
			'provider_output_digest',
			'duration_ms',
			'actual_routing',
			'retry',
			'available_at_utc',
			'finished_at_utc',
			'error_message'
		);
		return ! $this->stale_commit;
	}
}

/**
 * G2-IMP-05-S04 worksheet evaluation worker and output contract.
 */
final class Eval_S04_Execution implements Worksheet_Evaluation_Execution_Port_Interface {
	/**
	 * Snapshot calls.
	 *
	 * @var int
	 */
	public int $snapshot_calls = 0;
	/**
	 * Execute calls.
	 *
	 * @var int
	 */
	public int $execute_calls = 0;
	/**
	 * Initialize eval s04 execution dependencies and state.
	 *
	 * @param array<int,Worksheet_Evaluation_Routing_Snapshot|null> $snapshots Snapshots.
	 * @param Worksheet_Evaluation_Gateway_Result                   $result Result.
	 */
	public function __construct( private array $snapshots, public Worksheet_Evaluation_Gateway_Result $result ) {
	}
	/**
	 * Snapshot.
	 *
	 * @param string $task_key Task key.
	 * @return Worksheet_Evaluation_Routing_Snapshot|null
	 */
	public function snapshot( string $task_key ): ?Worksheet_Evaluation_Routing_Snapshot {
		unset( $task_key );
		++$this->snapshot_calls;
		return array_shift( $this->snapshots );
	}
	/**
	 * Execute eval s04 execution.
	 *
	 * @param Worksheet_Evaluation_Work_Item $item Item.
	 * @return Worksheet_Evaluation_Gateway_Result
	 */
	public function execute( Worksheet_Evaluation_Work_Item $item ): Worksheet_Evaluation_Gateway_Result {
		unset( $item );
		++$this->execute_calls;
		return $this->result;
	}
}

/**
 * G2-IMP-05-S04 worksheet evaluation worker and output contract.
 */
final class Eval_S04_Consent implements Worksheet_Evaluation_Execution_Consent_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Initialize eval s04 consent dependencies and state.
	 *
	 * @param bool $allowed Allowed.
	 */
	public function __construct( public bool $allowed ) {
	}
	/**
	 * Allows.
	 *
	 * @param Worksheet_Evaluation_Work_Item $item Item.
	 * @return bool
	 */
	public function allows( Worksheet_Evaluation_Work_Item $item ): bool {
		unset( $item );
		++$this->calls;
		return $this->allowed;
	}
}

/**
 * G2-IMP-05-S04 worksheet evaluation worker and output contract.
 */
final class Eval_S04_Source_Port implements Worksheet_Evaluation_Source_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Initialize eval s04 source port dependencies and state.
	 *
	 * @param Worksheet_Evaluation_Source $source Source.
	 * @param array<int,bool>             $availability Availability.
	 */
	public function __construct( private Worksheet_Evaluation_Source $source, private array $availability = array( true, true ) ) {
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
		$available = array() === $this->availability ? true : (bool) array_shift( $this->availability );
		return $available && 42 === $actor_user_id && $revision_id === $this->source->revision_id() ? $this->source : null;
	}
}

/**
 * G2-IMP-05-S04 worksheet evaluation worker and output contract.
 */
final class Eval_S04_Policy implements Access_Policy_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Initialize eval s04 policy dependencies and state.
	 *
	 * @param string          $revision_id Revision id.
	 * @param array<int,bool> $decisions Decisions.
	 */
	public function __construct( private string $revision_id, private array $decisions = array( true, true ) ) {
	}
	/**
	 * Authorize.
	 *
	 * @param Access_Policy_Request $request Request.
	 * @return Access_Policy_Decision
	 */
	public function authorize( Access_Policy_Request $request ): Access_Policy_Decision {
		++$this->calls;
		eval_s04_same( Run_Next_Worksheet_Evaluation::ACTION_EXECUTE, $request->action_key(), 'Worker action drifted.' );
		eval_s04_same( array( Addon_Capabilities::ASSESS_WORKSHEETS ), $request->required_capabilities(), 'Worker capability drifted.' );
		$allowed = array() === $this->decisions ? true : (bool) array_shift( $this->decisions );
		return $allowed
			? Access_Policy_Decision::allowed(
				$request->correlation_id(),
				null,
				Object_Authorization_Result::allowed( $this->revision_id, 'draft', 'owner' )
			)
			: Access_Policy_Decision::denied( 403, 'worker_authorization_revoked', $request->correlation_id() );
	}
}

/**
 * Assemble a worker with independently replaceable boundary doubles.
 *
 * @param Eval_S04_Repository  $repository Job storage double.
 * @param Eval_S04_Execution   $execution Execution gateway double.
 * @param Eval_S04_Consent     $consent Consent double.
 * @param Eval_S04_Source_Port $sources Source snapshot double.
 * @param Eval_S04_Policy      $policy Authorization double.
 * @param Canonical_Json       $canonical Canonical encoder.
 * @return Run_Next_Worksheet_Evaluation
 */
function eval_s04_worker(
	Eval_S04_Repository $repository,
	Eval_S04_Execution $execution,
	Eval_S04_Consent $consent,
	Eval_S04_Source_Port $sources,
	Eval_S04_Policy $policy,
	Canonical_Json $canonical
): Run_Next_Worksheet_Evaluation {
	return new Run_Next_Worksheet_Evaluation(
		$repository,
		$execution,
		$consent,
		$sources,
		$policy,
		new Worksheet_Evaluation_Output_Normalizer( $canonical ),
		eval_s04_generator( 400 ),
		static fn (): string => '2026-08-21 12:00:00',
		static fn (): string => '123e4567-e89b-42d3-a456-426614174000'
	);
}

/**
 * Eval s04 scenario.
 *
 * @param array<string,mixed>                                        $fixture Fixture.
 * @param Canonical_Json                                             $canonical Canonical JSON encoder used for stable digests.
 * @param Worksheet_Evaluation_Routing_Snapshot                      $route Route.
 * @param Worksheet_Evaluation_Gateway_Result                        $gateway Gateway.
 * @param array<int,Worksheet_Evaluation_Routing_Snapshot|null>|null $snapshots Snapshots.
 * @param bool                                                       $consent Consent.
 * @param array                                                      $policy Policy.
 * @param array                                                      $sources Sources.
 * @param int                                                        $attempt_count Attempt count.
 * @param string                                                     $queued_at_utc Queued at utc.
 * @return array
 */
function eval_s04_scenario(
	array $fixture,
	Canonical_Json $canonical,
	Worksheet_Evaluation_Routing_Snapshot $route,
	Worksheet_Evaluation_Gateway_Result $gateway,
	?array $snapshots = null,
	bool $consent = true,
	array $policy = array( true, true ),
	array $sources = array( true, true ),
	int $attempt_count = 0,
	string $queued_at_utc = '2026-08-21 11:00:00'
): array {
	$repository   = new Eval_S04_Repository( $fixture, $route, $attempt_count, $queued_at_utc );
	$execution    = new Eval_S04_Execution( $snapshots ?? array( $route, $route ), $gateway );
	$consent_port = new Eval_S04_Consent( $consent );
	$source_port  = new Eval_S04_Source_Port( $fixture['source'], $sources );
	$policy_port  = new Eval_S04_Policy( $fixture['source']->revision_id(), $policy );
	$worker       = eval_s04_worker( $repository, $execution, $consent_port, $source_port, $policy_port, $canonical );
	return compact( 'repository', 'execution', 'consent_port', 'source_port', 'policy_port', 'worker' );
}

/**
 * Eval s04 worker failure.
 *
 * @param callable():void $callback Callback.
 * @param string          $reason Reason.
 * @param string          $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function eval_s04_worker_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback();
	} catch ( Worksheet_Evaluation_Worker_Failure $failure ) {
		eval_s04_same( $reason, $failure->reason(), $message );
		return;
	}
	throw new RuntimeException( $message . ': expected failure was not thrown.' );
}

$canonical       = new Canonical_Json();
$fixture         = eval_s04_fixture( $canonical );
$route           = new Worksheet_Evaluation_Routing_Snapshot(
	Addon_Manifest::TASK_WORKSHEET_QUALITY,
	'openai',
	'gpt-5-mini',
	str_repeat( '7', 64 ),
	'routing-s04-v1'
);
$changed_route   = new Worksheet_Evaluation_Routing_Snapshot(
	Addon_Manifest::TASK_WORKSHEET_QUALITY,
	'openai',
	'gpt-5-mini',
	str_repeat( '8', 64 ),
	'routing-s04-v2'
);
$output          = eval_s04_output( $fixture );
$success_gateway = Worksheet_Evaluation_Gateway_Result::success( $output, $route, 'gateway-correlation-0001', 125 );

$scenario                     = eval_s04_scenario( $fixture, $canonical, $route, $success_gateway );
$scenario['repository']->idle = true;
eval_s04_same( Run_Worksheet_Evaluation_Result::IDLE, $scenario['worker']->run_once()->outcome(), 'Empty queue must be idle.' );
eval_s04_same( 0, $scenario['execution']->execute_calls, 'Idle worker must not execute the Gateway.' );

$scenario = eval_s04_scenario( $fixture, $canonical, $route, $success_gateway, null, false );
eval_s04_same( Run_Worksheet_Evaluation_Result::FAILED, $scenario['worker']->run_once()->outcome(), 'Missing external processing consent must fail closed.' );
eval_s04_same( 'external_processing_not_approved', $scenario['repository']->failure['error_code'], 'Consent denial reason drifted.' );
eval_s04_same( 0, $scenario['execution']->execute_calls, 'Consent denial must happen before Gateway execution.' );
eval_s04_same( 0, $scenario['repository']->start_calls, 'Consent denial must happen before an attempt starts.' );

$scenario = eval_s04_scenario( $fixture, $canonical, $route, $success_gateway );
$result   = $scenario['worker']->run_once();
eval_s04_same( Run_Worksheet_Evaluation_Result::SUCCEEDED, $result->outcome(), 'Valid output must commit successfully.' );
eval_s04_same( 1, $scenario['execution']->execute_calls, 'A claimed run must execute the Gateway exactly once.' );
eval_s04_same( 2, $scenario['execution']->snapshot_calls, 'Worker must perform preflight and postflight routing checks.' );
eval_s04_same( 2, $scenario['policy_port']->calls, 'Authorization must be checked before and after execution.' );
eval_s04_same( 2, $scenario['source_port']->calls, 'Source must be checked before and after execution.' );
eval_s04_same( 'needs_attention', $scenario['repository']->success->overall_status(), 'Normalized overall status drifted.' );
eval_s04_same( 1, count( $scenario['repository']->success->items() ), 'Finding projection must be stored once.' );
eval_s04_assert( ! str_contains( $scenario['repository']->success->normalized_json(), 'raw_response' ), 'Normalized storage must not contain raw provider responses.' );

$normalizer = new Worksheet_Evaluation_Output_Normalizer( $canonical );
$first      = $normalizer->normalize( $output, $fixture['input'] );
$second     = $normalizer->normalize( $output, $fixture['input'] );
eval_s04_same( $first->normalized_hash(), $second->normalized_hash(), 'Equivalent output must normalize deterministically.' );
eval_s04_same( $first->provider_output_digest(), $second->provider_output_digest(), 'Provider output digest must be deterministic.' );

// Reject incomplete or keyed dimensions without recording model-generated content.
$dimension_cases = array(
	array( array(), 'array count=0' ),
	array( array_slice( $output['dimensions'], 0, 1 ), 'array count=1' ),
	array( array_column( $output['dimensions'], null, 'dimension_key' ), 'object count=6' ),
	array( 'private generated text', 'string count=n/a' ),
	array( null, 'NULL count=n/a' ),
);
foreach ( $dimension_cases as [ $raw_dimensions, $diagnostic ] ) {
	$invalid_dimensions               = $output;
	$invalid_dimensions['dimensions'] = $raw_dimensions;
	$dimension_scenario               = eval_s04_scenario(
		$fixture,
		$canonical,
		$route,
		Worksheet_Evaluation_Gateway_Result::success( $invalid_dimensions, $route, 'gateway-dimension-shape', 100 )
	);
	eval_s04_same( Run_Worksheet_Evaluation_Result::FAILED, $dimension_scenario['worker']->run_once()->outcome(), 'Invalid dimensions must fail the worker.' );
	eval_s04_same( Worksheet_Evaluation_Worker_Failure::OUTPUT_SCHEMA_INVALID, $dimension_scenario['repository']->failure['error_code'], 'Dimension shape remains a schema failure.' );
	eval_s04_same( '$.dimensions (expected array count=6; received ' . $diagnostic . ')', $dimension_scenario['repository']->failure['error_message'], 'Only structural metadata belongs in the diagnostic.' );
	eval_s04_same( null, $dimension_scenario['repository']->success, 'Incomplete dimensions must never become a saved evaluation.' );
}

// Formatting boundaries must not change the literal text used as evidence.
$quote_cases = array(
	array(
		array(
			'type'     => 'blockquote',
			'children' => array(
				array(
					'type'     => 'p',
					'node_id'  => eval_s04_id(),
					'children' => array(
						array( 'text' => '개인' ),
						array(
							'text' => '정보',
							'bold' => true,
						),
						array( 'text' => '를 보호합니다.' ),
					),
				),
			),
		),
		'개인정보를 보호합니다.',
	),
	array(
		array(
			'type'     => 'blockquote',
			'children' => array(
				array(
					'type'     => 'p',
					'node_id'  => eval_s04_id(),
					'children' => array(
						array( 'text' => 'Use ' ),
						array(
							'text'   => 'relevant',
							'italic' => true,
						),
						array( 'text' => ' evidence.' ),
					),
				),
			),
		),
		'Use relevant evidence.',
	),
	array(
		array(
			'type'     => 'p',
			'children' => array(
				array( 'text' => 'Read the ' ),
				array(
					'type'     => 'a',
					'node_id'  => eval_s04_id(),
					'url'      => 'https://example.org/source',
					'children' => array( array( 'text' => 'source' ) ),
				),
				array( 'text' => ' carefully.' ),
			),
		),
		'Read the source carefully.',
	),
	array(
		array(
			'type'     => 'blockquote',
			'children' => array(
				array(
					'type'     => 'p',
					'node_id'  => eval_s04_id(),
					'children' => array( array( 'text' => 'First paragraph.' ) ),
				),
				array(
					'type'     => 'p',
					'node_id'  => eval_s04_id(),
					'children' => array( array( 'text' => 'Second paragraph.' ) ),
				),
			),
		),
		"First paragraph.\nSecond paragraph.",
	),
);
foreach ( $quote_cases as [ $content, $quote ] ) {
	$quoted_fixture                                       = eval_s04_fixture( $canonical, $content );
	$quoted_output                                        = eval_s04_output( $quoted_fixture );
	$quoted_output['findings'][0]['evidence'][0]['quote'] = $quote;
	$quoted_result                                        = $normalizer->normalize( $quoted_output, $quoted_fixture['input'] );
	eval_s04_same( $quote, json_decode( $quoted_result->normalized_json(), true )['findings'][0]['evidence'][0]['quote'], 'Literal quotation must survive formatting boundaries.' );
	$quoted_scenario = eval_s04_scenario(
		$quoted_fixture,
		$canonical,
		$route,
		Worksheet_Evaluation_Gateway_Result::success( $quoted_output, $route, 'gateway-quoted-evidence', 100 )
	);
	eval_s04_same( Run_Worksheet_Evaluation_Result::SUCCEEDED, $quoted_scenario['worker']->run_once()->outcome(), 'A formatted quotation must complete the worker, not only normalization.' );
	foreach ( array( 'Invented quotation.', str_replace( ' ', '', $quote ) . '!', '> ' . $quote ) as $unfaithful_quote ) {
		$invalid_quote                                        = $quoted_output;
		$invalid_quote['findings'][0]['evidence'][0]['quote'] = $unfaithful_quote;
		eval_s04_worker_failure(
			static fn () => $normalizer->normalize( $invalid_quote, $quoted_fixture['input'] ),
			Worksheet_Evaluation_Worker_Failure::OUTPUT_DOMAIN_INVALID,
			'Invented, rewritten or markdown-prefixed quotations must remain rejected.'
		);
	}
	$wrong_anchor                                        = $quoted_output;
	$wrong_anchor['findings'][0]['evidence'][0]['quote'] = 'S04 evaluation worksheet';
	eval_s04_worker_failure(
		static fn () => $normalizer->normalize( $wrong_anchor, $quoted_fixture['input'] ),
		Worksheet_Evaluation_Worker_Failure::OUTPUT_DOMAIN_INVALID,
		'Evidence from a different valid node must not be borrowed.'
	);
}

$invalid_output                 = $output;
$invalid_output['raw_response'] = 'must not persist';
eval_s04_worker_failure(
	static fn () => $normalizer->normalize( $invalid_output, $fixture['input'] ),
	Worksheet_Evaluation_Worker_Failure::OUTPUT_PRIVACY_INVALID,
	'Forbidden provider fields must fail closed.'
);
$invalid_anchor = $output;
$invalid_anchor['findings'][0]['evidence'][0]['anchor_id'] = eval_s04_id();
eval_s04_worker_failure(
	static fn () => $normalizer->normalize( $invalid_anchor, $fixture['input'] ),
	Worksheet_Evaluation_Worker_Failure::OUTPUT_DOMAIN_INVALID,
	'Unknown evidence anchors must fail closed.'
);
$oversize            = $output;
$oversize['summary'] = str_repeat( 'x', Worksheet_Evaluation_Output_Normalizer::MAXIMUM_OUTPUT_BYTES );
eval_s04_worker_failure(
	static fn () => $normalizer->normalize( $oversize, $fixture['input'] ),
	Worksheet_Evaluation_Worker_Failure::OUTPUT_TOO_LARGE,
	'Oversize provider output must fail before normalization.'
);

$scenario = eval_s04_scenario(
	$fixture,
	$canonical,
	$route,
	Worksheet_Evaluation_Gateway_Result::success( $invalid_output, $route, 'gateway-correlation-0002', 100 )
);
eval_s04_same( Run_Worksheet_Evaluation_Result::FAILED, $scenario['worker']->run_once()->outcome(), 'Invalid output must terminate the run.' );
eval_s04_same( Worksheet_Evaluation_Worker_Failure::OUTPUT_PRIVACY_INVALID, $scenario['repository']->failure['error_code'], 'Invalid output reason drifted.' );
eval_s04_same( null, $scenario['repository']->success, 'Invalid output must never write a result.' );

$scenario = eval_s04_scenario( $fixture, $canonical, $route, $success_gateway, array( $route, $changed_route ) );
eval_s04_same( Run_Worksheet_Evaluation_Result::FAILED, $scenario['worker']->run_once()->outcome(), 'Routing drift must discard successful provider output.' );
eval_s04_same( 'routing_changed_during_execute', $scenario['repository']->failure['error_code'], 'Routing drift reason changed.' );
eval_s04_assert( is_string( $scenario['repository']->failure['provider_output_digest'] ), 'Discarded output must retain only a digest.' );
eval_s04_same( null, $scenario['repository']->success, 'Routing drift must not commit a result.' );

$retry_gateway = Worksheet_Evaluation_Gateway_Result::failure( 'addon_ai_task_failed', true, 'gateway-correlation-0003', 80 );
$scenario      = eval_s04_scenario( $fixture, $canonical, $route, $retry_gateway );
eval_s04_same( Run_Worksheet_Evaluation_Result::RETRY_WAIT, $scenario['worker']->run_once()->outcome(), 'First transient failure must enter retry_wait.' );
eval_s04_same( true, $scenario['repository']->failure['retry'], 'Transient failure must be retryable.' );
eval_s04_same( '2026-08-21 12:01:00', $scenario['repository']->failure['available_at_utc'], 'First retry backoff must be 60 seconds.' );

$scenario = eval_s04_scenario( $fixture, $canonical, $route, $retry_gateway, null, true, array( true, true ), array( true, true ), 1 );
eval_s04_same( Run_Worksheet_Evaluation_Result::RETRY_WAIT, $scenario['worker']->run_once()->outcome(), 'Second transient failure must remain retryable.' );
eval_s04_same( '2026-08-21 12:05:00', $scenario['repository']->failure['available_at_utc'], 'Second retry backoff must be 300 seconds.' );

$terminal_gateway = Worksheet_Evaluation_Gateway_Result::failure( 'addon_ai_task_unroutable', false, 'gateway-correlation-0004', 10 );
$scenario         = eval_s04_scenario( $fixture, $canonical, $route, $terminal_gateway );
eval_s04_same( Run_Worksheet_Evaluation_Result::FAILED, $scenario['worker']->run_once()->outcome(), 'Unroutable execution must fail without retry.' );
eval_s04_same( false, $scenario['repository']->failure['retry'], 'Unroutable execution must not be retried.' );

$scenario = eval_s04_scenario( $fixture, $canonical, $route, $success_gateway, null, true, array( false ) );
eval_s04_same( Run_Worksheet_Evaluation_Result::FAILED, $scenario['worker']->run_once()->outcome(), 'Revoked authorization must fail.' );
eval_s04_same( 0, $scenario['execution']->execute_calls, 'Initial authorization denial must precede execution.' );

$scenario = eval_s04_scenario( $fixture, $canonical, $route, $success_gateway, null, true, array( true, false ) );
eval_s04_same( Run_Worksheet_Evaluation_Result::FAILED, $scenario['worker']->run_once()->outcome(), 'Postflight authorization revocation must discard output.' );
eval_s04_same( 'context_denied', $scenario['repository']->failure['error_code'], 'Postflight authorization reason drifted.' );
eval_s04_same( 1, $scenario['execution']->execute_calls, 'Postflight denial happens only after one execution.' );

$scenario = eval_s04_scenario( $fixture, $canonical, $route, $success_gateway, null, true, array( true, true ), array( false ) );
eval_s04_same( Run_Worksheet_Evaluation_Result::FAILED, $scenario['worker']->run_once()->outcome(), 'Stale source must fail.' );
eval_s04_same( 0, $scenario['execution']->execute_calls, 'Stale source must be rejected before execution.' );

$scenario                            = eval_s04_scenario( $fixture, $canonical, $route, $success_gateway );
$scenario['repository']->stale_start = true;
eval_s04_same( Run_Worksheet_Evaluation_Result::STALE, $scenario['worker']->run_once()->outcome(), 'Lost lease before attempt must be stale.' );
eval_s04_same( 0, $scenario['execution']->execute_calls, 'Lost lease must prevent execution.' );

$scenario                             = eval_s04_scenario( $fixture, $canonical, $route, $success_gateway );
$scenario['repository']->stale_commit = true;
eval_s04_same( Run_Worksheet_Evaluation_Result::STALE, $scenario['worker']->run_once()->outcome(), 'Lost lease at commit must not double-write.' );

$scenario = eval_s04_scenario( $fixture, $canonical, $route, $success_gateway, null, true, array( true ), array( true ), 3 );
eval_s04_same( Run_Worksheet_Evaluation_Result::FAILED, $scenario['worker']->run_once()->outcome(), 'Attempt ceiling must terminate the run.' );
eval_s04_same( 'worker_lost', $scenario['repository']->failure['error_code'], 'Attempt ceiling reason drifted.' );
eval_s04_same( 0, $scenario['execution']->execute_calls, 'Attempt ceiling must prevent execution.' );

$scenario = eval_s04_scenario(
	$fixture,
	$canonical,
	$route,
	$success_gateway,
	null,
	true,
	array( true ),
	array( true ),
	0,
	'2026-08-19 11:00:00'
);
eval_s04_same( Run_Worksheet_Evaluation_Result::FAILED, $scenario['worker']->run_once()->outcome(), 'Expired queue deadline must terminate the run.' );
eval_s04_same( 'queue_deadline_exceeded', $scenario['repository']->failure['error_code'], 'Queue deadline reason drifted.' );

$consent_item    = ( new Eval_S04_Repository( $fixture, $route ) )->claim_due(
	'2026-08-21 12:00:00',
	'123e4567-e89b-42d3-a456-426614174000',
	'2026-08-21 12:05:00'
);
$default_consent = new WordPress_Worksheet_Evaluation_Execution_Consent( $canonical, static fn () => null );
eval_s04_same( false, $default_consent->allows( $consent_item ), 'External processing consent must default to deny.' );
$unsigned_consent                 = array(
	'schema_id'           => WordPress_Worksheet_Evaluation_Execution_Consent::SCHEMA,
	'schema_version'      => WordPress_Worksheet_Evaluation_Execution_Consent::VERSION,
	'state'               => 'approved',
	'task_key'            => Addon_Manifest::TASK_WORKSHEET_QUALITY,
	'destination'         => array(
		'provider_key'        => $route->provider_key(),
		'model_key'           => $route->model_key(),
		'profile_fingerprint' => $route->profile_fingerprint(),
	),
	'input_categories'    => WordPress_Worksheet_Evaluation_Execution_Consent::DATA_CATEGORIES,
	'approved_by_user_id' => 42,
	'approved_at_utc'     => '2026-08-21 11:30:00',
);
$approved_consent                 = $unsigned_consent;
$approved_consent['consent_hash'] = hash( 'sha256', $canonical->encode( $unsigned_consent ) );
$consent                          = new WordPress_Worksheet_Evaluation_Execution_Consent( $canonical, static fn () => $approved_consent );
eval_s04_same( true, $consent->allows( $consent_item ), 'Exact consent must authorize the pinned destination.' );
$changed_consent                             = $approved_consent;
$changed_consent['destination']['model_key'] = 'different-model';
$changed_unsigned                            = $changed_consent;
unset( $changed_unsigned['consent_hash'] );
$changed_consent['consent_hash'] = hash( 'sha256', $canonical->encode( $changed_unsigned ) );
$consent                         = new WordPress_Worksheet_Evaluation_Execution_Consent( $canonical, static fn () => $changed_consent );
eval_s04_same( false, $consent->allows( $consent_item ), 'Consent must not follow a changed destination.' );

$module_scenario = eval_s04_scenario( $fixture, $canonical, $route, $success_gateway );
$module          = new Worksheet_Evaluation_Worker_Module( $module_scenario['worker'] );
$module->register();
eval_s04_assert( isset( $GLOBALS['eval_s04_filters']['cron_schedules'] ), 'Worker schedule filter must register.' );
eval_s04_assert( isset( $GLOBALS['eval_s04_actions'][ Worksheet_Evaluation_Worker_Module::SWEEP_HOOK ] ), 'Worker sweep action must register.' );
$schedules = $module->cron_schedules( array() );
eval_s04_same( 60, $schedules[ Worksheet_Evaluation_Worker_Module::SCHEDULE ]['interval'], 'Worker sweep interval drifted.' );
$module->ensure_scheduled();
eval_s04_same( Worksheet_Evaluation_Worker_Module::SWEEP_HOOK, $GLOBALS['eval_s04_scheduled'][0][2], 'Worker sweep hook was not scheduled.' );
Worksheet_Evaluation_Worker_Module::clear();
eval_s04_assert( in_array( Worksheet_Evaluation_Worker_Module::SWEEP_HOOK, $GLOBALS['eval_s04_cleared'], true ), 'Deactivation must clear the worker sweep.' );

fwrite( STDOUT, "G2-IMP-05-S04 worksheet evaluation worker contract passed.\n" );
