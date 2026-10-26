<?php
/**
 * Immediate serial evaluation dispatch; no external HTTP requests.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
$mode     = $argv[1] ?? 'default';
$constant = array(
	'disabled'  => 'DISABLE_WP_CRON',
	'alternate' => 'ALTERNATE_WP_CRON',
	'cron'      => 'DOING_CRON',
)[ $mode ] ?? null;
if ( null !== $constant ) {
	define( $constant, true ); }
require dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new OJAI\Dashboard\Core\Autoloader( dirname( __DIR__, 2 ) . '/src' ) )->register();

$http       = array();
$http_error = false;
$checks     = 0;
/**
 * Count a deterministic assertion.
 *
 * @param bool   $ok Assertion condition.
 * @param string $message Failure explanation.
 * @throws RuntimeException If the assertion fails.
 */
function ojai_check( bool $ok, string $message ): void {
	++$GLOBALS['checks'];
	if ( ! $ok ) {
		throw new RuntimeException( $message ); }
}
/**
 * Use a reserved test origin.
 *
 * @param string $path Relative admin path.
 */
function admin_url( string $path ): string {
	return 'https://example.test/wp-admin/' . $path; }
/**
 * Capture HTTP without a network connection.
 *
 * @param string $url Target URL.
 * @param array  $args Request options.
 */
function wp_remote_post( string $url, array $args ): mixed {
	$GLOBALS['http'][] = array( $url, $args );
	return $GLOBALS['http_error'] ? (object) array( 'error' => true ) : array();
}
/**
 * Recognize the synthetic transport error.
 *
 * @param mixed $response Captured response.
 */
function is_wp_error( mixed $response ): bool {
	return is_object( $response ); }
/**
 * Record the endpoint outcome.
 *
 * @param int $status HTTP status.
 */
function status_header( int $status ): void {
	$GLOBALS['status'] = $status; }
/**
 * Capture internal endpoint registration.
 *
 * @param string   $hook Action name.
 * @param callable $callback Registered handler.
 */
function add_action( string $hook, callable $callback ): void {
	$key                               = spl_object_id( $callback[0] ) . ':' . $callback[1];
	$GLOBALS['hooks'][ $hook ][ $key ] = $callback;
}

/**
 * Capture schedule-filter registration without WordPress.
 *
 * @param string   $hook Filter name.
 * @param callable $callback Registered handler.
 */
function add_filter( string $hook, callable $callback ): void {
	add_action( $hook, $callback );
}

/** In-memory coordination state, with explicit worker-lock contention. */
final class Evaluation_Dispatch_Db {
	/**
	 * Options table identifier.
	 *
	 * @var string
	 */
	public string $options = 'wp_options';
	/**
	 * Database error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Stored ticket hash and expiry.
	 *
	 * @var string|null
	 */
	public ?string $value = null;
	/**
	 * Worker lock state.
	 *
	 * @var bool
	 */
	public bool $busy = false;
	/**
	 * Queue fixture rows.
	 *
	 * @var array
	 */
	public array $rows = array();
	/**
	 * Observed readiness SQL.
	 *
	 * @var string
	 */
	public string $last_query = '';
	/**
	 * Preserve SQL and parameters for deterministic observation.
	 *
	 * @param string $sql Query template.
	 * @param mixed  ...$args Bound arguments.
	 */
	public function prepare( string $sql, mixed ...$args ): string {
		return json_encode( array( $sql, $args ), JSON_THROW_ON_ERROR ); }
	/**
	 * Simulate coordination queries.
	 *
	 * @param string $query Encoded prepared query.
	 * @throws RuntimeException If an unexpected query is issued.
	 */
	public function get_var( string $query ): mixed {
		list( $sql, $args ) = json_decode( $query, true, 512, JSON_THROW_ON_ERROR );
		if ( str_contains( $sql, 'SELECT option_value' ) ) {
			return $this->value; }
		if ( str_contains( $args[0], '_signal_' ) ) {
			return '1'; }
		if ( str_contains( $sql, 'IS_FREE_LOCK' ) ) {
			return $this->busy ? '0' : '1'; }
		if ( str_contains( $sql, 'GET_LOCK' ) ) {
			if ( $this->busy ) {
				return '0'; }
			$this->busy = true;
			return '1';
		}
		if ( str_contains( $sql, 'RELEASE_LOCK' ) ) {
			$this->busy = false;
			return '1'; }
		throw new RuntimeException( 'Unexpected query.' );
	}
	/**
	 * Simulate atomic ticket storage and consumption.
	 *
	 * @param string $query Encoded prepared query.
	 */
	public function query( string $query ): int {
		list( $sql, $args ) = json_decode( $query, true, 512, JSON_THROW_ON_ERROR );
		if ( str_starts_with( $sql, 'INSERT' ) ) {
			$this->value = $args[1];
			return 1; }
		if ( $this->value !== $args[1] ) {
			return 0; }
		$this->value = null;
		return 1;
	}
	/**
	 * Select due fixture items in deterministic order.
	 *
	 * @param string $sql Readiness query.
	 * @param string $format Requested row format.
	 */
	public function get_row( string $sql, string $format ): ?array {
		$this->last_query = $sql;
		$due              = array_values( array_filter( $this->rows, static fn ( array $row ): bool => $row['due'] <= time() ) );
		usort( $due, static fn ( array $a, array $b ): int => array( $a['due'], $a['id'] ) <=> array( $b['due'], $b['id'] ) );
		return $due[0] ?? null;
	}
}

