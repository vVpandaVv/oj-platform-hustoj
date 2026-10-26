<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Inspection;

final class MlpxReadOnlyProjector
{
    public const SCHEMA_VERSION = 1;

    private const MAX_RESPONSE_BYTES = 262144;
    private const MAX_SECTIONS = 30;
    private const MAX_ORPHAN_ANSWERS = 10;
    private const MAX_ANSWER_BYTES = 20000;
    private const MAX_TOTAL_ANSWER_BYTES = 100000;
    private const MAX_LIST_ITEMS = 50;
    private const MAX_PARAMETERS = 20;
    private const MAX_EXPERIMENTS = 20;
    private const MAX_RUNS = 50;
    private const MAX_CLASS_ROWS = 50;
    private const MAX_MATRIX_DIMENSION = 20;
    private const MAX_IMPORTANCE_ROWS = 50;

    /** @var array<string,array<string,true>> */
    private const HYPERPARAMETERS_BY_ALGORITHM = [
        'decision_tree' => [
            'maxDepth' => true,
            'minNumSamples' => true,
            'max_depth' => true,
            'min_samples_split' => true,
        ],
        'random_forest' => ['nEstimators' => true, 'n_estimators' => true],
        'naive_bayes' => [],
        'knn' => ['k' => true, 'n_neighbors' => true],
        'logistic_regression' => ['C' => true, 'maxIter' => true, 'max_iter' => true, 'tol' => true],
        'linear_regression' => [],
        'svm' => ['C' => true],
        'k_means' => ['nClusters' => true, 'n_clusters' => true],
    ];

    /** @var array<string,true> */
    private const MODEL_OMISSION_REASONS = [
        'overBudget' => true,
        'tooLarge' => true,
        'engineUnsupported' => true,
    ];

    /** @var array<string,string> */
    private array $notices = [];
    private int $answerBytes = 0;
    private int $runCount = 0;

    /**
     * Create a bounded display DTO. No paths, dataset values, model bytes,
     * attachment contents, arbitrary objects, or executable markup survive.
     *
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $settings
     * @param array<string,mixed> $runs
     * @param array<string,mixed> $portfolio
     * @return array<string,mixed>
     */
    public function project(array $manifest, array $settings, array $runs, array $portfolio): array
    {
        $this->notices = [];
        $this->answerBytes = 0;
        $this->runCount = 0;

        $dataType = $this->text($manifest['dataType'] ?? null, 40, '프로젝트 데이터 유형') ?? '';
        $portfolioView = $this->portfolio($portfolio);
        $settingsView = $this->settings($settings, $dataType);
        $experimentsView = $this->experiments($runs, $dataType);

        $result = [
            'schemaVersion' => self::SCHEMA_VERSION,
            'project' => [
                'name' => $this->text($manifest['name'] ?? null, 255, '프로젝트 이름'),
                'appVersion' => $this->text($manifest['appVersion'] ?? null, 100, '앱 버전'),
                'formatVersion' => $this->integer($manifest['formatVersion'] ?? null),
                'createdAt' => $this->text($manifest['createdAt'] ?? null, 80, '프로젝트 생성 시각'),
                'updatedAt' => $this->text($manifest['updatedAt'] ?? null, 80, '프로젝트 수정 시각'),
                'taskType' => $this->text($manifest['taskType'] ?? null, 40, '과제 유형'),
                'dataType' => $dataType,
                'locale' => $this->text($manifest['locale'] ?? null, 40, '프로젝트 언어'),
            ],
            'portfolio' => $portfolioView,
            'settings' => $settingsView,
            'experiments' => $experimentsView,
            'notices' => array_values($this->notices),
        ];
        return $this->fitResponse($result);
    }

