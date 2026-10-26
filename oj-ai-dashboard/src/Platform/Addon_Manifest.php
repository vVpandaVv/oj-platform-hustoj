<?php
/**
 * Atomic OJ Platform Addon API 1.1 Manifest.
 *
 * @package OJAI\Dashboard
 */

namespace OJAI\Dashboard\Platform;

use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskInstructionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAssetBundleDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsoleLocalNavBuilderV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsoleLocalNavItemV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsoleMenuDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsolePageDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonManifestBuilderV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPublicMenuDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPublicPageDefinitionV1;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Quality_Prompt_Contract;
use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the complete manifest candidate with no Runtime service lookup.
 */
final class Addon_Manifest {
	public const CONSOLE_PROJECTS         = 'addon.oj_ai_dashboard.console.projects';
	public const CONSOLE_WORKSHEETS       = 'addon.oj_ai_dashboard.console.worksheets';
	public const CONSOLE_WORKSHEET_EDIT   = 'addon.oj_ai_dashboard.console.worksheets.edit';
	public const CONSOLE_ASSIGNMENTS      = 'addon.oj_ai_dashboard.console.assignments';
	public const CONSOLE_ASSESSMENTS      = 'addon.oj_ai_dashboard.console.assessments';
	public const CONSOLE_REPORTS          = 'addon.oj_ai_dashboard.console.reports';
	public const CONSOLE_SETTINGS         = 'addon.oj_ai_dashboard.console.settings';
	public const CONSOLE_CRITERIA         = 'addon.oj_ai_dashboard.console.criteria';
	public const CONSOLE_STORAGE_DETAIL   = 'addon.oj_ai_dashboard.console.settings.storage_detail';
	public const PUBLIC_WORKSHEETS        = 'addon.oj_ai_dashboard.public.worksheets';
	public const PUBLIC_ASSIGNMENT        = 'addon.oj_ai_dashboard.public.assignments.show';
	public const PUBLIC_RESULT            = 'addon.oj_ai_dashboard.public.results.show';
	public const ASSET_CONSOLE            = 'addon.oj_ai_dashboard.asset.console';
	public const ASSET_SETTINGS           = 'addon.oj_ai_dashboard.asset.settings';
	public const ASSET_WORKSHEET_EDITOR   = 'addon.oj_ai_dashboard.asset.worksheet_editor';
	public const ASSET_PUBLIC             = 'addon.oj_ai_dashboard.asset.public';
	public const ASSET_STUDENT_WORKSHEET  = 'addon.oj_ai_dashboard.asset.student_worksheet';
	public const ASSET_STORAGE_DETAIL     = 'addon.oj_ai_dashboard.asset.storage_detail';
	public const ASSET_PROJECT_EDITOR     = 'addon.oj_ai_dashboard.asset.project_editor';
	public const ASSET_LEARNER_LABELS     = 'addon.oj_ai_dashboard.asset.learner_labels';
	public const TASK_WORKSHEET_QUALITY   = 'addon.oj_ai_dashboard.worksheet_quality_assessment';
	public const TASK_STUDENT_ASSESSMENT  = 'addon.oj_ai_dashboard.student_submission_assessment';
	public const TASK_ML_ASSESSMENT       = 'addon.oj_ai_dashboard.ml_submission_assessment';
	public const TASK_EXTERNAL_ASSESSMENT = 'addon.oj_ai_dashboard.external_submission_assessment';
	public const MENU_WORKSPACE           = 'addon.oj_ai_dashboard.console.menu.worksheets';
	public const MENU_SETTINGS            = 'addon.oj_ai_dashboard.console.menu.settings';

	public const ASSET_SUBMISSION_DOCUMENT = 'addon.oj_ai_dashboard.asset.submission_document';

