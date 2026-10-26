<?php
/**
 * G4-IMP-06-S03 C44 transactional withdrawal storage contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

ob_start();
require __DIR__ . '/submission-result-withdrawal-g4-imp-06-s03.php';
ob_end_clean();

use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Application\Withdraw_Submission_Result;
use OJAI\Dashboard\Submission\Assessment\Infrastructure\WordPress_Submission_Result_Withdrawal_Repository;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

/**
 * G4-IMP-06-S03 C44 transactional withdrawal storage contract.
 */
final class G4_Result_Withdrawal_Database {
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Source row.
	 *
	 * @var array<string,mixed>
	 */
	public array $source_row;
	/**
	 * Releases.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $releases;
	/**
	 * Outbox.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $outbox = null;
	/**
	 * Audit.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $audit = array();
	/**
	 * Events.
	 *
	 * @var array<int,string>
	 */
	public array $events = array();
	/**
	 * Fail pointer.
	 *
	 * @var bool
	 */
	public bool $fail_pointer = false;
	/**
	 * Transaction state.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $transaction_state = null;

	/**
	 * Initialize g4 result withdrawal database dependencies and state.
	 *
	 * @param array<string,mixed>               $source_row Source row.
	 * @param array<string,array<string,mixed>> $releases Releases.
	 */
	public function __construct( array $source_row, array $releases ) {
		$this->source_row = $source_row;
		$this->releases   = $releases;
	}

	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$arguments Arguments.
	 * @return string
	 */
	public function prepare( string $sql, ...$arguments ): string {
		return $sql . ' /*args:' . base64_encode( serialize( $arguments ) ) . '*/';
	}

	/**
	 * Get row.
	 *
	 * @param string $sql Sql.
	 * @param string $output Output.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function get_row( string $sql, string $output ): ?array {
		unset( $output );
		if ( str_contains( $sql, 'FROM oj_ai_outbox_events WHERE idempotency_key' ) ) {
			$args = $this->arguments( $sql );
			if ( null === $this->outbox || $this->outbox['idempotency_key'] !== ( $args[0] ?? null ) || $this->outbox['event_type'] !== ( $args[1] ?? null ) ) {
				return null;
			}
			return array(
				'aggregate_public_id' => $this->outbox['aggregate_public_id'],
				'payload_schema'      => $this->outbox['payload_schema'],
				'payload_version'     => $this->outbox['payload_version'],
				'payload_json'        => $this->outbox['payload_json'],
				'payload_hash'        => $this->outbox['payload_hash'],
			);
		}
		if ( str_starts_with( $sql, 'SELECT e.id AS evaluation_pk' ) ) {
			return $this->source_row;
		}
		if ( str_starts_with( $sql, 'SELECT release_row.content_hash AS release_hash' ) ) {
			$args    = $this->arguments( $sql );
			$release = $this->releases[ (string) ( $args[0] ?? '' ) ] ?? null;
			if ( null === $release ) {
				return null;
			}
			return array(
				'release_hash'               => $release['content_hash'],
				'release_no'                 => $release['release_no'],
				'state'                      => $release['state'],
				'visible_score'              => $release['visible_score'],
				'student_projection_schema'  => $release['student_projection_schema'],
				'student_projection_version' => $release['student_projection_version'],
				'student_projection_json'    => $release['student_projection_json'],
				'student_projection_hash'    => $release['student_projection_hash'],
				'release_reason_code'        => $release['release_reason_code'],
				'release_reason_note'        => $release['release_reason_note'],
				'released_at_utc'            => $release['released_at_utc'],
				'evaluation_id'              => $release['_evaluation_id'],
				'review_id'                  => $release['_review_id'],
				'review_hash'                => $release['_review_hash'],
				'feedback_id'                => $release['_feedback_public_id'],
				'previous_release_id'        => $release['_previous_public_id'],
			);
		}
		if ( str_starts_with( $sql, 'SELECT current_release_public_id' ) ) {
			return array(
				'current_release_public_id' => $this->source_row['current_release_id'],
				'current_release_hash'      => $this->source_row['current_release_hash'],
				'lock_version'              => $this->source_row['submission_lock_version'],
			);
		}
		throw new RuntimeException( 'Unexpected C44 get_row query: ' . $sql );
	}

	/**
	 * Get var.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function get_var( string $sql ) {
		if ( str_starts_with( $sql, 'SELECT event_hash FROM oj_ai_audit_events' ) ) {
			return null;
		} throw new RuntimeException( 'Unexpected C44 get_var query: ' . $sql );
	}

	/**
	 * Query.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function query( string $sql ) {
		if ( 'START TRANSACTION' === $sql ) {
			$this->transaction_state = array(
				'source'   => $this->source_row,
				'releases' => $this->releases,
				'outbox'   => $this->outbox,
				'audit'    => $this->audit,
			);
			$this->events[]          = 'START';
			return 1;
		}
		if ( 'COMMIT' === $sql ) {
			$this->transaction_state = null;
			$this->events[]          = 'COMMIT';
			return 1;
		}
		if ( 'ROLLBACK' === $sql ) {
			if ( is_array( $this->transaction_state ) ) {
				$this->source_row = $this->transaction_state['source'];
				$this->releases   = $this->transaction_state['releases'];
				$this->outbox     = $this->transaction_state['outbox'];
				$this->audit      = $this->transaction_state['audit'];
			} $this->transaction_state = null;
			$this->events[]            = 'ROLLBACK';
			return 1;
		}
		if ( str_starts_with( $sql, 'UPDATE oj_ai_submissions SET current_release_public_id' ) ) {
			$this->events[] = 'UPDATE_POINTER';
			if ( $this->fail_pointer ) {
				return 0;
			}
			$args                                     = $this->arguments( $sql );
			$this->source_row['current_release_id']   = $args[0];
			$this->source_row['current_release_hash'] = $args[1];
			++$this->source_row['submission_lock_version'];
			return 1;
		}
		throw new RuntimeException( 'Unexpected C44 query: ' . $sql );
	}

	/**
	 * Insert.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data Data.
	 * @return mixed
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function insert( string $table, array $data ) {
		$this->events[] = 'INSERT:' . $table;
		if ( 'oj_ai_submission_release_revisions' === $table ) {
			$data['id']                           = 902;
			$data['_evaluation_id']               = $this->source_row['evaluation_id'];
			$data['_review_id']                   = $this->source_row['current_release_review_id'];
			$data['_review_hash']                 = $this->source_row['current_release_review_hash'];
			$data['_feedback_public_id']          = $this->source_row['current_release_feedback_id'];
			$data['_previous_public_id']          = $this->source_row['current_release_id'];
			$this->releases[ $data['public_id'] ] = $data;
			return 1;
		}
		if ( 'oj_ai_audit_events' === $table ) {
			$this->audit[] = $data;
			return 1;
		}
		if ( 'oj_ai_outbox_events' === $table ) {
			$this->outbox = $data;
			return 1;
		}
		throw new RuntimeException( 'Unexpected C44 insert table: ' . $table );
	}

	/**
	 * Arguments.
	 *
	 * @param string $sql Sql.
	 * @return array<int,mixed>
	 */
	private function arguments( string $sql ): array {
		if ( 1 !== preg_match( '~/\*args:([A-Za-z0-9+/=]+)\*/$~D', $sql, $matches ) ) {
			return array();
		}
		$bytes   = base64_decode( $matches[1], true );
		$decoded = unserialize( $bytes ? $bytes : '', array( 'allowed_classes' => false ) );
		return is_array( $decoded ) ? array_values( $decoded ) : array();
	}
}

