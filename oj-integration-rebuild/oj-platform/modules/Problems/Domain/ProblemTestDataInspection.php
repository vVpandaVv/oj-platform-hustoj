<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

final class ProblemTestDataInspection
{
    /**
     * @param list<string> $missingInputs
     * @param list<string> $missingOutputs
     * @param list<string> $duplicateOutputs
     * @param list<string> $emptyFiles
     */
    public function __construct(
        private readonly int $pairCount,
        private readonly array $missingInputs,
        private readonly array $missingOutputs,
        private readonly array $duplicateOutputs,
        private readonly array $emptyFiles
    ) {
    }

    /**
     * @param list<ProblemDataFile> $files
     */
    public static function inspect(array $files): self
    {
        $inputs = [];
        $outputs = [];
        $emptyFiles = [];
        foreach ($files as $file) {
            if ($file->size() === 0) {
                $emptyFiles[] = $file->name();
            }
            $extension = $file->extension();
            if (! in_array($extension, ['in', 'out', 'ans'], true)) {
                continue;
            }
            $base = pathinfo($file->name(), PATHINFO_FILENAME);
            if ($extension === 'in') {
                $inputs[$base] = $file->name();
            } else {
                $outputs[$base][] = $file->name();
            }
        }

        $missingInputs = [];
        $missingOutputs = [];
        $duplicateOutputs = [];
        foreach ($inputs as $base => $name) {
            if (! isset($outputs[$base])) {
                $missingOutputs[] = $name;
            }
        }
        foreach ($outputs as $base => $names) {
            if (! isset($inputs[$base])) {
                array_push($missingInputs, ...$names);
            }
            if (count($names) > 1) {
                array_push($duplicateOutputs, ...$names);
            }
        }

        sort($missingInputs, SORT_NATURAL | SORT_FLAG_CASE);
        sort($missingOutputs, SORT_NATURAL | SORT_FLAG_CASE);
        sort($duplicateOutputs, SORT_NATURAL | SORT_FLAG_CASE);
        sort($emptyFiles, SORT_NATURAL | SORT_FLAG_CASE);

        return new self(
            count(array_intersect(array_keys($inputs), array_keys($outputs))),
            $missingInputs,
            $missingOutputs,
            $duplicateOutputs,
            $emptyFiles
        );
    }

    public function pairCount(): int
    {
        return $this->pairCount;
    }

    /**
     * @return list<string>
     */
    public function missingInputs(): array
    {
        return $this->missingInputs;
    }

    /**
     * @return list<string>
     */
    public function missingOutputs(): array
    {
        return $this->missingOutputs;
    }

    /**
     * @return list<string>
     */
    public function duplicateOutputs(): array
    {
        return $this->duplicateOutputs;
    }

    /**
     * @return list<string>
     */
    public function emptyFiles(): array
    {
        return $this->emptyFiles;
    }

    public function isValid(): bool
    {
        return $this->missingInputs === []
            && $this->missingOutputs === []
            && $this->duplicateOutputs === []
            && $this->emptyFiles === [];
    }
}
