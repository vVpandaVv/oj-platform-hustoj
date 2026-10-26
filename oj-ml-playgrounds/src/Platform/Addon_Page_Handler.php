<?php
/**
 * Shell-safe OJ Platform add-on Page handler.
 *
 * @package OJML\Playgrounds
 */

namespace OJML\Playgrounds\Platform;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;
use OJML\Playgrounds\Security\Addon_Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emits only a classic mount wrapper; protected data is loaded by REST.
 */
final class Addon_Page_Handler implements AddonPageHandlerV1 {
	/** @var Addon_Runtime_Bridge */
	private $runtime;

	/** @var string */
	private $route_key;

	/** @var string */
	private $page;

	/** @var string */
	private $title;

	/** @var string */
	private $capability;

	/** @var string */
	private $required_parameter;

	/** @var bool */
	private $playground;

	/**
	 * @param Addon_Runtime_Bridge $runtime Runtime readiness gate.
	 * @param string               $route_key Manifest route key.
	 * @param string               $page Stable wrapper page name.
	 * @param string               $title Page title.
	 * @param string               $capability Declared add-on capability.
	 * @param string               $required_parameter Required route parameter, if any.
	 * @param bool                 $playground Whether to expose the local playground URL.
	 */
	public function __construct(
		Addon_Runtime_Bridge $runtime,
		$route_key,
		$page,
		$title,
		$capability,
		$required_parameter = '',
		$playground = false
	) {
		$this->runtime            = $runtime;
		$this->route_key          = (string) $route_key;
		$this->page               = (string) $page;
		$this->title              = (string) $title;
		$this->capability         = (string) $capability;
		$this->required_parameter = (string) $required_parameter;
		$this->playground         = (bool) $playground;
	}

