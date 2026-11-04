<?php
/**
 * Transactional teacher grades on assignment targets, never on fabricated answers.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Infrastructure;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use OJAI\Dashboard\Assessment\Configuration\Domain\Rubric_Exception_Scores;
use OJAI\Dashboard\Submission\Assessment\Application\Manage_Non_Submission_Grade;
use OJAI\Dashboard\Submission\Assessment\Port\Non_Submission_Grade_Repository_Port_Interface;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Owns target grade snapshots and their transactional audit/replay records. */
final class WordPress_Non_Submission_Grade_Repository implements Non_Submission_Grade_Repository_Port_Interface {
	private const SCHEMA        = 'assignment_target_grade';
	private const EVENT         = 'assignment_target.grade_recorded';
	private const POLICY_SCHEMA = 'assignment_exception_policy';
	private const POLICY_EVENT  = 'assignment.exception_policy_saved';

	/**
	 * Initialize owned storage dependencies.
	 *
	 * @param Canonical_Json      $canonical Stable JSON encoder.
	 * @param Public_Id_Generator $ids Public identifiers.
	 * @param object|null         $database WordPress database or contract adapter.
	 */
	public function __construct( private Canonical_Json $canonical, private Public_Id_Generator $ids, private ?object $database = null ) {
	}

	/**
	 * Read teacher-only grade projections after context authorization.
	 *
	 * @param array $scope Exact authorized assignment scope.
	 * @return array
	 * @throws RuntimeException Rejects invalid stored grade scope.
	 */
	public function read( array $scope ): array {
		$assignment = $this->assignment( $scope, false );
		$limits     = $this->limits( $assignment );
		$rows       = $this->rows( $this->prepare( 'SELECT t.*, s.official_revision_public_id FROM oj_ai_assignment_targets t LEFT JOIN oj_ai_submissions s ON s.assignment_id = t.assignment_id AND s.learner_user_id = t.learner_user_id WHERE t.assignment_id = %d', array( $assignment['id'] ) ) );
		$result     = array();
		foreach ( $rows as $row ) {
			if ( 'active' !== $row['state'] || ! empty( $row['official_revision_public_id'] ) ) {
				continue;
			}
			$grade = $this->grade( $row, $limits );
			if ( null !== $grade && ( $grade['assignment_id'] !== $scope['assignment_id'] || $grade['target_id'] !== $row['public_id'] || $grade['publication_hash'] !== $limits['publication_hash'] ) ) {
				throw new RuntimeException( 'Stored target grade scope is invalid.' );
			}
			$result[ $row['public_id'] ] = array(
				'grading' => $limits + array( 'allowed' => in_array( $assignment['state'], array( 'open', 'closed' ), true ) ),
				'grade'   => $grade,
			);
		}
		return $result;
	}

