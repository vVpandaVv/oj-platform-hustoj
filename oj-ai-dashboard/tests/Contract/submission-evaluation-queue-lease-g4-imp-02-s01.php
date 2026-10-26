<?php
/**
 * G4-IMP-02-S01 student submission evaluation queue and lease contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/../../' );
define( 'ARRAY_A', 'ARRAY_A' );

require_once ABSPATH . 'src/Worksheet/Authoring/Domain/Public_Id.php';
require_once ABSPATH . 'src/Worksheet/Authoring/Domain/Public_Id_Generator.php';
require_once ABSPATH . 'src/Worksheet/Authoring/Domain/Canonical_Json.php';
require_once ABSPATH . 'src/Security/Addon_Capabilities.php';
require_once ABSPATH . 'src/Platform/Addon_Manifest.php';
require_once ABSPATH . 'src/Submission/Assessment/Domain/Submission_Score_Input_Builder.php';
require_once ABSPATH . 'src/Submission/Assessment/Domain/Submission_Evaluation_Routing_Snapshot.php';
require_once ABSPATH . 'src/Submission/Assessment/Domain/Submission_Evaluation_Work_Item.php';
require_once ABSPATH . 'src/Submission/Assessment/Port/Submission_Evaluation_Work_Repository_Port_Interface.php';
require_once ABSPATH . 'src/Submission/Assessment/Application/Submission_Evaluation_Worker_Failure.php';
require_once ABSPATH . 'src/Submission/Assessment/Application/Claim_Next_Submission_Evaluation.php';
require_once ABSPATH . 'src/Submission/Assessment/Application/Start_Submission_Evaluation_Attempt.php';
require_once ABSPATH . 'src/Submission/Assessment/Infrastructure/WordPress_Submission_Evaluation_Work_Repository.php';

use OJAI\Dashboard\Platform\Addon_Manifest;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Submission\Assessment\Application\Claim_Next_Submission_Evaluation;
use OJAI\Dashboard\Submission\Assessment\Application\Start_Submission_Evaluation_Attempt;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Worker_Failure;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Score_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Infrastructure\WordPress_Submission_Evaluation_Work_Repository;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

/**
 * G4 s01 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s01_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G4 s01 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s01_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G4 s01 id.
 *
 * @return string
 */
function g4_s01_id(): string {
	static $counter = 21000;
	++$counter;
	$id = '01K' . str_pad( (string) $counter, 23, '0', STR_PAD_LEFT );
	g4_s01_assert( Public_Id::is_valid( $id ), 'Fixture ID must satisfy PublicIdV1.' );
	return $id;
}

/**
 * G4 s01 ids.
 *
 * @param int $seed Seed.
 * @return Public_Id_Generator
 */
function g4_s01_ids( int $seed ): Public_Id_Generator {
	$counter = $seed;
	return new Public_Id_Generator(
		static function () use ( &$counter ): string {
			++$counter;
			return pack( 'N4', 0, 0, 0, $counter );
		}
	);
}

/**
 * Small transactional WordPress DB double that executes the repository's lease SQL.
 */
final class G4_S01_Database {
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
	 * Tables.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public array $tables;
	/**
	 * Snapshot.
	 *
	 * @var array<string,array<int,array<string,mixed>>>|null
	 */
	private ?array $snapshot = null;
	/**
	 * Next attempt id.
	 *
	 * @var int
	 */
	private int $next_attempt_id = 1;
	/**
	 * Snapshot next attempt id.
	 *
	 * @var int|null
	 */
	private ?int $snapshot_next_attempt_id = null;

