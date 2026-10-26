<?php
/**
 * G4-IMP-03-S01 review normalization regression under the S02 root transition.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader as AddonAutoloader;
use OJAI\Dashboard\Submission\Assessment\Application\Review_Submission_Evaluation;
use OJAI\Dashboard\Submission\Assessment\Application\Review_Submission_Evaluation_Command;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Review_Result;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Review_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

$addon_root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );
require_once $addon_root . '/src/Core/Autoloader.php';
( new AddonAutoloader( $addon_root . '/src' ) )->register();

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
 * G4 s01 failure.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $reason Reason.
 * @param string           $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s01_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback(); } catch ( Submission_Evaluation_Failure $failure ) {
		g4_s01_same( $reason, $failure->reason(), $message );
		return;
		}
		throw new RuntimeException( $message );
}

/**
 * G4 s01 invalid.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s01_invalid( callable $callback, string $message ): void {
	try {
		$callback(); } catch ( InvalidArgumentException ) {
		return;
		}
		throw new RuntimeException( $message );
}

/**
 * G4 s01 id.
 *
 * @return string
 */
function g4_s01_id(): string {
	static $suffix = 30100;
	++$suffix;
	$id = '01K' . str_pad( (string) $suffix, 23, '0', STR_PAD_LEFT );
	g4_s01_assert( Public_Id::is_valid( $id ), 'S01 fixture ID must satisfy PublicIdV1.' );
	return $id;
}

/**
 * G4 s01 snapshot.
 *
 * @param array $item_overrides Item overrides.
 * @return array<string,mixed>
 */
function g4_s01_snapshot( array $item_overrides = array() ): array {
	$first  = array(
		'binding_key'            => str_repeat( 'a', 64 ),
		'criterion_id'           => g4_s01_id(),
		'response_id'            => g4_s01_id(),
		'scope'                  => 'response',
		'position'               => 0,
		'candidate_points'       => '3.0000',
		'maximum_points'         => '5.0000',
		'levels'                 => null,
		'flags'                  => array(),
		'safety_review_required' => false,
	);
	$second = array(
		'binding_key'            => str_repeat( 'b', 64 ),
		'criterion_id'           => g4_s01_id(),
		'response_id'            => null,
		'scope'                  => 'submission',
		'position'               => 1,
		'candidate_points'       => '4.0000',
		'maximum_points'         => '5.0000',
		'levels'                 => null,
		'flags'                  => array(),
		'safety_review_required' => false,
	);
	foreach ( $item_overrides as $index => $values ) {
		if ( 0 === $index ) {
			$first = array_replace( $first, $values );
		}
		if ( 1 === $index ) {
			$second = array_replace( $second, $values );
		}
	}
	return array(
		'evaluation_lock_version'  => 4,
		'evaluation_state'         => 'review_required',
		'context_type'             => 'class',
		'context_id'               => 'class-g4-s01',
		'context_revision'         => 'class-g4-s01-r1',
		'submission_id'            => g4_s01_id(),
		'submission_revision_id'   => g4_s01_id(),
		'submission_revision_hash' => str_repeat( 'c', 64 ),
		'submission_lock_version'  => 7,
		'official_review_id'       => null,
		'official_review_hash'     => null,
		'current_release_id'       => null,
		'current_release_hash'     => null,
		'current_release_state'    => null,
		'result_id'                => g4_s01_id(),
		'result_hash'              => str_repeat( 'd', 64 ),
		'result_items'             => array( $first, $second ),
		'latest_review_id'         => null,
		'latest_review_no'         => 0,
	);
}

/**
 * G4-IMP-03-S01 review normalization regression under the S02 root transition.
 */
final class G4_S01_Repository implements Submission_Evaluation_Review_Repository_Port_Interface {
	/**
	 * Snapshot.
	 *
	 * @var array<string,mixed>
	 */
	public array $snapshot;
	/**
	 * Records.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $records = array();
	/**
	 * Receipts.
	 *
	 * @var array<string,array{digest:string,result:Submission_Evaluation_Review_Result}>
	 */
	private array $receipts = array();

	/**
	 * Initialize g4 s01 repository dependencies and state.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 */
	public function __construct( array $snapshot ) {
		$this->snapshot = $snapshot;
	}

