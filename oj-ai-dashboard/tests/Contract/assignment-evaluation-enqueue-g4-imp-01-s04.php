<?php
/**
 * G4-IMP-01-S04 C30 assignment submission evaluation enqueue contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

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
use OJAI\Dashboard\Submission\Assessment\Application\Assignment_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Application\Enqueue_Assignment_Evaluations;
use OJAI\Dashboard\Submission\Assessment\Application\Enqueue_Assignment_Evaluations_Command;
use OJAI\Dashboard\Submission\Assessment\Application\Enqueue_Assignment_Evaluations_Result;
use OJAI\Dashboard\Submission\Assessment\Application\Enqueue_Submission_Evaluation_Command;
use OJAI\Dashboard\Submission\Assessment\Application\Enqueue_Submission_Evaluation_Result;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Infrastructure\WordPress_Assignment_Evaluation_Batch_Receipt;
use OJAI\Dashboard\Submission\Assessment\Infrastructure\WordPress_Assignment_Evaluation_Batch_Source;
use OJAI\Dashboard\Submission\Assessment\Port\Assignment_Evaluation_Batch_Receipt_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Assignment_Evaluation_Batch_Source_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Enqueuer_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Presentation\Assignment_Evaluation_Rest_Controller;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use OJAI\Dashboard\Worksheet\Authoring\Infrastructure\Signed_Cursor_Codec;

require __DIR__ . '/submission-evaluation-enqueue-g4-imp-01-s03.php';

/**
 * G4 s04 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s04_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G4 s04 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s04_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G4 s04 failure.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $reason Reason.
 * @param string           $message Message.
 * @return Assignment_Evaluation_Failure
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s04_failure( callable $callback, string $reason, string $message ): Assignment_Evaluation_Failure {
	try {
		$callback(); } catch ( Assignment_Evaluation_Failure $failure ) {
		g4_s04_same( $reason, $failure->reason(), $message . ' Stable reason mismatch.' );
		return $failure;
		}
		throw new RuntimeException( $message );
}

/**
 * G4 s04 invalid.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s04_invalid( callable $callback, string $message ): void {
	try {
		$callback(); } catch ( InvalidArgumentException ) {
		return;
		}
		throw new RuntimeException( $message );
}

/**
 * G4-IMP-01-S04 C30 assignment submission evaluation enqueue contract.
 */
final class G4_S04_Source_Port implements Assignment_Evaluation_Batch_Source_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Initialize g4 s04 source port dependencies and state.
	 *
	 * @param array<string,mixed> $page Page.
	 */
	public function __construct( public array $page ) {
	}
	/**
	 * Page.
	 *
	 * @param string $assignment_id Distribution public identifier.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @param string $source_cutoff_utc Source cutoff utc.
	 * @param string $item_cursor Item cursor.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array|null
	 */
	public function page(
		string $assignment_id,
		string $context_type,
		string $context_id,
		string $context_revision,
		string $source_cutoff_utc,
		string $item_cursor,
		int $limit
	): ?array {
		++$this->calls;
		if (
			$assignment_id !== $this->page['assignment_id']
			|| $context_type !== $this->page['context_type']
			|| $context_id !== $this->page['context_id']
			|| $context_revision !== $this->page['context_revision']
			|| $source_cutoff_utc !== $this->page['source_cutoff_utc']
			|| '' !== ( $item_cursor ) || $limit < count( $this->page['items'] )
		) {
			return null;
		}
		return $this->page;
	}
}

/**
 * G4-IMP-01-S04 C30 assignment submission evaluation enqueue contract.
 */
