<?php
declare(strict_types=1);
define('READCONTENTS_IMPORT_HELPERS_ONLY', true);
require dirname(__DIR__,2).'/readcontents-content/network-curriculum/verify-import-v3.php';
require __DIR__.'/reader-wordpress.php';
spl_autoload_register(static function(string $class): void {
    if (str_starts_with($class,'ReadContents\\Library\\')) require dirname(__DIR__).'/src/'.str_replace('\\','/',$class).'.php';
});
use ReadContents\Library\Editor\ImportExport\ContentPackageService;
$node = static fn(string $id,string $type,?string $parent): array => [
    'id'=>$id,'slug'=>'numeric-'.$id,'title'=>'기존 '.$id,'type'=>$type,'parentId'=>$parent,
    'order'=>1,'status'=>'draft','contentType'=>'default','schemaVersion'=>'1.0.0','metadata'=>[],
];
$old = ['kind'=>'contents-editor-package','packageVersion'=>'1.0.0','scope'=>'topic',
    'tree'=>['topic'=>$node('1','topic',null),'chapters'=>[
        ['chapter'=>$node('2','chapter','1'),'sections'=>[
            ['section'=>$node('3','section','2'),'lessons'=>[$node('4','lesson','3')]],
        ]],
    ]], 'lessonDocuments'=>['4'=>['lessonId'=>'4','schemaVersion'=>'1.0.0','revision'=>1,
        'plateValue'=>[['type'=>'p','children'=>[['text'=>'기존 본문']]],
            ['type'=>'img','url'=>'assets/example.png','children'=>[['text'=>'']]]],
        'assets'=>[['id'=>'image','kind'=>'image','url'=>'assets/example.png']], 'visuals'=>[],
    ]]];
$new = $old;
$new['tree']['chapters'][0]['chapter']['title'] = '개정 챕터';
$new['lessonDocuments']['4']['plateValue'][0]['children'][0]['text'] = '개정 본문';
$repo = sandboxRepository();
$service = new ContentPackageService($repo);
$seed = $service->importTopicPackage($old);
$plan = $service->serverPreview($new);
need(isset($plan['expected'][4]),'Numeric key fixture');
foreach ([false,true] as $titles) {
    $skip = $service->serverOptions(['lessonConflictPolicy'=>'skip','updateContainerTitles'=>$titles]);
    same([], $service->serverAssetPaths($new,$skip,$plan['expected']),'Skip does not import existing assets');
    $replace = $service->serverOptions(['lessonConflictPolicy'=>'replace','updateContainerTitles'=>$titles]);
    same(['assets/example.png'], $service->serverAssetPaths($new,$replace,$plan['expected']),'Replace includes asset');
}
$options = $service->serverOptions(['lessonConflictPolicy'=>'replace','updateContainerTitles'=>true]);
$result = $service->importServerUnit($service->serverUnits($new)[0],$options,[],$plan['expected']);
same(1,$result['replaced']['lessons'],'Numeric lesson replaced');
same(0,$result['created']['lessons'],'No duplicate numeric lesson');
same('개정 챕터',$repo->getNode($seed['idMap']['2'])->title,'Container title updated');
echo "PASS: numeric source IDs in server asset selection, skip/replace and container-title update.\n";
