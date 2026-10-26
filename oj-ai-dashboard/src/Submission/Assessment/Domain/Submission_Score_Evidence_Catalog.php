<?php
/**
 * Request-local references to literal student evidence, never worksheet prose.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Domain;

use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Builds deterministic source excerpts for reference selection. */
final class Submission_Score_Evidence_Catalog {
	public const MAXIMUM_ENTRIES = 512;
	public const EXCERPT_LENGTH  = 240;

	/**
	 * Append bounded contiguous excerpts without modifying the answer.
	 *
	 * The full original answers remain in the request when the excerpt limit is reached.
	 *
	 * @param array<int,array<string,mixed>> $responses Redacted provider responses.
	 * @param array<int,array<string,mixed>> $artifacts Redacted provider artifacts.
	 * @return array<int,array<string,mixed>>
	 */
	public static function build( array $responses, array $artifacts ): array {
		$entries = array();
		$seen    = array();
		foreach ( $responses as $response ) {
			if ( in_array( $response['kind'], array( 'short_text', 'long_text' ), true ) ) {
				$text = $response['answer']['text'] ?? null;
				if ( is_string( $text ) ) {
					self::append( $entries, $seen, $text, 'response', 'response:' . $response['response_id'], $response['response_id'] );
				}
			}
		}
		foreach ( $artifacts as $artifact ) {
			if ( 'ready' !== $artifact['state'] || ! is_string( $artifact['text'] ) ) {
				continue;
			}
			foreach ( $artifact['anchors'] as $anchor ) {
				self::append( $entries, $seen, $anchor['quote'], 'artifact', $anchor['anchor'], $artifact['response_id'] );
			}
		}
		return $entries;
	}

	/**
	 * Append unique, bounded excerpts from the canonical source text.
	 *
	 * @param array<int,array<string,mixed>> $entries Entries.
	 * @param array<string,bool>             $seen Existing anchor/quote pairs.
	 * @param string                         $text Literal text.
	 * @param string                         $source Source type.
	 * @param string                         $anchor Source anchor.
	 * @param string                         $response_id Owning response.
	 */
	private static function append( array &$entries, array &$seen, string $text, string $source, string $anchor, string $response_id ): void {
		$text   = ( new Canonical_Json() )->text( $text );
		$length = mb_strlen( $text, 'UTF-8' );
		for ( $offset = 0; $offset < $length; $offset += self::EXCERPT_LENGTH ) {
			if ( count( $entries ) >= self::MAXIMUM_ENTRIES ) {
				break;
			}
			$quote = trim( mb_substr( $text, $offset, self::EXCERPT_LENGTH, 'UTF-8' ) );
			if ( '' === $quote ) {
				continue;
			}
			$key = hash( 'sha256', $source . ':' . $anchor . ':' . $quote );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$entries[]    = array(
				'evidence_ref' => 'E' . ( count( $entries ) + 1 ),
				'response_id'  => $response_id,
				'source'       => $source,
				'anchor'       => $anchor,
				'quote'        => $quote,
			);
		}
	}
}
