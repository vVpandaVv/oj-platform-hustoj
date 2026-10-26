<?php
/**
 * Frozen assessment policy, rubric, and review-rule profiles.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Assessment\Configuration\Domain;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Input_Builder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frozen assessment policy, rubric, and review-rule profiles.
 */
final class Assessment_Configuration_Profile {
	public const POLICY      = 'policy';
	public const RUBRIC      = 'rubric';
	public const REVIEW_RULE = 'review_rule';

	public const WORKSHEET_QUALITY          = 'worksheet_quality';
	public const STUDENT_SUBMISSION         = 'student_submission';
	public const MAXIMUM_LEVEL_LABEL_LENGTH = 500;

	public const STATE_DRAFT   = 'draft';
	public const STATE_ACTIVE  = 'active';
	public const STATE_RETIRED = 'retired';

	private const CORE_DIMENSION_LABELS       = array(
		'learning_goal_alignment' => '학습 목표 정렬',
		'instruction_clarity'     => '안내 명확성',
		'answerability'           => '응답 가능성',
		'difficulty_fit'          => '난이도 적합성',
		'rubric_quality'          => '평가 기준 품질',
		'safety'                  => '안전성',
	);
	private const CORE_DIMENSION_DESCRIPTIONS = array(
		'learning_goal_alignment' => '연결된 학습 목표·성취기준과 활동지 전체 또는 문항의 내용·응답이 실제로 맞닿는지 확인합니다. 연결이 없으면 판단하지 않습니다.',
		'instruction_clarity'     => '학생이 해야 할 일, 사용할 자료, 답하는 방법을 문서의 안내와 문항 문구에서 확인합니다.',
		'answerability'           => '제공된 자료와 문항 정보만으로 각 응답을 작성할 수 있는지 확인합니다.',
		'difficulty_fit'          => '성취기준 코드·내용에 학년 정보가 명시된 경우에만 사고 수준, 분량, 응답 형식이 어울리는지 확인합니다. 정보가 없으면 판단하지 않습니다.',
		'rubric_quality'          => '활동지에 연결된 학생 평가 항목, 적용 범위, 등급 설명, 배점이 문항과 응답을 실제로 평가할 수 있는지 확인합니다.',
		'safety'                  => '개인정보 노출, 유해하거나 차별적인 표현, 과도한 외부 공개 요구가 있는지 확인합니다.',
	);

	/** Prompt contracts shipped before the current worksheet-quality instruction. */
	private const HISTORICAL_WORKSHEET_PROMPTS = array(
		'worksheet_quality_prompt.v1' => '20198a7d53f5503cf8b672919b96bf4771c247d7b5287ab166417d2fb8c31887',
		'worksheet_quality_prompt.v2' => '5499f44428a9e504e045fb6035c6d650bef8601a8421567095063a231e5db2cd',
		'worksheet_quality_prompt.v3' => '6493e3218cb5e0ee968bc1dc59296cf7ebe0b7267aacb8b149387595f84b9fa4',
		'worksheet_quality_prompt.v4' => '445ad99e0611984968a635d254fb60ff6309425e32b69fdaa5a3b1112378bbe3',
		'worksheet_quality_prompt.v5' => '50e6b1975975f76e0728f3b5a28a42c3439effa1ed0608a790b0990ba36b32e2',
	);

	private const DEFAULT_IDS = array(
		self::WORKSHEET_QUALITY  => array(
			self::RUBRIC      => '01K00000000000000000000101',
			self::REVIEW_RULE => '01K00000000000000000000102',
			self::POLICY      => '01K00000000000000000000103',
		),
		self::STUDENT_SUBMISSION => array(
			self::RUBRIC      => '01K00000000000000000000201',
			self::REVIEW_RULE => '01K00000000000000000000202',
			self::POLICY      => '01K00000000000000000000203',
		),
	);

	private const DEFAULT_STUDENT_CRITERION_ID = '01K00000000000000000000211';

	private const DEFAULT_STUDENT_LEVEL_IDS = array(
		'01K00000000000000000000221',
		'01K00000000000000000000222',
		'01K00000000000000000000223',
		'01K00000000000000000000224',
	);

	/**
	 * Initialize assessment configuration profile dependencies and state.
	 *
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct( private Canonical_Json $canonical ) {
	}

	/**
	 * Resource types.
	 *
	 * @return array<int,string>
	 */
	public static function resource_types(): array {
		return array( self::POLICY, self::RUBRIC, self::REVIEW_RULE );
	}

	/**
	 * Subject types.
	 *
	 * @return array<int,string>
	 */
	public static function subject_types(): array {
		return array( self::WORKSHEET_QUALITY, self::STUDENT_SUBMISSION );
	}

