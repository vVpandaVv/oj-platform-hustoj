<?php
/**
 * ML assessment boundary: bounded evidence and strict, non-executable output.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\ExternalAssessment;

use InvalidArgumentException;

/** Assessment Profile boundary. */
class Assessment_Profile {
	public const TASK          = 'addon.oj_ai_dashboard.ml_submission_assessment';
	public const CONTRACT      = 'ml_submission_assessment.v1';
	public const SOURCE        = 'oj-ml-playgrounds';
	public const PROMPT_SHA256 = '3e54fc9ad9b3471086ec1951d8922778a0f20de4be95ac2237c48e1bc5c58eb2';

	/**
	 * Select an owned contract without accepting caller-supplied task names.
	 *
	 * @param string $source Registered source key.
	 * @return class-string<Assessment_Profile>
	 */
	public static function for_source( string $source ): string {
		return self::SOURCE === $source ? self::class : Common_Assessment_Profile::class;
	}

	/**
	 * Encode deterministic JSON without losing numeric experiment metrics.
	 *
	 * @param mixed $value value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function encode( mixed $value ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Canonical JSON requires explicit numeric and error semantics.
		return json_encode( self::sort( $value ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION );
	}

	/**
	 * Canonicalize nested evidence while rejecting unsupported or unbounded values.
	 *
	 * @param mixed $value value.
	 * @param int   $depth depth.
	 * @return mixed
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private static function sort( mixed $value, int $depth = 0 ): mixed {
		if ( $depth > 16 || is_object( $value ) || is_resource( $value ) || ( is_float( $value ) && ! is_finite( $value ) ) ) {
			throw new InvalidArgumentException( 'ml_ai_invalid_evidence' );
		}
		if ( is_array( $value ) ) {
			if ( ! array_is_list( $value ) ) {
				ksort( $value, SORT_STRING );
			}
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::sort( $item, $depth + 1 );
			}
		}
		return $value;
	}

	/**
	 * Reauthorize the source and validate its bounded evidence contract.
	 *
	 * @param array  $snapshot snapshot.
	 * @param string $id id.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function snapshot( array $snapshot, string $id ): array {
		if ( 'oj_ml_ai_evidence.v1' !== ( $snapshot['schema'] ?? null ) || ( $snapshot['source_id'] ?? null ) !== $id
			|| ! is_string( $snapshot['source_sha256'] ?? null ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $snapshot['source_sha256'] )
			|| ! is_array( $snapshot['assignment'] ?? null ) || ! is_array( $snapshot['evidence'] ?? null )
			|| ! is_array( $snapshot['rubric'] ?? null ) || ! array_is_list( $snapshot['rubric'] )
			|| count( $snapshot['rubric'] ) < 1 || count( $snapshot['rubric'] ) > 30 ) {
			throw new InvalidArgumentException( 'ml_ai_invalid_evidence' );
		}
		self::text( $snapshot['assignment']['title'] ?? null, 1000 );
		self::text( $snapshot['assignment']['instructions'] ?? null, 20000, true );
		$seen    = array();
		$maximum = 0.0;
		foreach ( $snapshot['rubric'] as $criterion ) {
			if ( ! is_array( $criterion ) ) {
				throw new InvalidArgumentException( 'ml_ai_invalid_rubric' );
			}
			$key = self::text( $criterion['id'] ?? null, 100 );
			if ( isset( $seen[ $key ] ) ) {
				throw new InvalidArgumentException( 'ml_ai_invalid_rubric' );
			}
			$seen[ $key ] = true;
			self::text( $criterion['label'] ?? null, 800 );
			self::text( $criterion['description'] ?? null, 8000, true );
			$maximum += self::number( $criterion['max_score'] ?? null, 0.01, 10000 );
		}
		if ( $maximum > 250000 ) {
			throw new InvalidArgumentException( 'ml_ai_invalid_rubric' );
		}
		$snapshot['limitations'] = self::texts( $snapshot['limitations'] ?? array(), 20, 1000 );
		if ( strlen( self::encode( $snapshot ) ) > 120000 ) {
			throw new InvalidArgumentException( 'ml_ai_evidence_too_large' );
		}
		return $snapshot;
	}

	/**
	 * Project educational evidence only, excluding source and actor identifiers.
	 *
	 * @param array $snapshot snapshot.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function input( array $snapshot ): array {
		$rubric = array_map(
			static fn( array $item ): array => array(
				'id'          => $item['id'],
				'label'       => $item['label'],
				'description' => $item['description'],
				'max_score'   => $item['max_score'],
			),
			$snapshot['rubric']
		);
		return array(
			'schema'      => self::CONTRACT,
			'assignment'  => array(
				'title'        => $snapshot['assignment']['title'],
				'instructions' => $snapshot['assignment']['instructions'],
			),
			'rubric'      => $rubric,
			'evidence'    => array(
				'project'   => $snapshot['evidence']['project'] ?? array(),
				'portfolio' => $snapshot['evidence']['portfolio'] ?? array(),
			),
			'limitations' => array_values( array_unique( array_merge( $snapshot['limitations'], array( '성능 지표는 학생 제출 기록이며 서버에서 재실행해 검증한 값이 아닙니다.' ) ) ) ),
		);
	}

	/**
	 * Bind the original artifact and all educational inputs to one digest.
	 *
	 * @param array $snapshot snapshot.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function digest( array $snapshot ): string {
		return hash(
			'sha256',
			self::encode(
				array(
					'prompt_sha256' => static::PROMPT_SHA256,
					'source_id'     => $snapshot['source_id'],
					'source_sha256' => $snapshot['source_sha256'],
					'input'         => static::input( $snapshot ),
				)
			)
		);
	}

	/**
	 * Validate exact rubric coverage and compute a teacher-only draft.
	 *
	 * @param array $output output.
	 * @param array $snapshot snapshot.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function output( array $output, array $snapshot ): array {
		if ( ( $output['schema'] ?? null ) !== static::CONTRACT || ! is_bool( $output['needs_review'] ?? null ) || ! is_array( $output['criteria'] ?? null )
			|| ! array_is_list( $output['criteria'] ) || count( $output['criteria'] ) !== count( $snapshot['rubric'] )
			|| strlen( self::encode( $output ) ) > 60000 ) {
			throw new InvalidArgumentException( 'ml_ai_invalid_output' );
		}
		$by_id = array();
		foreach ( $output['criteria'] as $item ) {
			if ( ! is_array( $item ) || ! is_string( $item['id'] ?? null ) || isset( $by_id[ $item['id'] ] ) ) {
				throw new InvalidArgumentException( 'ml_ai_invalid_output' );
			}
			$by_id[ $item['id'] ] = $item;
		}
		$criteria     = array();
		$total        = 0.0;
		$maximum      = 0.0;
		$needs_review = $output['needs_review'];
		foreach ( $snapshot['rubric'] as $criterion ) {
			$item = $by_id[ $criterion['id'] ] ?? null;
			if ( ! is_array( $item ) ) {
				throw new InvalidArgumentException( 'ml_ai_invalid_output' );
			}
			if ( ! array_key_exists( 'score', $item ) ) {
				throw new InvalidArgumentException( 'ml_ai_invalid_output' ); }
			$score        = null === $item['score'] ? null : round( self::number( $item['score'], 0, (float) $criterion['max_score'] ), 2 );
			$needs_review = $needs_review || null === $score;
			$total       += $score ?? 0;
			$maximum     += (float) $criterion['max_score'];
			$criteria[]   = array(
				'id'        => $criterion['id'],
				'label'     => $criterion['label'],
				'score'     => $score,
				'max_score' => $criterion['max_score'],
				'feedback'  => self::text( $item['feedback'] ?? null, 2000 ),
				'evidence'  => self::text( $item['evidence'] ?? null, 4000 ),
			);
		}
		return array(
			'criteria'     => $criteria,
			'needs_review' => $needs_review,
			'total_score'  => $needs_review ? null : round( $total, 2 ),
			'max_score'    => round( $maximum, 2 ),
			'summary'      => self::text( $output['summary'] ?? null, 5000 ),
			'limitations'  => array_values( array_unique( array_merge( static::input( $snapshot )['limitations'], self::texts( $output['limitations'] ?? array(), 20, 1000 ) ) ) ),
		);
	}

	/**
	 * Validate bounded UTF-8 text without interpreting student content.
	 *
	 * @param mixed $value value.
	 * @param int   $limit limit.
	 * @param bool  $allow_empty empty.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	protected static function text( mixed $value, int $limit, bool $allow_empty = false ): string {
		if ( ! is_string( $value ) || ( ! $allow_empty && '' === trim( $value ) ) || strlen( $value ) > $limit || 1 !== preg_match( '//u', $value ) ) {
			throw new InvalidArgumentException( 'ml_ai_invalid_text' );
		}
		return $value;
	}

	/**
	 * Validate a bounded list of plain-text notices.
	 *
	 * @param mixed $value value.
	 * @param int   $count count.
	 * @param int   $length length.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	protected static function texts( mixed $value, int $count, int $length ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > $count ) {
			throw new InvalidArgumentException( 'ml_ai_invalid_text' );
		}
		return array_map( static fn( mixed $item ): string => self::text( $item, $length ), $value );
	}

	/**
	 * Validate a finite score inside the server-owned rubric range.
	 *
	 * @param mixed $value value.
	 * @param float $min min.
	 * @param float $max max.
	 * @return float
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	protected static function number( mixed $value, float $min, float $max ): float {
		if ( ( ! is_int( $value ) && ! is_float( $value ) ) || ! is_finite( (float) $value ) || $value < $min || $value > $max ) {
			throw new InvalidArgumentException( 'ml_ai_invalid_score' );
		}
		return (float) $value;
	}
}
