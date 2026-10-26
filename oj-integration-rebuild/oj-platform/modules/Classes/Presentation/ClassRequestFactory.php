<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Classes\Presentation;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Classes\Application\ClassSaveCommand;
use CodingPanda\OjPlatform\Modules\Classes\Domain\ClassProblem;
use DomainException;

final class ClassRequestFactory
{
    public function __construct(private readonly WordPressUserGateway $users)
    {
    }

    public function saveCommand(HttpRequest $request): ClassSaveCommand
    {
        $removed = array_flip($this->selectedMapIds($request, 'problem_remove'));
        $existing = array_values(array_filter(
            $this->ids($request, 'class_problem_ids'),
            static fn (int $problemId): bool => ! isset($removed[$problemId])
        ));
        $problemIds = array_values(array_unique(array_merge(
            $existing,
            $this->ids($request, 'problem_ids')
        )));
        $problemIds = $this->sortProblemIds($request, $problemIds);

        return new ClassSaveCommand(
            $request->bodyInt('class_id'),
            $request->bodyString('name'),
            $request->bodyString('slug'),
            $request->bodyString('description'),
            $request->bodyString('visibility', 'private'),
            $request->bodyString('progress_visibility', 'private'),
            $request->bodyBool('enabled'),
            $this->dateTime($request->bodyString('starts_at'), '수업 시작 시각'),
            $this->dateTime($request->bodyString('ends_at'), '수업 종료 시각'),
            $request->bodyString('password'),
            $request->bodyString('blockly_profile_key'),
            $this->userIds($request->bodyString('instructor_user_logins')),
            $this->ids($request, 'group_ids'),
            $problemIds,
            $this->problemConfigurations($request, $problemIds),
            $request->bodyBool('approval_required')
        );
    }

    /** @return list<int> */
    private function userIds(string $value): array
    {
        $ids = [];
        foreach (preg_split('/[\s,]+/u', trim($value)) ?: [] as $login) {
            if ($login === '') {
                continue;
            }
            $identity = $this->users->findByLogin($login);
            if ($identity === null) {
                throw new DomainException(
                    '공동 강사 로그인 아이디를 찾을 수 없습니다: ' . $login
                );
            }
            $ids[] = $identity->userId();
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<int>
     */
    private function ids(HttpRequest $request, string $key): array

    {
        $values = $request->bodyIntList($key);
        if ($values !== []) {
            return $values;
        }
        preg_match_all('/\d+/', $request->bodyString($key), $matches);

        return array_values(array_unique(array_filter(array_map(
            'intval',
            $matches[0] ?? []
        ))));
    }
    /**
     * @param list<int> $problemIds
     * @return list<ClassProblem>
     */
    private function problemConfigurations(
        HttpRequest $request,
        array $problemIds
    ): array {
        $configured = $this->map($request, 'problem_configured');
        $visible = $this->map($request, 'problem_visible');
        $availableFrom = $this->map($request, 'problem_available_from');
        $availableUntil = $this->map($request, 'problem_available_until');
        $configurations = [];
        foreach ($problemIds as $sortOrder => $problemId) {
            if ($this->mapValue($configured, $problemId) === null) {
                continue;
            }
            $from = $this->dateTime(
                (string) ($this->mapValue($availableFrom, $problemId) ?? ''),
                '문제 공개 시작 시각'
            );
            $until = $this->dateTime(
                (string) ($this->mapValue($availableUntil, $problemId) ?? ''),
                '문제 공개 종료 시각'
            );
            if ($from !== null && $until !== null && $from >= $until) {
                throw new DomainException(
                    '문제 공개 종료 시각은 시작 시각보다 늦어야 합니다.'
                );
            }
            $configurations[] = new ClassProblem(
                $problemId,
                $sortOrder,
                $this->mapValue($visible, $problemId) !== null,
                $from,
                $until
            );
        }

        return $configurations;
    }

    /**
     * @param list<int> $problemIds
     * @return list<int>
     */
    private function sortProblemIds(HttpRequest $request, array $problemIds): array
    {
        $order = $this->map($request, 'problem_order');
        $positions = array_flip($problemIds);
        usort(
            $problemIds,
            function (int $left, int $right) use ($order, $positions): int {
                $leftOrder = (int) ($this->mapValue($order, $left) ?? PHP_INT_MAX);
                $rightOrder = (int) ($this->mapValue($order, $right) ?? PHP_INT_MAX);

                return ($leftOrder <=> $rightOrder)
                    ?: (($positions[$left] ?? 0) <=> ($positions[$right] ?? 0));
            }
        );

        return $problemIds;
    }

    /** @return array<int|string, mixed> */
    private function map(HttpRequest $request, string $key): array
    {
        $value = $request->body()[$key] ?? [];

        return is_array($value) ? $value : [];
    }

    /** @param array<int|string, mixed> $values */
    private function mapValue(array $values, int $key): mixed
    {
        return $values[$key] ?? $values[(string) $key] ?? null;
    }

    /** @return list<int> */
    private function selectedMapIds(HttpRequest $request, string $key): array
    {
        $ids = [];
        foreach ($this->map($request, $key) as $id => $selected) {
            if (
                preg_match('/^\d+$/', (string) $id) === 1
                && in_array((string) $selected, ['1', 'true', 'on'], true)
            ) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function dateTime(string $value, string $label): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match(
            '/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/',
            $value,
            $parts
        ) !== 1) {
            throw new DomainException($label . '이 올바르지 않습니다.');
        }
        $year = (int) $parts[1];
        $month = (int) $parts[2];
        $day = (int) $parts[3];
        $hour = (int) $parts[4];
        $minute = (int) $parts[5];
        $second = isset($parts[6]) ? (int) $parts[6] : 0;
        if (
            ! checkdate($month, $day, $year)
            || $hour > 23
            || $minute > 59
            || $second > 59
        ) {
            throw new DomainException($label . '이 올바르지 않습니다.');
        }

        return sprintf(
            '%04d-%02d-%02d %02d:%02d:%02d',
            $year,
            $month,
            $day,
            $hour,
            $minute,
            $second
        );
    }
}
