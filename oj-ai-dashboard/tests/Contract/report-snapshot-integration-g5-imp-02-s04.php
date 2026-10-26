<?php
/**
 * G5-IMP-02-S04 report snapshot stale, authorization, and concurrency freeze.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Reporting\Report\Application\Create_Report_Snapshot;
use OJAI\Dashboard\Reporting\Report\Application\Report_Snapshot_Failure;
use OJAI\Dashboard\Reporting\Report\Application\Read_Report_Snapshot;
use OJAI\Dashboard\Reporting\Report\Application\Report_Snapshot_Query_Failure;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Filter;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Snapshot_Profile;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Snapshot_Scope;
use OJAI\Dashboard\Reporting\Report\Infrastructure\WordPress_Report_Snapshot_Repository;
use OJAI\Dashboard\Reporting\Report\Port\Assignment_Progress_Source_Query_Port_Interface;
use OJAI\Dashboard\Reporting\Report\Port\Report_Snapshot_Query_Port_Interface;
use OJAI\Dashboard\Reporting\Report\Presentation\Report_Snapshot_Query_Rest_Controller;
use OJAI\Dashboard\Reporting\Report\Presentation\Report_Snapshot_Rest_Controller;
use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Security\Policy\Port\Object_Authorization_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

require __DIR__ . DIRECTORY_SEPARATOR . 'report-snapshot-create-g5-imp-02-s01.php';

/**
 * G5 snapshot s04 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_snapshot_s04_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G5 snapshot s04 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_snapshot_s04_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G5 snapshot s04 query record.
 *
 * @param array<string,mixed> $created Created.
 * @return array
 */
function g5_snapshot_s04_query_record( array $created ): array {
	return array(
		'public_id'               => $created['snapshot_public_id'],
		'report_type'             => 'worksheet_progress',
		'subject_type'            => 'context',
		'subject_public_id'       => null,
		'state'                   => 'ready',
		'context_type'            => $created['context_type'],
		'context_id'              => $created['context_id'],
		'context_revision'        => $created['context_revision'],
		'filter_schema'           => Report_Filter::SCHEMA_ID,
		'filter_version'          => Report_Snapshot_Profile::FILTER_DATABASE_VERSION,
		'filter_json'             => $created['filter_json'],
		'filter_hash'             => $created['filter_hash'],
		'source_watermark'        => $created['source_manifest_hash'],
		'source_manifest_schema'  => Report_Snapshot_Profile::SOURCE_MANIFEST_SCHEMA,
		'source_manifest_version' => Report_Snapshot_Profile::SOURCE_MANIFEST_DATABASE_VERSION,
		'source_manifest_json'    => $created['source_manifest_json'],
		'source_manifest_hash'    => $created['source_manifest_hash'],
		'projection_schema'       => Report_Snapshot_Profile::PROJECTION_SCHEMA,
		'projection_version'      => Report_Snapshot_Profile::PROJECTION_DATABASE_VERSION,
		'projection_json'         => $created['projection_json'],
		'projection_hash'         => $created['projection_hash'],
		'generated_by_user_id'    => $created['actor_user_id'],
		'generated_at_utc'        => $created['generated_at_utc'],
		'expires_at_utc'          => null,
	);
}

/**
 * G5-IMP-02-S04 report snapshot stale, authorization, and concurrency freeze.
 */
final class G5_Snapshot_S04_Query implements Report_Snapshot_Query_Port_Interface {
	/**
	 * Find calls.
	 *
	 * @var int
	 */
	public int $find_calls = 0;

