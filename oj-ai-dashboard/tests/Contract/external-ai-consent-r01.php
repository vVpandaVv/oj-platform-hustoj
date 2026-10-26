<?php
/**
 * G2-IMP-05-R01 external AI consent ledger and application contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Approve_External_Ai_Consent_Command;
use OJAI\Dashboard\Worksheet\Evaluation\Application\External_Ai_Consent_Failure;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Manage_External_Ai_Consent;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Revoke_External_Ai_Consent_Command;
use OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Routing_Snapshot;
use OJAI\Dashboard\Worksheet\Evaluation\Infrastructure\WordPress_External_Ai_Consent_Repository;
use OJAI\Dashboard\Worksheet\Evaluation\Port\Worksheet_Evaluation_Routing_Port_Interface;
use OJAI\Dashboard\Worksheet\Evaluation\Presentation\External_Ai_Consent_Rest_Controller;

$root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
require_once $root . '/src/Core/Autoloader.php';
( new Autoloader( $root . '/src' ) )->register();

$GLOBALS['r01_options'] = array();
$GLOBALS['r01_routes']  = array();

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Get option.
	 *
	 * @param string $name Name.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	function get_option( string $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['r01_options'] ) ? $GLOBALS['r01_options'][ $name ] : $default;
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
		if ( array_key_exists( $name, $GLOBALS['r01_options'] ) ) {
			return false;
		}
		$GLOBALS['r01_options'][ $name ] = $value;
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
		$GLOBALS['r01_options'][ $name ] = $value;
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
		$exists = array_key_exists( $name, $GLOBALS['r01_options'] );
		unset( $GLOBALS['r01_options'][ $name ] );
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
		$GLOBALS['r01_routes'][] = compact( 'namespace', 'route', 'arguments' );
		return true;
	}
}

/**
 * R01 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function r01_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * R01 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function r01_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . '\nExpected: ' . var_export( $expected, true ) . '\nActual: ' . var_export( $actual, true ) );
	}
}

/**
 * R01 failure.
 *
 * @param callable $operation Operation.
 * @param string   $reason Reason.
 * @param string   $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function r01_failure( callable $operation, string $reason, string $message ): void {
	try {
		$operation();
	} catch ( External_Ai_Consent_Failure $failure ) {
		r01_same( $reason, $failure->reason(), $message );
		return;
	}
	throw new RuntimeException( $message . ': expected failure was not thrown.' );
}

/**
 * G2-IMP-05-R01 external AI consent ledger and application contract.
 */
final class R01Database {
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
				static fn ( array $row ): bool => 'external_ai_consent' === $row['aggregate_type']
					&& '00000000000000000000000001' === $row['aggregate_public_id']
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
 * G2-IMP-05-R01 external AI consent ledger and application contract.
 */
final class R01Routing implements Worksheet_Evaluation_Routing_Port_Interface {
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
	 * Initialize r01 routing dependencies and state.
	 */
	public function __construct() {
		$this->profile = str_repeat( 'a', 64 );
	}

