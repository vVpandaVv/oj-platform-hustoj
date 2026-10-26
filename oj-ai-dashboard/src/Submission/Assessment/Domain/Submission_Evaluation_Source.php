<?php
/**
 * Exact official source for one student submission evaluation.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Domain;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps private source material inside the add-on boundary before projection.
 */
final class Submission_Evaluation_Source {
	/**
	 * Initialize submission evaluation source dependencies and state.
	 *
	 * @param string                 $submission_id Submission public identifier.
	 * @param int                    $lock_version Lock version.
	 * @param string                 $state State.
	 * @param string                 $context_type Context type.
	 * @param string                 $context_id Context id.
	 * @param string                 $context_revision Context revision.
	 * @param array<array-key,mixed> $official_revision Official revision.
	 * @param array<array-key,mixed> $publication Publication.
	 * @param array<array-key,mixed> $configuration Configuration.
	 * @param array<int,mixed>       $artifact_representations Raw representations validated by the input builder.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $submission_id,
		private int $lock_version,
		private string $state,
		private string $context_type,
		private string $context_id,
		private string $context_revision,
		private array $official_revision,
		private array $publication,
		private array $configuration,
		private array $artifact_representations
	) {
		new Public_Id( $submission_id );
		$revision_id    = $official_revision['id'] ?? null;
		$publication_id = $publication['id'] ?? null;
		if ( ! is_string( $revision_id ) || ! is_string( $publication_id ) ) {
			throw new InvalidArgumentException( 'Submission evaluation source IDs are unavailable.' );
		}
		new Public_Id( $revision_id );
		new Public_Id( $publication_id );
		if (
			$lock_version < 0
			|| ! in_array( $state, array( 'submitted', 'auto_submitted', 'proxy_submitted', 'locked' ), true )
			|| ! in_array( $context_type, array( 'class', 'group' ), true )
			|| ! $this->token( $context_id, 191 ) || ! $this->token( $context_revision, 191 )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) ( $official_revision['content_hash'] ?? '' ) )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) ( $publication['publication_hash'] ?? '' ) )
			|| array_is_list( $official_revision ) || array_is_list( $publication ) || array_is_list( $configuration )
			|| ! array_is_list( $artifact_representations )
		) {
			throw new InvalidArgumentException( 'Submission evaluation source is invalid.' );
		}
	}

	/**
	 * Submission id.
	 *
	 * @return string
	 */
	public function submission_id(): string {
		return $this->submission_id;
	}
	/**
	 * Lock version.
	 *
	 * @return int
	 */
	public function lock_version(): int {
		return $this->lock_version;
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
	 * Context type.
	 *
	 * @return string
	 */
	public function context_type(): string {
		return $this->context_type;
	}
	/**
	 * Context id.
	 *
	 * @return string
	 */
	public function context_id(): string {
		return $this->context_id;
	}
	/**
	 * Context revision.
	 *
	 * @return string
	 */
	public function context_revision(): string {
		return $this->context_revision;
	}
	/**
	 * Official revision id.
	 *
	 * @return string
	 */
	public function official_revision_id(): string {
		return (string) $this->official_revision['id'];
	}
	/**
	 * Source hash.
	 *
	 * @return string
	 */
	public function source_hash(): string {
		return (string) $this->official_revision['content_hash'];
	}
	/**
	 * Publication id.
	 *
	 * @return string
	 */
	public function publication_id(): string {
		return (string) $this->publication['id'];
	}
	/**
	 * Publication hash.
	 *
	 * @return string
	 */
	public function publication_hash(): string {
		return (string) $this->publication['publication_hash'];
	}
	/**
	 * Official revision.
	 *
	 * @return array<string,mixed>
	 */
	public function official_revision(): array {
		return $this->official_revision;
	}
	/**
	 * Publication.
	 *
	 * @return array<string,mixed>
	 */
	public function publication(): array {
		return $this->publication;
	}
	/**
	 * Configuration.
	 *
	 * @return array<string,mixed>
	 */
	public function configuration(): array {
		return $this->configuration;
	}
	/**
	 * Artifact representations.
	 *
	 * @return list<mixed> Raw representations validated by the input builder.
	 */
	public function artifact_representations(): array {
		return $this->artifact_representations;
	}

	/**
	 * Token.
	 *
	 * @param string $value Value.
	 * @param int    $maximum Maximum.
	 * @return bool
	 */
	private function token( string $value, int $maximum ): bool {
		return '' !== $value && strlen( $value ) <= $maximum && 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $value );
	}
}