$db         = new Evaluation_Dispatch_Db();
$dispatcher = new OJAI\Dashboard\Worksheet\Evaluation\Infrastructure\WordPress_Evaluation_Queue_Dispatcher( $db );
$dispatcher->register();
$submission     = new OJAI\Dashboard\Submission\Assessment\Infrastructure\WordPress_Submission_Evaluation_Queue_Wakeup( $dispatcher );
$worksheet      = new OJAI\Dashboard\Worksheet\Evaluation\Infrastructure\WordPress_Worksheet_Evaluation_Queue_Wakeup( $dispatcher );
$worker_modules = array(
	array( OJAI\Dashboard\Worksheet\Evaluation\Application\Run_Next_Worksheet_Evaluation::class, OJAI\Dashboard\Worksheet\Evaluation\Infrastructure\Worksheet_Evaluation_Worker_Module::class ),
	array( OJAI\Dashboard\Submission\Assessment\Application\Run_Next_Submission_Evaluation::class, OJAI\Dashboard\Submission\Assessment\Infrastructure\Submission_Evaluation_Worker_Module::class ),
	array( OJAI\Dashboard\Submission\Assessment\Application\Run_Next_Submission_Feedback::class, OJAI\Dashboard\Submission\Assessment\Infrastructure\Submission_Feedback_Worker_Module::class ),
	array( OJAI\Dashboard\Submission\Assessment\Application\Run_Next_Student_Note_Draft::class, OJAI\Dashboard\Submission\Assessment\Infrastructure\Student_Note_Worker_Module::class ),
);
foreach ( $worker_modules as list( $worker_class, $module_class ) ) {
	$worker = ( new ReflectionClass( $worker_class ) )->newInstanceWithoutConstructor();
	$module = new $module_class( $worker, $dispatcher );
	$module->register();
	$callbacks = array_values( $GLOBALS['hooks'][ $module_class::SWEEP_HOOK ] );
	ojai_check( array( $dispatcher, 'run' ) === $callbacks[0], 'All cron recovery hooks use the serial dispatcher.' );
}
ojai_check( 1 === count( $GLOBALS['hooks']['oj_ai_dashboard_evaluation_worker'] ), 'Shared event must not invoke four independent workers.' );
$handled = array();
foreach ( array( 'worksheet', 'score', 'feedback_draft', 'special_note_draft' ) as $key ) {
	$dispatcher->register_worker(
		$key,
		static function () use ( $db, &$handled, $key ): void {
			ojai_check( $db->busy, 'AI requires the queue lock.' );
			$row      = $db->get_row( $db->last_query, 'ARRAY_A' );
			$expected = 'worksheet_evaluation' === $row['subject_type'] ? 'worksheet' : $row['operation_key'];
			ojai_check( $key === $expected, 'Oldest eligible work across all four types is dispatched.' );
			$handled[] = $row['id'];
			unset( $db->rows[ $row['id'] ] );
		}
	);
}
foreach ( array( 'score', 'worksheet', 'special_note_draft', 'feedback_draft', 'score' ) as $id => $key ) {
	$db->rows[ $id ] = array(
		'id'            => $id,
		'lock_version'  => 0,
		'subject_type'  => 'worksheet' === $key ? 'worksheet_evaluation' : 'submission_evaluation',
		'operation_key' => $key,
		'due'           => time() - 1,
	);
}
$db->rows[99] = array(
	'id'            => 99,
	'lock_version'  => 0,
	'subject_type'  => 'submission_evaluation',
	'operation_key' => 'score',
	'due'           => time() + 300,
);
$submission->wake();
for ( $i = 0; $i < 100; ++$i ) {
	$worksheet->wake(); }
