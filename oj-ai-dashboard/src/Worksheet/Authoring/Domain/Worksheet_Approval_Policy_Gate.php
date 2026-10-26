<?php
/**
 * Pure approval policy gate for one exact worksheet draft revision.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Domain;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Application\Validate_Worksheet_Revision_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure approval policy gate for one exact worksheet draft revision.
 */
final class Worksheet_Approval_Policy_Gate {
	private const EVALUATION_STATES = array(
		'requested',
		'queued',
		'running',
		'review_required',
		'reviewed',
		'failed',
		'cancelled',
	);

	/**
	 * Initialize worksheet approval policy gate dependencies and state.
	 *
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct( private Canonical_Json $canonical ) {
	}

	/**
	 * Decide.
	 *
	 * @param Validate_Worksheet_Revision_Result $validation Validation.
	 * @param Worksheet_Approval_Policy          $policy Policy.
	 * @param array<string,mixed>|null           $evaluation_detail Evaluation detail.
	 * @return Worksheet_Approval_Decision
	 */
	public function decide(
		Validate_Worksheet_Revision_Result $validation,
		Worksheet_Approval_Policy $policy,
		?array $evaluation_detail
	): Worksheet_Approval_Decision {
		$blockers = array();
		$warnings = array();

		if ( 'draft' !== $validation->revision_state() ) {
			$blockers[] = 'worksheet_revision_not_draft';
		}
		if ( ! $validation->release_ready() || 'ready' !== $validation->status() ) {
			$blockers[] = 'worksheet_validation_not_ready';
		}
		if ( ! $this->validation_evidence_is_consistent( $validation ) ) {
			$blockers[] = 'worksheet_validation_evidence_inconsistent';
		}

		$evaluation_hash = null;
		if ( null === $evaluation_detail ) {
			if ( $policy->evaluation_required() ) {
				$blockers[] = 'worksheet_evaluation_required';
			} else {
				$warnings[] = 'worksheet_evaluation_not_run';
			}
		} else {
			$evaluation_hash = hash( 'sha256', $this->canonical->encode( $evaluation_detail ) );
			$evidence        = $this->evaluation_evidence( $validation, $evaluation_detail );
			$this->apply_evaluation_policy( $policy, $evidence, $blockers, $warnings );
		}

		return new Worksheet_Approval_Decision(
			$validation->worksheet_id(),
			$validation->revision_id(),
			$validation->document_hash(),
			$policy->revision_id(),
			$policy->content_hash(),
			$validation->projection_hash(),
			$evaluation_hash,
			$blockers,
			$warnings,
			$this->canonical
		);
	}

	/**
	 * Validation evidence is consistent.
	 *
	 * @param Validate_Worksheet_Revision_Result $validation Validation.
	 * @return bool
	 */
	private function validation_evidence_is_consistent( Validate_Worksheet_Revision_Result $validation ): bool {
		if ( $validation->release_ready() !== ( 'ready' === $validation->status() ) ) {
			return false;
		}
		if ( $validation->release_ready() && array() !== $validation->blockers() ) {
			return false;
		}

		$ids = array();
		foreach ( $validation->checks() as $check ) {
			if ( ! is_array( $check ) || array_is_list( $check ) ) {
				return false;
			}
			$id     = $check['id'] ?? null;
			$status = $check['status'] ?? null;
			if (
				! is_string( $id )
				|| 1 !== preg_match( '/^[a-z][a-z0-9_]{2,63}$/D', $id )
				|| isset( $ids[ $id ] )
				|| ! is_string( $status )
				|| ! in_array( $status, array( 'passed', 'pending', 'blocked' ), true )
			) {
				return false;
			}
			$ids[ $id ] = true;
			if ( $validation->release_ready() && 'passed' !== $status ) {
				return false;
			}
		}
		return array() !== $ids;
	}

