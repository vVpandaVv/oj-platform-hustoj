<?php
declare(strict_types=1);

namespace EduGames\Games\Modules\AI_Model_Lab\Domain;

/**
 * A fully-loaded labeling-game scenario: the client-safe metadata (model
 * options, feature/class display names, and the fixed labeling batch WITHOUT
 * true labels) plus the server-only answer key — each batch sample's real
 * true_label and the performance_by_correct_count lookup table. Neither the
 * true labels nor the performance table are ever exposed through
 * publicView(); see that method's docblock.
 */
final class Activity_Definition {
    /**
     * @param Model_Option[] $models
     * @param string[] $featureNames raw sklearn feature names, display-only
     * @param array<string, string> $featureLabels feature key => Korean label
     * @param string[] $labels class keys, in canonical order
     * @param array<string, string> $labelLabels class key => Korean label
     * @param array<int, array{id: int, features: array<string, float>, trueLabel: string}> $labelingBatch
     * @param array<string, array<int, array{accuracy: float, precision: float, recall: float, f1: float, confusionMatrix: array<int, array<int, int>>}>> $performanceByCorrectCount
     *        modelKey => [correctCount => performance row], correctCount runs 0..count($labelingBatch)
     */
    private function __construct(
        private readonly string $key,
        private readonly string $title,
        private readonly string $difficulty,
        private readonly string $datasetLabel,
        private readonly string $description,
        private readonly array $featureNames,
        private readonly array $featureLabels,
        private readonly array $labels,
        private readonly array $labelLabels,
        private readonly array $models,
        private readonly array $labelingBatch,
        private readonly array $performanceByCorrectCount,
        private readonly float $baselineAccuracy,
        private readonly float $bestAccuracy,
        private readonly int $retryPenaltyPerAttempt,
        private readonly string $displayType
    ) {
        $this->assertComplete();
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self {
        $models = array();
        foreach ((array) ($config['models'] ?? array()) as $model) {
            $models[] = Model_Option::fromArray((array) $model);
        }

        $labeling_batch = array();
        foreach ((array) ($config['labeling_batch'] ?? array()) as $sample) {
            $sample = (array) $sample;
            $features = array();
            foreach ((array) ($sample['features'] ?? array()) as $feature_key => $value) {
                $features[(string) $feature_key] = (float) $value;
            }

            $labeling_batch[] = array(
                'id' => (int) ($sample['id'] ?? 0),
                'features' => $features,
                'trueLabel' => (string) ($sample['true_label'] ?? ''),
            );
        }

        $performance_by_correct_count = array();
        foreach ((array) ($config['performance_by_correct_count'] ?? array()) as $model_key => $rows) {
            $by_count = array();
            foreach ((array) $rows as $row) {
                $row = (array) $row;
                $confusion_matrix = array();
                foreach ((array) ($row['confusion_matrix'] ?? array()) as $matrix_row) {
                    $confusion_matrix[] = array_map('intval', (array) $matrix_row);
                }

                $by_count[(int) ($row['correct_count'] ?? 0)] = array(
                    'accuracy' => (float) ($row['accuracy'] ?? 0),
                    'precision' => (float) ($row['precision'] ?? 0),
                    'recall' => (float) ($row['recall'] ?? 0),
                    'f1' => (float) ($row['f1'] ?? 0),
                    'confusionMatrix' => $confusion_matrix,
                );
            }

            $performance_by_correct_count[(string) $model_key] = $by_count;
        }

        return new self(
            (string) ($config['key'] ?? ''),
            (string) ($config['title'] ?? ''),
            (string) ($config['difficulty'] ?? ''),
            (string) ($config['dataset_label'] ?? ''),
            (string) ($config['description'] ?? ''),
            array_values(array_map('strval', (array) ($config['feature_names'] ?? array()))),
            array_map('strval', (array) ($config['feature_labels'] ?? array())),
            array_values(array_map('strval', (array) ($config['labels'] ?? array()))),
            array_map('strval', (array) ($config['label_labels'] ?? array())),
            $models,
            $labeling_batch,
            $performance_by_correct_count,
            (float) ($config['baseline_accuracy'] ?? 0),
            (float) ($config['best_accuracy'] ?? 0),
            (int) ($config['retry_penalty_per_attempt'] ?? 0),
            (string) ($config['display_type'] ?? '')
        );
    }

    /**
     * Safety net ported from the verified reference implementation: every
     * model must have a performance_by_correct_count entry for every
     * correct_count from 0 up to the labeling batch size. This caught real
     * data-generation bugs before — do not skip it.
     */
    private function assertComplete(): void {
        if (empty($this->labelingBatch)) {
            throw new \RuntimeException(sprintf(
                'AI Model Lab activity "%s" must define a non-empty labeling_batch.',
                $this->key
            ));
        }

        if (empty($this->models)) {
            throw new \RuntimeException(sprintf(
                'AI Model Lab activity "%s" must define at least one model.',
                $this->key
            ));
        }

        $total_count = count($this->labelingBatch);

        foreach ($this->models as $model) {
            $model_key = $model->key();

            if (! isset($this->performanceByCorrectCount[$model_key])) {
                throw new \RuntimeException(sprintf(
                    'AI Model Lab activity "%s" is missing performance_by_correct_count entries for model "%s".',
                    $this->key,
                    $model_key
                ));
            }

            for ($correct_count = 0; $correct_count <= $total_count; $correct_count++) {
                if (! isset($this->performanceByCorrectCount[$model_key][$correct_count])) {
                    throw new \RuntimeException(sprintf(
                        'AI Model Lab activity "%s" is missing performance_by_correct_count entry for model "%s" at correct_count=%d.',
                        $this->key,
                        $model_key,
                        $correct_count
                    ));
                }
            }
        }
    }

    public function key(): string {
        return $this->key;
    }

    public function title(): string {
        return $this->title;
    }

    public function difficulty(): string {
        return $this->difficulty;
    }

    public function datasetLabel(): string {
        return $this->datasetLabel;
    }

    public function description(): string {
        return $this->description;
    }

    /**
     * @return string[]
     */
    public function featureNames(): array {
        return $this->featureNames;
    }

    /**
     * @return array<string, string>
     */
    public function featureLabels(): array {
        return $this->featureLabels;
    }

    /**
     * @return string[]
     */
    public function labels(): array {
        return $this->labels;
    }

    /**
     * @return array<string, string>
     */
    public function labelLabels(): array {
        return $this->labelLabels;
    }

    /**
     * @return Model_Option[]
     */
    public function models(): array {
        return $this->models;
    }

    public function hasModel(string $modelKey): bool {
        foreach ($this->models as $model) {
            if ($model->key() === $modelKey) {
                return true;
            }
        }

        return false;
    }

    /**
     * Full server-side batch, including true labels — never send this
     * straight to the browser; use publicView()'s labelingBatch for that.
     *
     * @return array<int, array{id: int, features: array<string, float>, trueLabel: string}>
     */
    public function labelingBatch(): array {
        return $this->labelingBatch;
    }

    public function totalCount(): int {
        return count($this->labelingBatch);
    }

    public function trueLabelFor(int $sampleId): ?string {
        foreach ($this->labelingBatch as $sample) {
            if ($sample['id'] === $sampleId) {
                return $sample['trueLabel'];
            }
        }

        return null;
    }

    /**
     * @return int[]
     */
    public function sampleIds(): array {
        return array_map(
            static fn (array $sample): int => $sample['id'],
            $this->labelingBatch
        );
    }

    public function baselineAccuracy(): float {
        return $this->baselineAccuracy;
    }

    public function bestAccuracy(): float {
        return $this->bestAccuracy;
    }

    public function retryPenaltyPerAttempt(): int {
        return $this->retryPenaltyPerAttempt;
    }

    /**
     * Optional rendering hint for the client (e.g. 'pixel_grid_8x8' for the
     * digit-recognition activity, so its labeling batch renders as a small
     * image grid instead of a plain feature-value list). Empty string means
     * "use the default text-list rendering."
     */
    public function displayType(): string {
        return $this->displayType;
    }

    /**
     * The real, empirically-measured performance a model would achieve if
     * trained on this activity's labeling batch with exactly $correctCount
     * of the labels correct. Server-authoritative — never derived from or
     * trusted to the client.
     *
     * @return array{accuracy: float, precision: float, recall: float, f1: float, confusionMatrix: array<int, array<int, int>>}
     */
    public function performanceFor(string $modelKey, int $correctCount): array {
        if (! isset($this->performanceByCorrectCount[$modelKey][$correctCount])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown performance combination model="%s" correctCount=%d for activity "%s".',
                $modelKey,
                $correctCount,
                $this->key
            ));
        }

        return $this->performanceByCorrectCount[$modelKey][$correctCount];
    }

    /**
     * Client-safe subset — excludes true labels, the performance lookup
     * table, and the baseline/best/retry-penalty scoring parameters so
     * answers are never sent to the browser before submission.
     *
     * @return array<string, mixed>
     */
    public function publicView(): array {
        return array(
            'key' => $this->key,
            'title' => $this->title,
            'difficulty' => $this->difficulty,
            'datasetLabel' => $this->datasetLabel,
            'description' => $this->description,
            'featureNames' => $this->featureNames,
            'featureLabels' => $this->featureLabels,
            'labels' => $this->labels,
            'labelLabels' => $this->labelLabels,
            'models' => array_map(
                static fn (Model_Option $model): array => $model->toArray(),
                $this->models
            ),
            'labelingBatch' => array_map(
                static fn (array $sample): array => array(
                    'id' => $sample['id'],
                    'features' => $sample['features'],
                ),
                $this->labelingBatch
            ),
            'displayType' => $this->displayType,
        );
    }
}
