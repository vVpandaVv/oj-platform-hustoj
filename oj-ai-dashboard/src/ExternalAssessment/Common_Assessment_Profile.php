<?php
/**
 * Text-answer contract sharing the established rubric-result validation.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\ExternalAssessment;

use InvalidArgumentException;

/** Common assessment input; no source plugin internals or executable content. */
final class Common_Assessment_Profile extends Assessment_Profile {
	public const TASK          = 'addon.oj_ai_dashboard.external_submission_assessment';
	public const CONTRACT      = 'external_submission_assessment.v1';
	public const EVIDENCE      = 'oj_ai_assessment_evidence.v1';
	public const PROMPT_SHA256 = '97f1ffc09918740233e26625dab04af14b2feb658c843bef87d74a9b253b6e9e';

	/**
	 * Validate and project one authorized source snapshot.
	 *
	 * @param array  $snapshot Source-owned answer and rubric snapshot.
	 * @param string $id Exact source record identifier.
	 * @return array
	 * @throws InvalidArgumentException If input is malformed or exceeds limits.
	 */
	public static function snapshot( array $snapshot, string $id ): array {
		if ( self::EVIDENCE !== ( $snapshot['schema'] ?? null ) || $id !== ( $snapshot['source_id'] ?? null )
			|| ! is_array( $snapshot['assignment'] ?? null ) || strlen( self::encode( $snapshot ) ) > 120000 ) {
			throw new InvalidArgumentException( 'assessment_invalid_input' );
		}
		self::bounded_list( $snapshot['answers'] ?? null, 100 );
		self::bounded_list( $snapshot['rubric'] ?? null, 30 );
		$answers = array();
		$seen    = array();
		foreach ( $snapshot['answers'] as $answer ) {
			if ( ! is_array( $answer ) ) {
				throw new InvalidArgumentException( 'assessment_invalid_answer' );
			}
			$key = self::text( $answer['id'] ?? null, 100 );
			if ( isset( $seen[ $key ] ) ) {
				throw new InvalidArgumentException( 'assessment_duplicate_answer' );
			}
			$seen[ $key ] = true;
			$answers[]    = array(
				'id'   => $key,
				'text' => self::text( $answer['text'] ?? null, 50000, true ),
			);
		}
		$rubric  = array();
		$ids     = array();
		$maximum = 0.0;
		foreach ( $snapshot['rubric'] as $criterion ) {
			if ( ! is_array( $criterion ) ) {
				throw new InvalidArgumentException( 'assessment_invalid_rubric' );
			}
			$key = self::text( $criterion['id'] ?? null, 100 );
			if ( isset( $ids[ $key ] ) ) {
				throw new InvalidArgumentException( 'assessment_duplicate_criterion' );
			}
			$ids[ $key ] = true;
			$targets     = array_key_exists( 'answer_ids', $criterion ) ? $criterion['answer_ids'] : array_column( $answers, 'id' );
			self::bounded_list( $targets, 100 );
			$targets = self::texts( $targets, 100, 100 );
			if ( count( array_unique( $targets ) ) !== count( $targets ) || array_diff( $targets, array_column( $answers, 'id' ) ) ) {
				throw new InvalidArgumentException( 'assessment_invalid_binding' );
			}
			$score = self::number( $criterion['max_score'] ?? null, 0.01, 10000 );
			if ( abs( $score - round( $score, 2 ) ) > 0.00000001 ) {
				throw new InvalidArgumentException( 'assessment_invalid_score_precision' );
			}
			$maximum += $score;
			$rubric[] = array(
				'id'          => $key,
				'label'       => self::text( $criterion['label'] ?? null, 800 ),
				'description' => self::text( $criterion['description'] ?? null, 8000 ),
				'max_score'   => $score,
				'answer_ids'  => $targets,
			);
		}
		if ( $maximum > 250000 ) {
			throw new InvalidArgumentException( 'assessment_invalid_rubric' );
		}
		$normalized = array(
			'schema'      => self::EVIDENCE,
			'source_id'   => $id,
			'assignment'  => array(
				'title'        => self::text( $snapshot['assignment']['title'] ?? null, 1000 ),
				'instructions' => self::text( $snapshot['assignment']['instructions'] ?? null, 20000, true ),
			),
			'answers'     => $answers,
			'rubric'      => $rubric,
			'limitations' => self::texts( $snapshot['limitations'] ?? array(), 20, 1000 ),
		);
		$hash       = array_key_exists( 'source_sha256', $snapshot ) ? $snapshot['source_sha256'] : hash( 'sha256', self::encode( self::input( $normalized ) ) );
		if ( ! is_string( $hash ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
			throw new InvalidArgumentException( 'assessment_invalid_source_hash' );
		}
		$normalized['source_sha256'] = $hash;
		// Expanded default bindings also count toward the provider payload limit.
		if ( strlen( self::encode( $normalized ) ) > 120000 ) {
			throw new InvalidArgumentException( 'assessment_input_too_large' );
		}
		return $normalized;
	}

	/**
	 * Send educational fields only, never source metadata or actor identifiers.
	 *
	 * @param array $snapshot Validated normalized snapshot.
	 * @return array
	 */
	public static function input( array $snapshot ): array {
		return array(
			'schema'      => self::CONTRACT,
			'assignment'  => $snapshot['assignment'],
			'answers'     => $snapshot['answers'],
			'rubric'      => $snapshot['rubric'],
			'limitations' => $snapshot['limitations'],
		);
	}

	/**
	 * Require a nonempty bounded list before iterating untrusted data.
	 *
	 * @param mixed $value Candidate list.
	 * @param int   $limit Maximum entries.
	 * @return void
	 * @throws InvalidArgumentException If the list is malformed.
	 */
	private static function bounded_list( mixed $value, int $limit ): void {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) < 1 || count( $value ) > $limit ) {
			throw new InvalidArgumentException( 'assessment_invalid_list' );
		}
	}
}
