<?php
declare(strict_types=1);

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use ContentsEditor\Infrastructure\Assets;
use ContentsEditor\PublicSite\LessonShortcode;
use ContentsEditor\PublicSite\TopicShortcode;
use ContentsEditor\Storage\ContentRepository as LegacyRepository;
use ReadContents\Library\Addon\TopicPageHandler;
use ReadContents\Library\Editor\PublicSite\OptionalReadingBlocks;
use ReadContents\Library\Editor\PublicSite\PlateHtmlRenderer;
use ReadContents\Library\Editor\Storage\ContentRepository;

require __DIR__ . '/reader-wordpress.php';
define('READCONTENTS_LIBRARY_VERSION', 'test');
define('READCONTENTS_LIBRARY_URL', home_url('/plugin/'));
define('CONTENTS_EDITOR_PATH', dirname(__DIR__) . '/');
define('CONTENTS_EDITOR_CAPABILITY', 'manage_options');

function readingTree($repo, string $prefix): array {
    $parent = null;
    $tree = [];
    foreach (['topic', 'chapter', 'section', 'lesson'] as $type) {
        $tree[$type] = $repo->createNode(['type' => $type, 'slug' => "$prefix-$type", 'title' => "$prefix $type", 'status' => 'published', 'parentId' => $parent]);
        $parent = $tree[$type]->id;
    }
    return $tree;
}
function readingGroup(string $code, string $slug, string $question): array {
    return [
        ['type' => 'h2', 'children' => [['text' => '선택 심화 읽기']]],
        ['type' => 'p', 'children' => [['type' => 'a', 'url' => '/oj-addons/readcontents-library/' . $slug, 'target' => '_blank', 'children' => [['text' => $question]]]]],
        ['type' => 'p', 'children' => [['text' => $code . ' 단원명 · 새 탭에서 열립니다.']]],
    ];
}

$repo = new ContentRepository();
$source = readingTree($repo, 'source')['lesson'];
$dest = readingTree($repo, 'destination');
$slug = 'network-data-communications-advanced-d01';
$otherSlug = 'network-tcp-ip-analysis-t12';
$group = readingGroup('D01', $slug, '질문 하나');
$other = readingGroup('T12', $otherSlug, '질문 둘');
$normalized = $group;
array_unshift($normalized[1]['children'], ['text' => '']);
$normalized[1]['children'][] = ['text' => ''];

// Similar-looking material that is not a linked-edition group must survive unchanged.
$keep = [['type' => 'toc', 'children' => [['text' => '']]]];
foreach (['https://external.test/oj-addons/readcontents-library/' . $slug, '/oj-addons/oj-network-lab/'] as $url) {
    $ordinary = $group;
    $ordinary[1]['children'][0]['url'] = $url;
    array_push($keep, ...$ordinary);
}
$extra = $group;
$extra[1]['children'][0]['url'] = 'https://external.test/reference';
$extra[1]['children'][] = ['text' => '이어지는 본문'];
$keep = array_merge($keep, $extra, [$group[0], ['type' => 'p', 'children' => [['text' => '링크가 없는 질문']]], ['type' => 'p', 'children' => [['text' => '일반 단원명']]]]);
$original = array_merge($group, $keep, $other, $normalized);
$visible = array_merge($group, $keep, $normalized);
$repo->saveLessonDocument($source->id, ['revision' => 1, 'plateValue' => $original]);
$request = static fn ($slug) => new AddonPageRequestV1('addon.readcontents_library.topic', 7, ['topic_id' => $slug], [], 'ko_KR', 'optional-reading-test');
$route = static fn ($slug) => (new TopicPageHandler())->handle($request($slug));
$check = static function (array $expected, string $why) use ($source, $route, $slug, $otherSlug): void {
    $GLOBALS['readerSlugQueries'] = [];
    $page = $route($source->slug);
    readerCheck($page->status() === 200 && str_contains((string) $page->body(), (new PlateHtmlRenderer())->render($expected)), $why . ': complete server-rendered body (including TOC) differs');
    foreach ([$slug, $otherSlug] as $target) readerCheck(($GLOBALS['readerSlugQueries'][$target] ?? 0) === 1, $why . ': expected one lookup per destination');
};
$check($keep, 'Unregistered destinations');
$wrong = $repo->createNode(['type' => 'topic', 'slug' => $slug, 'title' => 'Not a lesson', 'status' => 'published']);
$check($keep, 'Published non-lesson');
readerCheck($route($slug)->status() === 404, 'Non-lesson cannot open via destination route');
$repo->updateNode($wrong->id, ['slug' => 'not-a-lesson']);
$repo->updateNode($dest['lesson']->id, ['slug' => $slug]);
$check($visible, 'Newly published destination');
readerCheck($route($slug)->status() === 200, 'Published destination opens');
foreach ($dest as $type => $node) {
    foreach (['draft', 'review', 'approved', 'archived'] as $status) {
        $repo->updateNode($node->id, ['status' => $status]);
        $check($keep, "$type $status");
        readerCheck($route($slug)->status() === 404, "$type $status must also block destination route");
    }
    $repo->updateNode($node->id, ['status' => 'published']);
    $check($visible, "$type republished");
}
$post = get_post($dest['section']->id);
$parent = $post->post_parent;
foreach ([999999, (int) $dest['lesson']->id] as $badParent) {
    $post->post_parent = $badParent;
    $check($keep, 'Missing or cyclic ancestor');
}
$post->post_parent = $parent;
$repo->archiveNode($dest['lesson']->id);
$readerCanEdit = true;
readerCheck($route($slug)->status() === 200, 'Teacher preview is unaffected');
$check($keep, 'Teacher preview privilege is not student access');
readerCheck($repo->getLessonDocument($source->id)->plateValue === $original, 'Stored editor document must not change');
$readerCanEdit = false;

