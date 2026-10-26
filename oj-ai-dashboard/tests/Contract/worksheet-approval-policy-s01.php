<?php
/**
 * G2-IMP-06-S01 worksheet approval policy gate contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader as Addon_Autoloader;
use OJAI\Dashboard\Worksheet\Authoring\Application\Validate_Worksheet_Revision_Result;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Approval_Policy;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Approval_Policy_Gate;

$addon_root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );

if ( ! class_exists( 'Normalizer' ) ) {
	/**
	 * G2-IMP-06-S01 worksheet approval policy gate contract.
	 */
	final class Normalizer {
		public const FORM_C = 4;
		/**
		 * Normalize.
		 *
		 * @param string $value Value.
		 * @param int    $form Form.
		 * @return string
		 */
		public static function normalize( string $value, int $form = self::FORM_C ): string {
			return $value;
		}
	}
}

require_once $addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core'
	. DIRECTORY_SEPARATOR . 'Autoloader.php';
( new Addon_Autoloader( $addon_root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * Approval s01 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function approval_s01_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Approval s01 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function approval_s01_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true )
		);
	}
}

/**
 * Approval s01 invalid.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function approval_s01_invalid( callable $callback, string $message ): void {
	try {
		$callback();
	} catch ( InvalidArgumentException ) {
		return;
	}
	throw new RuntimeException( $message );
}

/**
 * Approval s01 id.
 *
 * @param int $suffix Suffix.
 * @return string
 */
function approval_s01_id( int $suffix ): string {
	$id = '01K' . str_pad( (string) $suffix, 23, '0', STR_PAD_LEFT );
	approval_s01_assert( Public_Id::is_valid( $id ), 'S01 fixture ID must be PublicIdV1.' );
	return $id;
}

/**
 * Approval s01 hash.
 *
 * @param string $value Value.
 * @return string
 */
function approval_s01_hash( string $value ): string {
	return hash( 'sha256', $value );
}

/**
 * Approval s01 validation.
 *
 * @param string                              $revision_state Revision state.
 * @param bool                                $ready Ready.
 * @param array<int,array<string,mixed>>|null $checks Checks.
 * @return Validate_Worksheet_Revision_Result
 */
function approval_s01_validation(
	string $revision_state = 'draft',
	bool $ready = true,
	?array $checks = null
): Validate_Worksheet_Revision_Result {
	return new Validate_Worksheet_Revision_Result(
		approval_s01_id( 1 ),
		approval_s01_id( 2 ),
		$revision_state,
		approval_s01_hash( 'approval-s01-document' ),
		approval_s01_hash( 'approval-s01-validation' ),
		$ready ? 'ready' : 'blocked',
		$ready,
		$checks ?? array(
			array(
				'id'     => 'document_profile',
				'status' => $ready ? 'passed' : 'blocked',
			),
			array(
				'id'     => 'renderer_parity',
				'status' => $ready ? 'passed' : 'blocked',
			),
		),
		$ready ? array() : array( array( 'code' => 'worksheet_projection_invalid' ) ),
		array(),
		array( 'check_count' => 2 ),
		approval_s01_hash( $ready ? 'approval-s01-ready-projection' : 'approval-s01-blocked-projection' ),
		'2026-08-21 16:00:00'
	);
}

/**
 * Approval s01 finding.
 *
 * @param string $key Key.
 * @param bool   $safety Safety.
 * @return array<string,mixed>
 */
function approval_s01_finding( string $key, bool $safety = false ): array {
	return array(
		'finding_key'    => $key,
		'dimension_key'  => $safety ? 'safety' : 'clarity',
		'position'       => 0,
		'severity'       => $safety ? 'critical' : 'major',
		'title'          => '확인 항목',
		'rationale'      => '교사 확인이 필요합니다.',
		'evidence'       => array(),
		'recommendation' => '내용을 확인하세요.',
		'confidence'     => '0.9000',
	);
}

/**
 * Approval s01 review item.
 *
 * @param string $finding_key Finding key.
 * @param string $decision Decision.
 * @return array<string,mixed>
 */
function approval_s01_review_item( string $finding_key, string $decision ): array {
	if ( 'resolved' === $decision ) {
		return array(
			'finding_key'            => $finding_key,
			'decision'               => 'resolved',
			'reason_code'            => null,
			'note'                   => null,
			'resolution_revision_id' => approval_s01_id( 2 ),
			'resolution_source_hash' => approval_s01_hash( 'approval-s01-document' ),
		);
	}
	return array(
		'finding_key'            => $finding_key,
		'decision'               => $decision,
		'reason_code'            => 'teacher_verified',
		'note'                   => '교사가 근거를 확인했습니다.',
		'resolution_revision_id' => null,
		'resolution_source_hash' => null,
	);
}

