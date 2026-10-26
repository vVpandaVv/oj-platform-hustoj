<?php
/**
 * G5-IMP-07-S03-D3-D2 executable owner mapping contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Approve_Legacy_Migration;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Approve_Legacy_Migration_Command;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Approve_Legacy_Migration_Result;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Execute_Legacy_Migration;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Execute_Legacy_Migration_Command;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Execute_Legacy_Migration_Result;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Migration_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Approved_Legacy_Migration_Run;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Mapping_Manifest;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Migration_Plan;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Source_Snapshot;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\Legacy_Source_Profile_0_1_2;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\Platform_Legacy_Mapping_Decision_Validator;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Approval_Repository_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Execution_Repository_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Queue_Wakeup_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Site_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Preview_Token_Verifier_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Source_Inspector_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Presentation\Legacy_Migration_Approval_Rest_Controller;
use OJAI\Dashboard\Submission\Assignment\Domain\Assignment_Roster_Snapshot;
use OJAI\Dashboard\Submission\Assignment\Port\Assignment_Roster_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

$root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
require_once $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Autoloader.php';
( new Autoloader( $root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * D3d2 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function d3d2_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * D3d2 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function d3d2_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * D3d2 failure.
 *
 * @param callable():void $callback Callback.
 * @param string          $reason Reason.
 * @param string          $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function d3d2_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback();
	} catch ( Legacy_Migration_Failure $failure ) {
		d3d2_same( $reason, $failure->reason(), $message );
		return;
	}
	throw new RuntimeException( $message . ': no failure was raised.' );
}

/**
 * D3d2 invalid.
 *
 * @param callable():void $callback Callback.
 * @param string          $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function d3d2_invalid( callable $callback, string $message ): void {
	try {
		$callback();
	} catch ( InvalidArgumentException ) {
		return;
	}
	throw new RuntimeException( $message . ': no validation failure was raised.' );
}

/**
 * D3d2 id.
 *
 * @return string
 */
function d3d2_id(): string {
	static $ids = null;
	$ids      ??= new Public_Id_Generator();
	return $ids->create()->value();
}

/**
 * G5-IMP-07-S03-D3-D2 executable owner mapping contract.
 */
final class D3D2_Source implements Legacy_Source_Inspector_Port_Interface {
	/**
	 * Initialize d3 d2 source dependencies and state.
	 *
	 * @param Legacy_Source_Snapshot $snapshot Snapshot.
	 */
	public function __construct( public Legacy_Source_Snapshot $snapshot ) {
	}
	/**
	 * Inspect.
	 *
	 * @param string $source_profile Source profile.
	 * @return Legacy_Source_Snapshot
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function inspect( string $source_profile ): Legacy_Source_Snapshot {
		if ( $source_profile !== $this->snapshot->profile() ) {
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_PROFILE_UNSUPPORTED );
		}
		return $this->snapshot;
	}
}

/**
 * G5-IMP-07-S03-D3-D2 executable owner mapping contract.
 */
final class D3D2_Site implements Legacy_Migration_Site_Port_Interface {
	/**
	 * Site id.
	 *
	 * @return string
	 */
	public function site_id(): string {
		return '7';
	}
}

/**
 * G5-IMP-07-S03-D3-D2 executable owner mapping contract.
 */
final class D3D2_Token implements Legacy_Preview_Token_Verifier_Port_Interface {
	/**
	 * Initialize d3 d2 token dependencies and state.
	 *
	 * @param string              $token Token.
	 * @param array<string,mixed> $payload Input payload at this boundary; see the declared shape.
	 */
	public function __construct( private string $token, private array $payload ) {
	}
	/**
	 * Verify d3 d2 token.
	 *
	 * @param string $token Token.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function verify( string $token ): array {
		if ( ! hash_equals( $this->token, $token ) ) {
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::TOKEN_INVALID );
		}
		return $this->payload;
	}
}

/**
 * G5-IMP-07-S03-D3-D2 executable owner mapping contract.
 */
final class D3D2_Roster implements Assignment_Roster_Port_Interface {
	/**
	 * Context revision.
	 *
	 * @var string
	 */
	public string $context_revision = 'context-r3';
	/**
	 * Roster revision.
	 *
	 * @var string
	 */
	public string $roster_revision = 'roster-r9';
	/**
	 * Membership revision.
	 *
	 * @var string
	 */
	public string $membership_revision = 'membership-r5';
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;

