<?php
/**
 * Curriculum catalog canonical profile.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Curriculum\Catalog\Domain;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Curriculum catalog canonical profile.
 */
final class Curriculum_Catalog_Profile {
	public const CURRICULUM_METADATA_SCHEMA          = 'oj_ai.curriculum_metadata';
	public const STANDARD_METADATA_SCHEMA            = 'oj_ai.achievement_standard_metadata';
	public const METADATA_VERSION                    = 1;
	public const SOURCE_MANUAL                       = 'manual';
	public const STATE_ACTIVE                        = 'active';
	public const MAX_QUERY_ITEMS                     = 100;
	public const MAX_SEARCH_CHARACTERS               = 200;
	public const MAX_STATUS_CHARACTERS               = 32;
	public const MAX_METADATA_SCHEMA_CHARACTERS      = 100;
	public const MAX_TITLE_CHARACTERS                = 255;
	public const MAX_SOURCE_KEY_CHARACTERS           = 191;
	public const MAX_SOURCE_VERSION_CHARACTERS       = 64;
	public const MAX_LOCALE_CHARACTERS               = 16;
	public const MAX_STANDARD_CODE_CHARACTERS        = 100;
	public const MAX_GRADE_LABEL_CHARACTERS          = 100;
	public const MAX_STANDARD_DESCRIPTION_CHARACTERS = 10000;

	/**
	 * Initialize curriculum catalog profile dependencies and state.
	 *
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct( private Canonical_Json $canonical ) {
	}

	/**
	 * Prepare curriculum.
	 *
	 * @param array<string,mixed> $payload Input payload at this boundary; see the declared shape.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function prepare_curriculum( array $payload ): array {
		$this->exact_keys(
			$payload,
			array( 'curriculum_id', 'locale', 'source_key', 'source_version', 'title' )
		);
		$public_id      = $this->optional_public_id( $payload['curriculum_id'] ?? null );
		$title          = $this->text( $payload['title'] ?? null, 'title', self::MAX_TITLE_CHARACTERS, false );
		$source_key     = $this->text( $payload['source_key'] ?? null, 'source_key', self::MAX_SOURCE_KEY_CHARACTERS, false );
		$source_version = $this->text( $payload['source_version'] ?? null, 'source_version', self::MAX_SOURCE_VERSION_CHARACTERS, false );
		$locale         = $this->text( $payload['locale'] ?? null, 'locale', self::MAX_LOCALE_CHARACTERS, false );
		if ( 1 !== preg_match( '/^[A-Za-z]{2,3}(?:[_-][A-Za-z0-9]{2,8})*$/D', $locale ) ) {
			throw new InvalidArgumentException( 'Curriculum locale is invalid.' );
		}

		$metadata_json = $this->canonical->encode( array( 'managed' => true ) );
		$content       = array(
			'locale'         => $locale,
			'source_key'     => $source_key,
			'source_kind'    => self::SOURCE_MANUAL,
			'source_version' => $source_version,
			'state'          => self::STATE_ACTIVE,
			'title'          => $title,
		);

		return array(
			'public_id'        => $public_id,
			'source_kind'      => self::SOURCE_MANUAL,
			'source_key'       => $source_key,
			'source_version'   => $source_version,
			'title'            => $title,
			'locale'           => $locale,
			'state'            => self::STATE_ACTIVE,
			'metadata_schema'  => self::CURRICULUM_METADATA_SCHEMA,
			'metadata_version' => self::METADATA_VERSION,
			'metadata_json'    => $metadata_json,
			'metadata_hash'    => hash( 'sha256', $metadata_json ),
			'content_hash'     => hash( 'sha256', $this->canonical->encode( $content ) ),
		);
	}

	/**
	 * Prepare standard.
	 *
	 * @param array<string,mixed> $payload Input payload at this boundary; see the declared shape.
	 * @return array<string,mixed>
	 */
	public function prepare_standard( array $payload ): array {
		$this->exact_keys(
			$payload,
			array( 'code', 'description', 'grade_label', 'standard_id', 'title' )
		);
		$public_id   = $this->optional_public_id( $payload['standard_id'] ?? null );
		$code        = $this->text( $payload['code'] ?? null, 'code', self::MAX_STANDARD_CODE_CHARACTERS, false );
		$title       = $this->text( $payload['title'] ?? null, 'title', self::MAX_TITLE_CHARACTERS, false );
		$description = $this->text( $payload['description'] ?? null, 'description', self::MAX_STANDARD_DESCRIPTION_CHARACTERS, true );
		$grade_label = null;
		if ( null !== ( $payload['grade_label'] ?? null ) ) {
			$grade_label = $this->text( $payload['grade_label'], 'grade_label', self::MAX_GRADE_LABEL_CHARACTERS, true );
			if ( '' === $grade_label ) {
				$grade_label = null;
			}
		}

		$metadata_json = $this->canonical->encode( array( 'managed' => true ) );
		$content       = array(
			'code'        => $code,
			'description' => $description,
			'grade_label' => $grade_label,
			'title'       => $title,
		);

		return array(
			'public_id'        => $public_id,
			'code'             => $code,
			'title'            => $title,
			'description'      => $description,
			'grade_label'      => $grade_label,
			'metadata_schema'  => self::STANDARD_METADATA_SCHEMA,
			'metadata_version' => self::METADATA_VERSION,
			'metadata_json'    => $metadata_json,
			'metadata_hash'    => hash( 'sha256', $metadata_json ),
			'content_hash'     => hash( 'sha256', $this->canonical->encode( $content ) ),
		);
	}