    /** @param array<string,mixed> $portfolio @return array<string,mixed> */
    private function portfolio(array $portfolio): array
    {
        $template = is_array($portfolio['template'] ?? null) ? $portfolio['template'] : [];
        $sections = is_array($template['sections'] ?? null) ? array_values($template['sections']) : [];
        $answers = is_array($portfolio['answers'] ?? null) ? $portfolio['answers'] : [];
        $attachments = is_array($portfolio['attachments'] ?? null) ? $portfolio['attachments'] : [];
        $view = [];
        $seen = [];

        if (count($sections) > self::MAX_SECTIONS) {
            $this->notice('portfolio-sections', '포트폴리오 항목이 많아 처음 30개만 표시했습니다.');
        }
        foreach (array_slice($sections, 0, self::MAX_SECTIONS) as $section) {
            if (!is_array($section)) {
                continue;
            }
            $id = $this->text($section['id'] ?? null, 200, '포트폴리오 항목 ID');
            if ($id === null || $id === '') {
                continue;
            }
            $seen[$id] = true;
            $hasAnswer = array_key_exists($id, $answers) && is_string($answers[$id]);
            $answer = $hasAnswer ? $this->answer($answers[$id]) : null;
            $attachmentList = is_array($attachments[$id] ?? null) ? $attachments[$id] : [];
            $view[] = [
                'id' => $id,
                'title' => $this->text($section['title'] ?? null, 500, '포트폴리오 제목') ?? $id,
                'description' => $this->text($section['description'] ?? null, 4000, '포트폴리오 설명'),
                'answered' => $hasAnswer,
                'answer' => $answer,
                'attachmentCount' => count($attachmentList),
            ];
        }

        $orphans = 0;
        foreach ($answers as $id => $value) {
            $id = (string) $id;
            if (isset($seen[$id]) || !is_string($value)) {
                continue;
            }
            if ($orphans >= self::MAX_ORPHAN_ANSWERS) {
                $this->notice('portfolio-orphans', '템플릿에 없는 추가 답변이 많아 처음 10개만 표시했습니다.');
                break;
            }
            $safeId = $this->text($id, 200, '추가 답변 ID');
            if ($safeId === null || $safeId === '') {
                continue;
            }
            $attachmentList = is_array($attachments[$id] ?? null) ? $attachments[$id] : [];
            $view[] = [
                'id' => $safeId,
                'title' => '추가 답변: ' . $safeId,
                'description' => null,
                'answered' => true,
                'answer' => $this->answer($value),
                'attachmentCount' => count($attachmentList),
            ];
            $orphans++;
        }

        return [
            'locale' => $this->text($template['locale'] ?? null, 40, '포트폴리오 언어'),
            'answerFormat' => $this->text($portfolio['answerFormat'] ?? null, 40, '답변 형식'),
            'sections' => $view,
        ];
    }

