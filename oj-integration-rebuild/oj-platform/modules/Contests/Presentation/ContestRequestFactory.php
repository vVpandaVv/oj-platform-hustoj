<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Contests\Presentation;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Contests\Application\ContestSaveCommand;
use CodingPanda\OjPlatform\Modules\Contests\Domain\ContestScoringPolicy;
use DomainException;

final class ContestRequestFactory
{
    public function __construct(private readonly WordPressUserGateway $users)
    {
    }

    public function saveCommand(HttpRequest $request): ContestSaveCommand
    {
        $problemIds = $this->ids($request->bodyString('problem_ids'));
        $scoringPolicy = $request->bodyString(
            'scoring_policy',
            ContestScoringPolicy::STANDARD
        );

        return new ContestSaveCommand(
            $request->bodyInt('contest_id'),
            $request->bodyString('title'),
            $request->bodyString('description'),
            $this->dateTime($request->bodyString('starts_at')),
            $this->dateTime($request->bodyString('ends_at')),
            $request->bodyString('visibility', 'private'),
            $request->bodyInt('group_id') > 0
                ? $request->bodyInt('group_id')
                : null,
            $request->bodyString('result_visibility', 'participants'),
            $request->bodyString('ranking_visibility', 'private'),
            max(0, $request->bodyInt('wrong_penalty_points', 5)),
            $request->bodyInt('time_score_percent', 10),
            $request->bodyString('partial_score_policy', 'none'),
            $scoringPolicy,
            ContestScoringPolicy::rankingFor($scoringPolicy),
            max(0, $request->bodyInt('freeze_minutes')),
            $request->bodyString('blockly_profile_key'),
            $request->bodyBool('allow_ai_feedback'),
            $request->bodyString(
                'wrong_answer_policy',
                'penalty_before_accept'
            ),
            $request->bodyString('ip_policy', 'none'),
            $problemIds,
            $this->scores($request->bodyString('problem_scores')),
            $this->managerRoles($request->bodyString('manager_role_logins')),
            $request->bodyBool('approval_required')
        );
    }

    /**
     * @return list<int>
     */
    private function ids(string $value): array
    {
        preg_match_all('/\d+/', $value, $matches);

        return array_values(array_unique(array_filter(array_map(
            'intval',
            $matches[0] ?? []
        ))));
    }

    /**
     * @return array<int, float>
     */
    private function scores(string $value): array
    {
        $scores = [];
        foreach (preg_split('/\R/', $value) ?: [] as $line) {
            if (
                preg_match(
                    '/^\s*(\d+)\s*[:=,]\s*(\d+(?:\.\d+)?)\s*$/',
                    $line,
                    $matches
                ) === 1
            ) {
                $scores[(int) $matches[1]] = (float) $matches[2];
            }
        }

        return $scores;
    }

    /**
     * @return array<int, string>
     */
    private function managerRoles(string $value): array
    {
        $roles = [];
        $roleNames = [
            '공동 운영자' => 'manager',
            '시험 감독자' => 'proctor',
            '결과 검토자' => 'reviewer',
        ];
        foreach (preg_split('/\R/u', $value) ?: [] as $line) {
            $parts = preg_split('/\s*[:=,]\s*/u', trim($line), 2) ?: [];
            if (count($parts) !== 2 || ! isset($roleNames[$parts[1]])) {
                if (trim($line) === '') {
                    continue;
                }
                throw new DomainException(
                    '담당자는 로그인 아이디:역할 형식으로 입력해 주세요.'
                );
            }
            $identity = $this->users->findByLogin($parts[0]);
            if ($identity === null) {
                throw new DomainException(
                    '대회 담당자 로그인 아이디를 찾을 수 없습니다: '
                    . $parts[0]
                );
            }
            $roles[$identity->userId()] = $roleNames[$parts[1]];
        }

        return $roles;
    }

    private function dateTime(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (preg_match(
            '/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/',
            $value,
            $parts
        ) !== 1) {
            throw new DomainException('대회 시작·종료 시각이 올바르지 않습니다.');
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
            throw new DomainException('대회 시작·종료 시각이 올바르지 않습니다.');
        }

        return sprintf(
            '%04d-%02d-%02d %02d:%02d:%02d',
            $year, $month, $day, $hour, $minute, $second
        );
    }
}
