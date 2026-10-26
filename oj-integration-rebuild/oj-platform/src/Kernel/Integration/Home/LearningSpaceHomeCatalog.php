<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Home;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\Classes\Application\ClassService;
use CodingPanda\OjPlatform\Modules\Contests\Application\ContestService;
use CodingPanda\OjPlatform\Modules\Groups\Application\GroupService;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;
use CodingPanda\OjPlatform\Ui\View\RouteTarget;
use DateTimeImmutable;
use Throwable;

final class LearningSpaceHomeCatalog
{
    /** @var array<string, LearningSpaceSnapshot> */
    private array $cache = [];

    public function __construct(
        private readonly ClassService $classes,
        private readonly ContestService $contests,
        private readonly GroupService $groups,
        private readonly FeatureFlags $features,
        private readonly ErrorReporter $errors
    ) {
    }

    public function visible(int $userId, DateTimeImmutable $now): LearningSpaceSnapshot
    {
        return $this->load($userId, $now, false);
    }

    public function managed(int $userId, DateTimeImmutable $now): LearningSpaceSnapshot
    {
        return $this->load($userId, $now, true);
    }

    private function load(
        int $userId,
        DateTimeImmutable $now,
        bool $managed
    ): LearningSpaceSnapshot {
        $cacheKey = $userId . ':' . ($managed ? 'managed' : 'visible');
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }
        $items = [];
        $partial = false;
        if ($this->enabled('classes')) {
            try {
                $classes = $this->classes->homeFor($userId, $now->format('Y-m-d H:i:s'), 8, $managed);
                foreach ($classes as $class) {
                    if (
                        $class->isDeleted()
                        || ! $class->isEnabled()
                        || ! $class->isOpenAt($now->format('Y-m-d H:i:s'))
                    ) {
                        continue;
                    }
                    $items[] = new LearningSpaceSummary(
                        $managed ? '담당 수업' : '수업',
                        $class->name(),
                        $class->description() !== ''
                            ? $class->description()
                            : '수업 문제와 학습 현황',
                        $class->endsAt() === null
                            ? '학습 중'
                            : $this->shortDate($class->endsAt(), $now) . ' 종료',
                        'info',
                        RouteTarget::route('classes.show', ['class_id' => $class->id()])
                    );
                }
            } catch (Throwable $error) {
                $partial = true;
                $this->errors->report($error);
            }
        }
        if (count($items) < 8 && $this->enabled('contests')) {
            try {
                $contests = $this->contests->homeFor($userId, $now->format('Y-m-d H:i:s'), 8 - count($items), $managed);
                foreach ($contests as $contest) {
                    $stage = $contest->stage($now->format('Y-m-d H:i:s'));
                    if (in_array($stage, ['archived', 'ended'], true)) {
                        continue;
                    }
                    $items[] = new LearningSpaceSummary(
                        $managed ? '담당 대회·평가' : '대회·평가',
                        $contest->title(),
                        $stage === 'running'
                            ? '현재 진행 중인 대회·평가입니다.'
                            : '시작 예정인 대회·평가입니다.',
                        $stage === 'running'
                            ? $this->shortDate($contest->endsAt(), $now) . ' 종료'
                            : $this->shortDate($contest->startsAt(), $now) . ' 시작',
                        'warning',
                        RouteTarget::route('contests.show', ['contest_id' => $contest->id()])
                    );
                }
            } catch (Throwable $error) {
                $partial = true;
                $this->errors->report($error);
            }
        }
        if (count($items) < 8 && $this->enabled('groups')) {
            try {
                $groups = $this->groups->homeFor($userId, 8 - count($items), $managed);
                foreach ($groups as $group) {
                    if ($group->isDeleted() || ! $group->isEnabled()) {
                        continue;
                    }
                    $items[] = new LearningSpaceSummary(
                        $managed ? '담당 그룹' : '그룹',
                        $group->name(),
                        $group->description() !== ''
                            ? $group->description()
                            : '구성원과 함께 학습',
                        $group->isPerformanceMode() ? '수행 모드' : '일반 모드',
                        'neutral',
                        RouteTarget::route('groups.show', ['group_id' => $group->id()])
                    );
                }
            } catch (Throwable $error) {
                $partial = true;
                $this->errors->report($error);
            }
        }

        return $this->cache[$cacheKey] = new LearningSpaceSnapshot(
            array_slice($items, 0, 8),
            $partial
        );
    }

    private function enabled(string $feature): bool
    {
        try {
            return $this->features->isEnabled($feature);
        } catch (Throwable $error) {
            $this->errors->report($error);

            return false;
        }
    }

    private function shortDate(string $value, DateTimeImmutable $now): string
    {
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $value,
            $now->getTimezone()
        );

        return $date === false ? $value : $date->format('n월 j일 H:i');
    }
}
