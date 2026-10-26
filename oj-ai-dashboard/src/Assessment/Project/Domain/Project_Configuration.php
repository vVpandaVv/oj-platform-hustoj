<?php
/**
 * Project configuration.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Assessment\Project\Domain;

use OJAI\Dashboard\Assessment\Configuration\Domain\Assessment_Configuration_Profile;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An assessment owns its criteria and curriculum; templates are copied values.
 */
final class Project_Configuration {
	public const SCHEMA = 'oj_ai.assessment_project_configuration';
	/**
	 * Initialize configuration dependencies and state.
	 *
	 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
	 * @param Public_Id_Generator $ids Ids.
	 */
	public function __construct( private Canonical_Json $canonical, private Public_Id_Generator $ids ) {
	}

	/**
	 * Empty.
	 *
	 * @return array
	 */
	public function empty(): array {
		return array(
			'learning_goals'        => array(),
			'achievement_standards' => array(),
			'criteria'              => array(),
			'total_points'          => '0',
		);
	}

	/**
	 * Normalize.
	 *
	 * @param array $value Value.
	 * @param bool  $confirmed Confirmed.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function normalize( array $value, bool $confirmed ): array {
		self::keys( $value, array( 'learning_goals', 'achievement_standards', 'criteria', 'total_points' ) );
		$result = $this->empty();
		foreach ( array( 'learning_goals', 'achievement_standards', 'criteria' ) as $key ) {
			if ( ! is_array( $value[ $key ] ) || ! array_is_list( $value[ $key ] ) || count( $value[ $key ] ) > 100 ) {
				throw new Project_Input_Failure( '목표, 성취기준, 평가 항목은 각각 100개 이하여야 합니다.' );
			}
			if ( $confirmed && empty( $value[ $key ] ) ) {
				throw new Project_Input_Failure( '학습 목표, 성취기준, 평가 항목을 모두 작성한 뒤 확정해 주세요.' );
			}
		}
		$seen = array();
		if ( count( $value['learning_goals'] ) > \OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Profile::MAX_LEARNING_GOALS ) {
			throw new Project_Input_Failure( '학습 목표는 활동지당 최대 50개입니다.' );
		}
		foreach ( $value['learning_goals'] as $goal ) {
			self::keys( $goal, array( 'goal_id', 'text' ) );
			$id                         = $this->identity( $goal['goal_id'], $seen );
			$result['learning_goals'][] = array(
				'goal_id' => $id,
				'text'    => self::text( $goal['text'], 1000 ),
			);
		}
		foreach ( $value['achievement_standards'] as $standard ) {
			self::keys( $standard, array( 'standard_id', 'code', 'text' ) );
			$id                                = $this->identity( $standard['standard_id'], $seen );
			$result['achievement_standards'][] = array(
				'standard_id' => $id,
				'code'        => self::text( $standard['code'], 100, true ),
				'text'        => self::text( $standard['text'], 2000 ),
			);
		}
		if ( array() !== $value['criteria'] ) {
			$prepared               = ( new Assessment_Configuration_Profile( $this->canonical ) )->prepare(
				Assessment_Configuration_Profile::RUBRIC,
				array(
					'subject_type' => Assessment_Configuration_Profile::STUDENT_SUBMISSION,
					'total_points' => $value['total_points'],
					'value'        => array( 'criteria' => $value['criteria'] ),
				),
				true
			);
			$result['criteria']     = $prepared['value']['criteria'];
			$result['total_points'] = $prepared['total_points'];
		} elseif ( '0' !== $value['total_points'] ) {
			throw new Project_Input_Failure( '평가 항목이 없으면 총점은 0이어야 합니다.' );
		}
		return $result;
	}

	/**
	 * Copy.
	 *
	 * @param array $value Value.
	 * @return array
	 */
	public function copy( array $value ): array {
		$value = $this->normalize( $value, false );
		foreach ( $value['learning_goals'] as &$goal ) {
			$goal['goal_id'] = $this->ids->create()->value();
		} unset( $goal );
		foreach ( $value['achievement_standards'] as &$standard ) {
			$standard['standard_id'] = $this->ids->create()->value();
		} unset( $standard );
		foreach ( $value['criteria'] as &$criterion ) {
			$criterion['criterion_id'] = $this->ids->create()->value();
			foreach ( $criterion['levels'] as &$level ) {
				$level['level_key'] = $this->ids->create()->value();
			} unset( $level );
		} unset( $criterion );
		return $value;
	}

	/**
	 * Context.
	 *
	 * @param array $value Value.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function context( array $value ): array {
		self::keys( $value, array( 'project_id', 'revision_id', 'content_hash' ) );
		foreach ( array( 'project_id', 'revision_id' ) as $key ) {
			if ( ! is_string( $value[ $key ] ) || ! Public_Id::is_valid( $value[ $key ] ) ) {
				throw new Project_Input_Failure( '평가 연결 ID가 올바르지 않습니다.' );
			}
		}
		if ( ! is_string( $value['content_hash'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value['content_hash'] ) ) {
			throw new Project_Input_Failure( '평가 기준 버전이 올바르지 않습니다.' );
		}
		return $value;
	}

	/**
	 * Pin.
	 *
	 * @param array $project Project.
	 * @return array
	 */
	public static function pin( array $project ): array {
		return array(
			'project_id'   => $project['project_id'],
			'revision_id'  => $project['revision_id'],
			'content_hash' => $project['content_hash'],
		);
	}