	/**
	 * Table.
	 *
	 * @param string $resource_type Resource type.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function table( string $resource_type ): string {
		return match ( $resource_type ) {
			self::POLICY      => 'oj_ai_evaluation_policy_revisions',
			self::RUBRIC      => 'oj_ai_rubric_revisions',
			self::REVIEW_RULE => 'oj_ai_review_rule_revisions',
			default           => throw new InvalidArgumentException( 'Unknown assessment configuration resource.' ),
		};
	}

	/**
	 * Stem.
	 *
	 * @param string $resource_type Resource type.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function stem( string $resource_type ): string {
		return match ( $resource_type ) {
			self::POLICY      => 'policy',
			self::RUBRIC      => 'criteria',
			self::REVIEW_RULE => 'rules',
			default           => throw new InvalidArgumentException( 'Unknown assessment configuration resource.' ),
		};
	}

	/**
	 * Schema.
	 *
	 * @param string $resource_type Resource type.
	 * @param string $subject_type Subject type.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function schema( string $resource_type, string $subject_type ): string {
		self::assert_subject( $subject_type );
		return match ( $resource_type ) {
			self::POLICY      => 'oj_ai.' . $subject_type . '_policy',
			self::RUBRIC      => 'oj_ai.' . $subject_type . '_rubric',
			self::REVIEW_RULE => 'oj_ai.' . $subject_type . '_review_rule',
			default           => throw new InvalidArgumentException( 'Unknown assessment configuration resource.' ),
		};
	}

	/**
	 * Normalize one create payload into a physical JSON-profile record.
	 *
	 * @param string              $resource_type Resource type.
	 * @param array<string,mixed> $payload Input payload at this boundary; see the declared shape.
	 * @param bool                $strict Strict.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function prepare( string $resource_type, array $payload, bool $strict = true ): array {
		if ( ! in_array( $resource_type, self::resource_types(), true ) ) {
			throw new InvalidArgumentException( 'Unknown assessment configuration resource.' );
		}

		$expected = match ( $resource_type ) {
			self::POLICY      => array( 'subject_type', 'value' ),
			self::RUBRIC      => array( 'subject_type', 'total_points', 'value' ),
			self::REVIEW_RULE => array( 'confidence_threshold', 'explicit_release_required', 'review_required', 'subject_type', 'value' ),
			default          => throw new InvalidArgumentException( 'Unknown assessment configuration resource.' ),
		};
		$this->exact_keys( $payload, $expected );

		$subject_type = $payload['subject_type'] ?? null;
		$value        = $payload['value'] ?? null;
		if ( ! is_string( $subject_type ) || ! is_array( $value ) || array_is_list( $value ) ) {
			throw new InvalidArgumentException( 'Assessment configuration payload is invalid.' );
		}
		self::assert_subject( $subject_type );

		$normalized = match ( $resource_type ) {
			self::POLICY      => $this->policy( $subject_type, $value, $strict ),
			self::RUBRIC      => $this->rubric( $subject_type, $value, $strict ),
			self::REVIEW_RULE => $this->review_rule( $subject_type, $value ),
		};
		$json = $this->canonical->encode( $normalized );
		$hash = hash( 'sha256', $json );

		$result = array(
			'resource_type'             => $resource_type,
			'subject_type'              => $subject_type,
			'stem'                      => self::stem( $resource_type ),
			'schema'                    => self::schema( $resource_type, $subject_type ),
			'version'                   => 1,
			'value'                     => $normalized,
			'json'                      => $json,
			'hash'                      => $hash,
			'content_hash'              => $hash,
			'total_points'              => null,
			'review_required'           => null,
			'explicit_release_required' => null,
			'confidence_threshold'      => null,
		);

		if ( self::RUBRIC === $resource_type ) {
			$total_points = $payload['total_points'] ?? null;
			if ( self::WORKSHEET_QUALITY === $subject_type ) {
				if ( null !== $total_points ) {
					throw new InvalidArgumentException( 'Worksheet-quality rubrics do not use points.' );
				}
			} elseif ( ! is_string( $total_points ) ) {
				throw new InvalidArgumentException( 'Student-submission rubric points are required.' );
			} else {
				$total_units     = $this->decimal_units( $total_points, false );
				$criterion_units = 0;
				foreach ( $normalized['criteria'] as $criterion ) {
					$criterion_units += $this->decimal_units( $criterion['maximum_points'], false );
				}
				if ( $total_units !== $criterion_units ) {
					throw new InvalidArgumentException( 'Student-submission rubric total does not match its criteria.' );
				}
				$result['total_points'] = $this->decimal_string( $total_units );
			}
		}

		if ( self::REVIEW_RULE === $resource_type ) {
			if ( true !== ( $payload['review_required'] ?? null ) || true !== ( $payload['explicit_release_required'] ?? null ) ) {
				throw new InvalidArgumentException( 'Human review and explicit release are mandatory.' );
			}
			$threshold = $payload['confidence_threshold'] ?? null;
			if ( ! is_string( $threshold ) ) {
				throw new InvalidArgumentException( 'Review confidence threshold is invalid.' );
			}
			$threshold_units = $this->decimal_units( $threshold, true );
			if ( $threshold_units > 10000 ) {
				throw new InvalidArgumentException( 'Review confidence threshold is outside 0 through 1.' );
			}
			$result['review_required']           = true;
			$result['explicit_release_required'] = true;
			$result['confidence_threshold']      = $this->decimal_string( $threshold_units );
		}

		return $result;
	}

	/**
	 * Defaults.
	 *
	 * @return array<string,array<string,array<string,mixed>>>
	 */
	public function defaults(): array {
		$dimensions = array();
		foreach ( self::CORE_DIMENSION_LABELS as $key => $label ) {
			$dimensions[] = array(
				'dimension_key'     => $key,
				'label'             => $label,
				'description'       => self::CORE_DIMENSION_DESCRIPTIONS[ $key ],
				'evidence_required' => true,
			);
		}

		$quality_rubric = $this->prepare(
			self::RUBRIC,
			array(
				'subject_type' => self::WORKSHEET_QUALITY,
				'total_points' => null,
				'value'        => array( 'dimensions' => $dimensions ),
			)
		);
		$quality_review = $this->default_review( self::WORKSHEET_QUALITY );
		$quality_policy = $this->prepare(
			self::POLICY,
			array(
				'subject_type' => self::WORKSHEET_QUALITY,
				'value'        => array(
					'evaluation_enabled'      => true,
					'rubric_revision_id'      => self::DEFAULT_IDS[ self::WORKSHEET_QUALITY ][ self::RUBRIC ],
					'review_rule_revision_id' => self::DEFAULT_IDS[ self::WORKSHEET_QUALITY ][ self::REVIEW_RULE ],
					'prompt'                  => array(
						'contract_version' => Worksheet_Evaluation_Input_Builder::PROMPT_CONTRACT,
						'content_hash'     => Worksheet_Evaluation_Input_Builder::PROMPT_CONTENT_HASH,
					),
					'input_schema'            => array(
						'schema_id'      => Worksheet_Evaluation_Input_Builder::REQUEST_SCHEMA,
						'schema_version' => Worksheet_Evaluation_Input_Builder::REQUEST_VERSION,
						'content_hash'   => hash( 'sha256', Worksheet_Evaluation_Input_Builder::REQUEST_SCHEMA . '@' . Worksheet_Evaluation_Input_Builder::REQUEST_VERSION ),
					),
					'output_schema'           => array(
						'schema_id'      => Worksheet_Evaluation_Input_Builder::OUTPUT_SCHEMA,
						'schema_version' => Worksheet_Evaluation_Input_Builder::OUTPUT_VERSION,
						'content_hash'   => hash( 'sha256', Worksheet_Evaluation_Input_Builder::OUTPUT_SCHEMA . '@' . Worksheet_Evaluation_Input_Builder::OUTPUT_VERSION ),
					),
				),
			)
		);

		$student_rubric = $this->prepare(
			self::RUBRIC,
			array(
				'subject_type' => self::STUDENT_SUBMISSION,
				'total_points' => '10',
				'value'        => array(
					'criteria' => array(
						array(
							'criterion_id'   => self::DEFAULT_STUDENT_CRITERION_ID,
							'label'          => '완성도와 사고 과정',
							'description'    => '학생이 활동지 전체에서 관련 근거를 선택하고, 그 근거를 사용해 자신의 생각을 논리적으로 설명했는지 평가합니다.',
							'maximum_points' => '10',
							'position'       => 0,
							'scope'          => 'submission',
							'levels'         => array(
								array(
									'label'     => '관련 근거를 정확히 사용해 생각을 충분히 설명함',
									'level_key' => self::DEFAULT_STUDENT_LEVEL_IDS[0],
									'points'    => '10',
									'position'  => 0,
								),
								array(
									'label'     => '근거와 생각을 연결했으나 일부 설명이 부족함',
									'level_key' => self::DEFAULT_STUDENT_LEVEL_IDS[1],
									'points'    => '7',
									'position'  => 1,
								),
								array(
									'label'     => '근거가 부족하거나 생각과의 연결이 불분명함',
									'level_key' => self::DEFAULT_STUDENT_LEVEL_IDS[2],
									'points'    => '4',
									'position'  => 2,
								),
								array(
									'label'     => '평가할 응답이 없거나 과제 요구를 충족하지 못함',
									'level_key' => self::DEFAULT_STUDENT_LEVEL_IDS[3],
									'points'    => '0',
									'position'  => 3,
								),
							),
						),
					),
				),
			)
		);
		$student_review = $this->default_review( self::STUDENT_SUBMISSION );
		$student_policy = $this->prepare(
			self::POLICY,
			array(
				'subject_type' => self::STUDENT_SUBMISSION,
				'value'        => array(
					'assessment_enabled'      => true,
					'rubric_revision_id'      => self::DEFAULT_IDS[ self::STUDENT_SUBMISSION ][ self::RUBRIC ],
					'review_rule_revision_id' => self::DEFAULT_IDS[ self::STUDENT_SUBMISSION ][ self::REVIEW_RULE ],
				),
			)
		);

		$defaults = array(
			self::WORKSHEET_QUALITY  => array(
				self::RUBRIC      => $quality_rubric,
				self::REVIEW_RULE => $quality_review,
				self::POLICY      => $quality_policy,
			),
			self::STUDENT_SUBMISSION => array(
				self::RUBRIC      => $student_rubric,
				self::REVIEW_RULE => $student_review,
				self::POLICY      => $student_policy,
			),
		);

		foreach ( $defaults as $subject => &$records ) {
			foreach ( $records as $resource => &$record ) {
				$record['public_id'] = self::DEFAULT_IDS[ $subject ][ $resource ];
			}
			unset( $record );
		}
		unset( $records );

		return $defaults;
	}