$legacy = new LegacyRepository();
$legacyTree = readingTree($legacy, 'shortcode');
$legacy->saveLessonDocument($legacyTree['lesson']->id, ['plateValue' => $original]);
$shortcodes = [
    [new LessonShortcode(new Assets()), $legacyTree['lesson']->id],
    [new TopicShortcode(new Assets()), $legacyTree['topic']->id],
];
$checkShortcodes = static function (array $expected, string $why) use ($shortcodes): void {
    $body = (new ContentsEditor\PublicSite\PlateHtmlRenderer())->render($expected);
    foreach ($shortcodes as [$shortcode, $id]) {
        $html = $shortcode->render(['id' => $id]);
        readerCheck(str_contains($html, $body), get_class($shortcode) . ': ' . $why);
    }
};
$checkShortcodes($keep, 'Archived add-on destination');
$repo->updateNode($dest['lesson']->id, ['status' => 'published']);
$checkShortcodes($visible, 'Published add-on destination');
foreach (['readerCanView', 'readerLoggedIn', 'readerPlatformActive'] as $gate) {
    $GLOBALS[$gate] = false;
    $checkShortcodes($keep, 'Missing route prerequisite: ' . $gate);
    $GLOBALS[$gate] = true;
}
readerCheck($legacy->getLessonDocument($legacyTree['lesson']->id)->plateValue === $original, 'Shortcode editor document must not change');
echo "PASS: actual lesson handler and both shortcodes; publication, ancestry, route access, atomic groups, unrelated content, lookup counts and unchanged originals.\n";

// Optional read-only compatibility check against released content, never a rebuild.
foreach (array_slice($argv, 1) as $file) {
    $zip = new ZipArchive();
    readerCheck($zip->open($file, ZipArchive::RDONLY) === true, "Cannot open $file");
    $manifest = json_decode($zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    $zip->close();
    $docs = $manifest['lessonDocuments'];
    readerCheck(count($docs) === 20, 'Expected 20 released lessons');
    foreach ($docs as $doc) {
        $blocks = $doc['plateValue'];
        readerCheck(OptionalReadingBlocks::forReader($blocks, $repo, false) === array_slice($blocks, 0, -3), 'Only the three released blocks should be removed: ' . $doc['lessonId']);
        $target = basename($blocks[count($blocks) - 2]['children'][0]['url']);
        if (! $repo->getNodeBySlug($target)) {
            $repo->createNode(['type' => 'lesson', 'slug' => $target, 'title' => $target, 'status' => 'published', 'parentId' => $dest['section']->id]);
        }
        readerCheck(OptionalReadingBlocks::forReader($blocks, $repo, true) === $blocks, 'Published destination must retain the entire released document: ' . $doc['lessonId']);
    }
    echo 'PASS: ' . basename($file) . " (20 original documents).\n";
}
