<?php
declare(strict_types=1);

namespace EduGames\Games\Modules\Outlier_Detection;

use EduGames\Games\Contracts\Game_Module;

final class Outlier_Detection_Module implements Game_Module {
    public const ID = 'outlier-detection';
    public const SHORTCODE = 'outlier_detection';
    public const BLOCK = 'edu-games/outlier-detection';

    public function id(): string {
        return self::ID;
    }

    public function definition(): array {
        return array(
            'id' => $this->id(),
            'title' => __('이상치 찾기', 'edu-games'),
            'category' => 'data-analysis',
            'description' => __('수직선에서 떨어진 값을 검토함으로 분리하고 평균의 변화를 확인하며, 제한 시간 안에 더 많은 데이터 모음을 살펴봅니다.', 'edu-games'),
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
            'rankedRounds' => 8,
            'defaultDifficulty' => 'easy',
            'difficulties' => array(
                'easy' => array(
                    'label' => __('기초', 'edu-games'),
                    'description' => __('한눈에 벗어난 값 1개를 찾습니다.', 'edu-games'),
                    'baseScore' => 100,
                    'samples' => array('temp-cold-day', 'class-score-high', 'daily-steps-low', 'apple-price-high', 'plant-height-low', 'sleep-hours-low', 'pencil-length-high', 'lunch-wait-high', 'flower-petals-low', 'reading-minutes-low'),
                ),
                'normal' => array(
                    'label' => __('표준', 'edu-games'),
                    'description' => __('단위와 맥락을 함께 보고 이상치를 판단합니다.', 'edu-games'),
                    'baseScore' => 130,
                    'samples' => array('rainfall-heavy', 'battery-drain', 'bus-delay', 'book-pages', 'sensor-distance', 'classroom-noise-high', 'milk-temp-high', 'delivery-days-zero', 'river-depth-low', 'cafe-orders-high'),
                ),
                'hard' => array(
                    'label' => __('심화', 'edu-games'),
                    'description' => __('여러 개의 이상치나 양쪽 끝 이상치를 찾습니다.', 'edu-games'),
                    'baseScore' => 165,
                    'samples' => array('heart-rate-two', 'store-sales-two', 'internet-latency', 'lab-ph', 'marathon-time', 'blood-pressure-two', 'exam-time-two', 'energy-use-two', 'package-weight-two', 'water-turbidity-high'),
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
                'temp-cold-day',
                __('일주일 기온', 'edu-games'),
                __('7일 동안 같은 지역에서 낮 2시에 측정한 기온입니다.', 'edu-games'),
                __('섭씨', 'edu-games'),
                array(22, 23, 21, 24, 3, 22, 23),
                array(4),
                __('대부분 21~24도인데 3도만 크게 낮습니다.', 'edu-games')
            ),
            self::sample(
                'class-score-high',
                __('쪽지 시험 점수', 'edu-games'),
                __('같은 난이도의 10점 만점 쪽지 시험 결과입니다.', 'edu-games'),
                __('점', 'edu-games'),
                array(6, 7, 6, 8, 7, 10, 6, 7),
                array(5),
                __('대부분 6~8점 사이인데 10점만 뚜렷하게 높습니다.', 'edu-games')
            ),
            self::sample(
                'daily-steps-low',
                __('하루 걸음 수', 'edu-games'),
                __('한 학생의 평일 걸음 수 기록입니다.', 'edu-games'),
                __('걸음', 'edu-games'),
                array(7200, 7600, 6950, 7340, 810, 7480, 7010),
                array(4),
                __('대부분 7000걸음 안팎인데 810걸음만 매우 낮습니다.', 'edu-games')
            ),
            self::sample(
                'apple-price-high',
                __('사과 한 봉지 가격', 'edu-games'),
                __('여러 가게에서 같은 사과 한 봉지의 가격을 조사했습니다.', 'edu-games'),
                __('원', 'edu-games'),
                array(5900, 6100, 5800, 6200, 6050, 14900, 6000),
                array(5),
                __('대부분 5800~6200원인데 14900원은 다른 가격대입니다.', 'edu-games')
            ),
            self::sample(
                'plant-height-low',
                __('화분 새싹 키', 'edu-games'),
                __('같은 날 심은 새싹의 12일 뒤 키입니다.', 'edu-games'),
                __('cm', 'edu-games'),
                array(13, 15, 14, 16, 4, 14, 15),
                array(4),
                __('대부분 13~16cm인데 4cm만 크게 작습니다.', 'edu-games')
            ),
            self::sample(
                'sleep-hours-low',
                __('수면 시간 기록', 'edu-games'),
                __('한 학생의 일주일 수면 시간을 기록했습니다.', 'edu-games'),
                __('시간', 'edu-games'),
                array(7, 8, 7, 6, 2, 7, 8),
                array(4),
                __('대부분 6~8시간인데 2시간만 눈에 띄게 짧습니다.', 'edu-games')
            ),
            self::sample(
                'pencil-length-high',
                __('연필 길이 측정', 'edu-games'),
                __('같은 종류의 새 연필 길이를 여러 개 측정했습니다.', 'edu-games'),
                __('cm', 'edu-games'),
                array(18, 19, 17, 18, 42, 19, 18),
                array(4),
                __('대부분 17~19cm인데 42cm는 같은 종류의 연필로 보기 어렵습니다.', 'edu-games')
            ),
            self::sample(
                'lunch-wait-high',
                __('급식 대기 시간', 'edu-games'),
                __('한 반 학생들이 급식을 받기까지 기다린 시간입니다.', 'edu-games'),
                __('분', 'edu-games'),
                array(4, 5, 3, 4, 28, 5, 4),
                array(4),
                __('대부분 3~5분인데 28분은 다른 값들과 크게 다릅니다.', 'edu-games')
            ),
            self::sample(
                'flower-petals-low',
                __('꽃잎 수 관찰', 'edu-games'),
                __('같은 꽃밭에서 비슷한 꽃들의 꽃잎 수를 세었습니다.', 'edu-games'),
                __('장', 'edu-games'),
                array(8, 9, 8, 7, 1, 8, 9),
                array(4),
                __('대부분 7~9장인데 1장은 관찰이나 기록 오류일 가능성이 큽니다.', 'edu-games')
            ),
            self::sample(
                'reading-minutes-low',
                __('하루 독서 시간', 'edu-games'),
                __('매일 정해진 시간에 책을 읽은 시간을 기록했습니다.', 'edu-games'),
                __('분', 'edu-games'),
                array(24, 26, 25, 27, 3, 26, 24),
                array(4),
                __('대부분 20분대인데 3분만 매우 짧습니다.', 'edu-games')
            ),
            self::sample(
                'rainfall-heavy',
                __('한 달 강수량', 'edu-games'),
                __('비슷한 기후의 여러 도시에서 같은 달 강수량을 비교했습니다.', 'edu-games'),
                __('mm', 'edu-games'),
                array(78, 83, 74, 80, 91, 312, 86, 79),
                array(5),
                __('대부분 70~90mm대인데 312mm는 매우 큽니다.', 'edu-games')
            ),
            self::sample(
                'battery-drain',
                __('스마트폰 배터리 감소량', 'edu-games'),
                __('같은 앱을 30분 사용했을 때 배터리 감소량입니다.', 'edu-games'),
                __('%', 'edu-games'),
                array(7, 8, 6, 8, 7, 31, 9, 7),
                array(5),
                __('대부분 6~9%인데 31%는 비정상적으로 큽니다.', 'edu-games')
            ),
            self::sample(
                'bus-delay',
                __('버스 지연 시간', 'edu-games'),
                __('같은 노선 버스의 평일 아침 지연 시간입니다.', 'edu-games'),
                __('분', 'edu-games'),
                array(3, 4, 5, 2, 4, 37, 3, 5),
                array(5),
                __('대부분 2~5분인데 37분은 다른 패턴입니다.', 'edu-games')
            ),
            self::sample(
                'book-pages',
                __('짧은 글 페이지 수', 'edu-games'),
                __('같은 형식으로 제출한 독후감의 페이지 수입니다.', 'edu-games'),
                __('쪽', 'edu-games'),
                array(3, 4, 3, 4, 5, 3, 18, 4),
                array(6),
                __('대부분 3~5쪽인데 18쪽은 범위를 크게 벗어납니다.', 'edu-games')
            ),
            self::sample(
                'sensor-distance',
                __('거리 센서 측정값', 'edu-games'),
                __('고정된 물체까지의 거리를 여러 번 측정했습니다.', 'edu-games'),
                __('cm', 'edu-games'),
                array(48, 49, 47, 50, 48, 12, 49, 51),
                array(5),
                __('대부분 47~51cm인데 12cm는 센서 오류처럼 보입니다.', 'edu-games')
            ),
            self::sample(
                'classroom-noise-high',
                __('교실 소음 측정', 'edu-games'),
                __('조용한 활동 시간에 같은 교실에서 여러 번 소음을 측정했습니다.', 'edu-games'),
                __('dB', 'edu-games'),
                array(52, 55, 54, 53, 81, 56, 52),
                array(4),
                __('대부분 50dB대인데 81dB는 갑작스러운 큰 소음으로 보입니다.', 'edu-games')
            ),
            self::sample(
                'milk-temp-high',
                __('우유 보관 온도', 'edu-games'),
                __('냉장 보관 중인 우유의 온도를 여러 상자에서 확인했습니다.', 'edu-games'),
                __('섭씨', 'edu-games'),
                array(3, 4, 4, 5, 18, 3, 4, 5),
                array(4),
                __('대부분 3~5도인데 18도는 냉장 상태와 맞지 않습니다.', 'edu-games')
            ),
            self::sample(
                'delivery-days-zero',
                __('택배 배송 기간', 'edu-games'),
                __('같은 지역으로 보낸 택배가 도착하기까지 걸린 일수입니다.', 'edu-games'),
                __('일', 'edu-games'),
                array(2, 3, 3, 4, 0, 3, 2, 3),
                array(4),
                __('대부분 2~4일인데 0일은 배송 기간 자료로 보기 어렵습니다.', 'edu-games')
            ),
            self::sample(
                'river-depth-low',
                __('하천 수심 측정', 'edu-games'),
                __('같은 구간의 하천 수심을 일정한 간격으로 측정했습니다.', 'edu-games'),
                __('m', 'edu-games'),
                array(1.8, 2.0, 1.9, 1.7, 0.2, 1.8, 1.9),
                array(4),
                __('대부분 1.7~2.0m인데 0.2m는 주변 흐름과 크게 다릅니다.', 'edu-games')
            ),
            self::sample(
                'cafe-orders-high',
                __('카페 시간대 주문량', 'edu-games'),
                __('비슷한 시간대의 음료 주문량을 며칠 동안 기록했습니다.', 'edu-games'),
                __('잔', 'edu-games'),
                array(42, 45, 39, 44, 113, 41, 46),
                array(4),
                __('대부분 40잔 안팎인데 113잔은 특별 행사나 기록 오류를 의심할 수 있습니다.', 'edu-games')
            ),
            self::sample(
                'heart-rate-two',
                __('휴식 중 심박수', 'edu-games'),
                __('휴식 상태에서 측정한 심박수입니다. 양쪽으로 벗어난 값을 모두 찾습니다.', 'edu-games'),
                __('bpm', 'edu-games'),
                array(72, 76, 74, 75, 39, 73, 121, 77),
                array(4, 6),
                __('대부분 70대인데 39는 너무 낮고 121은 너무 높습니다.', 'edu-games')
            ),
            self::sample(
                'store-sales-two',
                __('매장 하루 판매량', 'edu-games'),
                __('비슷한 규모의 매장들이 하루 동안 판 물건 수입니다.', 'edu-games'),
                __('개', 'edu-games'),
                array(92, 87, 95, 90, 14, 89, 184, 93),
                array(4, 6),
                __('대부분 80~90개대인데 14개와 184개가 크게 벗어납니다.', 'edu-games')
            ),
            self::sample(
                'internet-latency',
                __('인터넷 응답 시간', 'edu-games'),
                __('같은 서버에 접속했을 때 응답 시간입니다.', 'edu-games'),
                __('ms', 'edu-games'),
                array(23, 25, 24, 26, 22, 181, 27, 24, 25),
                array(5),
                __('대부분 20ms대인데 181ms는 네트워크 지연 이상치입니다.', 'edu-games')
            ),
            self::sample(
                'lab-ph',
                __('수질 pH 측정', 'edu-games'),
                __('같은 장소에서 채취한 물의 pH를 여러 번 측정했습니다.', 'edu-games'),
                __('pH', 'edu-games'),
                array(7.1, 7.0, 7.2, 6.9, 7.1, 4.8, 7.0, 7.2),
                array(5),
                __('대부분 중성 근처인데 4.8은 산성 쪽으로 크게 벗어납니다.', 'edu-games')
            ),
            self::sample(
                'marathon-time',
                __('마라톤 완주 시간', 'edu-games'),
                __('같은 동호회 참가자들의 완주 시간입니다.', 'edu-games'),
                __('분', 'edu-games'),
                array(236, 242, 239, 245, 231, 410, 240, 238),
                array(5),
                __('대부분 230~245분인데 410분은 매우 깁니다.', 'edu-games')
            ),
            self::sample(
                'blood-pressure-two',
                __('수축기 혈압 측정', 'edu-games'),
                __('비슷한 조건에서 측정한 수축기 혈압입니다. 양쪽으로 벗어난 값을 찾습니다.', 'edu-games'),
                __('mmHg', 'edu-games'),
                array(118, 122, 119, 121, 82, 120, 168, 123),
                array(4, 6),
                __('대부분 118~123mmHg인데 82는 낮고 168은 높습니다.', 'edu-games')
            ),
            self::sample(
                'exam-time-two',
                __('문제 풀이 시간', 'edu-games'),
                __('같은 난이도의 연습 문제를 푸는 데 걸린 시간입니다.', 'edu-games'),
                __('분', 'edu-games'),
                array(48, 50, 49, 47, 12, 51, 93, 50),
                array(4, 6),
                __('대부분 47~51분인데 12분과 93분은 범위를 크게 벗어납니다.', 'edu-games')
            ),
            self::sample(
                'energy-use-two',
                __('교실 전력 사용량', 'edu-games'),
                __('비슷한 크기의 교실에서 하루 동안 사용한 전력량입니다.', 'edu-games'),
                __('kWh', 'edu-games'),
                array(31, 34, 32, 35, 6, 33, 78, 34),
                array(4, 6),
                __('대부분 30kWh대인데 6kWh와 78kWh는 사용 패턴이 크게 다릅니다.', 'edu-games')
            ),
            self::sample(
                'package-weight-two',
                __('소포 무게 검사', 'edu-games'),
                __('같은 상품을 담은 소포의 무게를 검사했습니다.', 'edu-games'),
                __('kg', 'edu-games'),
                array(1.2, 1.3, 1.1, 1.4, 0.1, 1.3, 4.8, 1.2),
                array(4, 6),
                __('대부분 1kg대인데 0.1kg과 4.8kg은 같은 상품 소포로 보기 어렵습니다.', 'edu-games')
            ),
            self::sample(
                'water-turbidity-high',
                __('물 탁도 측정', 'edu-games'),
                __('같은 정수 장치에서 나온 물의 탁도를 반복 측정했습니다.', 'edu-games'),
                __('NTU', 'edu-games'),
                array(1.8, 2.0, 1.9, 2.1, 2.0, 14.5, 1.7, 2.2),
                array(5),
                __('대부분 2NTU 안팎인데 14.5NTU는 물 상태나 측정 과정의 이상을 의심하게 합니다.', 'edu-games')
            ),
        );
    }

    /**
     * @param array<int, int|float> $values
     * @param array<int, int> $outlier_indexes
     * @return array<string, mixed>
     */
    private static function sample(
        string $id,
        string $title,
        string $context,
        string $unit,
        array $values,
        array $outlier_indexes,
        string $explanation
    ): array {
        return array(
            'id' => $id,
            'title' => $title,
            'context' => $context,
            'unit' => $unit,
            'values' => $values,
            'outlierIndexes' => $outlier_indexes,
            'explanation' => $explanation,
        );
    }
}
