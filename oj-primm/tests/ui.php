<?php
declare(strict_types=1);

use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAssetBundleDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonActivityProviderDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsoleMenuDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsolePageDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonIntegrationManifestBuilderV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonManifestBuilderV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPrivacyDataDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPublicMenuDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPublicPageDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRegistrationGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonSiteAssetDefinitionV1;
use OjPrimm\Addon\PageHandler;
use OjPrimm\Addon\Registration;
use OjPrimm\Addon\RestController;

$pluginRoot = dirname(__DIR__);
require_once dirname($pluginRoot) . '/oj-integration-rebuild/oj-platform/src/Kernel/Autoloader.php';
CodingPanda\OjPlatform\Kernel\Autoloader::register(dirname($pluginRoot) . '/oj-integration-rebuild/oj-platform');
require_once $pluginRoot . '/src/Core/Autoloader.php';
OjPrimm\Core\Autoloader::register();
define('OJ_PRIMM_VERSION', '0.1.0'); define('OJ_PRIMM_FILE', $pluginRoot . '/oj-primm.php');

$GLOBALS['primm_ui_actor'] = 19; $GLOBALS['primm_ui_caps'] = [];
$GLOBALS['primm_ui_hooks'] = []; $GLOBALS['primm_ui_routes'] = [];
function add_action(string $hook, mixed $callback, int $priority = 10, int $args = 0): void { $GLOBALS['primm_ui_hooks'][$hook][] = $callback; }
function register_rest_route(string $namespace, string $route, array $definition): void { $GLOBALS['primm_ui_routes'][] = [$namespace, $route, $definition]; }
function is_user_logged_in(): bool { return $GLOBALS['primm_ui_actor'] > 0; }
function get_current_user_id(): int { return $GLOBALS['primm_ui_actor']; }
function current_user_can(string $capability): bool { return in_array($capability, $GLOBALS['primm_ui_caps'], true); }
function is_super_admin(): bool { return false; }
function wp_verify_nonce(string $nonce, string $action): bool { return $nonce === 'test-nonce' && $action === 'wp_rest'; }
function wp_create_nonce(string $action): string { return 'test-nonce'; }
function rest_url(string $path): string { return '/wp-json/' . $path; }
function wp_json_encode(mixed $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE); }
class WP_Error {
    public function __construct(public string $code, public string $message, public array $data = []) {}
}
class WP_REST_Request {
    public function __construct(private string $nonce = 'test-nonce', private mixed $input = [], private array $params = []) {}
    public function get_header(string $name): string { return $this->nonce; }
    public function get_param(string $key): mixed { return $this->params[$key] ?? null; }
    public function get_json_params(): mixed { return $this->input; }
    public function get_body(): string { return json_encode($this->input, JSON_THROW_ON_ERROR); }
    public function get_route(): string { return $this->params['_route']??''; }
}
class WP_REST_Response {
    private array $headers=[];
    public function __construct(private mixed $data,public int $status=200){}
    public function header(string $name,string $value):void{$this->headers[$name]=$value;}
    public function get_headers():array{return $this->headers;}
    public function get_data():mixed{return $this->data;}
}
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void { ++$checks; if (!$condition) { throw new RuntimeException($message); } };
$builder = new class implements AddonIntegrationManifestBuilderV1 {
    public array $pages = []; public array $capabilities = []; public array $assets = []; public array $manifestData = []; public mixed $ready = null;
    public array $privacy = []; public array $aiTasks = [];
    public function onReady(callable $callback): void { $this->ready = $callback; }
    public function manifest(string $namespace, string $version, string $pluginFile, string $requiresPlatform, string $requiresAddonApi, string $diagnosticLabel, string $supportReference): void { $this->manifestData = func_get_args(); }
    public function capability(AddonCapabilityDefinitionV1 $definition): void { $this->capabilities[] = $definition; }
    public function consolePage(AddonConsolePageDefinitionV1 $definition): void { $this->pages[] = $definition; }
    public function publicPage(AddonPublicPageDefinitionV1 $definition): void { $this->pages[] = $definition; }
    public function consoleMenu(AddonConsoleMenuDefinitionV1 $definition): void {}
    public function publicMenu(AddonPublicMenuDefinitionV1 $definition): void {}
    public function assetBundle(AddonAssetBundleDefinitionV1 $definition): void { $this->assets[] = $definition; }
    public function aiTask(AddonAiTaskDefinitionV1 $definition): void { $this->aiTasks[] = $definition; }
    public function activityProvider(AddonActivityProviderDefinitionV1 $definition): void {}
    public function siteAsset(AddonSiteAssetDefinitionV1 $definition): void {}
    public function privacyData(AddonPrivacyDataDefinitionV1 $definition): void { $this->privacy[] = $definition; }
};
$gateway = new class($builder) implements AddonRegistrationGatewayV1 {
    public function __construct(private AddonManifestBuilderV1 $builder) {}
    public function apiVersion(): string { return '1.5.0'; }
    public function register(string $addonId, callable $build): void { if ($addonId !== 'oj-primm') { throw new LogicException('Wrong addon'); } $build($this->builder); }
};
Registration::register(); Registration::build($gateway); RestController::registerRoutes();
$assert(isset($GLOBALS['primm_ui_hooks']['oj_platform_register_addons'], $GLOBALS['primm_ui_hooks']['rest_api_init']), '플랫폼 등록과 REST 진입점을 제공해야 합니다.');
$assert(count($builder->pages) === 2 && is_callable($builder->ready), '교사·학생 화면과 accepted runtime callback이 필요합니다.');
$assert($builder->pages[0]->path() === '/oj-console/addons/oj-primm' && $builder->pages[1]->path() === '/oj-addons/oj-primm/learn', '화면 경로가 설치 안내와 같아야 합니다.');
$assert(count($builder->capabilities) === 2 && count($builder->assets) === 1, '역할별 권한과 범위 자산 묶음을 등록해야 합니다.');
$assert(count($builder->privacy) === 1 && count($builder->privacy[0]->dataCategories()) === 11
    && str_contains($builder->privacy[0]->retention(), '기본 기간 제한 없이')
    && str_contains($builder->privacy[0]->administrationNote(), '일반 삭제는 기록을 보존'),
    '플랫폼 공개 계약에 저장 자료·보관·개인정보 도구·일반 삭제 정책을 등록해야 합니다.');
