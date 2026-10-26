<?php
/**
 * Stable REST problem contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Rest;

use InvalidArgumentException;
use LogicException;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Policy_Value;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the frozen public status, code, and user message map.
 */
final class Rest_Problem {
	/**
	 * Rest problem.
	 *
	 * @var array<int,array{code:string,message:string}>
	 */
	private const DEFINITIONS = array(
		401 => array(
			'code'    => 'oj_ai_authentication_required',
			'message' => '로그인이 필요합니다.',
		),
		403 => array(
			'code'    => 'oj_ai_access_denied',
			'message' => '이 작업을 할 수 없습니다.',
		),
		404 => array(
			'code'    => 'oj_ai_resource_not_found',
			'message' => '대상을 찾을 수 없습니다.',
		),
		409 => array(
			'code'    => 'oj_ai_state_conflict',
			'message' => '내용이 바뀌었습니다. 새로고침해 주세요.',
		),
		410 => array(
			'code'    => 'oj_ai_resource_gone',
			'message' => '더 이상 사용할 수 없습니다.',
		),
		413 => array(
			'code'    => 'oj_ai_payload_too_large',
			'message' => '파일 또는 내용이 너무 큽니다.',
		),
		415 => array(
			'code'    => 'oj_ai_media_type_unsupported',
			'message' => '지원하지 않는 파일 형식입니다.',
		),
		422 => array(
			'code'    => 'oj_ai_validation_failed',
			'message' => '확인할 항목이 있습니다.',
		),
		429 => array(
			'code'    => 'oj_ai_rate_limited',
			'message' => '잠시 후 다시 시도해 주세요.',
		),
		500 => array(
			'code'    => 'oj_ai_operation_failed',
			'message' => '처리하지 못했습니다.',
		),
		503 => array(
			'code'    => 'oj_ai_runtime_unavailable',
			'message' => '지금은 사용할 수 없습니다.',
		),
	);

	/**
	 * Status.
	 *
	 * @var int
	 */
	private int $status;
	/**
	 * Code.
	 *
	 * @var string
	 */
	private string $code;
	/**
	 * Message.
	 *
	 * @var string
	 */
	private string $message;
	/**
	 * Request correlation identifier for diagnostics.
	 *
	 * @var string
	 */
	private string $correlation_id;

	/**
	 * Initialize rest problem dependencies and state.
	 *
	 * @param int    $status Status.
	 * @param string $code Code.
	 * @param string $message Message.
	 * @param string $correlation_id Request correlation identifier for diagnostics.
	 */
	private function __construct( int $status, string $code, string $message, string $correlation_id ) {
		Policy_Value::correlation_id( $correlation_id );

		$this->status         = $status;
		$this->code           = $code;
		$this->message        = $message;
		$this->correlation_id = $correlation_id;
	}

	/**
	 * For status.
	 *
	 * @param int    $status Status.
	 * @param string $correlation_id Request correlation identifier for diagnostics.
	 * @return self
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function for_status( int $status, string $correlation_id ): self {
		if ( ! isset( self::DEFINITIONS[ $status ] ) ) {
			throw new InvalidArgumentException( 'Unsupported frozen REST problem status.' );
		}

		$definition = self::DEFINITIONS[ $status ];
		return new self( $status, $definition['code'], $definition['message'], $correlation_id );
	}

	/**
	 * Build an operation-specific stable problem without weakening common status rules.
	 *
	 * @param int    $status Status.
	 * @param string $code Code.
	 * @param string $message Message.
	 * @param string $correlation_id Request correlation identifier for diagnostics.
	 * @return self
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function operation(
		int $status,
		string $code,
		string $message,
		string $correlation_id
	): self {
		if (
			! isset( self::DEFINITIONS[ $status ] )
			|| 1 !== preg_match( '/^[a-z][a-z0-9_]{2,95}$/D', $code )
			|| '' === trim( $message )
			|| strlen( $message ) > 300
			|| 1 !== preg_match( '//u', $message )
			|| 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $message )
		) {
			throw new InvalidArgumentException( 'Invalid operation REST problem.' );
		}

		return new self( $status, $code, $message, $correlation_id );
	}

	/**
	 * From policy.
	 *
	 * @param Access_Policy_Decision $decision Decision.
	 * @return self
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function from_policy( Access_Policy_Decision $decision ): self {
		if ( $decision->is_allowed() ) {
			throw new InvalidArgumentException( 'An allowed policy decision is not a REST problem.' );
		}

		$problem = self::for_status( $decision->status(), $decision->correlation_id() );
		if ( ! hash_equals( $problem->code(), $decision->code() ) ) {
			throw new LogicException( 'Access policy and REST problem codes have drifted.' );
		}

		return $problem;
	}

	/**
	 * Supported statuses.
	 *
	 * @return array<int,int>
	 */
	public static function supported_statuses(): array {
		return array_keys( self::DEFINITIONS );
	}

	/**
	 * Status.
	 *
	 * @return int
	 */
	public function status(): int {
		return $this->status;
	}

	/**
	 * Code.
	 *
	 * @return string
	 */
	public function code(): string {
		return $this->code;
	}

	/**
	 * Message.
	 *
	 * @return string
	 */
	public function message(): string {
		return $this->message;
	}

	/**
	 * Correlation id.
	 *
	 * @return string
	 */
	public function correlation_id(): string {
		return $this->correlation_id;
	}

	/**
	 * Data.
	 *
	 * @return array{status:int,correlation_id:string}
	 */
	public function data(): array {
		return array(
			'status'         => $this->status,
			'correlation_id' => $this->correlation_id,
		);
	}
}
