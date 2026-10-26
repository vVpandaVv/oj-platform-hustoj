<?php
declare(strict_types=1);
namespace EduGames\Progress;
use EduGames\Games\Modules\Image_Data\Image_Data_Module;
use EduGames\Games\Modules\Code_Typing\Code_Typing_Module;

final class Validator {
    public static function entries(string $game, array $p): array {
        if ($game==='image-data') {
            $samples=array_column(Image_Data_Module::rules()['samples'],null,'id');
            $id=$p['sampleId']??null;
            if (!is_string($id)||!isset($samples[$id])||!is_array($p['pixels']??null)||!array_is_list($p['pixels'])) throw new \InvalidArgumentException('복원할 그림을 확인하세요.');
            $expected=implode('',$samples[$id]['data']);
            $pixels=$p['pixels'];
            if (count($pixels)!==strlen($expected)) throw new \InvalidArgumentException('모든 칸을 복원해야 합니다.');
            foreach($pixels as $i=>$value) if (!is_scalar($value)||(string)$value!==$expected[$i]) throw new \InvalidArgumentException('색상 배열과 복원 결과가 다릅니다.');
            return array($id=>1);
        }
        if ($game==='code-typing') {
            if (isset($p['scoreId'])) {
                $row=self::score($game,$p['scoreId']); $meta=json_decode($row['meta'],true);
                $rules=Code_Typing_Module::rules(); $limit=$rules['modes']['assessment']['timeLimitSeconds'];
                if(($meta['mode']??'')!=='assessment'||!isset($rules['languages'][$meta['language']??''])||(int)$row['duration_seconds']!==$limit||(float)$row['accuracy']<97) throw new \InvalidArgumentException('제한시간을 마친 정확도 97% 이상 검정만 최고 속도로 저장합니다.');
                return array($meta['language'].':assessment:'.$limit=>min(6000,(int)round((float)($meta['cpm']??0))));
            }
            $rules=Code_Typing_Module::rules(); $key=$p['key']??null; $input=$p['input']??null;
            if (!is_string($key)||strlen($key)>120||!is_string($input)||strlen($input)>40000) throw new \InvalidArgumentException('타이핑 기록을 확인하세요.');
            $parts=explode(':',$key); if(count($parts)!==3||!isset($rules['languages'][$parts[0]],$rules['modes'][$parts[1]])) throw new \InvalidArgumentException('연습 종류를 확인하세요.');
            if ($parts[1]==='keyword'&&$parts[2]==='streak') {
                $target=$p['target']??null; $streak=$p['streak']??null;
                if(!is_string($target)||strlen($target)>3000||$input!==$target||!is_int($streak)||$streak<1||$streak>10000) throw new \InvalidArgumentException('완료된 키워드 연습만 기록합니다.');
                $words=preg_split('/\s+/',trim($target));
                $bank=$rules['keywordBank'][$parts[0]];
                foreach($rules['samples'] as $sample)if($sample['language']===$parts[0]&&$sample['mode']==='keyword')$bank=array_merge($bank,preg_split('/\s+/',trim($sample['content'])));
                if(count($words)<8||count($words)>32||array_diff($words,$bank)) throw new \InvalidArgumentException('키워드 목록을 확인하세요.');
                return array($key=>$streak);
            }
            $samples=array_column($rules['samples'],null,'id'); $id=$p['sampleId']??null; $sample=is_string($id)?($samples[$id]??null):null;
            if(!$sample||$sample['language']!==$parts[0]||$sample['mode']!==$parts[1]||$parts[1]==='assessment'||$parts[2]!==$sample['id']) throw new \InvalidArgumentException('코드 예제를 확인하세요.');
            $target=preg_split('//u',$sample['content'],-1,PREG_SPLIT_NO_EMPTY); $typed=preg_split('//u',$input,-1,PREG_SPLIT_NO_EMPTY);
            $seconds=$p['seconds']??null;
            if(!is_array($typed)||count($typed)<count($target)||!is_numeric($seconds)||!is_finite((float)$seconds)||$seconds<1||$seconds>86400) throw new \InvalidArgumentException('완료된 코드와 소요 시간을 확인하세요.');
            $correct=0; foreach($target as $i=>$char) if($typed[$i]===$char)$correct++;
            if(!$target||$correct/count($typed)<.97) throw new \InvalidArgumentException('정확도 97% 이상인 완료 기록만 저장합니다.');
            return array($key=>min(6000,(int)round($correct*60/(float)$seconds)));
        }
        if ($game==='device-matching') {
            $row=self::score($game,$p['scoreId']??null);
            $meta=json_decode($row['meta'],true);
            if(($meta['completedStages']??0)!==4) throw new \InvalidArgumentException('모든 단계를 완료해야 합니다.');
            return array('stars'=>min(12,max(0,(int)($meta['totalStars']??0))),'mistakes'=>max(0,(int)($meta['mistakes']??0)));
        }
        throw new \InvalidArgumentException('이 게임은 서버 점수에서 개인 기록을 조회합니다.');
    }
    private static function score(string $game, $id): array {
        global $wpdb;
        if(!is_int($id)||$id<1) throw new \InvalidArgumentException('저장된 게임 기록이 필요합니다.');
        $row=$wpdb->get_row($wpdb->prepare('SELECT meta, accuracy, duration_seconds FROM '.\EduGames\Database\Installer::scores_table().' WHERE id=%d AND user_id=%d AND game_slug=%s',$id,get_current_user_id(),$game),ARRAY_A);
        if(!$row) throw new \InvalidArgumentException('자신의 게임 기록만 사용할 수 있습니다.');
        return $row;
    }
}