	/**
	 * Initialize g5 snapshot s04 query dependencies and state.
	 *
	 * @param array<string,mixed>|null $record Record.
	 * @param Canonical_Json           $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct( public ?array $record, private Canonical_Json $canonical ) {
	}

	/**
	 * Scope.
	 *
	 * @param string $snapshot_id Snapshot id.
	 * @return Report_Snapshot_Scope|null
	 */
	public function scope( string $snapshot_id ): ?Report_Snapshot_Scope {
		if ( null === $this->record || $snapshot_id !== $this->record['public_id'] || 'ready' !== $this->record['state'] ) {
			return null;
		}
		$identity = $this->canonical->encode(
			array(
				'schema_id'            => Report_Snapshot_Profile::IDENTITY_SCHEMA,
				'schema_version'       => Report_Snapshot_Profile::IDENTITY_VERSION,
				'snapshot_id'          => $snapshot_id,
				'filter_hash'          => $this->record['filter_hash'],
				'source_manifest_hash' => $this->record['source_manifest_hash'],
				'projection_hash'      => $this->record['projection_hash'],
			)
		);
		return new Report_Snapshot_Scope(
			$snapshot_id,
			'ready',
			hash( 'sha256', $identity ),
			(string) $this->record['context_type'],
			(string) $this->record['context_id'],
			(string) $this->record['context_revision']
		);
	}

	/**
	 * Find record.
	 *
	 * @param string $snapshot_id Snapshot id.
	 * @return array|null
	 */
	public function find_record( string $snapshot_id ): ?array {
		++$this->find_calls;
		return null !== $this->record && $snapshot_id === $this->record['public_id'] ? $this->record : null;
	}
}

/**
 * G5-IMP-02-S04 report snapshot stale, authorization, and concurrency freeze.
 */
final class G5_Snapshot_S04_Objects implements Object_Authorization_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var array<int,string>
	 */
	public array $calls = array();
	/**
	 * Deny id.
	 *
	 * @var string|null
	 */
	public ?string $deny_id = null;
	/**
	 * Unavailable id.
	 *
	 * @var string|null
	 */
	public ?string $unavailable_id = null;

	/**
	 * Authorize.
	 *
	 * @param int                               $actor_user_id Authenticated actor from the access context.
	 * @param string                            $action_key Action key.
	 * @param Context_Authorization_Result|null $context Access or execution context for this operation.
	 * @param Object_Scope                      $object Object.
	 * @return Object_Authorization_Result
	 */
	public function authorize(
		int $actor_user_id,
		string $action_key,
		?Context_Authorization_Result $context,
		Object_Scope $object
	): Object_Authorization_Result {
		$this->calls[] = $object->id();
		g5_snapshot_s04_same( 77, $actor_user_id, 'S04 worksheet authorization actor drifted.' );
		g5_snapshot_s04_same( Read_Report_Snapshot::ACTION_READ, $action_key, 'S04 worksheet authorization action drifted.' );
		g5_snapshot_s04_same( Object_Scope::TYPE_WORKSHEET, $object->type(), 'S04 must authorize worksheet owner objects.' );
		g5_snapshot_s04_same( 'view', $object->action(), 'S04 worksheet object action drifted.' );
		if ( $object->id() === $this->deny_id ) {
			return Object_Authorization_Result::denied();
		}
		if ( $object->id() === $this->unavailable_id ) {
			return Object_Authorization_Result::unavailable();
		}
		return Object_Authorization_Result::allowed( hash( 'sha256', $object->id() ), 'active', 'educator' );
	}
}

/**
 * G5-IMP-02-S04 report snapshot stale, authorization, and concurrency freeze.
 */