	/**
	 * Rubric.
	 *
	 * @param array $project Project.
	 * @return array
	 */
	public static function rubric( array $project ): array {
		$value = array( 'criteria' => $project['configuration']['criteria'] );
		return array(
			'revision_id'  => $project['revision_id'],
			'content_hash' => hash( 'sha256', ( new Canonical_Json() )->encode( $value ) ),
			'criteria'     => $value['criteria'],
			'total_points' => $project['configuration']['total_points'],
		);
	}

	/**
	 * Policy.
	 *
	 * @param array  $project Project.
	 * @param string $review_id Review id.
	 * @return array
	 */
	public static function policy( array $project, string $review_id ): array {
		$value = array(
			'assessment_enabled'      => true,
			'rubric_revision_id'      => $project['revision_id'],
			'review_rule_revision_id' => $review_id,
		);
		return array(
			'revision_id'  => $project['revision_id'],
			'content_hash' => hash( 'sha256', ( new Canonical_Json() )->encode( $value ) ),
			'subject_type' => 'student_submission',
			'state'        => 'active',
			'value'        => $value,
		);
	}

	/**
	 * Seed.
	 *
	 * @param array $document Document.
	 * @param array $project Project.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function seed( array $document, array $project ): array {
		if ( 'confirmed' !== $project['state'] ) {
			throw new Project_Input_Failure( '평가 기준을 먼저 확정해 주세요.' );
		}
		self::clear_previous_criteria( $document );
		$document['settings']['assessment_project']  = self::pin( $project );
		$document['settings']['preparation']         = array(
			'state'               => 'confirmed',
			'rubric_revision_id'  => $project['revision_id'],
			'rubric_content_hash' => self::rubric( $project )['content_hash'],
		);
		$document['settings']['learning_goals']      = array_map(
			static fn ( array $goal ): array => $goal + array(
				'scope'       => 'worksheet',
				'response_id' => null,
			),
			$project['configuration']['learning_goals']
		);
		$document['settings']['assessment_bindings'] = array_map(
			static fn ( array $criterion ): array => array(
				'criterion_id' => $criterion['criterion_id'],
				'scope'        => 'worksheet',
				'response_id'  => null,
			),
			$project['configuration']['criteria']
		);
		return $document;
	}

	/**
	 * Clear previous criteria.
	 *
	 * @param array &$value Value.
	 * @return void
	 */
	private static function clear_previous_criteria( array &$value ): void {
		foreach ( $value as $key => &$child ) {
			if ( 'rubric_criterion_ids' === $key ) {
				$child = array(); } elseif ( is_array( $child ) ) {
				self::clear_previous_criteria( $child );
				}
		}
	}

	/**
	 * Keys.
	 *
	 * @param mixed $value Value.
	 * @param array $expected Expected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function keys( mixed $value, array $expected ): void {
		if ( ! is_array( $value ) ) {
			throw new Project_Input_Failure( '평가 입력 형식이 올바르지 않습니다.' );
		}
		$keys = array_keys( $value );
		sort( $keys );
		sort( $expected );
		if ( $keys !== $expected ) {
			throw new Project_Input_Failure( '평가 입력에 누락되거나 허용되지 않은 항목이 있습니다.' );
		}
	}

	/**
	 * Text.
	 *
	 * @param mixed $value Value.
	 * @param int   $max Max.
	 * @param bool  $empty Empty.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function text( mixed $value, int $max, bool $empty = false ): string { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.emptyFound -- Preserve the established named-argument signature; these parameter names are legal in supported PHP versions.
		if ( ! is_string( $value ) ) {
			throw new Project_Input_Failure( '문자열 입력이 필요합니다.' );
		}
		$value = trim( $value );
		if ( ! $empty && '' === $value ) {
			throw new Project_Input_Failure( '빈 항목이나 허용 길이를 넘은 입력을 확인해 주세요.' );
		}
		$length = preg_match_all( '/./us', $value );
		if ( false === $length || $length > $max || preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value ) ) {
			throw new Project_Input_Failure( '빈 항목이나 허용 길이를 넘은 입력을 확인해 주세요.' );
		}
		return $value;
	}
	/**
	 * Identity.
	 *
	 * @param mixed $id Id.
	 * @param array &$seen Seen.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function identity( mixed $id, array &$seen ): string {
		if ( ! is_string( $id ) || ! Public_Id::is_valid( $id ) || isset( $seen[ $id ] ) ) {
			throw new Project_Input_Failure( '목표·성취기준 ID가 올바르지 않거나 중복되었습니다.' );
		}
		$seen[ $id ] = true;
		return $id;
	}
}
