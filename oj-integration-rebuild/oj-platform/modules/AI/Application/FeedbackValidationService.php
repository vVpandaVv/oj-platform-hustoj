<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\Modules\AI\Application;

use CodingPanda\OjPlatform\Modules\AI\Contract\FeedbackGuideRepository;
use CodingPanda\OjPlatform\Modules\AI\Contract\FeedbackValidationStore;
use CodingPanda\OjPlatform\Modules\AI\Domain\ClassroomFeedbackPrompts as Prompts;
use CodingPanda\OjPlatform\Modules\AI\Domain\FeedbackValidationCases as Cases;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskRequest;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskRouter;
use DomainException;
use Throwable;

final class FeedbackValidationService
{
    // Only repository-owned fixtures enter this task; actual submissions retain their separate approval policy.
    public const TASK_TYPE = 'feedback_prompt_validation';
    public function __construct(private readonly FeedbackGuideRepository $guides,private readonly FeedbackValidationStore $store,private readonly AiTaskRouter $ai,private readonly FeedbackInputFactory $inputs,private readonly FeedbackOutputValidator $outputs) {}

    public function run(int $actor,string $candidateKey,string $caseKey,string $variant,string $token): void
    {
        if($actor<1||!preg_match('/^[a-f0-9]{32}$/D',$token)||!in_array($variant,['baseline','candidate'],true)) {throw new DomainException('검증 요청이 올바르지 않습니다.');}
        $candidate=$this->guides->find($candidateKey);
        if($candidate===null) {throw new DomainException('비교할 지침을 찾을 수 없습니다.');}
        $case=Cases::get($caseKey);$level=$candidate->level();
        $guide=$variant==='candidate'?$candidate:$this->guides->find(Prompts::BASELINES[$level]);
        if($guide===null) {throw new DomainException('비교 기준 지침을 찾을 수 없습니다.');}
        $old = $this->store->find($candidateKey, $token);
        if ($old !== null) {
            if ($old['case_key'] !== $caseKey || $old['variant'] !== $variant) {
                throw new DomainException('이미 사용한 검증 요청 번호입니다.');
            }
            return;
        }
        $routing=$this->ai->snapshot(self::TASK_TYPE,false);
        if($routing->fallbackProfileKey()!==null) {throw new DomainException('동일 모델 비교를 위해 대체 모델 없는 피드백 연결을 선택해 주세요.');}
        $input=$this->inputs->research(Cases::base($caseKey),['policy'=>'level_adaptive','baseline'=>[
            'analysis_status'=>$level==='insufficient'?'insufficient':'ready','level'=>$level==='insufficient'?null:$level,
            'proven_tier'=>match($level){'beginner'=>1,'intermediate'=>2,'advanced'=>3,default=>null},'skill_score'=>null,'policy_version'=>'synthetic-fixture-v1'],
            'instructions'=>$guide->instructions()]);
        $record=['id'=>$token,'candidate_key'=>$candidateKey,'candidate_revision'=>$candidate->revision(),'case_key'=>$caseKey,'fixture_version'=>Cases::VERSION,'split'=>$case['split'],'variant'=>$variant,'level'=>$level,
            'guide'=>['key'=>$guide->key(),'name'=>$guide->name(),'revision'=>$guide->revision(),'instructions'=>$guide->instructions()],
            'input'=>$input,'input_hash'=>$this->inputs->hash($input),'assignment'=>$routing->toArray(),'actor_id'=>$actor,'started_at'=>gmdate('c'),'status'=>'running','checks'=>[],
            'human_review'=>['correctness'=>null,'level_fit'=>null,'actionability'=>null,'answer_non_disclosure'=>null,'teacher_comment'=>null]];
        if(!$this->store->reserve($record)) {return;}
        $started=microtime(true);
        try {
            $response=$this->ai->execute($routing,new AiTaskRequest(self::TASK_TYPE,FeedbackInputFactory::CONTRACT_VERSION,$input));
            $record['model']=['provider'=>$response->providerKey(),'model'=>$response->modelKey(),'profile'=>$response->profileKey(),'fingerprint'=>$response->profileFingerprint()];
            $raw=$response->output();
            if(strlen(json_encode($raw,JSON_THROW_ON_ERROR))>64000) {throw new DomainException('검증 응답 크기 한도 초과');}
            $record['output']=$raw;
            try {
                $output=$this->outputs->validate($raw);
                $body=$output['result_understanding'].implode('',$output['step_hints']).$output['review_question'];
                $length=preg_match_all('/./us',$body);
                $record['checks']=['platform_format'=>true,'hints_1_to_3'=>count($output['step_hints'])<=3,'body_within_900'=>$length<=900,'no_level_label'=>preg_match('/초급|중급|고급|실험집단|통제집단|비교집단/u',$body)!==1];
                $record['status']='generated';
            } catch(DomainException $e) {$record['checks']=['platform_format'=>false];$record['validation_error']=$e->getMessage();$record['status']='rejected';}
        } catch(Throwable $e) {$record['status']='failed';$record['error']='AI 연결 또는 응답 처리에 실패했습니다. 운영 AI 연결과 처리 기록을 확인해 주세요.';}
        $record['elapsed_ms']=(int)round((microtime(true)-$started)*1000);$record['completed_at']=gmdate('c');
        $this->store->finish($record);
    }

    public function records(string $candidate): array {return $this->store->records($candidate);}

    public function page(string $candidate, int $page): array
    {
        $total = $this->store->count($candidate);
        $pages = max(1, (int) ceil($total / 10));
        $page = max(1, min($pages, $page));
        return [
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'records' => $this->store->page($candidate, ($page - 1) * 10, 10),
        ];
    }

    public function find(string $candidate, string $id): ?array
    {
        return preg_match('/^[a-f0-9]{32}$/D', $id) === 1
            ? $this->store->find($candidate, $id)
            : null;
    }

    public function export(string $candidate): array
    {
        $records=$this->records($candidate);
        foreach($records as &$record) {unset($record['actor_id']);}unset($record);
        return ['schema'=>'oj-feedback-validation-v1','exported_at'=>gmdate('c'),'scope'=>'합성 코드의 실제 AI 응답. 자동 검사는 교육 효과 또는 교사 평가가 아닙니다.','cases'=>Cases::all(),'records'=>$records];
    }
}
