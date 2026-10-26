<?php
declare(strict_types=1);

namespace EduGames\Games\Modules\Data_Visualization;

use EduGames\Games\Contracts\Game_Module;

final class Data_Visualization_Module implements Game_Module {
    public const ID = 'data-visualization';
    public const SHORTCODE = 'data_visualization';
    public const BLOCK = 'edu-games/data-visualization';

    public function id(): string {
        return self::ID;
    }

    public function definition(): array {
        return array(
            'id' => $this->id(),
            'title' => __('데이터 시각화', 'edu-games'),
            'category' => 'data-analysis',
            'description' => __('막대·꺾은선·원그래프·히스토그램·산점도로 출동, 경보, 보급 배분, 사이즈 적재, 통신 장치 배치를 수행하는 혼자 하는 시각화 게임입니다.', 'edu-games'),
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
            'chartTypes' => array(
                'bar' => __('막대그래프', 'edu-games'),
                'line' => __('꺾은선그래프', 'edu-games'),
                'pie' => __('원그래프', 'edu-games'),
                'scatter' => __('산점도', 'edu-games'),
                'histogram' => __('히스토그램', 'edu-games'),
            ),
            'difficulties' => array(
                'easy' => array(
                    'label' => __('기초', 'edu-games'),
                    'description' => __('항목 비교, 시간 변화, 비율처럼 뚜렷한 목적을 찾습니다.', 'edu-games'),
                    'baseScore' => 100,
                    'samples' => array('snack-count', 'weekly-temperature', 'class-budget', 'library-visits', 'club-members', 'school-lunch-vote', 'daily-water-intake', 'phone-os-share', 'recycling-count', 'weekend-activity'),
                ),
                'normal' => array(
                    'label' => __('표준', 'edu-games'),
                    'description' => __('분포, 관계, 누적 변화처럼 한 단계 더 복잡한 자료를 읽습니다.', 'edu-games'),
                    'baseScore' => 135,
                    'samples' => array('exam-distribution', 'height-weight', 'monthly-sales', 'transport-share', 'plant-growth', 'typing-speed-distribution', 'study-score-scatter', 'cafe-sales-line', 'pet-kind-share', 'exercise-minutes-bar'),
                ),
                'hard' => array(
                    'label' => __('심화', 'edu-games'),
                    'description' => __('차트 선택 이유와 해석을 함께 판단해야 합니다.', 'edu-games'),
                    'baseScore' => 175,
                    'samples' => array('screen-sleep', 'rainfall-season', 'project-time', 'sensor-spread', 'reading-trend', 'temperature-icecream', 'air-dust-histogram', 'budget-change-line', 'device-use-pie', 'plant-light-scatter'),
                ),
            ),
            'scoring' => array(
                'streakBonus' => 10,
                'maxStreakBonus' => 70,
                'wrongPenalty' => 25,
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
            self::category_sample(
                'snack-count',
                __('좋아하는 간식 조사', 'edu-games'),
                __('반 학생들이 가장 좋아하는 간식을 한 가지씩 선택했습니다. 항목별 인원 차이를 비교하려고 합니다.', 'edu-games'),
                __('명', 'edu-games'),
                array(__('과자', 'edu-games'), __('과일', 'edu-games'), __('떡볶이', 'edu-games'), __('빵', 'edu-games')),
                array(8, 5, 12, 7),
                'bar',
                __('떡볶이를 고른 학생이 12명으로 가장 많습니다.', 'edu-games'),
                array(
                    __('전체 간식의 비율 합계가 100%를 넘습니다.', 'edu-games'),
                    __('떡볶이를 고른 학생이 12명으로 가장 많습니다.', 'edu-games'),
                    __('과일은 시간이 지날수록 계속 증가합니다.', 'edu-games'),
                    __('두 변수 사이에 강한 관계가 보입니다.', 'edu-games'),
                ),
                __('항목별 크기를 비교할 때는 막대그래프가 가장 직접적입니다.', 'edu-games'),
                'easy'
            ),
            self::category_sample(
                'weekly-temperature',
                __('일주일 최고 기온', 'edu-games'),
                __('월요일부터 일요일까지 같은 지역의 최고 기온을 기록했습니다. 날짜에 따른 변화를 보고 싶습니다.', 'edu-games'),
                __('도', 'edu-games'),
                array(__('월', 'edu-games'), __('화', 'edu-games'), __('수', 'edu-games'), __('목', 'edu-games'), __('금', 'edu-games'), __('토', 'edu-games'), __('일', 'edu-games')),
                array(21, 22, 24, 26, 25, 23, 22),
                'line',
                __('기온은 목요일에 26도로 가장 높고 이후 내려갑니다.', 'edu-games'),
                array(
                    __('기온은 목요일에 26도로 가장 높고 이후 내려갑니다.', 'edu-games'),
                    __('가장 많은 항목은 토요일입니다.', 'edu-games'),
                    __('모든 날짜가 같은 비율을 차지합니다.', 'edu-games'),
                    __('두 변수의 관계를 판단하는 자료입니다.', 'edu-games'),
                ),
                __('시간 순서에 따른 변화를 볼 때는 꺾은선그래프가 적절합니다.', 'edu-games'),
                'easy'
            ),
            self::category_sample(
                'class-budget',
                __('학급 행사 예산', 'edu-games'),
                __('학급 행사비를 어디에 얼마나 썼는지 전체에서 차지하는 비율로 표현하려고 합니다.', 'edu-games'),
                __('만원', 'edu-games'),
                array(__('간식', 'edu-games'), __('장식', 'edu-games'), __('상품', 'edu-games'), __('준비물', 'edu-games')),
                array(12, 6, 9, 3),
                'pie',
                __('간식비가 전체 30만원 중 12만원으로 가장 큰 비중입니다.', 'edu-games'),
                array(
                    __('시간이 지날수록 예산이 증가합니다.', 'edu-games'),
                    __('간식비가 전체 30만원 중 12만원으로 가장 큰 비중입니다.', 'edu-games'),
                    __('상품과 준비물 사이에는 양의 상관관계가 있습니다.', 'edu-games'),
                    __('자료는 10점 간격의 분포입니다.', 'edu-games'),
                ),
                __('전체에서 각 항목이 차지하는 비율을 보여줄 때는 원그래프가 잘 맞습니다.', 'edu-games'),
                'easy'
            ),
            self::category_sample(
                'library-visits',
                __('요일별 도서관 방문자', 'edu-games'),
                __('요일마다 도서관에 온 학생 수를 비교하려고 합니다.', 'edu-games'),
                __('명', 'edu-games'),
                array(__('월', 'edu-games'), __('화', 'edu-games'), __('수', 'edu-games'), __('목', 'edu-games'), __('금', 'edu-games')),
                array(24, 31, 28, 35, 42),
                'bar',
                __('금요일 방문자가 42명으로 가장 많습니다.', 'edu-games'),
                array(
                    __('금요일 방문자가 42명으로 가장 많습니다.', 'edu-games'),
                    __('월요일과 금요일의 비율은 항상 같습니다.', 'edu-games'),
                    __('방문자는 시간이 갈수록 반드시 감소합니다.', 'edu-games'),
                    __('두 숫자 변수의 관계를 나타냅니다.', 'edu-games'),
                ),
                __('요일별 인원 비교는 막대그래프가 읽기 쉽습니다.', 'edu-games'),
                'easy'
            ),
            self::category_sample(
                'club-members',
                __('동아리별 인원 구성', 'edu-games'),
                __('학교 동아리 전체 인원이 어떤 동아리에 나뉘어 있는지 비율로 보여주려 합니다.', 'edu-games'),
                __('명', 'edu-games'),
                array(__('코딩', 'edu-games'), __('음악', 'edu-games'), __('체육', 'edu-games'), __('미술', 'edu-games')),
                array(18, 12, 24, 6),
                'pie',
                __('체육 동아리가 24명으로 전체에서 가장 큰 비율입니다.', 'edu-games'),
                array(
                    __('체육 동아리가 24명으로 전체에서 가장 큰 비율입니다.', 'edu-games'),
                    __('코딩 동아리는 매달 6명씩 늘어납니다.', 'edu-games'),
                    __('음악과 미술은 같은 인원입니다.', 'edu-games'),
                    __('점들이 오른쪽 위로 모여 있습니다.', 'edu-games'),
                ),
                __('전체 구성비를 보여주는 자료이므로 원그래프가 적절합니다.', 'edu-games'),
                'easy'
            ),
            self::category_sample(
                'school-lunch-vote',
                __('좋아하는 급식 메뉴', 'edu-games'),
                __('학생들이 가장 좋아하는 급식 메뉴를 하나씩 골랐습니다. 메뉴별 선택 인원을 비교하려고 합니다.', 'edu-games'),
                __('명', 'edu-games'),
                array(__('카레', 'edu-games'), __('비빔밥', 'edu-games'), __('스파게티', 'edu-games'), __('김치볶음밥', 'edu-games')),
                array(14, 9, 17, 11),
                'bar',
                __('스파게티를 고른 학생이 17명으로 가장 많습니다.', 'edu-games'),
                array(
                    __('스파게티를 고른 학생이 17명으로 가장 많습니다.', 'edu-games'),
                    __('메뉴별 선택 인원은 시간에 따라 계속 증가합니다.', 'edu-games'),
                    __('각 메뉴는 전체에서 모두 같은 비율입니다.', 'edu-games'),
                    __('두 숫자 변수의 관계를 보여줍니다.', 'edu-games'),
                ),
                __('항목별 인원 차이를 비교하므로 막대그래프가 적절합니다.', 'edu-games'),
                'easy'
            ),
            self::category_sample(
                'daily-water-intake',
                __('하루 물 섭취량 변화', 'edu-games'),
                __('월요일부터 금요일까지 마신 물의 양을 기록했습니다. 날짜에 따른 변화를 보려 합니다.', 'edu-games'),
                __('컵', 'edu-games'),
                array(__('월', 'edu-games'), __('화', 'edu-games'), __('수', 'edu-games'), __('목', 'edu-games'), __('금', 'edu-games')),
                array(5, 6, 6, 8, 7),
                'line',
                __('물 섭취량은 목요일에 8컵으로 가장 많습니다.', 'edu-games'),
                array(
                    __('물 섭취량은 목요일에 8컵으로 가장 많습니다.', 'edu-games'),
                    __('각 요일은 전체에서 같은 비율입니다.', 'edu-games'),
                    __('가장 많은 항목은 월요일입니다.', 'edu-games'),
                    __('점수 구간별 빈도를 보여줍니다.', 'edu-games'),
                ),
                __('요일 순서에 따른 변화이므로 꺾은선그래프가 읽기 좋습니다.', 'edu-games'),
                'easy'
            ),
            self::category_sample(
                'phone-os-share',
                __('휴대폰 운영체제 비율', 'edu-games'),
                __('반 학생들의 휴대폰 운영체제가 전체에서 차지하는 비율을 보여주려 합니다.', 'edu-games'),
                __('명', 'edu-games'),
                array(__('안드로이드', 'edu-games'), __('iOS', 'edu-games'), __('기타', 'edu-games')),
                array(18, 12, 2),
                'pie',
                __('안드로이드가 18명으로 가장 큰 비율을 차지합니다.', 'edu-games'),
                array(
                    __('안드로이드가 18명으로 가장 큰 비율을 차지합니다.', 'edu-games'),
                    __('운영체제 수는 매일 일정하게 증가합니다.', 'edu-games'),
                    __('iOS와 기타는 같은 인원입니다.', 'edu-games'),
                    __('두 숫자 변수의 관계가 핵심입니다.', 'edu-games'),
                ),
                __('전체 중 각 범주의 비율을 보여주므로 원그래프가 적절합니다.', 'edu-games'),
                'easy'
            ),
            self::category_sample(
                'recycling-count',
                __('분리수거 품목 수', 'edu-games'),
                __('하루 동안 모은 분리수거 품목 수를 종류별로 비교하려고 합니다.', 'edu-games'),
                __('개', 'edu-games'),
                array(__('종이', 'edu-games'), __('플라스틱', 'edu-games'), __('캔', 'edu-games'), __('유리', 'edu-games')),
                array(32, 45, 16, 9),
                'bar',
                __('플라스틱이 45개로 가장 많이 모였습니다.', 'edu-games'),
                array(
                    __('플라스틱이 45개로 가장 많이 모였습니다.', 'edu-games'),
                    __('종류별 수량은 시간에 따라 선으로 이어야 합니다.', 'edu-games'),
                    __('모든 품목은 같은 비율입니다.', 'edu-games'),
                    __('값이 구간별로 묶인 분포 자료입니다.', 'edu-games'),
                ),
                __('품목별 수량 차이를 비교하는 자료에는 막대그래프가 적합합니다.', 'edu-games'),
                'easy'
            ),
            self::category_sample(
                'weekend-activity',
                __('주말 활동 시간 구성', 'edu-games'),
                __('한 학생의 주말 활동 시간이 전체 시간에서 어떤 비중인지 나타내려 합니다.', 'edu-games'),
                __('시간', 'edu-games'),
                array(__('공부', 'edu-games'), __('운동', 'edu-games'), __('휴식', 'edu-games'), __('취미', 'edu-games')),
                array(3, 2, 6, 4),
                'pie',
                __('휴식 시간이 6시간으로 가장 큰 비중입니다.', 'edu-games'),
                array(
                    __('휴식 시간이 6시간으로 가장 큰 비중입니다.', 'edu-games'),
                    __('활동 시간은 요일이 지날수록 계속 증가합니다.', 'edu-games'),
                    __('운동과 취미는 같은 시간입니다.', 'edu-games'),
                    __('두 변수 사이의 상관관계를 보여줍니다.', 'edu-games'),
                ),
                __('전체 시간에서 활동별 비중을 보려는 자료이므로 원그래프가 어울립니다.', 'edu-games'),
                'easy'
            ),
            self::histogram_sample(
                'exam-distribution',
                __('수학 시험 점수 분포', 'edu-games'),
                __('학생들의 점수가 어느 구간에 많이 모였는지 보고 싶습니다.', 'edu-games'),
                __('명', 'edu-games'),
                array(__('50점대', 'edu-games'), __('60점대', 'edu-games'), __('70점대', 'edu-games'), __('80점대', 'edu-games'), __('90점대', 'edu-games')),
                array(2, 5, 11, 8, 4),
                __('70점대가 11명으로 가장 많이 모여 있습니다.', 'edu-games'),
                array(
                    __('70점대가 11명으로 가장 많이 모여 있습니다.', 'edu-games'),
                    __('점수는 날짜에 따라 계속 증가합니다.', 'edu-games'),
                    __('각 점수 구간의 비율만 보아야 합니다.', 'edu-games'),
                    __('키가 클수록 몸무게도 커지는 관계입니다.', 'edu-games'),
                ),
                __('연속적인 점수 구간의 분포를 볼 때는 히스토그램이 어울립니다.', 'edu-games'),
                'normal'
            ),
            self::scatter_sample(
                'height-weight',
                __('키와 몸무게 관계', 'edu-games'),
                __('여러 학생의 키와 몸무게를 함께 기록했습니다. 두 숫자 변수의 관계를 보고 싶습니다.', 'edu-games'),
                __('키 cm', 'edu-games'),
                __('몸무게 kg', 'edu-games'),
                array(
                    array('x' => 145, 'y' => 38),
                    array('x' => 150, 'y' => 42),
                    array('x' => 155, 'y' => 45),
                    array('x' => 160, 'y' => 50),
                    array('x' => 165, 'y' => 54),
                    array('x' => 170, 'y' => 59),
                ),
                __('키가 클수록 몸무게도 대체로 증가하는 양의 관계가 보입니다.', 'edu-games'),
                array(
                    __('전체에서 각 항목이 차지하는 비율을 비교합니다.', 'edu-games'),
                    __('키가 클수록 몸무게도 대체로 증가하는 양의 관계가 보입니다.', 'edu-games'),
                    __('몸무게는 요일별로 변합니다.', 'edu-games'),
                    __('가장 많은 구간은 70점대입니다.', 'edu-games'),
                ),
                __('두 수치형 변수의 관계는 산점도로 보는 것이 좋습니다.', 'edu-games'),
                'normal'
            ),
            self::category_sample(
                'monthly-sales',
                __('월별 온라인 판매량', 'edu-games'),
                __('1월부터 6월까지 같은 상품의 판매량 변화를 확인하려고 합니다.', 'edu-games'),
                __('개', 'edu-games'),
                array(__('1월', 'edu-games'), __('2월', 'edu-games'), __('3월', 'edu-games'), __('4월', 'edu-games'), __('5월', 'edu-games'), __('6월', 'edu-games')),
                array(80, 96, 110, 128, 145, 171),
                'line',
                __('판매량은 1월부터 6월까지 꾸준히 증가합니다.', 'edu-games'),
                array(
                    __('판매량은 1월부터 6월까지 꾸준히 증가합니다.', 'edu-games'),
                    __('3월 판매량이 가장 낮습니다.', 'edu-games'),
                    __('각 월은 전체에서 같은 비율입니다.', 'edu-games'),
                    __('판매량과 가격의 관계를 나타냅니다.', 'edu-games'),
                ),
                __('월별 변화 추세를 확인하는 목적이므로 꺾은선그래프가 적절합니다.', 'edu-games'),
                'normal'
            ),
            self::category_sample(
                'transport-share',
                __('등교 교통수단 비율', 'edu-games'),
                __('학생들이 어떤 교통수단으로 등교하는지 전체 비중을 비교하려고 합니다.', 'edu-games'),
                __('명', 'edu-games'),
                array(__('도보', 'edu-games'), __('버스', 'edu-games'), __('자전거', 'edu-games'), __('자동차', 'edu-games')),
                array(14, 18, 6, 12),
                'pie',
                __('버스가 18명으로 가장 큰 비중을 차지합니다.', 'edu-games'),
                array(
                    __('버스가 18명으로 가장 큰 비중을 차지합니다.', 'edu-games'),
                    __('자전거 이용자는 시간이 갈수록 증가합니다.', 'edu-games'),
                    __('도보와 자동차는 같은 수입니다.', 'edu-games'),
                    __('두 변수 사이에는 음의 관계가 있습니다.', 'edu-games'),
                ),
                __('전체 통학 인원 중 각 교통수단의 비중을 볼 때는 원그래프가 적합합니다.', 'edu-games'),
                'normal'
            ),
            self::category_sample(
                'plant-growth',
                __('식물 키 성장', 'edu-games'),
                __('같은 식물의 키를 며칠 동안 기록했습니다. 성장 추세를 보여주려 합니다.', 'edu-games'),
                __('cm', 'edu-games'),
                array(__('1일', 'edu-games'), __('2일', 'edu-games'), __('3일', 'edu-games'), __('4일', 'edu-games'), __('5일', 'edu-games'), __('6일', 'edu-games')),
                array(4, 5, 7, 8, 10, 12),
                'line',
                __('식물의 키는 1일부터 6일까지 계속 자랍니다.', 'edu-games'),
                array(
                    __('식물의 키는 1일부터 6일까지 계속 자랍니다.', 'edu-games'),
                    __('가장 큰 항목은 1일입니다.', 'edu-games'),
                    __('전체에서 6일이 차지하는 비율만 중요합니다.', 'edu-games'),
                    __('키와 물의 양 사이 관계를 나타냅니다.', 'edu-games'),
                ),
                __('시간이 지나는 순서가 있으므로 꺾은선그래프가 성장 흐름을 잘 보여줍니다.', 'edu-games'),
                'normal'
            ),
            self::histogram_sample(
                'typing-speed-distribution',
                __('타자 속도 분포', 'edu-games'),
                __('학생들의 1분 타자 수를 구간별로 묶어 어느 속도대가 많은지 보려 합니다.', 'edu-games'),
                __('명', 'edu-games'),
                array(__('0-99타', 'edu-games'), __('100-149타', 'edu-games'), __('150-199타', 'edu-games'), __('200-249타', 'edu-games'), __('250타 이상', 'edu-games')),
                array(3, 7, 12, 6, 2),
                __('150-199타 구간이 12명으로 가장 많습니다.', 'edu-games'),
                array(
                    __('150-199타 구간이 12명으로 가장 많습니다.', 'edu-games'),
                    __('타자 속도는 날짜별로 계속 증가합니다.', 'edu-games'),
                    __('각 속도 구간은 전체에서 모두 같은 비율입니다.', 'edu-games'),
                    __('두 숫자 변수의 관계를 보여주는 자료입니다.', 'edu-games'),
                ),
                __('속도처럼 연속적인 값을 구간으로 묶은 자료는 히스토그램이 적합합니다.', 'edu-games'),
                'normal'
            ),
            self::scatter_sample(
                'study-score-scatter',
                __('공부 시간과 점수 관계', 'edu-games'),
                __('학생별 하루 공부 시간과 쪽지시험 점수를 함께 기록했습니다.', 'edu-games'),
                __('공부 시간', 'edu-games'),
                __('점수', 'edu-games'),
                array(
                    array('x' => 1, 'y' => 55),
                    array('x' => 2, 'y' => 62),
                    array('x' => 3, 'y' => 70),
                    array('x' => 4, 'y' => 78),
                    array('x' => 5, 'y' => 83),
                    array('x' => 6, 'y' => 88),
                ),
                __('공부 시간이 길수록 점수가 대체로 높아지는 양의 관계가 보입니다.', 'edu-games'),
                array(
                    __('공부 시간이 길수록 점수가 대체로 높아지는 양의 관계가 보입니다.', 'edu-games'),
                    __('점수는 요일별 비율로 나누어져 있습니다.', 'edu-games'),
                    __('가장 많은 점수 구간은 0-99점입니다.', 'edu-games'),
                    __('공부 시간은 전체에서 같은 비중입니다.', 'edu-games'),
                ),
                __('두 수치형 변수의 관계를 보려는 자료이므로 산점도가 알맞습니다.', 'edu-games'),
                'normal'
            ),
            self::category_sample(
                'cafe-sales-line',
                __('카페 주간 판매량', 'edu-games'),
                __('월요일부터 토요일까지 같은 음료의 판매량이 어떻게 변했는지 확인하려 합니다.', 'edu-games'),
                __('잔', 'edu-games'),
                array(__('월', 'edu-games'), __('화', 'edu-games'), __('수', 'edu-games'), __('목', 'edu-games'), __('금', 'edu-games'), __('토', 'edu-games')),
                array(42, 45, 48, 51, 58, 66),
                'line',
                __('판매량은 월요일부터 토요일까지 꾸준히 증가합니다.', 'edu-games'),
                array(
                    __('판매량은 월요일부터 토요일까지 꾸준히 증가합니다.', 'edu-games'),
                    __('각 요일 판매량은 전체에서 같은 비율입니다.', 'edu-games'),
                    __('수요일 판매량이 가장 높습니다.', 'edu-games'),
                    __('판매량은 구간별 빈도 자료입니다.', 'edu-games'),
                ),
                __('시간 순서에 따른 변화를 볼 때는 꺾은선그래프가 적절합니다.', 'edu-games'),
                'normal'
            ),
            self::category_sample(
                'pet-kind-share',
                __('반려동물 종류 비율', 'edu-games'),
                __('설문에 나온 반려동물 종류가 전체에서 차지하는 비중을 보여주려 합니다.', 'edu-games'),
                __('명', 'edu-games'),
                array(__('강아지', 'edu-games'), __('고양이', 'edu-games'), __('물고기', 'edu-games'), __('없음', 'edu-games')),
                array(11, 9, 4, 16),
                'pie',
                __('반려동물이 없다고 답한 학생이 16명으로 가장 큰 비중입니다.', 'edu-games'),
                array(
                    __('반려동물이 없다고 답한 학생이 16명으로 가장 큰 비중입니다.', 'edu-games'),
                    __('고양이 수는 날짜에 따라 증가합니다.', 'edu-games'),
                    __('강아지와 고양이는 같은 인원입니다.', 'edu-games'),
                    __('두 숫자 변수 사이 관계가 핵심입니다.', 'edu-games'),
                ),
                __('전체 설문 응답에서 각 종류의 비율을 보려면 원그래프가 어울립니다.', 'edu-games'),
                'normal'
            ),
            self::category_sample(
                'exercise-minutes-bar',
                __('운동 종류별 시간', 'edu-games'),
                __('하루 동안 운동 종류별로 사용한 시간을 비교하려고 합니다.', 'edu-games'),
                __('분', 'edu-games'),
                array(__('걷기', 'edu-games'), __('달리기', 'edu-games'), __('줄넘기', 'edu-games'), __('스트레칭', 'edu-games')),
                array(35, 20, 15, 10),
                'bar',
                __('걷기 시간이 35분으로 가장 깁니다.', 'edu-games'),
                array(
                    __('걷기 시간이 35분으로 가장 깁니다.', 'edu-games'),
                    __('운동 시간은 월별로 계속 증가합니다.', 'edu-games'),
                    __('모든 운동은 같은 비율입니다.', 'edu-games'),
                    __('운동 시간과 키의 관계를 보여줍니다.', 'edu-games'),
                ),
                __('종류별 시간을 비교하므로 막대그래프가 가장 직접적입니다.', 'edu-games'),
                'normal'
            ),
            self::scatter_sample(
                'screen-sleep',
                __('화면 사용 시간과 수면 시간', 'edu-games'),
                __('학생별 하루 화면 사용 시간과 수면 시간을 함께 조사했습니다.', 'edu-games'),
                __('화면 시간', 'edu-games'),
                __('수면 시간', 'edu-games'),
                array(
                    array('x' => 1, 'y' => 8.5),
                    array('x' => 2, 'y' => 8.1),
                    array('x' => 3, 'y' => 7.4),
                    array('x' => 4, 'y' => 7.0),
                    array('x' => 5, 'y' => 6.4),
                    array('x' => 6, 'y' => 6.0),
                ),
                __('화면 사용 시간이 길수록 수면 시간이 줄어드는 경향이 있습니다.', 'edu-games'),
                array(
                    __('화면 사용 시간이 길수록 수면 시간이 줄어드는 경향이 있습니다.', 'edu-games'),
                    __('수면 시간은 월별로 꾸준히 증가합니다.', 'edu-games'),
                    __('각 학생은 전체의 같은 비율입니다.', 'edu-games'),
                    __('가장 많은 구간은 화면 시간 1시간입니다.', 'edu-games'),
                ),
                __('두 변수의 관계와 방향을 보려면 산점도가 적절합니다.', 'edu-games'),
                'hard'
            ),
            self::histogram_sample(
                'rainfall-season',
                __('일별 강수량 분포', 'edu-games'),
                __('한 달 동안의 일별 강수량을 구간별로 묶어 분포를 보려 합니다.', 'edu-games'),
                __('일', 'edu-games'),
                array(__('0mm', 'edu-games'), __('1-10mm', 'edu-games'), __('11-30mm', 'edu-games'), __('31-60mm', 'edu-games'), __('61mm 이상', 'edu-games')),
                array(12, 8, 6, 3, 1),
                __('비가 오지 않은 날이 12일로 가장 많습니다.', 'edu-games'),
                array(
                    __('비가 오지 않은 날이 12일로 가장 많습니다.', 'edu-games'),
                    __('강수량은 날짜가 지날수록 반드시 증가합니다.', 'edu-games'),
                    __('61mm 이상 구간이 가장 많습니다.', 'edu-games'),
                    __('강수량과 기온의 관계를 보여줍니다.', 'edu-games'),
                ),
                __('강수량을 구간으로 나누어 빈도를 보는 자료이므로 히스토그램이 알맞습니다.', 'edu-games'),
                'hard'
            ),
            self::category_sample(
                'project-time',
                __('프로젝트 시간 사용', 'edu-games'),
                __('한 프로젝트에서 조사, 설계, 제작, 발표 준비에 쓴 시간의 구성비를 나타내려 합니다.', 'edu-games'),
                __('시간', 'edu-games'),
                array(__('조사', 'edu-games'), __('설계', 'edu-games'), __('제작', 'edu-games'), __('발표', 'edu-games')),
                array(5, 8, 15, 4),
                'pie',
                __('제작 시간이 15시간으로 전체에서 가장 큰 비중을 차지합니다.', 'edu-games'),
                array(
                    __('제작 시간이 15시간으로 전체에서 가장 큰 비중을 차지합니다.', 'edu-games'),
                    __('발표 시간은 조사 시간보다 깁니다.', 'edu-games'),
                    __('시간은 매일 같은 간격으로 증가합니다.', 'edu-games'),
                    __('조사 시간과 설계 시간 사이 관계를 보여줍니다.', 'edu-games'),
                ),
                __('전체 프로젝트 시간 중 각 활동의 구성비를 보려면 원그래프가 좋습니다.', 'edu-games'),
                'hard'
            ),
            self::scatter_sample(
                'sensor-spread',
                __('거리와 센서 밝기값', 'edu-games'),
                __('물체와 센서 사이 거리, 센서가 읽은 밝기값을 함께 기록했습니다.', 'edu-games'),
                __('거리 cm', 'edu-games'),
                __('밝기값', 'edu-games'),
                array(
                    array('x' => 5, 'y' => 92),
                    array('x' => 10, 'y' => 78),
                    array('x' => 15, 'y' => 63),
                    array('x' => 20, 'y' => 49),
                    array('x' => 25, 'y' => 37),
                    array('x' => 30, 'y' => 25),
                ),
                __('거리가 멀어질수록 밝기값은 대체로 낮아집니다.', 'edu-games'),
                array(
                    __('거리가 멀어질수록 밝기값은 대체로 낮아집니다.', 'edu-games'),
                    __('밝기값은 시간 순서로 증가합니다.', 'edu-games'),
                    __('거리별 인원 수를 비교하는 자료입니다.', 'edu-games'),
                    __('전체 밝기값 중 각 항목의 비율을 나타냅니다.', 'edu-games'),
                ),
                __('거리와 밝기값이라는 두 수치 변수의 관계이므로 산점도를 사용합니다.', 'edu-games'),
                'hard'
            ),
            self::category_sample(
                'reading-trend',
                __('월별 독서량 변화', 'edu-games'),
                __('한 학생이 매달 읽은 책 수가 어떻게 변했는지 확인하려고 합니다.', 'edu-games'),
                __('권', 'edu-games'),
                array(__('3월', 'edu-games'), __('4월', 'edu-games'), __('5월', 'edu-games'), __('6월', 'edu-games'), __('7월', 'edu-games'), __('8월', 'edu-games')),
                array(2, 3, 5, 4, 6, 8),
                'line',
                __('독서량은 중간에 한 번 줄지만 전체적으로 증가합니다.', 'edu-games'),
                array(
                    __('독서량은 중간에 한 번 줄지만 전체적으로 증가합니다.', 'edu-games'),
                    __('3월과 8월은 같은 비율입니다.', 'edu-games'),
                    __('5권 구간에 학생이 가장 많습니다.', 'edu-games'),
                    __('책 수와 키의 관계를 보여줍니다.', 'edu-games'),
                ),
                __('월별 변화 추세가 핵심이므로 꺾은선그래프가 적합합니다.', 'edu-games'),
                'hard'
            ),
            self::scatter_sample(
                'temperature-icecream',
                __('기온과 아이스크림 판매량', 'edu-games'),
                __('여러 날의 낮 기온과 아이스크림 판매량을 함께 기록했습니다.', 'edu-games'),
                __('기온 도', 'edu-games'),
                __('판매량 개', 'edu-games'),
                array(
                    array('x' => 18, 'y' => 35),
                    array('x' => 21, 'y' => 48),
                    array('x' => 24, 'y' => 64),
                    array('x' => 27, 'y' => 82),
                    array('x' => 30, 'y' => 105),
                    array('x' => 33, 'y' => 126),
                ),
                __('기온이 높을수록 아이스크림 판매량도 늘어나는 경향이 있습니다.', 'edu-games'),
                array(
                    __('기온이 높을수록 아이스크림 판매량도 늘어나는 경향이 있습니다.', 'edu-games'),
                    __('판매량은 전체에서 같은 비율로 나뉩니다.', 'edu-games'),
                    __('가장 많은 구간은 18도입니다.', 'edu-games'),
                    __('기온은 월별로 반드시 감소합니다.', 'edu-games'),
                ),
                __('기온과 판매량이라는 두 수치 변수의 관계를 보려면 산점도가 적절합니다.', 'edu-games'),
                'hard'
            ),
            self::histogram_sample(
                'air-dust-histogram',
                __('미세먼지 농도 분포', 'edu-games'),
                __('한 달 동안 측정한 미세먼지 농도를 구간으로 묶어 분포를 확인하려 합니다.', 'edu-games'),
                __('일', 'edu-games'),
                array(__('0-15', 'edu-games'), __('16-35', 'edu-games'), __('36-75', 'edu-games'), __('76-150', 'edu-games'), __('151 이상', 'edu-games')),
                array(6, 11, 9, 3, 1),
                __('16-35 구간이 11일로 가장 많습니다.', 'edu-games'),
                array(
                    __('16-35 구간이 11일로 가장 많습니다.', 'edu-games'),
                    __('미세먼지는 날짜가 지날수록 계속 증가합니다.', 'edu-games'),
                    __('151 이상 구간이 가장 많습니다.', 'edu-games'),
                    __('미세먼지와 기온의 관계가 핵심입니다.', 'edu-games'),
                ),
                __('농도처럼 연속적인 수치를 구간별 빈도로 보려면 히스토그램이 맞습니다.', 'edu-games'),
                'hard'
            ),
            self::category_sample(
                'budget-change-line',
                __('월별 학급비 잔액', 'edu-games'),
                __('3월부터 8월까지 학급비 잔액이 어떻게 변했는지 흐름을 보려 합니다.', 'edu-games'),
                __('만원', 'edu-games'),
                array(__('3월', 'edu-games'), __('4월', 'edu-games'), __('5월', 'edu-games'), __('6월', 'edu-games'), __('7월', 'edu-games'), __('8월', 'edu-games')),
                array(80, 72, 66, 58, 61, 54),
                'line',
                __('학급비 잔액은 7월에 잠시 늘지만 전체적으로 줄어듭니다.', 'edu-games'),
                array(
                    __('학급비 잔액은 7월에 잠시 늘지만 전체적으로 줄어듭니다.', 'edu-games'),
                    __('각 월은 전체에서 같은 비율입니다.', 'edu-games'),
                    __('가장 많은 구간은 80만원대입니다.', 'edu-games'),
                    __('잔액과 키의 관계를 보여줍니다.', 'edu-games'),
                ),
                __('월별 변화 흐름을 읽어야 하므로 꺾은선그래프가 적합합니다.', 'edu-games'),
                'hard'
            ),
            self::category_sample(
                'device-use-pie',
                __('학습 기기 사용 시간 구성', 'edu-games'),
                __('하루 학습 시간 중 기기별 사용 시간이 차지하는 비율을 나타내려 합니다.', 'edu-games'),
                __('분', 'edu-games'),
                array(__('노트북', 'edu-games'), __('태블릿', 'edu-games'), __('스마트폰', 'edu-games'), __('종이책', 'edu-games')),
                array(70, 45, 25, 60),
                'pie',
                __('노트북 사용 시간이 70분으로 가장 큰 비중입니다.', 'edu-games'),
                array(
                    __('노트북 사용 시간이 70분으로 가장 큰 비중입니다.', 'edu-games'),
                    __('사용 시간은 날짜별로 계속 증가합니다.', 'edu-games'),
                    __('태블릿과 종이책은 같은 시간입니다.', 'edu-games'),
                    __('두 숫자 변수의 상관관계가 핵심입니다.', 'edu-games'),
                ),
                __('전체 학습 시간에서 각 기기가 차지하는 비율을 보려면 원그래프가 적합합니다.', 'edu-games'),
                'hard'
            ),
            self::scatter_sample(
                'plant-light-scatter',
                __('빛 노출 시간과 식물 성장', 'edu-games'),
                __('화분별 하루 빛 노출 시간과 일주일 동안 자란 키를 함께 기록했습니다.', 'edu-games'),
                __('빛 시간', 'edu-games'),
                __('성장 cm', 'edu-games'),
                array(
                    array('x' => 2, 'y' => 1.1),
                    array('x' => 3, 'y' => 1.5),
                    array('x' => 4, 'y' => 2.0),
                    array('x' => 5, 'y' => 2.4),
                    array('x' => 6, 'y' => 2.9),
                    array('x' => 7, 'y' => 3.2),
                ),
                __('빛을 받은 시간이 길수록 성장량도 대체로 커집니다.', 'edu-games'),
                array(
                    __('빛을 받은 시간이 길수록 성장량도 대체로 커집니다.', 'edu-games'),
                    __('성장량은 전체에서 같은 비율입니다.', 'edu-games'),
                    __('가장 많은 성장 구간은 1cm 이하입니다.', 'edu-games'),
                    __('빛 시간은 월별로 계속 감소합니다.', 'edu-games'),
                ),
                __('빛 시간과 성장량의 관계를 파악하는 자료이므로 산점도가 알맞습니다.', 'edu-games'),
                'hard'
            ),
        );
    }

    /**
     * @param array<int, string> $labels
     * @param array<int, int|float> $values
     * @param array<int, string> $insight_options
     * @return array<string, mixed>
     */
    private static function category_sample(
        string $id,
        string $title,
        string $context,
        string $unit,
        array $labels,
        array $values,
        string $chart_type,
        string $insight,
        array $insight_options,
        string $explanation,
        string $difficulty
    ): array {
        return array(
            'id' => $id,
            'title' => $title,
            'context' => $context,
            'unit' => $unit,
            'dataKind' => 'category',
            'labels' => $labels,
            'values' => $values,
            'chartType' => $chart_type,
            'insight' => $insight,
            'insightOptions' => $insight_options,
            'explanation' => $explanation,
            'difficulty' => $difficulty,
        );
    }

    /**
     * @param array<int, string> $bins
     * @param array<int, int|float> $counts
     * @param array<int, string> $insight_options
     * @return array<string, mixed>
     */
    private static function histogram_sample(
        string $id,
        string $title,
        string $context,
        string $unit,
        array $bins,
        array $counts,
        string $insight,
        array $insight_options,
        string $explanation,
        string $difficulty
    ): array {
        return array(
            'id' => $id,
            'title' => $title,
            'context' => $context,
            'unit' => $unit,
            'dataKind' => 'distribution',
            'labels' => $bins,
            'values' => $counts,
            'chartType' => 'histogram',
            'insight' => $insight,
            'insightOptions' => $insight_options,
            'explanation' => $explanation,
            'difficulty' => $difficulty,
        );
    }

    /**
     * @param array<int, array{x:int|float,y:int|float}> $points
     * @param array<int, string> $insight_options
     * @return array<string, mixed>
     */
    private static function scatter_sample(
        string $id,
        string $title,
        string $context,
        string $x_label,
        string $y_label,
        array $points,
        string $insight,
        array $insight_options,
        string $explanation,
        string $difficulty
    ): array {
        return array(
            'id' => $id,
            'title' => $title,
            'context' => $context,
            'unit' => '',
            'dataKind' => 'relationship',
            'xLabel' => $x_label,
            'yLabel' => $y_label,
            'points' => $points,
            'chartType' => 'scatter',
            'insight' => $insight,
            'insightOptions' => $insight_options,
            'explanation' => $explanation,
            'difficulty' => $difficulty,
        );
    }
}
