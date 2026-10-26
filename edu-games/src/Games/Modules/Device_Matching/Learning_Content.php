<?php
declare(strict_types=1);
namespace EduGames\Games\Modules\Device_Matching;
use EduGames\Learning\Question as Q;

final class Learning_Content {
    public static function questions(): array {
        $cases=array(
            array('keyboard','문자를 입력한다.','input'),array('mouse','포인터 위치와 클릭을 입력한다.','input'),
            array('webcam','영상 데이터를 입력한다.','input'),array('light-sensor','주변 밝기를 측정한다.','input'),
            array('ultrasonic-sensor','반사되어 돌아오는 초음파로 거리를 측정한다.','input'),
            array('cpu','명령을 해석하고 연산한다.','process'),array('graphics-card','영상 처리를 병렬 연산한다.','process'),
            array('ram','실행 중인 프로그램과 데이터를 일시적으로 보관한다.','storage'),array('usb-drive','전원이 없어도 파일을 보관한다.','storage'),
            array('monitor','영상 신호를 화면으로 보여준다.','output'),array('printer','디지털 내용을 종이에 출력한다.','output'),
            array('led','빛으로 상태를 표시한다.','output'),array('buzzer','소리로 상태를 알린다.','output'),
            array('servo-motor','명령에 맞는 각도로 축을 움직인다.','motion'),array('stepper-motor','일정한 단계씩 회전한다.','motion'),
            array('3d-printer','재료를 층층이 쌓아 입체물을 만든다.','output'),array('router','여러 기기를 네트워크에 연결하고 패킷을 전달한다.','network'),
            array('arduino-uno','센서 값을 읽고 제어 프로그램을 실행한다.','process'),array('microbit','입력에 따라 제어 프로그램을 실행한다.','process'),
            array('raspberry-pi','운영체제 위에서 프로그램을 실행하는 소형 컴퓨터다.','process'),
            array('desktop-computer','화면 장치가 아닌 독립된 본체에서 프로그램을 실행한다.','process'),
            array('laptop','접히는 화면과 키보드를 갖춘 휴대용 컴퓨터다.','process'),array('tablet','터치 화면을 중심으로 프로그램을 실행하는 휴대용 컴퓨터다.','process'),
            array('breadboard','납땜 없이 부품과 전선을 임시로 연결한다.','connect'),
            array('temperature-humidity-sensor','교실 공기의 온도와 상대 습도를 측정한다.','input'),
            array('pir-motion-sensor','적외선의 변화를 받아 사람의 움직임을 감지한다.','input'),
            array('temperature-probe','방수 금속 탐침으로 수조의 물 온도를 측정한다.','input'),
            array('gas-sensor','대상 가스에 반응해 달라진 전기 신호를 읽는다.','input'),
            array('force-sensor','감지 면을 눌렀는지와 누르는 대략적인 세기를 알아낸다.','input'),
            array('tilt-sensor','물체의 기울어짐에 따른 접점 상태 변화를 읽는다.','input'),
            array('reed-switch','자석이 가까워졌을 때 바뀌는 접점 상태를 읽는다.','input'),
            array('load-cell','무게에 따라 달라지는 미세한 변형을 측정한다.','input'),
            array('rotary-encoder','손으로 돌린 축의 방향과 회전량을 읽는다.','input'),
            array('dc-motor','전기 에너지로 바퀴에 연결한 축을 회전시킨다.','motion'),
            array('vibration-motor','치우친 추를 돌려 조작기를 떨리게 한다.','motion'),
            array('cooling-fan','날개를 회전시켜 공기를 움직인다.','motion'),
            array('water-pump','모터를 이용해 수조의 물을 순환시킨다.','motion'),
            array('linear-actuator','축을 직선으로 밀고 당긴다.','motion'),
        );
        $devices=Device_Matching_Module::rules()['devices'];$byId=array_column($devices,null,'id');$out=array();
        $roles=array('input'=>'입력·측정','process'=>'처리·프로그램 실행','storage'=>'저장','output'=>'정보·물체 출력','motion'=>'물리적 움직임','network'=>'네트워크 통신','connect'=>'회로 연결');
        foreach($cases as $i=>$case){[$id,$task,$role]=$case;$device=$byId[$id];
            $out[]=Q::make('function-'.$id,'장치 기능',$i<17?'easy':'normal','다음 상황에서 강조한 역할을 고르세요. 복합 장치가 가진 모든 기능을 고르는 문제는 아닙니다. '.$task,array('rows'=>array(array('장치'=>$device['name']))),array(Q::field('role','상황에서의 역할','choice',$roles)),array('role'=>array($role)),$device['name'].'의 이 상황에서의 역할은 '.$roles[$role].'입니다. '.$device['description'],'데이터를 받아들이는지, 처리·보관하는지, 결과를 내보내는지 구별하세요.',array('type'=>'device','image'=>plugins_url('assets/device-matching/images/'.$id.'.jpg',EDU_GAMES_FILE),'alt'=>$device['name']));
        }
        return $out;
    }
}
