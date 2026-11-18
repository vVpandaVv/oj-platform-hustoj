<?php
declare(strict_types=1);

define('READCONTENTS_IMPORT_HELPERS_ONLY', true);
require dirname(__DIR__, 2).'/readcontents-content/network-curriculum/verify-import-v3.php';
require __DIR__.'/reader-wordpress.php';
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'ReadContents\\Library\\')) {
        require dirname(__DIR__).'/src/'.str_replace('\\', '/', $class).'.php';
    }
});

use ReadContents\Library\Editor\ImportExport\ContentPackageService;

$node = static fn(string $id, string $type, ?string $parent): array => [
    'id'=>$id, 'slug'=>$id, 'title'=>'기존 '.$id, 'type'=>$type, 'parentId'=>$parent,
    'order'=>3, 'status'=>'draft', 'contentType'=>'default', 'schemaVersion'=>'1.0.0', 'metadata'=>[],
];
$old = ['kind'=>'contents-editor-package', 'packageVersion'=>'1.0.0', 'scope'=>'topic',
    'tree'=>['topic'=>$node('topic', 'topic', null), 'chapters'=>[
        ['chapter'=>$node('chapter', 'chapter', 'topic'), 'sections'=>[
            ['section'=>$node('section', 'section', 'chapter'), 'lessons'=>[
                $node('lesson-a', 'lesson', 'section'), $node('lesson-b', 'lesson', 'section'),
            ]],
            ['section'=>$node('empty', 'section', 'chapter'), 'lessons'=>[]],
        ]],
    ]], 'lessonDocuments'=>[]];
$new = $old;
$new['tree']['topic']['title'] = '개정 주제';
$new['tree']['chapters'][0]['chapter']['title'] = 'OSI 7계층';
$new['tree']['chapters'][0]['sections'][0]['section']['title'] = '계층별 데이터 전달';
$new['tree']['chapters'][0]['sections'][1]['section']['title'] = '보충 자료';
$repo = sandboxRepository();
$service = new ContentPackageService($repo);
$seed = $service->importTopicPackage($old);
$saved = [];
foreach ($seed['idMap'] as $source=>$id) {
    $repo->updateNode($id, ['status'=>'published', 'order'=>27, 'metadata'=>['custom'=>'유지']]);
    $saved[$source] = $repo->getNode($id)->toArray();
}
same(4, count($service->previewTopicPackage($new)['containerTitles']), 'Title preview');
foreach ([false, 'false', 0, '0'] as $flag) {
    $service->importTopicPackage($new, ['lessonConflictPolicy'=>'skip', 'updateContainerTitles'=>$flag]);
    foreach ($saved as $source=>$record) same($record, $repo->getNode($seed['idMap'][$source])->toArray(), 'Opt-out preservation');
}
$plan = $service->serverPreview($new);
$opts = $service->serverOptions(['lessonConflictPolicy'=>'skip', 'updateContainerTitles'=>true]);
$changed = 0;
foreach ($service->serverUnits($new) as $unit) {
    $result = $service->importServerUnit($unit, $opts, [], $plan['expected']);
    $changed += $result['replaced']['topics']+$result['replaced']['chapters']+$result['replaced']['sections'];
}
same(4, $changed, 'Containers renamed once across multiple units including empty section');
foreach (treeNodes($new) as $source) {
    $record = $saved[$source['id']];
    if ($source['type'] !== 'lesson') $record['title'] = $source['title'];
    same($record, $repo->getNode($seed['idMap'][$source['id']])->toArray(), 'Only title changed');
}
$repeat = $service->importTopicPackage($new, $opts);
same(0, array_sum($repeat['replaced']), 'Idempotent title update');
$plan = $service->serverPreview($new);
$repo->updateNode($seed['idMap']['chapter'], ['title'=>'교사가 수정한 제목']);
try {
    $service->importServerUnit($service->serverUnits($new)[0], $opts, [], $plan['expected']);
    throw new RuntimeException('Stale title overwrite was accepted');
} catch (InvalidArgumentException $expected) {
    need(str_contains($expected->getMessage(), '목차 제목'), 'Stale title error');
}
$service->importServerUnit($service->serverUnits($new)[0], $service->serverOptions(['lessonConflictPolicy'=>'skip']), [], $plan['expected']);
same('교사가 수정한 제목', $repo->getNode($seed['idMap']['chapter'])->title, 'Opt-out does not overwrite concurrent edit');
try {
    $service->serverOptions(['updateContainerTitles'=>'yes']);
    throw new RuntimeException('Invalid title option accepted');
} catch (InvalidArgumentException $expected) {}
$repo->updateNode($seed['idMap']['chapter'], ['title'=>$new['tree']['chapters'][0]['chapter']['title']]);
$plan = $service->serverPreview($new);
$repo->updateNode($seed['idMap']['section'], ['slug'=>'moved-section', 'title'=>'다른 장']);
try {
    $service->importServerUnit($service->serverUnits($new)[0], $opts, [], $plan['expected']);
    throw new RuntimeException('Missing title target was accepted');
} catch (InvalidArgumentException $expected) {
    need(str_contains($expected->getMessage(), '목차가 이동'), 'Missing title target error');
}
echo "PASS: title preview, opt-in/out, repeated server units, empty sections, identity/order/status/metadata preservation, stale and missing targets, invalid options.\n";
