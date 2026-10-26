<?php
declare(strict_types=1);
use CodingPanda\OjPlatform\Kernel\Autoloader;
use CodingPanda\OjPlatform\Modules\AI\Domain\ClassroomFeedbackPrompts as P;
use CodingPanda\OjPlatform\Modules\AI\Domain\FeedbackValidationCases as C;
use CodingPanda\OjPlatform\Modules\AI\Infrastructure\Filesystem\PrivateFeedbackGuideRepository;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\PrivateDataRootProvider;

$root=dirname(__DIR__).'/oj-platform';require_once $root.'/src/Kernel/Autoloader.php';Autoloader::register($root);
$out=$root.'/docs/research-feedback-validation-20260911';if(!is_dir($out)){mkdir($out,0770,true);}
$repository=new PrivateFeedbackGuideRepository(new class implements PrivateDataRootProvider{public function root():string{throw new RuntimeException('Only built-in guides may be read.');}});
$pack=['version'=>P::VERSION,'created_at'=>'2026-09-11','comparison_basis'=>'운영 사이트에서 변경 전 선택된 기본 지침 4종. 사용자가 과거 제작한 상세 페르소나 원문은 확보되지 않아 비교 대상에 포함하지 않았다.','guides'=>[]];
$md="# 수준별 피드백 프롬프트 원문\n\n버전: ".P::VERSION."\n\n현재 플랫폼의 학생 수준 평가 계산은 변경하지 않는다. 이 자료는 프롬프트의 교수 전략을 정의하며 교육 효과가 검증되었다는 뜻은 아니다.\n";
foreach(P::LEVELS as $key=>$label){$base=$repository->find(P::BASELINES[$key]);$pack['guides'][$key]=['name'=>P::name($key),'instructions'=>P::instructions($key),'baseline'=>['key'=>$base->key(),'revision'=>$base->revision(),'instructions'=>$base->instructions()]];$md.="\n## $label\n\n### 비교 기준 v1\n\n".$base->instructions()."\n\n### 신규 지침\n\n".P::instructions($key)."\n";}
file_put_contents($out.'/prompts.json',json_encode($pack,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));file_put_contents($out.'/prompts.md',$md);
file_put_contents($out.'/cases.json',json_encode(['version'=>C::VERSION,'cases'=>C::all()],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
echo $out.PHP_EOL;