	/**
	 * Lock the target and recheck official submission state before recording a grade.
	 *
	 * @param array $record Validated server command.
	 * @return array
	 * @throws Throwable Rolls back every partially written decision.
	 */
	public function save( array $record ): array {
		$this->query( 'START TRANSACTION' );
		try {
			$assignment = $this->assignment( $record['scope'], true );
			$input      = $record['input'];
			$target     = $this->row( $this->prepare( 'SELECT * FROM oj_ai_assignment_targets WHERE assignment_id = %d AND public_id = %s LIMIT 1 FOR UPDATE', array( $assignment['id'], $input['target_id'] ) ) );
			if ( null === $target || 'active' !== $target['state'] || (int) $target['learner_user_id'] < 1 ) {
				$this->conflict();
			}
			$receipt = $this->row( $this->prepare( 'SELECT * FROM oj_ai_outbox_events WHERE idempotency_key = %s LIMIT 1 FOR UPDATE', array( $record['storage_key'] ) ) );
			if ( null !== $receipt ) {
				$payload = $this->decode( $receipt['payload_json'], $receipt['payload_hash'] );
				if ( self::EVENT !== $receipt['event_type'] || ! hash_equals( $record['request_digest'], (string) ( $payload['request_digest'] ?? '' ) ) ) {
					$this->conflict();
				}
				$this->query( 'COMMIT' );
				return array(
					'grade'    => $payload['grade'],
					'replayed' => true,
				);
			}
			$submission = $this->row( $this->prepare( 'SELECT official_revision_public_id FROM oj_ai_submissions WHERE assignment_id = %d AND learner_user_id = %d LIMIT 1 FOR UPDATE', array( $assignment['id'], $target['learner_user_id'] ) ) );
			if ( ! in_array( $assignment['state'], array( 'open', 'closed' ), true ) || ! empty( $submission['official_revision_public_id'] ) ) {
				$this->conflict();
			}
			$limits = $this->limits( $assignment );
			$before = $this->grade( $target, $limits );
			if ( null !== $before && ( $before['assignment_id'] !== $record['scope']['assignment_id'] || $before['target_id'] !== $target['public_id'] || $before['publication_hash'] !== $limits['publication_hash'] || Manage_Non_Submission_Grade::units( $before['maximum_score'] ) !== Manage_Non_Submission_Grade::units( $limits['maximum_score'] ) ) ) {
				throw new RuntimeException( 'Stored target grade scope is invalid.' );
			}
			if ( $input['publication_hash'] !== $limits['publication_hash'] || $input['expected_hash'] !== ( $before['hash'] ?? null ) ) {
				$this->conflict();
			}
			if ( 'withdraw' === $input['action'] ) {
				if ( 'confirmed' !== ( $before['status'] ?? null ) ) {
					$this->conflict();
				}
				$grade           = $before;
				$grade['status'] = 'withdrawn';
			} else {
				if ( 'confirmed' === ( $before['status'] ?? null ) ) {
					$this->conflict();
				}
				if ( Manage_Non_Submission_Grade::units( $input['score'] ) > Manage_Non_Submission_Grade::units( $limits['maximum_score'] ) ) {
					throw new InvalidArgumentException( 'Grade exceeds the published maximum.' );
				}
				if ( 'rubric' !== $input['category'] && ( $input['exception_policy_hash'] ?? null ) !== ( $limits['exception_policy']['hash'] ?? null ) ) {
					$this->conflict();
				}
				$items = $this->decision_items( $input, $limits );
				$grade = array(
					'assignment_id'    => $record['scope']['assignment_id'],
					'target_id'        => $input['target_id'],
					'publication_hash' => $limits['publication_hash'],
					'status'           => 'confirmed',
					'category'         => $input['category'],
					'score'            => $input['score'],
					'maximum_score'    => $limits['maximum_score'],
					'memo'             => $input['memo'],
					'items'            => $items,
				);
				if ( 'rubric' !== $input['category'] && null !== $limits['exception_policy'] ) {
					$grade['exception_policy'] = $limits['exception_policy'];
				}
			}
			unset( $grade['hash'] );
			$grade['revision']       = ( $before['revision'] ?? 0 ) + 1;
			$grade['actor_user_id']  = $record['actor_user_id'];
			$grade['updated_at_utc'] = $record['occurred_at_utc'];
			$grade['previous_hash']  = $before['hash'] ?? null;
			$json                    = $this->canonical->encode( $grade );
			$hash                    = hash( 'sha256', $json );
			$changed                 = $this->db()->update(
				'oj_ai_assignment_targets',
				array(
					'non_submission_grade_schema'  => self::SCHEMA,
					'non_submission_grade_version' => isset( $grade['exception_policy'] ) ? 3 : ( array_key_exists( 'items', $grade ) ? 2 : 1 ),
					'non_submission_grade_json'    => $json,
					'non_submission_grade_hash'    => $hash,
				),
				array(
					'id'                        => $target['id'],
					'non_submission_grade_hash' => $input['expected_hash'],
				)
			);
			if ( 1 !== $changed ) {
				$this->conflict();
			}
			$grade['hash'] = $hash;
			$this->audit( $record, $before, $grade );
			$this->receipt( $record, $grade );
			$this->query( 'COMMIT' );
			return array(
				'grade'    => $grade,
				'replayed' => false,
			);
		} catch ( Throwable $failure ) {
			$this->db()->query( 'ROLLBACK' );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Boundary maps stable errors.
			throw $failure;
		}
	}

