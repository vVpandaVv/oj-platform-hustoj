<?php
declare(strict_types=1);
namespace OjConceptLab\Activities;
final class Catalog {
    public const ITEMS = [
        'outlier-detection'=>['이상치 탐색과 대표값 비교','데이터 분석','관측값을 검토·제외하며 분포와 평균·중앙값의 변화를 비교합니다.','chart-scatter'],
        'missing-values'=>['결측값 처리 방법 비교','데이터 분석','자료의 특성에 따라 대체·보간·보류의 결과를 비교합니다.','activity'],
        'data-visualization'=>['데이터 시각화와 그래프 표현','데이터 분석','범주·시계열·분포·관계를 알맞은 그래프로 표현합니다.','chart-no-axes-column'],
        'data-extraction'=>['조건에 따른 데이터 필터링과 추출','데이터 분석','행 조건과 필요한 열을 바꾸며 실제 출력 자료를 확인합니다.','filter'],
        'data-structure-lab'=>['스택·큐·우선순위 큐','알고리즘','삽입과 삭제를 직접 실행하며 저장·출력 순서를 비교합니다.','list'],
        'ai-classifier-lab'=>['특성과 규칙에 따른 분류 경계','인공지능 기초','입력 특성·임계값·비교 방향이 분류 결과에 미치는 영향을 봅니다.','sliders-horizontal'],
        'binary-search'=>['정렬된 자료의 이진 탐색','알고리즘','조회한 값과 목표를 비교하며 탐색 후보 범위를 줄입니다.','search'],
        'privacy'=>['공개 범위와 개인정보 최소 공개','정보윤리','공개 목적에 필요한 정보는 남기고 비공개 정보는 가립니다.','eye-off'],
        'network-routing'=>['네트워크 경로와 전송 비용','컴퓨팅 시스템','경로의 비용을 미리 비교하고 전송·취소·복원을 실험합니다.','network'],
        'run-length-encoding'=>['연속 길이 부호화와 무손실 압축','데이터 표현','연속된 같은 문자를 묶고 원문 복원과 저장 크기를 비교합니다.','package'],
        'multicore-scheduling'=>['멀티코어의 병렬 처리와 작업 배치','컴퓨팅 시스템','실행 시간과 마감을 보며 두 코어의 작업 진행을 비교합니다.','cpu'],
    ];
    private const HUB = [
        'outlier-detection'=>['이상치·대표값','평균·중앙값 비교','chart-scatter','blue'],
        'missing-values'=>['결측값 처리','빈 값을 채우는 방법','square-dashed','coral'],
        'data-visualization'=>['데이터 시각화','자료를 그래프로','chart-no-axes-combined','mint'],
        'data-extraction'=>['데이터 필터링·추출','조건에 맞는 행과 열','list-filter','amber'],
        'data-structure-lab'=>['스택·큐·우선순위 큐','넣기·꺼내기 순서','layers','mint'],
        'ai-classifier-lab'=>['분류 경계','규칙에 따른 분류','split','coral'],
        'binary-search'=>['이진 탐색','후보를 반씩 줄이기','search','amber'],
        'privacy'=>['개인정보 최소 공개','꼭 필요한 정보만','shield-check','mint'],
        'network-routing'=>['네트워크 경로','경로별 비용 비교','network','blue'],
        'run-length-encoding'=>['연속 길이 부호화','같은 문자 묶기','file-archive','coral'],
        'multicore-scheduling'=>['멀티코어 병렬 처리','두 코어에 작업 배치','cpu','amber'],
    ];
    public static function all(bool $include_closed=false): array {
        $closed=(array)get_option('oj_concept_lab_closed',[]); $items=[];
        foreach(self::ITEMS as $id=>[$title,$category,$description,$icon]) {
            if(!$include_closed&&in_array($id,$closed,true)) continue;
            [$hubTitle,$hubDescription,$hubIcon,$tone]=self::HUB[$id];
            $items[$id]=compact('id','title','category','description','icon','hubTitle','hubDescription','hubIcon','tone')+['open'=>!in_array($id,$closed,true),'url'=>home_url('/oj-addons/oj-concept-lab/activities/'.$id)];
        }
        return $items;
    }
    public static function data(string $id): array {
        static $banks;
        $banks??=json_decode((string)file_get_contents(OJ_CONCEPT_LAB_PATH.'resources/data/examples.json'),true,512,JSON_THROW_ON_ERROR);
        return $banks[$id]??[];
    }
}