	/**
	 * Handle one GET-only platform Page request.
	 *
	 * @param AddonPageRequestV1 $request Page request.
	 * @return AddonPageResponseV1
	 */
	public function handle( AddonPageRequestV1 $request ): AddonPageResponseV1 {
		if ( $request->routeKey() !== $this->route_key ) {
			return AddonPageResponseV1::unavailable( '요청한 화면을 열 수 없습니다.', 'addon_handler_failed' );
		}

		if ( ! $this->runtime->is_ready() && 'console-settings' === $this->page && function_exists('current_user_can') && current_user_can('install_plugins') ) {
            return $this->respond('<div class="ojp-notice ojp-notice--warning" role="status">서버 준비 또는 시스템 점검이 필요합니다.</div>' . self::server_install_markup(), 200, 'standard', true);
        }
		if ( ! $this->runtime->is_ready() ) {
			return AddonPageResponseV1::unavailable( 'ML 실습 서비스를 지금 열 수 없습니다.', 'addon_runtime_unavailable' );
		}

		$wordpress_capability = Addon_Capabilities::wordpress( $this->capability );
		$allowed              = Addon_Capabilities::SETTINGS === $this->capability
			? Addon_Capabilities::user_can_manage_settings( $request->actorUserId() )
			: '' !== $wordpress_capability && function_exists( 'user_can' ) && user_can( $request->actorUserId(), $wordpress_capability );
		if ( ! $allowed ) {
			return $this->error_response( 403, '이 화면을 볼 수 없습니다.' );
		}

		if (
			! function_exists( 'rest_url' )
			|| ! function_exists( 'wp_create_nonce' )
			|| ! function_exists( 'home_url' )
		) {
			return AddonPageResponseV1::unavailable( 'ML 실습 서비스를 지금 열 수 없습니다.', 'addon_runtime_unavailable' );
		}

		$parameters     = $request->parameters();
		$query          = $request->query();
		$assignment_id  = isset( $parameters['assignment_id'] ) ? (string) $parameters['assignment_id'] : '';
		$attempt_id     = isset( $parameters['attempt_id'] ) ? (string) $parameters['attempt_id'] : '';
		if ( '' === $assignment_id && isset( $query['assignment_id'] ) && is_string( $query['assignment_id'] ) ) {
			$assignment_id = $query['assignment_id'];
		}

		if (
			'' !== $this->required_parameter
			&& ( ! isset( $parameters[ $this->required_parameter ] ) || '' === (string) $parameters[ $this->required_parameter ] )
		) {
			return $this->error_response( 404, '요청한 대상을 찾을 수 없습니다.' );
		}

		$can_review = false;
		foreach ( array( Addon_Capabilities::REVIEW, Addon_Capabilities::MANAGE ) as $declared_capability ) {
			$mapped_capability = Addon_Capabilities::wordpress( $declared_capability );
			if ( '' !== $mapped_capability && user_can( $request->actorUserId(), $mapped_capability ) ) {
				$can_review = true;
				break;
			}
		}

		$privacy_notice = Privacy_Url::resolve( \OJML\Playgrounds\Ops\Options::all()['privacyUrl'], $this->runtime->is_ready() );
		$manage_capability = Addon_Capabilities::wordpress( Addon_Capabilities::MANAGE );
		$can_manage = '' !== $manage_capability && user_can( $request->actorUserId(), $manage_capability );
		$report_capability = Addon_Capabilities::wordpress( Addon_Capabilities::REPORT );
		$can_report = '' !== $report_capability && user_can( $request->actorUserId(), $report_capability );
		$can_console_settings = Addon_Capabilities::user_can_manage_settings( $request->actorUserId() );
		$settings_tools       = 'console-settings' === $this->page && $can_console_settings && function_exists( 'admin_url' );

        $lab_settings = \OJML\Playgrounds\Ops\Options::all();

		$attributes = array(
			'class'               => 'oj-ml-app',
			'data-oj-ml-page'     => $this->page,
			'data-rest-root'      => rest_url( 'oj-ml/v1/' ),
			'data-rest-nonce'     => wp_create_nonce( 'wp_rest' ),
			'data-assignment-id'  => $assignment_id,
			'data-attempt-id'     => $attempt_id,
			'data-playground-url' => $this->playground
				? OJ_ML_PLAYGROUNDS_URL . 'resources/playground/index.html?ver='
					. rawurlencode( OJ_ML_PLAYGROUNDS_VERSION )
				: '',
			'data-home-url'            => home_url( '/oj-addons/oj-ml-playgrounds/assignments' ),
			'data-lab-url'             => home_url( '/oj-addons/oj-ml-playgrounds/lab/{assignment_id}' ),
			'data-result-url'          => home_url( '/oj-addons/oj-ml-playgrounds/result/{attempt_id}' ),
			'data-console-results-url' => home_url( '/oj-console/addons/oj-ml-playgrounds/results?assignment_id={assignment_id}' ),
			'data-console-assignments-url' => $can_manage ? home_url( '/oj-console/addons/oj-ml-playgrounds/assignments' ) : '',
			'data-console-settings-url' => $can_console_settings ? home_url( '/oj-console/addons/oj-ml-playgrounds/settings' ) : '',
			'data-admin-post-url'      => $settings_tools ? admin_url( 'admin-post.php' ) : '',
			'data-migrate-nonce'       => $settings_tools ? wp_create_nonce( 'oj_ml_migrate' ) : '',
			'data-max-upload-bytes'    => (string) \OJML\Playgrounds\Ops\Options::all()['maxArtifactBytes'],
			'data-playground-version'  => '0.12.1',
            'data-public-pc-mode'       => in_array( $this->page, array( 'assignments', 'lab', 'result' ), true ) ? '1' : '0',
            'data-lab-focus-on-start'   => $lab_settings['labFocusOnStart'] ? '1' : '0',
            'data-lab-guidance-collapsed' => $lab_settings['labGuidanceCollapsed'] ? '1' : '0',
			'data-privacy-url'          => $privacy_notice['url'],
			'data-privacy-source'       => $privacy_notice['source'],
			'data-can-review'          => $can_review ? '1' : '0',
			'data-can-manage'          => $can_manage ? '1' : '0',
			'data-can-report'          => $can_report ? '1' : '0',
			'data-can-settings'        => $can_console_settings ? '1' : '0',
			'data-platform-local-nav'  => self::platform_has_local_nav() ? '1' : '0',
			'data-route-key'      => $request->routeKey(),
			'data-correlation-id' => $request->correlationId(),
		);

		$shared_device_notice = in_array( $this->page, array( 'assignments', 'lab', 'result' ), true )
			? '<aside class="oj-ml-shared-device" data-oj-ml-shared-device role="note">'
				. '<div><strong>공용 기기 보호가 적용됩니다.</strong>'
				. '<p>프로젝트는 현재 OJ 계정 전용 저장소에 암호화됩니다. 사용을 마치면 이 기기의 내 실습 데이터를 삭제하세요.</p>'
				. '<p class="oj-ml-meta">로컬 데이터를 지워도 이미 OJ 서버에 제출한 파일과 평가는 삭제되지 않습니다.</p>'
				. '<p class="oj-ml-meta"><strong>사용 종료 순서:</strong> 로컬 삭제 성공 확인 → 다운로드 폴더의 .mlpx·.csv·양식 .md 파일 직접 삭제 → OJ 로그아웃 → 모든 실습 탭과 브라우저 닫기</p></div>'
				. '<div class="oj-ml-actions"><button type="button" class="ojp-button ojp-button--small ojp-button--danger" data-oj-ml-wipe-local>이 기기의 내 실습 데이터 삭제</button>'
				. '<a class="ojp-button ojp-button--small ojp-button--plain" href="' . $this->escape( $attributes['data-privacy-url'] ) . '" target="_blank" rel="noopener noreferrer">개인정보 및 저장 안내</a></div>'
				. '</aside>'
			: '';

        $server_install = 'console-settings' === $this->page && $can_console_settings && current_user_can('install_plugins') ? self::server_install_markup() : '';

		$body = $this->page_header() . $server_install
			. '<section' . $this->attributes( $attributes ) . '>'
			. '<p class="oj-ml-status ojp-notice" data-oj-ml-status role="status">ML 실습 화면을 불러오는 중입니다.</p>'
			. $shared_device_notice
			. '<noscript><p class="oj-ml-app__notice ojp-notice ojp-notice--warning">이 화면을 사용하려면 JavaScript를 활성화해야 합니다.</p></noscript>'
			. '</section>';

		// The lab embeds the light-only playground app full width; every other
		// wrapper screen uses only platform tokens and follows the dark theme.
		return 'lab' === $this->page
			? $this->respond( $body, 200, 'immersive', false )
			: $this->respond( $body, 200, 'standard', true );
	}

