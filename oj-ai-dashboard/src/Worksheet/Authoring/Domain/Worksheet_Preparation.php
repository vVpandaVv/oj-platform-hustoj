<?php
/**
 * Criteria-first authoring readiness.
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
 * Criteria-first authoring readiness.
 */
final class Worksheet_Preparation {
	/**
	 * Draft.
	 *
	 * @return array
	 */
	public static function draft(): array {
		return array(
			'state'               => 'draft',
			'rubric_revision_id'  => null,
			'rubric_content_hash' => null,
		);
	}

	/**
	 * Canonicalize.
	 *
	 * @param mixed $value Value.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function canonicalize( mixed $value ): array {
		if ( ! is_array( $value ) || array_is_list( $value ) || 3 !== ( count( $value ) )
			|| array_diff( array_keys( $value ), array_keys( self::draft() ) )
			|| ! in_array( $value['state'] ?? null, array( 'draft', 'confirmed' ), true ) ) {
			throw Worksheet_Document_Validation_Failure::property_not_allowed( '$.settings.preparation' );
		}
		if ( 'draft' === $value['state'] ) {
			if ( null !== $value['rubric_revision_id'] || null !== $value['rubric_content_hash'] ) {
				throw Worksheet_Document_Validation_Failure::property_not_allowed( '$.settings.preparation' );
			}
			return self::draft();
		}
		if ( ! is_string( $value['rubric_revision_id'] ) || ! Public_Id::is_valid( $value['rubric_revision_id'] ) ) {
			throw Worksheet_Document_Validation_Failure::property_not_allowed( '$.settings.preparation.rubric_revision_id' );
		}
		if ( ! is_string( $value['rubric_content_hash'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value['rubric_content_hash'] ) ) {
			throw Worksheet_Document_Validation_Failure::property_not_allowed( '$.settings.preparation.rubric_content_hash' );
		}
		return array(
			'state'               => 'confirmed',
			'rubric_revision_id'  => $value['rubric_revision_id'],
			'rubric_content_hash' => $value['rubric_content_hash'],
		);
	}

	/**
	 * Old immutable documents without preparation retain their original contract.
	 *
	 * @param array $document Document.
	 * @return bool
	 */
	public static function ready( array $document ): bool {
		$settings = $document['settings'] ?? array();
		return ! array_key_exists( 'preparation', $settings ) || (
			'confirmed' === ( $settings['preparation']['state'] ?? null )
			&& ! empty( $settings['learning_goals'] ) && ! empty( $settings['assessment_bindings'] )
		);
	}

	/**
	 * Reject invalid ready.
	 *
	 * @param array      $document Document.
	 * @param array|null $rubric Rubric.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function assert_ready( array $document, ?array $rubric = null ): void {
		if ( ! self::ready( $document ) ) {
			throw new InvalidArgumentException( 'Worksheet criteria must be confirmed before review or publication.' );
		}
		$pin = $document['settings']['preparation'] ?? null;
		if ( null !== $pin && null !== $rubric && (
			$pin['rubric_revision_id'] !== ( $rubric['revision_id'] ?? null )
			|| $pin['rubric_content_hash'] !== ( $rubric['content_hash'] ?? null )
		) ) {
			throw new InvalidArgumentException( 'Worksheet criteria changed; confirmation is required.' );
		}
	}
}
