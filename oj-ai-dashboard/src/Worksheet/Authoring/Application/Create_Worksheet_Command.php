<?php
/**
 * CreateWorksheet command input.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Accepts only trusted actor metadata and the frozen C01 command profile.
 */
final class Create_Worksheet_Command {
	/**
	 * Authenticated actor from the access context.
	 *
	 * @var int
	 */
	private int $actor_user_id;
	/**
	 * Command identifier used for idempotency.
	 *
	 * @var string
	 */
	private string $command_id;
	/**
	 * Title.
	 *
	 * @var string
	 */
	private string $title;
	/**
	 * Locale.
	 *
	 * @var string
	 */
	private string $locale;
	/**
	 * Fingerprint scoped to the actor and command.
	 *
	 * @var string
	 */
	private string $idempotency_fingerprint;
	/**
	 * Request correlation identifier for diagnostics.
	 *
	 * @var string
	 */
	private string $correlation_id;
	/**
	 * Request digest.
	 *
	 * @var string
	 */
	private string $request_digest;

	/**
	 * Initialize worksheet command dependencies and state.
	 *
	 * @param int            $actor_user_id Authenticated actor from the access context.
	 * @param string         $command_id Command identifier used for idempotency.
	 * @param string         $expected_state Expected state.
	 * @param string         $title Title.
	 * @param string         $locale Locale.
	 * @param string         $idempotency_fingerprint Fingerprint scoped to the actor and command.
	 * @param string         $correlation_id Request correlation identifier for diagnostics.
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		int $actor_user_id,
		string $command_id,
		string $expected_state,
		string $title,
		string $locale,
		string $idempotency_fingerprint,
		string $correlation_id,
		Canonical_Json $canonical
	) {
		if ( $actor_user_id < 1 ) {
			throw new InvalidArgumentException( 'CreateWorksheet requires an authenticated actor.' );
		}
		if (
			strlen( $command_id ) < 8
			|| strlen( $command_id ) > 128
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $command_id )
		) {
			throw new InvalidArgumentException( 'CreateWorksheet command_id is invalid.' );
		}
		if ( 'absent' !== $expected_state ) {
			throw new InvalidArgumentException( 'CreateWorksheet expected_state must be absent.' );
		}
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $idempotency_fingerprint ) ) {
			throw new InvalidArgumentException( 'CreateWorksheet idempotency fingerprint is invalid.' );
		}
		if (
			strlen( $correlation_id ) > 100
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $correlation_id )
		) {
			throw new InvalidArgumentException( 'CreateWorksheet correlation ID is invalid.' );
		}

		$title = $canonical->text( trim( $title ) );
		if ( '' === $title || $this->character_length( $title ) > 255 || 1 === preg_match( '/[\x00-\x1F\x7F]/u', $title ) ) {
			throw new InvalidArgumentException( 'CreateWorksheet title is invalid.' );
		}

		$locale = $this->normalize_locale( $locale );

		$this->actor_user_id           = $actor_user_id;
		$this->command_id              = $command_id;
		$this->title                   = $title;
		$this->locale                  = $locale;
		$this->idempotency_fingerprint = $idempotency_fingerprint;
		$this->correlation_id          = $correlation_id;
		$this->request_digest          = hash(
			'sha256',
			$canonical->encode(
				array(
					'command_id' => $command_id,
					'expected'   => array( 'expected_state' => 'absent' ),
					'payload'    => array( 'title' => $title ),
					'locale'     => $locale,
				)
			)
		);
	}

	/**
	 * Actor user id.
	 *
	 * @return int
	 */
	public function actor_user_id(): int {
		return $this->actor_user_id;
	}

	/**
	 * Command id.
	 *
	 * @return string
	 */
	public function command_id(): string {
		return $this->command_id;
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	public function title(): string {
		return $this->title;
	}

	/**
	 * Locale.
	 *
	 * @return string
	 */
	public function locale(): string {
		return $this->locale;
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
	 * Request digest.
	 *
	 * @return string
	 */
	public function request_digest(): string {
		return $this->request_digest;
	}

	/**
	 * Idempotency storage key.
	 *
	 * @return string
	 */
	public function idempotency_storage_key(): string {
		return hash(
			'sha256',
			$this->actor_user_id . '|worksheet.create|' . $this->idempotency_fingerprint
		);
	}

	/**
	 * Normalize locale.
	 *
	 * @param string $locale Locale.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function normalize_locale( string $locale ): string {
		$locale = str_replace( '_', '-', trim( $locale ) );
		if ( strlen( $locale ) > 16 || 1 !== preg_match( '/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8}){0,2}$/D', $locale ) ) {
			throw new InvalidArgumentException( 'CreateWorksheet locale is invalid.' );
		}

		$parts      = explode( '-', $locale );
		$parts[0]   = strtolower( $parts[0] );
		$part_count = count( $parts );
		for ( $index = 1; $index < $part_count; ++$index ) {
			$parts[ $index ] = 4 === strlen( $parts[ $index ] )
				? ucfirst( strtolower( $parts[ $index ] ) )
				: strtoupper( $parts[ $index ] );
		}

		return implode( '-', $parts );
	}

	/**
	 * Character length.
	 *
	 * @param string $value Value.
	 * @return int
	 */
	private function character_length( string $value ): int {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $value, 'UTF-8' );
		}

		$count = preg_match_all( '/./us', $value, $matches );
		return false === $count ? PHP_INT_MAX : $count;
	}
}