	/**
	 * Initialize g4 s01 database dependencies and state.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $tables Tables.
	 */
	public function __construct( array $tables ) {
		$this->tables = $tables;
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
		$this->last_error = '';
		if ( str_contains( $sql, 'AS run_pk' ) ) {
			if ( 1 !== preg_match( "/r\.available_at_utc <= '([^']+)'/", $sql, $matches ) ) {
				$this->last_error = 'missing due time';
				return null;
			}
			$now = $matches[1];
			foreach ( $this->tables['oj_ai_evaluation_runs'] as $run ) {
				if ( 'submission_evaluation' !== ( $run['subject_type'] ?? null ) ) {
					continue;
				}
				$evaluation = $this->evaluation_by_public_id( (string) $run['evaluation_public_id'] );
				if ( null === $evaluation || ! in_array( $evaluation['state'], array( 'queued', 'running' ), true ) ) {
					continue;
				}
				$due     = in_array( $run['state'], array( 'queued', 'retry_wait' ), true ) && $run['available_at_utc'] <= $now;
				$expired = in_array( $run['state'], array( 'leased', 'running' ), true )
					&& is_string( $run['lease_expires_at_utc'] ) && $run['lease_expires_at_utc'] <= $now;
				if ( ! $due && ! $expired ) {
					continue;
				}
				$input = $this->input_by_evaluation_id( (int) $evaluation['id'] );
				if ( null === $input ) {
					continue;
				}
				$count = 0;
				foreach ( $this->tables['oj_ai_evaluation_attempts'] as $attempt ) {
					if ( (int) $attempt['evaluation_run_id'] === (int) $run['id'] ) {
						++$count;
					}
				}
				return array(
					'run_pk'                          => $run['id'],
					'run_public_id'                   => $run['public_id'],
					'run_lock_version'                => $run['lock_version'],
					'run_state'                       => $run['state'],
					'task_key'                        => $run['task_key'],
					'contract_version'                => $run['contract_version'],
					'operation_key'                   => $run['operation_key'],
					'input_schema_version'            => $run['input_schema_version'],
					'output_schema_version'           => $run['output_schema_version'],
					'input_digest'                    => $run['input_digest'],
					'run_idempotency_key'             => $run['idempotency_key'],
					'provider_key'                    => $run['provider_key'],
					'model_key'                       => $run['model_key'],
					'profile_fingerprint'             => $run['profile_fingerprint'],
					'routing_revision'                => $run['routing_revision'],
					'queued_at_utc'                   => $run['queued_at_utc'],
					'lease_expires_at_utc'            => $run['lease_expires_at_utc'],
					'evaluation_pk'                   => $evaluation['id'],
					'evaluation_public_id'            => $evaluation['public_id'],
					'created_by_user_id'              => $evaluation['created_by_user_id'],
					'evaluation_state'                => $evaluation['state'],
					'submission_revision_public_id'   => $evaluation['submission_revision_public_id'],
					'submission_revision_hash'        => $evaluation['submission_revision_hash'],
					'worksheet_publication_public_id' => $evaluation['worksheet_publication_public_id'],
					'publication_hash'                => $evaluation['publication_hash'],
					'authorization_envelope_json'     => $evaluation['authorization_envelope_json'],
					'authorization_envelope_hash'     => $evaluation['authorization_envelope_hash'],
					'input_json'                      => $input['input_json'],
					'input_hash'                      => $input['input_hash'],
					'input_byte_size'                 => $input['input_byte_size'],
					'attempt_count'                   => $count,
				);
			}
			return null;
		}

		if ( str_contains( $sql, 'SELECT r.id, r.state, r.lock_version' ) ) {
			if ( 1 !== preg_match( '/WHERE r\.id = ([0-9]+)/', $sql, $matches ) ) {
				return null;
			}
			$run = $this->run_by_id( (int) $matches[1] );
			if ( null === $run ) {
				return null;
			}
			$evaluation = $this->evaluation_by_public_id( (string) $run['evaluation_public_id'] );
			return array(
				'id'               => $run['id'],
				'state'            => $run['state'],
				'lock_version'     => $run['lock_version'],
				'lease_token'      => $run['lease_token'],
				'result_public_id' => $run['result_public_id'],
				'evaluation_state' => $evaluation['state'] ?? null,
			);
		}
		$this->last_error = 'unknown select';
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
		if ( 'oj_ai_evaluation_attempts' !== $table ) {
			$this->last_error = 'unknown insert table';
			return false;
		}
		foreach ( $this->tables[ $table ] as $row ) {
			if ( $row['public_id'] === $data['public_id']
				|| ( (int) $row['evaluation_run_id'] === (int) $data['evaluation_run_id'] && (int) $row['attempt_no'] === (int) $data['attempt_no'] ) ) {
				$this->last_error = 'duplicate attempt';
				return false;
			}
		}
		$data['id']               = $this->next_attempt_id++;
		$this->insert_id          = $data['id'];
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
		$this->last_error = '';
		if ( 'START TRANSACTION' === $sql ) {
			if ( null !== $this->snapshot ) {
				$this->last_error = 'nested transaction';
				return false;
			}
			$this->snapshot                 = unserialize( serialize( $this->tables ) );
			$this->snapshot_next_attempt_id = $this->next_attempt_id;
			return 1;
		}
		if ( 'COMMIT' === $sql ) {
			if ( null === $this->snapshot ) {
				$this->last_error = 'missing transaction';
				return false;
			}
			$this->snapshot                 = null;
			$this->snapshot_next_attempt_id = null;
			return 1;
		}
		if ( 'ROLLBACK' === $sql ) {
			if ( null !== $this->snapshot ) {
				$this->tables = $this->snapshot;
			}
			if ( null !== $this->snapshot_next_attempt_id ) {
				$this->next_attempt_id = $this->snapshot_next_attempt_id;
			}
			$this->snapshot                 = null;
			$this->snapshot_next_attempt_id = null;
			return 1;
		}

		if ( str_starts_with( $sql, 'UPDATE oj_ai_evaluation_attempts SET state' ) ) {
			if ( 1 !== preg_match( "/SET state = '([^']+)', error_code = '([^']+)', finished_at_utc = '([^']+)' WHERE evaluation_run_id = ([0-9]+) AND state = '([^']+)'/", $sql, $matches ) ) {
				return $this->query_error( 'invalid attempt expiry update' );
			}
			$updated = 0;
			foreach ( $this->tables['oj_ai_evaluation_attempts'] as &$attempt ) {
				if ( (int) $attempt['evaluation_run_id'] === (int) $matches[4] && $attempt['state'] === $matches[5] ) {
					$attempt['state']           = $matches[1];
					$attempt['error_code']      = $matches[2];
					$attempt['finished_at_utc'] = $matches[3];
					++$updated;
				}
			}
			unset( $attempt );
			return $updated;
		}

		if ( str_starts_with( $sql, "UPDATE oj_ai_evaluation_runs SET state = 'leased'" ) ) {
			if ( 1 !== preg_match( "/SET state = 'leased', lease_token = '([^']+)', lease_expires_at_utc = '([^']+)', lock_version = lock_version \+ 1, updated_at_utc = '([^']+)' WHERE id = ([0-9]+) AND lock_version = ([0-9]+) AND state = '([^']+)'/", $sql, $matches ) ) {
				return $this->query_error( 'invalid lease update' );
			}
			foreach ( $this->tables['oj_ai_evaluation_runs'] as &$run ) {
				if ( (int) $run['id'] === (int) $matches[4] && (int) $run['lock_version'] === (int) $matches[5] && $run['state'] === $matches[6] ) {
					$run['state']                = 'leased';
					$run['lease_token']          = $matches[1];
					$run['lease_expires_at_utc'] = $matches[2];
					$run['updated_at_utc']       = $matches[3];
					++$run['lock_version'];
					unset( $run );
					return 1;
				}
			}
			unset( $run );
			return 0;
		}

		if ( str_starts_with( $sql, "UPDATE oj_ai_evaluation_runs SET state = 'running'" ) ) {
			if ( 1 !== preg_match( "/lease_expires_at_utc = '([^']+)', started_at_utc = COALESCE\(started_at_utc, '([^']+)'\), updated_at_utc = '([^']+)' WHERE id = ([0-9]+) AND lock_version = ([0-9]+) AND state = 'leased' AND lease_token = '([^']+)'/", $sql, $matches ) ) {
				return $this->query_error( 'invalid start update' );
			}
			foreach ( $this->tables['oj_ai_evaluation_runs'] as &$run ) {
				if ( (int) $run['id'] === (int) $matches[4] && (int) $run['lock_version'] === (int) $matches[5] && 'leased' === $run['state'] && $run['lease_token'] === $matches[6] ) {
					$run['state']                = 'running';
					$run['lease_expires_at_utc'] = $matches[1];
					$run['started_at_utc']       = $run['started_at_utc'] ?? $matches[2];
					$run['updated_at_utc']       = $matches[3];
					++$run['lock_version'];
					unset( $run );
					return 1;
				}
			}
			unset( $run );
			return 0;
		}

		if ( str_starts_with( $sql, "UPDATE oj_ai_submission_evaluations SET state = 'running'" ) ) {
			if ( 1 !== preg_match( "/updated_by_user_id = ([0-9]+), updated_at_utc = '([^']+)' WHERE id = ([0-9]+) AND state IN \('queued','running'\)/", $sql, $matches ) ) {
				return $this->query_error( 'invalid evaluation state update' );
			}
			foreach ( $this->tables['oj_ai_submission_evaluations'] as &$evaluation ) {
				if ( (int) $evaluation['id'] === (int) $matches[3] && in_array( $evaluation['state'], array( 'queued', 'running' ), true ) ) {
					$evaluation['state']              = 'running';
					$evaluation['updated_by_user_id'] = (int) $matches[1];
					$evaluation['updated_at_utc']     = $matches[2];
					++$evaluation['lock_version'];
					unset( $evaluation );
					return 1;
				}
			}
			unset( $evaluation );
			return 0;
		}

		return $this->query_error( 'unknown query' );
	}

