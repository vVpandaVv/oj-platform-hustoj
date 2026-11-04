<?php
declare(strict_types=1);
namespace CodingPanda\OjResearch\Presentation;

use CodingPanda\OjResearch\Domain\ClassroomDocuments as D;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\{ActionUrlProvider,ActionSecurity};

final class ClassroomDocumentPages
{
    public function __construct(private readonly ActionUrlProvider $urls,private readonly ActionSecurity $security){}
    public function library(array $s):string
    {
        $h='<section class="ojp-research-card"><h2>보고서와 첨부자료</h2><p>필요한 문서를 골라 미리 보거나 별도 화면에서 열어 주세요. 문서별 HTML·Markdown을 내려받을 수 있습니다.</p></section>';
        foreach(['본문','자문','첨부자료']as$category){$h.='<h2>'.$category.'</h2><div class="ojp-research-grid">';foreach(D::CATALOG as$key=>$item){if($item['category']!==$category){continue;}$url='/oj-console/research/'.$s['id'].'/report?document='.$key;
            $h.='<article class="ojp-research-card"><h3>'.$this->e($item['title']).'</h3><p>'.$this->e($item['description']).'</p><div class="ojp-research-actions"><a class="ojp-button ojp-button--primary" href="'.$url.'" data-oj-research-document="'.$this->e($item['title']).'">미리보기</a><a class="ojp-button" href="'.$url.'" target="_blank" rel="noopener">별도 열기 ↗</a></div></article>';}$h.='</div>';}
        $h.='<details class="ojp-research-card" data-oj-research-modal><summary>분석 데이터 내려받기</summary><div><p>CSV는 익명 학생별 분석표, JSON은 설문·관찰을 포함한 전체 근거 자료입니다. 자유 의견에 개인 정보가 있는지 확인하세요.</p>'.$this->exports($s,'all',['csv'=>'학생별 데이터 CSV','json'=>'전체 근거 JSON']).'</div></details>';
        return $h.'<dialog class="ojp-dialog ojp-research-modal" id="oj-research-document-preview" aria-labelledby="oj-research-preview-title"><header class="ojp-dialog__header"><h2 id="oj-research-preview-title">문서 미리보기</h2><button type="button" class="ojp-button" data-oj-research-close>닫기</button></header><div class="ojp-dialog__body" data-oj-research-preview-body></div></dialog>';
    }
    public function document(array $s,array $r,string $document,bool $fragment=false):string
    {
        $title=D::title($document);$h='';
        if(!$fragment){$h.='<header class="ojp-page-header"><div><p class="ojp-eyebrow">'.$this->e($s['title']).'</p><h1>'.$this->e($title).'</h1></div><a class="ojp-button" href="/oj-console/research/'.$s['id'].'/report">보고서·첨부자료 목록</a></header>';}
        $h.='<p>수집된 자료 기준입니다. 미수집 항목과 해석 한계를 함께 확인하세요.</p>'.$this->exports($s,$document,['html'=>'인쇄용 HTML · PDF 저장','markdown'=>'문안·표 Markdown']);
        foreach(D::sections($s,$r,$document)as$section){$h.='<article class="ojp-research-paper"><h2>'.$this->e($section['title']).'</h2>';foreach($section['paragraphs']as$text){$h.='<p>'.nl2br($this->e($text)).'</p>';}foreach($section['tables']as$t){$h.='<h3>'.$this->e($t['caption']).'</h3><div class="ojp-table-wrap"><table class="ojp-table"><thead><tr>';foreach($t['headers']as$v){$h.='<th scope="col">'.$this->e((string)$v).'</th>';}$h.='</tr></thead><tbody>';foreach($t['rows']as$row){$h.='<tr>';foreach($row as$v){$h.='<td>'.$this->e((string)$v).'</td>';}$h.='</tr>';}if($t['rows']===[]){$h.='<tr><td colspan="'.count($t['headers']).'">아직 기록이 없습니다.</td></tr>';}$h.='</tbody></table></div>';}$h.='</article>';}
        return $h;
    }
    private function exports(array $s,string $document,array $formats):string
    {
        $h='<div class="ojp-research-actions">';foreach($formats as$format=>$label){$h.='<form method="post" action="'.$this->e($this->urls->actionUrl()).'">'.$this->security->hiddenFields('oj_research_command').'<input type="hidden" name="operation" value="pilot.export"><input type="hidden" name="study_id" value="'.$s['id'].'"><input type="hidden" name="revision" value="'.$s['revision'].'"><input type="hidden" name="document" value="'.$document.'"><input type="hidden" name="format" value="'.$format.'"><button class="ojp-button" type="submit">'.$label.'</button></form>';}
        return $h.'</div>';
    }
    private function e(string $v):string{return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
}
