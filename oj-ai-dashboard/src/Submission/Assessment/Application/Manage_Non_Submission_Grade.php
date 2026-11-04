<?php
/**
 * Validate teacher-only non-submission grading commands.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Assessment\Configuration\Domain\Rubric_Exception_Scores;
use OJAI\Dashboard\Submission\Assessment\Port\Non_Submission_Grade_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Validates explicit teacher commands independently of AI and answer submission. */
final class Manage_Non_Submission_Grade {
	/**
	 * Initialize the grade store.
	 *
	 * @param Non_Submission_Grade_Repository_Port_Interface $repository Grade store.
	 * @param Canonical_Json                                 $canonical Stable digest encoder.
	 */
	public function __construct( private Non_Submission_Grade_Repository_Port_Interface $repository, private Canonical_Json $canonical ) {
	}

	/**
	 * Execute a command after REST context/object authorization.
	 *
	 * @param int    $actor Authenticated teacher.
	 * @param array  $scope Authorized context and assignment.
	 * @param array  $input Exact command body.
	 * @param string $idempotency Verified idempotency fingerprint.
	 * @param string $correlation Request correlation.
	 * @return array
	 * @throws InvalidArgumentException Rejects untrusted command values.
	 */
	public function execute( int $actor, array $scope, array $input, string $idempotency, string $correlation ): array {
		$keys = array( 'target_id', 'action', 'category', 'score', 'memo', 'expected_hash', 'publication_hash' );
		if ( array_key_exists( 'items', $input ) ) {
			$keys[] = 'items';
		}
		if ( array_key_exists( 'exception_policy_hash', $input ) ) {
			$keys[] = 'exception_policy_hash';
			if ( null !== $input['exception_policy_hash'] && ( ! is_string( $input['exception_policy_hash'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $input['exception_policy_hash'] ) ) ) {
				throw new InvalidArgumentException( 'Invalid exception policy hash.' );
			}
		}
		if ( array_diff( array_keys( $input ), $keys ) || array_diff( $keys, array_keys( $input ) ) || $actor < 1 || '' === $idempotency ) {
			throw new InvalidArgumentException( 'Invalid non-submission grade command.' );
		}
		foreach ( array( 'target_id', 'action', 'category', 'score', 'memo', 'publication_hash' ) as $key ) {
			if ( ! is_string( $input[ $key ] ) ) {
				throw new InvalidArgumentException( 'Invalid grade field.' );
			}
		}
		new Public_Id( $input['target_id'] );
		new Public_Id( $scope['assignment_id'] );
		if (
			! in_array( $input['action'], array( 'confirm', 'withdraw' ), true )
			|| ! in_array( $input['category'], array( 'base', 'rubric', 'not_submitted', 'long_absence' ), true )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $input['publication_hash'] )
			|| ( null !== $input['expected_hash'] && ( ! is_string( $input['expected_hash'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $input['expected_hash'] ) ) )
			|| mb_strlen( $input['memo'], 'UTF-8' ) > 500
			|| 1 !== preg_match( '//u', $input['memo'] )
		) {
			throw new InvalidArgumentException( 'Invalid grade values.' );
		}
		self::units( $input['score'] );
		$items = $input['items'] ?? array();
		if ( ! is_array( $items ) || ! array_is_list( $items ) || count( $items ) > 100 || ( 'rubric' !== $input['category'] && array() !== $items ) ) {
			throw new InvalidArgumentException( 'Rubric and exception scores cannot be combined.' );
		}
		if ( 'confirm' === $input['action'] && 'base' === $input['category'] ) {
			throw new InvalidArgumentException( 'Select the published rubric or a configured exception score.' );
		}
		$command = array(
			'actor_user_id' => $actor,
			'scope'         => $scope,
			'input'         => $input,
		);
		return $this->repository->save(
			$command + array(
				'request_digest'  => hash( 'sha256', $this->canonical->encode( $command ) ),
				'storage_key'     => 'target-grade:' . hash( 'sha256', $actor . ':' . $scope['assignment_id'] . ':' . $input['target_id'] . ':' . $idempotency ),
				'correlation_id'  => $correlation,
				'occurred_at_utc' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * Convert points to the publication's four-decimal units without rounding.
	 *
	 * @param string $value Decimal points.
	 * @return int
	 * @throws InvalidArgumentException Rejects invalid decimals.
	 */
	public static function units( string $value ): int {
		return Rubric_Exception_Scores::units( $value );
	}

	/**
	 * Supplement an authorized assignment's exception scores, never student grades.
	 *
	 * @param int    $actor Authenticated teacher.
	 * @param array  $scope Authorized assignment context.
	 * @param array  $input Exact command body.
	 * @param string $idempotency Verified request fingerprint.
	 * @param string $correlation Request correlation.
	 * @return array
	 * @throws InvalidArgumentException Rejects malformed settings.
	 */
	public function configure( int $actor, array $scope, array $input, string $idempotency, string $correlation ): array {
		$keys = array( 'scores', 'reason', 'expected_hash', 'publication_hash' );
		if ( array_diff( array_keys( $input ), $keys ) || array_diff( $keys, array_keys( $input ) ) || $actor < 1 || '' === $idempotency
			|| ! is_string( $input['reason'] ) || '' === trim( $input['reason'] ) || mb_strlen( $input['reason'], 'UTF-8' ) > 500 || 1 !== preg_match( '//u', $input['reason'] )
			|| ! is_string( $input['publication_hash'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $input['publication_hash'] )
			|| ( null !== $input['expected_hash'] && ( ! is_string( $input['expected_hash'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $input['expected_hash'] ) ) ) ) {
			throw new InvalidArgumentException( 'Invalid assignment exception policy.' );
		}
		new Public_Id( $scope['assignment_id'] );
		$input['scores'] = Rubric_Exception_Scores::normalize( $input['scores'], '99999999.9999' );
		$input['reason'] = trim( $input['reason'] );
		$command         = array(
			'actor_user_id' => $actor,
			'scope'         => $scope,
			'input'         => $input,
		);
		return $this->repository->save_policy(
			$command + array(
				'request_digest'  => hash( 'sha256', $this->canonical->encode( $command ) ),
				'storage_key'     => 'exception-policy:' . hash( 'sha256', $actor . ':' . $scope['assignment_id'] . ':' . $idempotency ),
				'correlation_id'  => $correlation,
				'occurred_at_utc' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}
}
