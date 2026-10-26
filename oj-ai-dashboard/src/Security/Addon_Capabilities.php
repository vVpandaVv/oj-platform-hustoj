<?php
/**
 * Canonical OJ Platform add-on capabilities.
 *
 * @package OJAI\Dashboard
 */

namespace OJAI\Dashboard\Security;

use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the exact capability catalog declared by the add-on Manifest.
 */
final class Addon_Capabilities {
	public const VIEW_WORKSPACE       = 'oj_ai_dashboard_view_workspace';
	public const VIEW_WORKSHEETS      = 'oj_ai_dashboard_view_worksheets';
	public const MANAGE_WORKSHEETS    = 'oj_ai_dashboard_manage_worksheets';
	public const ASSESS_WORKSHEETS    = 'oj_ai_dashboard_assess_worksheets';
	public const ASSIGN_WORKSHEETS    = 'oj_ai_dashboard_assign_worksheets';
	public const MANAGE_SUBMISSIONS   = 'oj_ai_dashboard_manage_submissions';
	public const ASSESS_SUBMISSIONS   = 'oj_ai_dashboard_assess_submissions';
	public const REVIEW_ASSESSMENTS   = 'oj_ai_dashboard_review_assessments';
	public const PUBLISH_FEEDBACK     = 'oj_ai_dashboard_publish_feedback';
	public const MANAGE_STUDENT_NOTES = 'oj_ai_dashboard_manage_student_notes';
	public const VIEW_REPORTS         = 'oj_ai_dashboard_view_reports';
	public const EXPORT_REPORTS       = 'oj_ai_dashboard_export_reports';
	public const MANAGE_POLICIES      = 'oj_ai_dashboard_manage_policies';
	public const MANAGE_MIGRATIONS    = 'oj_ai_dashboard_manage_migrations';
	public const PARTICIPATE          = 'oj_ai_dashboard_participate';

	/**
	 * Return the exact Manifest capability definitions.
	 *
	 * @return array<int,array{key:string,label:string,audience:string}>
	 */
	public static function definitions() {
		return array(
			array(
				'key'      => self::VIEW_WORKSPACE,
				'label'    => 'AI 활동지 작업 공간 보기',
				'audience' => 'educator',
			),
			array(
				'key'      => self::VIEW_WORKSHEETS,
				'label'    => '활동지 보기',
				'audience' => 'educator',
			),
			array(
				'key'      => self::MANAGE_WORKSHEETS,
				'label'    => '활동지 만들기·편집',
				'audience' => 'educator',
			),
			array(
				'key'      => self::ASSESS_WORKSHEETS,
				'label'    => '활동지 AI 점검',
				'audience' => 'educator',
			),
			array(
				'key'      => self::ASSIGN_WORKSHEETS,
				'label'    => '활동지 배포 관리',
				'audience' => 'educator',
			),
			array(
				'key'      => self::MANAGE_SUBMISSIONS,
				'label'    => '학생 제출 운영',
				'audience' => 'educator',
			),
			array(
				'key'      => self::ASSESS_SUBMISSIONS,
				'label'    => '학생 제출 AI 평가',
				'audience' => 'educator',
			),
			array(
				'key'      => self::REVIEW_ASSESSMENTS,
				'label'    => '평가 검토·확정',
				'audience' => 'educator',
			),
			array(
				'key'      => self::PUBLISH_FEEDBACK,
				'label'    => '결과·피드백 공개',
				'audience' => 'educator',
			),
			array(
				'key'      => self::MANAGE_STUDENT_NOTES,
				'label'    => '학생 특기사항 관리',
				'audience' => 'educator',
			),
			array(
				'key'      => self::VIEW_REPORTS,
				'label'    => '활동지 보고서 보기',
				'audience' => 'educator',
			),
			array(
				'key'      => self::EXPORT_REPORTS,
				'label'    => '보고서·파일 내보내기',
				'audience' => 'educator',
			),
			array(
				'key'      => self::MANAGE_POLICIES,
				'label'    => '활동지 평가 설정',
				'audience' => 'educator',
			),
			array(
				'key'      => self::MANAGE_MIGRATIONS,
				'label'    => '이전·복구·정리 관리',
				'audience' => 'operator',
			),
			array(
				'key'      => self::PARTICIPATE,
				'label'    => '내 활동지 참여',
				'audience' => 'learner',
			),
		);
	}

	/**
	 * Return all declared keys.
	 *
	 * @return string[]
	 */
	public static function keys() {
		return array_column( self::definitions(), 'key' );
	}

	/**
	 * Map a declared key through the platform's public mapper.
	 *
	 * @param string $declared_key Manifest capability key.
	 * @return string
	 */
	public static function wordpress( $declared_key ) {
		if ( ! class_exists( AddonCapabilityKeyV1::class ) || ! in_array( $declared_key, self::keys(), true ) ) {
			return '';
		}

		return AddonCapabilityKeyV1::wordpress( $declared_key );
	}
}