	/**
	 * Evaluation evidence.
	 *
	 * @param Validate_Worksheet_Revision_Result $validation Validation.
	 * @param array<string,mixed>                $detail Detail.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function evaluation_evidence(
		Validate_Worksheet_Revision_Result $validation,
		array $detail
	): array {
		$this->exact_keys( $detail, array( 'evaluation', 'result', 'runs', 'reviews' ) );
		$evaluation = $this->object( $detail['evaluation'] ?? null );
		$this->exact_keys(
			$evaluation,
			array( 'id', 'state', 'is_stale', 'worksheet', 'revision', 'status', 'latest_run', 'requested_at_utc', 'updated_at_utc' )
		);
		$evaluation_id = $this->public_id( $evaluation['id'] ?? null );
		$state         = $this->one_of( $evaluation['state'] ?? null, self::EVALUATION_STATES );
		$is_stale      = $this->boolean( $evaluation['is_stale'] ?? null );

		$worksheet = $this->object( $evaluation['worksheet'] ?? null );
		$this->exact_keys( $worksheet, array( 'id', 'title' ) );
		if ( ! hash_equals( $validation->worksheet_id(), $this->public_id( $worksheet['id'] ?? null ) ) ) {
			throw new InvalidArgumentException( 'Worksheet approval evaluation belongs to another worksheet.' );
		}

		$revision = $this->object( $evaluation['revision'] ?? null );
		$this->exact_keys( $revision, array( 'id', 'revision_no', 'state', 'source_hash', 'is_current' ) );
		$revision_id = $this->public_id( $revision['id'] ?? null );
		$source_hash = $this->sha256( $revision['source_hash'] ?? null );
		$is_current  = $this->boolean( $revision['is_current'] ?? null );
		if ( $is_stale === $is_current ) {
			throw new InvalidArgumentException( 'Worksheet approval evaluation stale flags are inconsistent.' );
		}
		if ( hash_equals( $validation->revision_id(), $revision_id ) && ! hash_equals( $validation->document_hash(), $source_hash ) ) {
			throw new InvalidArgumentException( 'Worksheet approval evaluation source hash drifted.' );
		}
		$exact = hash_equals( $validation->revision_id(), $revision_id )
			&& hash_equals( $validation->document_hash(), $source_hash )
			&& $is_current
			&& ! $is_stale;

		$status = $this->object( $evaluation['status'] ?? null );
		$this->exact_keys(
			$status,
			array( 'overall_status', 'finding_count', 'needs_attention_count', 'safety_flagged', 'latest_review_no', 'next_action' )
		);
		$finding_count    = $this->non_negative_integer( $status['finding_count'] ?? null );
		$safety_flagged   = $this->boolean( $status['safety_flagged'] ?? null );
		$latest_review_no = $this->nullable_positive_integer( $status['latest_review_no'] ?? null );

		if ( ! is_array( $detail['runs'] ?? null ) || ! array_is_list( $detail['runs'] ) || array() === $detail['runs'] ) {
			throw new InvalidArgumentException( 'Worksheet approval evaluation run evidence is invalid.' );
		}

		$result       = $detail['result'] ?? null;
		$finding_keys = array();
		if ( null !== $result ) {
			$result = $this->object( $result );
			$this->exact_keys(
				$result,
				array(
					'id',
					'run_id',
					'overall_status',
					'overall_confidence',
					'summary',
					'abstained',
					'safety_flagged',
					'abstain_reason',
					'dimensions',
					'findings',
					'limitations',
					'created_at_utc',
				)
			);
			$this->public_id( $result['id'] ?? null );
			$findings = $result['findings'] ?? null;
			if ( ! is_array( $findings ) || ! array_is_list( $findings ) || count( $findings ) > 100 ) {
				throw new InvalidArgumentException( 'Worksheet approval evaluation findings are invalid.' );
			}
			foreach ( $findings as $finding ) {
				$finding = $this->object( $finding );
				$key     = $this->sha256( $finding['finding_key'] ?? null );
				if ( isset( $finding_keys[ $key ] ) ) {
					throw new InvalidArgumentException( 'Worksheet approval evaluation finding is duplicated.' );
				}
				$finding_keys[ $key ] = true;
			}
			if (
				$finding_count !== count( $finding_keys )
				|| $safety_flagged !== $this->boolean( $result['safety_flagged'] ?? null )
				|| $status['overall_status'] !== ( $result['overall_status'] ?? null )
			) {
				throw new InvalidArgumentException( 'Worksheet approval evaluation result summary drifted.' );
			}
		} elseif ( 0 !== $finding_count || $safety_flagged || null !== ( $status['overall_status'] ?? null ) ) {
			throw new InvalidArgumentException( 'Worksheet approval evaluation result is missing.' );
		}

		$reviews       = $this->review_evidence( $detail['reviews'] ?? null, array_keys( $finding_keys ) );
		$latest_review = array() === $reviews ? null : $reviews[ count( $reviews ) - 1 ];
		if ( null === $result && array() !== $reviews ) {
			throw new InvalidArgumentException( 'Worksheet approval evaluation reviews have no result.' );
		}
		if (
			( null === $latest_review && null !== $latest_review_no )
			|| ( null !== $latest_review && $latest_review['review_no'] !== $latest_review_no )
		) {
			throw new InvalidArgumentException( 'Worksheet approval evaluation review summary drifted.' );
		}
		if ( in_array( $state, array( 'review_required', 'reviewed' ), true ) && null === $result ) {
			throw new InvalidArgumentException( 'Worksheet approval reviewed evaluation has no result.' );
		}
		if ( 'reviewed' === $state && ( null === $latest_review || 'completed' !== $latest_review['decision'] ) ) {
			throw new InvalidArgumentException( 'Worksheet approval reviewed evaluation has no completed review.' );
		}

		$has_resolved  = false;
		$has_dismissed = false;
		if ( null !== $latest_review && 'completed' === $latest_review['decision'] ) {
			$decisions             = array_column( $latest_review['item_decisions'], 'decision', 'finding_key' );
			$expected_finding_keys = array_keys( $finding_keys );
			$decided_finding_keys  = array_keys( $decisions );
			sort( $expected_finding_keys, SORT_STRING );
			sort( $decided_finding_keys, SORT_STRING );
			if ( $expected_finding_keys !== $decided_finding_keys ) {
				throw new InvalidArgumentException( 'Worksheet approval completed review is incomplete.' );
			}
			$has_resolved  = in_array( 'resolved', $decisions, true );
			$has_dismissed = in_array( 'dismissed', $decisions, true );
		}

		return array(
			'evaluation_id'   => $evaluation_id,
			'state'           => $state,
			'exact'           => $exact,
			'has_result'      => null !== $result,
			'safety_flagged'  => $safety_flagged,
			'review_complete' => null !== $latest_review && 'completed' === $latest_review['decision'],
			'has_resolved'    => $has_resolved,
			'has_dismissed'   => $has_dismissed,
		);
	}

	/**
	 * Review evidence.
	 *
	 * @param mixed             $value Value.
	 * @param array<int,string> $finding_keys Finding keys.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function review_evidence( $value, array $finding_keys ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > 100 ) {
			throw new InvalidArgumentException( 'Worksheet approval evaluation reviews are invalid.' );
		}
		$allowed     = array_fill_keys( $finding_keys, true );
		$reviews     = array();
		$previous_id = null;
		foreach ( $value as $index => $review ) {
			$review = $this->object( $review );
			$this->exact_keys(
				$review,
				array( 'id', 'review_no', 'decision', 'note', 'item_decisions', 'previous_id', 'created_at_utc' )
			);
			$id        = $this->public_id( $review['id'] ?? null );
			$review_no = $this->positive_integer( $review['review_no'] ?? null );
			$previous  = null === ( $review['previous_id'] ?? null ) ? null : $this->public_id( $review['previous_id'] );
			if ( $review_no !== $index + 1 || $previous !== $previous_id ) {
				throw new InvalidArgumentException( 'Worksheet approval evaluation review chain is invalid.' );
			}
			$decision = $this->one_of( $review['decision'] ?? null, array( 'in_progress', 'completed' ) );
			$items    = $review['item_decisions'] ?? null;
			if ( ! is_array( $items ) || ! array_is_list( $items ) || count( $items ) > 100 ) {
				throw new InvalidArgumentException( 'Worksheet approval review items are invalid.' );
			}
			$normalized_items = array();
			foreach ( $items as $item ) {
				$item = $this->review_item( $item, $allowed );
				if ( isset( $normalized_items[ $item['finding_key'] ] ) ) {
					throw new InvalidArgumentException( 'Worksheet approval review decision is duplicated.' );
				}
				$normalized_items[ $item['finding_key'] ] = $item;
				if ( 'completed' === $decision && 'deferred' === $item['decision'] ) {
					throw new InvalidArgumentException( 'Worksheet approval completed review contains a deferred finding.' );
				}
			}
			ksort( $normalized_items, SORT_STRING );
			$reviews[]   = array(
				'id'             => $id,
				'review_no'      => $review_no,
				'decision'       => $decision,
				'item_decisions' => array_values( $normalized_items ),
			);
			$previous_id = $id;
		}
		return $reviews;
	}

	/**
	 * Review item.
	 *
	 * @param mixed              $value Value.
	 * @param array<string,bool> $allowed Allowed.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function review_item( $value, array $allowed ): array {
		$item = $this->object( $value );
		$this->exact_keys(
			$item,
			array( 'finding_key', 'decision', 'reason_code', 'note', 'resolution_revision_id', 'resolution_source_hash' )
		);
		$key      = $this->sha256( $item['finding_key'] ?? null );
		$decision = $this->one_of( $item['decision'] ?? null, array( 'resolved', 'deferred', 'dismissed' ) );
		if ( ! isset( $allowed[ $key ] ) ) {
			throw new InvalidArgumentException( 'Worksheet approval review refers to an unknown finding.' );
		}
		$reason          = $item['reason_code'] ?? null;
		$note            = $item['note'] ?? null;
		$resolution_id   = $item['resolution_revision_id'] ?? null;
		$resolution_hash = $item['resolution_source_hash'] ?? null;
		if ( 'resolved' === $decision ) {
			$this->public_id( $resolution_id );
			$this->sha256( $resolution_hash );
		} elseif (
			! is_string( $reason )
			|| 1 !== preg_match( '/^[a-z][a-z0-9_.-]{0,63}$/D', $reason )
			|| ! is_string( $note )
			|| '' === trim( $note )
			|| null !== $resolution_id
			|| null !== $resolution_hash
		) {
			throw new InvalidArgumentException( 'Worksheet approval review rationale is invalid.' );
		}
		return array(
			'finding_key' => $key,
			'decision'    => $decision,
		);
	}

	/**
	 * Apply evaluation policy.
	 *
	 * @param Worksheet_Approval_Policy $policy Policy.
	 * @param array<string,mixed>       $evidence Evidence.
	 * @param array<int,string>         &$blockers Blockers.
	 * @param array<int,string>         &$warnings Warnings.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function apply_evaluation_policy(
		Worksheet_Approval_Policy $policy,
		array $evidence,
		array &$blockers,
		array &$warnings
	): void {
		$state = $evidence['state'];
		if ( ! $evidence['exact'] ) {
			if ( $policy->evaluation_required() ) {
				$blockers[] = 'worksheet_evaluation_stale';
			} else {
				$warnings[] = 'worksheet_evaluation_stale';
			}
		}

		if ( $evidence['safety_flagged'] && ! $evidence['review_complete'] ) {
			$blockers[] = 'worksheet_evaluation_safety_review_required';
		}
		if ( in_array( $state, array( 'requested', 'queued', 'running' ), true ) ) {
			$blockers[] = 'worksheet_evaluation_in_progress';
			return;
		}
		if ( 'failed' === $state || 'cancelled' === $state ) {
			if ( $policy->evaluation_required() ) {
				$blockers[] = 'worksheet_evaluation_required';
			} else {
				$warnings[] = 'failed' === $state
					? 'worksheet_evaluation_failed'
					: 'worksheet_evaluation_cancelled';
			}
			if ( $evidence['has_result'] && ! $evidence['review_complete'] ) {
				$blockers[] = 'worksheet_evaluation_review_required';
			}
			if ( $evidence['review_complete'] ) {
				$this->apply_review_outcomes( $policy, $evidence, $blockers );
			}
			return;
		}

		if ( 'review_required' === $state ) {
			$blockers[] = 'worksheet_evaluation_review_required';
			return;
		}
		if ( 'reviewed' !== $state ) {
			throw new InvalidArgumentException( 'Worksheet approval evaluation state is unsupported.' );
		}
		if ( ! $evidence['review_complete'] ) {
			$blockers[] = 'worksheet_evaluation_review_incomplete';
		}
		$this->apply_review_outcomes( $policy, $evidence, $blockers );
	}

	/**
	 * Apply review outcomes.
	 *
	 * @param Worksheet_Approval_Policy $policy Policy.
	 * @param array<string,mixed>       $evidence Evidence.
	 * @param array<int,string>         &$blockers Blockers.
	 * @return void
	 */
	private function apply_review_outcomes(
		Worksheet_Approval_Policy $policy,
		array $evidence,
		array &$blockers
	): void {
		if ( $evidence['has_resolved'] ) {
			$blockers[] = 'worksheet_evaluation_reassessment_required';
		}
		if ( $evidence['has_dismissed'] && ! $policy->allow_finding_dismissal() ) {
			$blockers[] = 'worksheet_evaluation_dismissal_not_allowed';
		}
	}

