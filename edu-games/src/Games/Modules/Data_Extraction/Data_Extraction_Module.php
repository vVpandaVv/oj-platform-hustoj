<?php
declare(strict_types=1);

namespace EduGames\Games\Modules\Data_Extraction;

use EduGames\Games\Contracts\Game_Module;

final class Data_Extraction_Module implements Game_Module {
    public const ID = 'data-extraction';
    public const SHORTCODE = 'data_extraction';
    public const BLOCK = 'edu-games/data-extraction';

    public function id(): string {
        return self::ID;
    }

    public function definition(): array {
        return array(
            'id' => $this->id(),
            'title' => __('데이터 추출', 'edu-games'),
            'category' => 'data-analysis',
            'description' => __('색과 모양의 조건 관문을 연결해 물건을 출고하고, 행을 거르는 것과 열을 줄이는 것의 차이를 눈으로 확인합니다.', 'edu-games'),
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
            'rankedRounds' => 6,
            'defaultDifficulty' => 'easy',
            'difficulties' => array(
                'easy' => array(
                    'label' => __('기초', 'edu-games'),
                    'description' => __('문제에 바로 필요한 두 열을 찾습니다.', 'edu-games'),
                    'baseScore' => 100,
                    'samples' => array('exercise-lack', 'allowance-saving'),
                ),
                'normal' => array(
                    'label' => __('표준', 'edu-games'),
                    'description' => __('비교를 위해 함께 봐야 하는 열을 찾습니다.', 'edu-games'),
                    'baseScore' => 130,
                    'samples' => array('class-recycling', 'online-class-focus'),
                ),
                'hard' => array(
                    'label' => __('심화', 'edu-games'),
                    'description' => __('여러 열을 함께 살펴 원인이나 위험을 판단합니다.', 'edu-games'),
                    'baseScore' => 165,
                    'samples' => array('customer-churn-risk', 'traffic-congestion-cause'),
                ),
            ),
            'scoring' => array(
                'streakBonus' => 12,
                'maxStreakBonus' => 72,
                'wrongPenalty' => 20,
                'minimumCorrectScore' => 40,
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
                'exercise-lack',
                __('운동 부족 학생 찾기', 'edu-games'),
                __('보건 선생님이 평소 운동량이 부족한 학생을 찾아 운동 프로그램을 추천하려고 합니다.', 'edu-games'),
                array(
                    array('key' => 'student_no', 'label' => __('학생 번호', 'edu-games')),
                    array('key' => 'weekly_exercise_min', 'label' => __('주간 운동 시간(분)', 'edu-games')),
                    array('key' => 'favorite_color', 'label' => __('좋아하는 색깔', 'edu-games')),
                    array('key' => 'sitting_min', 'label' => __('하루 앉아있는 시간(분)', 'edu-games')),
                    array('key' => 'favorite_subject', 'label' => __('좋아하는 과목', 'edu-games')),
                ),
                array(
                    array('student_no' => 1, 'weekly_exercise_min' => 210, 'favorite_color' => __('빨간색', 'edu-games'), 'sitting_min' => 420, 'favorite_subject' => __('체육', 'edu-games')),
                    array('student_no' => 2, 'weekly_exercise_min' => 60, 'favorite_color' => __('파란색', 'edu-games'), 'sitting_min' => 540, 'favorite_subject' => __('수학', 'edu-games')),
                    array('student_no' => 3, 'weekly_exercise_min' => 180, 'favorite_color' => __('초록색', 'edu-games'), 'sitting_min' => 380, 'favorite_subject' => __('미술', 'edu-games')),
                    array('student_no' => 4, 'weekly_exercise_min' => 30, 'favorite_color' => __('노란색', 'edu-games'), 'sitting_min' => 600, 'favorite_subject' => __('과학', 'edu-games')),
                    array('student_no' => 5, 'weekly_exercise_min' => 240, 'favorite_color' => __('파란색', 'edu-games'), 'sitting_min' => 300, 'favorite_subject' => __('체육', 'edu-games')),
                    array('student_no' => 6, 'weekly_exercise_min' => 45, 'favorite_color' => __('보라색', 'edu-games'), 'sitting_min' => 560, 'favorite_subject' => __('음악', 'edu-games')),
                    array('student_no' => 7, 'weekly_exercise_min' => 200, 'favorite_color' => __('빨간색', 'edu-games'), 'sitting_min' => 350, 'favorite_subject' => __('국어', 'edu-games')),
                    array('student_no' => 8, 'weekly_exercise_min' => 20, 'favorite_color' => __('초록색', 'edu-games'), 'sitting_min' => 620, 'favorite_subject' => __('사회', 'edu-games')),
                ),
                array('weekly_exercise_min', 'sitting_min'),
                array(
                    array('key' => 'a', 'label' => __('주간 운동 시간이 짧은 순서로만 정렬해 하위 3명을 추천한다', 'edu-games'), 'correct' => false),
                    array('key' => 'b', 'label' => __('운동 시간이 짧으면서 앉아있는 시간이 긴 학생을 함께 살펴 추천한다', 'edu-games'), 'correct' => true),
                    array('key' => 'c', 'label' => __('좋아하는 색깔별로 학생을 그룹 지어 비교한다', 'edu-games'), 'correct' => false),
                    array('key' => 'd', 'label' => __('학생 번호 순서대로 무작위로 추천한다', 'edu-games'), 'correct' => false),
                ),
                __('운동 시간만 보면 놓치는 경우가 있어요. 앉아있는 시간이 긴데 운동도 적게 하는 학생(4번, 6번, 8번)을 함께 봐야 정확합니다.', 'edu-games')
            ),
            self::sample(
                'allowance-saving',
                __('저축 습관이 부족한 학생 찾기', 'edu-games'),
                __('학생들에게 용돈 관리 조언을 해주기 위해, 용돈에 비해 저축을 잘 하지 못하는 학생을 찾으려 합니다.', 'edu-games'),
                array(
                    array('key' => 'student_no', 'label' => __('학생 번호', 'edu-games')),
                    array('key' => 'monthly_allowance', 'label' => __('한 달 용돈(원)', 'edu-games')),
                    array('key' => 'monthly_saving', 'label' => __('한 달 저축액(원)', 'edu-games')),
                    array('key' => 'favorite_snack', 'label' => __('좋아하는 간식', 'edu-games')),
                    array('key' => 'siblings_count', 'label' => __('형제자매 수', 'edu-games')),
                ),
                array(
                    array('student_no' => 1, 'monthly_allowance' => 20000, 'monthly_saving' => 2000, 'favorite_snack' => __('과자', 'edu-games'), 'siblings_count' => 1),
                    array('student_no' => 2, 'monthly_allowance' => 15000, 'monthly_saving' => 6000, 'favorite_snack' => __('초콜릿', 'edu-games'), 'siblings_count' => 0),
                    array('student_no' => 3, 'monthly_allowance' => 30000, 'monthly_saving' => 3000, 'favorite_snack' => __('사탕', 'edu-games'), 'siblings_count' => 2),
                    array('student_no' => 4, 'monthly_allowance' => 10000, 'monthly_saving' => 5000, 'favorite_snack' => __('과자', 'edu-games'), 'siblings_count' => 1),
                    array('student_no' => 5, 'monthly_allowance' => 25000, 'monthly_saving' => 1000, 'favorite_snack' => __('젤리', 'edu-games'), 'siblings_count' => 0),
                    array('student_no' => 6, 'monthly_allowance' => 18000, 'monthly_saving' => 9000, 'favorite_snack' => __('초콜릿', 'edu-games'), 'siblings_count' => 3),
                    array('student_no' => 7, 'monthly_allowance' => 22000, 'monthly_saving' => 2200, 'favorite_snack' => __('과자', 'edu-games'), 'siblings_count' => 1),
                    array('student_no' => 8, 'monthly_allowance' => 12000, 'monthly_saving' => 6000, 'favorite_snack' => __('사탕', 'edu-games'), 'siblings_count' => 2),
                ),
                array('monthly_allowance', 'monthly_saving'),
                array(
                    array('key' => 'a', 'label' => __('저축액이 적은 순서로만 정렬해 하위 학생을 찾는다', 'edu-games'), 'correct' => false),
                    array('key' => 'b', 'label' => __('저축액을 용돈으로 나눈 저축률이 낮은 학생을 찾는다', 'edu-games'), 'correct' => true),
                    array('key' => 'c', 'label' => __('좋아하는 간식별로 학생을 분류한다', 'edu-games'), 'correct' => false),
                    array('key' => 'd', 'label' => __('형제자매 수가 많은 학생부터 살펴본다', 'edu-games'), 'correct' => false),
                ),
                __('용돈 크기가 다르기 때문에 저축액만 보면 안 됩니다. 저축액을 용돈으로 나눈 저축률(5번 4%, 1·3·7번 10%)이 가장 정확한 기준입니다.', 'edu-games')
            ),
            self::sample(
                'class-recycling',
                __('재활용률이 낮은 반 찾기', 'edu-games'),
                __('환경 동아리가 학급별 재활용 실천을 비교해, 1인당 재활용량이 가장 적은 반을 찾아 캠페인을 진행하려 합니다.', 'edu-games'),
                array(
                    array('key' => 'class_name', 'label' => __('반 이름', 'edu-games')),
                    array('key' => 'weekly_recycle_kg', 'label' => __('주간 재활용 무게(kg)', 'edu-games')),
                    array('key' => 'student_count', 'label' => __('학생 수(명)', 'edu-games')),
                    array('key' => 'homeroom_teacher', 'label' => __('담임 선생님', 'edu-games')),
                    array('key' => 'classroom_floor', 'label' => __('교실 위치(층)', 'edu-games')),
                    array('key' => 'lunch_satisfaction', 'label' => __('급식 만족도(5점 만점)', 'edu-games')),
                ),
                array(
                    array('class_name' => __('1반', 'edu-games'), 'weekly_recycle_kg' => 24, 'student_count' => 20, 'homeroom_teacher' => __('김선생님', 'edu-games'), 'classroom_floor' => 2, 'lunch_satisfaction' => 4.1),
                    array('class_name' => __('2반', 'edu-games'), 'weekly_recycle_kg' => 15, 'student_count' => 18, 'homeroom_teacher' => __('박선생님', 'edu-games'), 'classroom_floor' => 3, 'lunch_satisfaction' => 3.8),
                    array('class_name' => __('3반', 'edu-games'), 'weekly_recycle_kg' => 30, 'student_count' => 25, 'homeroom_teacher' => __('이선생님', 'edu-games'), 'classroom_floor' => 2, 'lunch_satisfaction' => 4.0),
                    array('class_name' => __('4반', 'edu-games'), 'weekly_recycle_kg' => 10, 'student_count' => 22, 'homeroom_teacher' => __('최선생님', 'edu-games'), 'classroom_floor' => 1, 'lunch_satisfaction' => 3.5),
                    array('class_name' => __('5반', 'edu-games'), 'weekly_recycle_kg' => 18, 'student_count' => 15, 'homeroom_teacher' => __('정선생님', 'edu-games'), 'classroom_floor' => 3, 'lunch_satisfaction' => 4.3),
                    array('class_name' => __('6반', 'edu-games'), 'weekly_recycle_kg' => 12, 'student_count' => 24, 'homeroom_teacher' => __('한선생님', 'edu-games'), 'classroom_floor' => 1, 'lunch_satisfaction' => 3.9),
                ),
                array('weekly_recycle_kg', 'student_count'),
                array(
                    array('key' => 'a', 'label' => __('주간 재활용 무게만 보고 가장 적은 반을 찾는다', 'edu-games'), 'correct' => false),
                    array('key' => 'b', 'label' => __('재활용 무게를 학생 수로 나눈 1인당 재활용량이 적은 반을 찾는다', 'edu-games'), 'correct' => true),
                    array('key' => 'c', 'label' => __('담임 선생님별로 비교한다', 'edu-games'), 'correct' => false),
                    array('key' => 'd', 'label' => __('교실 위치(층)별로 비교한다', 'edu-games'), 'correct' => false),
                ),
                __('반마다 학생 수가 다르므로 총 무게만 보면 불공평합니다. 1인당 재활용량(4반 0.45kg, 6반 0.5kg)이 가장 적은 반이 정답입니다.', 'edu-games')
            ),
            self::sample(
                'online-class-focus',
                __('온라인 수업 집중도가 낮은 시간대 찾기', 'edu-games'),
                __('온라인 수업 중 학생들의 집중도가 특히 떨어지는 시간대를 찾아 쉬는시간을 조정하려 합니다.', 'edu-games'),
                array(
                    array('key' => 'time_slot', 'label' => __('시간대', 'edu-games')),
                    array('key' => 'chat_participants', 'label' => __('채팅 참여 학생 수', 'edu-games')),
                    array('key' => 'camera_on_count', 'label' => __('카메라 켠 학생 수', 'edu-games')),
                    array('key' => 'weather', 'label' => __('그날 날씨', 'edu-games')),
                    array('key' => 'teacher_name', 'label' => __('담당 선생님', 'edu-games')),
                    array('key' => 'room_number', 'label' => __('교실 번호', 'edu-games')),
                ),
                array(
                    array('time_slot' => __('1교시(9시)', 'edu-games'), 'chat_participants' => 28, 'camera_on_count' => 30, 'weather' => __('맑음', 'edu-games'), 'teacher_name' => __('김교사', 'edu-games'), 'room_number' => 101),
                    array('time_slot' => __('2교시(10시)', 'edu-games'), 'chat_participants' => 25, 'camera_on_count' => 27, 'weather' => __('맑음', 'edu-games'), 'teacher_name' => __('박교사', 'edu-games'), 'room_number' => 102),
                    array('time_slot' => __('3교시(11시)', 'edu-games'), 'chat_participants' => 12, 'camera_on_count' => 15, 'weather' => __('비', 'edu-games'), 'teacher_name' => __('이교사', 'edu-games'), 'room_number' => 103),
                    array('time_slot' => __('4교시(13시)', 'edu-games'), 'chat_participants' => 10, 'camera_on_count' => 13, 'weather' => __('흐림', 'edu-games'), 'teacher_name' => __('최교사', 'edu-games'), 'room_number' => 104),
                    array('time_slot' => __('5교시(14시)', 'edu-games'), 'chat_participants' => 26, 'camera_on_count' => 29, 'weather' => __('흐림', 'edu-games'), 'teacher_name' => __('정교사', 'edu-games'), 'room_number' => 105),
                    array('time_slot' => __('6교시(15시)', 'edu-games'), 'chat_participants' => 27, 'camera_on_count' => 28, 'weather' => __('맑음', 'edu-games'), 'teacher_name' => __('한교사', 'edu-games'), 'room_number' => 106),
                ),
                array('chat_participants', 'camera_on_count'),
                array(
                    array('key' => 'a', 'label' => __('채팅 참여 학생 수만 보고 판단한다', 'edu-games'), 'correct' => false),
                    array('key' => 'b', 'label' => __('채팅 참여 학생 수와 카메라 켠 학생 수가 함께 낮은 시간대를 찾는다', 'edu-games'), 'correct' => true),
                    array('key' => 'c', 'label' => __('그날의 날씨별로 비교한다', 'edu-games'), 'correct' => false),
                    array('key' => 'd', 'label' => __('담당 선생님별로 비교한다', 'edu-games'), 'correct' => false),
                ),
                __('두 지표가 함께 낮은 3교시와 4교시(특히 점심시간 전후)가 집중도가 떨어지는 시간대로 보입니다.', 'edu-games')
            ),
            self::sample(
                'customer-churn-risk',
                __('다시 방문할 가능성이 낮은 고객 찾기', 'edu-games'),
                __('온라인 쇼핑몰이 예전엔 자주 구매했지만 최근 오랫동안 다시 구매하지 않은 "이탈 위험" 고객을 찾아 안내 메일을 보내려 합니다.', 'edu-games'),
                array(
                    array('key' => 'customer_id', 'label' => __('고객 번호', 'edu-games')),
                    array('key' => 'recent_purchase_amount', 'label' => __('최근 구매 금액(원)', 'edu-games')),
                    array('key' => 'purchase_frequency_month', 'label' => __('월평균 구매 횟수', 'edu-games')),
                    array('key' => 'days_since_last_purchase', 'label' => __('마지막 구매 후 지난 일수', 'edu-games')),
                    array('key' => 'favorite_color', 'label' => __('선호 색상', 'edu-games')),
                    array('key' => 'membership_years', 'label' => __('가입 기간(년)', 'edu-games')),
                ),
                array(
                    array('customer_id' => 'C1', 'recent_purchase_amount' => 50000, 'purchase_frequency_month' => 3, 'days_since_last_purchase' => 5, 'favorite_color' => __('빨강', 'edu-games'), 'membership_years' => 2),
                    array('customer_id' => 'C2', 'recent_purchase_amount' => 80000, 'purchase_frequency_month' => 2, 'days_since_last_purchase' => 60, 'favorite_color' => __('파랑', 'edu-games'), 'membership_years' => 1),
                    array('customer_id' => 'C3', 'recent_purchase_amount' => 30000, 'purchase_frequency_month' => 4, 'days_since_last_purchase' => 3, 'favorite_color' => __('초록', 'edu-games'), 'membership_years' => 3),
                    array('customer_id' => 'C4', 'recent_purchase_amount' => 120000, 'purchase_frequency_month' => 3, 'days_since_last_purchase' => 90, 'favorite_color' => __('노랑', 'edu-games'), 'membership_years' => 4),
                    array('customer_id' => 'C5', 'recent_purchase_amount' => 45000, 'purchase_frequency_month' => 3, 'days_since_last_purchase' => 4, 'favorite_color' => __('보라', 'edu-games'), 'membership_years' => 2),
                    array('customer_id' => 'C6', 'recent_purchase_amount' => 20000, 'purchase_frequency_month' => 1, 'days_since_last_purchase' => 75, 'favorite_color' => __('검정', 'edu-games'), 'membership_years' => 1),
                ),
                array('purchase_frequency_month', 'days_since_last_purchase'),
                array(
                    array('key' => 'a', 'label' => __('최근 구매 금액이 큰 고객부터 살펴본다', 'edu-games'), 'correct' => false),
                    array('key' => 'b', 'label' => __('월평균 구매 횟수가 있었지만 마지막 구매 후 지난 일수가 큰 고객을 함께 살펴본다', 'edu-games'), 'correct' => true),
                    array('key' => 'c', 'label' => __('선호 색상별로 고객을 분류한다', 'edu-games'), 'correct' => false),
                    array('key' => 'd', 'label' => __('가입 기간이 긴 고객부터 살펴본다', 'edu-games'), 'correct' => false),
                ),
                __('평소 구매 횟수가 있었는데도 오랫동안 구매가 없는 C2, C4 같은 고객이 이탈 위험이 큽니다. 구매 금액이나 가입 기간만으로는 알 수 없습니다.', 'edu-games')
            ),
            self::sample(
                'traffic-congestion-cause',
                __('교통 혼잡 원인 파악하기', 'edu-games'),
                __('어느 도로에서 평균 속도가 유독 느렸던 날의 원인을 사고나 날씨에서 찾아보려 합니다.', 'edu-games'),
                array(
                    array('key' => 'date', 'label' => __('날짜', 'edu-games')),
                    array('key' => 'avg_speed_kmh', 'label' => __('평균 속도(km/h)', 'edu-games')),
                    array('key' => 'accident_count', 'label' => __('사고 건수', 'edu-games')),
                    array('key' => 'rainfall_mm', 'label' => __('강수량(mm)', 'edu-games')),
                    array('key' => 'district_office', 'label' => __('담당 구청', 'edu-games')),
                    array('key' => 'road_pavement_age', 'label' => __('도로 포장 연차(년)', 'edu-games')),
                ),
                array(
                    array('date' => '9/1', 'avg_speed_kmh' => 45, 'accident_count' => 0, 'rainfall_mm' => 0, 'district_office' => __('강남구청', 'edu-games'), 'road_pavement_age' => 5),
                    array('date' => '9/2', 'avg_speed_kmh' => 20, 'accident_count' => 3, 'rainfall_mm' => 35, 'district_office' => __('강남구청', 'edu-games'), 'road_pavement_age' => 5),
                    array('date' => '9/3', 'avg_speed_kmh' => 42, 'accident_count' => 1, 'rainfall_mm' => 2, 'district_office' => __('강남구청', 'edu-games'), 'road_pavement_age' => 5),
                    array('date' => '9/4', 'avg_speed_kmh' => 15, 'accident_count' => 4, 'rainfall_mm' => 50, 'district_office' => __('강남구청', 'edu-games'), 'road_pavement_age' => 5),
                    array('date' => '9/5', 'avg_speed_kmh' => 40, 'accident_count' => 0, 'rainfall_mm' => 5, 'district_office' => __('강남구청', 'edu-games'), 'road_pavement_age' => 5),
                    array('date' => '9/6', 'avg_speed_kmh' => 44, 'accident_count' => 1, 'rainfall_mm' => 0, 'district_office' => __('강남구청', 'edu-games'), 'road_pavement_age' => 5),
                ),
                array('avg_speed_kmh', 'accident_count', 'rainfall_mm'),
                array(
                    array('key' => 'a', 'label' => __('평균 속도만 보고 혼잡한 날을 찾는다', 'edu-games'), 'correct' => false),
                    array('key' => 'b', 'label' => __('평균 속도가 낮고 사고 건수와 강수량이 함께 높은 날을 살펴 원인을 파악한다', 'edu-games'), 'correct' => true),
                    array('key' => 'c', 'label' => __('담당 구청별로 비교한다', 'edu-games'), 'correct' => false),
                    array('key' => 'd', 'label' => __('도로 포장 연차별로 비교한다', 'edu-games'), 'correct' => false),
                ),
                __('9/2와 9/4처럼 속도가 낮으면서 사고와 강수량도 함께 높은 날을 보면, 비와 사고가 혼잡의 실제 원인으로 보입니다. 이 기간 동안 담당 구청과 도로 포장 연차는 전혀 바뀌지 않았으므로 원인이 될 수 없습니다.', 'edu-games')
            ),
        );
    }

    /**
     * @param array<int, array{key:string,label:string}> $columns
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string> $relevant_columns
     * @param array<int, array{key:string,label:string,correct:bool}> $processing_options
     * @return array<string, mixed>
     */
    private static function sample(
        string $id,
        string $title,
        string $context,
        array $columns,
        array $rows,
        array $relevant_columns,
        array $processing_options,
        string $explanation
    ): array {
        return array(
            'id' => $id,
            'title' => $title,
            'context' => $context,
            'columns' => $columns,
            'rows' => $rows,
            'relevantColumns' => $relevant_columns,
            'processingOptions' => $processing_options,
            'explanation' => $explanation,
        );
    }
}
