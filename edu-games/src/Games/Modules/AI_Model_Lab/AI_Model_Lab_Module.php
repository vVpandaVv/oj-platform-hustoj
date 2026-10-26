<?php
declare(strict_types=1);

namespace EduGames\Games\Modules\AI_Model_Lab;

use EduGames\Games\Contracts\Game_Module;

final class AI_Model_Lab_Module implements Game_Module {
    public const ID = 'ai-model-lab';
    public const SHORTCODE = 'ai_model_lab';
    public const BLOCK = 'edu-games/ai-model-lab';

    public function id(): string {
        return self::ID;
    }

    public function definition(): array {
        return array(
            'id' => $this->id(),
            'title' => __('AI 모델 훈련소', 'edu-games'),
            'category' => 'ai',
            'description' => __('0~9 손글씨의 다양한 세 숫자 조합으로 로봇을 가르치고, 아홉 장의 기억으로 새로운 손글씨를 인식하는 기록에 도전합니다.', 'edu-games'),
            'status' => 'playable',
            'statusLabel' => __('플레이 가능', 'edu-games'),
            'block' => self::BLOCK,
            'shortcode' => self::SHORTCODE,
            'rules' => self::rules(),
        );
    }

    /**
     * Public summaries only. Historical activity files remain for old records.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array {
        return array(
            'activities' => array(
                array(
                    'key' => 'digit-labeling',
                    'title' => __('손글씨 숫자 인식', 'edu-games'),
                    'difficulty' => 'easy',
                    'datasetLabel' => __('scikit-learn 손글씨 숫자 데이터셋 (실제 8x8 이미지, 0/1/7 세 종류)', 'edu-games'),
                    'description' => __('8x8 흑백 이미지에 적힌 숫자가 0, 1, 7 중 무엇인지 직접 분류해 보세요. 전문 지식 없이 눈으로 보고 바로 판단할 수 있습니다.', 'edu-games'),
                ),
                array(
                    'key' => 'train-only',
                    'title' => __('훈련 자료로 전처리', 'edu-games'),
                    'difficulty' => 'normal',
                    'datasetLabel' => __('합성 측정 자료', 'edu-games'),
                    'description' => __('훈련·검증·평가 자료를 구분하고 훈련 관측값으로만 대체 규칙을 구합니다.', 'edu-games'),
                ),
                array(
                    'key' => 'metrics',
                    'title' => __('불균형 자료의 평가 지표', 'edu-games'),
                    'difficulty' => 'hard',
                    'datasetLabel' => __('가상 부품 검수 자료', 'edu-games'),
                    'description' => __('혼동행렬에서 놓친 불량과 정확도·재현율을 비교합니다.', 'edu-games'),
                ),
            ),
        );
    }
}
