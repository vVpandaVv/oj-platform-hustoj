<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemXmlExportService;
use CodingPanda\OjPlatform\Ui\Component\SiteDateFormatter;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use Throwable;

final class ProblemXmlExportActionHandler implements ActionHandler
{
    private const ACTION = 'oj_problem_xml_export';
    private const PATH = '/oj-console/problems/xml';

    public function __construct(
        private readonly ProblemXmlExportService $exports,
        private readonly ActionAccessGuard $guard,
        private readonly ActionResponse $responses,
        private readonly ErrorReporter $errors,
        private readonly SiteDateFormatter $dates = new SiteDateFormatter()
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $actorUserId = 0;
        try {
            $actor = $this->guard->authenticatedActor(self::ACTION, $request);
            $actorUserId = $actor->userId();
            $xml = $this->exports->export(
                $actorUserId,
                $request->bodyString('problem_selection'),
                $request->bodyBool('include_metadata'),
                $request->bodyBool('include_test_data')
            );
            $filename = 'oj-problems-'
                . $this->dates->formatTimestamp(time(), 'Ymd-His') . '.xml';

            return new HttpResponse(
                200,
                [
                    'Content-Type' => 'application/xml; charset=UTF-8',
                    'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                    'Content-Length' => (string) strlen($xml),
                    'Cache-Control' => 'private, no-store, max-age=0',
                    'X-Content-Type-Options' => 'nosniff',
                ],
                $xml
            );
        } catch (DomainException $error) {
            return $this->responses->redirect(
                self::PATH,
                new UiMessage(
                    'problems.xml_export_rejected',
                    MessageTone::Danger,
                    'XML 내보내기 거부',
                    $error->getMessage()
                ),
                $actorUserId
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->responses->redirect(
                self::PATH,
                new UiMessage(
                    'problems.xml_export_failed',
                    MessageTone::Danger,
                    'XML 내보내기 실패',
                    'XML 파일을 만들지 못했습니다. 시스템 상태를 확인해 주세요.'
                ),
                $actorUserId
            );
        }
    }
}
