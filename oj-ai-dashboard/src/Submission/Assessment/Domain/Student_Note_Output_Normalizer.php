<?php
/**
 * Strict student special-note output normalizer.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Domain;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Strict student special-note output normalizer.
 */
final class Student_Note_Output_Normalizer {
	public const MAXIMUM_OUTPUT_BYTES = 16384;
	public const SAFETY_FLAGS         = array( 'comparative_language', 'diagnostic_label', 'family_circumstance_inference', 'health_inference', 'identity_reference', 'motivation_inference', 'personality_inference', 'protected_trait_inference', 'score_disclosure', 'stigmatizing_language', 'unsupported_claim' );

	/**
	 * Initialize student note output normalizer dependencies and state.
	 *
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct( private Canonical_Json $canonical ) {
	}

	/**
	 * Safe diagnostic categories, never provider text or unrecognized exception content.
	 *
	 * @param InvalidArgumentException $error Validation failure.
	 * @return string
	 */
	public static function failure_message( InvalidArgumentException $error ): string {
		return match ( $error->getMessage() ) {
			'Student note output source is invalid.' => 'AI 응답의 원본 평가 확인 정보가 일치하지 않습니다.',
			'Student note evidence is not bound to a known observation.' => 'AI가 반환한 근거 문구가 제공된 원문과 일치하지 않습니다.',
			'Student note evidence reference is invalid.',
			'Student note evidence references are invalid.',
			'Student note evidence is invalid.',
			'Student note evidence item is invalid.' => '특기사항 문장에 연결된 근거가 누락되었거나 올바르지 않습니다.',
			'Student note hash is invalid.' => 'AI가 반환한 근거 또는 원본 확인값의 형식이 올바르지 않습니다.',
			'Student note text contains a prohibited inference or disclosure.' => '학생 신상·능력 추정, 비교 또는 점수 공개에 해당하는 표현이 감지되었습니다.',
			'Student note output requires safety review.',
			'Stored student note requires safety review.' => 'AI가 근거 없는 서술 또는 기재 제한 내용의 검토 필요를 표시했습니다.',
			'Student note text contains external or markup content.' => '특기사항에 허용되지 않는 링크·연락처 또는 서식 코드가 포함되어 있습니다.',
			'Student note output is too large.',
			'Student note result is too large.' => 'AI 특기사항 응답이 허용된 전체 분량을 초과했습니다.',
			'Student note text is invalid.' => '특기사항 문장이 비어 있거나 길이·문자 조건을 충족하지 않습니다.',
			'Student note narrative is duplicated.' => 'AI가 동일한 특기사항 항목을 중복 작성했습니다.',
			default => 'AI 특기사항 응답이 필요한 항목 또는 구조를 충족하지 않습니다.',
		};
	}

