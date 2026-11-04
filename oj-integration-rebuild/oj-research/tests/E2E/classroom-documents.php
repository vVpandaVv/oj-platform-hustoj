<?php
declare(strict_types=1);
require __DIR__.'/classroom.php';

use CodingPanda\OjResearch\Domain\ClassroomDocuments as D;
use CodingPanda\OjResearch\Presentation\ResearchPageHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\{HttpRequest,HttpResponse,RouteDefinition,RouteMatch,RouteArea,AuthenticationRequirement};
use CodingPanda\OjPlatform\Modules\Auth\Contract\{CurrentAccount,CurrentAccountProvider,UserIdentity};
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;
use CodingPanda\OjPlatform\Kernel\Module\ModuleId;

$startDocuments=$checks;
$accounts=new class implements CurrentAccountProvider {public int $actor=1;public function current():CurrentAccount{return new CurrentAccount(new UserIdentity($this->actor,'test','','합성'),null);}};
$responders=new class implements ConsoleResponder,PageResponder {
    public function respond(string $title,string $content,string $currentMenu,HttpRequest $request,int $status=200):HttpResponse{return HttpResponse::html('CONSOLE-SHELL'.$content,$status);}
    public function guest(string $title,string $content,HttpRequest $request,int $status=200):HttpResponse{return HttpResponse::html($content,$status);}
    public function authenticated(string $title,string $content,HttpRequest $request,int $status=200):HttpResponse{return HttpResponse::html($content,$status);}
};
$handler=new ResearchPageHandler($service,$accounts,$responders,$responders,$urls,$security,$ui);
$route=new RouteMatch(new RouteDefinition('console.research.report',new ModuleId('research'),'research','/oj-console/research/{study_id}/report',RouteArea::Console,['GET'],AuthenticationRequirement::Authenticated,'html'),['study_id'=>(string)$cid]);
$request=fn($document,$fragment='')=>new HttpRequest('GET','/oj-console/research/'.$cid.'/report',['document'=>$document,'fragment'=>$fragment]);
$main=$ui->report(1,$cid);researchAssert(substr_count($main,'data-oj-research-document=')===count(D::CATALOG),'All independent report and appendix links shown');
researchAssert(!str_contains($main,'<table')&&!str_contains($main,'class="ojp-research-paper"'),'Report library does not dump all paper tables into screen');
foreach(D::CATALOG as$key=>$definition){
    $full=$handler->handle($route,$request($key));$fragment=$handler->handle($route,$request($key,'1'));
    researchAssert($full->status()===200&&str_contains($full->body(),'CONSOLE-SHELL'),'Document has standalone console page: '.$key);
    researchAssert($fragment->status()===200&&!str_contains($fragment->body(),'CONSOLE-SHELL')&&str_contains($fragment->body(),'name="_wpnonce"'),'Lazy modal fragment is authorized and has export form: '.$key);
    foreach(['html','markdown','json']as$format){$export=$actions->handle(1,new HttpRequest('POST','/',[],['operation'=>'pilot.export','study_id'=>$cid,'document'=>$key,'format'=>$format]));researchAssert($export->status()===200&&str_contains($export->body(),$definition['title']),'Individual export: '.$key.' '.$format);}
    $snapshot('document-'.$key,$full->body());
    if(is_string($output)&&$output!==''){file_put_contents($output.'/fragment-'.$key.'.html',$fragment->body());}
}
researchAssert(!str_contains($ui->report(1,$cid,'surveys'),'synthetic()')&&!str_contains($ui->report(1,$cid,'surveys'),'설문 문항별 결과'),'Questionnaire appendix has blank forms without student responses');
researchAssert(!str_contains($ui->report(1,$cid,'report'),'전문가 의견·보완 요청'),'Main report does not include advisory response sheet');
researchAssert(str_contains($ui->report(1,$cid,'observations'),'&lt;script&gt;synthetic()&lt;/script&gt;'),'Raw opinions escaped in appendix');
researchAssert($handler->handle($route,$request('../private','1'))->status()===403,'Unknown document cannot select a file or template');
$accounts->actor=10;researchAssert($handler->handle($route,$request('observations','1'))->status()===403,'Fragment endpoint denies student access');
$accounts->actor=2;researchAssert($handler->handle($route,$request('results','1'))->status()===403,'Another teacher cannot fetch group report fragments');
$accounts->actor=1;
researchDenied(fn()=>$actions->handle(10,new HttpRequest('POST','/',[],['operation'=>'pilot.export','study_id'=>$cid,'document'=>'surveys','format'=>'html'])),'Student cannot export appendix by forging action');
$snapshot('report',$main);
echo 'Classroom documents passed: '.($checks-$startDocuments)." assertions; separate pages, authorized fragments, appendix exports and escaping\n";
