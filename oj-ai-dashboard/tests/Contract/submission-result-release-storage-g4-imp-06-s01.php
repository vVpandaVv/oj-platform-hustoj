<?php
/**
 * G4-IMP-06-S01 C43 transactional storage contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

ob_start();
require __DIR__ . '/submission-result-release-g4-imp-06-s01.php';
ob_end_clean();

use OJAI\Dashboard\Submission\Assessment\Application\Release_Submission_Result;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Feedback_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Feedback_Output_Normalizer;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Result_Projection_Builder;
use OJAI\Dashboard\Submission\Assessment\Infrastructure\WordPress_Submission_Result_Release_Repository;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

/**
 * G4-IMP-06-S01 C43 transactional storage contract.
 */
final class G4_Result_Release_Database {
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
	 * Release.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $release = null;
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
	 * Releases.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $releases = array();
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
	 * Transaction.
	 *
	 * @var bool
	 */
	private bool $transaction = false;
	/**
	 * Transaction release.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $transaction_release = null;
	/**
	 * Transaction releases.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $transaction_releases = array();
	/**
	 * Transaction source row.
	 *
	 * @var array<string,mixed>
	 */
	private array $transaction_source_row = array();
	/**
	 * Transaction outbox.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $transaction_outbox = null;
	/**
	 * Transaction audit.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $transaction_audit = array();

	/**
	 * Initialize g4 result release database dependencies and state.
	 *
	 * @param array<string,mixed> $source_row Source row.
	 */
	public function __construct( array $source_row ) {
		$this->source_row = $source_row;
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
			if ( null === $this->outbox || $this->outbox['idempotency_key'] !== ( $args[0] ?? null ) || ! in_array( $this->outbox['event_type'], array_slice( $args, 1 ), true ) ) {
				return null;
			}
			return array(
				'aggregate_public_id' => $this->outbox['aggregate_public_id'],
				'event_type'          => $this->outbox['event_type'],
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
			$previous_public_id = null;
			if ( null !== $release['previous_release_id'] ) {
				foreach ( $this->releases as $candidate ) {
					if ( $candidate['id'] === $release['previous_release_id'] ) {
						$previous_public_id = $candidate['public_id'];
						break;
					}
				}
				if ( null === $previous_public_id ) {
					throw new RuntimeException( 'C43 previous release fixture is missing.' );
				}
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
				'evaluation_id'              => $this->source_row['evaluation_id'],
				'review_id'                  => $this->source_row['official_review_id'],
				'review_hash'                => $this->source_row['official_review_hash'],
				'feedback_id'                => null === $release['feedback_revision_id'] ? null : $this->source_row['latest_feedback_id'],
				'previous_release_id'        => $previous_public_id,
			);
		}
		if ( str_starts_with( $sql, 'SELECT current_release_public_id' ) ) {
			return array(
				'current_release_public_id' => $this->source_row['current_release_id'],
				'current_release_hash'      => $this->source_row['current_release_hash'],
				'lock_version'              => $this->source_row['submission_lock_version'],
			);
		}
		throw new RuntimeException( 'Unexpected C43 get_row query: ' . $sql );
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
		} throw new RuntimeException( 'Unexpected C43 get_var query: ' . $sql );
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
			$this->transaction            = true;
			$this->transaction_release    = $this->release;
			$this->transaction_releases   = $this->releases;
			$this->transaction_source_row = $this->source_row;
			$this->transaction_outbox     = $this->outbox;
			$this->transaction_audit      = $this->audit;
			$this->events[]               = 'START';
			return 1;
		}
		if ( 'COMMIT' === $sql ) {
			$this->transaction = false;
			$this->events[]    = 'COMMIT';
			return 1;
		}
		if ( 'ROLLBACK' === $sql ) {
			$this->release     = $this->transaction_release;
			$this->releases    = $this->transaction_releases;
			$this->source_row  = $this->transaction_source_row;
			$this->outbox      = $this->transaction_outbox;
			$this->audit       = $this->transaction_audit;
			$this->transaction = false;
			$this->events[]    = 'ROLLBACK';
			return 1;
		}
		if ( str_starts_with( $sql, 'UPDATE oj_ai_submissions SET current_release_public_id' ) ) {
			$this->events[] = 'UPDATE_POINTER';
			if ( $this->fail_pointer ) {
				return 0;
			}
			$args = $this->arguments( $sql );
			if ( null === $this->release ) {
				throw new RuntimeException( 'C43 pointer fixture has no pending release.' );
			}
			$this->source_row['current_release_id']            = $args[0];
			$this->source_row['current_release_hash']          = $args[1];
			$this->source_row['current_release_pk']            = $this->release['id'];
			$this->source_row['joined_current_release_id']     = $this->release['public_id'];
			$this->source_row['current_release_evaluation_pk'] = $this->source_row['evaluation_pk'];
			$this->source_row['joined_current_release_hash']   = $this->release['content_hash'];
			$this->source_row['current_release_no']            = $this->release['release_no'];
			$this->source_row['current_release_state']         = $this->release['state'];
			$this->source_row['current_projection_hash']       = $this->release['student_projection_hash'];
			$this->source_row['release_count']                 = (string) count( $this->releases );
			++$this->source_row['submission_lock_version'];
			return 1;
		}
		throw new RuntimeException( 'Unexpected C43 query: ' . $sql );
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
			$data['id']                           = 900 + count( $this->releases ) + 1;
			$this->release                        = $data;
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
		throw new RuntimeException( 'Unexpected C43 insert table: ' . $table );
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
 * G4 release storage row.
 *
 * @param array<string,mixed> $source Source.
 * @param array<string,mixed> &$snapshot Snapshot.
 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
 * @return array<string,mixed>
 */