/**
 * Approval s01 detail.
 *
 * @param string                         $state State.
 * @param bool                           $exact Exact.
 * @param bool                           $safety Safety.
 * @param array<int,array<string,mixed>> $findings Findings.
 * @param array<int,array<string,mixed>> $items Items.
 * @param string                         $review_decision Review decision.
 * @return array<string,mixed>
 */
function approval_s01_detail(
	string $state,
	bool $exact = true,
	bool $safety = false,
	array $findings = array(),
	array $items = array(),
	string $review_decision = 'completed'
): array {
	$has_result  = in_array( $state, array( 'review_required', 'reviewed' ), true );
	$has_review  = 'reviewed' === $state || array() !== $items;
	$revision_id = $exact ? approval_s01_id( 2 ) : approval_s01_id( 30 );
	$source_hash = $exact
		? approval_s01_hash( 'approval-s01-document' )
		: approval_s01_hash( 'approval-s01-old-document' );
	$result      = null;
	if ( $has_result ) {
		$result = array(
			'id'                 => approval_s01_id( 5 ),
			'run_id'             => approval_s01_id( 4 ),
			'overall_status'     => $safety ? 'safety_review' : ( array() === $findings ? 'ready' : 'action_required' ),
			'overall_confidence' => '0.9000',
			'summary'            => '활동지 자체 평가 결과',
			'abstained'          => false,
			'safety_flagged'     => $safety,
			'abstain_reason'     => null,
			'dimensions'         => array(),
			'findings'           => $findings,
			'limitations'        => array(),
			'created_at_utc'     => '2026-08-21 16:05:00',
		);
	}
	$reviews = array();
	if ( $has_review ) {
		$reviews[] = array(
			'id'             => approval_s01_id( 6 ),
			'review_no'      => 1,
			'decision'       => $review_decision,
			'note'           => null,
			'item_decisions' => $items,
			'previous_id'    => null,
			'created_at_utc' => '2026-08-21 16:10:00',
		);
	}

	return array(
		'evaluation' => array(
			'id'               => approval_s01_id( 3 ),
			'state'            => $state,
			'is_stale'         => ! $exact,
			'worksheet'        => array(
				'id'    => approval_s01_id( 1 ),
				'title' => '승인할 활동지',
			),
			'revision'         => array(
				'id'          => $revision_id,
				'revision_no' => $exact ? 2 : 1,
				'state'       => 'draft',
				'source_hash' => $source_hash,
				'is_current'  => $exact,
			),
			'status'           => array(
				'overall_status'        => null === $result ? null : $result['overall_status'],
				'finding_count'         => count( $findings ),
				'needs_attention_count' => count( $findings ),
				'safety_flagged'        => $safety,
				'latest_review_no'      => $has_review ? 1 : null,
				'next_action'           => 'none',
			),
			'latest_run'       => array(
				'id'    => approval_s01_id( 4 ),
				'state' => 'succeeded',
			),
			'requested_at_utc' => '2026-08-21 16:01:00',
			'updated_at_utc'   => '2026-08-21 16:10:00',
		),
		'result'     => $result,
		'runs'       => array(
			array(
				'id'    => approval_s01_id( 4 ),
				'state' => 'succeeded',
			),
		),
		'reviews'    => $reviews,
	);
}

/**
 * Approval s01 codes.
 *
 * @param array<int,array{code:string}> $items Items.
 * @return array<int,string>
 */
function approval_s01_codes( array $items ): array {
	return array_column( $items, 'code' );
}

$canonical  = new Canonical_Json();
$gate       = new Worksheet_Approval_Policy_Gate( $canonical );
$validation = approval_s01_validation();
$required   = new Worksheet_Approval_Policy(
	approval_s01_id( 10 ),
	Worksheet_Approval_Policy::EVALUATION_REQUIRED,
	true,
	$canonical
);
$optional   = new Worksheet_Approval_Policy(
	approval_s01_id( 11 ),
	Worksheet_Approval_Policy::EVALUATION_OPTIONAL,
	true,
	$canonical
);

$ready = $gate->decide( $validation, $required, approval_s01_detail( 'reviewed' ) );
approval_s01_same( true, $ready->approval_ready(), 'A required exact reviewed evaluation with no findings must pass.' );
approval_s01_same( 'ready', $ready->status(), 'A passing approval decision must be ready.' );
approval_s01_same( array(), $ready->blockers(), 'A passing approval decision must have no blockers.' );
approval_s01_assert( 1 === preg_match( '/^[a-f0-9]{64}$/D', $ready->evidence_hash() ), 'Evidence hash must be SHA-256.' );
approval_s01_same(
	$ready->decision_hash(),
	$gate->decide( $validation, $required, approval_s01_detail( 'reviewed' ) )->decision_hash(),
	'The same approval evidence must produce the same decision hash.'
);

