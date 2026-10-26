<?php
/**
 * Provider-neutral structured score output, independently checked by the normalizer.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Describes provider output without replacing semantic validation. */
final class Submission_Score_Output_Schema {
	/**
	 * Build the schema for the current request only.
	 *
	 * @param array<int,string> $binding_refs Request-local binding references.
	 * @param array<int,string> $evidence_refs Request-local evidence references.
	 * @param string            $evaluation_ref Subject reference.
	 * @param string            $source_hash Immutable source hash.
	 * @return array<string,mixed>
	 */
	public static function build( array $binding_refs, array $evidence_refs, string $evaluation_ref, string $source_hash ): array {
		$evidence = self::object(
			array(
				'source' => array( 'enum' => array( 'response', 'artifact' ) ),
				'anchor' => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
				'quote'  => array(
					'type'      => array( 'string', 'null' ),
					'maxLength' => 240,
				),
			)
		);
		if ( array() !== $evidence_refs ) {
			$evidence = array( 'anyOf' => array( self::object( array( 'evidence_ref' => array( 'enum' => $evidence_refs ) ) ), $evidence ) );
		}
		$common          = array(
			'binding_key' => array( 'enum' => $binding_refs ),
			'confidence'  => array(
				'type'    => 'number',
				'minimum' => 0,
				'maximum' => 1,
			),
			'evidence'    => array(
				'type'     => 'array',
				'items'    => array( '$ref' => '#/$defs/evidence' ),
				'maxItems' => 3,
			),
			'flags'       => array(
				'type'        => 'array',
				'items'       => array( '$ref' => '#/$defs/flag' ),
				'uniqueItems' => true,
			),
		);
		$score           = self::object(
			array_merge(
				$common,
				array(
					'decision'         => array( 'const' => 'score' ),
					'candidate_points' => array(
						'type'    => 'string',
						'pattern' => '^[0-9]+(\\.[0-9]{1,4})?$',
					),
					'rationale'        => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 1500,
					),
					'evidence'         => array(
						'type'     => 'array',
						'items'    => array( '$ref' => '#/$defs/evidence' ),
						'minItems' => 1,
						'maxItems' => 3,
					),
				)
			)
		);
		$abstain         = self::object(
			array_merge(
				$common,
				array(
					'decision'         => array( 'const' => 'abstain' ),
					'candidate_points' => array( 'type' => 'null' ),
					'abstain_reason'   => array( 'enum' => array( 'artifact_unavailable', 'insufficient_evidence', 'ambiguous_response', 'safety_review' ) ),
				)
			)
		);
		$schema          = self::object(
			array(
				'schema_id'      => array( 'const' => Submission_Score_Input_Builder::OUTPUT_SCHEMA ),
				'schema_version' => array( 'const' => Submission_Score_Input_Builder::OUTPUT_VERSION ),
				'evaluation_ref' => array( 'const' => $evaluation_ref ),
				'source_hash'    => array( 'const' => $source_hash ),
				'items'          => array(
					'type'     => 'array',
					'items'    => array( '$ref' => '#/$defs/item' ),
					'minItems' => count( $binding_refs ),
					'maxItems' => count( $binding_refs ),
				),
				'summary'        => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 3000,
				),
				'limitations'    => array(
					'type'     => 'array',
					'items'    => array(
						'type'      => 'string',
						'maxLength' => 500,
					),
					'maxItems' => 20,
				),
			)
		);
		$schema['$defs'] = array(
			'item'     => array( 'anyOf' => array( $score, $abstain ) ),
			'evidence' => $evidence,
			'flag'     => array( 'enum' => Submission_Evaluation_Output_Normalizer::FLAGS ),
		);
		return $schema;
	}

	/**
	 * Define an object with required keys and no extra properties.
	 *
	 * @param array<string,mixed> $properties Required properties.
	 * @return array<string,mixed>
	 */
	private static function object( array $properties ): array {
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array_keys( $properties ),
			'additionalProperties' => false,
		);
	}
}