function g4_release_storage_row( array $source, array &$snapshot, Canonical_Json $canonical ): array {
	$previous_feedback_id                      = g4_feedback_review_id();
	$source_run_id                             = null;
	$feedback_no                               = 3;
	$snapshot['latest_feedback_revision_hash'] = hash(
		'sha256',
		$canonical->encode(
			array(
				'audience'             => 'student',
				'evaluation_id'        => $source['evaluation_id'],
				'feedback_hash'        => $snapshot['latest_feedback_profile_hash'],
				'previous_revision_id' => $previous_feedback_id,
				'review_id'            => $source['official_review_id'],
				'revision_no'          => $feedback_no,
				'source_run_id'        => $source_run_id,
				'state'                => 'approved',
			)
		)
	);
	return array(
		'evaluation_pk'                 => $source['evaluation_pk'],
		'evaluation_id'                 => $source['evaluation_id'],
		'evaluation_lock_version'       => $source['evaluation_lock_version'],
		'evaluation_state'              => 'reviewed',
		'submission_revision_id'        => $source['submission_revision_id'],
		'submission_revision_hash'      => $source['submission_revision_hash'],
		'joined_revision_hash'          => $source['submission_revision_hash'],
		'revision_state'                => 'saved',
		'submission_pk'                 => $source['submission_pk'],
		'submission_id'                 => $source['submission_id'],
		'submission_lock_version'       => $source['submission_lock_version'],
		'submission_state'              => 'submitted',
		'official_revision_public_id'   => $source['submission_revision_id'],
		'official_review_id'            => $source['official_review_id'],
		'official_review_hash'          => $source['official_review_hash'],
		'current_release_id'            => null,
		'current_release_hash'          => null,
		'worksheet_title'               => 'Fraction comparison worksheet',
		'context_type'                  => $source['context_type'],
		'context_id'                    => $source['context_id'],
		'context_revision'              => $source['context_revision'],
		'assignment_state'              => 'open',
		'target_state'                  => 'active',
		'official_review_pk'            => $source['official_review_pk'],
		'joined_review_id'              => $source['official_review_id'],
		'joined_review_hash'            => $source['official_review_hash'],
		'official_review_decision'      => 'adjusted',
		'official_score'                => '8.0000',
		'feedback_count'                => (string) $feedback_no,
		'latest_feedback_pk'            => 81,
		'latest_feedback_id'            => $snapshot['latest_feedback_revision_id'],
		'latest_feedback_hash'          => $snapshot['latest_feedback_revision_hash'],
		'latest_feedback_no'            => (string) $feedback_no,
		'latest_feedback_audience'      => 'student',
		'latest_feedback_schema'        => $snapshot['latest_feedback_schema'],
		'latest_feedback_version'       => (string) $snapshot['latest_feedback_version'],
		'latest_feedback_json'          => $snapshot['latest_feedback_json'],
		'latest_feedback_profile_hash'  => $snapshot['latest_feedback_profile_hash'],
		'latest_feedback_state'         => 'approved',
		'latest_feedback_source_run_id' => $source_run_id,
		'latest_feedback_review_id'     => $source['official_review_id'],
		'latest_feedback_review_hash'   => $source['official_review_hash'],
		'latest_previous_feedback_id'   => $previous_feedback_id,
		'release_count'                 => '0',
		'current_release_pk'            => null,
		'joined_current_release_id'     => null,
		'current_release_evaluation_pk' => null,
		'joined_current_release_hash'   => null,
		'current_release_no'            => null,
		'current_release_state'         => null,
		'current_projection_hash'       => null,
	);
}

