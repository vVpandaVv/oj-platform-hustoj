<?php
/**
 * Safe diagnostic projection for one add-on AI task route.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Diagnostics\Domain;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Safe diagnostic projection for one add-on AI task route.
 */
final class Ai_Routing_Task_Diagnostics {
	public const SUBJECT_WORKSHEET_QUALITY  = 'worksheet_quality';
	public const SUBJECT_STUDENT_SUBMISSION = 'student_submission';

	public const READY                = 'ready';
	public const UNROUTABLE           = 'unroutable';
	public const UNROUTABLE_TRANSIENT = 'unroutable_transient';
	public const ROUTING_STALE        = 'routing_stale';
	public const RUNTIME_UNAVAILABLE  = 'runtime_unavailable';

	/**
	 * Initialize ai routing task diagnostics dependencies and state.
	 *
	 * @param string      $subject Subject.
	 * @param string      $status Status.
	 * @param string      $code Code.
	 * @param string|null $routing_revision Routing revision.
	 * @param string|null $profile_fingerprint Profile fingerprint.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $subject,
		private string $status,
		private string $code,
		private ?string $routing_revision = null,
		private ?string $profile_fingerprint = null
	) {
		if ( ! in_array( $subject, array( self::SUBJECT_WORKSHEET_QUALITY, self::SUBJECT_STUDENT_SUBMISSION ), true ) ) {
			throw new InvalidArgumentException( 'Unknown AI routing diagnostic subject.' );
		}
		if ( ! in_array( $status, array( self::READY, self::UNROUTABLE, self::UNROUTABLE_TRANSIENT, self::ROUTING_STALE, self::RUNTIME_UNAVAILABLE ), true ) ) {
			throw new InvalidArgumentException( 'Unknown AI routing diagnostic status.' );
		}
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{2,99}$/D', $code ) ) {
			throw new InvalidArgumentException( 'AI routing diagnostics require a stable code.' );
		}

		if ( self::READY === $status ) {
			if (
				'ai_task_routing_ready' !== $code
				|| ! $this->safe_token( $routing_revision )
				|| ! $this->safe_token( $profile_fingerprint )
			) {
				throw new InvalidArgumentException( 'Ready AI routing diagnostics require safe routing details.' );
			}
		} elseif ( null !== $routing_revision || null !== $profile_fingerprint ) {
			throw new InvalidArgumentException( 'Unavailable AI routing diagnostics cannot expose routing details.' );
		}
	}

	/**
	 * Subject.
	 *
	 * @return string
	 */
	public function subject(): string {
		return $this->subject;
	}

	/**
	 * Status.
	 *
	 * @return string
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Projection.
	 *
	 * @return array{status:string,label:string,code:string,details:array<string,string>}
	 */
	public function projection(): array {
		return array(
			'status'  => $this->status,
			'label'   => $this->label(),
			'code'    => $this->code,
			'details' => self::READY === $this->status
				? array(
					'routing_revision'    => (string) $this->routing_revision,
					'profile_fingerprint' => (string) $this->profile_fingerprint,
				)
				: array(),
		);
	}

	/**
	 * Label.
	 *
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function label(): string {
		return match ( $this->status ) {
			self::READY => '연결됨',
			self::UNROUTABLE => '설정 필요',
			self::UNROUTABLE_TRANSIENT, self::RUNTIME_UNAVAILABLE => '확인 불가',
			self::ROUTING_STALE => '다시 확인 필요',
			default => throw new InvalidArgumentException( 'Unknown AI routing diagnostic status.' ),
		};
	}

	/**
	 * Safe token.
	 *
	 * @param string|null $value Value.
	 * @return bool
	 */
	private function safe_token( ?string $value ): bool {
		return is_string( $value )
			&& 1 === preg_match( '/^[0-9A-Za-z][0-9A-Za-z._:+\/-]{0,159}$/D', $value );
	}
}