$assert(str_contains($builder->privacy[0]->administrationNote(), '학생 코드·응답·명단은 AI에 보내지 않습니다.'), '학생 자료를 AI 생성 입력에서 제외하는 정책을 공개해야 합니다.');
$assert(str_contains($builder->privacy[0]->administrationNote(), '생성 자료·OJ 오류 진단을 AI에 보내 생성·수정'), '생성 예제의 실행 오류가 AI 수정 요청으로 전송되는 정책을 공개해야 합니다.');
$assert(count($builder->aiTasks) === 1 && $builder->aiTasks[0]->key() === 'addon.oj_primm.generate_lesson'
    && $builder->aiTasks[0]->inputContractVersion() === 'primm_lesson.v1' && $builder->aiTasks[0]->sourceKind() === 'addon_payload'
    && $builder->aiTasks[0]->instruction() !== null, '전용 AI 작업·입력 계약·지시문을 플랫폼에 등록해야 합니다.');
$assert(count($GLOBALS['primm_ui_routes']) === 34, '학습·교사·생성 관리·수업 버전·설정·전체 보고서·실습 검증·제출 연결·초안 전달 REST 경로가 모두 필요합니다.');
foreach ($GLOBALS['primm_ui_routes'] as [$namespace, $route, $definition]) {
    $assert($namespace === 'oj-primm/v1' && is_callable($definition['permission_callback']), '모든 REST 경로에 권한 판정이 필요합니다.');
}
$request = new WP_REST_Request();
$assert(RestController::authorize($request, 'student') instanceof WP_Error, '권한 없는 학생은 거부해야 합니다.');
$GLOBALS['primm_ui_caps'] = ['oj_addon_oj_primm_learn'];
$assert(RestController::authorize($request, 'student') === true, '참여 권한을 확인해야 합니다.');
$assert(RestController::authorize($request, 'teacher') instanceof WP_Error, '학생 권한으로 교사 API를 열면 안 됩니다.');
$assert(RestController::authorize(new WP_REST_Request('expired'), 'student') instanceof WP_Error, 'GET·POST 모두 표준 nonce를 확인해야 합니다.');
$GLOBALS['primm_ui_caps'] = ['oj_addon_oj_primm_teach'];
$assert(RestController::authorize($request, 'teacher') === true && RestController::authorize($request, 'reader') === true, '교사 권한과 읽기 권한을 확인해야 합니다.');
$assert(RestController::authorize($request, 'admin') instanceof WP_Error, '교사 권한만으로 관리자 작업 중단을 허용하면 안 됩니다.');
$GLOBALS['primm_ui_caps'][]='manage_options';$assert(RestController::authorize($request, 'admin')===true,'관리자 중단은 관리자 권한을 확인해야 합니다.');
$GLOBALS['primm_ui_actor'] = 0;
$assert(RestController::authorize($request, 'reader') instanceof WP_Error, '로그아웃 시 권한을 거부해야 합니다.');
$GLOBALS['primm_ui_actor'] = 19;
$pageRequest = new ReflectionClass(AddonPageRequestV1::class);
$page = (new PageHandler('student'))->handle($pageRequest->newInstanceWithoutConstructor());
$document = new DOMDocument(); @$document->loadHTML('<?xml encoding="UTF-8">' . $page->body());
$xpath = new DOMXPath($document); $config = json_decode($xpath->evaluate('string(//*[@data-oj-primm]/@data-config)'), true, 8, JSON_THROW_ON_ERROR);
$assert($page->layout() === 'wide' && $page->supportsDarkMode(), '플랫폼 공통 shell과 토큰을 사용해야 합니다.');
$assert($config['userId'] === 19 && $config['nonce'] === 'test-nonce' && $config['mode'] === 'student', '서버의 현재 사용자와 REST nonce를 전달해야 합니다.');
$assert($xpath->query('//script')->length === 0, '페이지 HTML에 인라인 실행 코드를 넣으면 안 됩니다.');
$csvResponse=new WP_REST_Response("student,status\n학습자,not_started\n");$csvResponse->header('Content-Type','text/csv; charset=UTF-8');
ob_start();$served=RestController::serveCsv(false,$csvResponse,new WP_REST_Request('test-nonce',[],['_route'=>'/oj-primm/v1/teacher/report.csv']),null);$csvOutput=ob_get_clean();
$assert($served&&$csvOutput===$csvResponse->get_data(),'CSV 보고서는 JSON 문자열로 감싸지 않고 인증된 응답을 그대로 내려보내야 합니다.');
$script = file_get_contents($pluginRoot . '/resources/js/primm.js'); $css = file_get_contents($pluginRoot . '/resources/css/primm.css');
$assert(str_contains($script, '/support/assemble') && str_contains($script, 'template_hash') && str_contains($script, 'support_state'), '실제 도움 위젯·버전·초안 복원을 연결해야 합니다.');
$assert(str_contains($script, 'expected_version') && str_contains($script, 'beforeunload'), '동시 수정 충돌과 저장 전 이탈을 처리해야 합니다.');
$assert(str_contains($script, '/teacher/generations') && str_contains($script, 'request_key') && str_contains($script, 'generationStorageKey'), '생성 작업·중복 요청 방지·재접속 복원을 연결해야 합니다.');
$assert(str_contains($script, 'generationPhase') && str_contains($script, 'repair_attempt') && str_contains($script, 'max_repairs'), 'OJ 검증·자동 수정·재검증 상태와 수정 한도를 표시해야 합니다.');
$assert(str_contains($script, "verification.status === 'verified' && verification.engine === 'oj'") && str_contains($script, '실제 실행으로 검증되지 않았습니다.'), 'OJ 검증 증거를 확인하고 이전 미검증 활동 표시를 유지해야 합니다.');
$assert(str_contains($script, "var generated = view.activity.generation_kind === 'ai';")
    && ! str_contains($script, 'var generated = Boolean('), '실행 검증 정보가 있는 기본 예제를 AI 생성 자료로 추론해서는 안 됩니다.');