	/**
	 * Build a shell-safe denial fragment without exposing a REST nonce.
	 *
	 * @param int    $status HTTP status.
	 * @param string $message User-facing message.
	 * @return AddonPageResponseV1
	 */
	private function error_response( $status, $message ) {
		$body = '<section class="oj-ml-app" data-oj-ml-page="error">'
			. '<p class="oj-ml-app__notice ojp-notice ojp-notice--danger" role="alert">' . $this->escape( $message ) . '</p>'
			. '</section>';

		return $this->respond( $body, (int) $status, 'standard', true );
	}

	/**
	 * Addon API 1.3 page() adds the layout and dark-mode opt-in; older
	 * platforms only have html().
	 *
	 * @param string $body Page body.
	 * @param int    $status HTTP status.
	 * @param string $layout standard, wide or immersive.
	 * @param bool   $dark Whether the page supports the platform dark theme.
	 * @return AddonPageResponseV1
	 */
	private function respond( $body, $status, $layout, $dark ) {
		if ( method_exists( AddonPageResponseV1::class, 'page' ) ) {
			return AddonPageResponseV1::page( $this->title, $body, $layout, $dark, $status );
		}

		return AddonPageResponseV1::html( $this->title, $body, $status );
	}

	/** @return bool Whether the platform draws console local navigation (2.1+). */
	private static function platform_has_local_nav() {
		return interface_exists( \CodingPanda\OjPlatform\AddonApi\V1\AddonConsoleLocalNavBuilderV1::class );
	}

	/**
	 * Large-title header with the real menu group as eyebrow (D-010).
	 * The immersive lab shows its title in the display toolbar instead.
	 *
	 * @return string
	 */
	private function page_header() {
		$headers = array(
			'console-assignments' => array( '학습 활동', 'ML 실습 과제', '수업이나 그룹에 머신러닝 실습 과제를 내고, 양식과 평가 기준을 정합니다.' ),
			'console-results'     => array( '학습 활동', 'ML 실습 제출·평가', '학생이 제출한 프로젝트를 확인하고, 평가를 저장하거나 학생에게 공개합니다.' ),
			'console-settings'    => array( '학습 활동', 'ML 실습 설정', '서버 상태와 DB 업데이트를 확인하고, 제출 파일 저장 한도와 보존 기간, 실습 화면 기본 보기를 정합니다.' ),
			'assignments'         => array( '학습 활동', '내 ML 실습', '배정된 실습을 열어 브라우저에서 실습하고 결과를 제출합니다.' ),
			'result'              => array( 'ML 실습', '제출 결과', '' ),
		);
		if ( ! isset( $headers[ $this->page ] ) ) {
			return '';
		}
		list( $eyebrow, $heading, $description ) = $headers[ $this->page ];

		return '<header class="ojp-page-header oj-ml-page-header"><div>'
			. '<p class="ojp-eyebrow">' . $this->escape( $eyebrow ) . '</p>'
			. '<h1>' . $this->escape( $heading ) . '</h1>'
			. ( '' !== $description ? '<p>' . $this->escape( $description ) . '</p>' : '' )
			. '</div></header>';
	}

