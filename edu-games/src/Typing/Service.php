<?php
declare(strict_types=1);
namespace EduGames\Typing;

use EduGames\Games\Modules\Code_Typing\Code_Typing_Module;
use EduGames\Rankings\Page;
use EduGames\Reports\Roster;
use EduGames\Support\Capabilities;

final class Service {
    public static function error(string $message,int $status=400): \WP_Error {return new \WP_Error('edu_games_typing',$message,array('status'=>$status));}
    public static function samples(): array {return array_values(array_filter(Code_Typing_Module::rules()['samples'],static fn($s)=>$s['mode']==='assessment'));}
    public static function catalog(): array {return array('version'=>Rules::VERSION,'samples'=>array_map(static fn($s)=>array_intersect_key($s,array_flip(array('id','title','language'))),self::samples()));}
    private static function chosen($ids,string $language): array {
        if(!is_array($ids)||!array_is_list($ids)||!$ids||count($ids)>30||count(array_unique($ids,SORT_REGULAR))!==count($ids))throw new \InvalidArgumentException('예제를 하나 이상 선택하세요.');
        $samples=array_column(self::samples(),null,'id');$result=array();
        foreach($ids as $id){if(!is_string($id)||!isset($samples[$id])||$samples[$id]['language']!==$language)throw new \InvalidArgumentException('선택한 언어와 예제를 확인하세요.');$result[]=array_intersect_key($samples[$id],array_flip(array('id','title','language','content','sourceKind','languageVersion','expectedOutput')));}
        return $result;
    }
    public static function evaluation_view(array $row): array {
        $c=json_decode($row['config_json'],true);unset($c['samples']);
        return array('id'=>(int)$row['id'],'title'=>$row['title'],'status'=>$row['status'],'contextLabels'=>array_column($c['contexts'],'label'))+$c;
    }
    public static function owned(int $id): array|\WP_Error {
        $row=Repository::evaluation($id);
        if(!$row)return self::error('평가를 찾을 수 없습니다.',404);
        if(!Capabilities::can_manage()||((int)$row['owner_id']!==get_current_user_id()&&!current_user_can('manage_options')))return self::error('이 평가를 조회할 권한이 없습니다.',403);
        return $row;
    }
    public static function create_evaluation(array $p): array|\WP_Error {
        if(!Capabilities::can_manage())return self::error('교사 권한이 필요합니다.',403);
        global $wpdb;
        $title=is_string($p['title']??null)?sanitize_text_field($p['title']):'';$language=$p['language']??'';
        $seconds=$p['durationSeconds']??300;$threshold=$p['minAccuracy']??95;$max=$p['maxAttempts']??1;$contexts=$p['contexts']??null;$lineEnding=$p['lineEnding']??'enter';
        if(!$title||count(Rules::chars($title))>191||!in_array($language,array('python','c'),true)||!is_int($seconds)||!in_array($seconds,array(60,180,300,600),true)
            ||!is_numeric($threshold)||!is_finite((float)$threshold)||(float)$threshold<70||(float)$threshold>100||!is_int($max)||$max<1||$max>3
            ||!is_array($contexts)||!array_is_list($contexts)||!$contexts||count($contexts)>12||!in_array($lineEnding,array('enter','space-enter'),true))return self::error('평가 제목, 시간, 정확도 또는 대상 그룹을 확인하세요.');
        try{$samples=self::chosen($p['sampleIds']??null,$language);}catch(\InvalidArgumentException $e){return self::error($e->getMessage());}
        $targets=array();$refs=array();
        foreach($contexts as $context){
            if(!is_array($context)||!in_array($context['type']??'',array('class','group'),true))return self::error('학급·그룹을 확인하세요.');
            foreach(array('id','revision') as $key)if(!is_string($context[$key]??null)||!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:@-]{0,159}$/D',$context[$key]))return self::error('그룹 목록을 새로 조회하세요.');
            $users=Roster::learners($context['type'],$context['id'],$context['revision']);if(is_wp_error($users))return $users;
            $label=is_string($context['label']??null)?sanitize_text_field($context['label']):$context['id'];
            $ref=array('type'=>$context['type'],'id'=>$context['id'],'revision'=>$context['revision'],'label'=>implode('',array_slice(Rules::chars($label),0,191)));$refs[]=$ref;
            foreach($users as $user)$targets[(int)$user][]=$ref['label'];
        }
        if(!$targets||count($targets)>2000)return self::error('대상 학생은 1~2,000명이어야 합니다.');
        $config=array('language'=>$language,'durationSeconds'=>$seconds,'minAccuracy'=>(float)$threshold,'maxAttempts'=>$max,
            'contexts'=>$refs,'targetCount'=>count($targets),'sampleIds'=>array_column($samples,'id'),'samples'=>$samples,'version'=>Rules::VERSION,'lineEnding'=>$lineEnding);
        if(false===$wpdb->query('START TRANSACTION'))return self::error('평가 저장을 시작하지 못했습니다.',500);
        $ok=$wpdb->insert(Repository::table('evaluations'),array('owner_id'=>get_current_user_id(),'title'=>$title,'status'=>'draft','config_json'=>wp_json_encode($config),'audit_json'=>wp_json_encode(array(array('actor'=>get_current_user_id(),'action'=>'created','at'=>gmdate('c')))),'created_at'=>gmdate('Y-m-d H:i:s')));
        $id=(int)$wpdb->insert_id;
        if(false!==$ok&&$id)foreach($targets as $user=>$groups)if(false===$wpdb->insert(Repository::table('targets'),array('evaluation_id'=>$id,'user_id'=>$user,'groups_json'=>wp_json_encode(array_values(array_unique($groups)))))){$ok=false;break;}
        if(false===$ok||!$id){$wpdb->query('ROLLBACK');return self::error('평가와 대상 명단을 저장하지 못했습니다.',500);}
        if(false===$wpdb->query('COMMIT')){$wpdb->query('ROLLBACK');return self::error('평가 저장을 확정하지 못했습니다.',500);}
        do_action('edu_games_typing_evaluation_changed',get_current_user_id(),$id,'created');
        return array('evaluation'=>self::evaluation_view(Repository::evaluation($id)));
    }
    public static function evaluations(): array|\WP_Error {
        if(!Capabilities::can_manage())return self::error('교사 권한이 필요합니다.',403);
        global $wpdb;$table=Repository::table('evaluations');
        $where=current_user_can('manage_options')?'1=1':$wpdb->prepare('owner_id=%d',get_current_user_id());
        $rows=$wpdb->get_results("SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT 200",ARRAY_A);
        return is_array($rows)?array('evaluations'=>array_map(array(__CLASS__,'evaluation_view'),$rows)):self::error('평가 목록을 읽지 못했습니다.',500);
    }
    public static function set_status(int $id,$status): array|\WP_Error {
        $row=self::owned($id);if(is_wp_error($row))return $row;
        if(!in_array($status,array('open','closed'),true))return self::error('평가 상태를 확인하세요.');
        $audit=json_decode($row['audit_json']??'[]',true);$audit[]=array('actor'=>get_current_user_id(),'action'=>$status,'at'=>gmdate('c'));
        global $wpdb;if(false===$wpdb->query($wpdb->prepare('UPDATE '.Repository::table('evaluations').' SET status=%s,audit_json=%s WHERE id=%d',$status,wp_json_encode(array_slice($audit,-100)),$id)))return self::error('평가 상태를 저장하지 못했습니다.',500);
        $row['status']=$status;do_action('edu_games_typing_evaluation_changed',get_current_user_id(),$id,$status);
        return array('evaluation'=>self::evaluation_view($row));
    }
    public static function assigned(): array|\WP_Error {
        global $wpdb;$e=Repository::table('evaluations');$t=Repository::table('targets');
        $rows=$wpdb->get_results($wpdb->prepare("SELECT e.* FROM {$e} e INNER JOIN {$t} t ON t.evaluation_id=e.id WHERE t.user_id=%d AND e.status<>'draft' ORDER BY e.id DESC LIMIT 100",get_current_user_id()),ARRAY_A);
        if(!is_array($rows))return self::error('참가할 평가를 조회하지 못했습니다.',500);
        $result=array();foreach($rows as $row){$view=self::evaluation_view($row);$runs=self::user_runs((int)$row['id'],get_current_user_id());if(is_wp_error($runs))return $runs;
            $view['attempts']=array_map(static fn($r)=>self::result_row($r)+($r['status']==='running'?array('token'=>$r['token']):array()),$runs);
            $best=self::best_finished($runs);$active=array_values(array_filter($runs,static fn($r)=>$r['status']==='running'));
            $view['result']=$best?self::result_row($best):null;
            $view['participation']=array('status'=>$active?'running':($best?'finished':'absent'),'attemptsUsed'=>count($runs),
                'attemptsRemaining'=>max(0,$view['maxAttempts']-count($runs)),'canStart'=>$row['status']==='open'&&count($runs)<$view['maxAttempts'],
                'activeAttemptNo'=>$active?(int)$active[0]['attempt_no']:null);$result[]=$view;}
        return array('evaluations'=>$result);
    }
    public static function preview(int $id): array|\WP_Error {
        global $wpdb;$row=Repository::evaluation($id);
        if(!$row||$row['status']!=='open')return self::error('현재 선택할 수 없는 평가입니다.',403);
        $user=$wpdb->get_var($wpdb->prepare('SELECT user_id FROM '.Repository::table('targets').' WHERE evaluation_id=%d AND user_id=%d',$id,get_current_user_id()));
        if((int)$user!==get_current_user_id())return self::error('이 평가의 참가자가 아닙니다.',403);
        $config=json_decode($row['config_json'],true);
        return array('evaluation'=>self::evaluation_view($row),'samples'=>$config['samples']);
    }
    private static function user_runs(int $id,int $user): array|\WP_Error {
        global $wpdb;$rows=$wpdb->get_results($wpdb->prepare('SELECT '.Repository::summary_columns().' FROM '.Repository::table('runs').' WHERE evaluation_id=%d AND user_id=%d AND version IN (%s,%s) ORDER BY attempt_no DESC LIMIT 1000',$id,$user,Rules::VERSION,Rules::LEGACY_VERSION),ARRAY_A);
        return is_array($rows)?array_map(array(Repository::class,'settle_summary'),$rows):self::error('검정 기록을 읽지 못했습니다.',500);
    }
    private static function token_view(string $token,int $user): array|\WP_Error {$row=Repository::run($token,$user);return $row?self::run_view($row):self::error('검정 상태를 읽지 못했습니다. 이어 하기로 다시 조회하세요.',500);}
    public static function start(array $p): array|\WP_Error {
        if(!Capabilities::can_play())return self::error('참가 권한이 필요합니다.',403);
        global $wpdb;$user=get_current_user_id();$id=$p['evaluationId']??0;
        if(!is_int($id)||$id<0)return self::error('평가 번호를 확인하세요.');
        if($id){
            $evaluation=Repository::evaluation($id);
            if(!$evaluation||$evaluation['status']!=='open')return self::error('현재 참가할 수 없는 평가입니다.',403);
            $target=$wpdb->get_var($wpdb->prepare('SELECT user_id FROM '.Repository::table('targets').' WHERE evaluation_id=%d AND user_id=%d',$id,$user));
            if((int)$target!==$user)return self::error('이 평가의 지정된 참가자가 아닙니다.',403);
            $config=json_decode($evaluation['config_json'],true);
            if(!Rules::supports($config['version']??''))return self::error('이 평가의 채점 버전은 더 이상 시작할 수 없습니다.',409);
            $challenge='evaluation-'.$id;
        }else{
            $language=$p['language']??'';$sampleId=$p['sampleId']??'';
            if(!is_string($language)||!is_string($sampleId))return self::error('언어와 예제를 확인하세요.');
            $available=array_values(array_filter(self::samples(),static fn($s)=>$s['language']===$language));$ids=array_column($available,'id');$first=array_search($sampleId,$ids,true);
            if($first===false)return self::error('검정 예제를 확인하세요.');
            $ids=array_merge(array_slice($ids,$first),array_slice($ids,0,$first));
            $lineEnding=$p['lineEnding']??'enter';if(!in_array($lineEnding,array('enter','space-enter'),true))return self::error('줄 끝 입력 설정을 확인하세요.');
            $config=array('samples'=>self::chosen($ids,$language),'durationSeconds'=>300,'minAccuracy'=>95,'maxAttempts'=>1000000,'language'=>$language,'lineEnding'=>$lineEnding);
            $challenge=$sampleId.($lineEnding==='space-enter'?':space-enter':'');
        }
        $runs=self::user_runs($id,$user);if(is_wp_error($runs))return $runs;
        foreach($runs as $run)if($run['status']==='running')return $run['challenge']===$challenge?self::token_view($run['token'],$user):self::error('진행 중인 검정을 먼저 마치세요.',409);
        $attempt=$runs?max(array_column($runs,'attempt_no'))+1:1;
        if($attempt>$config['maxAttempts'])return self::error('허용된 평가 응시 횟수를 모두 사용했습니다.',403);
        $recent=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Repository::table('runs').' WHERE user_id=%d AND started_at>%s',$user,gmdate('Y-m-d H:i:s',time()-60)));
        if($recent>=10)return self::error('잠시 후 검정을 시작하세요.',429);
        $state=Rules::create($config['samples'],(int)$config['durationSeconds'],(float)$config['minAccuracy'],microtime(true),$config['version']??Rules::VERSION,$config['lineEnding']??'enter');$token=bin2hex(random_bytes(24));
        $ok=$wpdb->insert(Repository::table('runs'),array('token'=>$token,'user_id'=>$user,'evaluation_id'=>$id,'attempt_no'=>$attempt,'challenge'=>$challenge,
            'version'=>$state['version'],'status'=>'running','revision'=>0,'last_request'=>'','state_json'=>wp_json_encode($state),'metrics_json'=>wp_json_encode(Rules::metrics($state,microtime(true))),'deadline_at'=>gmdate('Y-m-d H:i:s',(int)ceil($state['deadline'])),'started_at'=>gmdate('Y-m-d H:i:s')));
        if(false===$ok){$runs=self::user_runs($id,$user);if(!is_wp_error($runs))foreach($runs as $run)if($run['status']==='running'&&$run['challenge']===$challenge)return self::token_view($run['token'],$user);return self::error('검정을 저장하지 못했습니다. 다시 시도하세요.',500);}
        return self::token_view($token,$user);
    }
    public static function run_view(array $row): array {
        return Rules::view(Repository::state($row),microtime(true))+array('token'=>$row['token'],'revision'=>(int)$row['revision'],'lastRequest'=>$row['last_request'],'evaluationId'=>(int)$row['evaluation_id'],'challenge'=>$row['challenge'],'attemptNo'=>(int)$row['attempt_no']);
    }
    public static function update(array $p,bool $resume=false): array|\WP_Error {
        $token=$p['token']??null;if(!is_string($token)||!preg_match('/^[a-f0-9]{48}$/D',$token))return self::error('검정 식별자가 올바르지 않습니다.');
        $row=Repository::run($token,get_current_user_id());if(!$row)return self::error('검정에 접근할 수 없습니다.',404);
        if($resume)return self::run_view(Repository::settle($row));
        $request=$p['requestId']??null;
        if(!is_string($request)||!preg_match('/^[A-Za-z0-9-]{8,64}$/D',$request))return self::error('요청 번호를 확인하세요.');
        if(hash_equals($row['last_request'],$request))return self::run_view($row);
        if(!is_int($p['revision']??null)||$p['revision']!==(int)$row['revision'])return self::error('다른 창에서 진행되었습니다. 이어 하기로 갱신하세요.',409);
        try{$s=Rules::apply(Repository::state($row),$p['events']??array(),microtime(true),!empty($p['finish']));}catch(\InvalidArgumentException $e){return self::error($e->getMessage());}
        global $wpdb;if(!Repository::save($row,$s,$request))return self::error('저장되지 않았거나 다른 요청이 먼저 처리되었습니다. 이어 하기로 갱신하세요.',!empty($wpdb->last_error)?500:409);
        $row['state_json']=wp_json_encode($s);$row['revision']++;$row['last_request']=$request;return self::run_view($row);
    }
    public static function result_row(array $row): array {
        $m=isset($row['state_json'])?Rules::metrics(Repository::state($row),microtime(true)):json_decode($row['metrics_json'],true,512,JSON_THROW_ON_ERROR);
        return array('id'=>(int)$row['id'],'version'=>$row['version'],'attemptNo'=>(int)$row['attempt_no'],'status'=>$row['status'],'completedAt'=>$row['completed_at']??null)+$m;
    }
    private static function best_finished(array $runs): ?array {
        $finished=array_values(array_filter($runs,static fn($r)=>$r['status']==='finished'));
        usort($finished,static fn($a,$b)=>($b['qualified']<=>$a['qualified'])?:($b['effective_cpm']<=>$a['effective_cpm'])?:($b['input_accuracy']<=>$a['input_accuracy'])?:($a['id']<=>$b['id']));
        return $finished[0]??null;
    }
    public static function results(int $id,$page=1,$size=20,bool $export=false,?int $student=null,array $filters=array()): array|\WP_Error {
        $evaluation=self::owned($id);if(is_wp_error($evaluation))return $evaluation;
        $filters=self::result_filters($export?array():$filters);if(is_wp_error($filters))return $filters;
        global $wpdb;$targets=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.Repository::table('targets').' WHERE evaluation_id=%d ORDER BY user_id ASC',$id),ARRAY_A);
        if(!is_array($targets))return self::error('대상 학생을 읽지 못했습니다.',500);
        if($student!==null){
            if(!in_array($student,array_map('intval',array_column($targets,'user_id')),true))return self::error('평가 대상 학생이 아닙니다.',404);
            $runs=self::user_runs($id,$student);return is_wp_error($runs)?$runs:array('attempts'=>array_map(array(__CLASS__,'result_row'),$runs));
        }
        $version=json_decode($evaluation['config_json'],true)['version'];
        $runs=$wpdb->get_results($wpdb->prepare('SELECT '.Repository::summary_columns().' FROM '.Repository::table('runs').' WHERE evaluation_id=%d AND version=%s ORDER BY attempt_no DESC',$id,$version),ARRAY_A);
        if(!is_array($runs))return self::error('평가 결과를 읽지 못했습니다.',500);
        $byUser=array();foreach($runs as $run){$run=Repository::settle_summary($run);$byUser[(int)$run['user_id']][]=$run;}
        $rows=array();$summary=array('assigned'=>count($targets),'completed'=>0,'running'=>0,'absent'=>0);
        foreach($targets as $target){
            $user=(int)$target['user_id'];$attempts=$byUser[$user]??array();
            $active=array_values(array_filter($attempts,static fn($r)=>$r['status']==='running'));
            $record=self::best_finished($attempts)??($attempts[0]??null);$status=$active?'running':($record?$record['status']:'absent');$summary[$status==='finished'?'completed':$status]++;
            $u=get_userdata($user);$rows[]=array('userId'=>$user,'name'=>$u?$u->display_name:'학생 #'.$user,'groups'=>json_decode($target['groups_json'],true),'status'=>$status,'attempts'=>count($attempts),
                'resultStatus'=>$record?$record['status']:'absent','activeAttemptNo'=>$active?(int)$active[0]['attempt_no']:null)
                +($record?self::result_row($record):array('effectiveCpm'=>0,'inputAccuracy'=>0,'finalAccuracy'=>0,'validCharacters'=>0,'mistakes'=>0,'qualified'=>false,'completedAt'=>null));
        }
        $groups=array();$final=array();$maximum=0;
        foreach($rows as &$row){
            foreach($row['groups'] as $group)$groups[]=(string)$group;
            $row['quality']=!empty($row['verificationIssue'])?'review':($row['resultStatus']==='finished'?($row['qualified']?'qualified':'below'):'pending');
            if($row['resultStatus']==='finished')$final[]=$row;
            $maximum=max($maximum,(float)$row['effectiveCpm']);
        }unset($row);
        $groups=array_values(array_unique($groups,SORT_STRING));sort($groups,SORT_STRING);
        if($filters['group']!==''&&!in_array($filters['group'],$groups,true))return self::error('평가 대상 그룹을 확인하세요.');
        $count=count($final);
        $comparison=array('finalCount'=>$count,'averageCpm'=>$count?round(array_sum(array_column($final,'effectiveCpm'))/$count,1):null,
            'averageInputAccuracy'=>$count?round(array_sum(array_column($final,'inputAccuracy'))/$count,1):null,
            'cpmMax'=>max(1,$final?max(array_column($final,'effectiveCpm')):$maximum));
        // Filter and sort the whole authorized roster, never just the visible page.
        $rows=array_values(array_filter($rows,static function($row)use($filters){
            if($filters['status']!=='all'&&$row['status']!==$filters['status'])return false;
            if($filters['group']!==''&&!in_array($filters['group'],$row['groups'],true))return false;
            if($filters['quality']!=='all'&&$row['quality']!==$filters['quality'])return false;
            return $filters['search']===''||preg_match('/'.preg_quote($filters['search'],'/').'/iu',implode(' ',array_merge(array($row['name'],(string)$row['userId']),$row['groups'])))===1;
        }));
        usort($rows,static function($a,$b)use($filters){
            $sort=$filters['sort'];$direction=$filters['direction']==='desc'?-1:1;
            if(in_array($sort,array('cpm','accuracy'),true)){
                if(($a['status']==='absent')!==($b['status']==='absent'))return $a['status']==='absent'?1:-1;
                $key=$sort==='cpm'?'effectiveCpm':'inputAccuracy';$order=$a[$key]<=>$b[$key];
            }elseif($sort==='status'){$order=array('running'=>0,'absent'=>1,'finished'=>2)[$a['status']]<=>array('running'=>0,'absent'=>1,'finished'=>2)[$b['status']];}
            else{$order=strnatcasecmp($a['name'],$b['name']);}
            return $order*$direction?:strnatcasecmp($a['name'],$b['name'])?:$a['userId']<=>$b['userId'];
        });
        $pagination=Page::meta(count($rows),$page,$size);
        return array('evaluation'=>self::evaluation_view($evaluation),'summary'=>$summary,'comparison'=>$comparison,'groups'=>$groups,'filters'=>$filters,
            'rows'=>$export?$rows:array_slice($rows,$pagination['offset'],$pagination['perPage']),'pagination'=>$pagination);
    }
    private static function result_filters(array $filters): array|\WP_Error {
        $values=array('status'=>'all','quality'=>'all','group'=>'','search'=>'','sort'=>'name','direction'=>'asc');
        foreach($values as $key=>$default){if(isset($filters[$key])&&!is_string($filters[$key]))return self::error('결과 조회 조건을 확인하세요.');$values[$key]=$filters[$key]??$default;}
        if(!in_array($values['status'],array('all','finished','running','absent'),true)||!in_array($values['quality'],array('all','qualified','below','review'),true)
            ||!in_array($values['sort'],array('name','cpm','accuracy','status'),true)||!in_array($values['direction'],array('asc','desc'),true)
            ||count(Rules::chars($values['search']))>100||count(Rules::chars($values['group']))>191)return self::error('결과 조회 조건을 확인하세요.');
        $values['search']=trim(sanitize_text_field($values['search']));return $values;
    }
    public static function leaderboard(string $sample,$page=1,$size=20,string $version=Rules::VERSION,string $lineEnding='enter'): array|\WP_Error {
        global $wpdb;$ids=array_column(self::samples(),'id');if(!in_array($sample,$ids,true))return self::error('검정 예제를 선택하세요.');
        if(!Rules::supports($version))return self::error('검정 채점 버전을 확인하세요.');
        if(!in_array($lineEnding,array('enter','space-enter'),true))return self::error('줄 끝 입력 설정을 확인하세요.');
        if($lineEnding==='space-enter')$sample.=':space-enter';
        $table=Repository::table('runs');
        $due=$wpdb->get_results($wpdb->prepare('SELECT '.Repository::summary_columns()." FROM {$table} WHERE evaluation_id=0 AND version=%s AND challenge=%s AND status='running' AND deadline_at<%s LIMIT 2000",$version,$sample,gmdate('Y-m-d H:i:s',time()-3)),ARRAY_A);
        if(!is_array($due))return self::error('종료된 검정 결과를 확정하지 못했습니다.',500);
        foreach($due as $run)Repository::settle_summary($run);
        $rows=$wpdb->get_results($wpdb->prepare('SELECT '.Repository::summary_columns()." FROM {$table} WHERE evaluation_id=0 AND version=%s AND challenge=%s AND status='finished' AND qualified=1 ORDER BY effective_cpm DESC,input_accuracy DESC,id ASC",$version,$sample),ARRAY_A);
        if(!is_array($rows))return self::error('새 검정 순위를 읽지 못했습니다.',500);
        $best=array();foreach($rows as $row)if(!isset($best[$row['user_id']]))$best[$row['user_id']]=$row;
        $rows=array_values($best);$leaders=array();$personal=null;$previous=null;$rank=0;
        foreach($rows as $i=>$row){$m=self::result_row($row);$key=$m['effectiveCpm'].':'.$m['inputAccuracy'];if($key!==$previous)$rank=$i+1;$previous=$key;
            $u=get_userdata((int)$row['user_id']);$name=(int)$row['user_id']===get_current_user_id()?'나':(Capabilities::can_manage()&&$u?$u->display_name:'도전자');
            $entry=array('rank'=>$rank,'name'=>$name,'playerName'=>$name,'score'=>$m['effectiveCpm'],'accuracy'=>$m['inputAccuracy'],'effectiveCpm'=>$m['effectiveCpm'],'durationSeconds'=>$m['durationSeconds'],'isMe'=>(int)$row['user_id']===get_current_user_id())+\EduGames\Rankings\Record_Moderation::fields('typing',(int)$row['id'],'code-typing');
            if((int)$row['user_id']===get_current_user_id())$personal=$entry;$leaders[]=$entry;
        }
        $pagination=Page::meta(count($leaders),$page,$size);
        return array('version'=>$version,'leaderboard'=>array_slice($leaders,$pagination['offset'],$pagination['perPage']),'pagination'=>$pagination,'myRank'=>$personal['rank']??null,
            'overview'=>array('participants'=>count($leaders),'topScore'=>$leaders[0]['score']??0,'personal'=>$personal));
    }
}
