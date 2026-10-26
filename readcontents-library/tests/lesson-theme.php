<?php
declare(strict_types=1);

// Uses production response, document renderer and Plate renderer without a WordPress DB.
$workspace = dirname(__DIR__, 2);
$legacy = in_array('--legacy', $argv, true);
if ($legacy) {
    final class LegacyLessonPageResponse
    {
        public function __construct(public string $title, public string $body, public int $status) {}
        public static function html(string $title, string $body, int $status = 200): self
        {
            return new self($title, $body, $status);
        }
    }
    class_alias(LegacyLessonPageResponse::class, 'CodingPanda\\OjPlatform\\AddonApi\\V1\\AddonPageResponseV1');
}
spl_autoload_register(static function (string $class) use ($workspace): void {
    $prefixes = [
        'CodingPanda\\OjPlatform\\' => $workspace . '/oj-integration-rebuild/oj-platform/src/',
        'ReadContents\\Library\\' => dirname(__DIR__) . '/src/ReadContents/Library/',
    ];
    foreach ($prefixes as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $file = $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) require $file;
        }
    }
});
function __($value, ...$unused) { return $value; }
function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function esc_html__($value, ...$unused) { return esc_attr($value); }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function lessonThemeCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

use ReadContents\Library\Addon\PageResponse;
use ReadContents\Library\Editor\PublicSite\PlateHtmlRenderer;
use CodingPanda\OjPlatform\Ui\Asset\UiAssetLocator;
use CodingPanda\OjPlatform\Ui\Component\ColorSchemeChoice;
use CodingPanda\OjPlatform\Ui\View\HtmlDocumentRenderer;

if ($legacy) {
    $lesson = PageResponse::lesson('이전 플랫폼', '<p>본문 보존</p>');
    $missing = PageResponse::reader('없음', '<p>안내</p>', 404);
    $editor = PageResponse::immersive('편집', '<p>편집기</p>');
    lessonThemeCheck($lesson->title === '이전 플랫폼' && $lesson->body === '<p>본문 보존</p>' && $lesson->status === 200, 'Legacy lesson response must remain usable');
    lessonThemeCheck($missing->status === 404 && $editor->body === '<p>편집기</p>', 'Legacy fallback must preserve status and body');
    echo "PASS: legacy Addon API html() fallback.\n";
    exit;
}

if (!in_array('--fixture', $argv, true)) {
    $lesson = PageResponse::lesson('학습 자료', '<p>본문 보존</p>');
    lessonThemeCheck($lesson->supportsDarkMode(), 'Lesson response must allow the platform screen mode');
    lessonThemeCheck($lesson->title() === '학습 자료' && $lesson->body() === '<p>본문 보존</p>' && $lesson->status() === 200 && $lesson->layout() === 'wide', 'Lesson response must preserve content and use the wide reader layout');
    lessonThemeCheck(PageResponse::reader('없음', '<p>안내</p>', 404)->status() === 404, 'Not-found status must remain 404');
    lessonThemeCheck(PageResponse::reader('목록', '<p>목록</p>')->supportsDarkMode(), 'Library screen mode must remain enabled');
    lessonThemeCheck(PageResponse::immersive('편집', '<p>편집</p>')->layout() === 'immersive', 'Editor layout must remain immersive');
    echo "PASS: lesson screen-mode opt-in, content/status/layout preservation.\n";
    exit;
}