$required_missing = $gate->decide( $validation, $required, null );
approval_s01_same( false, $required_missing->approval_ready(), 'A required missing evaluation must block.' );
approval_s01_same(
	array( 'worksheet_evaluation_required' ),
	approval_s01_codes( $required_missing->blockers() ),
	'A required missing evaluation blocker code drifted.'
);

$optional_missing = $gate->decide( $validation, $optional, null );
approval_s01_same( true, $optional_missing->approval_ready(), 'An optional missing evaluation must not block.' );
approval_s01_same(
	array( 'worksheet_evaluation_not_run' ),
	approval_s01_codes( $optional_missing->warnings() ),
	'An optional missing evaluation must remain explicit.'
);

$optional_failed = $gate->decide( $validation, $optional, approval_s01_detail( 'failed' ) );
approval_s01_same( true, $optional_failed->approval_ready(), 'An optional failed evaluation alone must not block.' );
approval_s01_same(
	array( 'worksheet_evaluation_failed' ),
	approval_s01_codes( $optional_failed->warnings() ),
	'An optional failed evaluation must remain explicit.'
);

$optional_running = $gate->decide( $validation, $optional, approval_s01_detail( 'running' ) );
approval_s01_same( false, $optional_running->approval_ready(), 'An in-flight optional evaluation must block an approval race.' );
approval_s01_same(
	array( 'worksheet_evaluation_in_progress' ),
	approval_s01_codes( $optional_running->blockers() ),
	'In-flight evaluation blocker code drifted.'
);

$review_required = $gate->decide( $validation, $optional, approval_s01_detail( 'review_required' ) );
approval_s01_same( false, $review_required->approval_ready(), 'AI output without human review must never approve.' );
approval_s01_same(
	array( 'worksheet_evaluation_review_required' ),
	approval_s01_codes( $review_required->blockers() ),
	'Human review blocker code drifted.'
);

$safety_finding = approval_s01_finding( approval_s01_hash( 'approval-s01-safety' ), true );
$safety         = $gate->decide(
	$validation,
	$optional,
	approval_s01_detail( 'review_required', true, true, array( $safety_finding ) )
);
approval_s01_same( false, $safety->approval_ready(), 'A known safety flag must block even when evaluation is optional.' );
approval_s01_same(
	array( 'worksheet_evaluation_review_required', 'worksheet_evaluation_safety_review_required' ),
	approval_s01_codes( $safety->blockers() ),
	'Safety blockers must remain explicit and deterministic.'
);

$failed_with_safety_result                        = approval_s01_detail(
	'review_required',
	true,
	true,
	array( $safety_finding )
);
$failed_with_safety_result['evaluation']['state'] = 'failed';
$failed_safety                                    = $gate->decide( $validation, $optional, $failed_with_safety_result );
approval_s01_same( false, $failed_safety->approval_ready(), 'A failed retry must not hide an earlier safety result.' );
approval_s01_same(
	array( 'worksheet_evaluation_review_required', 'worksheet_evaluation_safety_review_required' ),
	approval_s01_codes( $failed_safety->blockers() ),
	'Preserved safety result blockers drifted.'
);
approval_s01_same(
	array( 'worksheet_evaluation_failed' ),
	approval_s01_codes( $failed_safety->warnings() ),
	'Failed state must remain explicit while safety review blocks.'
);

$required_stale = $gate->decide( $validation, $required, approval_s01_detail( 'reviewed', false ) );
approval_s01_same( false, $required_stale->approval_ready(), 'A required stale evaluation must block.' );
approval_s01_same(
	array( 'worksheet_evaluation_stale' ),
	approval_s01_codes( $required_stale->blockers() ),
	'Required stale evaluation blocker code drifted.'
);

$optional_stale = $gate->decide( $validation, $optional, approval_s01_detail( 'reviewed', false ) );
approval_s01_same( true, $optional_stale->approval_ready(), 'A completed stale optional evaluation with no findings may warn.' );
approval_s01_same(
	array( 'worksheet_evaluation_stale' ),
	approval_s01_codes( $optional_stale->warnings() ),
	'Optional stale evaluation warning code drifted.'
);

