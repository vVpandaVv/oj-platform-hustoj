<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\Modules\Submissions\Application;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\CodeExecutionEngine;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\CodeExecutionMaintenance;
use DomainException;

/** Administrative native runtime probes. Never infer readiness from a DB connection alone. */
final class CodeExecutionSupport
{
    private const IDS=['c'=>0,'cpp'=>1,'java'=>3,'python'=>6];
    public function __construct(private readonly SettingsManager $settings,private readonly SubmissionSettingsProvider $submissions,
        private readonly CodeExecutionEngine $engine,private readonly \Closure $key){}
    public static function limits(array $value):array
    {
        return ['global_pending'=>max(1,min(100,(int)($value['global_pending']??8))),
            'actor_pending'=>max(1,min(20,(int)($value['actor_pending']??2))),
            'actor_hourly'=>max(1,min(1000,(int)($value['actor_hourly']??120)))];
    }
    public function policy():array
    {
        $snapshot=$this->settings->read('private_execution',[]);
        return self::limits((array)$snapshot->value())+['revision'=>$snapshot->revision()];
    }
    public function savePolicy(int $global,int $actor,int $hour,int $revision):void
    {
        $value=(array)$this->settings->read('private_execution',[])->value();
        $this->settings->save('private_execution',array_replace($value,self::limits(['global_pending'=>$global,'actor_pending'=>$actor,'actor_hourly'=>$hour])),$revision);
    }
    private function fingerprint():string
    {
        return hash('sha256','native-probe.v2:'.$this->settings->read('judge_database')->revision().':'.$this->settings->read('submission')->revision().':'.$this->submissions->current()->disabledLanguageMask());
    }
    public function matrix():array
    {
        $value=(array)$this->settings->read('private_execution',[])->value();
        $mask=$this->submissions->current()->disabledLanguageMask();$fingerprint=$this->fingerprint();$matrix=[];
        foreach(self::IDS as $language=>$id){
            $configured=($mask&(1<<$id))===0;$check=$value['checks'][$language]??[];
            $current=($check['fingerprint']??'')===$fingerprint;
            $matrix[]=['language'=>$language,'configured'=>$configured,
                'state'=>!$configured?'disabled':($current?($check['state']??'untested'):'untested'),
                'checked_at'=>$current?($check['checked_at']??''):'',
                'message'=>!$configured?'제출 설정에서 허용하지 않은 언어입니다.':($current?($check['message']??'실제 실행 검사가 필요합니다.'):'실제 실행 검사가 필요합니다.')];
        }
        return $matrix;
    }
    public function allowed():array
    {
        return array_values(array_column(array_filter($this->matrix(),static fn(array $row):bool=>$row['state']==='passed'),'language'));
    }
    public function probe(int $actor,string $language,bool $restart=false):array
    {
        if(!isset(self::IDS[$language])||($this->submissions->current()->disabledLanguageMask()&(1<<self::IDS[$language]))!==0){throw new DomainException('제출 설정에서 허용한 검사 언어를 선택해 주세요.');}
        $snapshot=$this->settings->read('private_execution',[]);$value=(array)$snapshot->value();
        $fingerprint=$this->fingerprint();$check=$value['checks'][$language]??[];
        if($restart||($check['state']??'')!=='pending'||($check['fingerprint']??'')!==$fingerprint){
            $key=($this->key)();if(!is_string($key)||strlen($key)<32){throw new DomainException('실행 검사의 서명 키를 확인해 주세요.');}
            $identity=CodeExecutionIdentity::actorPrefix($actor,$key).'_'.bin2hex(random_bytes(8));
            $marker='OJVERIFY_'.hash_hmac('sha256','support:'.$identity,$key);
            $source=match($language){
                'python'=>"print('OJ_RUNTIME_OK')\n",
                'c'=>"#include <stdio.h>\nint main(void){puts(\"OJ_RUNTIME_OK\");return 0;}\n",
                'cpp'=>"#include <iostream>\nint main(){std::cout << \"OJ_RUNTIME_OK\\n\";return 0;}\n",
                'java'=>"public class Main { public static void main(String[] args) { System.out.println(\"OJ_RUNTIME_OK\"); } }\n",
            };
            $id=$this->engine->submit($identity,self::IDS[$language],(new CodeExecutionProbe())->instrument($language,$source,$marker),'');
            $check=['state'=>'pending','fingerprint'=>$fingerprint,'id'=>$id,'identity'=>$identity,'marker'=>$marker,'checked_at'=>gmdate('Y-m-d H:i:s'),'message'=>'기존 채점기에서 검사 중입니다. 잠시 후 결과 조회를 누르세요.'];
        }
        $row=$this->engine->poll((int)$check['id'],(string)$check['identity']);
        if($row===null){$check['state']='failed';$check['message']='검사 실행 기록을 찾을 수 없습니다. 다시 검사해 주세요.';}
        elseif(!in_array($row['result'],[0,1,2,3,12,14],true)){
            $result=(new CodeExecutionProbe())->inspect($row['result'],$row['output'],$row['compile_error'],$check['marker']);
            $passed=$result['verdict']==='executed'&&$result['actual_output']==="OJ_RUNTIME_OK\n";
            $check['state']=$passed?'passed':'failed';$check['message']=$passed?'실제 채점기의 정상 실행과 전체 출력을 확인했습니다.':
                ($this->message($result['diagnostics'])?:'실행·컴파일 또는 전체 출력 검증에 실패했습니다.');
        }elseif($row['age_seconds']>300){$check['state']='failed';$check['message']='검사 대기 시간이 초과되었습니다. 채점 데몬과 허용 언어를 확인해 주세요.';}
        $check['checked_at']=gmdate('Y-m-d H:i:s');$value['checks'][$language]=$check;
        $this->settings->save('private_execution',$value,$snapshot->revision());
        return ['language'=>$language,'state'=>$check['state'],'message'=>$check['message']];
    }
    public function status():array
    {
        return $this->engine instanceof CodeExecutionMaintenance?$this->engine->status():[];
    }
    private function message(string $message):string
    {
        preg_match('/^.{0,200}/us',$message,$matches);
        return $matches[0]??'실행 진단을 확인해 주세요.';
    }
    public function cleanup():array
    {
        return $this->engine instanceof CodeExecutionMaintenance?$this->engine->housekeeping():[];
    }
}