$storage_canonical   = new Canonical_Json();
$storage_fixture     = g4_release_fixture( $storage_canonical );
$storage_source      = $storage_fixture['source'];
$storage_snapshot    = $storage_fixture['snapshot'];
$storage_db          = new G4_Result_Release_Database( g4_release_storage_row( $storage_source, $storage_snapshot, $storage_canonical ) );
$storage_repository  = new WordPress_Submission_Result_Release_Repository( $storage_canonical, $storage_db );
$storage_application = new Release_Submission_Result( new G4_Feedback_Review_Source( $storage_source ), $storage_repository, new Submission_Result_Projection_Builder( new Submission_Feedback_Input_Builder( $storage_canonical ), new Submission_Feedback_Output_Normalizer( $storage_canonical ), $storage_canonical ), new Public_Id_Generator(), $storage_canonical, static fn (): string => '2026-08-24 23:20:00' );
$storage_command     = g4_release_command( $storage_source, $storage_snapshot, $storage_canonical, 'command-g4-release-storage', str_repeat( 'e', 64 ) );
$storage_result      = $storage_application->execute( $storage_command );

g4_release_same( array( 'START', 'INSERT:oj_ai_submission_release_revisions', 'UPDATE_POINTER', 'INSERT:oj_ai_audit_events', 'INSERT:oj_ai_outbox_events', 'COMMIT' ), $storage_db->events, 'C43 transaction operation order drifted.' );
g4_release_same( $storage_result->release_id(), $storage_db->source_row['current_release_id'], 'C43 submission current release pointer did not advance.' );
g4_release_same( $storage_result->release_hash(), $storage_db->source_row['current_release_hash'], 'C43 submission current release hash did not advance.' );
g4_release_same( 5, $storage_db->source_row['submission_lock_version'], 'C43 pointer CAS must advance submission lock once.' );
g4_release_same( 1, count( $storage_db->audit ), 'C43 must append one audit event.' );
$storage_outbox = $storage_db->outbox;
if ( null === $storage_outbox ) {
	throw new RuntimeException( 'C43 outbox is missing.' );
}
$storage_payload = json_decode( $storage_outbox['payload_json'], true, 128, JSON_THROW_ON_ERROR );
g4_release_same( array( 'evaluation_id', 'release_id', 'release_path', 'request_digest' ), array_keys( $storage_payload ), 'C43 outbox allowlist drifted.' );
foreach ( array( 'visible_score', 'overall_feedback', 'criterion_feedback', 'student_projection_json', 'feedback_json' ) as $forbidden ) {
	g4_release_assert( ! str_contains( $storage_outbox['payload_json'], $forbidden ), 'C43 outbox leaked result content: ' . $forbidden );
}
$storage_event_count = count( $storage_db->events );
$storage_replay      = $storage_application->execute( $storage_command );
g4_release_same( true, $storage_replay->replayed(), 'C43 storage receipt must replay.' );
g4_release_same( $storage_event_count, count( $storage_db->events ), 'C43 replay must open no new transaction.' );

$race_fixture          = g4_release_fixture( $storage_canonical );
$race_source           = $race_fixture['source'];
$race_snapshot         = $race_fixture['snapshot'];
$race_db               = new G4_Result_Release_Database( g4_release_storage_row( $race_source, $race_snapshot, $storage_canonical ) );
$race_db->fail_pointer = true;
$race_repository       = new WordPress_Submission_Result_Release_Repository( $storage_canonical, $race_db );
$race_application      = new Release_Submission_Result( new G4_Feedback_Review_Source( $race_source ), $race_repository, new Submission_Result_Projection_Builder( new Submission_Feedback_Input_Builder( $storage_canonical ), new Submission_Feedback_Output_Normalizer( $storage_canonical ), $storage_canonical ), new Public_Id_Generator(), $storage_canonical, static fn (): string => '2026-08-24 23:21:00' );
g4_release_failure( static fn () => $race_application->execute( g4_release_command( $race_source, $race_snapshot, $storage_canonical, 'command-g4-release-race', str_repeat( 'f', 64 ) ) ), Submission_Evaluation_Failure::RELEASE_SOURCE_STALE, 'C43 pointer race must return release_source_stale.' );
g4_release_assert( in_array( 'ROLLBACK', $race_db->events, true ) && ! in_array( 'COMMIT', $race_db->events, true ), 'C43 pointer race must roll back the transaction.' );
g4_release_same( null, $race_db->release, 'Rolled-back C43 race must persist no release revision.' );