/**
 * G4 withdrawal storage fixture.
 *
 * @param array{source:array<string,mixed>,snapshot:array<string,mixed>,release_record:array<string,mixed>} $fixture Fixture.
 * @return array{row:array<string,mixed>,releases:array<string,array<string,mixed>>}
 */
function g4_withdrawal_storage_fixture( array $fixture ): array {
	$source         = $fixture['source'];
	$snapshot       = $fixture['snapshot'];
	$release        = $fixture['release_record'];
	$release_id     = $snapshot['current_release_id'];
	$stored_release = array(
		'id'                         => 901,
		'public_id'                  => $release_id,
		'content_hash'               => $snapshot['current_release_hash'],
		'submission_evaluation_id'   => $source['evaluation_pk'],
		'submission_review_id'       => $source['official_review_pk'],
		'feedback_revision_id'       => $snapshot['current_release_feedback_pk'],
		'release_no'                 => 1,
		'state'                      => 'released',
		'visible_score'              => $release['visible_score'],
		'student_projection_schema'  => $release['projection_schema'],
		'student_projection_version' => $release['projection_version'],
		'student_projection_json'    => $release['projection_json'],
		'student_projection_hash'    => $release['projection_hash'],
		'previous_release_id'        => null,
		'release_reason_code'        => null,
		'release_reason_note'        => null,
		'released_at_utc'            => $release['released_at_utc'],
		'_evaluation_id'             => $source['evaluation_id'],
		'_review_id'                 => $source['official_review_id'],
		'_review_hash'               => $source['official_review_hash'],
		'_feedback_public_id'        => $snapshot['current_release_feedback_id'],
		'_previous_public_id'        => null,
	);
	$row            = array(
		'evaluation_pk'                      => $source['evaluation_pk'],
		'evaluation_id'                      => $source['evaluation_id'],
		'evaluation_lock_version'            => $source['evaluation_lock_version'],
		'evaluation_state'                   => 'reviewed',
		'submission_revision_id'             => $source['submission_revision_id'],
		'submission_revision_hash'           => $source['submission_revision_hash'],
		'joined_revision_hash'               => $source['submission_revision_hash'],
		'revision_state'                     => 'saved',
		'submission_pk'                      => $source['submission_pk'],
		'submission_id'                      => $source['submission_id'],
		'submission_lock_version'            => $snapshot['submission_lock_version'],
		'submission_state'                   => 'submitted',
		'official_revision_public_id'        => $source['submission_revision_id'],
		'official_review_id'                 => $source['official_review_id'],
		'official_review_hash'               => $source['official_review_hash'],
		'joined_official_review_id'          => $source['official_review_id'],
		'joined_official_review_hash'        => $source['official_review_hash'],
		'official_review_decision'           => 'adjusted',
		'current_release_id'                 => $release_id,
		'current_release_hash'               => $snapshot['current_release_hash'],
		'context_type'                       => $source['context_type'],
		'context_id'                         => $source['context_id'],
		'context_revision'                   => $source['context_revision'],
		'assignment_state'                   => 'open',
		'target_state'                       => 'active',
		'release_count'                      => '1',
		'current_release_pk'                 => 901,
		'joined_current_release_id'          => $release_id,
		'joined_current_release_hash'        => $snapshot['current_release_hash'],
		'current_release_evaluation_pk'      => $source['evaluation_pk'],
		'current_release_review_pk'          => $source['official_review_pk'],
		'current_release_feedback_pk'        => $snapshot['current_release_feedback_pk'],
		'current_release_previous_pk'        => null,
		'current_release_no'                 => '1',
		'current_release_state'              => 'released',
		'current_release_visible_score'      => $release['visible_score'],
		'current_release_projection_schema'  => $release['projection_schema'],
		'current_release_projection_version' => (string) $release['projection_version'],
		'current_release_projection_json'    => $release['projection_json'],
		'current_release_projection_hash'    => $release['projection_hash'],
		'current_release_reason_code'        => null,
		'current_release_reason_note'        => null,
		'current_release_released_at_utc'    => $release['released_at_utc'],
		'current_release_review_id'          => $source['official_review_id'],
		'current_release_review_hash'        => $source['official_review_hash'],
		'current_release_feedback_id'        => $snapshot['current_release_feedback_id'],
		'current_release_previous_id'        => null,
		'previous_release_evaluation_pk'     => null,
	);
	return array(
		'row'      => $row,
		'releases' => array( $release_id => $stored_release ),
	);
}