$assert(str_contains($script, "view.activity.generation_kind === 'template'")
    && str_contains($script, '기본 예제를 바탕으로 준비한 도입 자료입니다.')
    && str_contains($script, '목표 문제의 개념을 연습하는 AI 생성 도입 예제입니다.'), '부분 AI 수정이 가능한 기본 예제 출처와 전체 AI 생성 출처를 구분해 안내해야 합니다.');
$assert(str_contains($script, '기본 예제의 예상 출력입니다. 실제 실행으로 검증되지 않았습니다.')
    && str_contains($script, '아래 예상 출력은 기본 예제의 예상 결과이며 실제 실행으로 검증되지 않았습니다.')
    && str_contains($script, 'OJ 자동 실행 검증 전인 기본 예제 기반 수업 자료입니다.'), '기본 예제도 OJ 검증 전에는 AI 초안이나 검증된 출력으로 표시해서는 안 됩니다.');
$assert(str_contains($script, "preparedLesson ? '준비된 도움 자료로 코드를 수정해 보세요.")
    && str_contains($script, "preparedLesson ? '예상 출력 확인'"), '템플릿 기반 수정 도움과 예상 출력 확인은 기존 별도 도움 문제·검증 완료 결과와 구분해야 합니다.');
$assert(str_contains($script,'_conflicts')&&str_contains($script,'서버 기록과 다른 버전')&&str_contains($script,'이 초안으로 이어쓰기'),'다른 버전의 초안도 보관하고 명시적으로 비교·복구해야 합니다.');
$assert(str_contains($script,'review_only')&&str_contains($script,'?stage=')&&str_contains($script,'focusTarget.focus()'),'완료 단계 다시 읽기와 단계 전환 포커스를 제공해야 합니다.');
$assert(str_contains($script,"'/practice'")&&str_contains($script,'practiceSource')&&str_contains($script,'모든 수정 사례를 통과했습니다.'),'학생 수정 코드의 실제 실행과 현재 코드 일치를 확인해야 합니다.');
$assert(str_contains($script, '정답 제출 미확인') && str_contains($script, '과정 진행과 공식 채점 결과는 별개'), '자가 과정 완료를 공식 정답으로 표시하면 안 됩니다.');
$assert(! str_contains($script, "'submission_id'") && str_contains($script, '/submission-launch') && str_contains($script, '/submission-check'), '학생이 제출 번호를 입력하지 않고 초안 전달·본인 제출 확인 API를 사용해야 합니다.');
$assert(str_contains($script, 'submission_launch') && str_contains($script, 'expires_at') && str_contains($script, 'lockPreparation') && str_contains($script, 'checkFallback'), '저장한 초안 전달을 준비하는 동안 편집을 보호하고 만료·변경된 대체 링크를 폐기해야 합니다.');
$assert(str_contains($script, "window.open('about:blank'") && str_contains($script, 'popup.opener = null') && str_contains($script, "'linked' : 'watch'"), '사용자 클릭에서 안전한 새 창을 준비하고 연결된 제출은 고정 조회해야 합니다.');
$assert(str_contains($script, '현재 초안과 다른 코드의 제출 결과') && str_contains($script, '제출 원문을 확인할 수 없어'), '다른 코드와 원문 비교 불가 상태를 구분해야 합니다.');
$assert(str_contains($script, 'if (event.persisted) { suspended = true; clearPoll(); }') && str_contains($script, "addEventListener('pageshow', pageShow)"), '같은 탭 제출 후 BFCache로 돌아오면 컨트롤러를 유지하고 제출 감시를 재개해야 합니다.');
$assert(!str_contains($css, ':root') && !str_contains($css, '!important') && preg_match('/(^|\n)(?:html|body|button|input)\s*[,{]/', $css) !== 1, '애드온 스타일을 루트 아래로 제한해야 합니다.');
foreach ([$script, $css, file_get_contents(__FILE__)] as $text) { $assert(!str_starts_with($text, "\xEF\xBB\xBF") && preg_match('//u', $text) === 1, 'UI 텍스트를 UTF-8 BOM 없이 저장해야 합니다.'); }
echo 'PRIMM UI boundary checks passed: ' . $checks . "\n";
