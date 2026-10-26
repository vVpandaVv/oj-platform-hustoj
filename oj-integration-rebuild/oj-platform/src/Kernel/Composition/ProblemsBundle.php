<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Composition;

use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeResults;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemJobAccessRule;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemCatalogService;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemContextAccessRegistry;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemDetailSectionRegistry;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemSubmissionPanelRegistry;
use CodingPanda\OjPlatform\Modules\Problems\Contract\LearningProblemProvider;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemTopicDictionary;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionProblemProvider;
use CodingPanda\OjPlatform\Modules\Problems\Module;

final class ProblemsBundle
{
    /**
     * @param array<string, object> $services
     */
    public function __construct(
        private readonly Module $module,
        private readonly array $services
    ) {
    }

    public function module(): Module
    {
        return $this->module;
    }

    public function contextAccess(): ProblemContextAccessRegistry
    {
        $service = $this->services[ProblemContextAccessRegistry::class] ?? null;
        if (! $service instanceof ProblemContextAccessRegistry) {
            throw new \LogicException(
                'Problems 학습 문맥 접근 레지스트리가 없습니다.'
            );
        }

        return $service;
    }

    public function jobAccessRule(): ProblemJobAccessRule
    {
        $service = $this->services[ProblemJobAccessRule::class] ?? null;
        if (! $service instanceof ProblemJobAccessRule) {
            throw new \LogicException(
                'Problems 작업 접근 정책 서비스가 없습니다.'
            );
        }

        return $service;
    }

    public function submissionProblems(): SubmissionProblemProvider
    {
        $service = $this->services[SubmissionProblemProvider::class] ?? null;
        if (! $service instanceof SubmissionProblemProvider) {
            throw new \LogicException(
                'Problems 제출 정책 Provider가 없습니다.'
            );
        }

        return $service;
    }

    public function learningProblems(): LearningProblemProvider
    {
        $service = $this->services[LearningProblemProvider::class] ?? null;
        if (! $service instanceof LearningProblemProvider) {
            throw new \LogicException(
                'Problems 학습 데이터 Provider가 없습니다.'
            );
        }

        return $service;
    }

    public function catalog(): ProblemCatalogService
    {
        $service = $this->services[ProblemCatalogService::class] ?? null;
        if (! $service instanceof ProblemCatalogService) {
            throw new \LogicException('Problems 문제 카탈로그 서비스가 없습니다.');
        }

        return $service;
    }

    public function topics(): ProblemTopicDictionary
    {
        $service = $this->services[ProblemTopicDictionary::class] ?? null;
        if (! $service instanceof ProblemTopicDictionary) {
            throw new \LogicException(
                'Problems 주제 사전 서비스가 없습니다.'
            );
        }

        return $service;
    }

    public function detailSections(): ProblemDetailSectionRegistry
    {
        $service = $this->services[ProblemDetailSectionRegistry::class] ?? null;
        if (! $service instanceof ProblemDetailSectionRegistry) {
            throw new \LogicException(
                'Problems 상세 섹션 레지스트리가 없습니다.'
            );
        }

        return $service;
    }

    public function submissionPanel(): ProblemSubmissionPanelRegistry
    {
        $service = $this->services[ProblemSubmissionPanelRegistry::class] ?? null;
        if (! $service instanceof ProblemSubmissionPanelRegistry) {
            throw new \LogicException(
                'Problems 제출 패널 레지스트리가 없습니다.'
            );
        }

        return $service;
    }

    public function judgeResults(): JudgeResults
    {
        $service = $this->services[JudgeResults::class] ?? null;
        if (! $service instanceof JudgeResults) {
            throw new \LogicException(
                'Problems Judge 결과 카탈로그가 없습니다.'
            );
        }

        return $service;
    }

    public function bindInto(BuildContainer $container): void
    {
        foreach ($this->services as $serviceId => $service) {
            $container->bind($serviceId, $service);
        }
    }
}