	/**
	 * Find command receipt.
	 *
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @return Submission_Evaluation_Review_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_command_receipt( string $key, string $digest ): ?Submission_Evaluation_Review_Result {
		if ( ! isset( $this->receipts[ $key ] ) ) {
			return null;
		}
		if ( ! hash_equals( $this->receipts[ $key ]['digest'], $digest ) ) {
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::IDEMPOTENCY_CONFLICT );
		}
		return $this->copy_result( $this->receipts[ $key ]['result'], true );
	}

	/**
	 * Find owned snapshot.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $evaluation_id Evaluation id.
	 * @return array|null
	 */
	public function find_owned_snapshot( int $actor_user_id, string $evaluation_id ): ?array {
		unset( $actor_user_id, $evaluation_id );
		return $this->snapshot;
	}

	/**
	 * Append review.
	 *
	 * @param array $record Record.
	 * @return Submission_Evaluation_Review_Result
	 */
	public function append_review( array $record ): Submission_Evaluation_Review_Result {
		$this->records[] = $record;
		$advanced        = 'rejected' !== $record['decision'];
		$result          = new Submission_Evaluation_Review_Result(
			$record['evaluation_public_id'],
			$record['submission_public_id'],
			$record['submission_revision_public_id'],
			$record['submission_revision_hash'],
			$record['expected_result_public_id'],
			$record['expected_result_hash'],
			$record['review_public_id'],
			$record['content_hash'],
			$record['review_no'],
			$record['decision'],
			$record['official_score'],
			$record['score_decisions'],
			$record['reason'],
			$advanced ? $record['review_public_id'] : $record['expected_official_review_public_id'],
			$advanced ? $record['content_hash'] : $record['expected_official_review_hash'],
			$advanced,
			$record['occurred_at_utc'],
			false
		);
		$this->receipts[ $record['command_storage_key'] ] = array(
			'digest' => $record['request_digest'],
			'result' => $result,
		);
		return $result;
	}

	/**
	 * Copy result.
	 *
	 * @param Submission_Evaluation_Review_Result $source Source.
	 * @param bool                                $replayed Replayed.
	 * @return Submission_Evaluation_Review_Result
	 */
	private function copy_result( Submission_Evaluation_Review_Result $source, bool $replayed ): Submission_Evaluation_Review_Result {
		return new Submission_Evaluation_Review_Result(
			$source->evaluation_id(),
			$source->submission_id(),
			$source->submission_revision_id(),
			$source->submission_revision_hash(),
			$source->result_id(),
			$source->result_hash(),
			$source->review_id(),
			$source->review_hash(),
			$source->review_no(),
			$source->decision(),
			$source->official_score(),
			$source->score_decisions(),
			$source->reason(),
			$source->official_review_id(),
			$source->official_review_hash(),
			$source->official_pointer_advanced(),
			$source->occurred_at_utc(),
			$replayed
		);
	}
}

/**
 * G4 s01 command.
 *
 * @param Canonical_Json                 $canonical Canonical JSON encoder used for stable digests.
 * @param array                          $snapshot Snapshot.
 * @param string                         $decision Decision.
 * @param array<int,array<string,mixed>> $items Items.
 * @param string|null                    $reason Reason.
 * @param string                         $command_id Command identifier used for idempotency.
 * @param string|null                    $result_hash Result hash.
 * @param string|null                    $official_id Official id.
 * @param string|null                    $official_hash Official hash.
 * @return Review_Submission_Evaluation_Command
 */
function g4_s01_command(
	Canonical_Json $canonical,
	array $snapshot,
	string $decision,
	array $items = array(),
	?string $reason = null,
	string $command_id = 'command-g4-s01-review',
	?string $result_hash = null,
	?string $official_id = null,
	?string $official_hash = null
): Review_Submission_Evaluation_Command {
	return new Review_Submission_Evaluation_Command(
		42,
		g4_s01_id(),
		$command_id,
		$snapshot['result_id'],
		$result_hash ?? $snapshot['result_hash'],
		$official_id,
		$official_hash,
		$snapshot['latest_review_no'],
		$decision,
		$items,
		$reason,
		str_repeat( 'f', 64 ),
		'correlation-g4-s01',
		(string) $snapshot['evaluation_lock_version'],
		$snapshot['evaluation_state'],
		'context_staff',
		$canonical
	);
}

/**
 * G4 s01 application.
 *
 * @param G4_S01_Repository $repository Persistence boundary used by this operation.
 * @param Canonical_Json    $canonical Canonical JSON encoder used for stable digests.
 * @return Review_Submission_Evaluation
 */
