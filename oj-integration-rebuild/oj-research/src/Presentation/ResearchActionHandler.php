<?php

declare(strict_types=1);

namespace CodingPanda\OjResearch\Presentation;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjResearch\Application\ResearchService;
use CodingPanda\OjResearch\Domain\PilotProtocol;
use DomainException;
use Throwable;

final class ResearchActionHandler implements ActionHandler
{
    private readonly ResearchStudyActions $studies;
    private readonly ResearchParticipantActions $participants;
    private readonly ResearchExportAction $exports;

    public function __construct(
        private readonly ResearchService $research,
        private readonly ActionAccessGuard $guard,
        private readonly ErrorReporter $errors,
        private readonly ?PilotActions $pilot = null
    ) {
        $this->studies = new ResearchStudyActions($research);
        $this->participants = new ResearchParticipantActions($research);
        $this->exports = new ResearchExportAction($research);
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        try {
            $actor = $this->guard->authenticatedActor('oj_research_command', $request)->userId();
            $operation = $request->bodyString('operation');
            if (str_starts_with($operation, 'pilot.') && $this->pilot !== null) {
                return $this->pilot->handle($actor, $request);
            }
            $id = $request->bodyInt('study_id');
            if (
                $id > 0
                && ! in_array($operation, ['withdraw', 'answer', 'rate'], true)
                && PilotProtocol::is($this->research->managed($actor, $id))
            ) {
                throw new DomainException('시범 연구 관리 화면의 작업을 사용해 주세요.');
            }
            return match ($operation) {
                'create' => $this->studies->create($actor, $request),
                'enroll' => $this->studies->enroll($actor, $request),
                'allocate' => $this->studies->allocate($actor, $request),
                'transition' => $this->studies->transition($actor, $request),
                'answer' => $this->participants->answer($actor, $request),
                'rate' => $this->participants->rate($actor, $request),
                'missing' => $this->participants->missing($actor, $request),
                'withdraw' => $this->participants->withdraw($actor, $request),
                'export' => $this->exports->handle($actor, $request),
                default => throw new DomainException('지원하지 않는 연구 작업입니다.'),
            };
        } catch (DomainException $error) {
            return HttpResponse::html(
                '<h1>연구 작업을 완료하지 못했습니다.</h1><p>'
                    . htmlspecialchars($error->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    . '</p><p><a href="/oj-console/research">연구 목록으로</a> · '
                    . '<a href="/research">내 활동으로</a></p>',
                409
            );
        } catch (Throwable $error) {
            $this->errors->report($error);
            return HttpResponse::html(
                '<h1>연구 작업을 저장하지 못했습니다.</h1><p>연결 상태를 확인한 뒤 다시 시도해 주세요.</p>',
                503
            );
        }
    }
}
