<?php
/**
 * G4-IMP-02-R01 external AI consent ledger and application contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use OJAI\Dashboard\Submission\Assessment\Application\Approve_Submission_External_Ai_Consent_Command;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_External_Ai_Consent_Failure;
use OJAI\Dashboard\Submission\Assessment\Application\Manage_Submission_External_Ai_Consent;
use OJAI\Dashboard\Submission\Assessment\Application\Revoke_Submission_External_Ai_Consent_Command;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Routing_Snapshot;
use OJAI\Dashboard\Submission\Assessment\Infrastructure\WordPress_Submission_External_Ai_Consent_Repository;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Routing_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Presentation\Submission_External_Ai_Consent_Rest_Controller;

$root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
require_once $root . '/src/Core/Autoloader.php';
( new Autoloader( $root . '/src' ) )->register();

$GLOBALS['g4r01_options'] = array();
$GLOBALS['g4r01_routes']  = array();

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Get option.
	 *
	 * @param string $name Name.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	function get_option( string $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['g4r01_options'] ) ? $GLOBALS['g4r01_options'][ $name ] : $default;
	}
}
if ( ! function_exists( 'add_option' ) ) {
	/**
	 * Add option.
	 *
	 * @param string $name Name.
	 * @param mixed  $value Value.
	 * @param string $deprecated Deprecated.
	 * @param bool   $autoload Autoload.
	 * @return bool
	 */
	function add_option( string $name, $value, string $deprecated = '', bool $autoload = true ): bool {
		if ( array_key_exists( $name, $GLOBALS['g4r01_options'] ) ) {
			return false;
		}
		$GLOBALS['g4r01_options'][ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Update option.
	 *
	 * @param string $name Name.
	 * @param mixed  $value Value.
	 * @param mixed  $autoload Autoload.
	 * @return bool
	 */
	function update_option( string $name, $value, $autoload = null ): bool {
		$GLOBALS['g4r01_options'][ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	/**
	 * Delete option.
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	function delete_option( string $name ): bool {
		$exists = array_key_exists( $name, $GLOBALS['g4r01_options'] );
		unset( $GLOBALS['g4r01_options'][ $name ] );
		return $exists;
	}
}
if ( ! function_exists( 'register_rest_route' ) ) {
	/**
	 * Register rest route.
	 *
	 * @param string $namespace Namespace.
	 * @param string $route Route.
	 * @param array  $arguments Arguments.
	 * @return bool
	 */
	function register_rest_route( string $namespace, string $route, array $arguments ): bool {
		$GLOBALS['g4r01_routes'][] = compact( 'namespace', 'route', 'arguments' );
		return true;
	}
}

/**
 * G4r01 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4r01_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G4r01 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4r01_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . '\nExpected: ' . var_export( $expected, true ) . '\nActual: ' . var_export( $actual, true ) );
	}
}

/**
 * G4r01 failure.
 *
 * @param callable $operation Operation.
 * @param string   $reason Reason.
 * @param string   $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4r01_failure( callable $operation, string $reason, string $message ): void {
	try {
		$operation();
	} catch ( Submission_External_Ai_Consent_Failure $failure ) {
		g4r01_same( $reason, $failure->reason(), $message );
		return;
	}
	throw new RuntimeException( $message . ': expected failure was not thrown.' );
}

/**
 * G4-IMP-02-R01 external AI consent ledger and application contract.
 */
final class G4R01Database {
	/**
	 * Tables.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public array $tables = array(
		'oj_ai_outbox_events' => array(),
		'oj_ai_audit_events'  => array(),
	);
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
	 * Queries.
	 *
	 * @var array<int,string>
	 */
	public array $queries = array();

	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$values Values.
	 * @return string
	 */
	public function prepare( string $sql, ...$values ): string {
		foreach ( $values as $value ) {
			$replacement = is_int( $value ) ? (string) $value : "'" . str_replace( "'", "''", (string) $value ) . "'";
			$sql         = preg_replace( '/%[sd]/', $replacement, $sql, 1 ) ?? '';
		}
		return $sql;
	}

	/**
	 * Get row.
	 *
	 * @param string $sql Sql.
	 * @param string $output Output.
	 * @return array<string,mixed>|null
	 */
	public function get_row( string $sql, string $output ): ?array {
		if ( ! str_contains( $sql, 'FROM oj_ai_outbox_events' ) ) {
			return null;
		}
		$rows = $this->tables['oj_ai_outbox_events'];
		if ( preg_match( "/idempotency_key = '([^']+)'/", $sql, $match ) ) {
			foreach ( $rows as $row ) {
				if ( $row['idempotency_key'] === $match[1] ) {
					return $row;
				}
			}
			return null;
		}
		$rows = array_values(
			array_filter(
				$rows,
				static fn ( array $row ): bool => 'submission_external_ai_consent' === $row['aggregate_type']
					&& '00000000000000000000000002' === $row['aggregate_public_id']
			)
		);
		return array() === $rows ? null : $rows[ array_key_last( $rows ) ];
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
	 * @return bool
	 */
	public function insert( string $table, array $data ): bool {
		if ( ! array_key_exists( $table, $this->tables ) ) {
			$this->last_error = 'unknown table';
			return false;
		}
		$data['id']               = count( $this->tables[ $table ] ) + 1;
		$this->tables[ $table ][] = $data;
		$this->insert_id          = $data['id'];
		return true;
	}

	/**
	 * Query.
	 *
	 * @param string $sql Sql.
	 * @return int
	 */
	public function query( string $sql ): int {
		$this->queries[] = $sql;
		return 1;
	}
}

/**
 * G4-IMP-02-R01 external AI consent ledger and application contract.
 */
final class G4R01Routing implements Submission_Evaluation_Routing_Port_Interface {
	/**
	 * Provider.
	 *
	 * @var string
	 */
	public string $provider = 'provider-a';
	/**
	 * Model.
	 *
	 * @var string
	 */
	public string $model = 'model-a';
	/**
	 * Profile.
	 *
	 * @var string
	 */
	public string $profile;
	/**
	 * Revision.
	 *
	 * @var string
	 */
	public string $revision = 'routing-1';
	/**
	 * Available.
	 *
	 * @var bool
	 */
	public bool $available = true;

	/**
	 * Initialize g4 r01 routing dependencies and state.
	 */
	public function __construct() {
		$this->profile = str_repeat( 'a', 64 );
	}

	/**
	 * Plan.
	 *
	 * @return Submission_Evaluation_Routing_Snapshot
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function plan(): Submission_Evaluation_Routing_Snapshot {
		if ( ! $this->available ) {
			throw new RuntimeException( 'routing unavailable' );
		}
		return new Submission_Evaluation_Routing_Snapshot(
			'addon.oj_ai_dashboard.student_submission_assessment',
			$this->provider,
			$this->model,
			$this->profile,
			$this->revision
		);
	}
}

$canonical  = new Canonical_Json();
$database   = new G4R01Database();
$counter    = 0;
$ids        = new Public_Id_Generator(
	static function ( int $length ) use ( &$counter ): string {
		++$counter;
		return str_repeat( "\0", 15 ) . chr( $counter );
	}
);
$routing    = new G4R01Routing();
$repository = new WordPress_Submission_External_Ai_Consent_Repository( $canonical, $ids, $database );
$manager    = new Manage_Submission_External_Ai_Consent(
	$repository,
	$routing,
	$canonical,
	static fn (): string => '2026-08-21 10:00:00'
);

$initial = $manager->read();
g4r01_same( 'missing', $initial['consent']['state'], 'A fresh install must begin without implicit consent.' );
g4r01_same( true, $initial['routing']['available'], 'The safe routing snapshot must be visible before approval.' );
g4r01_same(
	array( 'student_artifact_text', 'student_response_content', 'submission_assessment_configuration', 'worksheet_grading_content' ),
	$initial['input_categories'],
	'Q38 must disclose the exact student submission data categories.'
);
g4r01_same(
	'addon.oj_ai_dashboard.student_submission_assessment',
	$initial['consent']['task_key'],
	'Q38 must be bound to the student submission assessment task.'
);

$approve  = new Approve_Submission_External_Ai_Consent_Command(
	7,
	'command-approve-01',
	'missing',
	null,
	'routing-1',
	str_repeat( 'a', 64 ),
	true,
	str_repeat( '1', 64 ),
	'corr-r01-approve',
	$canonical
);
$approved = $manager->approve( $approve );
g4r01_same( 'approved', $approved->status()['consent']['state'], 'C78 must activate exact-route consent.' );
g4r01_same( true, $approved->status()['consent']['effective'], 'C78 consent must be effective for the approved route.' );
g4r01_same( 1, count( $database->tables['oj_ai_outbox_events'] ), 'C78 must append one literal-table consent event.' );
g4r01_same( 1, count( $database->tables['oj_ai_audit_events'] ), 'C78 must append one audit event.' );
g4r01_same( 'submission_external_ai_consent.approved', $database->tables['oj_ai_outbox_events'][0]['event_type'], 'C78 event type drifted.' );
g4r01_same( 'submission_external_ai.consent.approved', $database->tables['oj_ai_audit_events'][0]['action_key'], 'C78 audit action drifted.' );

$replay = $manager->approve( $approve );
g4r01_same( true, $replay->replayed(), 'The same C78 idempotency key must replay.' );
g4r01_same( 1, count( $database->tables['oj_ai_outbox_events'] ), 'C78 replay must not append another event.' );
g4r01_same( 1, count( $database->tables['oj_ai_audit_events'] ), 'C78 replay must not append another audit.' );

$approve_conflict = new Approve_Submission_External_Ai_Consent_Command(
	7,
	'command-approve-02',
	'missing',
	null,
	'routing-1',
	str_repeat( 'a', 64 ),
	true,
	str_repeat( '1', 64 ),
	'corr-r01-conflict',
	$canonical
);
g4r01_failure(
	static fn () => $manager->approve( $approve_conflict ),
	Submission_External_Ai_Consent_Failure::IDEMPOTENCY_CONFLICT,
	'C78 must reject a different payload that reuses the same idempotency key.'
);

$current = $manager->read();
$revoke  = new Revoke_Submission_External_Ai_Consent_Command(
	7,
	'command-revoke-001',
	'approved',
	$current['consent']['consent_hash'],
	str_repeat( '2', 64 ),
	'corr-r01-revoke',
	$canonical
);
$revoked = $manager->revoke( $revoke );
g4r01_same( 'missing', $revoked->status()['consent']['state'], 'C79 must remove effective consent.' );
g4r01_same( 2, count( $database->tables['oj_ai_outbox_events'] ), 'C79 must append one revocation event.' );
g4r01_same( 2, count( $database->tables['oj_ai_audit_events'] ), 'C79 must append one audit event.' );
g4r01_same(
	$database->tables['oj_ai_audit_events'][0]['event_hash'],
	$database->tables['oj_ai_audit_events'][1]['previous_event_hash'],
	'C79 must preserve the append-only audit hash chain.'
);
g4r01_same( null, $repository->current_record(), 'The authoritative ledger must return no consent after C79.' );

$approve_after_revoke = new Approve_Submission_External_Ai_Consent_Command(
	7,
	'command-approve-03',
	'missing',
	null,
	'routing-1',
	str_repeat( 'a', 64 ),
	true,
	str_repeat( '3', 64 ),
	'corr-r01-approve-2',
	$canonical
);
$manager->approve( $approve_after_revoke );
$routing->provider = 'provider-b';
$routing->model    = 'model-b';
$routing->profile  = str_repeat( 'b', 64 );
$routing->revision = 'routing-2';
$stale             = $manager->read();
g4r01_same( 'stale', $stale['consent']['state'], 'A changed routing destination must make prior consent stale.' );
g4r01_same( false, $stale['consent']['effective'], 'Stale consent must never authorize execution.' );

$reapprove = new Approve_Submission_External_Ai_Consent_Command(
	7,
	'command-approve-04',
	'stale',
	$stale['consent']['consent_hash'],
	'routing-2',
	str_repeat( 'b', 64 ),
	true,
	str_repeat( '4', 64 ),
	'corr-r01-reapprove',
	$canonical
);
$renewed   = $manager->approve( $reapprove );
g4r01_same( 'approved', $renewed->status()['consent']['state'], 'C78 must renew consent after exact routing drift acknowledgement.' );
g4r01_same( 'provider-b', $renewed->status()['consent']['destination']['provider_key'], 'Renewed consent must bind to the new provider.' );

$last = array_key_last( $database->tables['oj_ai_outbox_events'] );
$database->tables['oj_ai_outbox_events'][ $last ]['payload_hash'] = str_repeat( '0', 64 );
g4r01_failure(
	static fn () => $manager->read(),
	Submission_External_Ai_Consent_Failure::STORED_DATA_INVALID,
	'Q38 must fail closed on a malformed authoritative receipt.'
);

$reflection = new ReflectionClass( Submission_External_Ai_Consent_Rest_Controller::class );
/**
 * Controller.
 *
 * @var Submission_External_Ai_Consent_Rest_Controller $controller
 */
$controller = $reflection->newInstanceWithoutConstructor();
$controller->register_routes();
$controller->register_routes();
g4r01_same( 3, count( $GLOBALS['g4r01_routes'] ), 'R01 must register Q38, C78, and C79 exactly once.' );
g4r01_same(
	array(
		'/settings/submission-external-ai-consent',
		'/settings/submission-external-ai-consent/commands/approve',
		'/settings/submission-external-ai-consent/commands/revoke',
	),
	array_column( $GLOBALS['g4r01_routes'], 'route' ),
	'R01 REST route catalog drifted.'
);

$repository_source  = file_get_contents( $root . '/src/Submission/Assessment/Infrastructure/WordPress_Submission_External_Ai_Consent_Repository.php' );
$composition_source = file_get_contents( $root . '/src/Core/Composition_Root.php' );
g4r01_assert( is_string( $repository_source ) && ! str_contains( $repository_source, '$wpdb->prefix' ), 'R01 must not use a WordPress table prefix.' );
g4r01_assert( ! str_contains( (string) $repository_source, 'wp_oj_ai_' ), 'R01 must not use legacy prefixed add-on tables.' );
g4r01_assert( str_contains( (string) $repository_source, "'oj_ai_outbox_events'" ), 'R01 must use the literal outbox table.' );
g4r01_assert( str_contains( (string) $repository_source, "'oj_ai_audit_events'" ), 'R01 must use the literal audit table.' );
g4r01_assert( ! str_contains( (string) $composition_source, 'CodingPanda\\OjPlatform\\Kernel' ), 'R01 Runtime composition must not use platform internals.' );
g4r01_assert( ! str_contains( (string) $repository_source, 'legacy_record' ), 'Submission consent must not inherit a hidden option fallback.' );
g4r01_assert( ! str_contains( (string) $repository_source, 'synchronize_legacy_cache' ), 'Submission consent must have one authoritative ledger.' );
g4r01_assert( str_contains( (string) $repository_source, "'submission_external_ai_consent'" ), 'Submission consent must use a separate aggregate.' );
g4r01_assert( str_contains( (string) $composition_source, 'new WordPress_Submission_External_Ai_Consent_Repository(' ), 'Composition must construct the submission consent ledger.' );
g4r01_assert( str_contains( (string) $composition_source, 'new Submission_External_Ai_Consent_Rest_Module(' ), 'Composition must register Q38/C78/C79.' );
g4r01_assert( str_contains( (string) $composition_source, 'new Submission_Evaluation_Worker_Module(' ), 'R01 must activate the submission evaluation worker.' );
g4r01_assert(
	str_contains( (string) $composition_source, 'static fn () => $submission_external_ai_consent_repository->current_record()' ),
	'The submission worker must read only the authoritative submission consent ledger.'
);
$deactivation_source = file_get_contents( $root . '/src/Core/Deactivation.php' );
g4r01_assert( str_contains( (string) $deactivation_source, 'Submission_Evaluation_Worker_Module::clear();' ), 'Deactivation must clear submission evaluation hooks.' );

fwrite( STDOUT, "G4-IMP-02-R01 student submission external AI consent contract passed: Q38/C78/C79, isolated literal ledger, exact routing, revocation, worker activation, idempotency and audit chain.\n" );
