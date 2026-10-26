<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Composition;

use CodingPanda\OjPlatform\Modules\AI\Application\AiFeedbackJobAccessRule;
use CodingPanda\OjPlatform\Modules\AI\Application\LearnerLevelService;
use CodingPanda\OjPlatform\Modules\AI\Module;

final class AiFeedbackBundle
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

    public function levels(): LearnerLevelService
    {
        return $this->typedService(LearnerLevelService::class);
    }

    public function feedbackJobs(): \CodingPanda\OjPlatform\Modules\AI\Application\SubmissionFeedbackJobService
    {
        return $this->typedService(\CodingPanda\OjPlatform\Modules\AI\Application\SubmissionFeedbackJobService::class);
    }

    public function jobAccessRule(): AiFeedbackJobAccessRule
    {
        return $this->typedService(AiFeedbackJobAccessRule::class);
    }


    public function bindInto(BuildContainer $container): void
    {
        foreach ($this->services as $serviceId => $service) {
            $container->bind($serviceId, $service);
        }
    }

    /**
     * @template T of object
     * @param class-string<T> $serviceId
     * @return T
     */
    private function typedService(string $serviceId): object
    {
        $service = $this->services[$serviceId] ?? null;
        if (! is_object($service) || ! $service instanceof $serviceId) {
            throw new \LogicException('AI Feedback 조립 서비스가 없습니다: ' . $serviceId);
        }

        return $service;
    }
}