$foreign_row          = g4_release_storage_row( $storage_source, $storage_snapshot, $storage_canonical );
$foreign_row          = array_merge(
	$foreign_row,
	array(
		'current_release_id'            => $storage_result->release_id(),
		'current_release_hash'          => $storage_result->release_hash(),
		'current_release_pk'            => 901,
		'joined_current_release_id'     => $storage_result->release_id(),
		'current_release_evaluation_pk' => $storage_source['evaluation_pk'] + 100,
		'current_release_revision_id'   => $storage_source['submission_revision_id'],
		'joined_current_release_hash'   => $storage_result->release_hash(),
		'current_release_no'            => 8,
		'current_release_state'         => 'stale',
		'current_projection_hash'       => $storage_db->release['student_projection_hash'],
	)
);
$foreign_db           = new G4_Result_Release_Database( $foreign_row );
$foreign_db->releases = $storage_db->releases;
$foreign_repository   = new WordPress_Submission_Result_Release_Repository( $storage_canonical, $foreign_db );
$foreign_snapshot     = $foreign_repository->find_owned_snapshot( 42, $storage_source['evaluation_id'] );
g4_release_same( false, $foreign_snapshot['current_release_same_evaluation'], 'A prior evaluation on the same answer must be identifiable.' );
$foreign_application = new Release_Submission_Result( new G4_Feedback_Review_Source( $storage_source ), $foreign_repository, new Submission_Result_Projection_Builder( new Submission_Feedback_Input_Builder( $storage_canonical ), new Submission_Feedback_Output_Normalizer( $storage_canonical ), $storage_canonical ), new Public_Id_Generator(), $storage_canonical, static fn (): string => '2026-09-08 10:00:00' );
$foreign_result      = $foreign_application->execute( g4_release_command( $storage_source, $foreign_snapshot, $storage_canonical, 'command-cross-evaluation-correction', str_repeat( '9', 64 ), 'total', 'hidden', 'correction', 'data_correction', null ) );
g4_release_same( 1, $foreign_result->release_no(), 'New evaluation correction must start its own release sequence.' );
g4_release_same( 'corrected', $foreign_result->state(), 'Cross-evaluation replacement must retain correction semantics.' );
g4_release_same( 901, $foreign_db->release['previous_release_id'], 'Cross-evaluation correction must retain the previous release pointer.' );
$foreign_row['current_release_revision_id'] = g4_feedback_review_id();
$unrelated                                  = new WordPress_Submission_Result_Release_Repository( $storage_canonical, new G4_Result_Release_Database( $foreign_row ) );
g4_release_failure( static fn () => $unrelated->find_owned_snapshot( 42, $storage_source['evaluation_id'] ), Submission_Evaluation_Failure::STORED_DATA_INVALID, 'A release from another answer revision must be rejected.' );

$storage_repository_source = (string) file_get_contents( $addon_root . '/src/Submission/Assessment/Infrastructure/WordPress_Submission_Result_Release_Repository.php' );
g4_release_assert( 1 !== preg_match( '/\b(?:UPDATE|DELETE)\s+oj_ai_submission_release_revisions\b/i', $storage_repository_source ), 'C43 release history must remain append-only.' );
g4_release_assert( str_contains( $storage_repository_source, 'START TRANSACTION' ) && str_contains( $storage_repository_source, 'FOR UPDATE' ) && str_contains( $storage_repository_source, "'COMMIT'" ) && str_contains( $storage_repository_source, "'ROLLBACK'" ), 'C43 transaction guards are incomplete.' );
g4_release_assert( ! str_contains( $storage_repository_source, '$wpdb->prefix' ) && ! str_contains( $storage_repository_source, 'wp_oj_ai_' ), 'C43 storage must use literal oj_ai_* table names.' );

fwrite( STDOUT, "G4-IMP-06-S01 C43 storage passed: release, pointer CAS, audit, body-free outbox, receipt replay, append-only history, and rollback are atomic.\n" );