	/**
	 * Object.
	 *
	 * @param mixed $value Value.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function object( $value ): array {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			throw new InvalidArgumentException( 'Worksheet approval evaluation object is invalid.' );
		}
		return $value;
	}

	/**
	 * Exact keys.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $expected Expected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function exact_keys( array $value, array $expected ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'Worksheet approval evaluation fields are invalid.' );
		}
	}

	/**
	 * Public id.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function public_id( $value ): string {
		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( 'Worksheet approval public ID is invalid.' );
		}
		new Public_Id( $value );
		return $value;
	}

	/**
	 * Sha256.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function sha256( $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Worksheet approval SHA-256 value is invalid.' );
		}
		return $value;
	}

	/**
	 * One of.
	 *
	 * @param mixed             $value Value.
	 * @param array<int,string> $allowed Allowed.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function one_of( $value, array $allowed ): string {
		if ( ! is_string( $value ) || ! in_array( $value, $allowed, true ) ) {
			throw new InvalidArgumentException( 'Worksheet approval enumeration value is invalid.' );
		}
		return $value;
	}

	/**
	 * Boolean.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function boolean( $value ): bool {
		if ( ! is_bool( $value ) ) {
			throw new InvalidArgumentException( 'Worksheet approval boolean value is invalid.' );
		}
		return $value;
	}

	/**
	 * Positive integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function positive_integer( $value ): int {
		if ( ! is_int( $value ) || $value < 1 ) {
			throw new InvalidArgumentException( 'Worksheet approval positive integer is invalid.' );
		}
		return $value;
	}

	/**
	 * Non negative integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function non_negative_integer( $value ): int {
		if ( ! is_int( $value ) || $value < 0 ) {
			throw new InvalidArgumentException( 'Worksheet approval non-negative integer is invalid.' );
		}
		return $value;
	}

	/**
	 * Nullable positive integer.
	 *
	 * @param mixed $value Value.
	 * @return int|null
	 */
	private function nullable_positive_integer( $value ): ?int {
		return null === $value ? null : $this->positive_integer( $value );
	}
}
