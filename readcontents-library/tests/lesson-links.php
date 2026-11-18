<?php
declare(strict_types=1);

use ReadContents\Library\Editor\PublicSite\OptionalReadingBlocks;

require __DIR__.'/optional-reading.php';

$tree=readingTree($repo,'ordinary');
$target=$tree['lesson']->slug;
$base='/oj-addons/readcontents-library/'.$target;
$a=static fn(string $url)=>['type'=>'a','url'=>$url,'target'=>'_blank','children'=>[['text'=>'연결 설명','bold'=>true]]];
$p=static fn(array $children)=>['type'=>'p','children'=>$children];
$internal=[$base, $base.'/?part=2#fault', home_url($base), '/school'.$base, '//reader-layout.test/school'.$base, 'https://reader-layout.test:443/school'.$base];
$unrelated=['https://external.test'.$base, 'https://reader-layout.test.evil/school'.$base, 'http://reader-layout.test/school'.$base, 'https://reader-layout.test:444/school'.$base, '/oj-addons/oj-network-lab/learn?exercise=l21', '/oj-addons/readcontents-library/topic/course', '#local-heading'];
$blocks=[];
foreach($internal as$url)$blocks[]=$p([['text'=>''],$a($url),['text'=>'']]);
$mixed=$p([['text'=>'앞 문장 '],$a($base),['text'=>' 뒤 문장']]);
$unlinked=$p([['text'=>'앞 문장 '],['text'=>'연결 설명','bold'=>true],['text'=>' 뒤 문장']]);
$blocks[]=$mixed;
$blocks[]=['type'=>'table','children'=>[['type'=>'tr','children'=>[['type'=>'td','children'=>[$mixed]]]]]];
$hidden=[$unlinked,['type'=>'table','children'=>[['type'=>'tr','children'=>[['type'=>'td','children'=>[$unlinked]]]]]]];
foreach($unrelated as$url){$node=$p([$a($url)]);$blocks[]=$node;$hidden[]=$node;}
$originalBlocks=$blocks;
$assertLinks=static function(array $expected,string $reason)use($repo,$blocks,$target):void{
    $GLOBALS['readerSlugQueries']=[];
    readerCheck(OptionalReadingBlocks::forReader($blocks,$repo,true)===$expected,$reason);
    readerCheck(($GLOBALS['readerSlugQueries'][$target]??0)===1,'One destination query: '.$reason);
};
$assertLinks($blocks,'All ordinary links to published lesson remain unchanged');
foreach($tree as$type=>$node){
    foreach(['draft','review','approved','archived']as$status){
        $repo->updateNode($node->id,['status'=>$status]);
        $assertLinks($hidden,'Hide ordinary links: '.$type.' '.$status);
    }
    $repo->updateNode($node->id,['status'=>'published']);
    $assertLinks($blocks,'Republication takes effect next request: '.$type);
}
$repo->updateNode($tree['lesson']->id,['slug'=>'moved-lesson']);
$assertLinks($hidden,'Missing ordinary lesson is hidden');
$repo->updateNode($tree['lesson']->id,['slug'=>$target]);
readerCheck(OptionalReadingBlocks::forReader($blocks,$repo,false)===$hidden,'No route capability hides internal links only');
readerCheck($blocks===$originalBlocks,'Filtering must not mutate original blocks');

$repo->saveLessonDocument($source->id,['revision'=>$repo->getLessonDocument($source->id)->revision,'plateValue'=>$blocks]);
$repo->updateNode($tree['lesson']->id,['status'=>'draft']);
$body=(string)$route($source->slug)->body();
readerCheck(!str_contains($body,'href="'.home_url($base).'"'),'Actual handler hides unpublished ordinary destination');
readerCheck(str_contains($body,'앞 문장 <strong>연결 설명</strong> 뒤 문장'),'Actual handler retains inline explanation and formatting');
readerCheck($repo->getLessonDocument($source->id)->plateValue===$blocks,'Actual handler preserves saved editor document');
echo "PASS: generic lesson links, subdirectory URLs, origin boundaries, nested prose, publication/ancestry, one-query cache and unchanged originals.\n";
