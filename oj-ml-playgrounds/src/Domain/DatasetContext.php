<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Domain;

/** Description is educational context, never proof of the submitted data's meaning. */
final class DatasetContext
{
    public static function policy(array $policy): array
    {
        $description = $policy['datasetDescription'] ?? '';
        $section = $policy['datasetDescriptionSectionId'] ?? 'dataset_description';
        if (!is_string($description) || strlen($description) > 16000 || preg_match('//u', $description) !== 1
            || preg_match_all('/./us', $description) > 4000
            || !is_string($section) || preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/D', $section) !== 1) {
            throw new Problem('OJ_ML_DATASET_DESCRIPTION_INVALID', '데이터 설명은 4,000자 이내, 학생 설명 항목 ID는 영문으로 시작하는 1~64자로 입력하세요.', 400);
        }
        return ['datasetDescription' => self::plain($description), 'datasetDescriptionSectionId' => $section];
    }

    public static function from(array $policy, array $portfolio): array
    {
        $context = self::policy($policy);
        $answer = '';
        foreach ($portfolio['sections'] ?? [] as $section) {
            if (($section['id'] ?? null) === $context['datasetDescriptionSectionId'] && is_string($section['answer'] ?? null)) {
                $answer = self::plain($section['answer']);
                break;
            }
        }
        return [
            'teacherDescription' => $context['datasetDescription'],
            'studentDescription' => $answer,
            'studentDescriptionSectionId' => $context['datasetDescriptionSectionId'],
            'provenance' => 'teacher_assignment_and_student_portfolio',
            'verifiedMeaning' => false,
        ];
    }

    private static function plain(string $value): string
    {
        return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', strip_tags($value)) ?? '');
    }
}