final class G5_Snapshot_S04_Never_Progress implements Assignment_Progress_Source_Query_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;

	/**
	 * List context.
	 *
	 * @param int                $actor_user_id Authenticated actor from the access context.
	 * @param string             $context_type Context type.
	 * @param string             $context_id Context id.
	 * @param string             $context_revision Context revision.
	 * @param string             $cursor Continuation cursor from the previous page.
	 * @param int                $limit Maximum number of entries requested.
	 * @param Report_Filter|null $filter Filter.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function list_context(
		int $actor_user_id,
		string $context_type,
		string $context_id,
		string $context_revision,
		string $cursor,
		int $limit,
		?Report_Filter $filter = null
	): array {
		++$this->calls;
		throw new RuntimeException( 'An exact durable replay touched mutable report sources.' );
	}
}

/**
 * G5 snapshot s04 expect query failure.
 *
 * @param array<string,mixed>          $record Record.
 * @param Canonical_Json               $canonical Canonical JSON encoder used for stable digests.
 * @param Context_Authorization_Result $context Access or execution context for this operation.
 * @param string                       $expected_reason Expected reason.
 * @param string                       $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_snapshot_s04_expect_query_failure(
	array $record,
	Canonical_Json $canonical,
	Context_Authorization_Result $context,
	string $expected_reason,
	string $message
): void {
	foreach ( array( 'read', 'read_source_manifest' ) as $method ) {
		$reader = new Read_Report_Snapshot(
			new G5_Snapshot_S04_Query( $record, $canonical ),
			new G5_Snapshot_S04_Objects(),
			new Report_Snapshot_Profile( $canonical ),
			$canonical
		);
		try {
			$reader->{$method}( 77, (string) $record['public_id'], $context );
			throw new RuntimeException( $message . ' Method: ' . $method );
		} catch ( Report_Snapshot_Query_Failure $failure ) {
			g5_snapshot_s04_same( $expected_reason, $failure->reason(), $message . ' Method: ' . $method );
		}
	}
}

g5_snapshot_s04_assert( is_array( $memory->last_record ), 'S04 did not receive the actual C45 persistence record.' );
$created_record    = $memory->last_record;
$query_record      = g5_snapshot_s04_query_record( $created_record );
$stored_projection = json_decode( $query_record['projection_json'], true, 128, JSON_THROW_ON_ERROR );
$stored_manifest   = json_decode( $query_record['source_manifest_json'], true, 128, JSON_THROW_ON_ERROR );
$worksheet_ids     = array_map(
	static fn ( array $item ): string => (string) $item['worksheet']['id'],
	$stored_projection['items']
);
$current_context   = Context_Authorization_Result::allowed(
	'class',
	'class-g5-snapshot',
	'active',
	'class-g5-snapshot-r99',
	'educator',
	'correlation-g5-s04'
);
$query             = new G5_Snapshot_S04_Query( $query_record, $canonical );
$objects           = new G5_Snapshot_S04_Objects();
$reader            = new Read_Report_Snapshot( $query, $objects, new Report_Snapshot_Profile( $canonical ), $canonical );

$public_payload   = $reader->read( 77, $result->snapshot_id(), $current_context )->payload();
$manifest_payload = $reader->read_source_manifest( 77, $result->snapshot_id(), $current_context )->payload();
g5_snapshot_s04_same( $result->snapshot_id(), $public_payload['id'], 'S04 changed the C45 snapshot ID at Q22.' );
g5_snapshot_s04_same( $result->snapshot_hash(), $public_payload['snapshot_hash'], 'S04 changed the C45 snapshot identity at Q22.' );
g5_snapshot_s04_same( $stored_projection, $public_payload['projection'], 'S04 did not return the exact C45 projection.' );
g5_snapshot_s04_same( $created_record['source_manifest_json'], $manifest_payload['source_manifest_json'], 'S04 changed the exact C45 manifest bytes.' );
g5_snapshot_s04_same( $stored_manifest, $manifest_payload['source_manifest'], 'S04 changed the exact C45 manifest document.' );
g5_snapshot_s04_same( $result->snapshot_hash(), $manifest_payload['snapshot_hash'], 'S04 manifest handoff lost the C45 snapshot identity.' );
g5_snapshot_s04_same(
	array(
		'type'              => 'class',
		'id'                => 'class-g5-snapshot',
		'snapshot_revision' => 'class-g5-snapshot-r1',
	),
	$manifest_payload['context'],
	'S04 must preserve the snapshot revision while authorizing the current Context revision.'
);
g5_snapshot_s04_same( array_merge( $worksheet_ids, $worksheet_ids ), $objects->calls, 'S04 did not reauthorize every worksheet for Q22 and provenance.' );
g5_snapshot_s04_assert( ! array_key_exists( 'source_manifest', $public_payload ), 'S04 exposed the internal source manifest through Q22.' );
g5_snapshot_s04_assert( ! array_key_exists( 'source_manifest_json', $public_payload ), 'S04 exposed canonical manifest bytes through Q22.' );

$before_live_drift                            = $public_payload;
$before_manifest_drift                        = $manifest_payload;
$drifted_source                               = $source_one;
$drifted_source['progress']['updated_at_utc'] = '2026-08-25 15:00:00';
$drifted_source['progress']                   = g5_snapshot_rehash( $canonical, $drifted_source['progress'] );
$drifted_projection                           = $projector->project(
	array( $drifted_source['progress'] ),
	array( $drifted_source['worksheet'] ),
	array( $drifted_source['assessment'] )
);
g5_snapshot_s04_assert(
	! hash_equals( $command_values['first_manifest_hash'], $drifted_projection['source_manifest_hash'] ),
	'S04 live-source fixture did not create a newer source manifest.'
);
g5_snapshot_s04_same( $before_live_drift, $reader->read( 77, $result->snapshot_id(), $current_context )->payload(), 'A later live source changed an immutable Q22 snapshot.' );
g5_snapshot_s04_same( $before_manifest_drift, $reader->read_source_manifest( 77, $result->snapshot_id(), $current_context )->payload(), 'A later live source changed immutable snapshot provenance.' );

$never_progress     = new G5_Snapshot_S04_Never_Progress();
$replay_application = new Create_Report_Snapshot(
	$never_progress,
	new G5_Snapshot_Worksheets( array() ),
	new G5_Snapshot_Assessments( array() ),
	$projector,
	$memory,
	new Report_Snapshot_Profile( $canonical ),
	new Public_Id_Generator(),
	$canonical,
	static fn (): string => '2026-08-25 15:05:00'
);
$late_replay        = $replay_application->execute( $command_values['command'] );
g5_snapshot_s04_assert( $late_replay->replayed(), 'S04 exact retry did not use the durable C45 receipt.' );
g5_snapshot_s04_same( $result->snapshot_id(), $late_replay->snapshot_id(), 'S04 exact retry changed the committed snapshot winner.' );
g5_snapshot_s04_same( 0, $never_progress->calls, 'S04 exact retry read mutable sources before durable replay.' );
g5_snapshot_s04_same( null, $race_repository->last_record, 'S04 generation-race fixture persisted a stale snapshot.' );

$concurrent_database                     = new G5_Snapshot_Wpdb();
$concurrent_database->race_outbox_insert = true;
$concurrent_winner                       = ( new WordPress_Report_Snapshot_Repository( $canonical, $concurrent_database ) )->create( $created_record );
g5_snapshot_s04_assert( $concurrent_winner->replayed(), 'S04 same-key concurrent loser did not recover the durable winner.' );
g5_snapshot_s04_same( $result->snapshot_id(), $concurrent_winner->snapshot_id(), 'S04 same-key concurrency changed snapshot identity.' );
g5_snapshot_s04_same( 1, count( $concurrent_database->tables['oj_ai_report_snapshots'] ?? array() ), 'S04 same-key concurrency committed more than one snapshot.' );
g5_snapshot_s04_same( 1, count( $concurrent_database->tables['oj_ai_outbox_events'] ?? array() ), 'S04 same-key concurrency committed more than one receipt.' );
g5_snapshot_s04_assert( in_array( 'ROLLBACK', $concurrent_database->queries, true ), 'S04 concurrent loser did not roll back before winner recovery.' );

$conflict_database                      = new G5_Snapshot_Wpdb();
$conflict_database->race_outbox_insert  = true;
$conflict_database->race_request_digest = hash_equals( $created_record['request_digest'], str_repeat( 'f', 64 ) )
	? str_repeat( 'e', 64 )
	: str_repeat( 'f', 64 );
try {
	( new WordPress_Report_Snapshot_Repository( $canonical, $conflict_database ) )->create( $created_record );
	throw new RuntimeException( 'S04 accepted a same-key concurrent winner with another request digest.' );
} catch ( Report_Snapshot_Failure $failure ) {
	g5_snapshot_s04_same( Report_Snapshot_Failure::IDEMPOTENCY_CONFLICT, $failure->reason(), 'S04 concurrent digest conflict reason drifted.' );
}
g5_snapshot_s04_same( 1, count( $conflict_database->tables['oj_ai_report_snapshots'] ?? array() ), 'S04 concurrent conflict did not preserve the single committed winner.' );

$denied_query  = new G5_Snapshot_S04_Query( $query_record, $canonical );
$denied_reader = new Read_Report_Snapshot( $denied_query, new G5_Snapshot_S04_Objects(), new Report_Snapshot_Profile( $canonical ), $canonical );
try {
	$denied_reader->read( 77, $result->snapshot_id(), Context_Authorization_Result::denied( 'context_scope_denied', 'correlation-g5-s04-denied' ) );
	throw new RuntimeException( 'S04 disclosed a snapshot after Context access was revoked.' );
} catch ( Report_Snapshot_Query_Failure $failure ) {
	g5_snapshot_s04_same( Report_Snapshot_Query_Failure::SOURCE_ACCESS_DENIED, $failure->reason(), 'S04 Context revocation reason drifted.' );
}
g5_snapshot_s04_same( 0, $denied_query->find_calls, 'S04 Context revocation must stop before loading snapshot content.' );

$revoked_objects          = new G5_Snapshot_S04_Objects();
$revoked_objects->deny_id = $worksheet_ids[1];
$revoked_reader           = new Read_Report_Snapshot( new G5_Snapshot_S04_Query( $query_record, $canonical ), $revoked_objects, new Report_Snapshot_Profile( $canonical ), $canonical );
foreach ( array( 'read', 'read_source_manifest' ) as $method ) {
	try {
		$revoked_reader->{$method}( 77, $result->snapshot_id(), $current_context );
		throw new RuntimeException( 'S04 disclosed snapshot data after worksheet access was revoked. Method: ' . $method );
	} catch ( Report_Snapshot_Query_Failure $failure ) {
		g5_snapshot_s04_same( Report_Snapshot_Query_Failure::SOURCE_ACCESS_DENIED, $failure->reason(), 'S04 worksheet revocation reason drifted.' );
	}
}

$unavailable_objects                 = new G5_Snapshot_S04_Objects();
$unavailable_objects->unavailable_id = $worksheet_ids[0];
$unavailable_reader                  = new Read_Report_Snapshot( new G5_Snapshot_S04_Query( $query_record, $canonical ), $unavailable_objects, new Report_Snapshot_Profile( $canonical ), $canonical );
try {
	$unavailable_reader->read_source_manifest( 77, $result->snapshot_id(), $current_context );
	throw new RuntimeException( 'S04 disclosed provenance while worksheet policy was unavailable.' );
} catch ( Report_Snapshot_Query_Failure $failure ) {
	g5_snapshot_s04_same( Report_Snapshot_Query_Failure::SOURCE_ACCESS_UNAVAILABLE, $failure->reason(), 'S04 unavailable worksheet policy reason drifted.' );
}

$hash_damaged                    = $query_record;
$hash_damaged['projection_json'] = str_replace( 'Worksheet 1', 'Worksheet damaged', $hash_damaged['projection_json'] );
g5_snapshot_s04_assert( $hash_damaged['projection_json'] !== $query_record['projection_json'], 'S04 projection hash-damage fixture did not mutate bytes.' );
g5_snapshot_s04_expect_query_failure( $hash_damaged, $canonical, $current_context, Report_Snapshot_Query_Failure::STORED_DATA_INVALID, 'S04 accepted projection bytes that no longer match the stored hash.' );

$unknown_field                       = $query_record;
$unknown_projection                  = json_decode( $unknown_field['projection_json'], true, 128, JSON_THROW_ON_ERROR );
$unknown_projection['student_email'] = 'hidden@example.test';
$unknown_field['projection_json']    = $canonical->encode( $unknown_projection );
$unknown_field['projection_hash']    = hash( 'sha256', $unknown_field['projection_json'] );
g5_snapshot_s04_expect_query_failure( $unknown_field, $canonical, $current_context, Report_Snapshot_Query_Failure::STORED_DATA_INVALID, 'S04 accepted a rehashed private projection field.' );

$noncanonical                                    = $query_record;
$noncanonical_manifest                           = json_decode( $noncanonical['source_manifest_json'], true, 128, JSON_THROW_ON_ERROR );
$noncanonical['source_manifest_json']            = json_encode( $noncanonical_manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
$noncanonical['source_manifest_hash']            = hash( 'sha256', $noncanonical['source_manifest_json'] );
$noncanonical['source_watermark']                = $noncanonical['source_manifest_hash'];
$noncanonical_projection                         = json_decode( $noncanonical['projection_json'], true, 128, JSON_THROW_ON_ERROR );
$noncanonical_projection['source_manifest_hash'] = $noncanonical['source_manifest_hash'];
$noncanonical['projection_json']                 = $canonical->encode( $noncanonical_projection );
$noncanonical['projection_hash']                 = hash( 'sha256', $noncanonical['projection_json'] );
g5_snapshot_s04_expect_query_failure( $noncanonical, $canonical, $current_context, Report_Snapshot_Query_Failure::STORED_DATA_INVALID, 'S04 accepted noncanonical stored manifest bytes with matching hashes.' );

$unlinked          = $query_record;
$unlinked_manifest = json_decode( $unlinked['source_manifest_json'], true, 128, JSON_THROW_ON_ERROR );
$unlinked_manifest['entries'][0]['sources'][0]['hash'] = hash( 'sha256', 's04-unlinked-source' );
$unlinked['source_manifest_json']                      = $canonical->encode( $unlinked_manifest );
$unlinked['source_manifest_hash']                      = hash( 'sha256', $unlinked['source_manifest_json'] );
$unlinked['source_watermark']                          = $unlinked['source_manifest_hash'];
$unlinked_projection                                   = json_decode( $unlinked['projection_json'], true, 128, JSON_THROW_ON_ERROR );
$unlinked_projection['source_manifest_hash']           = $unlinked['source_manifest_hash'];
$unlinked['projection_json']                           = $canonical->encode( $unlinked_projection );
$unlinked['projection_hash']                           = hash( 'sha256', $unlinked['projection_json'] );
g5_snapshot_s04_expect_query_failure( $unlinked, $canonical, $current_context, Report_Snapshot_Query_Failure::STORED_DATA_INVALID, 'S04 accepted projection and manifest source-link drift.' );

$unsupported                           = $query_record;
$unsupported['source_manifest_schema'] = 'oj_ai.report_source_manifest.v2';
g5_snapshot_s04_expect_query_failure( $unsupported, $canonical, $current_context, Report_Snapshot_Query_Failure::PROFILE_UNSUPPORTED, 'S04 accepted an unsupported stored manifest profile.' );

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * G5-IMP-02-S04 report snapshot stale, authorization, and concurrency freeze.
	 */
	final class WP_Error {
		/**
		 * Initialize wp error dependencies and state.
		 *
		 * @param string              $code Code.
		 * @param string              $message Message.
		 * @param array<string,mixed> $data Data.
		 */
		public function __construct( private string $code, private string $message, private array $data ) {
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
		 * @return array<string,mixed>
		 */
		public function get_error_data(): array {
			return $this->data;
		}
	}
}

