<?php
/**
 * Stable worksheet application failure.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps repository details out of Presentation error mapping.
 */
final class Worksheet_Failure extends RuntimeException {
	public const IDEMPOTENCY_CONFLICT                  = 'worksheet_idempotency_conflict';
	public const NOT_FOUND                             = 'worksheet_not_found';
	public const STORAGE_UNAVAILABLE                   = 'worksheet_storage_unavailable';
	public const STORED_DATA_INVALID                   = 'worksheet_stored_data_invalid';
	public const REVISION_CONFLICT                     = 'worksheet_revision_conflict';
	public const ASSET_NOT_READY                       = 'worksheet_asset_not_ready';
	public const APPROVAL_BLOCKED                      = 'worksheet_approval_blocked';
	public const APPROVAL_CONFLICT                     = 'worksheet_approval_conflict';
	public const APPROVAL_EVIDENCE_UNAVAILABLE         = 'worksheet_approval_evidence_unavailable';
	public const PUBLICATION_BLOCKED                   = 'worksheet_publication_blocked';
	public const PUBLICATION_CONFLICT                  = 'worksheet_publication_conflict';
	public const PUBLICATION_APPROVAL_UNAVAILABLE      = 'worksheet_publication_approval_unavailable';
	public const PUBLICATION_CONFIGURATION_UNAVAILABLE = 'worksheet_publication_configuration_unavailable';
	public const LIFECYCLE_CONFLICT                    = 'worksheet_lifecycle_conflict';
	public const PRINT_SOURCE_CONFLICT                 = 'worksheet_print_source_conflict';

	/**
	 * Reason.
	 *
	 * @var string
	 */
	private string $reason;

	/**
	 * Safe context.
	 *
	 * @var array<string,string>
	 */
	private array $safe_context;

	/**
	 * Initialize worksheet failure dependencies and state.
	 *
	 * @param string               $reason Reason.
	 * @param array<string,string> $safe_context Safe context.
	 */
	private function __construct( string $reason, array $safe_context = array() ) {
		parent::__construct( $reason );
		$this->reason       = $reason;
		$this->safe_context = $safe_context;
	}

	/**
	 * Asset not ready.
	 *
	 * @return self
	 */
	public static function asset_not_ready(): self {
		return new self( self::ASSET_NOT_READY );
	}

	/**
	 * Idempotency conflict.
	 *
	 * @return self
	 */
	public static function idempotency_conflict(): self {
		return new self( self::IDEMPOTENCY_CONFLICT );
	}

	/**
	 * Not found.
	 *
	 * @return self
	 */
	public static function not_found(): self {
		return new self( self::NOT_FOUND );
	}

	/**
	 * Storage unavailable.
	 *
	 * @return self
	 */
	public static function storage_unavailable(): self {
		return new self( self::STORAGE_UNAVAILABLE );
	}

	/**
	 * Stored data invalid.
	 *
	 * @return self
	 */
	public static function stored_data_invalid(): self {
		return new self( self::STORED_DATA_INVALID );
	}

	/**
	 * Revision conflict.
	 *
	 * @param string $expected_revision_id Revision the caller read before this operation.
	 * @param string $current_revision_id Current revision id.
	 * @param string $current_revision_hash Current revision hash.
	 * @return self
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function revision_conflict(
		string $expected_revision_id,
		string $current_revision_id,
		string $current_revision_hash
	): self {
		new Public_Id( $expected_revision_id );
		new Public_Id( $current_revision_id );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $current_revision_hash ) ) {
			throw new InvalidArgumentException( 'Worksheet revision conflict hash is invalid.' );
		}

		return new self(
			self::REVISION_CONFLICT,
			array(
				'expected_revision_id'  => $expected_revision_id,
				'current_revision_id'   => $current_revision_id,
				'current_revision_hash' => $current_revision_hash,
			)
		);
	}

	/**
	 * Approval blocked.
	 *
	 * @param string $decision_hash Decision hash.
	 * @param string $blocker_code Blocker code.
	 * @return self
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function approval_blocked( string $decision_hash, string $blocker_code ): self {
		self::assert_hash( $decision_hash );
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{2,99}$/D', $blocker_code ) ) {
			throw new InvalidArgumentException( 'Worksheet approval blocker code is invalid.' );
		}
		return new self(
			self::APPROVAL_BLOCKED,
			array(
				'approval_decision_hash' => $decision_hash,
				'blocker_code'           => $blocker_code,
			)
		);
	}

	/**
	 * Approval conflict.
	 *
	 * @param string $document_hash Document hash.
	 * @param string $validation_projection_hash Validation projection hash.
	 * @param string $approval_decision_hash Approval decision hash.
	 * @return self
	 */
	public static function approval_conflict(
		string $document_hash,
		string $validation_projection_hash,
		string $approval_decision_hash
	): self {
		foreach ( array( $document_hash, $validation_projection_hash, $approval_decision_hash ) as $hash ) {
			self::assert_hash( $hash );
		}
		return new self(
			self::APPROVAL_CONFLICT,
			array(
				'current_document_hash'              => $document_hash,
				'current_validation_projection_hash' => $validation_projection_hash,
				'current_approval_decision_hash'     => $approval_decision_hash,
			)
		);
	}