	/**
	 * Evaluation by public id.
	 *
	 * @param string $public_id Public id.
	 * @return array<string,mixed>|null
	 */
	private function evaluation_by_public_id( string $public_id ): ?array {
		foreach ( $this->tables['oj_ai_submission_evaluations'] as $row ) {
			if ( $row['public_id'] === $public_id ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Input by evaluation id.
	 *
	 * @param int $evaluation_id Evaluation id.
	 * @return array<string,mixed>|null
	 */
	private function input_by_evaluation_id( int $evaluation_id ): ?array {
		foreach ( $this->tables['oj_ai_submission_evaluation_inputs'] as $row ) {
			if ( (int) $row['submission_evaluation_id'] === $evaluation_id ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Run by id.
	 *
	 * @param int $id Id.
	 * @return array<string,mixed>|null
	 */
	private function run_by_id( int $id ): ?array {
		foreach ( $this->tables['oj_ai_evaluation_runs'] as $row ) {
			if ( (int) $row['id'] === $id ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Query error.
	 *
	 * @param string $message Message.
	 * @return mixed
	 */
	private function query_error( string $message ) {
		$this->last_error = $message;
		return false;
	}
}

/**
 * G4 s01 tables.
 *
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @return array<string,array<int,array<string,mixed>>>
 */
function g4_s01_tables( Canonical_Json $canonical ): array {
	$evaluation_id      = g4_s01_id();
	$run_id             = g4_s01_id();
	$submission_id      = g4_s01_id();
	$revision_id        = g4_s01_id();
	$publication_id     = g4_s01_id();
	$source_hash        = hash( 'sha256', 'g4-s01-source' );
	$publication_hash   = hash( 'sha256', 'g4-s01-publication' );
	$payload            = array(
		'operation'       => Submission_Score_Input_Builder::OPERATION,
		'output_contract' => array(
			'schema_id'      => Submission_Score_Input_Builder::OUTPUT_SCHEMA,
			'schema_version' => Submission_Score_Input_Builder::OUTPUT_VERSION,
		),
		'subject'         => array(
			'publication_hash' => $publication_hash,
			'source_hash'      => $source_hash,
		),
	);
	$input              = array(
		'operation'          => Submission_Score_Input_Builder::OPERATION,
		'operation_contract' => Submission_Score_Input_Builder::OPERATION_CONTRACT,
		'operation_payload'  => $payload,
		'operation_schema'   => Submission_Score_Input_Builder::OPERATION_SCHEMA,
		'payload_hash'       => hash( 'sha256', $canonical->encode( $payload ) ),
	);
	$input_json         = $canonical->encode( $input );
	$authorization      = array(
		'action_key'            => 'submission.evaluation.enqueue',
		'actor_user_id'         => 42,
		'checked_at_utc'        => '2026-08-23 00:00:00',
		'context'               => array(
			'id'        => 'class/g4-s01',
			'operation' => 'evaluate',
			'revision'  => 'context-revision-7',
			'type'      => 'class',
		),
		'object'                => array(
			'action'                 => 'evaluate',
			'actor_relation'         => 'context_staff',
			'authorized_revision'    => '3',
			'authorized_state'       => 'submitted',
			'id'                     => $submission_id,
			'official_revision_hash' => $source_hash,
			'official_revision_id'   => $revision_id,
			'type'                   => 'submission',
		),
		'required_capabilities' => array( Addon_Capabilities::ASSESS_SUBMISSIONS ),
		'schema_id'             => 'oj_ai.authorization_envelope',
		'schema_version'        => '1.0.0',
		'shell'                 => 'console',
		'surface'               => 'rest',
	);
	$authorization_json = $canonical->encode( $authorization );
	return array(
		'oj_ai_submission_evaluations'       => array(
			array(
				'id'                              => 1,
				'public_id'                       => $evaluation_id,
				'lock_version'                    => 0,
				'created_by_user_id'              => 42,
				'state'                           => 'queued',
				'submission_revision_public_id'   => $revision_id,
				'submission_revision_hash'        => $source_hash,
				'worksheet_publication_public_id' => $publication_id,
				'publication_hash'                => $publication_hash,
				'authorization_envelope_json'     => $authorization_json,
				'authorization_envelope_hash'     => hash( 'sha256', $authorization_json ),
			),
		),
		'oj_ai_submission_evaluation_inputs' => array(
			array(
				'id'                       => 1,
				'submission_evaluation_id' => 1,
				'input_json'               => $input_json,
				'input_hash'               => hash( 'sha256', $input_json ),
				'input_byte_size'          => strlen( $input_json ),
			),
		),
		'oj_ai_evaluation_runs'              => array(
			array(
				'id'                    => 1,
				'public_id'             => $run_id,
				'lock_version'          => 0,
				'subject_type'          => 'submission_evaluation',
				'evaluation_public_id'  => $evaluation_id,
				'state'                 => 'queued',
				'task_key'              => Addon_Manifest::TASK_STUDENT_ASSESSMENT,
				'contract_version'      => Submission_Score_Input_Builder::OUTER_INPUT_CONTRACT,
				'operation_key'         => Submission_Score_Input_Builder::OPERATION,
				'input_schema_version'  => Submission_Score_Input_Builder::REQUEST_VERSION,
				'output_schema_version' => Submission_Score_Input_Builder::OUTPUT_VERSION,
				'input_digest'          => hash( 'sha256', $input_json ),
				'idempotency_key'       => 'run:' . hash( 'sha256', 'g4-s01-run' ),
				'provider_key'          => 'provider-g4-s01',
				'model_key'             => 'model-g4-s01',
				'profile_fingerprint'   => hash( 'sha256', 'g4-s01-profile' ),
				'routing_revision'      => 'routing-g4-s01-r1',
				'available_at_utc'      => '2026-08-23 00:00:00',
				'queued_at_utc'         => '2026-08-23 00:00:00',
				'lease_token'           => null,
				'lease_expires_at_utc'  => null,
				'started_at_utc'        => null,
				'result_public_id'      => null,
			),
		),
		'oj_ai_evaluation_attempts'          => array(),
	);
}

$canonical  = new Canonical_Json();
$database   = new G4_S01_Database( g4_s01_tables( $canonical ) );
$repository = new WordPress_Submission_Evaluation_Work_Repository( $canonical, $database );
$token_a    = '11111111-1111-4111-8111-111111111111';
$token_b    = '22222222-2222-4222-8222-222222222222';
$token_c    = '33333333-3333-4333-8333-333333333333';

$first_claim = new Claim_Next_Submission_Evaluation(
	$repository,
	static fn (): string => '2026-08-23 00:00:00',
	static fn (): string => $token_a
);
$first_lease = $first_claim->execute();
g4_s01_assert( null !== $first_lease, 'The first due worker must acquire the run.' );
g4_s01_same( 'leased', $first_lease->state(), 'Claim must return a leased work item.' );
g4_s01_same( $token_a, $first_lease->lease_token(), 'Claim must preserve the new lease token.' );
g4_s01_same( 1, $first_lease->lock_version(), 'Claim must advance run lock version once.' );
g4_s01_same( '2026-08-23 00:05:00', $database->tables['oj_ai_evaluation_runs'][0]['lease_expires_at_utc'], 'Claim lease must last exactly 300 seconds.' );

$competing_claim = new Claim_Next_Submission_Evaluation(
	$repository,
	static fn (): string => '2026-08-23 00:00:00',
	static fn (): string => $token_b
);
g4_s01_same( null, $competing_claim->execute(), 'A second worker must not acquire an unexpired lease.' );

$start_first   = new Start_Submission_Evaluation_Attempt(
	$repository,
	g4_s01_ids( 22000 ),
	static fn (): string => '2026-08-23 00:00:10'
);
$first_running = $start_first->execute( $first_lease );
g4_s01_assert( null !== $first_running, 'The lease owner must start the first append-only attempt.' );
g4_s01_same( 'running', $first_running->state(), 'Start must move the run to running.' );
g4_s01_same( 1, $first_running->attempt_no(), 'The first started attempt number must be one.' );
g4_s01_same( 2, $first_running->lock_version(), 'Starting must advance run lock version again.' );
g4_s01_same( '2026-08-23 00:05:10', $database->tables['oj_ai_evaluation_runs'][0]['lease_expires_at_utc'], 'Starting must renew the lease for 300 seconds.' );
g4_s01_same( 'running', $database->tables['oj_ai_submission_evaluations'][0]['state'], 'Starting must move the evaluation root to running.' );
g4_s01_same( 1, count( $database->tables['oj_ai_evaluation_attempts'] ), 'Starting must append exactly one attempt.' );

$early_reclaim = new Claim_Next_Submission_Evaluation(
	$repository,
	static fn (): string => '2026-08-23 00:05:09',
	static fn (): string => $token_b
);
g4_s01_same( null, $early_reclaim->execute(), 'A running lease must remain exclusive until its exact expiry.' );

$expired_reclaim = new Claim_Next_Submission_Evaluation(
	$repository,
	static fn (): string => '2026-08-23 00:05:10',
	static fn (): string => $token_c
);
$reclaimed       = $expired_reclaim->execute();
g4_s01_assert( null !== $reclaimed, 'A worker must reclaim the run at the exact lease expiry.' );
g4_s01_same( $token_c, $reclaimed->lease_token(), 'Reclaim must replace the prior lease token.' );
g4_s01_same( 3, $reclaimed->lock_version(), 'Reclaim must advance the lock version.' );
g4_s01_same( 1, $reclaimed->attempt_count(), 'Reclaim must preserve the historical attempt count.' );
g4_s01_same( 'gateway_failed', $database->tables['oj_ai_evaluation_attempts'][0]['state'], 'Reclaim must close the abandoned started attempt.' );
g4_s01_same( 'worker_lost', $database->tables['oj_ai_evaluation_attempts'][0]['error_code'], 'Reclaim must use the stable worker_lost reason.' );

$late_start = new Start_Submission_Evaluation_Attempt(
	$repository,
	g4_s01_ids( 23000 ),
	static fn (): string => '2026-08-23 00:05:11'
);
g4_s01_same( null, $late_start->execute( $first_lease ), 'The old lease token must not start an attempt after reclaim.' );
g4_s01_same( 1, count( $database->tables['oj_ai_evaluation_attempts'] ), 'A stale worker must not append an attempt.' );

$start_reclaimed = new Start_Submission_Evaluation_Attempt(
	$repository,
	g4_s01_ids( 24000 ),
	static fn (): string => '2026-08-23 00:05:11'
);
$second_running  = $start_reclaimed->execute( $reclaimed );
g4_s01_assert( null !== $second_running, 'The reclaim owner must start the next attempt.' );
g4_s01_same( 2, $second_running->attempt_no(), 'Reclaim must append attempt two instead of overwriting attempt one.' );
g4_s01_same( 4, $second_running->lock_version(), 'The second start must advance the current lock version.' );
g4_s01_same( 2, count( $database->tables['oj_ai_evaluation_attempts'] ), 'Attempt history must remain append-only.' );
g4_s01_same( array( 1, 2 ), array_column( $database->tables['oj_ai_evaluation_attempts'], 'attempt_no' ), 'Attempt numbers must remain strictly increasing.' );

$corrupt_tables = g4_s01_tables( $canonical );
$corrupt_tables['oj_ai_submission_evaluation_inputs'][0]['input_json'] .= ' ';
$corrupt_database   = new G4_S01_Database( $corrupt_tables );
$corrupt_repository = new WordPress_Submission_Evaluation_Work_Repository( $canonical, $corrupt_database );
$corrupt_claim      = new Claim_Next_Submission_Evaluation(
	$corrupt_repository,
	static fn (): string => '2026-08-23 00:00:00',
	static fn (): string => $token_a
);
$invalid_reason     = null;
try {
	$corrupt_claim->execute();
} catch ( Submission_Evaluation_Worker_Failure $failure ) {
	$invalid_reason = $failure->reason();
}
g4_s01_same( Submission_Evaluation_Worker_Failure::INVALID_WORK_ITEM, $invalid_reason, 'Corrupt canonical input must fail closed.' );
g4_s01_same( 'queued', $corrupt_database->tables['oj_ai_evaluation_runs'][0]['state'], 'Invalid work item must roll the lease update back.' );
g4_s01_same( 0, $corrupt_database->tables['oj_ai_evaluation_runs'][0]['lock_version'], 'Invalid work item rollback must preserve lock version.' );
g4_s01_same( array(), $corrupt_database->tables['oj_ai_evaluation_attempts'], 'Invalid work item must not create an attempt.' );

echo "G4-IMP-02-S01 passed: one-winner claim, 300-second lease, exact-expiry reclaim, stale-token rejection, append-only attempts, and corrupt-input rollback are frozen.\n";
