<?php
/**
 * Safe projection for one official submission awaiting evaluation.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Domain;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Safe projection for one official submission awaiting evaluation.
 */
final class Submission_Evaluation_Candidate_Projector {
	/**
	 * Initialize submission evaluation candidate projector dependencies and state.
	 *
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct( private Canonical_Json $canonical ) {
	}

	/**
	 * Project submission evaluation candidate projector.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function project( array $row ): array {
		try {
			if (
				'active' !== ( $row['target_state'] ?? null )
				|| 'withdrawn' === ( $row['assignment_state'] ?? null )
				|| ! in_array( $row['submission_state'] ?? null, array( 'submitted', 'auto_submitted', 'proxy_submitted', 'locked' ), true )
			) {
				throw new InvalidArgumentException( 'Submission evaluation candidate state is invalid.' );
			}

			$submission_id = $this->public_id( $row['submission_id'] ?? null );
			$revision_id   = $this->public_id( $row['official_revision_id'] ?? null );
			$revision_hash = $this->sha256( $row['official_revision_hash'] ?? null );
			$display       = $this->display( $row );

			return array(
				'kind'       => 'candidate',
				'id'         => $submission_id,
				'state'      => 'not_evaluated',
				'assignment' => array(
					'id'    => $this->public_id( $row['assignment_id'] ?? null ),
					'title' => $this->text( $row['assignment_title'] ?? null, 255 ),
					'state' => $this->one_of( $row['assignment_state'] ?? null, array( 'draft', 'scheduled', 'open', 'closed' ) ),
				),
				'learner'    => array(
					'user_id'             => $this->positive_integer( $row['learner_user_id'] ?? null ),
					'display_name'        => $this->text( $display['display_name'] ?? null, 255 ),
					'membership_revision' => $this->token( $row['membership_revision'] ?? null, 191 ),
				),
				'submission' => array(
					'id'                     => $submission_id,
					'state'                  => (string) $row['submission_state'],
					'official_revision_id'   => $revision_id,
					'official_revision_hash' => $revision_hash,
					'submitted_at_utc'       => $this->datetime( $row['submitted_at_utc'] ?? null ),
				),
			);
		} catch ( Submission_Evaluation_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		}
	}

	/**
	 * Display.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function display( array $row ): array {
		if (
			'oj_ai.assignment_target_display' !== ( $row['display_snapshot_schema'] ?? null )
			|| 1 !== $this->positive_integer( $row['display_snapshot_version'] ?? null )
		) {
			throw new InvalidArgumentException( 'Submission candidate display schema is invalid.' );
		}
		$json = $row['display_snapshot_json'] ?? null;
		$hash = $this->sha256( $row['display_snapshot_hash'] ?? null );
		if ( ! is_string( $json ) || ! hash_equals( $hash, hash( 'sha256', $json ) ) ) {
			throw new InvalidArgumentException( 'Submission candidate display hash is invalid.' );
		}
		try {
			$display = json_decode( $json, true, 128, JSON_THROW_ON_ERROR );
		} catch ( JsonException $error ) {
			throw new InvalidArgumentException( 'Submission candidate display JSON is invalid.', 0, $error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The diagnostic text is literal; a chained Throwable is not HTML output.
		}
		if (
			! is_array( $display ) || array_is_list( $display )
			|| array( 'display_name' ) !== array_keys( $display )
			|| ! hash_equals( $json, $this->canonical->encode( $display ) )
		) {
			throw new InvalidArgumentException( 'Submission candidate display projection drifted.' );
		}
		return $display;
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
			throw new InvalidArgumentException( 'Submission candidate ID is invalid.' );
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
			throw new InvalidArgumentException( 'Submission candidate hash is invalid.' );
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
		if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			$value = (int) $value;
		}
		if ( ! is_int( $value ) || $value < 1 ) {
			throw new InvalidArgumentException( 'Submission candidate integer is invalid.' );
		}
		return $value;
	}

	/**
	 * Text.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function text( $value, int $maximum ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) || strlen( $value ) > $maximum * 4 || str_contains( $value, "\n" ) ) {
			throw new InvalidArgumentException( 'Submission candidate text is invalid.' );
		}
		return $value;
	}

	/**
	 * Token.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function token( $value, int $maximum ): string {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > $maximum || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $value ) ) {
			throw new InvalidArgumentException( 'Submission candidate token is invalid.' );
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
			throw new InvalidArgumentException( 'Submission candidate enum is invalid.' );
		}
		return $value;
	}

	/**
	 * Datetime.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function datetime( $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Submission candidate datetime is invalid.' );
		}
		return $value;
	}
}