	/**
	 * Collect.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $expected_context_revision Expected context revision.
	 * @return Assignment_Roster_Snapshot
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function collect( int $actor_user_id, string $context_type, string $context_id, string $expected_context_revision ): Assignment_Roster_Snapshot {
		++$this->calls;
		if ( 77 !== $actor_user_id || 'class' !== $context_type || 'class-7' !== $context_id || $expected_context_revision !== $this->context_revision ) {
			throw new RuntimeException( 'Current platform Context no longer matches the approved target.' );
		}
		return new Assignment_Roster_Snapshot(
			$context_type,
			$context_id,
			$this->context_revision,
			$this->roster_revision,
			array(
				array(
					'learner_user_id'     => 707,
					'membership_revision' => $this->membership_revision,
				),
			)
		);
	}
}

/**
 * G5-IMP-07-S03-D3-D2 executable owner mapping contract.
 */
final class D3D2_Approval_Repository implements Legacy_Migration_Approval_Repository_Port_Interface {
	/**
	 * Records.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $records = array();

	/**
	 * Approve.
	 *
	 * @param array $record Record.
	 * @return Approve_Legacy_Migration_Result
	 */
	public function approve( array $record ): Approve_Legacy_Migration_Result {
		$this->records[] = $record;
		return new Approve_Legacy_Migration_Result(
			(string) $record['run_public_id'],
			'approved',
			(string) $record['source_profile'],
			(string) $record['source_version'],
			(string) $record['source_fingerprint'],
			(string) $record['plan_hash'],
			(string) $record['preview_hash'],
			(int) $record['item_total'],
			(string) $record['approved_at_utc'],
			false
		);
	}
}

/**
 * G5-IMP-07-S03-D3-D2 executable owner mapping contract.
 */
final class D3D2_Execution_Repository implements Legacy_Migration_Execution_Repository_Port_Interface {
	/**
	 * Starts.
	 *
	 * @var int
	 */
	public int $starts = 0;
	/**
	 * Last record.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $last_record = null;

	/**
	 * Initialize d3 d2 execution repository dependencies and state.
	 *
	 * @param Approved_Legacy_Migration_Run $run Run.
	 */
	public function __construct( private Approved_Legacy_Migration_Run $run ) {
	}
	/**
	 * Started receipt.
	 *
	 * @param string $storage_key Storage key.
	 * @param string $request_digest Request digest.
	 * @return Execute_Legacy_Migration_Result|null
	 */
	public function started_receipt( string $storage_key, string $request_digest ): ?Execute_Legacy_Migration_Result {
		return null;
	}
	/**
	 * Approved run.
	 *
	 * @param string $run_id Run id.
	 * @return Approved_Legacy_Migration_Run
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function approved_run( string $run_id ): Approved_Legacy_Migration_Run {
		if ( $run_id !== $this->run->public_id() ) {
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::RUN_NOT_FOUND );
		}
		return $this->run;
	}
	/**
	 * Start.
	 *
	 * @param array $record Record.
	 * @return Execute_Legacy_Migration_Result
	 */
	public function start( array $record ): Execute_Legacy_Migration_Result {
		++$this->starts;
		$this->last_record = $record;
		return new Execute_Legacy_Migration_Result(
			(string) $record['run_public_id'],
			'running',
			(string) $record['source_fingerprint'],
			(string) $record['plan_hash'],
			(string) $record['checkpoint_hash'],
			$record['checkpoint'],
			(int) $record['item_total'],
			(string) $record['started_at_utc'],
			false
		);
	}
}

/**
 * G5-IMP-07-S03-D3-D2 executable owner mapping contract.
 */
final class D3D2_Queue implements Legacy_Migration_Queue_Wakeup_Port_Interface {
	/**
	 * Runs.
	 *
	 * @var array<int,string>
	 */
	public array $runs = array();
	/**
	 * Wake.
	 *
	 * @param string $run_id Run id.
	 * @return void
	 */
	public function wake( string $run_id ): void {
		$this->runs[] = $run_id;
	}
}

/**
 * G5-IMP-07-S03-D3-D2 executable owner mapping contract.
 */
final class D3D2_Request {
	/**
	 * Initialize d3 d2 request dependencies and state.
	 *
	 * @param array<string,mixed> $body Body.
	 */
	public function __construct( private array $body ) {
	}
	/**
	 * Get json params.
	 *
	 * @return array<string,mixed>
	 */
	public function get_json_params(): array {
		return $this->body;
	}
}

$canonical        = new Canonical_Json();
$manifest_builder = new Legacy_Mapping_Manifest( $canonical, Legacy_Source_Profile_0_1_2::PROFILE );
$manifest_builder->observe(
	'worksheet_assignments',
	array(
		'id'            => '41',
		'group_id'      => '0',
		'academic_year' => '2026',
		'grade'         => '5',
		'class_name'    => 'class-a',
	)
);
$manifest_builder->observe(
	'worksheet_submissions',
	array(
		'assignment_id'   => '41',
		'student_user_id' => '77',
	)
);
$manifest = $manifest_builder->manifest();
d3d2_same(
	array(
		'context' => 1,
		'user'    => 1,
	),
	$manifest_builder->requirements(),
	'Actual assignment dependencies did not create one Context and learner requirement.'
);
d3d2_same( array( 'kind', 'source_ref', 'source_type' ), array_keys( $manifest[0] ), 'Context manifest leaked source identity fields.' );
d3d2_same( array( 'assignment_source_ref', 'context_source_ref', 'kind', 'source_ref' ), array_keys( $manifest[1] ), 'Learner manifest leaked source identity fields or lost its assignment relation.' );
d3d2_same( $manifest_builder->assignment_ref( 41 ), $manifest[1]['assignment_source_ref'], 'Learner requirement was not bound to its opaque assignment identity.' );
d3d2_assert( $manifest[1]['assignment_source_ref'] !== $manifest[1]['context_source_ref'], 'Assignment and Context mapping identities collapsed.' );
$manifest_json = $canonical->encode( $manifest );
foreach ( array( 'academic_year', 'class_name', 'student_user_id', 'source_user_id' ) as $forbidden ) {
	d3d2_assert( ! str_contains( $manifest_json, $forbidden ), 'PII or source identity leaked into the mapping manifest: ' . $forbidden );
}

$snapshot     = new Legacy_Source_Snapshot(
	Legacy_Source_Profile_0_1_2::PROFILE,
	Legacy_Source_Profile_0_1_2::VERSION,
	hash( 'sha256', 'd3-d2-source' ),
	array(
		array(
			'logical_table' => 'worksheet_assignments',
			'source_table'  => 'wp_oj_ai_worksheet_assignments',
			'disposition'   => 'migrate',
			'rows'          => 1,
		),
		array(
			'logical_table' => 'worksheet_submissions',
			'source_table'  => 'wp_oj_ai_worksheet_submissions',
			'disposition'   => 'migrate',
			'rows'          => 1,
		),
	),
	0,
	0,
	$manifest_builder->requirements(),
	array(),
	array(),
	$manifest
);
$plans        = new Legacy_Migration_Plan( $canonical );
$preview_plan = $plans->build( '7', $snapshot );
d3d2_same( Legacy_Migration_Plan::PROFILE, $preview_plan['profile'], 'C61 mapping plan profile did not advance to 1.2.0.' );
d3d2_same( 2, $preview_plan['counts']['mapping_needed'], 'C61 did not count actual mapping dependencies.' );

$context_ref      = $manifest[0]['source_ref'];
$user_ref         = $manifest[1]['source_ref'];
$context_decision = array(
	'target'     => array(
		'roster_revision'  => 'roster-r9',
		'context_type'     => 'class',
		'context_revision' => 'context-r3',
		'context_id'       => 'class-7',
	),
	'source_ref' => $context_ref,
	'kind'       => 'context',
);
$user_decision    = array(
	'target'     => array(
		'membership_revision' => 'membership-r5',
		'learner_user_id'     => 707,
	),
	'source_ref' => $user_ref,
	'kind'       => 'user',
);
$decisions        = array( $user_decision, $context_decision );
$approved_plan    = $plans->build( '7', $snapshot, $decisions );
$plans->assert_executable( $approved_plan );
d3d2_same( array( 'context', 'user' ), array_column( $approved_plan['mapping_decisions'], 'kind' ), 'Mapping decisions were not stored in canonical order.' );
d3d2_same( 'class-7', $approved_plan['mapping_decisions'][0]['target']['context_id'], 'Canonical Context target drifted.' );
d3d2_invalid( static fn () => $plans->build( '7', $snapshot, array( $context_decision, $context_decision, $user_decision ) ), 'Duplicate source_ref was accepted.' );
d3d2_invalid( static fn () => $plans->assert_executable( $plans->build( '7', $snapshot, array( $context_decision ) ) ), 'Incomplete mapping decisions became executable.' );
$tampered_plan                             = $approved_plan;
$tampered_plan['counts']['mapping_needed'] = 1;
$without_hash                              = $tampered_plan;
unset( $without_hash['plan_hash'] );
$tampered_plan['plan_hash'] = hash( 'sha256', $canonical->encode( $without_hash ) );
d3d2_invalid( static fn () => $plans->assert_valid( $tampered_plan ), 'A rehashed mapping count drift was accepted.' );

$token               = 'd3d2.' . str_repeat( 'a', 64 );
$key_hash            = hash( 'sha256', 'd3-d2-approval-key' );
$tokens              = new D3D2_Token(
	$token,
	array(
		'purpose'              => 'legacy_migration.preview',
		'site'                 => '7',
		'source_fingerprint'   => $snapshot->source_fingerprint(),
		'plan_hash'            => $preview_plan['plan_hash'],
		'idempotency_key_hash' => $key_hash,
		'issued_at_utc'        => '2026-08-25 12:00:00',
		'expires_at_utc'       => '2026-08-25 12:30:00',
		'nonce'                => str_repeat( 'b', 32 ),
	)
);
$source              = new D3D2_Source( $snapshot );
$site                = new D3D2_Site();
$roster              = new D3D2_Roster();
$mapping_validator   = new Platform_Legacy_Mapping_Decision_Validator( $roster );
$approval_repository = new D3D2_Approval_Repository();
$approvals           = new Approve_Legacy_Migration(
	$source,
	$tokens,
	$site,
	$approval_repository,
	new Public_Id_Generator(),
	$canonical,
	static fn (): string => '2026-08-25 12:05:00',
	$mapping_validator
);
$approval_command    = new Approve_Legacy_Migration_Command(
	77,
	Legacy_Source_Profile_0_1_2::PROFILE,
	$token,
	$key_hash,
	'corr-g5-d3-d2-approve',
	$canonical,
	$decisions
);
$changed_command     = new Approve_Legacy_Migration_Command(
	77,
	Legacy_Source_Profile_0_1_2::PROFILE,
	$token,
	$key_hash,
	'corr-g5-d3-d2-changed',
	$canonical,
	array( $context_decision, array_replace_recursive( $user_decision, array( 'target' => array( 'membership_revision' => 'membership-other' ) ) ) )
);
d3d2_assert( $approval_command->request_digest() !== $changed_command->request_digest(), 'C70 request digest did not bind mapping decisions.' );
d3d2_failure(
	static fn () => $approvals->execute( new Approve_Legacy_Migration_Command( 77, Legacy_Source_Profile_0_1_2::PROFILE, $token, $key_hash, 'corr-g5-d3-d2-missing', $canonical, array( $context_decision ) ) ),
	Legacy_Migration_Failure::MAPPING_REQUIRED,
	'C70 accepted an incomplete mapping set.'
);
d3d2_failure(
	static fn () => $approvals->execute( new Approve_Legacy_Migration_Command( 77, Legacy_Source_Profile_0_1_2::PROFILE, $token, $key_hash, 'corr-g5-d3-d2-duplicate', $canonical, array( $context_decision, $context_decision, $user_decision ) ) ),
	Legacy_Migration_Failure::MAPPING_REQUIRED,
	'C70 misclassified duplicate mapping decisions.'
);
$approval_result = $approvals->execute( $approval_command );
d3d2_same( 'approved', $approval_result->state(), 'Complete C70 mapping decisions were not approved.' );
d3d2_same( 1, count( $approval_repository->records ), 'C70 wrote a run before mapping validation or wrote more than once.' );
d3d2_same( 1, $roster->calls, 'C70 did not validate one exact platform roster.' );
$approval_record = $approval_repository->records[0];
$stored_preview  = json_decode( (string) $approval_record['preview_json'], true, 64, JSON_THROW_ON_ERROR );
$stored_plan     = $stored_preview['plan'];
d3d2_same( Legacy_Migration_Plan::PROFILE, $stored_plan['profile'], 'C70 did not persist the 1.2.0 assignment-bound mapping plan.' );
d3d2_same( $approved_plan['mapping_decisions'], $stored_plan['mapping_decisions'], 'C70 did not freeze canonical mapping decisions.' );
d3d2_same( 0, $approval_result->platform_write_count(), 'C70 reported a platform write while validating mappings.' );

$approved_run         = new Approved_Legacy_Migration_Run(
	1,
	$approval_result->run_id(),
	0,
	Legacy_Source_Profile_0_1_2::PROFILE,
	Legacy_Source_Profile_0_1_2::VERSION,
	$snapshot->source_fingerprint(),
	$approval_result->preview_hash(),
	$approval_result->plan_hash(),
	$stored_plan,
	2
);
$execution_repository = new D3D2_Execution_Repository( $approved_run );
$queue                = new D3D2_Queue();
$execution            = new Execute_Legacy_Migration(
	$source,
	$site,
	$execution_repository,
	$queue,
	new Public_Id_Generator(),
	$canonical,
	static fn (): string => '2026-08-25 12:10:00',
	$mapping_validator
);
$execution_command    = new Execute_Legacy_Migration_Command(
	77,
	$approval_result->run_id(),
	d3d2_id(),
	'approved',
	$snapshot->source_fingerprint(),
	$approval_result->plan_hash(),
	hash( 'sha256', 'd3-d2-execute-key' ),
	'corr-g5-d3-d2-execute',
	$canonical
);

$roster->context_revision = 'context-r4';
d3d2_failure( static fn () => $execution->execute( $execution_command ), Legacy_Migration_Failure::MAPPING_REQUIRED, 'C62 accepted a changed Context revision.' );
$roster->context_revision = 'context-r3';
$roster->roster_revision  = 'roster-r10';
d3d2_failure( static fn () => $execution->execute( $execution_command ), Legacy_Migration_Failure::MAPPING_REQUIRED, 'C62 accepted a changed roster revision.' );
$roster->roster_revision     = 'roster-r9';
$roster->membership_revision = 'membership-r6';
d3d2_failure( static fn () => $execution->execute( $execution_command ), Legacy_Migration_Failure::MAPPING_REQUIRED, 'C62 accepted a changed learner membership revision.' );
d3d2_same( 0, $execution_repository->starts, 'A stale C62 mapping changed the run state.' );
$roster->membership_revision = 'membership-r5';
$execution_result            = $execution->execute( $execution_command );
d3d2_same( 'running', $execution_result->state(), 'C62 did not start after exact mapping revalidation.' );
d3d2_same( 1, $execution_repository->starts, 'C62 did not perform exactly one durable start.' );
d3d2_same( array( $approval_result->run_id() ), $queue->runs, 'C62 did not wake the worker after the durable start.' );
d3d2_same( 0, $execution_result->platform_write_count(), 'C62 reported a platform write while revalidating mappings.' );

$rest        = ( new ReflectionClass( Legacy_Migration_Approval_Rest_Controller::class ) )->newInstanceWithoutConstructor();
$input       = new ReflectionMethod( Legacy_Migration_Approval_Rest_Controller::class, 'input' );
$legacy_body = $input->invoke(
	$rest,
	new D3D2_Request(
		array(
			'source'        => array( 'profile' => Legacy_Source_Profile_0_1_2::PROFILE ),
			'preview_token' => $token,
		)
	)
);
d3d2_same( array(), $legacy_body['mapping_decisions'], 'C70 broke the existing two-field no-mapping body.' );
$mapped_body = $input->invoke(
	$rest,
	new D3D2_Request(
		array(
			'source'            => array( 'profile' => Legacy_Source_Profile_0_1_2::PROFILE ),
			'preview_token'     => $token,
			'mapping_decisions' => $decisions,
		)
	)
);
d3d2_same( $decisions, $mapped_body['mapping_decisions'], 'C70 did not accept the conditional mapping_decisions field.' );
d3d2_invalid(
	static fn () => $input->invoke(
		$rest,
		new D3D2_Request(
			array(
				'source'            => array( 'profile' => Legacy_Source_Profile_0_1_2::PROFILE ),
				'preview_token'     => $token,
				'mapping_decisions' => $decisions,
				'extra'             => true,
			)
		)
	),
	'C70 accepted an undeclared body field.'
);

fwrite( STDOUT, "G5-IMP-07-S03-D3-D2 passed: PII-free dependency manifests, canonical C70 decisions, platform roster validation, and C62 revision revalidation are frozen.\n" );