	/**
	 * Project row.
	 *
	 * @param string              $resource_type Resource type.
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function project_row( string $resource_type, array $row ): array {
		$stem         = self::stem( $resource_type );
		$public_id    = $row['public_id'] ?? null;
		$subject_type = $row['subject_type'] ?? null;
		$json         = $row[ $stem . '_json' ] ?? null;
		$hash         = $row[ $stem . '_hash' ] ?? null;
		$content_hash = $row['content_hash'] ?? null;
		if (
			! is_string( $public_id ) || ! Public_Id::is_valid( $public_id )
			|| ! is_string( $subject_type ) || ! in_array( $subject_type, self::subject_types(), true )
			|| ! is_string( $json ) || ! is_string( $hash ) || ! is_string( $content_hash )
			|| ! hash_equals( $hash, hash( 'sha256', $json ) ) || ! hash_equals( $hash, $content_hash )
			|| self::schema( $resource_type, $subject_type ) !== ( $row[ $stem . '_schema' ] ?? null )
			|| 1 !== $this->integer( $row[ $stem . '_version' ] ?? null, 1 )
		) {
			throw new InvalidArgumentException( 'Stored assessment configuration payload is invalid.' );
		}
		try {
			$value = json_decode( $json, true, 64, JSON_THROW_ON_ERROR );
		} catch ( JsonException $error ) {
			throw new InvalidArgumentException( 'Stored assessment configuration JSON is invalid.', 0, $error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The diagnostic text is literal; a chained Throwable is not HTML output.
		}
		if ( ! is_array( $value ) || array_is_list( $value ) || ! hash_equals( $json, $this->canonical->encode( $value ) ) ) {
			throw new InvalidArgumentException( 'Stored assessment configuration JSON is not canonical.' );
		}

		$payload = array(
			'subject_type' => $subject_type,
			'value'        => $value,
		);
		if ( self::RUBRIC === $resource_type ) {
			$total                   = $row['total_points'] ?? null;
			$payload['total_points'] = null === $total ? null : (string) $total;
		} elseif ( self::REVIEW_RULE === $resource_type ) {
			$payload['review_required']           = 1 === $this->integer( $row['review_required'] ?? null, 0 );
			$payload['explicit_release_required'] = 1 === $this->integer( $row['explicit_release_required'] ?? null, 0 );
			$payload['confidence_threshold']      = (string) ( $row['confidence_threshold'] ?? '' );
		}
		$prepared = $this->prepare( $resource_type, $payload, false );
		if ( ! hash_equals( $prepared['hash'], $hash ) ) {
			throw new InvalidArgumentException( 'Stored assessment configuration content drifted.' );
		}

		$state = $row['state'] ?? null;
		if ( ! is_string( $state ) || ! in_array( $state, array( self::STATE_DRAFT, self::STATE_ACTIVE, self::STATE_RETIRED ), true ) ) {
			throw new InvalidArgumentException( 'Stored assessment configuration state is invalid.' );
		}

		return array(
			'resource_type'             => $resource_type,
			'revision_id'               => $public_id,
			'subject_type'              => $subject_type,
			'revision_no'               => $this->integer( $row['revision_no'] ?? null, 1 ),
			'state'                     => $state,
			'lock_version'              => $this->integer( $row['lock_version'] ?? null, 0 ),
			'content_hash'              => $hash,
			'schema_id'                 => $prepared['schema'],
			'schema_version'            => 1,
			'value'                     => $prepared['value'],
			'total_points'              => $prepared['total_points'],
			'review_required'           => $prepared['review_required'],
			'explicit_release_required' => $prepared['explicit_release_required'],
			'confidence_threshold'      => $prepared['confidence_threshold'],
			'created_at_utc'            => $this->datetime( $row['created_at_utc'] ?? null, false ),
			'updated_at_utc'            => $this->datetime( $row['updated_at_utc'] ?? null, false ),
			'activated_at_utc'          => $this->datetime( $row['activated_at_utc'] ?? null, true ),
			'retired_at_utc'            => $this->datetime( $row['retired_at_utc'] ?? null, true ),
		);
	}

	/**
	 * Reject invalid activatable revision.
	 *
	 * @param array<string,mixed> $revision Revision.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function assert_activatable_revision( array $revision ): void {
		$resource_type = $revision['resource_type'] ?? null;
		$subject_type  = $revision['subject_type'] ?? null;
		$value         = $revision['value'] ?? null;
		if ( ! is_string( $resource_type ) || ! is_string( $subject_type ) || ! is_array( $value ) || array_is_list( $value ) ) {
			throw new InvalidArgumentException( 'Assessment configuration revision is invalid.' );
		}

		$payload = array(
			'subject_type' => $subject_type,
			'value'        => $value,
		);
		if ( self::RUBRIC === $resource_type ) {
			$payload['total_points'] = $revision['total_points'] ?? null;
		} elseif ( self::REVIEW_RULE === $resource_type ) {
			$payload['review_required']           = $revision['review_required'] ?? null;
			$payload['explicit_release_required'] = $revision['explicit_release_required'] ?? null;
			$payload['confidence_threshold']      = $revision['confidence_threshold'] ?? null;
		}
		$prepared     = $this->prepare( $resource_type, $payload, true );
		$content_hash = $revision['content_hash'] ?? null;
		if ( ! is_string( $content_hash ) || ! hash_equals( $prepared['content_hash'], $content_hash ) ) {
			throw new InvalidArgumentException( 'Assessment configuration revision is not activatable.' );
		}
	}

	/**
	 * Prepare a current worksheet policy from an exact package-owned historical prompt.
	 *
	 * Existing policy switches and pinned rubric/review references are preserved. A
	 * null result means the active policy already satisfies the current contract.
	 *
	 * @param array<string,mixed> $revision Projected active policy revision.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function prepare_worksheet_prompt_upgrade( array $revision ): ?array {
		if (
			self::POLICY !== ( $revision['resource_type'] ?? null )
			|| self::WORKSHEET_QUALITY !== ( $revision['subject_type'] ?? null )
			|| self::STATE_ACTIVE !== ( $revision['state'] ?? null )
			|| ! is_array( $revision['value'] ?? null )
			|| array_is_list( $revision['value'] )
		) {
			throw new InvalidArgumentException( 'Worksheet policy upgrade source is invalid.' );
		}

		$value  = $revision['value'];
		$prompt = $value['prompt'] ?? null;
		if ( ! is_array( $prompt ) || array_is_list( $prompt ) ) {
			throw new InvalidArgumentException( 'Worksheet policy prompt is invalid.' );
		}
		$contract = $prompt['contract_version'] ?? null;
		$hash     = $prompt['content_hash'] ?? null;
		if ( ! is_string( $contract ) || ! is_string( $hash ) ) {
			throw new InvalidArgumentException( 'Worksheet policy prompt descriptor is invalid.' );
		}

		$payload = array(
			'subject_type' => self::WORKSHEET_QUALITY,
			'value'        => $value,
		);
		if (
			Worksheet_Evaluation_Input_Builder::PROMPT_CONTRACT === $contract
			&& hash_equals( Worksheet_Evaluation_Input_Builder::PROMPT_CONTENT_HASH, $hash )
		) {
			$this->prepare( self::POLICY, $payload, true );
			return null;
		}

		$known_hash = self::HISTORICAL_WORKSHEET_PROMPTS[ $contract ] ?? null;
		if ( ! is_string( $known_hash ) || ! hash_equals( $known_hash, $hash ) ) {
			throw new InvalidArgumentException( 'Worksheet policy prompt is not a known package contract.' );
		}

		$value['prompt'] = array(
			'contract_version' => Worksheet_Evaluation_Input_Builder::PROMPT_CONTRACT,
			'content_hash'     => Worksheet_Evaluation_Input_Builder::PROMPT_CONTENT_HASH,
		);

		return $this->prepare(
			self::POLICY,
			array(
				'subject_type' => self::WORKSHEET_QUALITY,
				'value'        => $value,
			),
			true
		);
	}

	/**
	 * Prepare the current package rubric from the exact historical default.
	 *
	 * Custom legacy rubrics are deliberately preserved for operator review. A
	 * non-null result is therefore safe to install without changing user data.
	 *
	 * @param array<string,mixed> $revision Projected student rubric revision.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function prepare_package_student_rubric_upgrade( array $revision ): ?array {
		if (
			self::RUBRIC !== ( $revision['resource_type'] ?? null )
			|| self::STUDENT_SUBMISSION !== ( $revision['subject_type'] ?? null )
			|| ! in_array( $revision['state'] ?? null, array( self::STATE_ACTIVE, self::STATE_RETIRED ), true )
			|| ! is_array( $revision['value'] ?? null )
			|| array_is_list( $revision['value'] )
			|| ! is_string( $revision['total_points'] ?? null )
			|| ! is_string( $revision['content_hash'] ?? null )
		) {
			throw new InvalidArgumentException( 'Student rubric upgrade source is invalid.' );
		}

		$legacy = $this->prepare(
			self::RUBRIC,
			array(
				'subject_type' => self::STUDENT_SUBMISSION,
				'total_points' => '10',
				'value'        => array(
					'criteria' => array(
						array(
							'criterion_id'   => self::DEFAULT_STUDENT_CRITERION_ID,
							'label'          => '완성도와 사고 과정',
							'maximum_points' => '10',
							'position'       => 0,
							'scope'          => 'submission',
						),
					),
				),
			),
			false
		);
		if (
			! hash_equals( $legacy['content_hash'], $revision['content_hash'] )
			|| $legacy['total_points'] !== $revision['total_points']
		) {
			return null;
		}

		return $this->defaults()[ self::STUDENT_SUBMISSION ][ self::RUBRIC ];
	}

	/**
	 * Policy.
	 *
	 * @param string              $subject_type Subject type.
	 * @param array<string,mixed> $value Value.
	 * @param bool                $strict Strict.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function policy( string $subject_type, array $value, bool $strict = true ): array {
		if ( self::STUDENT_SUBMISSION === $subject_type ) {
			$this->exact_keys( $value, array( 'assessment_enabled', 'review_rule_revision_id', 'rubric_revision_id' ) );
			if ( ! is_bool( $value['assessment_enabled'] ?? null ) ) {
				throw new InvalidArgumentException( 'Student assessment enabled state is invalid.' );
			}
			return array(
				'assessment_enabled'      => $value['assessment_enabled'],
				'review_rule_revision_id' => $this->public_id( $value['review_rule_revision_id'] ?? null ),
				'rubric_revision_id'      => $this->public_id( $value['rubric_revision_id'] ?? null ),
			);
		}

		$this->exact_keys( $value, array( 'evaluation_enabled', 'input_schema', 'output_schema', 'prompt', 'review_rule_revision_id', 'rubric_revision_id' ) );
		if ( ! is_bool( $value['evaluation_enabled'] ?? null ) ) {
			throw new InvalidArgumentException( 'Worksheet evaluation enabled state is invalid.' );
		}
		$prompt = $this->object( $value['prompt'] ?? null );
		$this->exact_keys( $prompt, array( 'content_hash', 'contract_version' ) );
		$prompt_contract = $prompt['contract_version'] ?? null;
		$prompt_hash     = $prompt['content_hash'] ?? null;
		if (
			! is_string( $prompt_contract ) || 1 !== preg_match( '/^[a-z][a-z0-9_.-]{2,63}$/D', $prompt_contract )
			|| ! is_string( $prompt_hash ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $prompt_hash )
			|| ( $strict && Worksheet_Evaluation_Input_Builder::PROMPT_CONTRACT !== $prompt_contract )
			|| ( $strict && Worksheet_Evaluation_Input_Builder::PROMPT_CONTENT_HASH !== $prompt_hash )
		) {
			throw new InvalidArgumentException( 'Worksheet prompt contract is invalid.' );
		}
		return array(
			'evaluation_enabled'      => $value['evaluation_enabled'],
			'input_schema'            => $this->schema_descriptor( $value['input_schema'] ?? null, Worksheet_Evaluation_Input_Builder::REQUEST_SCHEMA, Worksheet_Evaluation_Input_Builder::REQUEST_VERSION, $strict ),
			'output_schema'           => $this->schema_descriptor( $value['output_schema'] ?? null, Worksheet_Evaluation_Input_Builder::OUTPUT_SCHEMA, Worksheet_Evaluation_Input_Builder::OUTPUT_VERSION, $strict ),
			'prompt'                  => array(
				'content_hash'     => $prompt_hash,
				'contract_version' => $prompt_contract,
			),
			'review_rule_revision_id' => $this->public_id( $value['review_rule_revision_id'] ?? null ),
			'rubric_revision_id'      => $this->public_id( $value['rubric_revision_id'] ?? null ),
		);
	}

	/**
	 * Rubric.
	 *
	 * @param string              $subject_type Subject type.
	 * @param array<string,mixed> $value Value.
	 * @param bool                $strict Strict.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function rubric( string $subject_type, array $value, bool $strict = true ): array {
		if ( self::WORKSHEET_QUALITY === $subject_type ) {
			$this->exact_keys( $value, array( 'dimensions' ) );
			$dimensions = $value['dimensions'] ?? null;
			if ( ! is_array( $dimensions ) || ! array_is_list( $dimensions ) || count( $dimensions ) < 6 || count( $dimensions ) > 12 ) {
				throw new InvalidArgumentException( 'Worksheet-quality dimensions are invalid.' );
			}
			$result = array();
			$seen   = array();
			$core   = array_keys( self::CORE_DIMENSION_LABELS );
			foreach ( $dimensions as $position => $dimension ) {
				$dimension = $this->object( $dimension );
				$this->exact_keys( $dimension, array( 'description', 'dimension_key', 'evidence_required', 'label' ) );
				$key = $dimension['dimension_key'] ?? null;
				if (
					! is_string( $key ) || 1 !== preg_match( '/^[a-z][a-z0-9_]{2,63}$/D', $key )
					|| isset( $seen[ $key ] ) || true !== ( $dimension['evidence_required'] ?? null )
					|| ( $position < 6 && $core[ $position ] !== $key )
					|| ( $position >= 6 && in_array( $key, $core, true ) )
				) {
					throw new InvalidArgumentException( 'Worksheet-quality dimension identity is invalid.' );
				}
				$seen[ $key ] = true;
				$result[]     = array(
					'description'       => $this->text( $dimension['description'] ?? null, 1000 ),
					'dimension_key'     => $key,
					'evidence_required' => true,
					'label'             => $this->text( $dimension['label'] ?? null, 120 ),
				);
			}
			return array( 'dimensions' => $result );
		}

		$this->exact_keys( $value, array( 'criteria' ) );
		$criteria = $value['criteria'] ?? null;
		if ( ! is_array( $criteria ) || ! array_is_list( $criteria ) || count( $criteria ) < 1 || count( $criteria ) > 100 ) {
			throw new InvalidArgumentException( 'Student-submission criteria are invalid.' );
		}
		$result = array();
		$seen   = array();
		foreach ( $criteria as $position => $criterion ) {
			$criterion       = $this->object( $criterion );
			$has_levels      = array_key_exists( 'levels', $criterion );
			$has_description = array_key_exists( 'description', $criterion );
			$keys            = array( 'criterion_id', 'label', 'maximum_points', 'position', 'scope' );
			if ( $has_levels ) {
				$keys[] = 'levels';
			}
			if ( $has_description ) {
				$keys[] = 'description';
			}
			$this->exact_keys( $criterion, $keys );
			if ( $strict && ( ! $has_levels || ! $has_description ) ) {
				throw new InvalidArgumentException( 'Student-submission criterion description and levels are required.' );
			}
			$id    = $this->public_id( $criterion['criterion_id'] ?? null );
			$scope = $criterion['scope'] ?? null;
			if ( isset( $seen[ $id ] ) || $position !== ( $criterion['position'] ?? null ) || ! in_array( $scope, array( 'response', 'submission' ), true ) ) {
				throw new InvalidArgumentException( 'Student-submission criterion identity is invalid.' );
			}
			$seen[ $id ] = true;
			$points      = $criterion['maximum_points'] ?? null;
			if ( ! is_string( $points ) ) {
				throw new InvalidArgumentException( 'Student-submission criterion points are invalid.' );
			}
			$maximum_units        = $this->decimal_units( $points, false );
			$normalized_criterion = array(
				'criterion_id'   => $id,
				'label'          => $this->text( $criterion['label'] ?? null, 120 ),
				'maximum_points' => $this->decimal_string( $maximum_units ),
				'position'       => $position,
				'scope'          => $scope,
			);
			if ( $has_description ) {
				$normalized_criterion['description'] = $this->text( $criterion['description'] ?? null, 1000 );
			}
			if ( $has_levels ) {
				$levels      = $this->criterion_levels( $criterion['levels'] ?? null );
				$level_units = array_map(
					fn ( array $level ): int => $this->decimal_units( $level['points'], true ),
					$levels
				);
				if ( $maximum_units !== max( $level_units ) ) {
					throw new InvalidArgumentException( 'Student-submission criterion maximum points must match its highest level.' );
				}
				$normalized_criterion['levels'] = $levels;
			}
			$result[] = $normalized_criterion;
		}
		return array( 'criteria' => $result );
	}

	/**
	 * Criterion levels.
	 *
	 * @param mixed $raw Raw.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function criterion_levels( $raw ): array {
		if ( ! is_array( $raw ) || ! array_is_list( $raw ) || count( $raw ) < 2 || count( $raw ) > 10 ) {
			throw new InvalidArgumentException( 'Student-submission criterion levels are invalid.' );
		}
		$result      = array();
		$seen_ids    = array();
		$seen_points = array();
		foreach ( $raw as $position => $level ) {
			$level = $this->object( $level );
			$this->exact_keys( $level, array( 'label', 'level_key', 'points', 'position' ) );
			$id = $this->public_id( $level['level_key'] ?? null );
			if ( isset( $seen_ids[ $id ] ) || $position !== ( $level['position'] ?? null ) ) {
				throw new InvalidArgumentException( 'Student-submission criterion level identity is invalid.' );
			}
			$points = $level['points'] ?? null;
			if ( ! is_string( $points ) ) {
				throw new InvalidArgumentException( 'Student-submission criterion level points are invalid.' );
			}
			$units = $this->decimal_units( $points, true );
			if ( isset( $seen_points[ $units ] ) ) {
				throw new InvalidArgumentException( 'Student-submission criterion level points must be unique.' );
			}
			$seen_ids[ $id ]       = true;
			$seen_points[ $units ] = true;
			$result[]              = array(
				'label'     => $this->text( $level['label'] ?? null, self::MAXIMUM_LEVEL_LABEL_LENGTH ),
				'level_key' => $id,
				'points'    => $this->decimal_string( $units ),
				'position'  => $position,
			);
		}
		return $result;
	}

	/**
	 * Review rule.
	 *
	 * @param string              $subject_type Subject type.
	 * @param array<string,mixed> $value Value.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function review_rule( string $subject_type, array $value ): array {
		unset( $subject_type );
		$this->exact_keys( $value, array( 'abstain_handling', 'safety_handling' ) );
		if ( 'require_review' !== ( $value['abstain_handling'] ?? null ) || 'require_explicit_review' !== ( $value['safety_handling'] ?? null ) ) {
			throw new InvalidArgumentException( 'Review safety handling is invalid.' );
		}
		return array(
			'abstain_handling' => 'require_review',
			'safety_handling'  => 'require_explicit_review',
		);
	}

	/**
	 * Default review.
	 *
	 * @param string $subject_type Subject type.
	 * @return array<string,mixed>
	 */
	private function default_review( string $subject_type ): array {
		return $this->prepare(
			self::REVIEW_RULE,
			array(
				'subject_type'              => $subject_type,
				'value'                     => array(
					'abstain_handling' => 'require_review',
					'safety_handling'  => 'require_explicit_review',
				),
				'review_required'           => true,
				'explicit_release_required' => true,
				'confidence_threshold'      => '0.8',
			)
		);
	}