final class G4_S04_Submission_Enqueuer implements Submission_Evaluation_Enqueuer_Port_Interface {
	/**
	 * Commands.
	 *
	 * @var array<int,Enqueue_Submission_Evaluation_Command>
	 */
	public array $commands = array();
	/**
	 * Initialize g4 s04 submission enqueuer dependencies and state.
	 *
	 * @param string              $failed_submission_id Failed submission id.
	 * @param string              $existing_submission_id Existing submission id.
	 * @param string              $publication_id Publication id.
	 * @param Public_Id_Generator $ids Ids.
	 */
	public function __construct(
		private string $failed_submission_id,
		private string $existing_submission_id,
		private string $publication_id,
		private Public_Id_Generator $ids
	) {
	}
	/**
	 * Execute g4 s04 submission enqueuer.
	 *
	 * @param Enqueue_Submission_Evaluation_Command $command Command.
	 * @return Enqueue_Submission_Evaluation_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Enqueue_Submission_Evaluation_Command $command ): Enqueue_Submission_Evaluation_Result {
		$this->commands[] = $command;
		if ( $command->submission_id() === $this->failed_submission_id ) {
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::ARTIFACT_NOT_READY );
		}
		return new Enqueue_Submission_Evaluation_Result(
			$this->ids->create()->value(),
			'queued',
			$command->submission_id(),
			$command->expected_official_revision_id(),
			$command->expected_official_revision_hash(),
			$this->publication_id,
			hash( 'sha256', 'g4-s04-publication' ),
			$this->ids->create()->value(),
			1,
			'queued',
			'2026-08-23 01:30:00',
			$command->submission_id() === $this->existing_submission_id
		);
	}
}

/**
 * G4-IMP-01-S04 C30 assignment submission evaluation enqueue contract.
 */
final class G4_S04_Receipt_Port implements Assignment_Evaluation_Batch_Receipt_Port_Interface {
	/**
	 * Rows.
	 *
	 * @var array<string,array{digest:string,result:Enqueue_Assignment_Evaluations_Result}>
	 */
	public array $rows = array();
	/**
	 * Find receipt.
	 *
	 * @param string $storage_key Storage key.
	 * @param string $request_digest Request digest.
	 * @return Enqueue_Assignment_Evaluations_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_receipt( string $storage_key, string $request_digest ): ?Enqueue_Assignment_Evaluations_Result {
		$row = $this->rows[ $storage_key ] ?? null;
		if ( null === $row ) {
			return null;
		}
		if ( ! hash_equals( $row['digest'], $request_digest ) ) {
			throw Assignment_Evaluation_Failure::because( Assignment_Evaluation_Failure::IDEMPOTENCY_CONFLICT );
		}
		return $row['result']->with_replayed( true );
	}
	/**
	 * Store receipt.
	 *
	 * @param array                                 $record Record.
	 * @param Enqueue_Assignment_Evaluations_Result $result Result.
	 * @return Enqueue_Assignment_Evaluations_Result
	 */
	public function store_receipt( array $record, Enqueue_Assignment_Evaluations_Result $result ): Enqueue_Assignment_Evaluations_Result {
		$existing = $this->find_receipt( (string) $record['idempotency_storage_key'], (string) $record['request_digest'] );
		if ( $existing instanceof Enqueue_Assignment_Evaluations_Result ) {
			return $existing;
		}
		$this->rows[ (string) $record['idempotency_storage_key'] ] = array(
			'digest' => (string) $record['request_digest'],
			'result' => $result,
		);
		return $result;
	}
}

/**
 * G4-IMP-01-S04 C30 assignment submission evaluation enqueue contract.
 */
final class G4_S04_Receipt_Database {
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Rows.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $rows = array();
	/**
	 * Transactions.
	 *
	 * @var array<int,string>
	 */
	public array $transactions = array();
	/**
	 * Inject duplicate.
	 *
	 * @var bool
	 */
	public bool $inject_duplicate = false;
	/**
	 * Snapshot.
	 *
	 * @var array<int,array<string,mixed>>|null
	 */
	private ?array $snapshot = null;
	/**
	 * External winner.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $external_winner = null;

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
			$replacement = 'd' === $placeholder[1] ? (string) (int) $value : "'" . str_replace( "'", "''", (string) $value ) . "'";
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
		if ( ! preg_match( "/idempotency_key = '([^']+)'/", $sql, $match ) ) {
			return null;
		}
		foreach ( $this->rows as $row ) {
			if ( $match[1] === ( $row['idempotency_key'] ?? null ) ) {
				return array_intersect_key( $row, array_flip( array( 'aggregate_type', 'aggregate_public_id', 'event_type', 'payload_schema', 'payload_version', 'payload_json', 'payload_hash' ) ) );
			}
		}
		return null;
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
		if ( 'oj_ai_outbox_events' !== $table ) {
			$this->last_error = 'wrong table';
			return false;
		}
		if ( $this->inject_duplicate ) {
			$this->inject_duplicate = false;
			$this->external_winner  = $data;
			$this->last_error       = 'duplicate idempotency key';
			return false;
		}
		foreach ( $this->rows as $row ) {
			if ( $row['idempotency_key'] === $data['idempotency_key'] ) {
				$this->last_error = 'duplicate idempotency key';
				return false;
			}
		}
		$this->rows[] = $data;
		return 1;
	}
	/**
	 * Query.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 */
	public function query( string $sql ) {
		$this->last_error     = '';
		$this->transactions[] = $sql;
		if ( 'START TRANSACTION' === $sql ) {
			$this->snapshot = $this->rows;
			return 1;
		}
		if ( 'COMMIT' === $sql ) {
			$this->snapshot = null;
			return 1;
		}
		if ( 'ROLLBACK' === $sql ) {
			$this->rows = $this->snapshot ?? $this->rows;
			if ( is_array( $this->external_winner ) ) {
				$this->rows[] = $this->external_winner;
			}
			$this->snapshot        = null;
			$this->external_winner = null;
			return 1;
		}
		return 1;
	}
}

/**
 * G4-IMP-01-S04 C30 assignment submission evaluation enqueue contract.
 */
final class G4_S04_Source_Database {
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Last sql.
	 *
	 * @var string
	 */
	public string $last_sql = '';
	/**
	 * Initialize g4 s04 source database dependencies and state.
	 *
	 * @param array<string,mixed>            $assignment Assignment.
	 * @param array<int,array<string,mixed>> $rows Rows.
	 */
	public function __construct( public array $assignment, public array $rows ) {
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
			$replacement = 'd' === $placeholder[1] ? (string) (int) $value : "'" . str_replace( "'", "''", (string) $value ) . "'";
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
		unset( $sql, $format );
		return $this->assignment;
	}
	/**
	 * Get results.
	 *
	 * @param string $sql Sql.
	 * @param string $format Format.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_results( string $sql, string $format ): array {
		unset( $format );
		$this->last_sql = $sql;
		$rows           = $this->rows;
		if ( preg_match( "/t\.public_id > '([^']+)'/", $sql, $match ) ) {
			$rows = array_values( array_filter( $rows, static fn ( array $row ): bool => $row['target_id'] > $match[1] ) );
		}
		$limit = preg_match( '/LIMIT ([0-9]+)$/', $sql, $match ) ? (int) $match[1] : count( $rows );
		return array_slice( $rows, 0, $limit );
	}
}

/**
 * G4-IMP-01-S04 C30 assignment submission evaluation enqueue contract.
 */
final class G4_S04_Policy implements Access_Policy_Port_Interface {
	/**
	 * Last request.
	 *
	 * @var Access_Policy_Request|null
	 */
	public ?Access_Policy_Request $last_request = null;
	/**
	 * Initialize g4 s04 policy dependencies and state.
	 *
	 * @param string $assignment_id Distribution public identifier.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 */
	public function __construct( private string $assignment_id, private string $context_id, private string $context_revision ) {
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
		if (
			! $request->request_integrity_verified()
			|| 42 !== $request->actor_user_id()
			|| Assignment_Evaluation_Rest_Controller::ACTION_ENQUEUE !== $request->action_key()
			|| array( Addon_Capabilities::ASSESS_SUBMISSIONS ) !== $request->required_capabilities()
			|| ! $context instanceof Context_Scope
			|| 'class' !== $context->type() || $this->context_id !== $context->id()
			|| Context_Scope::OPERATION_EVALUATE !== $context->operation()
			|| $this->context_revision !== $context->expected_revision()
			|| ! $object instanceof Object_Scope
			|| Object_Scope::TYPE_ASSIGNMENT !== $object->type()
			|| $this->assignment_id !== $object->id()
			|| 'evaluate_submissions' !== $object->action()
		) {
			return Access_Policy_Decision::denied( 403, 'assignment_evaluation_scope_denied', $request->correlation_id() );
		}
		return Access_Policy_Decision::allowed(
			$request->correlation_id(),
			Context_Authorization_Result::allowed( 'class', $this->context_id, 'active', $this->context_revision, 'educator' ),
			Object_Authorization_Result::allowed( '7', 'open', 'context_staff' )
		);
	}
}

/**
 * G4-IMP-01-S04 C30 assignment submission evaluation enqueue contract.
 */
final class G4_S04_Request {
	/**
	 * Headers.
	 *
	 * @var array<string,mixed>
	 */
	private array $headers = array();
	/**
	 * Initialize g4 s04 request dependencies and state.
	 *
	 * @param array<string,mixed> $headers Headers.
	 * @param array<string,mixed> $json Json.
	 * @param string              $assignment_id Distribution public identifier.
	 * @param array<string,mixed> $query Query.
	 */
	public function __construct( array $headers, private array $json, private string $assignment_id, private array $query ) {
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
		return array( 'assignment_id' => $this->assignment_id );
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
 * G4 s04 body.
 *
 * @param string $command_id Command identifier used for idempotency.
 * @param string $cutoff Cutoff.
 * @param int    $limit Maximum number of entries requested.
 * @return array<string,mixed>
 */
function g4_s04_body( string $command_id, string $cutoff, int $limit = 50 ): array {
	return array(
		'command_id' => $command_id,
		'expected'   => array(
			'expected_schedule_version' => 7,
			'expected_state'            => 'open',
		),
		'payload'    => array(
			'item_cursor'       => '',
			'limit'             => $limit,
			'source_cutoff_utc' => $cutoff,
		),
	);
}

/**
 * G4 s04 command.
 *
 * @param array<string,mixed> $page Page.
 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
 * @param string              $fingerprint Fingerprint.
 * @param string              $command_id Command identifier used for idempotency.
 * @param string|null         $cutoff Cutoff.
 * @param int                 $expected_version Expected version.
 * @param int                 $limit Maximum number of entries requested.
 * @return Enqueue_Assignment_Evaluations_Command
 */
function g4_s04_command(
	array $page,
	Canonical_Json $canonical,
	string $fingerprint,
	string $command_id = 'command-g4-s04-001',
	?string $cutoff = null,
	int $expected_version = 7,
	int $limit = 50
): Enqueue_Assignment_Evaluations_Command {
	return new Enqueue_Assignment_Evaluations_Command(
		42,
		(string) $page['assignment_id'],
		$command_id,
		'open',
		$expected_version,
		$cutoff ?? (string) $page['source_cutoff_utc'],
		'',
		$limit,
		$fingerprint,
		'correlation-g4-s04',
		'class',
		(string) $page['context_id'],
		(string) $page['context_revision'],
		'7',
		'open',
		'context_staff',
		$canonical
	);
}

g4_s04_assert( isset( $canonical ) && $canonical instanceof Canonical_Json, 'S03 canonical fixture is unavailable.' );
$id_generator     = g4_s03_generator( 12000 );
$next_id          = static fn (): string => $id_generator->create()->value();
$assignment_id    = $next_id();
$publication_id   = $next_id();
$cutoff           = '2026-08-23 01:00:00';
$context_id       = 'class-g4-s04';
$context_revision = 'class-g4-s04-r7';
$hash             = static fn ( string $value ): string => hash( 'sha256', $value );

$target_ids     = array();
$submission_ids = array();
$revision_ids   = array();
for ( $index = 0; $index < 9; ++$index ) {
	$target_ids[]     = $next_id();
	$submission_ids[] = $next_id();
	$revision_ids[]   = $next_id();
}
$item                = static function ( int $index, string $target_state, ?string $submission_state, ?string $submitted_at, ?string $revision_id ) use ( $target_ids, $submission_ids, $hash ): array {
	return array(
		'official_revision_hash'  => null === $revision_id ? null : $hash( 'revision-' . $index ),
		'official_revision_id'    => $revision_id,
		'submission_id'           => null === $submission_state ? null : $submission_ids[ $index ],
		'submission_lock_version' => null === $submission_state ? null : 3,
		'submission_state'        => $submission_state,
		'submitted_at_utc'        => $submitted_at,
		'target_id'               => $target_ids[ $index ],
		'target_state'            => $target_state,
	);
};
$items               = array(
	$item( 0, 'active', 'submitted', '2026-08-23 00:10:00', $revision_ids[0] ),
	$item( 1, 'active', 'submitted', '2026-08-23 00:20:00', $revision_ids[1] ),
	$item( 2, 'active', 'locked', '2026-08-23 00:30:00', $revision_ids[2] ),
	$item( 3, 'active', null, null, null ),
	$item( 4, 'revoked', 'submitted', '2026-08-23 00:40:00', $revision_ids[4] ),
	$item( 5, 'active', 'submitted', '2026-08-23 01:10:00', $revision_ids[5] ),
	$item( 6, 'active', 'draft', null, null ),
	$item( 7, 'active', 'submitted', '2026-08-23 00:50:00', null ),
	$item( 8, 'active', 'submitted', null, $revision_ids[8] ),
);
$page                = array(
	'assignment_id'     => $assignment_id,
	'context_id'        => $context_id,
	'context_revision'  => $context_revision,
	'context_type'      => 'class',
	'current_revision'  => '7',
	'items'             => $items,
	'next_cursor'       => '',
	'source_cutoff_utc' => $cutoff,
	'state'             => 'open',
);
$source_port         = new G4_S04_Source_Port( $page );
$submission_enqueuer = new G4_S04_Submission_Enqueuer( $submission_ids[0], $submission_ids[2], $publication_id, $id_generator );
$receipt_port        = new G4_S04_Receipt_Port();
$application         = new Enqueue_Assignment_Evaluations(
	$source_port,
	$submission_enqueuer,
	$receipt_port,
	$id_generator,
	$canonical,
	static fn (): string => '2026-08-23 02:00:00'
);
$command             = g4_s04_command( $page, $canonical, $hash( 'g4-s04-key-1' ) );
$result              = $application->execute( $command );

g4_s04_same(
	array(
		'created'  => 1,
		'existing' => 1,
		'failed'   => 2,
		'skipped'  => 5,
		'total'    => 9,
	),
	$result->counts(),
	'C30 partial-success counts drifted.'
);
g4_s04_same(
	array( 'failed', 'created', 'existing', 'skipped', 'skipped', 'skipped', 'skipped', 'skipped', 'failed' ),
	array_column( $result->items(), 'status' ),
	'C30 item statuses drifted.'
);
g4_s04_same(
	array( Submission_Evaluation_Failure::ARTIFACT_NOT_READY, null, null, 'submission_missing', 'target_inactive', 'outside_source_cutoff', 'submission_not_official', 'official_revision_missing', 'submission_source_invalid' ),
	array_column( $result->items(), 'reason' ),
	'C30 item reasons drifted.'
);
g4_s04_same( 3, count( $submission_enqueuer->commands ), 'C30 must call C29 only for eligible official submissions and continue after one failure.' );
g4_s04_same( $submission_ids[1], $submission_enqueuer->commands[1]->submission_id(), 'C30 stopped after the first item failure.' );
foreach ( $result->items() as $receipt_item ) {
	g4_s04_assert( ! array_key_exists( 'learner_user_id', $receipt_item ) && ! array_key_exists( 'learner_name', $receipt_item ), 'C30 receipt exposed learner identity.' );
}

$source_calls     = $source_port->calls;
$submission_calls = count( $submission_enqueuer->commands );
$exact            = $application->execute( $command );
g4_s04_same( true, $exact->replayed(), 'C30 exact retry must return the durable receipt.' );
g4_s04_same( $result->receipt_digest(), $exact->receipt_digest(), 'C30 exact retry receipt digest drifted.' );
g4_s04_same( $source_calls, $source_port->calls, 'C30 exact retry must not reread assignment targets.' );
g4_s04_same( $submission_calls, count( $submission_enqueuer->commands ), 'C30 exact retry must not rerun C29 items.' );

$conflict = g4_s04_command( $page, $canonical, $hash( 'g4-s04-key-1' ), 'command-g4-s04-changed', null, 7, 49 );
g4_s04_failure( static fn () => $application->execute( $conflict ), Assignment_Evaluation_Failure::IDEMPOTENCY_CONFLICT, 'C30 changed-body retry must conflict.' );
$stale = g4_s04_command( $page, $canonical, $hash( 'g4-s04-key-stale' ), 'command-g4-s04-stale', null, 8 );
g4_s04_failure( static fn () => $application->execute( $stale ), Assignment_Evaluation_Failure::SOURCE_STALE, 'C30 stale schedule version must fail closed.' );
$future = g4_s04_command( $page, $canonical, $hash( 'g4-s04-key-future' ), 'command-g4-s04-future', '2026-08-23 03:00:00' );
g4_s04_failure( static fn () => $application->execute( $future ), Assignment_Evaluation_Failure::CUTOFF_INVALID, 'C30 future cutoff must fail closed.' );

$receipt_database   = new G4_S04_Receipt_Database();
$receipt_repository = new WordPress_Assignment_Evaluation_Batch_Receipt( $canonical, $receipt_database );
$receipt_record     = array(
	'actor_user_id'           => 42,
	'assignment_public_id'    => $assignment_id,
	'command_id'              => 'command-g4-s04-receipt',
	'correlation_id'          => 'correlation-g4-s04-receipt',
	'idempotency_storage_key' => 'command:' . $hash( 'g4-s04-receipt-key' ),
	'outbox_public_id'        => $next_id(),
	'processed_at_utc'        => $result->processed_at_utc(),
	'request_digest'          => $hash( 'g4-s04-receipt-request' ),
);
$stored             = $receipt_repository->store_receipt( $receipt_record, $result );
g4_s04_same( false, $stored->replayed(), 'First C30 receipt store must be original.' );
g4_s04_same( 1, count( $receipt_database->rows ), 'C30 must persist one receipt-only outbox row.' );
g4_s04_same( $result->processed_at_utc(), $receipt_database->rows[0]['published_at_utc'] ?? null, 'C30 receipt-only outbox row must already be published.' );
g4_s04_same( true, $receipt_repository->find_receipt( $receipt_record['idempotency_storage_key'], $receipt_record['request_digest'] )?->replayed(), 'C30 durable receipt replay flag drifted.' );
g4_s04_failure(
	static fn () => $receipt_repository->find_receipt( $receipt_record['idempotency_storage_key'], $hash( 'different-request' ) ),
	Assignment_Evaluation_Failure::IDEMPOTENCY_CONFLICT,
	'C30 durable receipt must reject a changed request.'
);
$valid_payload_hash                        = $receipt_database->rows[0]['payload_hash'];
$receipt_database->rows[0]['payload_hash'] = 'broken';
g4_s04_failure(
	static fn () => $receipt_repository->find_receipt( $receipt_record['idempotency_storage_key'], $receipt_record['request_digest'] ),
	Assignment_Evaluation_Failure::STORED_DATA_INVALID,
	'C30 malformed stored receipt hash must be a storage-data failure.'
);
$receipt_database->rows[0]['payload_hash'] = $valid_payload_hash;

$race_database                          = new G4_S04_Receipt_Database();
$race_database->inject_duplicate        = true;
$race_repository                        = new WordPress_Assignment_Evaluation_Batch_Receipt( $canonical, $race_database );
$race_record                            = $receipt_record;
$race_record['idempotency_storage_key'] = 'command:' . $hash( 'g4-s04-race-key' );
$race_record['outbox_public_id']        = $next_id();
$race_record['request_digest']          = $hash( 'g4-s04-race-request' );
$race_winner                            = $race_repository->store_receipt( $race_record, $result );
g4_s04_same( true, $race_winner->replayed(), 'C30 concurrent receipt loser must recover the durable winner.' );
g4_s04_assert( in_array( 'ROLLBACK', $race_database->transactions, true ), 'C30 concurrent receipt recovery must roll back the losing transaction.' );

$cursor_target_one = $next_id();
$cursor_target_two = $next_id();
$source_database   = new G4_S04_Source_Database(
	array(
		'assignment_pk'    => 71,
		'assignment_id'    => $assignment_id,
		'assignment_state' => 'open',
		'schedule_version' => 7,
		'context_type'     => 'class',
		'context_id'       => $context_id,
		'context_revision' => $context_revision,
	),
	array(
		array(
			'target_id'                   => $cursor_target_one,
			'has_evaluation'              => '0',
			'target_state'                => 'active',
			'submission_id'               => null,
			'submission_lock_version'     => null,
			'submission_state'            => null,
			'official_revision_public_id' => null,
			'official_revision_hash'      => null,
			'submitted_at_utc'            => null,
		),
		array(
			'target_id'                   => $cursor_target_two,
			'has_evaluation'              => '0',
			'target_state'                => 'revoked',
			'submission_id'               => null,
			'submission_lock_version'     => null,
			'submission_state'            => null,
			'official_revision_public_id' => null,
			'official_revision_hash'      => null,
			'submitted_at_utc'            => null,
		),
	)
);
$cursor_codec      = new Signed_Cursor_Codec( $canonical, 'g4-s04-cursor-secret-at-least-16' );
$batch_source      = new WordPress_Assignment_Evaluation_Batch_Source( $cursor_codec, $source_database );
$cursor_page_one   = $batch_source->page( $assignment_id, 'class', $context_id, $context_revision, $cutoff, '', 1 );
g4_s04_assert( is_array( $cursor_page_one ), 'C30 source first page is unavailable.' );
$next_cursor = (string) $cursor_page_one['next_cursor'];
g4_s04_assert( '' !== $next_cursor && strlen( $next_cursor ) <= Enqueue_Assignment_Evaluations_Command::MAXIMUM_CURSOR, 'C30 cursor must fit the frozen 191-byte field.' );
$cursor_payload = $cursor_codec->decode( $next_cursor, 'ae_batch' );
$cursor_keys    = array_keys( $cursor_payload );
sort( $cursor_keys, SORT_STRING );
g4_s04_same( array( 'i', 's', 'v' ), $cursor_keys, 'C30 cursor payload must remain compact and scope-bound.' );
$cursor_page_two = $batch_source->page( $assignment_id, 'class', $context_id, $context_revision, $cutoff, $next_cursor, 1 );
g4_s04_same( $cursor_target_two, $cursor_page_two['items'][0]['target_id'] ?? null, 'C30 cursor did not advance by target ID.' );
g4_s04_assert( str_contains( $source_database->last_sql, 'oj_ai_assignment_targets' ) && str_contains( $source_database->last_sql, 'oj_ai_submissions' ) && str_contains( $source_database->last_sql, 'oj_ai_submission_revisions' ), 'C30 source lost literal add-on tables.' );
g4_s04_invalid(
	static fn () => $batch_source->page( $assignment_id, 'class', $context_id, $context_revision, '2026-08-23 00:59:59', $next_cursor, 1 ),
	'C30 cursor must be bound to the cutoff.'
);
$source_database->assignment['schedule_version'] = 8;
g4_s04_invalid(
	static fn () => $batch_source->page( $assignment_id, 'class', $context_id, $context_revision, $cutoff, $next_cursor, 1 ),
	'C30 cursor must be bound to schedule version.'
);
$source_database->assignment['schedule_version'] = 7;
$other_assignment_id                             = $next_id();
$source_database->assignment['assignment_id']    = $other_assignment_id;
g4_s04_invalid(
	static fn () => $batch_source->page( $other_assignment_id, 'class', $context_id, $context_revision, $cutoff, $next_cursor, 1 ),
	'C30 cursor must be bound to assignment.'
);

$GLOBALS['g4_s03_routes'] = array();
$rest_source              = new G4_S04_Source_Port( $page );
$rest_enqueuer            = new G4_S04_Submission_Enqueuer( $submission_ids[0], $submission_ids[2], $publication_id, $id_generator );
$rest_application         = new Enqueue_Assignment_Evaluations(
	$rest_source,
	$rest_enqueuer,
	new G4_S04_Receipt_Port(),
	$id_generator,
	$canonical,
	static fn (): string => '2026-08-23 02:00:00'
);
$policy                   = new G4_S04_Policy( $assignment_id, $context_id, $context_revision );
$correlations             = new Correlation_Id_Factory();
$envelopes                = new Rest_Envelope_Factory();
$permissions              = new Rest_Permission_Handoff(
	new WordPress_Rest_Request_Adapter( new WordPress_Current_Actor() ),
	new Rest_Request_Authorizer( $policy, new WordPress_Rest_Nonce_Verifier(), $correlations ),
	$envelopes,
	$correlations
);
$controller               = new Assignment_Evaluation_Rest_Controller( $permissions, $rest_application, $canonical, $envelopes, $correlations );
$controller->register_routes();
g4_s04_same( 1, count( $GLOBALS['g4_s03_routes'] ), 'C30 route must register exactly once.' );
g4_s04_same( Assignment_Evaluation_Rest_Controller::ROUTE_EVALUATE, $GLOBALS['g4_s03_routes'][0][1], 'C30 route path drifted.' );
g4_s04_same( 'POST', $GLOBALS['g4_s03_routes'][0][2]['methods'] ?? null, 'C30 route method drifted.' );
$rest_request = new G4_S04_Request(
	array(
		'X-WP-Nonce'      => 'valid-g4-s03-nonce',
		'Idempotency-Key' => 'rest-g4-s04-key-001',
	),
	g4_s04_body( 'command-g4-s04-rest', $cutoff ),
	$assignment_id,
	array(
		'context_type'     => 'class',
		'context_id'       => $context_id,
		'context_revision' => $context_revision,
	)
);
g4_s04_same( true, $controller->permissions_evaluate( $rest_request ), 'C30 permission callback must enforce nonce, capability, Context, and assignment scope.' );
$rest_response = $controller->evaluate( $rest_request );
g4_s04_assert( $rest_response instanceof WP_REST_Response, 'C30 success must use the common REST envelope.' );
g4_s04_same( 202, $rest_response->get_status(), 'C30 success status drifted.' );
$rest_data = $rest_response->get_data();
g4_s04_same( $assignment_id, $rest_data['data']['assignment_id'] ?? null, 'C30 REST assignment drifted.' );
g4_s04_same( Enqueue_Assignment_Evaluations_Result::RECEIPT_SCHEMA, $rest_data['data']['receipt']['schema_id'] ?? null, 'C30 REST receipt schema drifted.' );
g4_s04_same( 9, $rest_data['meta']['counts']['total'] ?? null, 'C30 REST partial-success count drifted.' );
g4_s04_assert( $policy->last_request instanceof Access_Policy_Request, 'C30 REST did not execute the access policy.' );

$bad_body                          = g4_s04_body( 'command-g4-s04-bad', $cutoff );
$bad_body['payload']['unexpected'] = true;
$bad_request                       = new G4_S04_Request(
	array(
		'X-WP-Nonce'      => 'valid-g4-s03-nonce',
		'Idempotency-Key' => 'rest-g4-s04-key-002',
	),
	$bad_body,
	$assignment_id,
	array(
		'context_type'     => 'class',
		'context_id'       => $context_id,
		'context_revision' => $context_revision,
	)
);
g4_s04_same( true, $controller->permissions_evaluate( $bad_request ), 'C30 permission callback must not parse command body.' );
$bad_response = $controller->evaluate( $bad_request );
g4_s04_assert( $bad_response instanceof WP_Error, 'C30 unknown body fields must return a problem.' );
g4_s04_same( 422, $bad_response->get_error_data()['status'] ?? null, 'C30 unknown body field status drifted.' );

$bad_query_request = new G4_S04_Request(
	array(
		'X-WP-Nonce'      => 'valid-g4-s03-nonce',
		'Idempotency-Key' => 'rest-g4-s04-key-003',
	),
	g4_s04_body( 'command-g4-s04-bad-query', $cutoff ),
	$assignment_id,
	array(
		'context_type'     => 'class',
		'context_id'       => $context_id,
		'context_revision' => $context_revision,
		'extra'            => 'no',
	)
);
$bad_query_problem = $controller->permissions_evaluate( $bad_query_request );
g4_s04_assert( $bad_query_problem instanceof WP_Error, 'C30 unknown Context query fields must fail closed.' );
g4_s04_same( 422, $bad_query_problem->get_error_data()['status'] ?? null, 'C30 unknown Context query status drifted.' );

$addon_root     = dirname( __DIR__, 2 );
$runtime_files  = array(
	$addon_root . '/src/Submission/Assessment/Application/Enqueue_Assignment_Evaluations.php',
	$addon_root . '/src/Submission/Assessment/Infrastructure/WordPress_Assignment_Evaluation_Batch_Source.php',
	$addon_root . '/src/Submission/Assessment/Infrastructure/WordPress_Assignment_Evaluation_Batch_Receipt.php',
	$addon_root . '/src/Submission/Assessment/Presentation/Assignment_Evaluation_Rest_Controller.php',
	$addon_root . '/src/Submission/Assignment/Infrastructure/Assignment_Object_Authorization.php',
	$addon_root . '/src/Core/Composition_Root.php',
);
$joined_runtime = '';
foreach ( $runtime_files as $file ) {
	$source_text = file_get_contents( $file );
	g4_s04_assert( is_string( $source_text ), 'C30 runtime file is unreadable: ' . $file );
	$joined_runtime .= "\n" . $source_text;
	foreach ( array( '$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\\OjPlatform\\Kernel\\' ) as $forbidden ) {
		g4_s04_assert( ! str_contains( $source_text, $forbidden ), 'C30 runtime crossed an add-on boundary: ' . $forbidden );
	}
}
foreach ( array(
	"'/assignments/(?P<assignment_id>[0-9A-HJKMNP-TV-Z]{26})/commands/evaluate-submissions'",
	'Addon_Capabilities::ASSESS_SUBMISSIONS',
	'Context_Scope::OPERATION_EVALUATE',
	"'evaluate_submissions'",
	"'oj_ai_assignments'",
	"'oj_ai_assignment_targets'",
	"'oj_ai_submissions'",
	"'oj_ai_submission_revisions'",
	"'oj_ai_outbox_events'",
	"'assignment_evaluations.enqueue_receipt'",
	"'published_at_utc'",
	'Submission_Evaluation_Enqueuer_Port_Interface',
) as $marker ) {
	g4_s04_assert( str_contains( $joined_runtime, $marker ), 'C30 runtime marker is missing: ' . $marker );
}
g4_s04_assert( ! str_contains( $joined_runtime, 'Platform_Ai_Gateway_Client' ), 'C30 must not execute the AI Gateway synchronously.' );

echo "G4-IMP-01-S04 passed: C30 partial success, C29 reuse, durable exact replay, compact scoped cursor, race recovery, and strict REST authorization.\n";
