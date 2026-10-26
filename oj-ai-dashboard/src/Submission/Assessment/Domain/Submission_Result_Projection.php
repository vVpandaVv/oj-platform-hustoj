<?php
/**
 * Canonical student-safe result projection stored in an immutable release.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Domain;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical student-safe result projection stored in an immutable release.
 */
final class Submission_Result_Projection {
	public const SCHEMA         = 'oj_ai.student_release_projection';
	public const VERSION        = 1;
	public const SCHEMA_VERSION = '1.0.0';
	public const MAXIMUM_BYTES  = 131072;

	/**
	 * Initialize submission result projection dependencies and state.
	 *
	 * @param array<array-key,mixed> $projection Projection.
	 * @param string                 $json Json.
	 * @param string                 $hash Hash.
	 * @param string|null            $visible_score Visible score.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private array $projection,
		private string $json,
		private string $hash,
		private ?string $visible_score
	) {
		if (
			array_is_list( $projection )
			|| self::SCHEMA !== ( $projection['schema_id'] ?? null )
			|| self::SCHEMA_VERSION !== ( $projection['schema_version'] ?? null )
			|| strlen( $json ) > self::MAXIMUM_BYTES
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash )
			|| ! hash_equals( $hash, hash( 'sha256', $json ) )
			|| ( null !== $visible_score && 1 !== preg_match( '/^(?:0|[1-9][0-9]{0,7})\.[0-9]{4}$/D', $visible_score ) )
		) {
			throw new InvalidArgumentException( 'Submission result projection is invalid.' );
		}
	}

	/**
	 * Projection.
	 *
	 * @return array<string,mixed>
	 */
	public function projection(): array {
		return $this->projection;
	}
	/**
	 * Schema.
	 *
	 * @return string
	 */
	public function schema(): string {
		return self::SCHEMA;
	}
	/**
	 * Version.
	 *
	 * @return int
	 */
	public function version(): int {
		return self::VERSION;
	}
	/**
	 * Json.
	 *
	 * @return string
	 */
	public function json(): string {
		return $this->json;
	}
	/**
	 * Hash.
	 *
	 * @return string
	 */
	public function hash(): string {
		return $this->hash;
	}
	/**
	 * Visible score.
	 *
	 * @return string|null
	 */
	public function visible_score(): ?string {
		return $this->visible_score;
	}
}