	/**
	 * Save only the assignment policy, retaining all target grade snapshots.
	 *
	 * @param array $record Validated teacher command.
	 * @return array
	 * @throws Throwable Rolls back settings if audit or receipt persistence fails.
	 */
	public function save_policy( array $record ): array {
		$this->query( 'START TRANSACTION' );
		try {
			$assignment = $this->assignment( $record['scope'], true );
			$input      = $record['input'];
			$receipt    = $this->row( $this->prepare( 'SELECT * FROM oj_ai_outbox_events WHERE idempotency_key = %s LIMIT 1 FOR UPDATE', array( $record['storage_key'] ) ) );
			if ( null !== $receipt ) {
				$payload = $this->decode( $receipt['payload_json'], $receipt['payload_hash'] );
				if ( self::POLICY_EVENT !== $receipt['event_type'] || ! hash_equals( $record['request_digest'], (string) ( $payload['request_digest'] ?? '' ) ) ) {
					$this->conflict();
				}
				$this->query( 'COMMIT' );
				return array(
					'policy'   => $payload['policy'],
					'replayed' => true,
				);
			}
			$limits = $this->limits( $assignment );
			$before = $limits['exception_policy'];
			if ( ! in_array( $assignment['state'], array( 'open', 'closed' ), true ) || $input['publication_hash'] !== $limits['publication_hash'] || $input['expected_hash'] !== ( $before['hash'] ?? null ) ) {
				$this->conflict();
			}
			$policy         = array(
				'assignment_id'    => $assignment['public_id'],
				'publication_hash' => $limits['publication_hash'],
				'scores'           => Rubric_Exception_Scores::normalize( $input['scores'], $limits['maximum_score'] ),
				'reason'           => $input['reason'],
				'revision'         => ( $before['revision'] ?? 0 ) + 1,
				'actor_user_id'    => $record['actor_user_id'],
				'updated_at_utc'   => $record['occurred_at_utc'],
				'previous_hash'    => $before['hash'] ?? null,
			);
			$json           = $this->canonical->encode( $policy );
			$policy['hash'] = hash( 'sha256', $json );
			$changed        = $this->db()->update(
				'oj_ai_assignments',
				array(
					'exception_policy_schema'  => self::POLICY_SCHEMA,
					'exception_policy_version' => 1,
					'exception_policy_json'    => $json,
					'exception_policy_hash'    => $policy['hash'],
				),
				array(
					'id'                    => $assignment['id'],
					'exception_policy_hash' => $input['expected_hash'],
				)
			);
			if ( 1 !== $changed ) {
				$this->conflict();
			}
			$this->audit( $record, $before, $policy, true );
			$this->receipt( $record, $policy, true );
			$this->query( 'COMMIT' );
			return array(
				'policy'   => $policy,
				'replayed' => false,
			);
		} catch ( Throwable $failure ) {
			$this->db()->query( 'ROLLBACK' );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Boundary maps stable errors.
			throw $failure;
		}
	}

	/**
	 * Read the exact context assignment.
	 *
	 * @param array $scope Authorized scope.
	 * @param bool  $lock Lock current state.
	 * @return array
	 */
	private function assignment( array $scope, bool $lock ): array {
		$row = $this->row( $this->prepare( 'SELECT * FROM oj_ai_assignments WHERE public_id = %s AND context_type = %s AND context_id = %s AND context_revision = %s LIMIT 1' . ( $lock ? ' FOR UPDATE' : '' ), array( $scope['assignment_id'], $scope['context_type'], $scope['context_id'], $scope['context_revision'] ) ) );
		if ( null === $row ) {
			$this->conflict();
		}
		return $row;
	}