function g4_s01_application( G4_S01_Repository $repository, Canonical_Json $canonical ): Review_Submission_Evaluation {
	return new Review_Submission_Evaluation(
		$repository,
		new Public_Id_Generator(),
		$canonical,
		static fn (): string => '2026-08-23 15:00:00'
	);
}

$canonical = new Canonical_Json();

$accepted_snapshot    = g4_s01_snapshot();
$accepted_repository  = new G4_S01_Repository( $accepted_snapshot );
$accepted_application = g4_s01_application( $accepted_repository, $canonical );
$accepted_command     = g4_s01_command( $canonical, $accepted_snapshot, 'accepted' );
$accepted             = $accepted_application->execute( $accepted_command );
g4_s01_same( 'accepted', $accepted->decision(), 'Accepted review decision drifted.' );
g4_s01_same( '7.0000', $accepted->official_score(), 'Accepted review total must be calculated by the server.' );
g4_s01_same( 2, count( $accepted->score_decisions() ?? array() ), 'Accepted review must preserve every binding.' );
g4_s01_same( array( 'ai_accepted', 'ai_accepted' ), array_column( $accepted->score_decisions() ?? array(), 'source' ), 'Accepted review must canonicalize AI sources.' );
g4_s01_same( true, $accepted->official_pointer_advanced(), 'S02 activation must advance an accepted official pointer.' );
g4_s01_same( $accepted->review_id(), $accepted->official_review_id(), 'Accepted official pointer must target the appended review.' );
g4_s01_same( 1, count( $accepted_repository->records ), 'Accepted review must append exactly once.' );
$accepted_replay = $accepted_application->execute( $accepted_command );
g4_s01_same( true, $accepted_replay->replayed(), 'Exact C34 replay must use the durable receipt.' );
g4_s01_same( 1, count( $accepted_repository->records ), 'Exact C34 replay must not append twice.' );

$adjusted_snapshot   = g4_s01_snapshot();
$adjusted_repository = new G4_S01_Repository( $adjusted_snapshot );
$adjusted            = g4_s01_application( $adjusted_repository, $canonical )->execute(
	g4_s01_command(
		$canonical,
		$adjusted_snapshot,
		'adjusted',
		array(
			array(
				'binding_key'     => str_repeat( 'b', 64 ),
				'source'          => 'teacher_assigned',
				'official_points' => '5',
				'reason_code'     => 'manual_assessment',
				'reason_note'     => null,
			),
			array(
				'binding_key'     => str_repeat( 'a', 64 ),
				'source'          => 'ai_accepted',
				'official_points' => '3.0000',
				'reason_code'     => null,
				'reason_note'     => null,
			),
		),
		'Teacher checked all criteria.'
	)
);
g4_s01_same( '8.0000', $adjusted->official_score(), 'Adjusted review total must ignore client ordering and sum official points.' );
g4_s01_same( array( 0, 1 ), array_column( $adjusted->score_decisions() ?? array(), 'position' ), 'Stored score decisions must follow result order.' );
g4_s01_same( 'teacher_assigned', ( $adjusted->score_decisions() ?? array() )[1]['source'], 'Adjusted source provenance drifted.' );
$adjusted_record = $adjusted_repository->records[0];
g4_s01_same( hash( 'sha256', $adjusted_record['score_decisions_json'] ), $adjusted_record['score_decisions_hash'], 'Score decision profile hash drifted.' );

$rejected_snapshot                         = g4_s01_snapshot();
$official_review_id                        = g4_s01_id();
$official_review_hash                      = str_repeat( '9', 64 );
$rejected_snapshot['official_review_id']   = $official_review_id;
$rejected_snapshot['official_review_hash'] = $official_review_hash;
$rejected_repository                       = new G4_S01_Repository( $rejected_snapshot );
$rejected                                  = g4_s01_application( $rejected_repository, $canonical )->execute(
	g4_s01_command( $canonical, $rejected_snapshot, 'rejected', array(), 'The AI result does not reflect the submitted work.', 'command-g4-s01-reject', null, $official_review_id, $official_review_hash )
);
g4_s01_same( null, $rejected->official_score(), 'Rejected review must not create an official score.' );
g4_s01_same( null, $rejected->score_decisions(), 'Rejected review must not store score decisions.' );
g4_s01_same( $official_review_id, $rejected->official_review_id(), 'Rejected review must preserve the existing official pointer.' );
g4_s01_same( false, $rejected->official_pointer_advanced(), 'Rejected review must never advance the pointer.' );