	/**
	 * Approval evidence unavailable.
	 *
	 * @return self
	 */
	public static function approval_evidence_unavailable(): self {
		return new self( self::APPROVAL_EVIDENCE_UNAVAILABLE );
	}

	/**
	 * Publication blocked.
	 *
	 * @param string $blocker_code Blocker code.
	 * @return self
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function publication_blocked( string $blocker_code ): self {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{2,99}$/D', $blocker_code ) ) {
			throw new InvalidArgumentException( 'Worksheet publication blocker code is invalid.' );
		}
		return new self( self::PUBLICATION_BLOCKED, array( 'blocker_code' => $blocker_code ) );
	}

	/**
	 * Publication conflict.
	 *
	 * @param string $document_hash Document hash.
	 * @param string $approval_decision_hash Approval decision hash.
	 * @return self
	 */
	public static function publication_conflict( string $document_hash, string $approval_decision_hash ): self {
		self::assert_hash( $document_hash );
		self::assert_hash( $approval_decision_hash );
		return new self(
			self::PUBLICATION_CONFLICT,
			array(
				'current_approval_decision_hash' => $approval_decision_hash,
				'current_document_hash'          => $document_hash,
			)
		);
	}

	/**
	 * Publication approval unavailable.
	 *
	 * @return self
	 */
	public static function publication_approval_unavailable(): self {
		return new self( self::PUBLICATION_APPROVAL_UNAVAILABLE );
	}

	/**
	 * Publication configuration unavailable.
	 *
	 * @return self
	 */
	public static function publication_configuration_unavailable(): self {
		return new self( self::PUBLICATION_CONFIGURATION_UNAVAILABLE );
	}

	/**
	 * Lifecycle conflict.
	 *
	 * @param string $current_state Current state.
	 * @param string $current_revision_id Current revision id.
	 * @param int    $current_lock_version Current lock version.
	 * @return self
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function lifecycle_conflict(
		string $current_state,
		string $current_revision_id,
		int $current_lock_version
	): self {
		if ( ! in_array( $current_state, array( 'draft', 'active', 'archived' ), true ) ) {
			throw new InvalidArgumentException( 'Worksheet lifecycle conflict state is invalid.' );
		}
		new Public_Id( $current_revision_id );
		if ( $current_lock_version < 0 ) {
			throw new InvalidArgumentException( 'Worksheet lifecycle conflict lock version is invalid.' );
		}
		return new self(
			self::LIFECYCLE_CONFLICT,
			array(
				'current_lock_version' => (string) $current_lock_version,
				'current_revision_id'  => $current_revision_id,
				'current_state'        => $current_state,
			)
		);
	}

	/**
	 * Print source conflict.
	 *
	 * @param string $expected_source_hash Expected source hash.
	 * @param string $current_source_hash Current source hash.
	 * @return self
	 */
	public static function print_source_conflict( string $expected_source_hash, string $current_source_hash ): self {
		self::assert_hash( $expected_source_hash );
		self::assert_hash( $current_source_hash );
		return new self(
			self::PRINT_SOURCE_CONFLICT,
			array(
				'expected_source_hash' => $expected_source_hash,
				'current_source_hash'  => $current_source_hash,
			)
		);
	}

	/**
	 * Return the stable failure reason used by boundary mappers.
	 *
	 * @return string
	 */
	public function reason(): string {
		return $this->reason;
	}

	/**
	 * Safe context.
	 *
	 * @return array<string,string>
	 */
	public function safe_context(): array {
		return $this->safe_context;
	}

	/**
	 * Reject invalid hash.
	 *
	 * @param string $hash Hash.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private static function assert_hash( string $hash ): void {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
			throw new InvalidArgumentException( 'Worksheet approval hash is invalid.' );
		}
	}
}