	/**
	 * Read the immutable publication's actual bound maximum, not a browser value.
	 *
	 * @param array $assignment Assignment row.
	 * @return array
	 * @throws RuntimeException Rejects corrupted published grading limits.
	 */
	private function limits( array $assignment ): array {
		$row = $this->row( $this->prepare( 'SELECT content_hash, publication_hash, grading_schema_schema, grading_schema_version, grading_schema_json, grading_schema_hash FROM oj_ai_worksheet_publications WHERE public_id = %s LIMIT 1', array( $assignment['worksheet_publication_public_id'] ) ) );
		if ( null === $row || $assignment['publication_hash'] !== $row['publication_hash'] || $row['content_hash'] !== $row['publication_hash'] || 1 !== (int) $row['grading_schema_version'] ) {
			throw new RuntimeException( 'Published grading profile is invalid.' );
		}
		$profile = $this->decode( $row['grading_schema_json'], $row['grading_schema_hash'] );
		$payload = $profile['payload'] ?? null;
		if ( ! is_array( $payload ) || ( $profile['schema_id'] ?? null ) !== $row['grading_schema_schema'] || hash( 'sha256', $this->canonical->encode( $payload ) ) !== ( $profile['payload_hash'] ?? null ) ) {
			throw new RuntimeException( 'Published grading payload is invalid.' );
		}
		$maximum = $payload['rubric']['total_points'] ?? null;
		if ( ! is_string( $maximum ) || Manage_Non_Submission_Grade::units( $maximum ) < 1 ) {
			throw new RuntimeException( 'Published maximum is invalid.' );
		}
		$published = Rubric_Exception_Scores::normalize(
			$payload['rubric']['exception_scores'] ?? array(
				'not_submitted' => null,
				'long_absence'  => null,
			),
			$maximum
		);
		$policy    = null;
		if ( null !== $assignment['exception_policy_hash'] || null !== $assignment['exception_policy_json'] || null !== $assignment['exception_policy_schema'] || null !== $assignment['exception_policy_version'] ) {
			if ( self::POLICY_SCHEMA !== $assignment['exception_policy_schema'] || 1 !== (int) $assignment['exception_policy_version'] ) {
				throw new RuntimeException( 'Stored exception policy schema is invalid.' );
			}
			$policy = $this->decode( (string) $assignment['exception_policy_json'], (string) $assignment['exception_policy_hash'] ) + array( 'hash' => $assignment['exception_policy_hash'] );
			$this->validate_policy( $policy, $assignment['public_id'], $assignment['publication_hash'], $maximum );
		}
		return array(
			'maximum_score'              => $maximum,
			'publication_hash'           => $assignment['publication_hash'],
			'bindings'                   => $payload['bindings'] ?? array(),
			'published_exception_scores' => $published,
			'exception_scores'           => $policy['scores'] ?? $published,
			'exception_policy'           => $policy,
		);
	}