	/**
	 * Schema descriptor.
	 *
	 * @param mixed  $value Value.
	 * @param string $schema_id Schema id.
	 * @param string $schema_version Schema version.
	 * @param bool   $strict Strict.
	 * @return array<string,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function schema_descriptor( $value, string $schema_id, string $schema_version, bool $strict = true ): array {
		$value = $this->object( $value );
		$this->exact_keys( $value, array( 'content_hash', 'schema_id', 'schema_version' ) );
		$content_hash          = $value['content_hash'] ?? null;
		$stored_schema_id      = $value['schema_id'] ?? null;
		$stored_schema_version = $value['schema_version'] ?? null;
		if (
			! is_string( $stored_schema_id ) || 1 !== preg_match( '/^[a-z][a-z0-9_.]{2,127}$/D', $stored_schema_id )
			|| ! is_string( $stored_schema_version ) || 1 !== preg_match( '/^(?:0|[1-9][0-9]*)(?:\.(?:0|[1-9][0-9]*)){2}$/D', $stored_schema_version )
			|| ! is_string( $content_hash ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $content_hash )
			|| ( $strict && $schema_id !== $stored_schema_id ) || ( $strict && $schema_version !== $stored_schema_version )
		) {
			throw new InvalidArgumentException( 'Assessment schema descriptor is invalid.' );
		}
		return array(
			'content_hash'   => $content_hash,
			'schema_id'      => $stored_schema_id,
			'schema_version' => $stored_schema_version,
		);
	}

	/**
	 * Object.
	 *
	 * @param mixed $value Value.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function object( $value ): array {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			throw new InvalidArgumentException( 'Assessment configuration object is invalid.' );
		}
		return $value;
	}

	/**
	 * Public id.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function public_id( $value ): string {
		if ( ! is_string( $value ) || ! Public_Id::is_valid( $value ) ) {
			throw new InvalidArgumentException( 'Assessment configuration public ID is invalid.' );
		}
		return $value;
	}

	/**
	 * Text.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function text( $value, int $maximum ): string {
		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( 'Assessment configuration text is invalid.' );
		}
		$value = $this->canonical->text( trim( str_replace( array( "\r\n", "\r" ), "\n", $value ) ) );
		$count = preg_match_all( '/./us', $value, $matches );
		if ( '' === $value || false === $count || $count > $maximum || 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value ) ) {
			throw new InvalidArgumentException( 'Assessment configuration text is invalid.' );
		}
		return $value;
	}

	/**
	 * Decimal units.
	 *
	 * @param string $value Value.
	 * @param bool   $allow_zero Allow zero.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decimal_units( string $value, bool $allow_zero ): int {
		if ( 1 !== preg_match( '/^(?:0|[1-9][0-9]{0,7})(?:\.([0-9]{1,4}))?$/D', $value, $matches ) ) {
			throw new InvalidArgumentException( 'Assessment configuration decimal is invalid.' );
		}
		$parts = explode( '.', $value, 2 );
		$units = ( (int) $parts[0] * 10000 ) + (int) str_pad( $parts[1] ?? '', 4, '0' );
		if ( ! $allow_zero && $units < 1 ) {
			throw new InvalidArgumentException( 'Assessment configuration decimal must be positive.' );
		}
		return $units;
	}

	/**
	 * Decimal string.
	 *
	 * @param int $units Units.
	 * @return string
	 */
	private function decimal_string( int $units ): string {
		$whole    = intdiv( $units, 10000 );
		$fraction = rtrim( str_pad( (string) ( $units % 10000 ), 4, '0', STR_PAD_LEFT ), '0' );
		return '' === $fraction ? (string) $whole : $whole . '.' . $fraction;
	}

	/**
	 * Integer.
	 *
	 * @param mixed $value Value.
	 * @param int   $minimum Minimum.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function integer( $value, int $minimum ): int {
		if ( is_int( $value ) && $value >= $minimum ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) && (int) $value >= $minimum ) {
			return (int) $value;
		}
		throw new InvalidArgumentException( 'Assessment configuration integer is invalid.' );
	}

	/**
	 * Datetime.
	 *
	 * @param mixed $value Value.
	 * @param bool  $nullable Nullable.
	 * @return string|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function datetime( $value, bool $nullable ): ?string {
		if ( $nullable && null === $value ) {
			return null;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Assessment configuration timestamp is invalid.' );
		}
		return $value;
	}

	/**
	 * Reject invalid subject.
	 *
	 * @param string $subject_type Subject type.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private static function assert_subject( string $subject_type ): void {
		if ( ! in_array( $subject_type, self::subject_types(), true ) ) {
			throw new InvalidArgumentException( 'Unknown assessment configuration subject.' );
		}
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
			throw new InvalidArgumentException( 'Assessment configuration contains unknown or missing fields.' );
		}
	}
}