	/**
	 * Build one complete manifest candidate.
	 *
	 * @param AddonManifestBuilderV1       $builder Manifest builder.
	 * @param Addon_Runtime_Readiness_Gate $readiness Accepted onReady gate.
	 * @param Access_Policy_Port_Interface $policy Common access policy.
	 * @param array<string,object>         $page_handlers Injected feature Page handlers.
	 * @return void
	 */
	public static function build(
		AddonManifestBuilderV1 $builder,
		Addon_Runtime_Readiness_Gate $readiness,
		Access_Policy_Port_Interface $policy,
		array $page_handlers = array()
	) {
		$builder->manifest(
			Addon_Integration::ADDON_NAMESPACE,
			OJ_AI_DASHBOARD_VERSION,
			OJ_AI_DASHBOARD_FILE,
			Addon_Integration::PLATFORM_RANGE,
			Addon_Integration::ADDON_API_RANGE,
			'활동지 평가',
			'OJAI-A-DS-02'
		);

		self::add_capabilities( $builder );
		foreach ( self::asset_bundles() as $bundle ) {
			$builder->assetBundle( $bundle );
		}
		self::add_pages( $builder, $policy, $page_handlers );
		// Addon API 1.3 (OJ Platform 2.1+): the platform draws the local navigation.
		$platform_local_nav = $builder instanceof AddonConsoleLocalNavBuilderV1;
		foreach ( self::console_menus( $platform_local_nav ) as $menu ) {
			$builder->consoleMenu( $menu );
		}
		Worksheet_Workflow::use_platform_local_navigation( $platform_local_nav );
		if ( $platform_local_nav ) {
			foreach ( self::console_local_nav() as $item ) {
				$builder->consoleLocalNav( $item );
			}
		}
		foreach ( self::public_menus() as $menu ) {
			$builder->publicMenu( $menu );
		}
		foreach ( self::ai_tasks() as $task ) {
			$builder->aiTask( $task );
		}
		$builder->onReady( array( $readiness, 'connect' ) );
	}

	/**
	 * Return expected counts from the same catalog used for registration.
	 *
	 * @param bool $platform_local_nav Whether the platform draws the local navigation (Addon API 1.3).
	 * @return array
	 */
	public static function contribution_counts( bool $platform_local_nav = true ): array {
		return array(
			'capability'   => count( Addon_Capabilities::definitions() ),
			'console_page' => count( self::console_pages() ),
			'public_page'  => count( self::public_pages() ),
			'console_menu' => count( self::console_menus( $platform_local_nav ) ),
			'public_menu'  => count( self::public_menus() ),
			'asset_bundle' => count( self::asset_bundles() ),
			'ai_task'      => count( self::ai_tasks() ),
		);
	}

	/**
	 * Count of local navigation items registered on Addon API 1.3 platforms.
	 *
	 * @return int
	 */
	public static function local_nav_count(): int {
		return count( self::console_local_nav() );
	}

	/**
	 * Add the exact 15-capability catalog.
	 *
	 * @param AddonManifestBuilderV1 $builder Manifest builder.
	 * @return void
	 */
	private static function add_capabilities( AddonManifestBuilderV1 $builder ) {
		foreach ( Addon_Capabilities::definitions() as $definition ) {
			$builder->capability(
				new AddonCapabilityDefinitionV1(
					$definition['key'],
					$definition['label'],
					$definition['audience']
				)
			);
		}
	}