	/**
	 * Validate both current settings and historical settings pinned to a grade.
	 *
	 * @param array  $policy Hash-checked policy snapshot.
	 * @param string $assignment Assignment public identifier.
	 * @param string $publication Publication hash.
	 * @param string $maximum Published points.
	 * @return void
	 * @throws RuntimeException Rejects a corrupt or foreign snapshot.
	 */
	private function validate_policy( array $policy, string $assignment, string $publication, string $maximum ): void {
		$keys = array( 'assignment_id', 'publication_hash', 'scores', 'reason', 'revision', 'actor_user_id', 'updated_at_utc', 'previous_hash', 'hash' );
		$hash = $policy['hash'] ?? null;
		if ( array_diff( $keys, array_keys( $policy ) ) || array_diff( array_keys( $policy ), $keys )
			|| $policy['assignment_id'] !== $assignment || $policy['publication_hash'] !== $publication
			|| ! is_int( $policy['revision'] ) || $policy['revision'] < 1 || ! is_int( $policy['actor_user_id'] ) || $policy['actor_user_id'] < 1
			|| ! is_string( $policy['reason'] ) || '' === trim( $policy['reason'] ) || mb_strlen( $policy['reason'], 'UTF-8' ) > 500
			|| ! is_string( $policy['updated_at_utc'] ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $policy['updated_at_utc'] )
			|| ( null !== $policy['previous_hash'] && ( ! is_string( $policy['previous_hash'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $policy['previous_hash'] ) ) ) ) {
			throw new RuntimeException( 'Stored exception policy is invalid.' );
		}
		Rubric_Exception_Scores::normalize( $policy['scores'], $maximum );
		unset( $policy['hash'] );
		if ( ! is_string( $hash ) || hash( 'sha256', $this->canonical->encode( $policy ) ) !== $hash ) {
			throw new RuntimeException( 'Stored exception policy hash is invalid.' );
		}
	}

	/**
	 * Derive the score from immutable bindings or one configured exception.
	 *
	 * @param array $input Requested decision.
	 * @param array $limits Published grading configuration.
	 * @return array
	 * @throws InvalidArgumentException Rejects mixed, incomplete or altered scores.
	 */
	private function decision_items( array $input, array $limits ): array {
		$items = $input['items'] ?? array();
		if ( ! is_array( $items ) || ! array_is_list( $items ) ) {
			throw new InvalidArgumentException( 'Invalid rubric decisions.' );
		}
		if ( 'rubric' !== $input['category'] ) {
			$score = $limits['exception_scores'][ $input['category'] ] ?? null;
			if ( null === $score || array() !== $items || Manage_Non_Submission_Grade::units( $input['score'] ) !== Manage_Non_Submission_Grade::units( $score ) ) {
				throw new InvalidArgumentException( 'Invalid or mixed exception score.' );
			}
			return array();
		}
		$bindings = array_column( $limits['bindings'], null, 'binding_key' );
		if ( array() === $bindings || count( $bindings ) !== count( $items ) ) {
			throw new InvalidArgumentException( 'Every published criterion requires a decision.' );
		}
		$total = 0;
		$seen  = array();
		foreach ( $items as $item ) {
			$keys = array( 'binding_key', 'level_key', 'score' );
			if ( ! is_array( $item ) || array_diff( array_keys( $item ), $keys ) || array_diff( $keys, array_keys( $item ) ) || ! is_string( $item['binding_key'] ) || ! is_string( $item['score'] ) || ( null !== $item['level_key'] && ! is_string( $item['level_key'] ) ) ) {
				throw new InvalidArgumentException( 'Invalid criterion decision.' );
			}
			$key = $item['binding_key'];
			if ( ! isset( $bindings[ $key ] ) || isset( $seen[ $key ] ) ) {
				throw new InvalidArgumentException( 'Unknown or duplicate criterion.' );
			}
			$binding = $bindings[ $key ];
			$points  = Manage_Non_Submission_Grade::units( $item['score'] );
			$levels  = array_column( $binding['levels'] ?? array(), 'points', 'level_key' );
			if ( $points > Manage_Non_Submission_Grade::units( $binding['maximum_points'] )
				|| ( array() !== $levels && ( ! isset( $levels[ $item['level_key'] ?? '' ] ) || $points !== Manage_Non_Submission_Grade::units( $levels[ $item['level_key'] ] ) ) )
				|| ( array() === $levels && null !== $item['level_key'] ) ) {
				throw new InvalidArgumentException( 'Score does not match the published level.' );
			}
			$seen[ $key ] = true;
			$total       += $points;
		}
		if ( $total !== Manage_Non_Submission_Grade::units( $input['score'] ) || $total > Manage_Non_Submission_Grade::units( $limits['maximum_score'] ) ) {
			throw new InvalidArgumentException( 'Rubric total does not match its decisions.' );
		}
		return $items;
	}

	/**
	 * Verify and present a stored grade.
	 *
	 * @param array $row Target row.
	 * @param array $limits Immutable grading configuration.
	 * @return array|null
	 * @throws RuntimeException Rejects partial or corrupt grade snapshots.
	 */
	private function grade( array $row, array $limits ): ?array {
		if ( null === $row['non_submission_grade_hash'] && null === $row['non_submission_grade_json'] && null === $row['non_submission_grade_schema'] && null === $row['non_submission_grade_version'] ) {
			return null;
		}
		if ( self::SCHEMA !== $row['non_submission_grade_schema'] || ! in_array( (int) $row['non_submission_grade_version'], array( 1, 2, 3 ), true ) ) {
			throw new RuntimeException( 'Stored grade schema is invalid.' );
		}
		$grade   = $this->decode( (string) $row['non_submission_grade_json'], (string) $row['non_submission_grade_hash'] );
		$keys    = array( 'assignment_id', 'target_id', 'publication_hash', 'status', 'category', 'score', 'maximum_score', 'memo', 'revision', 'actor_user_id', 'updated_at_utc', 'previous_hash' );
		$version = (int) $row['non_submission_grade_version'];
		if ( $version >= 2 ) {
			$keys[] = 'items';
		}
		if ( 3 === $version ) {
			$keys[] = 'exception_policy';
		}
		if ( array_diff( $keys, array_keys( $grade ) ) || array_diff( array_keys( $grade ), $keys )
			|| ! in_array( $grade['status'] ?? null, array( 'confirmed', 'withdrawn' ), true )
			|| ! in_array( $grade['category'] ?? null, 1 === $version ? array( 'base', 'not_submitted' ) : array( 'rubric', 'not_submitted', 'long_absence' ), true )
			|| ! is_int( $grade['revision'] ) || $grade['revision'] < 1 || ! is_int( $grade['actor_user_id'] ) || $grade['actor_user_id'] < 1
			|| ! is_string( $grade['memo'] ) || mb_strlen( $grade['memo'], 'UTF-8' ) > 500
			|| ! is_string( $grade['score'] ?? null ) || ! is_string( $grade['maximum_score'] ?? null )
			|| Manage_Non_Submission_Grade::units( $grade['score'] ) > Manage_Non_Submission_Grade::units( $grade['maximum_score'] ) ) {
			throw new RuntimeException( 'Stored grade values are invalid.' );
		}
		if ( $version >= 2 ) {
			$historical                     = $limits;
			$historical['exception_scores'] = $limits['published_exception_scores'];
			if ( 3 === $version ) {
				if ( ! is_array( $grade['exception_policy'] ) || 'rubric' === $grade['category'] ) {
					throw new RuntimeException( 'Stored exception grade policy is invalid.' );
				}
				$this->validate_policy( $grade['exception_policy'], $grade['assignment_id'], $grade['publication_hash'], $grade['maximum_score'] );
				$historical['exception_scores'] = $grade['exception_policy']['scores'];
			}
			$this->decision_items( $grade, $historical );
		}
		return $grade + array( 'hash' => $row['non_submission_grade_hash'] );
	}

	/**
	 * Append a complete teacher decision to the shared audit chain.
	 *
	 * @param array      $record Server command.
	 * @param array|null $before Previous grade.
	 * @param array      $grade New grade.
	 * @param bool       $policy Assignment policy instead of a student decision.
	 * @return void
	 */
	private function audit( array $record, ?array $before, array $grade, bool $policy = false ): void {
		$previous = $this->row( 'SELECT event_hash FROM oj_ai_audit_events ORDER BY id DESC LIMIT 1 FOR UPDATE' );
		$metadata = $this->canonical->encode(
			array(
				'before'                         => $before,
				( $policy ? 'policy' : 'grade' ) => $grade,
				'request_digest'                 => $record['request_digest'],
			)
		);
		$event    = array(
			'action_key'          => $policy ? self::POLICY_EVENT : self::EVENT,
			'actor_user_id'       => $record['actor_user_id'],
			'after_digest'        => $grade['hash'],
			'before_digest'       => $before['hash'] ?? null,
			'correlation_id'      => $record['correlation_id'],
			'metadata_hash'       => hash( 'sha256', $metadata ),
			'object_public_id'    => $policy ? $grade['assignment_id'] : $grade['target_id'],
			'object_type'         => $policy ? 'assignment' : 'assignment_target',
			'occurred_at_utc'     => $record['occurred_at_utc'],
			'previous_event_hash' => $previous['event_hash'] ?? null,
		);
		$this->insert(
			'oj_ai_audit_events',
			$event + array(
				'public_id'        => $this->ids->create()->value(),
				'context_type'     => $record['scope']['context_type'],
				'context_id'       => $record['scope']['context_id'],
				'context_revision' => $record['scope']['context_revision'],
				'metadata_schema'  => ( $policy ? self::POLICY_SCHEMA : self::SCHEMA ) . '_audit',
				'metadata_version' => 1,
				'metadata_json'    => $metadata,
				'event_hash'       => hash( 'sha256', $this->canonical->encode( $event ) ),
			)
		);
	}

	/**
	 * Persist replay evidence as an already-completed local event, with no AI work.
	 *
	 * @param array $record Server command.
	 * @param array $grade Saved grade.
	 * @param bool  $policy Assignment policy instead of a student decision.
	 * @return void
	 */
	private function receipt( array $record, array $grade, bool $policy = false ): void {
		$json = $this->canonical->encode(
			array(
				'request_digest'                 => $record['request_digest'],
				( $policy ? 'policy' : 'grade' ) => $grade,
			)
		);
		$this->insert(
			'oj_ai_outbox_events',
			array(
				'public_id'           => $this->ids->create()->value(),
				'lock_version'        => 0,
				'created_by_user_id'  => $record['actor_user_id'],
				'created_at_utc'      => $record['occurred_at_utc'],
				'updated_by_user_id'  => $record['actor_user_id'],
				'updated_at_utc'      => $record['occurred_at_utc'],
				'aggregate_type'      => $policy ? 'assignment' : 'assignment_target',
				'aggregate_public_id' => $policy ? $grade['assignment_id'] : $grade['target_id'],
				'event_type'          => $policy ? self::POLICY_EVENT : self::EVENT,
				'idempotency_key'     => $record['storage_key'],
				'payload_schema'      => ( $policy ? self::POLICY_SCHEMA : self::SCHEMA ) . '_receipt',
				'payload_version'     => 1,
				'payload_json'        => $json,
				'payload_hash'        => hash( 'sha256', $json ),
				'available_at_utc'    => $record['occurred_at_utc'],
				'published_at_utc'    => $record['occurred_at_utc'],
				'attempt_count'       => 0,
			)
		);
	}

	/**
	 * Decode hash-checked canonical JSON.
	 *
	 * @param string $json Stored JSON.
	 * @param string $hash Stored hash.
	 * @return array
	 * @throws RuntimeException Rejects damaged data.
	 */
	private function decode( string $json, string $hash ): array {
		$value = json_decode( $json, true, 64, JSON_THROW_ON_ERROR );
		if ( ! is_array( $value ) || hash( 'sha256', $this->canonical->encode( $value ) ) !== $hash ) {
			throw new RuntimeException( 'Stored grade data is invalid.' );
		}
		return $value;
	}

	/**
	 * Reject a stale or ineligible target decision.
	 *
	 * @return never
	 * @throws Assignment_Failure Reports a stable conflict.
	 */
	private function conflict(): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Stable error object mapped by REST.
		throw Assignment_Failure::because( Assignment_Failure::STATE_CONFLICT );
	}