$abstain_snapshot = g4_s01_snapshot(
	array(
		0 => array(
			'candidate_points' => null,
			'flags'            => array( 'evidence_limited', 'teacher_review_required' ),
		),
	)
);
g4_s01_failure(
	static fn () => g4_s01_application( new G4_S01_Repository( $abstain_snapshot ), $canonical )->execute( g4_s01_command( $canonical, $abstain_snapshot, 'accepted' ) ),
	Submission_Evaluation_Failure::REVIEW_ITEMS_INCOMPLETE,
	'Accepted review must reject unresolved abstain bindings.'
);

$recovered_repository = new G4_S01_Repository( $abstain_snapshot );
$recovered            = g4_s01_application( $recovered_repository, $canonical )->execute(
	g4_s01_command(
		$canonical,
		$abstain_snapshot,
		'adjusted',
		array(
			array(
				'binding_key'     => str_repeat( 'a', 64 ),
				'source'          => 'teacher_assigned',
				'official_points' => '3',
				'reason_code'     => 'manual_assessment',
				'reason_note'     => 'Compared the original response against the criterion.',
			),
			array(
				'binding_key'     => str_repeat( 'b', 64 ),
				'source'          => 'ai_accepted',
				'official_points' => '4',
				'reason_code'     => null,
				'reason_note'     => null,
			),
		)
	)
);
g4_s01_same( '7.0000', $recovered->official_score(), 'Manual resolution must restore a complete official total.' );
g4_s01_same( array( 'teacher_assigned', 'ai_accepted' ), array_column( $recovered->score_decisions() ?? array(), 'source' ), 'Manual resolution must retain score provenance.' );
g4_s01_same( true, $recovered->official_pointer_advanced(), 'Resolved abstention must permit official scoring.' );
g4_s01_same( 1, count( $recovered_repository->records ), 'Resolution must append exactly one review.' );

$safety_snapshot = g4_s01_snapshot(
	array(
		0 => array(
			'flags'                  => array( 'safety_review_required' ),
			'safety_review_required' => true,
		),
	)
);
g4_s01_failure(
	static fn () => g4_s01_application( new G4_S01_Repository( $safety_snapshot ), $canonical )->execute( g4_s01_command( $canonical, $safety_snapshot, 'accepted' ) ),
	Submission_Evaluation_Failure::SAFETY_REVIEW_REQUIRED,
	'Accepted review must not silently accept a safety flag.'
);
$unsafe_adjustments = array(
	array(
		'binding_key'     => str_repeat( 'a', 64 ),
		'source'          => 'teacher_assigned',
		'official_points' => '3',
		'reason_code'     => 'manual_assessment',
		'reason_note'     => null,
	),
	array(
		'binding_key'     => str_repeat( 'b', 64 ),
		'source'          => 'ai_accepted',
		'official_points' => '4',
		'reason_code'     => null,
		'reason_note'     => null,
	),
);
g4_s01_failure(
	static fn () => g4_s01_application( new G4_S01_Repository( $safety_snapshot ), $canonical )->execute( g4_s01_command( $canonical, $safety_snapshot, 'adjusted', $unsafe_adjustments ) ),
	Submission_Evaluation_Failure::SAFETY_REVIEW_REQUIRED,
	'Safety resolution must include a teacher action note.'
);
$safe_adjustments                   = $unsafe_adjustments;
$safe_adjustments[0]['reason_note'] = 'Compared the original response and assigned the score manually.';
$safe_result                        = g4_s01_application( new G4_S01_Repository( $safety_snapshot ), $canonical )->execute( g4_s01_command( $canonical, $safety_snapshot, 'adjusted', $safe_adjustments ) );
g4_s01_same( '7.0000', $safe_result->official_score(), 'Documented safety resolution should allow a teacher-assigned score.' );

$range_snapshot = g4_s01_snapshot();
$range_items    = array(
	array(
		'binding_key'     => str_repeat( 'a', 64 ),
		'source'          => 'teacher_assigned',
		'official_points' => '6',
		'reason_code'     => 'manual_assessment',
		'reason_note'     => null,
	),
	array(
		'binding_key'     => str_repeat( 'b', 64 ),
		'source'          => 'ai_accepted',
		'official_points' => '4',
		'reason_code'     => null,
		'reason_note'     => null,
	),
);
g4_s01_failure(
	static fn () => g4_s01_application( new G4_S01_Repository( $range_snapshot ), $canonical )->execute( g4_s01_command( $canonical, $range_snapshot, 'adjusted', $range_items ) ),
	Submission_Evaluation_Failure::REVIEW_SCORE_INVALID,
	'Out-of-range teacher score must not append a review.'
);
g4_s01_failure(
	static fn () => g4_s01_application( new G4_S01_Repository( $range_snapshot ), $canonical )->execute(
		g4_s01_command( $canonical, $range_snapshot, 'adjusted', array( $range_items[0] ) )
	),
	Submission_Evaluation_Failure::REVIEW_ITEMS_INCOMPLETE,
	'Adjusted review must include every binding exactly once.'
);