	/**
	 * Return route-scoped asset bundles.
	 *
	 * @return AddonAssetBundleDefinitionV1[]
	 */
	private static function asset_bundles(): array {
		return array(
			new AddonAssetBundleDefinitionV1(
				self::ASSET_SUBMISSION_DOCUMENT,
				'oj-ai-dashboard-submission-document',
				array( 'resources/css/submission-document.css' ),
				array( 'resources/js/submission-document.js' ),
				array(),
				OJ_AI_DASHBOARD_VERSION
			),
			new AddonAssetBundleDefinitionV1(
				self::ASSET_LEARNER_LABELS,
				'oj-ai-dashboard-learner-labels',
				array(),
				array( 'resources/js/learner-labels.js' ),
				array(),
				OJ_AI_DASHBOARD_VERSION
			),
			new AddonAssetBundleDefinitionV1(
				self::ASSET_PROJECT_EDITOR,
				'oj-ai-dashboard-project-editor',
				array( 'resources/assets/builder/plate-template-builder.css' ),
				array( 'resources/assets/builder/plate-template-builder.js' ),
				array(),
				OJ_AI_DASHBOARD_VERSION
			),
			new AddonAssetBundleDefinitionV1(
				self::ASSET_CONSOLE,
				'oj-ai-dashboard-console',
				array(
					'resources/css/admin-shell.css',
					'resources/css/assignment-workbench.css',
					'resources/css/worksheet-authoring-workbench.css',
					'resources/css/worksheet-evaluation-workbench.css',
					'resources/css/submission-evaluation-review-workbench.css',
					'resources/css/report-dashboard.css',
					'resources/css/worksheet-workflow.css',
					'resources/css/assessment-projects.css',
				),
				array(
					'resources/js/worksheet-workflow.js',
					'resources/js/assessment-projects.js',
					'resources/js/assignment-workbench.js',
					'resources/js/worksheet-authoring-workbench.js',
					'resources/js/worksheet-evaluation-workbench.js',
					'resources/js/submission-evaluation-review-workbench.js',
					'resources/js/report-dashboard.js',
				),
				array(),
				OJ_AI_DASHBOARD_VERSION
			),
			new AddonAssetBundleDefinitionV1(
				self::ASSET_SETTINGS,
				'oj-ai-dashboard-settings',
				array(
					'resources/css/admin-shell.css',
					'resources/css/external-ai-consent-settings.css',
					'resources/css/assessment-configuration-settings.css',
					'resources/css/curriculum-catalog-settings.css',
					'resources/css/operations-diagnostics.css',
					'resources/css/data-management-settings.css',
					'resources/css/worksheet-workflow.css',
				),
				array(
					'resources/js/external-ai-consent-settings.js',
					'resources/js/worksheet-workflow.js',
					'resources/js/assessment-configuration-settings.js',
					'resources/js/curriculum-catalog-settings.js',
					'resources/js/operations-diagnostics.js',
					'resources/js/data-management-settings.js',
				),
				array(),
				OJ_AI_DASHBOARD_VERSION
			),
			new AddonAssetBundleDefinitionV1(
				self::ASSET_WORKSHEET_EDITOR,
				'oj-ai-dashboard-worksheet-editor',
				array(
					'resources/css/admin-shell.css',
					'resources/assets/builder/plate-template-builder.css',
					'resources/css/worksheet-authoring-workbench.css',
					'resources/css/worksheet-evaluation-workbench.css',
					'resources/css/worksheet-workflow.css',
				),
				array(
					'resources/assets/builder/plate-template-builder.js',
					'resources/js/worksheet-workflow.js',
					'resources/js/worksheet-authoring-workbench.js',
					'resources/js/worksheet-evaluation-workbench.js',
				),
				array(),
				OJ_AI_DASHBOARD_VERSION
			),
			new AddonAssetBundleDefinitionV1(
				self::ASSET_PUBLIC,
				'oj-ai-dashboard-public',
				array( 'resources/assets/katex/katex.min.css', 'resources/css/student-assignments.css', 'resources/css/student-results.css' ),
				array( 'resources/assets/katex/katex.min.js', 'resources/js/student-assignments.js', 'resources/js/student-submission-autosave.js', 'resources/js/student-results.js' ),
				array(),
				OJ_AI_DASHBOARD_VERSION
			),
			new AddonAssetBundleDefinitionV1(
				self::ASSET_STORAGE_DETAIL,
				'oj-ai-dashboard-storage-detail',
				array(
					'resources/css/admin-shell.css',
					'resources/css/operations-diagnostics.css',
					'resources/css/worksheet-workflow.css',
				),
				array(
					'resources/js/operations-storage-detail.js',
					'resources/js/worksheet-workflow.js',
				),
				array(),
				OJ_AI_DASHBOARD_VERSION
			),
			new AddonAssetBundleDefinitionV1(
				self::ASSET_STUDENT_WORKSHEET,
				'oj-ai-dashboard-student-worksheet',
				array( 'resources/assets/katex/katex.min.css', 'resources/css/student-assignments.css' ),
				array( 'resources/assets/katex/katex.min.js', 'resources/js/student-assignments.js', 'resources/js/student-submission-autosave.js' ),
				array(),
				OJ_AI_DASHBOARD_VERSION
			),
		);
	}

