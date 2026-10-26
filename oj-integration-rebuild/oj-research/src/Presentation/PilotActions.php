<?php

declare(strict_types=1);

namespace CodingPanda\OjResearch\Presentation;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjResearch\Application\ResearchService;
use CodingPanda\OjResearch\Application\PilotService;
use CodingPanda\OjResearch\Domain\PilotProtocol;
use CodingPanda\OjResearch\Domain\PilotReport;
use DomainException;

final class PilotActions
{
    public function __construct(
        private readonly ResearchService $research,
        private readonly PilotService $pilot,
        private readonly PilotReportExporter $exports = new PilotReportExporter()
    ) {
    }

    public function handle(int $actor, HttpRequest $request): HttpResponse
    {
        return match ($request->bodyString('operation')) {
            'pilot.save' => $this->save($actor, $request),
            'pilot.material' => $this->material($actor, $request),
            'pilot.enroll' => $this->enroll($actor, $request),
            'pilot.allocate' => $this->allocate($actor, $request),
            'pilot.transition' => $this->transition($actor, $request),
            'pilot.collect' => $this->collect($actor, $request),
            'pilot.timer' => $this->timer($actor, $request),
            'pilot.finish' => $this->finish($actor, $request),
            'pilot.survey' => $this->survey($actor, $request),
            'pilot.export' => $this->export($actor, $request),
            default => throw new DomainException('지원하지 않는 연구 작업입니다.'),
        };
    }

    private function save(int $actor, HttpRequest $request): HttpResponse
    {
        $id = $this->pilot->save(
            $actor, $request->bodyInt('study_id'), $request->bodyInt('revision'),
            PilotConfigurationInput::fromRequest($request)
        );
        return $this->saved($id);
    }

    private function material(int $actor, HttpRequest $request): HttpResponse
    {
        $input = ['reviewed' => $request->bodyBool('reviewed')];
        foreach (['topic', 'difficulty', 'answer', 'explanation', 'errors', 'source', 'version'] as $key) {
            $input[$key] = $request->bodyString($key);
        }
        $id = $request->bodyInt('study_id');
        $this->pilot->material($actor, $id, $request->bodyInt('revision'), $request->bodyInt('problem_id'), $input);
        return $this->saved($id, 'materials');
    }

    private function enroll(int $actor, HttpRequest $request): HttpResponse
    {
        if (! $request->bodyBool('consent_confirmed')) {
            throw new DomainException('선택 학생의 연구 참여와 자료 수집 동의를 확인해 주세요.');
        }
        $id = $request->bodyInt('study_id');
        $this->pilot->enroll($actor, $id, $request->bodyInt('revision'), $request->bodyIntList('participants'));
        return $this->saved($id);
    }

    private function allocate(int $actor, HttpRequest $request): HttpResponse
    {
        $id = $request->bodyInt('study_id');
        $manual = [];
        foreach ($this->research->participants($actor, $id) as $participant) {
            $manual[$participant['id']] = $request->bodyString('assign_' . $participant['id']);
        }
        $this->pilot->allocate($actor, $id, $request->bodyInt('revision'), $manual);
        return $this->saved($id, 'students');
    }

    private function transition(int $actor, HttpRequest $request): HttpResponse
    {
        $id = $request->bodyInt('study_id');
        $this->pilot->transition(
            $actor, $id, $request->bodyInt('revision'),
            $request->bodyString('next_phase'), $request->bodyString('reason')
        );
        return $this->saved($id);
    }

    private function collect(int $actor, HttpRequest $request): HttpResponse
    {
        $id = $request->bodyInt('study_id');
        $this->pilot->collect($actor, $id, $request->bodyInt('revision'));
        return $this->saved($id);
    }

    private function timer(int $actor, HttpRequest $request): HttpResponse
    {
        $problem = $request->bodyInt('problem_id');
        $action = $request->bodyString('timer_action');
        $this->pilot->timer(
            $actor, $request->bodyInt('study_id'), $request->bodyInt('revision'), $problem, $action
        );
        return HttpResponse::redirect($action === 'start' ? '/problems/' . $problem : '/research?notice=saved');
    }

    private function finish(int $actor, HttpRequest $request): HttpResponse
    {
        $this->pilot->finish($actor, $request->bodyInt('study_id'), $request->bodyInt('revision'));
        return HttpResponse::redirect('/research?notice=saved');
    }

    private function survey(int $actor, HttpRequest $request): HttpResponse
    {
        $context = $this->pilot->learner($actor);
        if ($context === null) {
            throw new DomainException('연구 참여 정보를 확인해 주세요.');
        }
        $answers = [];
        foreach (array_keys($context['study']['data']['protocol']['survey_items']) as $index) {
            $answers[] = $request->bodyString('answer_' . $index);
        }
        $this->pilot->survey($actor, $request->bodyInt('study_id'), $request->bodyInt('revision'), $answers);
        return HttpResponse::redirect('/research?notice=saved');
    }

    private function export(int $actor, HttpRequest $request): HttpResponse
    {
        $id = $request->bodyInt('study_id');
        $study = $this->research->managed($actor, $id);
        if (! PilotProtocol::is($study)) {
            throw new DomainException('시범 연구 보고서가 아닙니다.');
        }
        $data = PilotReport::build(
            $study, $this->research->participants($actor, $id), $this->pilot->records($actor, $id)
        );
        return $this->exports->response($study, $data, $request->bodyString('format'));
    }

    private function saved(int $id, string $tab = ''): HttpResponse
    {
        return HttpResponse::redirect(
            '/oj-console/research/' . $id . '?notice=saved' . ($tab === '' ? '' : '&tab=' . $tab)
        );
    }
}