$withdrawal_storage_canonical   = new Canonical_Json();
$withdrawal_storage_app_fixture = g4_withdrawal_fixture( $withdrawal_storage_canonical );
$withdrawal_storage             = g4_withdrawal_storage_fixture( $withdrawal_storage_app_fixture );
$withdrawal_storage_db          = new G4_Result_Withdrawal_Database( $withdrawal_storage['row'], $withdrawal_storage['releases'] );
$withdrawal_storage_repository  = new WordPress_Submission_Result_Withdrawal_Repository( $withdrawal_storage_canonical, $withdrawal_storage_db );
$withdrawal_storage_application = new Withdraw_Submission_Result( $withdrawal_storage_repository, new Public_Id_Generator(), $withdrawal_storage_canonical, static fn (): string => '2026-08-25 00:20:00' );
$withdrawal_storage_command     = g4_withdrawal_command( $withdrawal_storage_app_fixture['snapshot'], $withdrawal_storage_canonical, 'command-g4-withdraw-storage', str_repeat( '8', 64 ), 'other', 'Teacher-only withdrawal detail.' );
$withdrawal_storage_result      = $withdrawal_storage_application->execute( $withdrawal_storage_command );

g4_release_same( array( 'START', 'INSERT:oj_ai_submission_release_revisions', 'UPDATE_POINTER', 'INSERT:oj_ai_audit_events', 'INSERT:oj_ai_outbox_events', 'COMMIT' ), $withdrawal_storage_db->events, 'C44 transaction operation order drifted.' );
g4_release_same( $withdrawal_storage_result->release_id(), $withdrawal_storage_db->source_row['current_release_id'], 'C44 current release pointer did not advance.' );
g4_release_same( $withdrawal_storage_result->release_hash(), $withdrawal_storage_db->source_row['current_release_hash'], 'C44 current release hash did not advance.' );
g4_release_same( $withdrawal_storage_app_fixture['snapshot']['submission_lock_version'] + 1, $withdrawal_storage_db->source_row['submission_lock_version'], 'C44 pointer CAS must advance submission lock once.' );
$withdrawal_stored = $withdrawal_storage_db->releases[ $withdrawal_storage_result->release_id() ];
g4_release_same( 901, $withdrawal_stored['previous_release_id'], 'C44 must link the previous release PK.' );
g4_release_same( 'withdrawn', $withdrawal_stored['state'], 'C44 stored state drifted.' );
g4_release_same( $withdrawal_storage_app_fixture['release_record']['projection_json'], $withdrawal_stored['student_projection_json'], 'C44 stored projection must be an exact copy.' );
g4_release_same( 'Teacher-only withdrawal detail.', $withdrawal_stored['release_reason_note'], 'C44 private reason note must be stored only in the release revision.' );
g4_release_same( 1, count( $withdrawal_storage_db->audit ), 'C44 must append one audit event.' );
$withdrawal_audit_json = $withdrawal_storage_db->audit[0]['metadata_json'];
g4_release_assert( ! str_contains( $withdrawal_audit_json, 'Teacher-only withdrawal detail.' ), 'C44 audit metadata must not duplicate the private reason note.' );
$withdrawal_outbox = $withdrawal_storage_db->outbox;
if ( null === $withdrawal_outbox ) {
	throw new RuntimeException( 'C44 outbox is missing.' );
}
$withdrawal_payload = json_decode( $withdrawal_outbox['payload_json'], true, 128, JSON_THROW_ON_ERROR );
g4_release_same( array( 'evaluation_id', 'release_id', 'release_path', 'request_digest' ), array_keys( $withdrawal_payload ), 'C44 outbox allowlist drifted.' );
foreach ( array( 'reason_code', 'reason_note', 'visible_score', 'student_projection_json', 'overall_feedback' ) as $forbidden ) {
	g4_release_assert( ! str_contains( $withdrawal_outbox['payload_json'], $forbidden ), 'C44 outbox leaked private release content: ' . $forbidden );
}