$permissions       = ( new ReflectionClass( Rest_Permission_Handoff::class ) )->newInstanceWithoutConstructor();
$correlations      = ( new ReflectionClass( Correlation_Id_Factory::class ) )->newInstanceWithoutConstructor();
$envelopes         = new Rest_Envelope_Factory();
$create_controller = new Report_Snapshot_Rest_Controller( $permissions, $application, $canonical, $envelopes, $correlations );
$create_problem    = new ReflectionMethod( $create_controller, 'snapshot_problem' );
$stale_problem     = $create_problem->invoke( $create_controller, Report_Snapshot_Failure::because( Report_Snapshot_Failure::SOURCE_STALE ), 'correlation-g5-s04' );
g5_snapshot_s04_same( Report_Snapshot_Failure::SOURCE_STALE, $stale_problem->get_error_code(), 'S04 C45 stale REST code drifted.' );
g5_snapshot_s04_same( 409, $stale_problem->get_error_data()['status'] ?? null, 'S04 C45 stale REST status must remain 409.' );

$query_controller = new Report_Snapshot_Query_Rest_Controller( $permissions, $reader, $envelopes, $correlations );
$query_problem    = new ReflectionMethod( $query_controller, 'snapshot_problem' );
$denied_problem   = $query_problem->invoke( $query_controller, Report_Snapshot_Query_Failure::because( Report_Snapshot_Query_Failure::SOURCE_ACCESS_DENIED ), 'correlation-g5-s04' );
g5_snapshot_s04_same( Report_Snapshot_Query_Failure::SOURCE_ACCESS_DENIED, $denied_problem->get_error_code(), 'S04 Q22 revocation REST code drifted.' );
g5_snapshot_s04_same( 404, $denied_problem->get_error_data()['status'] ?? null, 'S04 Q22 revocation must remain enumeration-safe 404.' );
$damaged_problem = $query_problem->invoke( $query_controller, Report_Snapshot_Query_Failure::because( Report_Snapshot_Query_Failure::STORED_DATA_INVALID ), 'correlation-g5-s04' );
g5_snapshot_s04_same( 503, $damaged_problem->get_error_data()['status'] ?? null, 'S04 damaged stored snapshot REST status must remain 503.' );
g5_snapshot_s04_assert( ! str_contains( json_encode( $damaged_problem->get_error_data(), JSON_THROW_ON_ERROR ), 'source_manifest_json' ), 'S04 REST error exposed stored snapshot content.' );