$stale_snapshot = g4_s01_snapshot();
g4_s01_failure(
	static fn () => g4_s01_application( new G4_S01_Repository( $stale_snapshot ), $canonical )->execute(
		g4_s01_command( $canonical, $stale_snapshot, 'accepted', array(), null, 'command-g4-s01-stale', str_repeat( 'e', 64 ) )
	),
	Submission_Evaluation_Failure::REVIEW_SOURCE_STALE,
	'Changed exact result hash must fail as a stable review source conflict.'
);
g4_s01_invalid(
	static fn () => new Review_Submission_Evaluation_Command(
		42,
		g4_s01_id(),
		'command-g4-s01-partial',
		$stale_snapshot['result_id'],
		$stale_snapshot['result_hash'],
		g4_s01_id(),
		null,
		0,
		'accepted',
		array(),
		null,
		str_repeat( 'f', 64 ),
		'correlation-g4-s01',
		'4',
		'review_required',
		'context_staff',
		$canonical
	),
	'Partial expected official pointer must be rejected before storage.'
);
g4_s01_invalid(
	static fn () => g4_s01_command( $canonical, $stale_snapshot, 'rejected', array(), null ),
	'Rejected review must require a reason.'
);

$repository_source = (string) file_get_contents( $addon_root . '/src/Submission/Assessment/Infrastructure/WordPress_Submission_Evaluation_Review_Repository.php' );
$root_source       = (string) file_get_contents( $addon_root . '/src/Core/Composition_Root.php' );
g4_s01_assert( str_contains( $repository_source, "'oj_ai_submission_evaluation_reviews'" ), 'S01 repository must use the literal review table.' );
g4_s01_assert( str_contains( $repository_source, "'oj_ai_audit_events'" ) && str_contains( $repository_source, "'oj_ai_outbox_events'" ), 'S01 append must include audit and outbox records.' );
g4_s01_assert( str_contains( $repository_source, "'START TRANSACTION'" ) && str_contains( $repository_source, "' FOR UPDATE'" ), 'S01 append must lock and transact.' );
g4_s01_assert( str_contains( $repository_source, "'previous_review_id'" ) && str_contains( $repository_source, "'score_overrides_json'" ), 'S01 review must preserve the immutable chain and complete score profile.' );
g4_s01_assert( ! str_contains( $repository_source, '$wpdb->prefix' ) && ! str_contains( $repository_source, 'wp_oj_ai_' ), 'S01 must not use WordPress-prefixed tables.' );
g4_s01_assert( ! str_contains( $repository_source, 'CodingPanda\\OjPlatform\\Kernel\\' ), 'S01 must not use OJ Platform internals.' );
g4_s01_assert( 1 !== preg_match( '/\b(?:UPDATE|DELETE)\s+oj_ai_submission_(?:evaluation_results|evaluation_items|evaluation_reviews)\b/i', $repository_source ), 'S01 must never mutate or delete result/item/review history.' );
g4_s01_assert( str_contains( $root_source, 'WordPress_Submission_Evaluation_Review_Repository' ), 'S02 must activate the reviewed repository in the Composition Root.' );
$outbox_start  = strpos( $repository_source, 'private function insert_outbox' );
$receipt_start = strpos( $repository_source, 'private function command_receipt' );
g4_s01_assert( false !== $outbox_start && false !== $receipt_start && $receipt_start > $outbox_start, 'S01 outbox method boundaries must remain inspectable.' );
$outbox_source = substr( $repository_source, $outbox_start, $receipt_start - $outbox_start );
g4_s01_assert( ! str_contains( $outbox_source, "'official_score'" ) && ! str_contains( $outbox_source, "'score_decisions'" ), 'S01 outbox receipt must not contain score content.' );

fwrite( STDOUT, "G4-IMP-03-S01 review normalization regression passed under S02 activation.\n" );
