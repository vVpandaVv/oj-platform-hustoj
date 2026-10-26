<?php

declare(strict_types=1);

namespace CodingPanda\OjResearch\Presentation;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjResearch\Application\ResearchService;
use DomainException;

final class ResearchStudyActions
{
    public function __construct(private readonly ResearchService $research)
    {
    }

    public function create(int $actor, HttpRequest $request): HttpResponse
    {
        $problems = preg_split('/\R/u', trim($request->bodyString('practice_problems'))) ?: [];
        $checks = preg_split('/\R/u', trim($request->bodyString('check_prompts'))) ?: [];
        if (count($problems) !== count($checks)) {
            throw new DomainException('차시별 학습 문제와 확인 문제의 줄 수를 맞춰 주세요.');
        }
        $lessons = [];
        foreach ($problems as $index => $line) {
            if (preg_match('/^\s*\d+(?:\s*,\s*\d+)*\s*$/D', $line) !== 1) {
                throw new DomainException('학습 문제 번호는 쉼표로 구분해 주세요.');
            }
            $lessons[] = ['problems' => array_map('intval', explode(',', $line)), 'check' => $checks[$index]];
        }
        $input = [
            'title' => $request->bodyString('title'),
            'groups' => $request->bodyIntList('groups'),
            'raters' => [$request->bodyInt('rater_1'), $request->bodyInt('rater_2')],
            'lessons' => $lessons,
            'delay_days' => $request->bodyInt('delay_days', 14),
        ];
        foreach (['pretest', 'posttest', 'delayed', 'instructions', 'analysis_plan', 'consent_reference'] as $key) {
            $input[$key] = $request->bodyString($key);
        }
        return $this->saved($this->research->create($actor, $input));
    }

    public function enroll(int $actor, HttpRequest $request): HttpResponse
    {
        if (! $request->bodyBool('consent_confirmed')) {
            throw new DomainException('선택 학생의 연구 참여와 자료 수집 동의 확인이 필요합니다.');
        }
        $id = $request->bodyInt('study_id');
        $this->research->enroll($actor, $id, $request->bodyInt('revision'), $request->bodyIntList('participants'));
        return $this->saved($id);
    }

    public function allocate(int $actor, HttpRequest $request): HttpResponse
    {
        $id = $request->bodyInt('study_id');
        $this->research->allocate($actor, $id, $request->bodyInt('revision'));
        return $this->saved($id);
    }

    public function transition(int $actor, HttpRequest $request): HttpResponse
    {
        $id = $request->bodyInt('study_id');
        $this->research->transition(
            $actor, $id, $request->bodyInt('revision'),
            $request->bodyString('next_phase'), $request->bodyString('reason')
        );
        return $this->saved($id);
    }

    private function saved(int $id): HttpResponse
    {
        return HttpResponse::redirect('/oj-console/research/' . $id . '?notice=saved');
    }
}