	/**
	 * Add Console and PublicUI Page routes.
	 *
	 * @param AddonManifestBuilderV1       $builder Manifest builder.
	 * @param Access_Policy_Port_Interface $policy Common access policy.
	 * @param array<string,object>         $page_handlers Injected feature Page handlers.
	 * @return void
	 */
	private static function add_pages( AddonManifestBuilderV1 $builder, Access_Policy_Port_Interface $policy, array $page_handlers ) {
		foreach ( self::console_pages() as $page ) {
			$handler = $page_handlers[ $page['key'] ] ?? null;
			if ( null === $handler ) {
				$handler = new Addon_Page_Handler(
					$policy,
					$page['key'],
					$page['title'],
					Access_Policy_Request::SHELL_CONSOLE
				);
				if ( self::CONSOLE_PROJECTS === $page['key'] ) {
					$handler = new Assessment_Project_Page_Handler( $policy );
				} elseif ( self::CONSOLE_WORKSHEETS === $page['key'] ) {
					$handler = new Worksheet_Workbench_Page_Handler(
						$policy,
						$page['key'],
						Worksheet_Workbench_Page_Handler::MODE_LIST
					);
				} elseif ( self::CONSOLE_WORKSHEET_EDIT === $page['key'] ) {
					$handler = new Worksheet_Workbench_Page_Handler(
						$policy,
						$page['key'],
						Worksheet_Workbench_Page_Handler::MODE_EDIT
					);
				} elseif ( self::CONSOLE_ASSESSMENTS === $page['key'] ) {
					$handler = new Worksheet_Evaluation_Workbench_Page_Handler( $policy, $page['key'] );
				} elseif ( self::CONSOLE_ASSIGNMENTS === $page['key'] ) {
					$handler = new Assignment_Workbench_Page_Handler( $policy, $page['key'] );
				} elseif ( self::CONSOLE_REPORTS === $page['key'] ) {
					$handler = new Report_Dashboard_Page_Handler( $policy, $page['key'] );
				} elseif ( in_array( $page['key'], array( self::CONSOLE_SETTINGS, self::CONSOLE_CRITERIA ), true ) ) {
					$handler = new External_Ai_Consent_Settings_Page_Handler( $policy, $page['key'] );
				} elseif ( self::CONSOLE_STORAGE_DETAIL === $page['key'] ) {
					$handler = new Storage_Operations_Detail_Page_Handler( $policy, $page['key'] );
				}
			}
			$capability = match ( $page['key'] ) {
				self::CONSOLE_PROJECTS => Addon_Capabilities::VIEW_WORKSPACE,
				self::CONSOLE_SETTINGS        => Addon_Capabilities::MANAGE_POLICIES,
				self::CONSOLE_CRITERIA        => Addon_Capabilities::MANAGE_POLICIES,
				self::CONSOLE_STORAGE_DETAIL  => Addon_Capabilities::MANAGE_POLICIES,
				self::CONSOLE_ASSIGNMENTS     => Addon_Capabilities::ASSIGN_WORKSHEETS,
				default                       => Addon_Capabilities::VIEW_WORKSPACE,
				self::CONSOLE_REPORTS         => Addon_Capabilities::VIEW_REPORTS,
			};
			$asset_bundles = array( $page['asset'] );
			if ( self::ASSET_CONSOLE === $page['asset'] ) {
				array_unshift( $asset_bundles, self::ASSET_LEARNER_LABELS, self::ASSET_SUBMISSION_DOCUMENT );
			}
			if ( self::CONSOLE_PROJECTS === $page['key'] ) {
				array_unshift( $asset_bundles, self::ASSET_PROJECT_EDITOR );
			}
			$builder->consolePage(
				new AddonConsolePageDefinitionV1(
					$page['key'],
					$page['path'],
					$handler,
					$capability,
					$asset_bundles
				)
			);
		}

		foreach ( self::public_pages() as $page ) {
			$handler = $page_handlers[ $page['key'] ] ?? new Addon_Page_Handler(
				$policy,
				$page['key'],
				$page['title'],
				Access_Policy_Request::SHELL_PUBLIC
			);
			$builder->publicPage(
				new AddonPublicPageDefinitionV1(
					$page['key'],
					$page['path'],
					$handler,
					Addon_Capabilities::PARTICIPATE,
					array( $page['asset'] )
				)
			);
		}
	}

	/**
	 * Return capability-scoped Console entry points.
	 *
	 * The settings screen is the last local navigation segment of the teacher
	 * menu. Platforms without local navigation (OJ Platform 2.0.x) get a
	 * separate settings menu under the same anchor instead.
	 *
	 * @param bool $platform_local_nav Whether the platform draws the local navigation.
	 * @return AddonConsoleMenuDefinitionV1[]
	 */
	private static function console_menus( bool $platform_local_nav ): array {
		$menus = array(
			new AddonConsoleMenuDefinitionV1(
				self::MENU_WORKSPACE,
				'활동지 평가',
				'classes',
				self::CONSOLE_PROJECTS,
				Addon_Capabilities::VIEW_WORKSPACE,
				40
			),
		);
		if ( ! $platform_local_nav ) {
			$menus[] = new AddonConsoleMenuDefinitionV1(
				self::MENU_SETTINGS,
				'활동지 평가 설정',
				'classes',
				self::CONSOLE_SETTINGS,
				Addon_Capabilities::MANAGE_POLICIES,
				42
			);
		}

		return $menus;
	}

