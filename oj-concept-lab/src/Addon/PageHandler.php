<?php
declare(strict_types=1);
namespace OjConceptLab\Addon;
use CodingPanda\OjPlatform\AddonApi\V1\{AddonPageHandlerV1,AddonPageRequestV1,AddonPageResponseV1};
use OjConceptLab\Activities\Catalog;
use OjConceptLab\Frontend\Renderer;
final class PageHandler implements AddonPageHandlerV1 {
    public function __construct(private string $id='',private bool $manage=false){}
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1 {
        if($this->manage&&!Registration::can_manage()) return AddonPageResponseV1::unavailable('교사 전용 화면입니다.','addon_access_denied');
        if($this->id!==''&&!isset(Catalog::all()[$this->id])&&!Registration::can_manage()) return AddonPageResponseV1::unavailable('현재 공개되지 않은 체험입니다.','addon_access_denied');
        $body=Renderer::render($this->id,$this->manage);$title=Catalog::ITEMS[$this->id][0]??'개념 체험';
        return method_exists(AddonPageResponseV1::class,'page')?AddonPageResponseV1::page($title,$body,'standard',true):AddonPageResponseV1::html($title,$body);
    }
}
