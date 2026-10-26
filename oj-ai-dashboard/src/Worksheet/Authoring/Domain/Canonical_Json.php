<?php
/**
 * Worksheet canonical JSON encoder.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Domain;

use InvalidArgumentException;
use JsonException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sorts object keys, preserves list order, normalizes text, and rejects floats.
 */
final class Canonical_Json {
	/**
	 * Encode canonical json.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function encode( $value ): string {
		try {
			return json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Domain JSON uses explicit flags and error handling; fallback re-encoding would change canonical bytes.
				$this->normalize( $value ),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
			);
		} catch ( JsonException $error ) {
			throw new InvalidArgumentException( 'Canonical JSON encoding failed.', 0, $error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The diagnostic text is literal; a chained Throwable is not HTML output.
		}
	}

	/**
	 * Text.
	 *
	 * @param string $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function text( string $value ): string {
		if ( 1 !== preg_match( '//u', $value ) ) {
			throw new InvalidArgumentException( 'Canonical text must be valid UTF-8.' );
		}

		if ( class_exists( '\\Normalizer' ) ) {
			$normalized = \Normalizer::normalize( $value, \Normalizer::FORM_C );
			if ( ! is_string( $normalized ) ) {
				throw new InvalidArgumentException( 'Unicode NFC normalization failed.' );
			}
			return $normalized;
		}

		if ( 1 === preg_match( '/^[\\x00-\\x7F]*$/D', $value ) ) {
			return $value;
		}

		throw new InvalidArgumentException( 'Unicode NFC normalization requires PHP intl.' );
	}

	/**
	 * Normalize.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function normalize( $value ) {
		if ( null === $value || is_bool( $value ) || is_int( $value ) ) {
			return $value;
		}
		if ( is_float( $value ) ) {
			throw new InvalidArgumentException( 'Canonical worksheet JSON uses decimal strings, not floats.' );
		}
		if ( is_string( $value ) ) {
			return $this->text( $value );
		}
		if ( ! is_array( $value ) ) {
			throw new InvalidArgumentException( 'Canonical worksheet JSON contains an unsupported value.' );
		}

		if ( array_is_list( $value ) ) {
			return array_map( fn ( $item ) => $this->normalize( $item ), $value );
		}

		$normalized = array();
		foreach ( $value as $key => $item ) {
			if ( ! is_string( $key ) ) {
				throw new InvalidArgumentException( 'Canonical JSON object keys must be strings.' );
			}
			$normalized[ $key ] = $this->normalize( $item );
		}
		ksort( $normalized, SORT_STRING );

		return $normalized;
	}
}