$withdrawal_event_count    = count( $withdrawal_storage_db->events );
$withdrawal_storage_replay = $withdrawal_storage_application->execute( $withdrawal_storage_command );
g4_release_same( true, $withdrawal_storage_replay->replayed(), 'C44 storage receipt must replay.' );
g4_release_same( $withdrawal_event_count, count( $withdrawal_storage_db->events ), 'C44 replay must open no new transaction.' );

$withdrawal_race_fixture          = g4_withdrawal_fixture( $withdrawal_storage_canonical );
$withdrawal_race_storage          = g4_withdrawal_storage_fixture( $withdrawal_race_fixture );
$withdrawal_race_db               = new G4_Result_Withdrawal_Database( $withdrawal_race_storage['row'], $withdrawal_race_storage['releases'] );
$withdrawal_race_db->fail_pointer = true;
$withdrawal_race_repository       = new WordPress_Submission_Result_Withdrawal_Repository( $withdrawal_storage_canonical, $withdrawal_race_db );
$withdrawal_race_application      = new Withdraw_Submission_Result( $withdrawal_race_repository, new Public_Id_Generator(), $withdrawal_storage_canonical, static fn (): string => '2026-08-25 00:21:00' );
g4_release_failure( static fn () => $withdrawal_race_application->execute( g4_withdrawal_command( $withdrawal_race_fixture['snapshot'], $withdrawal_storage_canonical, 'command-g4-withdraw-race', str_repeat( '9', 64 ) ) ), Submission_Evaluation_Failure::RELEASE_SOURCE_STALE, 'C44 pointer race must return release_source_stale.' );
g4_release_assert( in_array( 'ROLLBACK', $withdrawal_race_db->events, true ) && ! in_array( 'COMMIT', $withdrawal_race_db->events, true ), 'C44 pointer race must roll back the transaction.' );
g4_release_same( 1, count( $withdrawal_race_db->releases ), 'Rolled-back C44 race must preserve only the original release.' );

$withdrawal_storage_source = (string) file_get_contents( $addon_root . '/src/Submission/Assessment/Infrastructure/WordPress_Submission_Result_Withdrawal_Repository.php' );
g4_release_assert( 1 !== preg_match( '/\b(?:UPDATE|DELETE)\s+oj_ai_submission_release_revisions\b/i', $withdrawal_storage_source ), 'C44 release history must remain append-only.' );
g4_release_assert( str_contains( $withdrawal_storage_source, 'START TRANSACTION' ) && str_contains( $withdrawal_storage_source, 'FOR UPDATE' ) && str_contains( $withdrawal_storage_source, "'COMMIT'" ) && str_contains( $withdrawal_storage_source, "'ROLLBACK'" ), 'C44 transaction guards are incomplete.' );
g4_release_assert( ! str_contains( $withdrawal_storage_source, '$wpdb->prefix' ) && ! str_contains( $withdrawal_storage_source, 'wp_oj_ai_' ), 'C44 storage must use literal oj_ai_* table names.' );

fwrite( STDOUT, "G4-IMP-06-S03 C44 storage passed: immutable append, exact-copy projection, current pointer CAS, audit, body-free outbox, durable replay, and rollback are atomic.\n" );
