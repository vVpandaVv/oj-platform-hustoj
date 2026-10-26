<?php

declare(strict_types=1);

namespace CodingPanda\OjResearch\Presentation;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjResearch\Application\ResearchService;
use CodingPanda\OjResearch\Domain\ResearchProtocol;

final class ResearchParticipantActions
{
    public function __construct(private readonly ResearchService $research)
    {
    }

    public function answer(int $actor, HttpRequest $request): HttpResponse
    {
        $this->research->submitAnswer(
            $actor, $request->bodyInt('study_id'), $request->bodyInt('revision'),
            $request->bodyString('code'), $request->bodyString('explanation')
        );
        return HttpResponse::redirect('/research?notice=saved');
    }

    public function rate(int $actor, HttpRequest $request): HttpResponse
    {
        $scores = [];
        foreach (array_keys(ResearchProtocol::RUBRIC) as $key) {
            $scores[$key] = $request->bodyString($key);
        }
        $this->research->rate($actor, $request->bodyInt('study_id'), $request->bodyString('blind_id'), $scores);
        return HttpResponse::redirect('/oj-console/research/rating?notice=saved');
    }

    public function missing(int $actor, HttpRequest $request): HttpResponse
    {
        $id = $request->bodyInt('study_id');
        $this->research->missing(
            $actor, $id, $request->bodyInt('revision'), $request->bodyInt('participant_id'),
            $request->bodyString('assessment'), $request->bodyString('reason')
        );
        return HttpResponse::redirect('/oj-console/research/' . $id . '?notice=saved');
    }

    public function withdraw(int $actor, HttpRequest $request): HttpResponse
    {
        $id = $request->bodyInt('study_id');
        $this->research->withdraw(
            $actor, $id, $request->bodyInt('revision'), $request->bodyInt('participant_id'),
            $request->bodyString('reason')
        );
        return HttpResponse::redirect('/oj-console/research/' . $id . '?notice=saved');
    }
}