$addon_root       = dirname( __DIR__, 2 );
$production_files = array(
	$addon_root . '/src/Reporting/Report/Application/Create_Report_Snapshot.php',
	$addon_root . '/src/Reporting/Report/Application/Read_Report_Snapshot.php',
	$addon_root . '/src/Reporting/Report/Application/Read_Report_Source_Manifest_Result.php',
	$addon_root . '/src/Reporting/Report/Infrastructure/WordPress_Report_Snapshot_Repository.php',
	$addon_root . '/src/Reporting/Report/Infrastructure/WordPress_Report_Snapshot_Query.php',
	$addon_root . '/src/Reporting/Report/Presentation/Report_Snapshot_Rest_Controller.php',
	$addon_root . '/src/Reporting/Report/Presentation/Report_Snapshot_Query_Rest_Controller.php',
	$addon_root . '/src/Core/Composition_Root.php',
);
$runtime_source   = '';
foreach ( $production_files as $path ) {
	$source          = (string) file_get_contents( $path );
	$runtime_source .= $source;
	foreach ( array( '$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\\OjPlatform\\Kernel\\' ) as $forbidden ) {
		g5_snapshot_s04_assert( ! str_contains( $source, $forbidden ), 'S04 crossed a frozen boundary: ' . $forbidden );
	}
}
g5_snapshot_s04_assert( 1 !== preg_match( '/\b(?:UPDATE|DELETE)\s+oj_ai_report_snapshots\b/i', $runtime_source ), 'S04 must keep report snapshots append-only.' );
$presentation_source = '';
foreach ( glob( $addon_root . '/src/Reporting/Report/Presentation/*.php' ) as $path ) {
	$presentation_source .= (string) file_get_contents( $path );
}
g5_snapshot_s04_assert( ! str_contains( $presentation_source, 'read_source_manifest' ), 'S04 internal provenance acquired a presentation adapter.' );

echo "G5-IMP-02-S04 passed: create-to-query immutability, stale source rejection, stored-profile integrity, current authorization, durable replay, and one concurrent winner are frozen.\n";