	/**
	 * Project curriculum.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	public function project_curriculum( array $row ): array {
		$this->assert_public_id( $row['public_id'] ?? null );
		$this->assert_hash( $row['content_hash'] ?? null );
		$this->assert_metadata( $row );

		return array(
			'curriculum_id'  => (string) $row['public_id'],
			'source_kind'    => $this->stored_text( $row['source_kind'] ?? null, self::MAX_STATUS_CHARACTERS, false ),
			'source_key'     => $this->stored_text( $row['source_key'] ?? null, self::MAX_SOURCE_KEY_CHARACTERS, false ),
			'source_version' => $this->stored_text( $row['source_version'] ?? null, self::MAX_SOURCE_VERSION_CHARACTERS, false ),
			'title'          => $this->stored_text( $row['title'] ?? null, self::MAX_TITLE_CHARACTERS, false ),
			'locale'         => $this->stored_text( $row['locale'] ?? null, self::MAX_LOCALE_CHARACTERS, false ),
			'state'          => $this->stored_text( $row['state'] ?? null, self::MAX_STATUS_CHARACTERS, false ),
			'lock_version'   => $this->integer( $row['lock_version'] ?? null ),
			'updated_at_utc' => $this->stored_datetime( $row['updated_at_utc'] ?? null ),
		);
	}

	/**
	 * Project standard.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	public function project_standard( array $row ): array {
		$this->assert_public_id( $row['public_id'] ?? null );
		$this->assert_hash( $row['content_hash'] ?? null );
		$this->assert_metadata( $row );

		$grade_label = $row['grade_label'] ?? null;
		if ( null !== $grade_label ) {
			$grade_label = $this->stored_text( $grade_label, self::MAX_GRADE_LABEL_CHARACTERS, true );
		}

		return array(
			'standard_public_id'        => (string) $row['public_id'],
			'code'                      => $this->stored_text( $row['code'] ?? null, self::MAX_STANDARD_CODE_CHARACTERS, false ),
			'title'                     => $this->stored_text( $row['title'] ?? null, self::MAX_TITLE_CHARACTERS, false ),
			'description'               => $this->stored_text( $row['description'] ?? null, self::MAX_STANDARD_DESCRIPTION_CHARACTERS, true ),
			'grade_label'               => $grade_label,
			'curriculum_id'             => $this->stored_public_id( $row['curriculum_public_id'] ?? null ),
			'curriculum_title'          => $this->stored_text( $row['curriculum_title'] ?? null, self::MAX_TITLE_CHARACTERS, false ),
			'curriculum_source_version' => $this->stored_text( $row['curriculum_source_version'] ?? null, self::MAX_SOURCE_VERSION_CHARACTERS, false ),
			'lock_version'              => $this->integer( $row['lock_version'] ?? null ),
			'updated_at_utc'            => $this->stored_datetime( $row['updated_at_utc'] ?? null ),
		);
	}

	/**
	 * Optional public id.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function optional_public_id( $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		if ( ! is_string( $value ) || ! Public_Id::is_valid( $value ) ) {
			throw new InvalidArgumentException( 'Catalog public ID is invalid.' );
		}
		return $value;
	}

	/**
	 * Reject invalid public id.
	 *
	 * @param mixed $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_public_id( $value ): void {
		if ( ! is_string( $value ) || ! Public_Id::is_valid( $value ) ) {
			throw new InvalidArgumentException( 'Stored catalog public ID is invalid.' );
		}
	}

	/**
	 * Stored public id.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function stored_public_id( $value ): string {
		$this->assert_public_id( $value );
		return (string) $value;
	}

	/**
	 * Reject invalid hash.
	 *
	 * @param mixed $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_hash( $value ): void {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Stored catalog hash is invalid.' );
		}
	}

	/**
	 * Reject invalid metadata.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_metadata( array $row ): void {
		if (
			! is_string( $row['metadata_schema'] ?? null )
			|| '' === trim( (string) $row['metadata_schema'] )
			|| strlen( (string) $row['metadata_schema'] ) > self::MAX_METADATA_SCHEMA_CHARACTERS
			|| $this->integer( $row['metadata_version'] ?? null ) < 1
			|| ! is_string( $row['metadata_json'] ?? null )
			|| ! is_string( $row['metadata_hash'] ?? null )
			|| ! hash_equals( (string) $row['metadata_hash'], hash( 'sha256', (string) $row['metadata_json'] ) )
		) {
			throw new InvalidArgumentException( 'Stored catalog metadata is invalid.' );
		}
		$metadata = json_decode( (string) $row['metadata_json'], true );
		if ( ! is_array( $metadata ) ) {
			throw new InvalidArgumentException( 'Stored catalog metadata shape is invalid.' );
		}
	}

	/**
	 * Text.
	 *
	 * @param mixed  $value Value.
	 * @param string $field Field.
	 * @param int    $maximum Maximum.
	 * @param bool   $allow_empty Allow empty.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function text( $value, string $field, int $maximum, bool $allow_empty ): string {
		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( 'Catalog ' . $field . ' must be text.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
		$value = trim( $this->canonical->text( $value ) );
		if (
			( ! $allow_empty && '' === $value )
			|| $this->characters( $value ) > $maximum
			|| 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value )
		) {
			throw new InvalidArgumentException( 'Catalog ' . $field . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
		return $value;
	}

	/**
	 * Stored text.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @param bool  $allow_empty Allow empty.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function stored_text( $value, int $maximum, bool $allow_empty ): string {
		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( 'Stored catalog text is invalid.' );
		}
		$value = $this->canonical->text( $value );
		if (
			( ! $allow_empty && '' === $value )
			|| $this->characters( $value ) > $maximum
			|| 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value )
		) {
			throw new InvalidArgumentException( 'Stored catalog text is invalid.' );
		}
		return $value;
	}

	/**
	 * Integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function integer( $value ): int {
		if ( is_int( $value ) && $value >= 0 ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) {
			return (int) $value;
		}
		throw new InvalidArgumentException( 'Stored catalog integer is invalid.' );
	}

	/**
	 * Stored datetime.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function stored_datetime( $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Stored catalog timestamp is invalid.' );
		}
		return $value;
	}

	/**
	 * Characters.
	 *
	 * @param string $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function characters( string $value ): int {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $value, 'UTF-8' );
		}
		$count = preg_match_all( '/./us', $value );
		if ( false === $count ) {
			throw new InvalidArgumentException( 'Catalog text is invalid UTF-8.' );
		}
		return $count;
	}

	/**
	 * Exact keys.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $expected Expected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function exact_keys( array $value, array $expected ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'Catalog contains unknown or missing fields.' );
		}
	}
}