    /** @param array<string,mixed> $settings @return array<string,mixed> */
    private function settings(array $settings, string $dataType): array
    {
        $data = is_array($settings['data'] ?? null) ? $settings['data'] : [];
        return [
            'runtime' => $this->text($settings['runtime'] ?? null, 100, '실행 환경'),
            'sampleCount' => $this->integer($settings['nSamples'] ?? null),
            'split' => $this->split(is_array($settings['split'] ?? null) ? $settings['split'] : []),
            'selectedAlgorithms' => $this->selectedAlgorithms($settings['selectedAlgorithms'] ?? null),
            'hyperparameters' => $this->parameterGroups($settings['hyperparameters'] ?? null),
            'data' => $dataType === 'image' ? $this->imageSettings($data) : $this->tabularSettings($data),
        ];
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function tabularSettings(array $data): array
    {
        $preprocessing = is_array($data['preprocessing'] ?? null) ? $data['preprocessing'] : [];
        return [
            'type' => 'tabular',
            'datasetConfigured' => is_array($data['dataset'] ?? null),
            'testDatasetConfigured' => is_array($data['testDataset'] ?? null),
            'predictDatasetConfigured' => is_array($data['predictDataset'] ?? null),
            'features' => $this->stringList($data['features'] ?? null, self::MAX_LIST_ITEMS, '선택 특성'),
            'target' => $this->text($data['target'] ?? null, 500, '목표 열'),
            'preprocessing' => [
                'missing' => $this->text($preprocessing['missing'] ?? null, 50, '결측치 처리'),
                'scaling' => $this->text($preprocessing['scaling'] ?? null, 50, '스케일링'),
                'categoricalEncoding' => $this->text($preprocessing['categoricalEncoding'] ?? null, 50, '범주형 인코딩'),
            ],
        ];
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function imageSettings(array $data): array
    {
        $dataset = is_array($data['dataset'] ?? null) ? $data['dataset'] : [];
        return [
            'type' => 'image',
            'datasetConfigured' => $dataset !== [],
            'testDatasetConfigured' => is_array($data['testDataset'] ?? null),
            'predictDatasetConfigured' => is_array($data['predictDataset'] ?? null),
            'categories' => $this->stringList($data['categories'] ?? null, self::MAX_LIST_ITEMS, '이미지 범주'),
            'backboneId' => $this->text($data['backboneId'] ?? null, 200, '이미지 백본'),
            'canonicalSize' => $this->integer($dataset['canonicalSize'] ?? null),
            'format' => $this->text($dataset['format'] ?? null, 30, '이미지 형식'),
            'quality' => $this->number($dataset['quality'] ?? null),
        ];
    }

    /** @param array<string,mixed> $split @return array<string,mixed> */
    private function split(array $split): array
    {
        return [
            'method' => $this->text($split['method'] ?? null, 50, '데이터 분할 방식'),
            'testSize' => $this->number($split['testSize'] ?? null),
            'stratify' => is_bool($split['stratify'] ?? null) ? $split['stratify'] : null,
            'randomState' => $this->integer($split['randomState'] ?? null),
        ];
    }

    /** @return list<array{algorithm:string,runtime:?string}> */
    private function selectedAlgorithms(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        if (count($value) > 20) {
            $this->notice('selected-algorithms', '선택 알고리즘이 많아 처음 20개만 표시했습니다.');
        }
        $result = [];
        foreach (array_slice(array_values($value), 0, 20) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $algorithm = $this->text($item['algorithm'] ?? null, 200, '알고리즘 이름');
            if ($algorithm === null || $algorithm === '') {
                continue;
            }
            $result[] = [
                'algorithm' => $algorithm,
                'runtime' => $this->text($item['runtime'] ?? null, 100, '알고리즘 실행 환경'),
            ];
        }
        return $result;
    }

    /** @return list<array{algorithm:string,runtime:?string,items:list<array{name:string,value:string}>}> */
    private function parameterGroups(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $groups = [];
        $count = 0;
        foreach ($value as $algorithm => $runtimeGroups) {
            if ($count >= 20) {
                $this->notice('parameter-groups', '하이퍼파라미터 그룹이 많아 처음 20개만 표시했습니다.');
                break;
            }
            if (!is_array($runtimeGroups)) {
                continue;
            }
            $name = $this->text((string) $algorithm, 200, '하이퍼파라미터 알고리즘');
            if ($name === null || $name === '') {
                continue;
            }
            $nested = false;
            foreach ($runtimeGroups as $candidate) {
                if (is_array($candidate)) {
                    $nested = true;
                    break;
                }
            }
            if (!$nested) {
                $groups[] = [
                    'algorithm' => $name,
                    'runtime' => null,
                    'items' => $this->parameterItems($runtimeGroups, $name),
                ];
                $count++;
                continue;
            }
            foreach ($runtimeGroups as $runtime => $parameters) {
                if ($count >= 20) {
                    $this->notice('parameter-groups', '하이퍼파라미터 그룹이 많아 처음 20개만 표시했습니다.');
                    break 2;
                }
                if (!is_array($parameters)) {
                    continue;
                }
                $groups[] = [
                    'algorithm' => $name,
                    'runtime' => $this->text((string) $runtime, 100, '하이퍼파라미터 실행 환경'),
                    'items' => $this->parameterItems($parameters, $name),
                ];
                $count++;
            }
        }
        return $groups;
    }

    /** @param array<string,mixed> $parameters @return list<array{name:string,value:string}> */
    private function parameterItems(array $parameters, string $algorithm): array
    {
        $allowed = self::HYPERPARAMETERS_BY_ALGORITHM[$algorithm] ?? [];
        $items = [];
        $count = 0;
        foreach ($parameters as $name => $value) {
            $name = (string) $name;
            if (!isset($allowed[$name])) {
                $this->notice('unknown-parameters', '0.12.1 허용 목록에 없는 하이퍼파라미터는 표시하지 않았습니다.');
                continue;
            }
            $safeValue = $this->number($value);
            if ($safeValue === null) {
                $this->notice('invalid-parameters', '숫자 형식이 아닌 하이퍼파라미터는 표시하지 않았습니다.');
                continue;
            }
            if ($count >= self::MAX_PARAMETERS) {
                $this->notice('parameters', '하이퍼파라미터가 많아 각 그룹의 처음 20개만 표시했습니다.');
                break;
            }
            $items[] = ['name' => $name, 'value' => (string) $safeValue];
            $count++;
        }
        return $items;
    }

    /** @param array<string,mixed> $runs @return list<array<string,mixed>> */
    private function experiments(array $runs, string $dataType): array
    {
        $experiments = is_array($runs['experiments'] ?? null) ? array_values($runs['experiments']) : [];
        if (count($experiments) > self::MAX_EXPERIMENTS) {
            $this->notice('experiments', '실험이 많아 처음 20개만 표시했습니다.');
        }
        $result = [];
        foreach (array_slice($experiments, 0, self::MAX_EXPERIMENTS) as $experimentIndex => $experiment) {
            if (!is_array($experiment)) {
                continue;
            }
            $experimentSettings = is_array($experiment['settings'] ?? null) ? $experiment['settings'] : [];
            // Use this experiment's snapshot, never the project's current settings.
            $snapshot = $this->settings($experimentSettings, $dataType);
            $experimentRuns = [];
            $rawRuns = is_array($experiment['runs'] ?? null) ? array_values($experiment['runs']) : [];
            foreach ($rawRuns as $run) {
                if ($this->runCount >= self::MAX_RUNS) {
                    $this->notice('runs', '실행 기록이 많아 전체 실험에서 처음 50개만 표시했습니다.');
                    break;
                }
                if (!is_array($run)) {
                    continue;
                }
                $experimentRuns[] = $this->run($run);
                $this->runCount++;
            }
            $result[] = [
                'sourceExperimentIndex' => $experimentIndex,
                'id' => $this->text($experiment['id'] ?? null, 200, '실험 ID'),
                'startedAt' => $this->text($experiment['startedAt'] ?? null, 80, '실험 시작 시각'),
                'taskType' => $this->text($experimentSettings['taskType'] ?? null, 40, '실험 과제 유형'),
                'runtime' => $snapshot['runtime'],
                'sampleCount' => $snapshot['sampleCount'],
                'split' => $snapshot['split'],
                'selectedAlgorithms' => $snapshot['selectedAlgorithms'],
                'hyperparameters' => $snapshot['hyperparameters'],
                'data' => is_array($experimentSettings['data'] ?? null) ? $snapshot['data'] : [],
                'runs' => $experimentRuns,
            ];
            if ($this->runCount >= self::MAX_RUNS) {
                if ($experimentIndex + 1 < count($experiments)) {
                    $this->notice('runs', '실행 기록 표시 한도 때문에 이후 실험을 생략했습니다.');
                }
                break;
            }
        }
        return $result;
    }

    /** @param array<string,mixed> $run @return array<string,mixed> */
    private function run(array $run): array
    {
        $engine = is_array($run['engine'] ?? null) ? $run['engine'] : [];
        $model = is_array($run['model'] ?? null) ? $run['model'] : [];
        $failure = is_array($run['failure'] ?? null) ? $run['failure'] : [];
        $warning = is_array($run['warning'] ?? null) ? $run['warning'] : [];
        $algorithm = $this->text($run['algorithm'] ?? null, 200, '실행 알고리즘');
        return [
            'id' => $this->text($run['id'] ?? null, 200, '실행 ID'),
            'algorithm' => $algorithm,
            'status' => $this->text($run['status'] ?? null, 40, '실행 상태'),
            'computedBy' => $this->text($run['computedBy'] ?? null, 40, '계산 위치'),
            'trainedAt' => $this->text($run['trainedAt'] ?? null, 80, '학습 시각'),
            'hyperparameters' => $algorithm === null
                ? []
                : $this->parameterItems(is_array($run['hyperparameters'] ?? null) ? $run['hyperparameters'] : [], $algorithm),
            'metrics' => $this->metrics($run['metrics'] ?? null),
            'perClass' => $this->perClass($run['perClass'] ?? null),
            'confusionMatrix' => $this->confusionMatrix($run['confusionMatrix'] ?? null),
            'featureImportance' => $this->featureImportance($run['featureImportance'] ?? null),
            'engine' => [
                'kind' => $this->text($engine['kind'] ?? null, 100, '엔진 종류'),
                'version' => $this->text($engine['version'] ?? null, 100, '엔진 버전'),
            ],
            'failureCode' => $this->text($failure['code'] ?? null, 200, '실행 실패 코드'),
            'warningCode' => $this->text($warning['code'] ?? null, 200, '실행 경고 코드'),
            'model' => [
                'format' => $this->text($model['format'] ?? null, 100, '모델 형식'),
                'includesPreprocessing' => is_bool($model['includesPreprocessing'] ?? null) ? $model['includesPreprocessing'] : null,
                'sizeBytes' => $this->integer($model['sizeBytes'] ?? null),
            ],
            'modelOmitted' => $this->modelOmission($run['modelOmitted'] ?? null),
        ];
    }

    private function modelOmission(mixed $value): ?string
    {
        return is_string($value) && isset(self::MODEL_OMISSION_REASONS[$value]) ? $value : null;
    }

    /** @return list<array{name:string,value:int|float}> */
    private function metrics(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $allowed = ['accuracy', 'f1Macro', 'r2', 'rmse', 'mae', 'silhouette', 'inertia'];
        $items = [];
        foreach ($allowed as $name) {
            $safeNumber = $this->number($value[$name] ?? null);
            if ($safeNumber === null) {
                continue;
            }
            $items[] = ['name' => $name, 'value' => $safeNumber];
        }
        return $items;
    }

    /** @return list<array<string,mixed>> */
    private function perClass(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        if (count($value) > self::MAX_CLASS_ROWS) {
            $this->notice('per-class', '클래스별 지표가 많아 처음 50개만 표시했습니다.');
        }
        $result = [];
        foreach (array_slice(array_values($value), 0, self::MAX_CLASS_ROWS) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $result[] = [
                'label' => $this->text($row['label'] ?? null, 300, '클래스 이름'),
                'precision' => $this->number($row['precision'] ?? null),
                'recall' => $this->number($row['recall'] ?? null),
                'specificity' => $this->number($row['specificity'] ?? null),
                'f1' => $this->number($row['f1'] ?? null),
                'support' => $this->integer($row['support'] ?? null),
            ];
        }
        return $result;
    }

    /** @return array{labels:list<string>,matrix:list<list<int>>}|null */
    private function confusionMatrix(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $rawLabels = is_array($value['labels'] ?? null) ? $value['labels'] : [];
        $rawMatrix = is_array($value['matrix'] ?? null) ? array_values($value['matrix']) : [];
        $dimension = count($rawLabels);
        if ($dimension < 1 || $dimension > self::MAX_MATRIX_DIMENSION || count($rawMatrix) !== $dimension) {
            $this->notice('confusion-matrix', '형식이 맞지 않거나 20×20을 넘는 혼동행렬은 표시하지 않았습니다.');
            return null;
        }
        $labels = $this->stringList($rawLabels, self::MAX_MATRIX_DIMENSION, '혼동행렬 클래스');
        if (count($labels) !== $dimension) {
            $this->notice('confusion-matrix', '형식이 맞지 않는 혼동행렬은 표시하지 않았습니다.');
            return null;
        }
        $matrix = [];
        foreach ($rawMatrix as $row) {
            if (!is_array($row) || count($row) !== $dimension) {
                $this->notice('confusion-matrix', '정사각형이 아닌 혼동행렬은 표시하지 않았습니다.');
                return null;
            }
            $cells = [];
            foreach (array_values($row) as $cell) {
                $number = $this->integer($cell);
                if ($number === null) {
                    $this->notice('confusion-matrix', '숫자 형식이 아닌 혼동행렬은 표시하지 않았습니다.');
                    return null;
                }
                $cells[] = $number;
            }
            $matrix[] = $cells;
        }
        return ['labels' => $labels, 'matrix' => $matrix];
    }

    /** @return list<array{feature:?string,importance:int|float|null}> */
    private function featureImportance(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        if (count($value) > self::MAX_IMPORTANCE_ROWS) {
            $this->notice('feature-importance', '특성 중요도가 많아 처음 50개만 표시했습니다.');
        }
        $result = [];
        foreach (array_slice(array_values($value), 0, self::MAX_IMPORTANCE_ROWS) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $result[] = [
                'feature' => $this->text($row['feature'] ?? null, 500, '특성 이름'),
                'importance' => $this->number($row['importance'] ?? null),
            ];
        }
        return $result;
    }

    /** @return list<string> */
    private function stringList(mixed $value, int $limit, string $label): array
    {
        if (!is_array($value)) {
            return [];
        }
        if (count($value) > $limit) {
            $this->notice('list-' . strtolower(str_replace(' ', '-', $label)), $label . ' 항목이 많아 처음 ' . $limit . '개만 표시했습니다.');
        }
        $result = [];
        foreach (array_slice(array_values($value), 0, $limit) as $item) {
            $text = $this->text($item, 500, $label);
            if ($text !== null) {
                $result[] = $text;
            }
        }
        return $result;
    }

    private function answer(string $value): string
    {
        $remaining = self::MAX_TOTAL_ANSWER_BYTES - $this->answerBytes;
        if ($remaining <= 0) {
            $this->notice('answer-total', '답변 전체 길이가 안전 한도를 넘어 나머지 내용을 생략했습니다.');
            return '';
        }
        $clean = $this->clean($value);
        $limit = min(self::MAX_ANSWER_BYTES, $remaining);
        $answer = $this->clip($clean, $limit, 'portfolio-answer', '긴 포트폴리오 답변은 항목당 20,000바이트까지만 표시했습니다.');
        $this->answerBytes += strlen($answer);
        if (strlen($clean) > $limit) {
            $this->notice('answer-total', '답변 전체 길이에는 안전 표시 한도가 적용됩니다.');
        }
        return $answer;
    }


    private function integer(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    private function number(mixed $value): int|float|null
    {
        if (is_int($value)) {
            return $value;
        }
        return is_float($value) && is_finite($value) ? $value : null;
    }

    private function text(mixed $value, int $maxBytes, string $label): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        return $this->clip($this->clean($value), $maxBytes, 'text-' . sha1($label), $label . '이(가) 길어 일부를 생략했습니다.');
    }

    private function clean(string $value): string
    {
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
        return is_string($clean) ? $clean : '';
    }

    private function clip(string $value, int $maxBytes, string $noticeKey, string $notice): string
    {
        if (strlen($value) <= $maxBytes) {
            return $value;
        }
        $cut = substr($value, 0, $maxBytes);
        while ($cut !== '' && preg_match('//u', $cut) !== 1) {
            $cut = substr($cut, 0, -1);
        }
        $this->notice($noticeKey, $notice);
        return rtrim($cut) . '…';
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    private function fitResponse(array $result): array
    {
        if ($this->encodedBytes($result) <= self::MAX_RESPONSE_BYTES) {
            return $result;
        }

        foreach ($result['experiments'] as &$experiment) {
            if (!is_array($experiment) || !is_array($experiment['runs'] ?? null)) {
                continue;
            }
            foreach ($experiment['runs'] as &$run) {
                if (!is_array($run)) {
                    continue;
                }
                $run['perClass'] = [];
                $run['confusionMatrix'] = null;
                $run['featureImportance'] = [];
            }
            unset($run);
        }
        unset($experiment);
        $this->notice('response-heavy-results', '응답 크기 제한 때문에 클래스별 지표·혼동행렬·특성 중요도 일부를 생략했습니다.');
        $result['notices'] = array_values($this->notices);
        if ($this->encodedBytes($result) <= self::MAX_RESPONSE_BYTES) {
            return $result;
        }

        $remaining = 50000;
        foreach ($result['portfolio']['sections'] as &$section) {
            if (!is_array($section) || !is_string($section['answer'] ?? null)) {
                continue;
            }
            $limit = min(5000, max(0, $remaining));
            $section['answer'] = $limit > 0
                ? $this->clip($section['answer'], $limit, 'response-answers', '응답 크기 제한 때문에 긴 답변 일부를 생략했습니다.')
                : '';
            $remaining -= strlen($section['answer']);
        }
        unset($section);
        $result['notices'] = array_values($this->notices);
        if ($this->encodedBytes($result) <= self::MAX_RESPONSE_BYTES) {
            return $result;
        }

        $result['experiments'] = array_slice($result['experiments'], 0, 5);
        $this->notice('response-experiments', '응답 크기 제한 때문에 일부 실험 기록을 생략했습니다.');
        $result['notices'] = array_values($this->notices);
        if ($this->encodedBytes($result) <= self::MAX_RESPONSE_BYTES) {
            return $result;
        }

        $result['experiments'] = [];
        $result['portfolio']['sections'] = array_slice($result['portfolio']['sections'], 0, 10);
        $this->notice('response-final', '응답 크기 안전 한도 때문에 일부 상세 내용을 표시하지 않았습니다.');
        $result['notices'] = array_values($this->notices);
        if ($this->encodedBytes($result) <= self::MAX_RESPONSE_BYTES) {
            return $result;
        }

        foreach ($result['portfolio']['sections'] as &$section) {
            if (!is_array($section)) {
                continue;
            }
            $section['description'] = null;
            if (is_string($section['answer'] ?? null)) {
                $section['answer'] = $this->clip(
                    $section['answer'],
                    2000,
                    'response-minimal-answers',
                    '응답 크기 제한 때문에 답변을 항목당 2,000바이트로 줄였습니다.'
                );
            }
        }
        unset($section);
        $result['settings']['hyperparameters'] = [];
        $result['settings']['selectedAlgorithms'] = array_slice($result['settings']['selectedAlgorithms'], 0, 10);
        if (is_array($result['settings']['data'] ?? null)) {
            $result['settings']['data']['features'] = [];
            $result['settings']['data']['categories'] = [];
        }
        $result['notices'] = ['응답 크기 안전 한도 때문에 일부 상세 내용을 표시하지 않았습니다.'];
        if ($this->encodedBytes($result) <= self::MAX_RESPONSE_BYTES) {
            return $result;
        }

        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'project' => [],
            'portfolio' => ['locale' => null, 'answerFormat' => null, 'sections' => []],
            'settings' => [
                'runtime' => null,
                'sampleCount' => null,
                'split' => [],
                'selectedAlgorithms' => [],
                'hyperparameters' => [],
                'data' => [],
            ],
            'experiments' => [],
            'notices' => ['제출 내용이 표시 한도를 넘어 안전한 최소 보기로 대체했습니다.'],
        ];
    }

    /** @param array<string,mixed> $value */
    private function encodedBytes(array $value): int
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($encoded) ? strlen($encoded) : PHP_INT_MAX;
    }
    private function notice(string $key, string $message): void
    {
        $this->notices[$key] = $message;
    }
}
