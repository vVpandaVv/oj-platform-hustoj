<?php
declare(strict_types=1);

namespace EduGames\Games\Modules\Missing_Values;

use EduGames\Games\Contracts\Game_Module;

final class Missing_Values_Module implements Game_Module {
    public const ID = 'missing-values';
    public const SHORTCODE = 'missing_values';
    public const BLOCK = 'edu-games/missing-values';

    public function id(): string {
        return self::ID;
    }

    public function definition(): array {
        return array(
            'id' => $this->id(),
            'title' => __('결측값 처리', 'edu-games'),
            'category' => 'data-analysis',
            'description' => __('흐름·분포·종류·주기에 맞는 복원 도구로 세 빈칸을 채우고, 방법마다 달라지는 복원값과 오차를 비교합니다.', 'edu-games'),
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
            'rankedRounds' => 10,
            'defaultDifficulty' => 'easy',
            'methods' => array(
                'mean' => __('평균으로 대체', 'edu-games'),
                'median' => __('중앙값으로 대체', 'edu-games'),
                'mode' => __('최빈값으로 대체', 'edu-games'),
                'interpolate' => __('앞뒤 값으로 보간', 'edu-games'),
                'pattern' => __('규칙으로 추론', 'edu-games'),
                'previous' => __('이전 값 유지', 'edu-games'),
            ),
            'difficulties' => array(
                'easy' => array(
                    'label' => __('기초', 'edu-games'),
                    'description' => __('빈칸 1개와 뚜렷한 대표값을 다룹니다.', 'edu-games'),
                    'baseScore' => 100,
                    'samples' => array('water-mode', 'quiz-median', 'fruit-mode', 'temp-mean', 'attendance-previous', 'shoe-size-mode', 'favorite-color-mode', 'lunch-count-median', 'room-humidity-mean', 'seat-status-previous'),
                ),
                'normal' => array(
                    'label' => __('표준', 'edu-games'),
                    'description' => __('시간 순서와 증가 규칙을 함께 판단합니다.', 'edu-games'),
                    'baseScore' => 135,
                    'samples' => array('sales-interpolate', 'sensor-interpolate', 'study-mode', 'steps-mean', 'book-pattern', 'bike-distance-interpolate', 'practice-pages-pattern', 'cafeteria-demand-mean', 'mood-mode', 'evening-temp-interpolate'),
                ),
                'hard' => array(
                    'label' => __('심화', 'edu-games'),
                    'description' => __('빈칸이 여러 개이거나 방법 선택이 더 중요합니다.', 'edu-games'),
                    'baseScore' => 175,
                    'samples' => array('monthly-pattern-two', 'plant-growth-two', 'inventory-previous', 'lab-median', 'distance-interpolate-two', 'savings-quarter-pattern-two', 'train-position-interpolate-two', 'sensor-status-previous', 'survey-age-median', 'package-scan-pattern-two'),
                ),
            ),
            'scoring' => array(
                'streakBonus' => 10,
                'maxStreakBonus' => 70,
                'wrongPenalty' => 25,
                'multiBlankBonus' => 20,
                'minimumCorrectScore' => 45,
            ),
            'samples' => self::samples(),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function samples(): array {
        return array(
            self::sample(
                'water-mode',
                __('하루 물 마신 컵 수', 'edu-games'),
                __('한 학생이 일주일 동안 마신 물의 컵 수입니다. 평소 패턴을 대표하는 값으로 빈칸을 채우세요.', 'edu-games'),
                __('컵', 'edu-games'),
                array('월', '화', '수', '목', '금', '토', '일'),
                array(7, 8, null, 7, 8, 7, 7),
                array(2 => 7),
                'mode',
                __('7컵이 가장 자주 나타나므로 최빈값으로 채우는 것이 자연스럽습니다.', 'edu-games'),
                'easy',
                'numeric'
            ),
            self::sample(
                'quiz-median',
                __('쪽지시험 점수', 'edu-games'),
                __('대부분의 점수가 비슷하고 극단값이 없는 짧은 점수 자료입니다.', 'edu-games'),
                __('점', 'edu-games'),
                array('1회', '2회', '3회', '4회', '5회'),
                array(6, 7, null, 7, 8),
                array(2 => 7),
                'median',
                __('정렬하면 가운데 값이 7점이므로 중앙값으로 채우면 전체 흐름이 유지됩니다.', 'edu-games'),
                'easy',
                'numeric'
            ),
            self::sample(
                'fruit-mode',
                __('좋아하는 과일 설문', 'edu-games'),
                __('분류형 데이터의 빈칸은 가장 많이 나온 범주로 채우는 경우가 많습니다.', 'edu-games'),
                '',
                array('학생1', '학생2', '학생3', '학생4', '학생5', '학생6'),
                array(__('사과', 'edu-games'), __('배', 'edu-games'), __('사과', 'edu-games'), null, __('사과', 'edu-games'), __('포도', 'edu-games')),
                array(3 => __('사과', 'edu-games')),
                'mode',
                __('분류형 자료에서는 평균을 낼 수 없으므로 가장 자주 나온 사과로 채웁니다.', 'edu-games'),
                'easy',
                'text'
            ),
            self::sample(
                'temp-mean',
                __('교실 온도 기록', 'edu-games'),
                __('같은 시간대에 측정한 교실 온도입니다. 큰 변화가 없어 평균으로 대표값을 정할 수 있습니다.', 'edu-games'),
                __('도', 'edu-games'),
                array('월', '화', '수', '목', '금'),
                array(22, 23, null, 22, 23),
                array(2 => 22.5),
                'mean',
                __('알려진 값의 평균은 22.5도입니다.', 'edu-games'),
                'easy',
                'numeric'
            ),
            self::sample(
                'attendance-previous',
                __('출석 상태 기록', 'edu-games'),
                __('같은 활동을 이어서 진행한 기록입니다. 중간 기록이 빠졌을 때 직전 상태를 유지한다고 볼 수 있습니다.', 'edu-games'),
                '',
                array('1교시', '2교시', '3교시', '4교시', '5교시'),
                array(__('참여', 'edu-games'), __('참여', 'edu-games'), null, __('참여', 'edu-games'), __('참여', 'edu-games')),
                array(2 => __('참여', 'edu-games')),
                'previous',
                __('상태 자료가 계속 이어질 때는 직전 값을 유지하는 방법을 쓸 수 있습니다.', 'edu-games'),
                'easy',
                'text'
            ),
            self::sample(
                'shoe-size-mode',
                __('신발 크기 조사', 'edu-games'),
                __('같은 반 학생들의 신발 크기 중 하나가 비어 있습니다. 가장 자주 나온 값을 대표값으로 사용하세요.', 'edu-games'),
                __('mm', 'edu-games'),
                array('학생1', '학생2', '학생3', '학생4', '학생5', '학생6'),
                array(240, 245, 240, null, 250, 240),
                array(3 => 240),
                'mode',
                __('240mm가 가장 자주 나오므로 최빈값으로 빈칸을 채웁니다.', 'edu-games'),
                'easy',
                'numeric'
            ),
            self::sample(
                'favorite-color-mode',
                __('좋아하는 색 설문', 'edu-games'),
                __('분류형 설문 자료에서 빠진 응답을 가장 많이 나온 범주로 대체합니다.', 'edu-games'),
                '',
                array('학생1', '학생2', '학생3', '학생4', '학생5', '학생6'),
                array(__('파랑', 'edu-games'), __('초록', 'edu-games'), __('파랑', 'edu-games'), null, __('파랑', 'edu-games'), __('노랑', 'edu-games')),
                array(3 => __('파랑', 'edu-games')),
                'mode',
                __('색 이름은 평균을 낼 수 없으므로 가장 많이 나온 파랑으로 채웁니다.', 'edu-games'),
                'easy',
                'text'
            ),
            self::sample(
                'lunch-count-median',
                __('급식 배식 수량', 'edu-games'),
                __('비슷한 규모의 모둠별 배식 수량입니다. 가운데 값을 사용해 빈칸을 안정적으로 채우세요.', 'edu-games'),
                __('개', 'edu-games'),
                array('1모둠', '2모둠', '3모둠', '4모둠', '5모둠'),
                array(28, 30, null, 31, 32),
                array(2 => 30.5),
                'median',
                __('알려진 값을 정렬하면 가운데 두 값 30과 31의 평균인 30.5개가 중앙값입니다.', 'edu-games'),
                'easy',
                'numeric'
            ),
            self::sample(
                'room-humidity-mean',
                __('교실 습도 기록', 'edu-games'),
                __('같은 시간대의 교실 습도 기록입니다. 값들이 비슷하므로 평균으로 대체할 수 있습니다.', 'edu-games'),
                __('%', 'edu-games'),
                array('월', '화', '수', '목', '금'),
                array(42, 44, null, 43, 45),
                array(2 => 43.5),
                'mean',
                __('알려진 네 값의 평균은 43.5%입니다.', 'edu-games'),
                'easy',
                'numeric'
            ),
            self::sample(
                'seat-status-previous',
                __('자리 사용 상태', 'edu-games'),
                __('한 좌석의 사용 상태를 시간 순서로 기록했습니다. 변화가 기록되기 전까지는 직전 상태를 유지합니다.', 'edu-games'),
                '',
                array('09시', '10시', '11시', '12시', '13시'),
                array(__('사용 중', 'edu-games'), __('사용 중', 'edu-games'), null, __('비어 있음', 'edu-games'), __('비어 있음', 'edu-games')),
                array(2 => __('사용 중', 'edu-games')),
                'previous',
                __('11시의 변화 기록이 없으므로 직전 10시의 사용 중 상태를 유지합니다.', 'edu-games'),
                'easy',
                'text'
            ),
            self::sample(
                'sales-interpolate',
                __('가게 매출 추세', 'edu-games'),
                __('날짜 순서가 있는 매출 자료입니다. 앞뒤 값 사이를 자연스럽게 이어 보세요.', 'edu-games'),
                __('만원', 'edu-games'),
                array('월', '화', '수', '목', '금'),
                array(120, 130, null, 150, 160),
                array(2 => 140),
                'interpolate',
                __('130과 150 사이의 중간값인 140만원으로 보간할 수 있습니다.', 'edu-games'),
                'normal',
                'numeric'
            ),
            self::sample(
                'sensor-interpolate',
                __('센서 온도 측정', 'edu-games'),
                __('10분 간격으로 같은 센서가 기록한 값입니다. 시간 순서의 흐름을 이용하세요.', 'edu-games'),
                __('도', 'edu-games'),
                array('00분', '10분', '20분', '30분', '40분'),
                array(5.1, 5.2, null, 5.4, 5.5),
                array(2 => 5.3),
                'interpolate',
                __('5.2와 5.4 사이의 값은 5.3도로 보는 것이 적절합니다.', 'edu-games'),
                'normal',
                'numeric'
            ),
            self::sample(
                'study-mode',
                __('하루 공부 시간', 'edu-games'),
                __('비슷한 생활 패턴이 반복되는 자료입니다. 가장 자주 나온 값을 찾으세요.', 'edu-games'),
                __('시간', 'edu-games'),
                array('월', '화', '수', '목', '금', '토'),
                array(3, 4, 3, null, 3, 4),
                array(3 => 3),
                'mode',
                __('3시간이 가장 많이 나타나므로 최빈값으로 대체합니다.', 'edu-games'),
                'normal',
                'numeric'
            ),
            self::sample(
                'steps-mean',
                __('평일 걸음 수', 'edu-games'),
                __('평일 활동량이 비슷한 사람의 기록입니다. 알려진 값의 평균을 구하세요.', 'edu-games'),
                __('걸음', 'edu-games'),
                array('월', '화', '수', '목', '금'),
                array(7200, 7600, null, 7400, 7800),
                array(2 => 7500),
                'mean',
                __('알려진 네 값의 평균은 7500걸음입니다.', 'edu-games'),
                'normal',
                'numeric'
            ),
            self::sample(
                'book-pattern',
                __('읽은 책 쪽수', 'edu-games'),
                __('매일 같은 양씩 더 읽는 계획표입니다. 증가 규칙을 찾아 빈칸을 채우세요.', 'edu-games'),
                __('쪽', 'edu-games'),
                array('1일', '2일', '3일', '4일', '5일'),
                array(12, 18, null, 30, 36),
                array(2 => 24),
                'pattern',
                __('매일 6쪽씩 늘어나므로 3일째는 24쪽입니다.', 'edu-games'),
                'normal',
                'numeric'
            ),
            self::sample(
                'bike-distance-interpolate',
                __('자전거 이동 거리', 'edu-games'),
                __('일정한 속도로 이동한 자전거의 거리 기록입니다. 앞뒤 값을 이용해 중간 값을 추정하세요.', 'edu-games'),
                __('km', 'edu-games'),
                array('0분', '10분', '20분', '30분', '40분'),
                array(0, 3, null, 9, 12),
                array(2 => 6),
                'interpolate',
                __('10분마다 3km씩 이동하므로 20분의 거리는 6km입니다.', 'edu-games'),
                'normal',
                'numeric'
            ),
            self::sample(
                'practice-pages-pattern',
                __('문제집 풀이 쪽수', 'edu-games'),
                __('매일 정해진 쪽수만큼 더 풀기로 한 기록입니다. 증가 규칙을 찾아보세요.', 'edu-games'),
                __('쪽', 'edu-games'),
                array('1일', '2일', '3일', '4일', '5일'),
                array(5, 10, 15, null, 25),
                array(3 => 20),
                'pattern',
                __('매일 5쪽씩 늘어나는 규칙이므로 4일째는 20쪽입니다.', 'edu-games'),
                'normal',
                'numeric'
            ),
            self::sample(
                'cafeteria-demand-mean',
                __('매점 음료 판매량', 'edu-games'),
                __('비슷한 날씨의 평일 음료 판매량입니다. 큰 차이가 없으므로 평균으로 빈칸을 채웁니다.', 'edu-games'),
                __('병', 'edu-games'),
                array('월', '화', '수', '목', '금'),
                array(36, 40, null, 38, 42),
                array(2 => 39),
                'mean',
                __('알려진 값 36, 40, 38, 42의 평균은 39병입니다.', 'edu-games'),
                'normal',
                'numeric'
            ),
            self::sample(
                'mood-mode',
                __('오늘의 기분 기록', 'edu-games'),
                __('하루 동안 여러 번 기록한 기분 상태입니다. 분류형 자료이므로 최빈값을 사용합니다.', 'edu-games'),
                '',
                array('아침', '1교시', '점심', '오후', '방과후', '저녁'),
                array(__('좋음', 'edu-games'), __('좋음', 'edu-games'), __('보통', 'edu-games'), null, __('좋음', 'edu-games'), __('보통', 'edu-games')),
                array(3 => __('좋음', 'edu-games')),
                'mode',
                __('좋음이 가장 많이 나타나므로 최빈값으로 대체합니다.', 'edu-games'),
                'normal',
                'text'
            ),
            self::sample(
                'evening-temp-interpolate',
                __('저녁 기온 변화', 'edu-games'),
                __('저녁 시간대에 일정하게 내려가는 기온 기록입니다. 앞뒤 값을 이어 빈칸을 채우세요.', 'edu-games'),
                __('도', 'edu-games'),
                array('18시', '19시', '20시', '21시', '22시'),
                array(24, 22, null, 18, 16),
                array(2 => 20),
                'interpolate',
                __('22도와 18도 사이의 자연스러운 중간 값은 20도입니다.', 'edu-games'),
                'normal',
                'numeric'
            ),
            self::sample(
                'monthly-pattern-two',
                __('월별 저축 금액', 'edu-games'),
                __('매달 같은 금액만큼 더 저축한 기록입니다. 두 빈칸을 모두 채우세요.', 'edu-games'),
                __('만원', 'edu-games'),
                array('1월', '2월', '3월', '4월', '5월', '6월'),
                array(10, 20, null, 40, null, 60),
                array(2 => 30, 4 => 50),
                'pattern',
                __('10만원씩 증가하는 규칙이므로 3월은 30만원, 5월은 50만원입니다.', 'edu-games'),
                'hard',
                'numeric'
            ),
            self::sample(
                'plant-growth-two',
                __('식물 키 성장 기록', 'edu-games'),
                __('식물이 일정한 속도로 자란다고 보고 빠진 측정값을 보간하세요.', 'edu-games'),
                __('cm', 'edu-games'),
                array('1일', '2일', '3일', '4일', '5일', '6일'),
                array(4, null, 8, null, 12, 14),
                array(1 => 6, 3 => 10),
                'interpolate',
                __('앞뒤 측정값 사이를 이어 보면 2일은 6cm, 4일은 10cm입니다.', 'edu-games'),
                'hard',
                'numeric'
            ),
            self::sample(
                'inventory-previous',
                __('창고 재고 상태', 'edu-games'),
                __('입출고가 없었던 시간대의 재고 상태는 직전 기록을 유지한다고 봅니다.', 'edu-games'),
                __('개', 'edu-games'),
                array('09시', '10시', '11시', '12시', '13시', '14시'),
                array(32, 32, null, null, 28, 28),
                array(2 => 32, 3 => 32),
                'previous',
                __('새로운 변화가 기록되기 전까지는 직전 재고 32개를 유지합니다.', 'edu-games'),
                'hard',
                'numeric'
            ),
            self::sample(
                'lab-median',
                __('실험 반복 측정값', 'edu-games'),
                __('반복 측정값 중 일부가 비어 있습니다. 극단값의 영향을 덜 받는 대표값을 사용하세요.', 'edu-games'),
                __('g', 'edu-games'),
                array('1회', '2회', '3회', '4회', '5회', '6회'),
                array(18.1, 18.3, null, 18.2, 18.4, 28.0),
                array(2 => 18.3),
                'median',
                __('28.0g처럼 튀는 값이 있으므로 중앙값 18.3g이 더 안정적입니다.', 'edu-games'),
                'hard',
                'numeric'
            ),
            self::sample(
                'distance-interpolate-two',
                __('이동 거리 기록', 'edu-games'),
                __('일정한 속도로 이동한 거리 기록입니다. 시간 간격을 이용해 두 빈칸을 채우세요.', 'edu-games'),
                __('m', 'edu-games'),
                array('0초', '10초', '20초', '30초', '40초', '50초'),
                array(0, null, 24, null, 48, 60),
                array(1 => 12, 3 => 36),
                'interpolate',
                __('10초마다 12m씩 이동하므로 10초는 12m, 30초는 36m입니다.', 'edu-games'),
                'hard',
                'numeric'
            ),
            self::sample(
                'savings-quarter-pattern-two',
                __('분기별 저축 누적액', 'edu-games'),
                __('분기마다 같은 금액을 추가로 저축한 누적 기록입니다. 빠진 두 값을 규칙으로 추론하세요.', 'edu-games'),
                __('만원', 'edu-games'),
                array('1분기', '2분기', '3분기', '4분기', '5분기', '6분기'),
                array(15, null, 45, 60, null, 90),
                array(1 => 30, 4 => 75),
                'pattern',
                __('분기마다 15만원씩 늘어나므로 2분기는 30만원, 5분기는 75만원입니다.', 'edu-games'),
                'hard',
                'numeric'
            ),
            self::sample(
                'train-position-interpolate-two',
                __('기차 위치 기록', 'edu-games'),
                __('기차가 일정한 속도로 이동한다고 보고 시간별 위치의 빈칸을 보간하세요.', 'edu-games'),
                __('km', 'edu-games'),
                array('0분', '15분', '30분', '45분', '60분', '75분'),
                array(0, null, 24, null, 48, 60),
                array(1 => 12, 3 => 36),
                'interpolate',
                __('15분마다 12km씩 이동하므로 15분은 12km, 45분은 36km입니다.', 'edu-games'),
                'hard',
                'numeric'
            ),
            self::sample(
                'sensor-status-previous',
                __('센서 동작 상태', 'edu-games'),
                __('센서 상태 로그에서 값이 빠졌습니다. 새로운 상태가 기록되기 전까지는 직전 상태를 유지합니다.', 'edu-games'),
                '',
                array('09:00', '09:10', '09:20', '09:30', '09:40', '09:50'),
                array(__('정상', 'edu-games'), __('정상', 'edu-games'), null, null, __('점검', 'edu-games'), __('점검', 'edu-games')),
                array(2 => __('정상', 'edu-games'), 3 => __('정상', 'edu-games')),
                'previous',
                __('09:40에 점검 상태가 기록되기 전까지는 직전 정상 상태를 유지합니다.', 'edu-games'),
                'hard',
                'text'
            ),
            self::sample(
                'survey-age-median',
                __('동아리 설문 나이', 'edu-games'),
                __('응답자 나이 중 하나가 비어 있고 한 값은 매우 큽니다. 극단값 영향을 줄이려면 중앙값이 적절합니다.', 'edu-games'),
                __('세', 'edu-games'),
                array('응답1', '응답2', '응답3', '응답4', '응답5', '응답6'),
                array(15, 16, null, 15, 17, 49),
                array(2 => 16),
                'median',
                __('49세가 섞여 있으므로 평균보다 중앙값 16세가 더 안정적입니다.', 'edu-games'),
                'hard',
                'numeric'
            ),
            self::sample(
                'package-scan-pattern-two',
                __('물류 스캔 누적 수', 'edu-games'),
                __('일정한 간격으로 처리된 물류 스캔 누적 수입니다. 증가 규칙으로 두 빈칸을 채우세요.', 'edu-games'),
                __('건', 'edu-games'),
                array('09시', '10시', '11시', '12시', '13시', '14시'),
                array(120, 150, null, 210, null, 270),
                array(2 => 180, 4 => 240),
                'pattern',
                __('매시간 30건씩 누적되므로 11시는 180건, 13시는 240건입니다.', 'edu-games'),
                'hard',
                'numeric'
            ),
        );
    }

    /**
     * @param array<int, string> $labels
     * @param array<int, int|float|string|null> $values
     * @param array<int, int|float|string> $answers
     * @return array<string, mixed>
     */
    private static function sample(
        string $id,
        string $title,
        string $context,
        string $unit,
        array $labels,
        array $values,
        array $answers,
        string $method,
        string $explanation,
        string $difficulty,
        string $value_type
    ): array {
        return array(
            'id' => $id,
            'title' => $title,
            'context' => $context,
            'unit' => $unit,
            'labels' => $labels,
            'values' => $values,
            'answers' => $answers,
            'missingIndexes' => array_keys($answers),
            'method' => $method,
            'explanation' => $explanation,
            'difficulty' => $difficulty,
            'valueType' => $value_type,
        );
    }
}