ojai_check( 1 === count( $http ), 'Bulk enqueue coalesces independently of cron mode.' );
ojai_check( array() === $handled, 'Enqueue never runs AI inline.' );
$request = $http[0][1];
ojai_check( false === $request['blocking'] && true === $request['sslverify'] && 0 === $request['redirection'], 'Safe nonblocking loopback required.' );
ojai_check( array( 'action', 'ticket' ) === array_keys( $request['body'] ), 'No submission or provider data in signals.' );
ojai_check( ! str_contains( (string) $db->value, $request['body']['ticket'] ), 'Only ticket hash is stored.' );
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['ticket']           = str_repeat( 'a', 64 );
$dispatcher->handle();
ojai_check( 403 === $GLOBALS['status'] && array() === $handled, 'Reject forgery.' );
$_SERVER['REQUEST_METHOD'] = 'GET';
$_POST['ticket']           = $request['body']['ticket'];
$dispatcher->handle();
ojai_check( 403 === $GLOBALS['status'], 'GET cannot consume tickets.' );
$_SERVER['REQUEST_METHOD'] = 'POST';
for ( $i = 0; $i < 5; ++$i ) {
	ojai_check( isset( $http[ $i ] ), 'Completion immediately signals next work.' );
	$_POST['ticket'] = $http[ $i ][1]['body']['ticket'];
	$dispatcher->handle();
	ojai_check( 204 === $GLOBALS['status'], 'Valid signal runs one job.' );
	$dispatcher->handle();
	ojai_check( 403 === $GLOBALS['status'], 'Reject replay.' );
}
ojai_check( array( 0, 1, 2, 3, 4 ) === $handled, 'FIFO, not fixed subject priority.' );
ojai_check( 5 === count( $http ) && isset( $db->rows[99] ), 'Future retry stays queued, with no dispatch loop.' );
ojai_check( str_contains( $db->last_query, 'ORDER BY r.available_at_utc ASC, r.id ASC' ) && str_contains( $db->last_query, 'r.available_at_utc <= UTC_TIMESTAMP()' ), 'SQL readiness and FIFO.' );
ojai_check( false === $db->busy, 'Lock released.' );
$db->busy = true;
$dispatcher->run();
$dispatcher->wake();
ojai_check( 5 === count( $http ) && 5 === count( $handled ), 'Concurrent cron and HTTP cannot run another item.' );
$db->busy            = false;
$db->rows[99]['due'] = time() - 1;
$dispatcher->register_worker( 'score', static function (): void {} );
$dispatcher->run();
ojai_check( 5 === count( $http ), 'Unchanged head cannot busy-loop.' );
$dispatcher->register_worker(
	'score',
	static function (): void {
		throw new RuntimeException( 'Synthetic failure' );
	}
);
$dispatcher->run();
ojai_check( 5 === count( $http ) && false === $db->busy, 'Exceptions unlock and defer to recovery.' );
$http_error = true;
$submission->wake();
ojai_check( null === $db->value && isset( $db->rows[99] ), 'Transport failure preserves work and clears its signal.' );
$http_error = false;
$worksheet->wake();
$ticket          = $http[ array_key_last( $http ) ][1]['body']['ticket'];
$db->value       = json_encode(
	array(
		'hash'    => hash( 'sha256', $ticket ),
		'expires' => time() - 1,
	)
);
$_POST['ticket'] = $ticket;
$dispatcher->handle();
ojai_check( 403 === $GLOBALS['status'], 'Reject expired tickets.' );
fwrite( STDOUT, 'PASS evaluation dispatch: ' . $mode . ' (' . $checks . " checks)\n" );
