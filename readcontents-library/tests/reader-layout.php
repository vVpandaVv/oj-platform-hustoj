<?php
declare(strict_types=1);

// The repository/handler/renderers are production classes. Only WordPress I/O
// is replaced with in-memory posts and metadata; no site or database is used.
$workspace = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($workspace): void {
    foreach ([
        'CodingPanda\\OjPlatform\\' => $workspace . '/oj-integration-rebuild/oj-platform/src/',
        'ReadContents\\Library\\' => dirname(__DIR__) . '/src/ReadContents/Library/',
    ] as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $file = $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) require $file;
        }
    }
});
$readerPosts = $readerMeta = [];
$readerSequence = 0;
$readerCanEdit = false;
#[AllowDynamicProperties]
class WP_Post { public function __construct(array $values) { foreach ($values as $key => $value) $this->$key = $value; } }
class WP_Query { public array $posts; public function __construct(array $args) { $this->posts = get_posts($args); } }
function __($text, ...$unused) { return $text; }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function esc_html($text) { return esc_attr($text); }
function esc_html__($text, ...$unused) { return esc_html($text); }
function esc_url($url) { return esc_attr($url); }
function home_url($path = '') { return 'https://reader-layout.test/school' . '/' . ltrim((string) $path, '/'); }
function add_query_arg($key, $value, $url) { return $url . (str_contains($url, '?') ? '&' : '?') . rawurlencode($key) . '=' . rawurlencode($value); }
function sanitize_title($text) { return strtolower(trim(strip_tags((string) $text))); }
function sanitize_text_field($text) { return trim(strip_tags((string) $text)); }
function absint($value) { return abs((int) $value); }
function user_can($user, $capability) { return $GLOBALS['readerCanEdit']; }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function wp_slash($value) { return is_array($value) ? array_map('wp_slash', $value) : (is_string($value) ? addslashes($value) : $value); }
function wp_unslash($value) { return is_array($value) ? array_map('wp_unslash', $value) : (is_string($value) ? stripslashes($value) : $value); }
function get_post($id) { return $GLOBALS['readerPosts'][(int) $id] ?? null; }
function get_the_title($post) { return $post->post_title; }
function get_post_meta($id, $key, ...$unused) { return $GLOBALS['readerMeta'][(int) $id][$key] ?? ''; }
function update_post_meta($id, $key, $value) { $GLOBALS['readerMeta'][(int) $id][$key] = wp_unslash($value); return true; }
function is_wp_error($value) { return false; }
function wp_insert_post($values, ...$unused) {
    $id = ++$GLOBALS['readerSequence'];
    $GLOBALS['readerPosts'][$id] = new WP_Post(['ID' => $id] + wp_unslash($values));
    return $id;
}
function get_posts($args) {
    $posts = array_filter($GLOBALS['readerPosts'], static function ($post) use ($args) {
        foreach (['post_type', 'post_parent'] as $key) if (isset($args[$key]) && $post->$key !== $args[$key]) return false;
        if (isset($args['name']) && $post->post_name !== $args['name']) return false;
        if (isset($args['post_status']) && !in_array($post->post_status, (array) $args['post_status'], true)) return false;
        foreach ($args['meta_query'] ?? [] as $filter) {
            if (!is_array($filter)) continue;
            $equal = get_post_meta($post->ID, $filter['key']) === $filter['value'];
            if (($filter['compare'] ?? '=') === '!=' ? $equal : !$equal) return false;
        }
        return true;
    });
    usort($posts, static fn ($a, $b) => ($a->menu_order <=> $b->menu_order) ?: ($a->ID <=> $b->ID));
    return isset($args['posts_per_page']) && $args['posts_per_page'] > -1 ? array_slice($posts, 0, $args['posts_per_page']) : $posts;
}
function readerCheck(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function readerDom(string $html): DOMXPath {
    $previous = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors(); libxml_use_internal_errors($previous);
    return new DOMXPath($dom);
}
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\Ui\Asset\UiAssetLocator;
use CodingPanda\OjPlatform\Ui\Component\ColorSchemeChoice;
use CodingPanda\OjPlatform\Ui\View\HtmlDocumentRenderer;
use ReadContents\Library\Addon\TopicPageHandler;
use ReadContents\Library\Editor\PublicSite\LessonOutlineRenderer;
use ReadContents\Library\Editor\Storage\ContentRepository;

define('READCONTENTS_LIBRARY_VERSION', '3.3.10');
define('READCONTENTS_LIBRARY_URL', 'https://reader-layout.test/plugin/');
define('READCONTENTS_LIBRARY_FILE', dirname(__DIR__) . '/contents-editor.php');
$repo = new ContentRepository();
$create = static fn (string $type, string $title, string $slug, ?string $parent = null, string $status = 'published', int $order = 0) => $repo->createNode(['type' => $type, 'title' => $title, 'slug' => $slug, 'parentId' => $parent, 'status' => $status, 'order' => $order]);
$topic = $create('topic', '인공지능과 미래 사회', 'ai-topic');
$lessons = []; $chapters = []; $sections = [];
for ($chapterIndex = 1; $chapterIndex <= 2; $chapterIndex++) {
    $chapter = $create('chapter', $chapterIndex === 1 ? '학습 유형과 데이터' : '문제 해결과 모델 평가', 'chapter-' . $chapterIndex, $topic->id, 'published', $chapterIndex);
    $section = $create('section', $chapterIndex === 1 ? '문제와 학습 방식' : '평가 결과 해석', 'section-' . $chapterIndex, $chapter->id);
    $chapters[] = $chapter; $sections[] = $section;
    for ($index = 1; $index <= 12; $index++) {
        $number = ($chapterIndex - 1) * 12 + $index;
        $name = '레슨 ' . str_pad((string) $number, 2, '0', STR_PAD_LEFT);
        $lesson = $create('lesson', $name . ($number === 3 ? ' 긴 제목의 학습 자료와 적용 사례를 여러 조건으로 비교하는 방법' : ' 학습 자료'), 'lesson-' . $number, $section->id, 'published', $index);
        $lessons[$number] = $lesson;
        $paragraphs = [['type' => 'h1', 'children' => [['text' => $name . ' 학습 유형과 문제 해결']]], ['type' => 'p', 'children' => [['text' => '문제의 목적과 데이터 구조를 살펴보고 적합한 학습 방식을 선택합니다.']]], ['type' => 'quiz', 'data' => ['question' => '범주를 구분하는 학습 유형은?', 'choices' => [['id' => 'classification', 'text' => '분류'], ['id' => 'regression', 'text' => '수치 예측']], 'correctChoiceId' => 'classification', 'explanation' => '분류는 입력 자료를 범주로 구분합니다.'], 'children' => [['text' => '']]]];
        for ($row = 1; $row <= 70; $row++) $paragraphs[] = ['type' => 'p', 'children' => [['text' => $row . '. 데이터의 특징, 학습 목표, 모델의 결과를 함께 살펴봅니다. 목차와 본문의 너비가 달라져도 읽던 내용과 입력 상태는 유지되어야 합니다.']]];
        $repo->saveLessonDocument($lesson->id, ['revision' => 1, 'plateValue' => $paragraphs]);
    }
}
$draftLesson = $create('lesson', 'SECRET_DRAFT_LESSON', 'draft-lesson', $sections[0]->id, 'draft');
$draftSection = $create('section', 'SECRET_DRAFT_SECTION', 'draft-section', $chapters[0]->id, 'draft');
$draftSectionChild = $create('lesson', 'SECRET_DRAFT_SECTION_CHILD', 'draft-section-child', $draftSection->id);
$draftChapter = $create('chapter', 'SECRET_DRAFT_CHAPTER', 'draft-chapter', $topic->id, 'draft');
$draftChapterSection = $create('section', 'SECRET_DRAFT_CHAPTER_SECTION', 'draft-chapter-section', $draftChapter->id);
$create('lesson', 'SECRET_DRAFT_CHAPTER_CHILD', 'draft-chapter-child', $draftChapterSection->id);
$emptyChapter = $create('chapter', 'SECRET_EMPTY_CHAPTER', 'empty-chapter', $topic->id);
$emptySection = $create('section', 'SECRET_EMPTY_SECTION', 'empty-section', $emptyChapter->id);
$create('lesson', 'SECRET_EMPTY_SECTION_DRAFT', 'empty-section-draft', $emptySection->id, 'draft');
$draftTopic = $create('topic', 'SECRET_DRAFT_TOPIC', 'draft-topic', null, 'draft');
$draftTopicChapter = $create('chapter', 'SECRET_DRAFT_TOPIC_CHAPTER', 'draft-topic-chapter', $draftTopic->id);
$draftTopicSection = $create('section', 'SECRET_DRAFT_TOPIC_SECTION', 'draft-topic-section', $draftTopicChapter->id);
$create('lesson', 'SECRET_DRAFT_TOPIC_CHILD', 'draft-topic-child', $draftTopicSection->id);
$request = static fn (string $slug) => new AddonPageRequestV1('addon.readcontents_library.topic', 7, ['topic_id' => $slug], [], 'ko_KR', 'reader-layout-test');
$tree = $repo->buildTopicTree($topic->id);
$renderer = new LessonOutlineRenderer();
$fixture = in_array('--fixture', $argv, true);
if (!$fixture) {
    $outline = $renderer->render($tree, $lessons[13]->id);
    $dom = readerDom($outline);
    readerCheck(!str_contains($outline, 'SECRET_'), 'Outline must filter draft and empty ancestors and lessons');
    readerCheck($dom->query('//a[contains(@class,"rcis-reader__lesson")]')->length === 24, 'Whole topic must include published lessons in every chapter');
    readerCheck($dom->query('//*[@aria-current="page"]')->length === 1, 'Current lesson must be marked once');
    readerCheck(str_contains($dom->query('//*[@aria-current="page"]')->item(0)->textContent, '레슨 13'), 'Current lesson marker must identify the requested lesson');
    readerCheck($dom->query('//details[@open]')->length === 1 && $dom->query('//details[@open]//*[@aria-current="page"]')->length === 1, 'The chapter containing the current lesson must open');
    foreach ($dom->query('//a[@href]') as $link) readerCheck(str_starts_with($link->getAttribute('href'), 'https://reader-layout.test/school/oj-addons/readcontents-library/'), 'Links must preserve the site subdirectory');
    readerCheck(!str_contains($renderer->render($repo->buildTopicTree($draftTopic->id), ''), 'SECRET_'), 'Draft topic titles and children must not leak');
    $unsafe = $tree;
    $publishedChapterIndex = array_search($chapters[0]->id, array_map(static fn ($group) => $group['chapter']['id'], $tree['chapters']), true);
    $publishedSectionIndex = array_search($sections[0]->id, array_map(static fn ($group) => $group['section']['id'], $tree['chapters'][$publishedChapterIndex]['sections']), true);
    foreach ([['topic'], ['chapters', $publishedChapterIndex, 'chapter'], ['chapters', $publishedChapterIndex, 'sections', $publishedSectionIndex, 'section'], ['chapters', $publishedChapterIndex, 'sections', $publishedSectionIndex, 'lessons', 1]] as $keys) {
        $target =& $unsafe;
        foreach ($keys as $key) $target =& $target[$key];
        $target['title'] = '표시 <img src=x onerror="alert(1)"> & "문자"';
        unset($target);
    }
    $escaped = $renderer->render($unsafe, $lessons[1]->id);
    readerCheck(readerDom($escaped)->query('//img|//script|//*[@onerror]')->length === 0 && substr_count($escaped, '&lt;img') === 4, 'Every hierarchy title must be escaped (escaped titles: ' . substr_count($escaped, '&lt;img') . ')');
    $page = (new TopicPageHandler())->handle($request('lesson-1'));
    $pageDom = readerDom((string) $page->body());
    readerCheck($page->status() === 200 && $page->layout() === 'wide' && $page->supportsDarkMode(), 'Public lesson must use the wide, theme-aware response');
    readerCheck($pageDom->query('//*[@data-rcis-reader]')->length === 1 && $pageDom->query('//article[contains(@class,"rcis-reader__content")]')->length === 1, 'Handler must wrap the existing article exactly once');
    readerCheck($pageDom->query('//*[@data-rcis-reader-outline]//a[contains(@href,"/lesson-13")]')->length === 1, 'Handler outline must include other chapters');
    readerCheck($pageDom->query('//*[@data-rcis-reader-outline]//*[@aria-current="page"]')->length === 1, 'Handler must pass the current lesson ID to the outline');
    readerCheck($pageDom->query('//button[@data-rcis-outline-toggle and @aria-controls="rcis-reader-outline"]')->length === 1, 'Toggle must control the real outline');
    readerCheck($pageDom->query('//script[contains(@src,"reader-layout.js")]')->length === 1, 'Handler must load reader interactions');
    foreach (['missing-lesson', 'draft-lesson', 'draft-section-child', 'draft-topic-child'] as $slug) {
        $missing = (new TopicPageHandler())->handle($request($slug));
        readerCheck($missing->status() === 404 && !str_contains((string) $missing->body(), 'data-rcis-reader'), 'Student cannot view missing/draft/private-ancestor lesson ' . $slug);
    }
    $readerCanEdit = true;
    $preview = (new TopicPageHandler())->handle($request('draft-lesson'));
    $previewDom = readerDom((string) $preview->body());
    readerCheck($preview->status() === 200 && str_contains((string) $preview->body(), '초안 미리보기'), 'Editor draft preview must remain available');
    readerCheck(!str_contains($previewDom->query('//*[@data-rcis-reader-outline]')->item(0)->textContent, 'SECRET_'), 'Preview outline must still filter unpublished entries');
    echo "PASS: public outline filtering/current chapter/escaping/nested URLs; real repository and lesson handler integration; draft access and preview.\n";
    exit;
}

$slug = 'lesson-1';
foreach ($argv as $argument) if (str_starts_with($argument, '--lesson=')) $slug = substr($argument, strlen('--lesson='));
$response = (new TopicPageHandler())->handle($request($slug));
readerCheck($response->status() === 200, 'Fixture lesson must exist');
$locator = new class implements UiAssetLocator {
    public function stylesheetUrl(): string { return '/platform/resources/ui/css/oj-ui-system.css'; }
    public function scriptUrl(): string { return '/platform/resources/ui/js/oj-ui.js'; }
    public function mathJaxLoaderUrl(): string { return '/noop.js'; }
    public function iconSpriteUrl(): string { return '/empty.svg'; }
    public function faviconUrl(): string { return '/empty.svg'; }
};
$styles = '';
foreach (['readcontents-library.css', 'editor/public.css', 'reader-theme.css', 'reader-layout.css'] as $file) $styles .= '<link rel="stylesheet" href="/plugin/assets/' . $file . '">';
$markers = ($response->supportsDarkMode() ? '' : '<span hidden data-ojp-light-only></span>') . '<span hidden data-ojp-layout="' . esc_attr($response->layout()) . '"></span>';
$body = $styles . '<main class="ojp-shell" style="margin:auto;padding:16px"><header style="margin-bottom:20px">' . ColorSchemeChoice::render() . '</header>' . $markers . $response->body() . '</main>';
echo (new HtmlDocumentRenderer($locator, '학생 읽기자료'))->render('공개 레슨 목차', $body, 'ojp-app');
