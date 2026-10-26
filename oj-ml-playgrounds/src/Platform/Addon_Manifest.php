<?php
/**
 * Atomic OJ Platform Addon API 1.1 manifest.
 *
 * @package OJML\Playgrounds
 */

namespace OJML\Playgrounds\Platform;

use CodingPanda\OjPlatform\AddonApi\V1\AddonAssetBundleDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsoleLocalNavBuilderV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsoleLocalNavItemV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsoleMenuDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsolePageDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonManifestBuilderV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPublicMenuDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPublicPageDefinitionV1;
use OJML\Playgrounds\Security\Addon_Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the complete manifest without accessing Runtime services.
 */
final class Addon_Manifest {
	public const CONSOLE_ASSIGNMENTS = 'addon.oj_ml_playgrounds.console.assignments';
	public const CONSOLE_RESULTS     = 'addon.oj_ml_playgrounds.console.results';
	public const CONSOLE_SETTINGS    = 'addon.oj_ml_playgrounds.console.settings';
	public const PUBLIC_ASSIGNMENTS  = 'addon.oj_ml_playgrounds.public.assignments';
	public const PUBLIC_LAB          = 'addon.oj_ml_playgrounds.public.lab';
	public const PUBLIC_RESULT       = 'addon.oj_ml_playgrounds.public.result';
	public const ASSET_WRAPPER       = 'addon.oj_ml_playgrounds.asset.wrapper';
	public const MENU_MAIN           = 'addon.oj_ml_playgrounds.console.menu.assignments';
	public const MENU_SETTINGS       = 'addon.oj_ml_playgrounds.console.menu.settings';

	/**
	 * @param AddonManifestBuilderV1 $builder Manifest builder.
	 * @param Addon_Runtime_Bridge   $runtime Accepted onReady bridge.
	 * @return void
	 */
	public static function build( AddonManifestBuilderV1 $builder, Addon_Runtime_Bridge $runtime ) {
		$builder->manifest(
			Addon_Integration::ADDON_NAMESPACE,
			OJ_ML_PLAYGROUNDS_VERSION,
			OJ_ML_PLAYGROUNDS_FILE,
			Addon_Integration::PLATFORM_RANGE,
			Addon_Integration::ADDON_API_RANGE,
			'ML Playgrounds',
			'OJML-ADDON-01'
		);

		self::add_capabilities( $builder );
		self::add_assets( $builder );
		self::add_pages( $builder, $runtime );
		self::add_menus( $builder );
		$builder->onReady( array( $runtime, 'connect' ) );
	}

	/** @param AddonManifestBuilderV1 $builder Manifest builder. */
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

	/** @param AddonManifestBuilderV1 $builder Manifest builder. */
	private static function add_assets( AddonManifestBuilderV1 $builder ) {
		$builder->assetBundle(
			new AddonAssetBundleDefinitionV1(
				self::ASSET_WRAPPER,
				'oj-ml-playgrounds-addon',
				array( 'resources/css/addon.css', 'resources/css/display-mode.css' ),
				array( 'resources/js/public-pc.js', 'resources/js/public-pc-bootstrap.js', 'resources/js/portfolio-bridge.js', 'resources/js/teacher-workflow.js', 'resources/js/rubric-tools.js', 'resources/js/assignment-policy-tools.js', 'resources/js/display-mode.js', 'resources/js/addon.js' ),
				array(),
				OJ_ML_PLAYGROUNDS_VERSION
			)
		);
	}

	/**
	 * @param AddonManifestBuilderV1 $builder Manifest builder.
	 * @param Addon_Runtime_Bridge   $runtime Runtime bridge.
	 */
	private static function add_pages( AddonManifestBuilderV1 $builder, Addon_Runtime_Bridge $runtime ) {
		foreach ( self::console_pages() as $page ) {
			$builder->consolePage(
				new AddonConsolePageDefinitionV1(
					$page['key'],
					$page['path'],
					new Addon_Page_Handler(
						$runtime,
						$page['key'],
						$page['page'],
						$page['title'],
						$page['capability']
					),
					$page['capability'],
					array( self::ASSET_WRAPPER )
				)
			);
		}

		foreach ( self::public_pages() as $page ) {
			$builder->publicPage(
				new AddonPublicPageDefinitionV1(
					$page['key'],
					$page['path'],
					new Addon_Page_Handler(
						$runtime,
						$page['key'],
						$page['page'],
						$page['title'],
						$page['capability'],
						$page['parameter'],
						$page['playground']
					),
					$page['capability'],
					array( self::ASSET_WRAPPER )
				)
			);
		}
	}

