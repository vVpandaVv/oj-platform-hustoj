<?php
declare(strict_types=1);

namespace EduGames\Games\Modules\Device_Matching;

use EduGames\Games\Contracts\Game_Module;

final class Device_Matching_Module implements Game_Module {
    public const ID = 'device-matching';
    public const SHORTCODE = 'device_matching';
    public const BLOCK = 'edu-games/device-matching';
    private const REVIEWED_SOURCES = array(
        'laptop'=>array('M2 Macbook Air Starlight model.jpg','CC BY-SA 4.0','KKPCW (Kyu3)'),
        'tablet'=>array('IPad Air 11-inch (M2) front side (20250525 154539).jpg','CC BY-SA 4.0','茅野ふたば'),
        'ram'=>array('2023 Pamięci Corsair Vengeance RGB.jpg','CC BY-SA 4.0','Jacek Halicki'),
        'keyboard'=>array('Logitech MX Keys YR0073 Wireless Keyboard.jpg','CC BY-SA 4.0','TaurusEmerald'),
        'webcam'=>array('Logitech Brio 301 webcam HS3.jpg','CC BY 4.0','Hayden Schiff'),
        '3d-printer'=>array('DG 東莞 DongGuan 南城區 Nancheng 鴻福路 HongFu Road 東莞京東商場 JD Mall Electronics Showroom 3D printers December 2024 R12S 06 (cropped).jpg','CC0','NAS 238 WMMNO'),
    );

    public function id(): string {
        return self::ID;
    }