	/**
	 * Return the local navigation under the teacher menu (Addon API 1.3).
	 *
	 * @return AddonConsoleLocalNavItemV1[]
	 */
	private static function console_local_nav(): array {
		if ( ! class_exists( AddonConsoleLocalNavItemV1::class ) ) {
			return array();
		}
		$items = array(
			array( 'projects', '평가 프로젝트', self::CONSOLE_PROJECTS, 10 ),
			array( 'worksheets', '활동지 보관함', self::CONSOLE_WORKSHEETS, 20 ),
			array( 'assignments', '배포·제출', self::CONSOLE_ASSIGNMENTS, 30 ),
			array( 'assessments', '평가', self::CONSOLE_ASSESSMENTS, 40 ),
			array( 'criteria', '평가 기준', self::CONSOLE_CRITERIA, 50 ),
			array( 'reports', '보고서', self::CONSOLE_REPORTS, 60 ),
			array( 'settings', '설정', self::CONSOLE_SETTINGS, 70 ),
		);
		return array_map(
			static fn( array $item ) => new AddonConsoleLocalNavItemV1(
				'addon.oj_ai_dashboard.nav-' . $item[0],
				$item[1],
				self::MENU_WORKSPACE,
				$item[2],
				$item[3]
			),
			$items
		);
	}

	/**
	 * Public menus.
	 *
	 * @return AddonPublicMenuDefinitionV1[]
	 */
	private static function public_menus(): array {
		return array(
			new AddonPublicMenuDefinitionV1(
				'addon.oj_ai_dashboard.public.menu.worksheets',
				'활동지',
				'classes',
				self::PUBLIC_WORKSHEETS,
				Addon_Capabilities::PARTICIPATE,
				40,
				'learner',
				true,
				true
			),
		);
	}

	/**
	 * Return package-owned AI task contracts.
	 *
	 * @return AddonAiTaskDefinitionV1[]
	 */
	private static function ai_tasks(): array {
		return array(
			new AddonAiTaskDefinitionV1(
				self::TASK_EXTERNAL_ASSESSMENT,
				'공통 답안 평가',
				\OJAI\Dashboard\ExternalAssessment\Common_Assessment_Profile::CONTRACT,
				'addon_payload',
				new AddonAiTaskInstructionV1( 'external_submission_assessment_prompt.v1', self::instruction( 'external-submission-assessment-prompt-v1.txt', \OJAI\Dashboard\ExternalAssessment\Common_Assessment_Profile::PROMPT_SHA256 ) )
			),
			new AddonAiTaskDefinitionV1(
				self::TASK_ML_ASSESSMENT,
				'ML 실습 제출 평가',
				'ml_submission_assessment.v1',
				'addon_payload',
				new AddonAiTaskInstructionV1( 'ml_submission_assessment_prompt.v1', self::instruction( 'ml-submission-assessment-prompt-v1.txt', \OJAI\Dashboard\ExternalAssessment\Assessment_Profile::PROMPT_SHA256 ) )
			),
			new AddonAiTaskDefinitionV1(
				self::TASK_WORKSHEET_QUALITY,
				'활동지 자체 평가',
				'worksheet_quality.v1',
				'none',
				new AddonAiTaskInstructionV1(
					Worksheet_Quality_Prompt_Contract::VERSION,
					self::instruction(
						Worksheet_Quality_Prompt_Contract::FILENAME,
						Worksheet_Quality_Prompt_Contract::CONTENT_HASH
					)
				)
			),
			new AddonAiTaskDefinitionV1(
				self::TASK_STUDENT_ASSESSMENT,
				'학생 제출 평가',
				'student_submission_assessment.v1',
				'addon_payload',
				new AddonAiTaskInstructionV1(
					'student_submission_assessment_prompt.v10',
					self::instruction( 'student-submission-assessment-prompt-v10.txt' )
				)
			),
		);
	}

