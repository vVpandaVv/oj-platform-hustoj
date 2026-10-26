<?php
/**
 * Durable C53/C54 export job control receipt.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\Export\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Durable C53/C54 export job control receipt.
 */
final class Export_Control_Result {
	public const OPERATION_CANCEL = 'cancel';
	public const OPERATION_RETRY  = 'retry';

	/**
	 * Initialize export control result dependencies and state.
	 *
	 * @param string $operation Operation.
	 * @param string $source_job_id Source job id.
	 * @param string $job_id Job id.
	 * @param string $state State.
	 * @param string $artifact_kind Artifact kind.
	 * @param string $source_type Source type.
	 * @param string $source_id Source id.
	 * @param string $source_hash Source hash.
	 * @param string $request_hash Request hash.
	 * @param string $queued_at_utc Queued at utc.
	 * @param string $occurred_at_utc Occurred at utc.
	 * @param bool   $replayed Replayed.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $operation,
		private string $source_job_id,
		private string $job_id,
		private string $state,
		private string $artifact_kind,
		private string $source_type,
		private string $source_id,
		private string $source_hash,
		private string $request_hash,
		private string $queued_at_utc,
		private string $occurred_at_utc,
		private bool $replayed
	) {
		new Public_Id( $source_job_id );
		new Public_Id( $job_id );
		new Public_Id( $source_id );
		$valid_transition =
			( self::OPERATION_CANCEL === $operation && 'cancelled' === $state && hash_equals( $source_job_id, $job_id ) )
			|| ( self::OPERATION_RETRY === $operation && in_array( $state, array( 'queued', 'running' ), true ) && ! hash_equals( $source_job_id, $job_id ) );
		if (
			! $valid_transition
			|| ! self::supports( $source_type, $artifact_kind )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $source_hash )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $request_hash )
			|| ! self::is_utc( $queued_at_utc )
			|| ! self::is_utc( $occurred_at_utc )
		) {
			throw new InvalidArgumentException( 'Export control result is invalid.' );
		}
	}

	/**
	 * Operation.
	 *
	 * @return string
	 */
	public function operation(): string {
		return $this->operation;
	}
	/**
	 * Source job id.
	 *
	 * @return string
	 */
	public function source_job_id(): string {
		return $this->source_job_id;
	}
	/**
	 * Job id.
	 *
	 * @return string
	 */
	public function job_id(): string {
		return $this->job_id;
	}
	/**
	 * State.
	 *
	 * @return string
	 */
	public function state(): string {
		return $this->state;
	}
	/**
	 * Artifact kind.
	 *
	 * @return string
	 */
	public function artifact_kind(): string {
		return $this->artifact_kind;
	}
	/**
	 * Source type.
	 *
	 * @return string
	 */
	public function source_type(): string {
		return $this->source_type;
	}
	/**
	 * Source id.
	 *
	 * @return string
	 */
	public function source_id(): string {
		return $this->source_id;
	}
	/**
	 * Source hash.
	 *
	 * @return string
	 */
	public function source_hash(): string {
		return $this->source_hash;
	}
	/**
	 * Request hash.
	 *
	 * @return string
	 */
	public function request_hash(): string {
		return $this->request_hash;
	}
	/**
	 * Queued at utc.
	 *
	 * @return string
	 */
	public function queued_at_utc(): string {
		return $this->queued_at_utc;
	}
	/**
	 * Occurred at utc.
	 *
	 * @return string
	 */
	public function occurred_at_utc(): string {
		return $this->occurred_at_utc;
	}
	/**
	 * Replayed.
	 *
	 * @return bool
	 */
	public function replayed(): bool {
		return $this->replayed;
	}

	/**
	 * Supports.
	 *
	 * @param string $source_type Source type.
	 * @param string $artifact_kind Artifact kind.
	 * @return bool
	 */
	private static function supports( string $source_type, string $artifact_kind ): bool {
		return ( 'worksheet_revision' === $source_type && in_array( $artifact_kind, array( 'worksheet_pdf', 'worksheet_package' ), true ) )
			|| ( 'worksheet_publication' === $source_type && 'worksheet_pdf' === $artifact_kind )
			|| ( 'submission_revision' === $source_type && 'submission_pdf' === $artifact_kind )
			|| ( 'submission_release' === $source_type && 'result_pdf' === $artifact_kind )
			|| ( 'report_snapshot' === $source_type && in_array( $artifact_kind, array( 'report_csv', 'report_pdf', 'report_json_package' ), true ) )
			|| ( 'assignment' === $source_type && 'submission_bundle' === $artifact_kind );
	}

	/**
	 * Is utc.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private static function is_utc( string $value ): bool {
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value );
	}
}