	/**
	 * Plan.
	 *
	 * @return Worksheet_Evaluation_Routing_Snapshot
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function plan(): Worksheet_Evaluation_Routing_Snapshot {
		if ( ! $this->available ) {
			throw new RuntimeException( 'routing unavailable' );
		}
		return new Worksheet_Evaluation_Routing_Snapshot(
			'addon.oj_ai_dashboard.worksheet_quality_assessment',
			$this->provider,
			$this->model,
			$this->profile,
			$this->revision
		);
	}
}

$canonical  = new Canonical_Json();
$database   = new R01Database();
$counter    = 0;
$ids        = new Public_Id_Generator(
	static function ( int $length ) use ( &$counter ): string {
		++$counter;
		return str_repeat( "\0", 15 ) . chr( $counter );
	}
);
$routing    = new R01Routing();
$repository = new WordPress_External_Ai_Consent_Repository( $canonical, $ids, $database );
$manager    = new Manage_External_Ai_Consent(
	$repository,
	$routing,
	$canonical,
	static fn (): string => '2026-08-21 10:00:00'
);

$initial = $manager->read();
r01_same( 'missing', $initial['consent']['state'], 'A fresh install must begin without implicit consent.' );
r01_same( true, $initial['routing']['available'], 'The safe routing snapshot must be visible before approval.' );

$approve  = new Approve_External_Ai_Consent_Command(
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
r01_same( 'approved', $approved->status()['consent']['state'], 'C76 must activate exact-route consent.' );
r01_same( true, $approved->status()['consent']['effective'], 'C76 consent must be effective for the approved route.' );
r01_same( 1, count( $database->tables['oj_ai_outbox_events'] ), 'C76 must append one literal-table consent event.' );
r01_same( 1, count( $database->tables['oj_ai_audit_events'] ), 'C76 must append one audit event.' );
r01_same( 'external_ai_consent.approved', $database->tables['oj_ai_outbox_events'][0]['event_type'], 'C76 event type drifted.' );
r01_same( 'external_ai.consent.approved', $database->tables['oj_ai_audit_events'][0]['action_key'], 'C76 audit action drifted.' );

$replay = $manager->approve( $approve );
r01_same( true, $replay->replayed(), 'The same C76 idempotency key must replay.' );
r01_same( 1, count( $database->tables['oj_ai_outbox_events'] ), 'C76 replay must not append another event.' );
r01_same( 1, count( $database->tables['oj_ai_audit_events'] ), 'C76 replay must not append another audit.' );

$approve_conflict = new Approve_External_Ai_Consent_Command(
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
r01_failure(
	static fn () => $manager->approve( $approve_conflict ),
	External_Ai_Consent_Failure::IDEMPOTENCY_CONFLICT,
	'C76 must reject a different payload that reuses the same idempotency key.'
);

$current = $manager->read();
$revoke  = new Revoke_External_Ai_Consent_Command(
	7,
	'command-revoke-001',
	'approved',
	$current['consent']['consent_hash'],
	str_repeat( '2', 64 ),
	'corr-r01-revoke',
	$canonical
);
$revoked = $manager->revoke( $revoke );
r01_same( 'missing', $revoked->status()['consent']['state'], 'C77 must remove effective consent.' );
r01_same( 2, count( $database->tables['oj_ai_outbox_events'] ), 'C77 must append one revocation event.' );
r01_same( 2, count( $database->tables['oj_ai_audit_events'] ), 'C77 must append one audit event.' );
r01_same(
	$database->tables['oj_ai_audit_events'][0]['event_hash'],
	$database->tables['oj_ai_audit_events'][1]['previous_event_hash'],
	'C77 must preserve the append-only audit hash chain.'
);
r01_same( null, $repository->current_record(), 'The authoritative ledger must return no consent after C77.' );

$approve_after_revoke = new Approve_External_Ai_Consent_Command(
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
r01_same( 'stale', $stale['consent']['state'], 'A changed routing destination must make prior consent stale.' );
r01_same( false, $stale['consent']['effective'], 'Stale consent must never authorize execution.' );

$reapprove = new Approve_External_Ai_Consent_Command(
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
r01_same( 'approved', $renewed->status()['consent']['state'], 'C76 must renew consent after exact routing drift acknowledgement.' );
r01_same( 'provider-b', $renewed->status()['consent']['destination']['provider_key'], 'Renewed consent must bind to the new provider.' );

$last = array_key_last( $database->tables['oj_ai_outbox_events'] );
$database->tables['oj_ai_outbox_events'][ $last ]['payload_hash'] = str_repeat( '0', 64 );
r01_failure(
	static fn () => $manager->read(),
	External_Ai_Consent_Failure::STORED_DATA_INVALID,
	'Q37 must fail closed on a malformed authoritative receipt.'
);

$reflection = new ReflectionClass( External_Ai_Consent_Rest_Controller::class );
/**
 * Controller.
 *
 * @var External_Ai_Consent_Rest_Controller $controller
 */
$controller = $reflection->newInstanceWithoutConstructor();
$controller->register_routes();
$controller->register_routes();
r01_same( 3, count( $GLOBALS['r01_routes'] ), 'R01 must register Q37, C76, and C77 exactly once.' );
r01_same(
	array(
		'/settings/external-ai-consent',
		'/settings/external-ai-consent/commands/approve',
		'/settings/external-ai-consent/commands/revoke',
	),
	array_column( $GLOBALS['r01_routes'], 'route' ),
	'R01 REST route catalog drifted.'
);

$repository_source  = file_get_contents( $root . '/src/Worksheet/Evaluation/Infrastructure/WordPress_External_Ai_Consent_Repository.php' );
$composition_source = file_get_contents( $root . '/src/Core/Composition_Root.php' );
r01_assert( is_string( $repository_source ) && ! str_contains( $repository_source, '$wpdb->prefix' ), 'R01 must not use a WordPress table prefix.' );
r01_assert( ! str_contains( (string) $repository_source, 'wp_oj_ai_' ), 'R01 must not use legacy prefixed add-on tables.' );
r01_assert( str_contains( (string) $repository_source, "'oj_ai_outbox_events'" ), 'R01 must use the literal outbox table.' );
r01_assert( str_contains( (string) $repository_source, "'oj_ai_audit_events'" ), 'R01 must use the literal audit table.' );
r01_assert( ! str_contains( (string) $composition_source, 'CodingPanda\\OjPlatform\\Kernel' ), 'R01 Runtime composition must not use platform internals.' );
r01_assert( str_contains( (string) $composition_source, 'current_record()' ), 'The worker must read the authoritative consent ledger.' );

fwrite( STDOUT, "G2-IMP-05-R01 external AI consent contract passed: Q37/C76/C77, literal ledger, exact routing, revocation, idempotency and audit chain.\n" );