	/**
	 * One sidebar entry per add-on (D-010). On OJ Platform 2.1+ (Addon API 1.3)
	 * the results and settings pages become local-navigation segments of
	 * "ML 실습" (교사 메뉴 › ML 실습 › 설정, the last segment). Older platforms
	 * have no local navigation, so they keep the three learning_support
	 * entries, including a separate "ML 실습 설정" menu.
	 *
	 * @param AddonManifestBuilderV1 $builder Manifest builder.
	 */
	private static function add_menus( AddonManifestBuilderV1 $builder ) {
		$local_nav = $builder instanceof AddonConsoleLocalNavBuilderV1;
		$menus     = $local_nav
			? array(
				array( self::MENU_MAIN, 'ML 실습', 'learning_support', self::CONSOLE_ASSIGNMENTS, Addon_Capabilities::MANAGE, 40 ),
			)
			: array(
				array( self::MENU_MAIN, 'ML 실습 과제', 'learning_support', self::CONSOLE_ASSIGNMENTS, Addon_Capabilities::MANAGE, 40 ),
				array( 'addon.oj_ml_playgrounds.console.menu.results', 'ML 실습 결과', 'learning_support', self::CONSOLE_RESULTS, Addon_Capabilities::REPORT, 41 ),
				array( self::MENU_SETTINGS, 'ML 실습 설정', 'learning_support', self::CONSOLE_SETTINGS, Addon_Capabilities::SETTINGS, 42 ),
			);

		foreach ( $menus as $menu ) {
			$builder->consoleMenu(
				new AddonConsoleMenuDefinitionV1(
					$menu[0],
					$menu[1],
					$menu[2],
					$menu[3],
					$menu[4],
					$menu[5]
				)
			);
		}

		if ( $local_nav ) {
			$builder->consoleLocalNav(
				new AddonConsoleLocalNavItemV1( 'addon.oj_ml_playgrounds.console.nav.assignments', '과제', self::MENU_MAIN, self::CONSOLE_ASSIGNMENTS, 10 )
			);
			$builder->consoleLocalNav(
				new AddonConsoleLocalNavItemV1( 'addon.oj_ml_playgrounds.console.nav.results', '제출·평가', self::MENU_MAIN, self::CONSOLE_RESULTS, 20 )
			);
			$builder->consoleLocalNav(
				new AddonConsoleLocalNavItemV1( 'addon.oj_ml_playgrounds.console.nav.settings', '설정', self::MENU_MAIN, self::CONSOLE_SETTINGS, 90 )
			);
		}

		$builder->publicMenu(
			new AddonPublicMenuDefinitionV1(
				'addon.oj_ml_playgrounds.public.menu.assignments',
				'ML 실습',
				'learning_support',
				self::PUBLIC_ASSIGNMENTS,
				Addon_Capabilities::USE,
				40,
				'learner',
				true,
				true
			)
		);
	}

	/**
	 * @return array<int,array{key:string,path:string,page:string,title:string,capability:string}>
	 */
	private static function console_pages() {
		return array(
			array(
				'key' => self::CONSOLE_ASSIGNMENTS, 'path' => '/oj-console/addons/oj-ml-playgrounds/assignments',
				'page' => 'console-assignments', 'title' => 'ML 실습 과제', 'capability' => Addon_Capabilities::MANAGE,
			),
			array(
				'key' => self::CONSOLE_RESULTS, 'path' => '/oj-console/addons/oj-ml-playgrounds/results',
				'page' => 'console-results', 'title' => 'ML 실습 제출·평가', 'capability' => Addon_Capabilities::REPORT,
			),
			array(
				'key' => self::CONSOLE_SETTINGS, 'path' => '/oj-console/addons/oj-ml-playgrounds/settings',
				'page' => 'console-settings', 'title' => 'ML 실습 설정', 'capability' => Addon_Capabilities::SETTINGS,
			),
		);
	}

	/**
	 * @return array<int,array{key:string,path:string,page:string,title:string,capability:string,parameter:string,playground:bool}>
	 */
	private static function public_pages() {
		return array(
			array(
				'key' => self::PUBLIC_ASSIGNMENTS, 'path' => '/oj-addons/oj-ml-playgrounds/assignments',
				'page' => 'assignments', 'title' => '내 ML 실습', 'capability' => Addon_Capabilities::USE,
				'parameter' => '', 'playground' => false,
			),
			array(
				'key' => self::PUBLIC_LAB, 'path' => '/oj-addons/oj-ml-playgrounds/lab/{assignment_id}',
				'page' => 'lab', 'title' => 'ML 실습', 'capability' => Addon_Capabilities::SUBMIT,
				'parameter' => 'assignment_id', 'playground' => true,
			),
			array(
				'key' => self::PUBLIC_RESULT, 'path' => '/oj-addons/oj-ml-playgrounds/result/{attempt_id}',
				'page' => 'result', 'title' => 'ML 실습 결과', 'capability' => Addon_Capabilities::USE,
				'parameter' => 'attempt_id', 'playground' => false,
			),
		);
	}
}