	/**
	 * Read a single owned row.
	 *
	 * @param string $sql Prepared SQL.
	 * @return array|null
	 */
	private function row( string $sql ): ?array {
		return $this->rows( $sql )[0] ?? null;
	}

	/**
	 * Read owned rows and reject database errors.
	 *
	 * @param string $sql Prepared SQL.
	 * @return array
	 * @throws RuntimeException Rejects unavailable database reads.
	 */
	private function rows( string $sql ): array {
		$db             = $this->db();
		$db->last_error = '';
		$rows           = $db->get_results( $sql, 'ARRAY_A' );
		if ( '' !== $db->last_error || ! is_array( $rows ) ) {
			throw new RuntimeException( 'Grade storage read failed.' );
		}
		return $rows;
	}

	/**
	 * Bind SQL values through WordPress.
	 *
	 * @param string $sql SQL template.
	 * @param array  $values Bound values.
	 * @return string
	 */
	private function prepare( string $sql, array $values ): string {
		return $this->db()->prepare( $sql, ...$values );
	}

	/**
	 * Execute a transaction command.
	 *
	 * @param string $sql Transaction command.
	 * @return void
	 * @throws RuntimeException Rejects transaction failures.
	 */
	private function query( string $sql ): void {
		if ( false === $this->db()->query( $sql ) ) {
			throw new RuntimeException( 'Grade transaction failed.' );
		}
	}

	/**
	 * Insert a mandatory history record.
	 *
	 * @param string $table Owned table.
	 * @param array  $data Stored row.
	 * @return void
	 * @throws RuntimeException Rejects partial writes.
	 */
	private function insert( string $table, array $data ): void {
		if ( 1 !== $this->db()->insert( $table, $data ) ) {
			throw new RuntimeException( 'Grade event write failed.' );
		}
	}

	/**
	 * Resolve the owned database connection.
	 *
	 * @return object
	 * @throws RuntimeException Rejects missing storage.
	 */
	private function db(): object {
		$db = $this->database ?? ( $GLOBALS['wpdb'] ?? null );
		if ( ! is_object( $db ) ) {
			throw new RuntimeException( 'Grade storage unavailable.' );
		}
		return $db;
	}
}