	/**
	 * Normalize.
	 *
	 * @param array<string,mixed> $output Output.
	 * @param array<string,mixed> $input Input.
	 * @return Student_Note_Normalized_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function normalize( array $output, array $input ): Student_Note_Normalized_Result {
		$json = $this->canonical->encode( $output );
		if ( strlen( $json ) > self::MAXIMUM_OUTPUT_BYTES ) {
			throw new InvalidArgumentException( 'Student note output is too large.' );
		}
		$this->exact_keys( $output, array( 'evidence', 'note_ref', 'safety_flags', 'schema_id', 'schema_version', 'source_hash', 'strengths', 'summary', 'support_suggestions' ) );
		$payload = $this->input_payload( $input );
		$subject = $payload['subject'] ?? null;
		if ( ! is_array( $subject ) || array_is_list( $subject ) ) {
			throw new InvalidArgumentException( 'Student note input subject is invalid.' );
		}
		if (
			Student_Note_Input_Builder::OUTPUT_SCHEMA !== ( $output['schema_id'] ?? null )
			|| Student_Note_Input_Builder::OUTPUT_VERSION !== ( $output['schema_version'] ?? null )
			|| ! hash_equals( (string) ( $subject['note_ref'] ?? '' ), (string) ( $output['note_ref'] ?? '' ) )
			|| ! hash_equals( (string) ( $subject['source_hash'] ?? '' ), (string) ( $output['source_hash'] ?? '' ) )
		) {
			throw new InvalidArgumentException( 'Student note output source is invalid.' );
		}

		$known       = $this->evidence_index( $payload['evidence'] ?? null );
		$evidence    = $this->evidence( $output['evidence'] ?? null, $known );
		$selected    = array_fill_keys( array_column( $evidence, 'evidence_key' ), true );
		$summary     = $this->narrative( $output['summary'] ?? null, Student_Note_Input_Builder::MAXIMUM_SUMMARY_CHARACTERS, $selected );
		$strengths   = $this->narrative_list( $output['strengths'] ?? null, Student_Note_Input_Builder::MAXIMUM_STRENGTHS, $selected );
		$suggestions = $this->narrative_list( $output['support_suggestions'] ?? null, Student_Note_Input_Builder::MAXIMUM_SUPPORT_SUGGESTIONS, $selected );
		$flags       = $this->flags( $output['safety_flags'] ?? null );
		if ( array() !== $flags ) {
			throw new InvalidArgumentException( 'Student note output requires safety review.' );
		}

		$profile      = array(
			'evidence'            => $evidence,
			'note_ref'            => (string) $output['note_ref'],
			'safety_flags'        => array(),
			'schema_id'           => Student_Note_Input_Builder::RESULT_SCHEMA,
			'schema_version'      => Student_Note_Input_Builder::RESULT_VERSION,
			'source_hash'         => (string) $output['source_hash'],
			'strengths'           => $strengths,
			'summary'             => $summary,
			'support_suggestions' => $suggestions,
		);
		$profile      = $this->validate_stored_profile( $profile );
		$profile_json = $this->canonical->encode( $profile );
		if ( strlen( $profile_json ) > self::MAXIMUM_OUTPUT_BYTES ) {
			throw new InvalidArgumentException( 'Student note result is too large.' );
		}
		return new Student_Note_Normalized_Result( $profile, $profile_json, hash( 'sha256', $profile_json ), hash( 'sha256', $json ), false );
	}

	/**
	 * Validate stored profile.
	 *
	 * @param array<string,mixed> $profile Profile.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function validate_stored_profile( array $profile ): array {
		$this->exact_keys( $profile, array( 'evidence', 'note_ref', 'safety_flags', 'schema_id', 'schema_version', 'source_hash', 'strengths', 'summary', 'support_suggestions' ) );
		if (
			Student_Note_Input_Builder::RESULT_SCHEMA !== ( $profile['schema_id'] ?? null )
			|| Student_Note_Input_Builder::RESULT_VERSION !== ( $profile['schema_version'] ?? null )
		) {
			throw new InvalidArgumentException( 'Stored student note schema is invalid.' );
		}
		$note_ref = $profile['note_ref'] ?? null;
		if ( ! is_string( $note_ref ) || 1 !== preg_match( '/^SN_[A-Z2-7]{32}$/D', $note_ref ) ) {
			throw new InvalidArgumentException( 'Stored student note reference is invalid.' );
		}
		$source_hash = $this->sha256( $profile['source_hash'] ?? null );
		$evidence    = $this->stored_evidence( $profile['evidence'] ?? null );
		$known       = array_fill_keys( array_column( $evidence, 'evidence_key' ), true );
		$summary     = $this->narrative( $profile['summary'] ?? null, Student_Note_Input_Builder::MAXIMUM_SUMMARY_CHARACTERS, $known );
		$strengths   = $this->narrative_list( $profile['strengths'] ?? null, Student_Note_Input_Builder::MAXIMUM_STRENGTHS, $known );
		$suggestions = $this->narrative_list( $profile['support_suggestions'] ?? null, Student_Note_Input_Builder::MAXIMUM_SUPPORT_SUGGESTIONS, $known );
		$flags       = $this->flags( $profile['safety_flags'] ?? null );
		if ( array() !== $flags ) {
			throw new InvalidArgumentException( 'Stored student note requires safety review.' );
		}

		$normalized = array(
			'evidence'            => $evidence,
			'note_ref'            => $note_ref,
			'safety_flags'        => array(),
			'schema_id'           => Student_Note_Input_Builder::RESULT_SCHEMA,
			'schema_version'      => Student_Note_Input_Builder::RESULT_VERSION,
			'source_hash'         => $source_hash,
			'strengths'           => $strengths,
			'summary'             => $summary,
			'support_suggestions' => $suggestions,
		);
		if ( ! hash_equals( $this->canonical->encode( $profile ), $this->canonical->encode( $normalized ) ) ) {
			throw new InvalidArgumentException( 'Stored student note representation drifted.' );
		}
		return $normalized;
	}

	/**
	 * Input payload.
	 *
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function input_payload( array $input ): array {
		$this->exact_keys( $input, array( 'operation', 'operation_contract', 'operation_payload', 'operation_schema', 'payload_hash' ) );
		$payload = $input['operation_payload'] ?? null;
		if (
			Student_Note_Input_Builder::OPERATION !== ( $input['operation'] ?? null )
			|| Student_Note_Input_Builder::OPERATION_CONTRACT !== ( $input['operation_contract'] ?? null )
			|| Student_Note_Input_Builder::OPERATION_SCHEMA !== ( $input['operation_schema'] ?? null )
			|| ! is_array( $payload ) || array_is_list( $payload )
			|| ! hash_equals( $this->sha256( $input['payload_hash'] ?? null ), hash( 'sha256', $this->canonical->encode( $payload ) ) )
		) {
			throw new InvalidArgumentException( 'Student note input contract is invalid.' );
		}
		return $payload;
	}

	/**
	 * Evidence index.
	 *
	 * @param mixed $value Value.
	 * @return array<string,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function evidence_index( $value ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || array() === $value || count( $value ) > Student_Note_Input_Builder::MAXIMUM_EVIDENCE ) {
			throw new InvalidArgumentException( 'Student note input evidence is invalid.' );
		}
		$result = array();
		foreach ( $value as $item ) {
			if ( ! is_array( $item ) || array_is_list( $item ) ) {
				throw new InvalidArgumentException( 'Student note input evidence item is invalid.' );
			}
			$key         = $this->sha256( $item['evidence_key'] ?? null );
			$observation = $this->text( $item['known_observation'] ?? null, Student_Note_Input_Builder::MAXIMUM_EVIDENCE_OBSERVATION_CHARACTERS, false );
			if ( isset( $result[ $key ] ) ) {
				throw new InvalidArgumentException( 'Student note input evidence is duplicated.' );
			}
			$result[ $key ] = $observation;
		}
		return $result;
	}

	/**
	 * Evidence.
	 *
	 * @param mixed                $value Value.
	 * @param array<string,string> $known Known.
	 * @return array<int,array<string,string>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function evidence( $value, array $known ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || array() === $value || count( $value ) > count( $known ) ) {
			throw new InvalidArgumentException( 'Student note evidence is invalid.' );
		}
		$result = array();
		$seen   = array();
		foreach ( $value as $item ) {
			if ( ! is_array( $item ) || array_is_list( $item ) ) {
				throw new InvalidArgumentException( 'Student note evidence item is invalid.' );
			}
			$this->exact_keys( $item, array( 'evidence_key', 'observation' ) );
			$key         = $this->sha256( $item['evidence_key'] ?? null );
			$observation = $this->text( $item['observation'] ?? null, Student_Note_Input_Builder::MAXIMUM_EVIDENCE_OBSERVATION_CHARACTERS, false );
			if ( isset( $seen[ $key ] ) || ! isset( $known[ $key ] ) || ! hash_equals( $known[ $key ], $observation ) ) {
				throw new InvalidArgumentException( 'Student note evidence is not bound to a known observation.' );
			}
			$result[]     = array(
				'evidence_key' => $key,
				'observation'  => $observation,
			);
			$seen[ $key ] = true;
		}
		usort( $result, static fn ( array $left, array $right ): int => strcmp( $left['evidence_key'], $right['evidence_key'] ) );
		return $result;
	}

	/**
	 * Stored evidence.
	 *
	 * @param mixed $value Value.
	 * @return array<int,array<string,string>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function stored_evidence( $value ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || array() === $value || count( $value ) > Student_Note_Input_Builder::MAXIMUM_EVIDENCE ) {
			throw new InvalidArgumentException( 'Stored student note evidence is invalid.' );
		}
		$result = array();
		$seen   = array();
		foreach ( $value as $item ) {
			if ( ! is_array( $item ) || array_is_list( $item ) ) {
				throw new InvalidArgumentException( 'Stored student note evidence item is invalid.' );
			}
			$this->exact_keys( $item, array( 'evidence_key', 'observation' ) );
			$key = $this->sha256( $item['evidence_key'] ?? null );
			if ( isset( $seen[ $key ] ) ) {
				throw new InvalidArgumentException( 'Stored student note evidence is duplicated.' );
			}
			$result[]     = array(
				'evidence_key' => $key,
				'observation'  => $this->text( $item['observation'] ?? null, Student_Note_Input_Builder::MAXIMUM_EVIDENCE_OBSERVATION_CHARACTERS, false ),
			);
			$seen[ $key ] = true;
		}
		usort( $result, static fn ( array $left, array $right ): int => strcmp( $left['evidence_key'], $right['evidence_key'] ) );
		return $result;
	}

	/**
	 * Narrative.
	 *
	 * @param mixed              $value Value.
	 * @param int                $maximum Maximum.
	 * @param array<string,bool> $known Known.
	 * @return array{evidence_keys:array<int,string>,text:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function narrative( $value, int $maximum, array $known ): array {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			throw new InvalidArgumentException( 'Student note narrative is invalid.' );
		}
		$this->exact_keys( $value, array( 'evidence_keys', 'text' ) );
		$keys = $this->evidence_keys( $value['evidence_keys'] ?? null, $known );
		$text = $this->text( $value['text'] ?? null, $maximum, true );
		return array(
			'evidence_keys' => $keys,
			'text'          => $text,
		);
	}

	/**
	 * Narrative list.
	 *
	 * @param mixed              $value Value.
	 * @param int                $maximum_count Maximum count.
	 * @param array<string,bool> $known Known.
	 * @return array<int,array{evidence_keys:array<int,string>,text:string}>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function narrative_list( $value, int $maximum_count, array $known ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > $maximum_count ) {
			throw new InvalidArgumentException( 'Student note narrative list is invalid.' );
		}
		$result = array();
		$seen   = array();
		foreach ( $value as $item ) {
			$narrative = $this->narrative( $item, Student_Note_Input_Builder::MAXIMUM_ITEM_CHARACTERS, $known );
			if ( isset( $seen[ $narrative['text'] ] ) ) {
				throw new InvalidArgumentException( 'Student note narrative is duplicated.' );
			}
			$result[]                   = $narrative;
			$seen[ $narrative['text'] ] = true;
		}
		return $result;
	}

	/**
	 * Evidence keys.
	 *
	 * @param mixed              $value Value.
	 * @param array<string,bool> $known Known.
	 * @return array<int,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function evidence_keys( $value, array $known ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || array() === $value || count( $value ) > count( $known ) ) {
			throw new InvalidArgumentException( 'Student note evidence references are invalid.' );
		}
		$result = array();
		foreach ( $value as $key ) {
			$key = $this->sha256( $key );
			if ( ! isset( $known[ $key ] ) || in_array( $key, $result, true ) ) {
				throw new InvalidArgumentException( 'Student note evidence reference is invalid.' );
			}
			$result[] = $key;
		}
		sort( $result, SORT_STRING );
		return $result;
	}

	/**
	 * Flags.
	 *
	 * @param mixed $value Value.
	 * @return array<int,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function flags( $value ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > count( self::SAFETY_FLAGS ) ) {
			throw new InvalidArgumentException( 'Student note safety flags are invalid.' );
		}
		$result = array();
		foreach ( $value as $flag ) {
			if ( ! is_string( $flag ) || ! in_array( $flag, self::SAFETY_FLAGS, true ) || in_array( $flag, $result, true ) ) {
				throw new InvalidArgumentException( 'Student note safety flag is invalid.' );
			}
			$result[] = $flag;
		}
		sort( $result, SORT_STRING );
		return $result;
	}

	/**
	 * Text.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @param bool  $screen_semantics Screen semantics.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function text( $value, int $maximum, bool $screen_semantics ): string {
		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( 'Student note text is invalid.' );
		}
		$value = trim( $value );
		if ( '' === $value || $this->length( $value ) > $maximum || 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value ) ) {
			throw new InvalidArgumentException( 'Student note text is invalid.' );
		}
		if ( 1 === preg_match( '/<[^>]+>|(?:https?:\/\/|www\.)\S+|\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/iu', $value ) ) {
			throw new InvalidArgumentException( 'Student note text contains external or markup content.' );
		}
		// Exempt only the academic compound, not other inferences in the same sentence.
		$screening_text = preg_replace( '/인공\s*지능|\bartificial\s+intelligence\b/iu', 'AI', $value ) ?? $value;
		if ( $screen_semantics && 1 === preg_match( '/(?:personality|intelligence|motivation|family|parent|health|disab(?:ility|led)|diagnos(?:is|ed)|adhd|autis(?:m|tic)|lazy|gifted|성격|지능|동기|가정환경|가족|부모|건강|장애|진단|주의력결핍|게으르|영재|classmate|peer|other students?|better than|worse than|average|rank|percentile|다른 학생|또래|평균보다|상위|하위|등수|석차|백분위|\bscore\b|\bpoints?\b|\bgrade\b|점수|득점|총점|student[_ ]?id|e-?mail|login|username|학생\s*id|이름|이메일|로그인|학급명|반명|그룹명)/iu', $screening_text ) ) {
			throw new InvalidArgumentException( 'Student note text contains a prohibited inference or disclosure.' );
		}
		return $value;
	}

	/**
	 * Length.
	 *
	 * @param string $value Value.
	 * @return int
	 */
	private function length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}
	/**
	 * Sha256.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function sha256( $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Student note hash is invalid.' );
		} return $value;
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
			throw new InvalidArgumentException( 'Student note object shape is invalid.' );
		}
	}
}
