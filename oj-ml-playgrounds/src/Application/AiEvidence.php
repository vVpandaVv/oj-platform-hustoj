<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Application;

use OJML\Playgrounds\Domain\Problem;
use OJML\Playgrounds\Domain\Rubric;
use OJML\Playgrounds\Domain\DatasetContext;

/** Converts the verified, non-executing preview to the versioned AI evidence contract. */
final class AiEvidence
{
    public static function build(array $attempt, array $assignment, array $preview): array
    {
        if (($preview['source'] ?? '') !== 'verified-read-only'
            || !is_array($preview['content'] ?? null)
            || ($preview['content']['notices'] ?? []) !== []) {
            throw new Problem('OJ_ML_AI_EVIDENCE_INCOMPLETE', '안전 요약에 생략된 내용이 있어 AI 평가를 요청할 수 없습니다. 원본 바로보기로 직접 검토하세요.', 409);
        }
        $rubric = Rubric::normalize($assignment['rubric'] ?? []);
        if (!Rubric::structured($rubric) || empty($rubric['criteria'])) {
            throw new Problem('OJ_ML_AI_RUBRIC_REQUIRED', '과제에 항목별 평가 기준과 배점을 먼저 설정하세요.', 409);
        }
        $hash = strtolower((string) ($attempt['artifact']['sha256'] ?? ''));
        if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
            throw new Problem('OJ_ML_AI_EVIDENCE_INVALID', '제출 원본의 무결성 기록을 확인할 수 없습니다.', 409);
        }
        $view = $preview['content'];
        $experiments = [];
        $runCount = 0;
        if (count($view['experiments'] ?? []) > 20) {
            self::incomplete();
        }
        foreach (($view['experiments'] ?? []) as $experiment) {
            $runs = [];
            foreach (($experiment['runs'] ?? []) as $run) {
                if (++$runCount > 50) {
                    self::incomplete();
                }
                $runs[] = array_intersect_key($run, array_flip([
                    'algorithm', 'status', 'computedBy', 'hyperparameters', 'metrics',
                    'featureImportance', 'engine', 'failureCode', 'warningCode',
                    'modelOmitted',
                ])) + [
                    'runNumber' => count($runs) + 1,
                    'model' => self::pick($run['model'] ?? null, ['format', 'includesPreprocessing', 'sizeBytes']),
                    'perClass' => self::anonymousClasses($run['perClass'] ?? []),
                    'confusionMatrix' => self::anonymousMatrix($run['confusionMatrix'] ?? null),
                ];
            }
            $experiments[] = self::pick($experiment, ['taskType', 'sourceExperimentIndex']) + self::settings($experiment) + [
                'experimentNumber' => count($experiments) + 1,
                'runs' => $runs,
            ];
        }
        $answers = [];
        foreach (($view['portfolio']['sections'] ?? []) as $section) {
            // Never promote a student-edited question/title/description to teacher instructions.
            $answers[] = array_intersect_key($section, array_flip(['id', 'answer', 'answered', 'attachmentCount']));
        }
        $result = [
            'schema' => 'oj_ml_ai_evidence.v1',
            'source_id' => (string) $attempt['uuid'],
            'source_sha256' => $hash,
            'context' => ['type' => (string) $assignment['context_type'], 'id' => (string) $assignment['context_id']],
            'assignment' => [
                'title' => self::plain((string) $assignment['title']),
                'instructions' => self::plain((string) ($assignment['description'] ?? '')),
            ],
            'rubric' => array_map(static fn (array $criterion): array => self::criterion($criterion, $rubric['version']), $rubric['criteria']),
            'evidence' => [
                'project' => [
                    'taskType' => $view['project']['taskType'] ?? null,
                    'dataType' => $view['project']['dataType'] ?? null,
                    'appVersion' => $view['project']['appVersion'] ?? null,
                    'formatVersion' => $view['project']['formatVersion'] ?? null,
                    'settings' => self::settings($view['settings'] ?? []),
                    'experimentCount' => count($experiments),
                    'runCount' => $runCount,
                    'experiments' => $experiments,
                    'datasetContext' => DatasetContext::from($assignment['policy'] ?? [], $view['portfolio'] ?? []),
                    'datasetProfiles' => DatasetProfileEvidence::project($view['datasetProfiles'] ?? null),
                ],
                'portfolio' => [
                    'teacherTemplate' => $assignment['policy']['portfolioTemplate'] ?? null,
                    'answers' => $answers,
                ],
            ],
            'limitations' => [
                '성능 지표는 학생 제출 기록이며 서버에서 재실행해 검증한 값이 아닙니다.',
                '원본 데이터 행·이미지·모델·첨부 파일은 분석에 포함되지 않았습니다. 이 자료가 필요한 판단은 교사가 직접 확인해야 합니다.',
                '현재 프로젝트 설정과 실험 당시 설정은 다를 수 있습니다. 실험 당시 기록에 없는 설정은 확인할 수 없습니다.',
                '프로젝트 이름·식별자·시각과 원시 실행 로그는 제외하며, 기록 순서·상태·설정·결과 요약만 포함합니다.',
                '통계는 제출 원본의 전처리 전 CSV를 서버에서 집계한 값입니다. 데이터의 출처·변수 의미·결측 원인과 전처리 후 변화, 학습·평가 분리의 실제 실행은 검증하지 않았습니다.',
                '데이터 설명은 교사·학생이 작성한 맥락이며 사실 여부가 검증된 자료가 아닙니다. 범주 이름과 원본 행은 전달하지 않으며 작은 집단·식별자형 수치 등의 통계를 보호 목적으로 생략할 수 있습니다.',
            ],
        ];
        $readiness = EvidenceReadiness::assess($rubric, $result['evidence']['project']);
        $result['evidence']['project']['evidenceReadiness'] = $readiness;
        foreach ($result['rubric'] as $index => &$criterion) {
            $check = $readiness['criteria'][$index];
            $criterion['description'] .= "\n\n[필수 평가 근거]\n"
                . json_encode($check, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . "\nstatus가 needs_review인 항목은 점수를 null로 두고 교사 확인이 필요한 이유를 설명하세요. "
                . "자료가 있더라도 내용상 근거가 부족하면 추측하여 채점하지 마세요. 데이터 설명은 사실 검증이 아니며, 통계는 전처리 전 집계이고 성능 지표는 재실행하지 않은 제출 기록입니다. "
                . "동일 데이터·분할 조건의 비교만 성능 비교 근거로 사용하고 누수 없음이나 최적 처리임을 단정하지 마세요.";
            if (strlen($criterion['description']) > 8000) {
                throw new Problem('OJ_ML_AI_EVIDENCE_TOO_LARGE', '평가 기준과 필수 근거 안내가 AI 전달 한도를 초과했습니다. 기준을 줄이거나 직접 평가하세요.', 409);
            }
        }
        unset($criterion);
        $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || strlen($encoded) > 120000 || strlen($result['assignment']['instructions']) > 20000) {
            throw new Problem('OJ_ML_AI_EVIDENCE_TOO_LARGE', 'AI 평가 자료가 제한을 초과했습니다. 내용을 줄이거나 직접 평가하세요.', 409);
        }
        return $result;
    }

    /** Existing AI gateways keep description in their provider input and cache digest. */
    private static function criterion(array $criterion, int $version): array
    {
        $result = [
            'id' => $criterion['id'], 'label' => $criterion['title'],
            'description' => $criterion['description'], 'max_score' => $criterion['maxScore'],
        ];
        if ($version === 2) {
            $levels = json_encode($criterion['levels'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($levels)) {
                throw new Problem('OJ_ML_AI_EVIDENCE_INVALID', '등급별 평가 기준을 안전하게 전달할 수 없습니다.', 409);
            }
            // Metadata alone would be dropped by older compatible gateways: the complete table is also teacher instruction text.
            $result['levels'] = $criterion['levels'];
            $result['description'] .= "\n\n[교사 등급별 채점표 — 루브릭 버전 2]\n"
                . $levels . "\n정의된 등급 중 정확히 하나를 선택하고 그 등급의 고정 점수를 그대로 사용하세요. "
                . "등급 사이의 점수·부분 점수·반올림한 점수를 만들지 마세요. "
                . "제출 근거로 등급을 확인할 수 없으면 점수는 null로 두고 교사 검토가 필요한 이유를 설명하세요. "
                . "선택한 등급과 구체 기준에 해당하는 제출 근거를 피드백에 제시하세요.";
            if (strlen($result['description']) > 8000) {
                throw new Problem('OJ_ML_AI_EVIDENCE_TOO_LARGE', '등급별 평가 기준이 AI 전달 한도를 초과했습니다. 기준을 줄이거나 교사가 직접 평가하세요.', 409);
            }
        }
        return $result;
    }

    /** Keep the AI boundary independent of future fields added to the teacher preview. */
    private static function settings(array $settings): array
    {
        return self::pick($settings, ['runtime', 'sampleCount']) + [
            'split' => self::pick($settings['split'] ?? null, ['method', 'testSize', 'stratify', 'randomState']),
            'selectedAlgorithms' => array_map(
                static fn (array $algorithm): array => self::pick($algorithm, ['algorithm', 'runtime']),
                $settings['selectedAlgorithms'] ?? []
            ),
            'hyperparameters' => self::parameterGroups($settings['hyperparameters'] ?? []),
            'data' => self::data($settings['data'] ?? []),
        ];
    }

    private static function data(array $data): array
    {
        $result = self::pick($data, [
            'type', 'datasetConfigured', 'testDatasetConfigured', 'predictDatasetConfigured',
            'features', 'target', 'backboneId', 'canonicalSize', 'format', 'quality',
        ]) + ['preprocessing' => self::pick($data['preprocessing'] ?? null, ['missing', 'scaling', 'categoricalEncoding'])];
        if (is_array($data['categories'] ?? null)) { $result['categoryCount'] = count($data['categories']); }
        return $result;
    }

    private static function parameterGroups(array $groups): array
    {
        return array_map(static fn (array $group): array => self::pick($group, ['algorithm', 'runtime']) + [
            'items' => array_map(static fn (array $item): array => self::pick($item, ['name', 'value']), $group['items'] ?? []),
        ], $groups);
    }

    /** Class values can contain personal data. Keep numeric metrics, not source labels. */
    private static function anonymousClasses(array $classes): array
    {
        $result = [];
        foreach ($classes as $row) {
            $result[] = self::pick($row, ['precision', 'recall', 'specificity', 'f1', 'support'])
                + ['categoryNumber' => count($result) + 1];
        }
        return $result;
    }

    private static function anonymousMatrix(mixed $matrix): ?array
    {
        if (!is_array($matrix) || !is_array($matrix['matrix'] ?? null)) { return null; }
        return ['categoryNumbers' => range(1, count($matrix['matrix'])), 'matrix' => $matrix['matrix']];
    }

    private static function pick(mixed $value, array $keys): array
    {
        return is_array($value) ? array_intersect_key($value, array_flip($keys)) : [];
    }

    private static function incomplete(): never
    {
        throw new Problem('OJ_ML_AI_EVIDENCE_INCOMPLETE', '안전 요약의 기록 수가 한도를 초과해 AI 평가를 요청할 수 없습니다. 원본 바로보기로 직접 검토하세요.', 409);
    }

    private static function plain(string $value): string
    {
        return trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