$finding_a         = approval_s01_finding( approval_s01_hash( 'approval-s01-finding-a' ) );
$finding_b         = approval_s01_finding( approval_s01_hash( 'approval-s01-finding-b' ) );
$dismissed_detail  = approval_s01_detail(
	'reviewed',
	true,
	false,
	array( $finding_b, $finding_a ),
	array(
		approval_s01_review_item( $finding_a['finding_key'], 'dismissed' ),
		approval_s01_review_item( $finding_b['finding_key'], 'dismissed' ),
	)
);
$dismissed_allowed = $gate->decide( $validation, $required, $dismissed_detail );
approval_s01_same( true, $dismissed_allowed->approval_ready(), 'Policy-authorized teacher dismissals may clear findings.' );

$no_dismissal      = new Worksheet_Approval_Policy(
	approval_s01_id( 12 ),
	Worksheet_Approval_Policy::EVALUATION_REQUIRED,
	false,
	$canonical
);
$dismissed_blocked = $gate->decide( $validation, $no_dismissal, $dismissed_detail );
approval_s01_same( false, $dismissed_blocked->approval_ready(), 'A policy-forbidden dismissal must block.' );
approval_s01_same(
	array( 'worksheet_evaluation_dismissal_not_allowed' ),
	approval_s01_codes( $dismissed_blocked->blockers() ),
	'Dismissal policy blocker code drifted.'
);

$resolved_detail = approval_s01_detail(
	'reviewed',
	true,
	false,
	array( $finding_a ),
	array( approval_s01_review_item( $finding_a['finding_key'], 'resolved' ) )
);
$resolved        = $gate->decide( $validation, $optional, $resolved_detail );
approval_s01_same( false, $resolved->approval_ready(), 'A resolved finding must require validation and evaluation of a new revision.' );
approval_s01_same(
	array( 'worksheet_evaluation_reassessment_required' ),
	approval_s01_codes( $resolved->blockers() ),
	'Reassessment blocker code drifted.'
);

$blocked_validation = $gate->decide( approval_s01_validation( 'draft', false ), $optional, null );
approval_s01_same( false, $blocked_validation->approval_ready(), 'A blocked validation must block approval.' );
approval_s01_same(
	array( 'worksheet_validation_not_ready' ),
	approval_s01_codes( $blocked_validation->blockers() ),
	'Validation blocker code drifted.'
);

$approved_revision = $gate->decide( approval_s01_validation( 'approved' ), $optional, null );
approval_s01_same( false, $approved_revision->approval_ready(), 'The approval gate accepts draft revisions only.' );
approval_s01_assert(
	in_array( 'worksheet_revision_not_draft', approval_s01_codes( $approved_revision->blockers() ), true ),
	'Non-draft revision blocker is missing.'
);

$inconsistent_validation = approval_s01_validation(
	'draft',
	true,
	array(
		array(
			'id'     => 'renderer_parity',
			'status' => 'pending',
		),
	)
);
$inconsistent            = $gate->decide( $inconsistent_validation, $optional, null );
approval_s01_assert(
	in_array( 'worksheet_validation_evidence_inconsistent', approval_s01_codes( $inconsistent->blockers() ), true ),
	'Inconsistent validation evidence must fail closed.'
);

$malformed               = approval_s01_detail( 'reviewed' );
$malformed['unexpected'] = true;
approval_s01_invalid(
	static fn () => $gate->decide( $validation, $required, $malformed ),
	'Unknown evaluation projection fields must fail closed.'
);

$source_drift = approval_s01_detail( 'reviewed' );
$source_drift['evaluation']['revision']['source_hash'] = approval_s01_hash( 'tampered-source' );
approval_s01_invalid(
	static fn () => $gate->decide( $validation, $required, $source_drift ),
	'The same revision ID with another source hash must fail closed.'
);

approval_s01_same( true, $required->review_required(), 'Human review must be a fixed policy value.' );
approval_s01_same( true, $required->explicit_release_required(), 'Explicit release must be a fixed policy value.' );
approval_s01_assert( 1 === preg_match( '/^[a-f0-9]{64}$/D', $required->content_hash() ), 'Policy content hash must be SHA-256.' );

$gate_source = file_get_contents(
	$addon_root . '/src/Worksheet/Authoring/Domain/Worksheet_Approval_Policy_Gate.php'
);
approval_s01_assert( is_string( $gate_source ), 'Approval gate source must be readable.' );
approval_s01_assert( ! str_contains( $gate_source, '$wpdb' ), 'S01 must remain storage-free.' );
approval_s01_assert( ! str_contains( $gate_source, 'register_rest_route' ), 'S01 must not open a REST route.' );
approval_s01_assert( ! str_contains( $gate_source, 'OJ\Platform\\' ), 'S01 must not depend on platform internals.' );

echo "G2-IMP-06-S01 worksheet approval policy gate contract passed.\n";