$text = static fn (string $value): array => ['text' => $value];
$paragraph = static fn (string $value): array => ['type' => 'p', 'children' => [$text($value)]];
$nodes = [
    ['type' => 'h1', 'children' => [$text('학습 유형과 문제 해결')]],
    $paragraph('데이터의 특징을 살펴보고 문제에 적합한 학습 유형을 선택합니다.'),
    ['type' => 'h2', 'children' => [$text('자료 비교')]],
    ['type' => 'p', 'children' => [$text('인라인 코드: '), ['text' => 'model.predict(data)', 'code' => true], $text(' · '), ['text' => '강조 표시', 'highlight' => true], $text(' · '), ['text' => 'Ctrl', 'kbd' => true]]],
    ['type' => 'p', 'children' => [['text' => '저자 지정 색상', 'color' => '#8b0000', 'backgroundColor' => '#fff2cc']]],
    ['type' => 'blockquote', 'children' => [$paragraph('문제의 목적과 데이터 구조를 함께 고려합니다.')]],
    ['type' => 'table', 'children' => [
        ['type' => 'tr', 'children' => [['type' => 'th', 'children' => [$paragraph('학습 유형')]], ['type' => 'th', 'children' => [$paragraph('목적')]]]],
        ['type' => 'tr', 'children' => [['type' => 'td', 'children' => [$paragraph('분류')]], ['type' => 'td', 'children' => [$paragraph('범주 구분')]]]],
        ['type' => 'tr', 'children' => [['type' => 'td', 'backgroundColor' => '#fff2cc', 'children' => [['type' => 'p', 'children' => [['text' => '저자 지정 표 색상', 'color' => '#123456']]]]], ['type' => 'td', 'children' => [$paragraph('조건 비교')]]]],
    ]],
    ['type' => 'callout', 'children' => [$paragraph('알아두기: 평가 데이터는 학습에 사용하지 않습니다.')]],
    ['type' => 'callout', 'backgroundColor' => '#fff2cc', 'children' => [['type' => 'p', 'children' => [['text' => '저자 지정 알림 색상', 'color' => '#123456']]]]],
    ['type' => 'toggle', 'children' => [$text('데이터 준비에 관한 보충 설명')]],
    ['type' => 'p', 'children' => [['type' => 'date', 'date' => '2026-10-21', 'children' => [$text('')]], $text(' · '), ['type' => 'mention', 'value' => '학습 자료', 'children' => [$text('')]]]],
    ['type' => 'code_block', 'lang' => 'python', 'children' => [['type' => 'code_line', 'children' => [$text('accuracy = correct / total')]]]],
    ['type' => 'img', 'url' => 'https://lesson-theme.test/sample.svg', 'alt' => '색상 보존 검사용 그림', 'caption' => '실습 화면 캡션', 'width' => 240, 'children' => [$text('')]],
    ['type' => 'file', 'url' => 'https://lesson-theme.test/sample.csv', 'name' => '연습 데이터 CSV', 'children' => [$text('')]],
    ['type' => 'quiz', 'data' => ['question' => '범주를 구분하는 학습 유형은?', 'choices' => [['id' => 'classification', 'text' => '분류'], ['id' => 'regression', 'text' => '수치 예측']], 'correctChoiceId' => 'classification', 'explanation' => '분류는 입력 데이터를 범주로 구분합니다.'], 'children' => [$text('')]],
    ['type' => 'visualization', 'data' => ['visualId' => 'theme-network', 'schemaVersion' => '1.0.0', 'visualType' => 'network', 'title' => '문제 해결 과정', 'source' => ['format' => 'algoviz-dsl', 'code' => "visual network\ntitle \"문제 해결 과정\"\nnode A \"데이터\" circle at 100 90\nnode B \"모델\" circle at 300 90\nedge A B label \"학습\" straight\nstep first 0 \"데이터 준비\"\nstep-note first \"학습에 사용할 데이터를 준비합니다.\"\nstep second 1 \"모델 학습\"\nstep-note second \"준비한 데이터로 모델을 학습합니다.\""]], 'children' => [$text('')]],
];
$body = '<article class="rcis-reading rcis-story" data-contents-editor-runtime="public">'
    . '<nav class="ojp-breadcrumb rcis-breadcrumb" aria-label="읽기자료 경로"><a href="#">읽기자료</a></nav>'
    . (new PlateHtmlRenderer())->render($nodes)
    . '<div class="contents-editor-content-surface contents-editor-content-surface--public contents-editor-plate-content"><form aria-label="입력 부품 검증"><label>학습 메모 <input aria-label="학습 메모" value="검토할 데이터"></label><label>유형 <select aria-label="학습 유형"><option>분류</option><option>예측</option></select></label><textarea aria-label="분석 내용">데이터의 구조를 살펴봅니다.</textarea></form></div>'
    . '<nav class="rcis-section-siblings" aria-label="함께 읽을 레슨"><h2 class="rcis-section-siblings__title">다음 자료</h2><ol class="rcis-section-siblings__list"><li><a href="#">모델 평가</a></li></ol></nav></article>';
$response = PageResponse::lesson('레슨 화면 모드 검증', $body);
$locator = new class implements UiAssetLocator {
    public function stylesheetUrl(): string { return '/platform/resources/ui/css/oj-ui-system.css'; }
    public function scriptUrl(): string { return '/platform/resources/ui/js/oj-ui.js'; }
    public function mathJaxLoaderUrl(): string { return '/noop.js'; }
    public function iconSpriteUrl(): string { return '/empty.svg'; }
    public function faviconUrl(): string { return '/empty.svg'; }
};
$fixture = '<link rel="stylesheet" href="/plugin/assets/readcontents-library.css">'
    . '<link rel="stylesheet" href="/plugin/assets/editor/public.css">'
    . '<link rel="stylesheet" href="/plugin/assets/reader-theme.css">'
    . '<style>.theme-fixture{max-width:1000px;margin:auto;padding:20px;min-width:0}.theme-fixture>header{margin-bottom:20px}</style>'
    . '<main class="theme-fixture"><header>' . ColorSchemeChoice::render() . '</header>'
    . ($response->supportsDarkMode() ? '' : '<span hidden data-ojp-light-only></span>')
    . $response->body() . '</main><script type="module" src="/plugin/assets/editor/public.js"></script>';
echo (new HtmlDocumentRenderer($locator, '읽기자료 검증'))->render('레슨 화면 모드', $fixture, 'ojp-app');