	/**
	 * @param array<string,string> $attributes HTML attributes.
	 * @return string
	 */
	private function attributes( array $attributes ) {
		$html = '';
		foreach ( $attributes as $name => $value ) {
			$html .= ' ' . $name . '="' . $this->escape( $value ) . '"';
		}

		return $html;
	}

	/**
	 * @param string $value Attribute or text value.
	 * @return string
	 */
	private function escape( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

    /** The installer belongs to this add-on; the platform only supplies modal UI. */
    private static function server_install_markup(): string
    {
        $quote = static fn (string $value): string => "'" . str_replace("'", "'\"'\"'", $value) . "'";
        $installer = dirname(__DIR__, 2) . '/resources/server/install.sh';
        $id = 'oj-ml-playgrounds-server-install';
        $commands = [];
        $commands['ML 저장소 준비'] = 'sudo bash ' . $quote($installer) . ' ' . $quote('ml') . ' ' . $quote(rtrim(ABSPATH, '/\\')) . ' ' . $quote(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION) . ' --apply';
        $content = '';
        $fallback = '';
        $index = 0;
        foreach ($commands as $label => $command) {
            $commandId = $id . '-command-' . $index;
            $part = '<p>' . esc_html($label) . '</p><textarea class="ojp-textarea" id="' . esc_attr($commandId) . '" rows="3" readonly aria-label="' . esc_attr($label) . '">' . esc_textarea($command) . '</textarea>'
                . '<button type="button" class="ojp-button" data-ojp-copy-target="' . esc_attr($commandId) . '">명령 복사</button>';
            $content .= $index > 0 ? '<details><summary>' . esc_html($label) . '</summary>' . $part . '</details>' : $part;
            $fallback .= '<p>' . esc_html($label) . '</p><textarea class="ojp-textarea" rows="3" readonly aria-label="' . esc_attr($label) . '">' . esc_textarea($command) . '</textarea>';
            $index++;
        }
        $storageReady = defined('OJ_ML_PLAYGROUNDS_PRIVATE_DIR') && is_dir((string) constant('OJ_ML_PLAYGROUNDS_PRIVATE_DIR')) && is_writable((string) constant('OJ_ML_PLAYGROUNDS_PRIVATE_DIR'));
        $notice = $storageReady ? '' : '<div class="ojp-notice ojp-notice--warning" role="status">비공개 저장소 준비가 필요합니다. 아래 설치 창에서 명령을 확인하세요.</div>';
        return $notice . '<section class="ojp-section"><h2>서버 설치</h2><p>처음 사용할 때 서버 SSH에서 한 번 실행하세요.</p>'
            . '<button type="button" class="ojp-button" style="display:none" hidden data-ojp-server-open="' . esc_attr($id) . '">서버 설치·복구</button>'
            . '<details data-ojp-server-fallback="' . esc_attr($id) . '"><summary>서버 설치 명령</summary>' . $fallback . '</details>'
            . '<dialog class="ojp-server-install" id="' . esc_attr($id) . '" aria-labelledby="' . esc_attr($id . '-title') . '"><h2 id="' . esc_attr($id . '-title') . '">서버 설치·복구</h2>'
            . '<p>명령 복사 → 같은 서버의 SSH에서 실행 → 상태 확인</p>' . $content
            . '<details><summary>변경 내용</summary><p>이 플러그인의 설치기만 실행합니다. 기존 설정과 충돌하면 중단하고, 설정 파일은 변경 전에 백업합니다. PHP-FPM이 재시작될 수 있으므로 수업이 없는 시간에 실행하세요.</p></details>'
            . '<button type="button" class="ojp-button" data-ojp-server-close>닫기</button></dialog></section>';
    }
}