    public function definition(): array {
        return array(
            'id' => $this->id(),
            'title' => __('장치 카드 매칭', 'edu-games'),
            'category' => 'hardware',
            'description' => __('컴퓨팅 장치와 피지컬 컴퓨팅 장치 사진을 이름·설명 카드와 짝지어 찾는 카드 뒤집기 게임입니다.', 'edu-games'),
            'status' => 'playable',
            'statusLabel' => __('플레이 가능', 'edu-games'),
            'block' => self::BLOCK,
            'shortcode' => self::SHORTCODE,
            'rules' => self::rules(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array {
        return array(
            'stages' => array(
                array('stage' => 1, 'pairs' => 6),
                array('stage' => 2, 'pairs' => 8),
                array('stage' => 3, 'pairs' => 10),
                array('stage' => 4, 'pairs' => 12),
            ),
            'maxStars' => 3,
            'stageRating' => array(
                'twoStarMaxMistakes' => 2,
                'oneStarMaxMistakes' => 4,
            ),
            'mismatchRevealMs' => 950,
            'scoring' => array(
                'matchBase' => 100,
                'stageBonusPerMatch' => 15,
                'streakBonus' => 10,
                'maxStreakBonus' => 60,
                'mismatchPenalty' => 20,
                'stageClearBaseBonus' => 100,
                'starBonus' => 60,
                'completionBonus' => 300,
            ),
            'devices' => self::devices(),
        );
    }

    /**
     * @return array<int, array<string, string>>
     */
    private static function devices(): array {
        return array(
            self::device('desktop-computer', __('데스크톱 컴퓨터', 'edu-games'), __('책상 위에서 사용하는 본체와 입출력 장치를 갖춘 컴퓨터입니다.', 'edu-games'), 'computing', __('컴퓨팅 장치', 'edu-games'), 'RGB_desktop_computer_setup_with_keyboard_and_monitor.jpg', 'CC0', 'SankalpSasnur'),
            self::device('laptop', __('노트북', 'edu-games'), __('화면, 키보드, 배터리가 하나로 결합된 휴대용 컴퓨터입니다.', 'edu-games'), 'computing', __('컴퓨팅 장치', 'edu-games'), 'Notebook_computer.jpg', 'CC BY-SA 4.0', 'KK IN HK'),
            self::device('tablet', __('태블릿', 'edu-games'), __('터치 화면으로 조작하는 얇은 휴대용 컴퓨팅 장치입니다.', 'edu-games'), 'computing', __('컴퓨팅 장치', 'edu-games'), 'Galaxy_Tab.jpg', 'CC BY-SA 4.0', 'KA KIT'),
            self::device('cpu', __('CPU', 'edu-games'), __('명령을 해석하고 계산을 수행하는 컴퓨터의 핵심 처리 장치입니다.', 'edu-games'), 'component', __('컴퓨터 부품', 'edu-games'), 'AMD_Ryzen_9_7900X.jpg', 'CC BY-SA 4.0', 'CristoCalis'),
            self::device('ram', __('메모리', 'edu-games'), __('실행 중인 프로그램과 데이터를 임시로 저장하는 주기억 장치입니다.', 'edu-games'), 'component', __('컴퓨터 부품', 'edu-games'), 'DDR5_SDRAM_IMGP6295_smial_wp.jpg', 'FAL', 'Smial'),
            self::device('graphics-card', __('그래픽카드', 'edu-games'), __('화면에 표시할 그래픽 연산을 담당하는 확장 장치입니다.', 'edu-games'), 'component', __('컴퓨터 부품', 'edu-games'), 'Asus_Strix_RTX_4090.jpg', 'CC BY-SA 4.0', 'Benlisquare'),
            self::device('keyboard', __('키보드', 'edu-games'), __('글자, 숫자, 명령을 입력하는 대표적인 입력 장치입니다.', 'edu-games'), 'peripheral', __('주변 장치', 'edu-games'), 'Mechanical_Keyboard.jpg', 'CC BY-SA 4.0', 'SolarMainframe'),
            self::device('mouse', __('마우스', 'edu-games'), __('포인터를 움직이고 화면의 대상을 선택하는 입력 장치입니다.', 'edu-games'), 'peripheral', __('주변 장치', 'edu-games'), 'A_wireless_computer_mouse.jpg', 'CC BY-SA 4.0', 'Pixloom'),
            self::device('monitor', __('모니터', 'edu-games'), __('컴퓨터의 화면 정보를 보여주는 출력 장치입니다.', 'edu-games'), 'peripheral', __('주변 장치', 'edu-games'), 'Computer_Screen_Monitor.jpg', 'CC BY-SA 4.0', 'U3211603'),
            self::device('printer', __('프린터', 'edu-games'), __('문서나 이미지를 종이에 출력하는 주변 장치입니다.', 'edu-games'), 'peripheral', __('주변 장치', 'edu-games'), 'HP_LaserJet_Enterprise_M558_01.jpg', 'CC BY-SA 4.0', 'Achim Raschka'),
            self::device('webcam', __('웹캠', 'edu-games'), __('영상을 촬영해 컴퓨터로 전달하는 카메라 입력 장치입니다.', 'edu-games'), 'peripheral', __('주변 장치', 'edu-games'), 'Logitech_Webcam.png', 'CC BY-SA 4.0', 'IT Photography'),
            self::device('router', __('무선 공유기', 'edu-games'), __('가정이나 교실의 여러 장치를 무선 네트워크와 인터넷에 연결해 주는 장치입니다.', 'edu-games'), 'network', __('네트워크 장치', 'edu-games'), 'Wireless_Router_(50841204223).jpg', 'CC BY 2.0', 'ajay_suresh'),
            self::device('usb-drive', __('USB 저장장치', 'edu-games'), __('파일을 저장하고 옮길 때 사용하는 휴대용 보조 기억 장치입니다.', 'edu-games'), 'storage', __('저장 장치', 'edu-games'), 'SanDisk-Cruzer-USB-4GB-ThumbDrive.jpg', 'Public domain', 'Evan-Amos'),
            self::device('3d-printer', __('3D 프린터', 'edu-games'), __('디지털 모델을 층층이 쌓아 입체 물체로 출력하는 장치입니다.', 'edu-games'), 'output', __('출력 장치', 'edu-games'), 'Imprimante_3D_Prusa.jpg', 'CC0', 'Marinos2.0'),
            self::device('arduino-uno', __('아두이노 우노', 'edu-games'), __('센서와 액추에이터를 연결해 작품을 제어하는 마이크로컨트롤러 보드입니다.', 'edu-games'), 'physical-computing', __('피지컬 컴퓨팅', 'edu-games'), 'Arduino_UNO_R4_WiFi.jpg', 'CC BY-SA 4.0', 'Lomrjyo'),
            self::device('microbit', __('마이크로비트', 'edu-games'), __('LED, 버튼, 센서가 포함된 교육용 마이크로컨트롤러 보드입니다.', 'edu-games'), 'physical-computing', __('피지컬 컴퓨팅', 'edu-games'), 'BBC_micro_bit_v2.jpg', 'CC BY 4.0', 'SimonWaldherr'),
            self::device('raspberry-pi', __('라즈베리 파이', 'edu-games'), __('작은 보드 한 장에 컴퓨터 기능을 담은 싱글 보드 컴퓨터입니다.', 'edu-games'), 'physical-computing', __('피지컬 컴퓨팅', 'edu-games'), 'Raspberry_Pi_5_Board.jpg', 'CC BY-SA 4.0', 'SimonWaldherr'),
            self::device('breadboard', __('브레드보드', 'edu-games'), __('납땜 없이 전자 부품과 점퍼선을 꽂아 회로를 구성하는 판입니다.', 'edu-games'), 'physical-computing', __('피지컬 컴퓨팅', 'edu-games'), 'Electronics-White-Breadboard.jpg', 'Public domain', 'Evan-Amos'),
            self::device('ultrasonic-sensor', __('초음파 센서', 'edu-games'), __('초음파가 되돌아오는 시간을 이용해 거리를 측정하는 센서입니다.', 'edu-games'), 'sensor', __('센서', 'edu-games'), 'HC_SR04_Ultrasonic_sensor_1480319_20_21_HDR_Enhancer.jpg', 'CC BY-SA 3.0', 'Nevit Dilmen'),
            self::device('light-sensor', __('조도 센서', 'edu-games'), __('빛의 밝기에 따라 저항값이 달라져 주변 밝기를 감지하는 센서입니다.', 'edu-games'), 'sensor', __('센서', 'edu-games'), '25mm_light-dependent_resistor_(LDR)_(5).jpg', 'CC BY-SA 4.0', 'Suyash Dwivedi'),
            self::device('servo-motor', __('서보 모터', 'edu-games'), __('정해진 각도로 회전하도록 제어할 수 있는 액추에이터입니다.', 'edu-games'), 'actuator', __('액추에이터', 'edu-games'), 'Servo_motor_1530625.JPG', 'CC BY-SA 3.0', 'Nevit Dilmen'),
            self::device('stepper-motor', __('스테퍼 모터', 'edu-games'), __('작은 각도 단위로 단계적으로 회전하는 정밀 제어 모터입니다.', 'edu-games'), 'actuator', __('액추에이터', 'edu-games'), 'Nema_17_Stepper_Motor.jpg', 'CC BY-SA 2.0', 'oomlout'),
            self::device('led', __('LED', 'edu-games'), __('전류가 흐르면 빛을 내는 작은 출력 부품입니다.', 'edu-games'), 'output', __('출력 장치', 'edu-games'), 'Electronic-Component-Red-LED.jpg', 'Public domain', 'Evan-Amos'),
            self::device('buzzer', __('부저', 'edu-games'), __('전기 신호를 소리로 바꾸어 알림음을 내는 출력 장치입니다.', 'edu-games'), 'output', __('출력 장치', 'edu-games'), 'Electromagnetic_buzzer_02.jpg', 'CC BY-SA 3.0', 'jdx'),
            self::device('temperature-humidity-sensor', __('온습도 센서', 'edu-games'), __('공기의 온도와 상대 습도를 함께 측정합니다. 온실이나 교실의 환경을 살피는 데 쓰입니다.', 'edu-games'), 'sensor', __('센서', 'edu-games'), 'DHT22-Temperatur-Sensor.jpg', 'CC0', 'Ubahnverleih'),
            self::device('pir-motion-sensor', __('PIR 움직임 센서', 'edu-games'), __('몸에서 나오는 적외선의 변화를 감지합니다. 사람이 움직일 때 자동 조명을 켜는 데 쓰입니다.', 'edu-games'), 'sensor', __('센서', 'edu-games'), 'PIR Motion Detector.jpg', 'CC BY-SA 2.0', 'oomlout'),
            self::device('temperature-probe', __('방수 온도 센서', 'edu-games'), __('금속 탐침 부분의 온도를 측정합니다. 수조의 물 온도처럼 물에 닿는 곳을 측정할 때 쓰입니다.', 'edu-games'), 'sensor', __('센서', 'edu-games'), 'Sensor DS18B20.jpg', 'CC BY-SA 4.0', 'Leonardo Postay'),
            self::device('gas-sensor', __('가스 센서', 'edu-games'), __('감지 대상 가스에 반응해 전기 신호가 달라집니다. 모든 종류의 가스를 구별하는 센서는 아닙니다.', 'edu-games'), 'sensor', __('센서', 'edu-games'), 'Gas-Sensor.jpg', 'CC BY-SA 3.0', 'Drahkrub'),
            self::device('force-sensor', __('누름 힘 센서', 'edu-games'), __('얇은 감지 면을 누르면 저항이 달라집니다. 눌림의 유무와 대략적인 세기를 알아냅니다.', 'edu-games'), 'sensor', __('센서', 'edu-games'), 'Force Sensing Resistor cropped.jpg', 'CC BY-SA 2.0', 'oomlout; cropped by Bomazi'),
            self::device('tilt-sensor', __('기울기 스위치 센서', 'edu-games'), __('기울어짐에 따라 내부 접점 상태가 바뀝니다. 정밀한 각도 대신 물체가 기울었는지를 감지합니다.', 'edu-games'), 'sensor', __('센서', 'edu-games'), 'Digital Tilt Sensor Forceup 1480265 6 7 HDR Enhancer.jpg', 'CC BY-SA 3.0', 'Nevit Dilmen'),
            self::device('reed-switch', __('자석 감지 리드 스위치', 'edu-games'), __('자석이 가까워지면 유리관 안의 접점 상태가 바뀝니다. 문이 열렸는지 감지하는 데 쓰입니다.', 'edu-games'), 'sensor', __('센서', 'edu-games'), 'Reed switch (aka).jpg', 'CC BY-SA 2.5', 'André Karwath aka Aka'),
            self::device('load-cell', __('무게 센서(로드셀)', 'edu-games'), __('힘을 받아 생기는 미세한 변형을 전기 신호로 바꿉니다. 전자저울에서 무게를 측정하는 데 쓰입니다.', 'edu-games'), 'sensor', __('센서', 'edu-games'), 'Load Cell Sensor Module from a Digital Weighing Scale 02.jpg', 'CC BY-SA 4.0', 'Gannu03'),
            self::device('rotary-encoder', __('회전 감지 엔코더', 'edu-games'), __('축을 돌릴 때 생기는 신호로 회전 방향과 움직인 양을 알아냅니다. 스스로 축을 돌리는 모터는 아닙니다.', 'edu-games'), 'sensor', __('센서', 'edu-games'), 'Rotary Encoder - Illuminated (RGB) (33888650838).jpg', 'CC BY 2.0', 'SparkFun Electronics'),
            self::device('dc-motor', __('DC 모터', 'edu-games'), __('직류 전원을 받아 축을 계속 회전시킵니다. 바퀴 등을 움직이며, 각도 제어에는 추가 장치가 필요합니다.', 'edu-games'), 'actuator', __('액추에이터', 'edu-games'), '3V DC Motor.jpg', 'CC BY-SA 3.0', 'Dcaldero8983'),
            self::device('vibration-motor', __('진동 모터', 'edu-games'), __('한쪽으로 치우친 추를 회전시켜 떨림을 만듭니다. 게임 조작기 등의 진동 알림에 쓰입니다.', 'edu-games'), 'actuator', __('액추에이터', 'edu-games'), 'Vibration motors.jpg', 'CC BY-SA 3.0', 'Tiia Monto'),
            self::device('cooling-fan', __('냉각 팬', 'edu-games'), __('모터로 날개를 돌려 공기를 움직입니다. 전자 장치의 열을 식히며 온도를 측정하는 센서는 아닙니다.', 'edu-games'), 'actuator', __('액추에이터', 'edu-games'), '11718 - DC Brushless Fan - 80x80x15mm (12V).jpg', 'CC BY 2.0', 'SparkFun Electronics'),
            self::device('water-pump', __('수중 펌프', 'edu-games'), __('물속에서 모터를 돌려 물을 이동시킵니다. 수조의 물을 순환시키는 것처럼 액체를 움직이는 데 쓰입니다.', 'edu-games'), 'actuator', __('액추에이터', 'edu-games'), 'Tauchpumpe EDEN 104s 2007-10-14 aa EDIT CUT 1944x1944.jpg', 'CC BY 3.0', 'User:Zapyon'),
            self::device('linear-actuator', __('직선 액추에이터', 'edu-games'), __('전기 에너지를 이용해 축을 직선으로 밀거나 당깁니다. 회전 대신 앞뒤 움직임이 필요한 곳에 쓰입니다.', 'edu-games'), 'actuator', __('액추에이터', 'edu-games'), 'LCA25.jpg', 'CC BY-SA 3.0', 'smacmca'),
        );
    }

    /**
     * @return array<string, string>
     */
    private static function device(
        string $id,
        string $name,
        string $description,
        string $category,
        string $category_label,
        string $source_file,
        string $license,
        string $author
    ): array {
        if(isset(self::REVIEWED_SOURCES[$id])){[$source_file,$license,$author]=self::REVIEWED_SOURCES[$id];}
        return array(
            'id' => $id,
            'name' => $name,
            'description' => $description,
            'category' => $category,
            'categoryLabel' => $category_label,
            'image' => self::image_url($id),
            'imageAlt' => sprintf(__('%s 사진', 'edu-games'), $name),
            'sourceFile' => $source_file,
            'sourceUrl' => 'https://commons.wikimedia.org/wiki/File:' . rawurlencode($source_file),
            'license' => $license,
            'author' => $author,
        );
    }

    private static function image_url(string $id): string {
        return plugins_url('assets/device-matching/images/' . $id . '.jpg', EDU_GAMES_FILE).(isset(self::REVIEWED_SOURCES[$id])?'?v=20260911':'');
    }
}