	/**
	 * Return Console Page descriptors.
	 *
	 * @return array<int,array{key:string,path:string,title:string,asset:string}>
	 */
	private static function console_pages() {
		return array(
			array(
				'key'   => self::CONSOLE_PROJECTS,
				'path'  => '/oj-console/addons/oj-ai-dashboard/projects',
				'title' => '평가 프로젝트',
				'asset' => self::ASSET_CONSOLE,
			),
			array(
				'key'   => self::CONSOLE_WORKSHEETS,
				'path'  => '/oj-console/addons/oj-ai-dashboard/worksheets',
				'title' => '활동지',
				'asset' => self::ASSET_CONSOLE,
			),
			array(
				'key'   => self::CONSOLE_WORKSHEET_EDIT,
				'path'  => '/oj-console/addons/oj-ai-dashboard/worksheets/{worksheet_id}',
				'title' => '활동지 편집',
				'asset' => self::ASSET_WORKSHEET_EDITOR,
			),
			array(
				'key'   => self::CONSOLE_ASSIGNMENTS,
				'path'  => '/oj-console/addons/oj-ai-dashboard/assignments',
				'title' => '배포·제출',
				'asset' => self::ASSET_CONSOLE,
			),
			array(
				'key'   => self::CONSOLE_ASSESSMENTS,
				'path'  => '/oj-console/addons/oj-ai-dashboard/assessments',
				'title' => '평가',
				'asset' => self::ASSET_CONSOLE,
			),
			array(
				'key'   => self::CONSOLE_REPORTS,
				'path'  => '/oj-console/addons/oj-ai-dashboard/reports',
				'title' => '보고서',
				'asset' => self::ASSET_CONSOLE,
			),
			array(
				'key'   => self::CONSOLE_SETTINGS,
				'path'  => '/oj-console/addons/oj-ai-dashboard/settings',
				'title' => '설정',
				'asset' => self::ASSET_SETTINGS,
			),
			array(
				'key'   => self::CONSOLE_CRITERIA,
				'path'  => '/oj-console/addons/oj-ai-dashboard/criteria',
				'title' => '평가 기준',
				'asset' => self::ASSET_SETTINGS,
			),
			array(
				'key'   => self::CONSOLE_STORAGE_DETAIL,
				'path'  => '/oj-console/addons/oj-ai-dashboard/settings/storage-detail',
				'title' => '파일 보관 상세',
				'asset' => self::ASSET_STORAGE_DETAIL,
			),
		);
	}

	/**
	 * Return PublicUI Page descriptors.
	 *
	 * @return array<int,array{key:string,path:string,title:string,asset:string}>
	 */
	private static function public_pages() {
		return array(
			array(
				'key'   => self::PUBLIC_WORKSHEETS,
				'path'  => '/oj-addons/oj-ai-dashboard/worksheets',
				'title' => '활동지',
				'asset' => self::ASSET_PUBLIC,
			),
			array(
				'key'   => self::PUBLIC_ASSIGNMENT,
				'path'  => '/oj-addons/oj-ai-dashboard/assignments/{assignment_id}',
				'title' => '활동지 작성',
				'asset' => self::ASSET_STUDENT_WORKSHEET,
			),
			array(
				'key'   => self::PUBLIC_RESULT,
				'path'  => '/oj-addons/oj-ai-dashboard/results/{release_id}',
				'title' => '결과',
				'asset' => self::ASSET_PUBLIC,
			),
		);
	}

	/**
	 * Load an immutable package instruction.
	 *
	 * @param string      $filename Instruction filename.
	 * @param string|null $expected_hash Expected normalized SHA-256 hash.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private static function instruction( $filename, $expected_hash = null ) {
		$path    = OJ_AI_DASHBOARD_DIR . 'resources/contracts/ai/' . $filename;
		$content = is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.

		if ( false === $content || '' === trim( $content ) ) {
			throw new RuntimeException( 'The packaged AI task instruction is unavailable.' );
		}
		if ( is_string( $expected_hash ) ) {
			$normalized = str_replace( array( "\r\n", "\r" ), "\n", $content );
			if ( ! hash_equals( $expected_hash, hash( 'sha256', $normalized ) ) ) {
				throw new RuntimeException( 'The packaged AI task instruction failed integrity verification.' );
			}
		}

		return $content;
	}
}
