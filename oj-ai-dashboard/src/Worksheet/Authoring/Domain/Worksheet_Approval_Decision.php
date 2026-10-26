<?php
/**
 * Deterministic worksheet approval policy decision.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Domain;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deterministic worksheet approval policy decision.
 */
final class Worksheet_Approval_Decision {
	public const SCHEMA_ID      = 'oj_ai.worksheet_approval_decision';
	public const SCHEMA_VERSION = '1.0.0';

	/**
	 * Blockers.
	 *
	 * @var array<int,array{code:string}>
	 */
	private array $blockers;

	/**
	 * Warnings.
	 *
	 * @var array<int,array{code:string}>
	 */
	private array $warnings;

	/**
	 * Evidence hash.
	 *
	 * @var string
	 */
	private string $evidence_hash;
	/**
	 * Decision hash.
	 *
	 * @var string
	 */
	private string $decision_hash;

	/**
	 * Initialize worksheet approval decision dependencies and state.
	 *
	 * @param string            $worksheet_id Worksheet public identifier.
	 * @param string            $revision_id Revision id.
	 * @param string            $document_hash Document hash.
	 * @param string            $policy_revision_id Policy revision id.
	 * @param string            $policy_hash Policy hash.
	 * @param string            $validation_projection_hash Validation projection hash.
	 * @param string|null       $evaluation_projection_hash Evaluation projection hash.
	 * @param array<int,string> $blocker_codes Blocker codes.
	 * @param array<int,string> $warning_codes Warning codes.
	 * @param Canonical_Json    $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct(
		private string $worksheet_id,
		private string $revision_id,
		private string $document_hash,
		private string $policy_revision_id,
		private string $policy_hash,
		private string $validation_projection_hash,
		private ?string $evaluation_projection_hash,
		array $blocker_codes,
		array $warning_codes,
		Canonical_Json $canonical
	) {
		new Public_Id( $worksheet_id );
		new Public_Id( $revision_id );
		new Public_Id( $policy_revision_id );
		foreach ( array( $document_hash, $policy_hash, $validation_projection_hash ) as $hash ) {
			$this->assert_hash( $hash );
		}
		if ( null !== $evaluation_projection_hash ) {
			$this->assert_hash( $evaluation_projection_hash );
		}

		$this->blockers      = $this->normalize_codes( $blocker_codes );
		$this->warnings      = $this->normalize_codes( $warning_codes );
		$evidence            = array(
			'document_hash'              => $this->document_hash,
			'evaluation_projection_hash' => $this->evaluation_projection_hash,
			'policy_hash'                => $this->policy_hash,
			'policy_revision_id'         => $this->policy_revision_id,
			'revision_id'                => $this->revision_id,
			'validation_projection_hash' => $this->validation_projection_hash,
			'worksheet_id'               => $this->worksheet_id,
		);
		$this->evidence_hash = hash( 'sha256', $canonical->encode( $evidence ) );
		$this->decision_hash = hash( 'sha256', $canonical->encode( $this->projection_without_hash() ) );
	}

	/**
	 * Status.
	 *
	 * @return string
	 */
	public function status(): string {
		return array() === $this->blockers ? 'ready' : 'blocked';
	}

	/**
	 * Approval ready.
	 *
	 * @return bool
	 */
	public function approval_ready(): bool {
		return array() === $this->blockers;
	}

	/**
	 * Blockers.
	 *
	 * @return array<int,array{code:string}>
	 */
	public function blockers(): array {
		return $this->blockers;
	}

	/**
	 * Warnings.
	 *
	 * @return array<int,array{code:string}>
	 */
	public function warnings(): array {
		return $this->warnings;
	}

	/**
	 * Evidence hash.
	 *
	 * @return string
	 */
	public function evidence_hash(): string {
		return $this->evidence_hash;
	}

	/**
	 * Evaluation projection hash.
	 *
	 * @return string|null
	 */
	public function evaluation_projection_hash(): ?string {
		return $this->evaluation_projection_hash;
	}

	/**
	 * Decision hash.
	 *
	 * @return string
	 */
	public function decision_hash(): string {
		return $this->decision_hash;
	}

	/**
	 * Projection.
	 *
	 * @return array<string,mixed>
	 */
	public function projection(): array {
		return array_merge(
			$this->projection_without_hash(),
			array( 'decision_hash' => $this->decision_hash )
		);
	}

	/**
	 * Projection without hash.
	 *
	 * @return array<string,mixed>
	 */
	private function projection_without_hash(): array {
		return array(
			'schema_id'      => self::SCHEMA_ID,
			'schema_version' => self::SCHEMA_VERSION,
			'source'         => array(
				'worksheet_id'  => $this->worksheet_id,
				'revision_id'   => $this->revision_id,
				'document_hash' => $this->document_hash,
			),
			'policy'         => array(
				'revision_id'  => $this->policy_revision_id,
				'content_hash' => $this->policy_hash,
			),
			'status'         => $this->status(),
			'approval_ready' => $this->approval_ready(),
			'blockers'       => $this->blockers,
			'warnings'       => $this->warnings,
			'evidence_hash'  => $this->evidence_hash,
		);
	}

	/**
	 * Normalize codes.
	 *
	 * @param array<array-key,mixed> $codes Codes.
	 * @return array<int,array{code:string}>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function normalize_codes( array $codes ): array {
		$normalized = array();
		foreach ( $codes as $code ) {
			if ( ! is_string( $code ) || 1 !== preg_match( '/^[a-z][a-z0-9_]{2,99}$/D', $code ) ) {
				throw new InvalidArgumentException( 'Worksheet approval decision code is invalid.' );
			}
			$normalized[ $code ] = array( 'code' => $code );
		}
		ksort( $normalized, SORT_STRING );
		return array_values( $normalized );
	}

	/**
	 * Reject invalid hash.
	 *
	 * @param string $hash Hash.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_hash( string $hash ): void {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
			throw new InvalidArgumentException( 'Worksheet approval decision hash is invalid.' );
		}
	}
}
