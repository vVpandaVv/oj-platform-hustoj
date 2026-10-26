<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Classes\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Classes\Application\ClassService;
use CodingPanda\OjPlatform\Ui\Component\SiteDateFormatter;
use CodingPanda\OjPlatform\Shared\Export\SpreadsheetCsv;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use Throwable;

final class ExportClassProgressActionHandler implements ActionHandler
{
    private const ACTION = 'oj_class_export_progress';

    public function __construct(
        private readonly ClassService $classes,
        private readonly ActionAccessGuard $guard,
        private readonly ActionResponse $responses,
        private readonly ErrorReporter $errors,
        private readonly SiteDateFormatter $dates = new SiteDateFormatter()
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $actorId = 0;
        $classId = $request->bodyInt('class_id');
        try {
            $actor = $this->guard->authenticatedActor(self::ACTION, $request);
            $actorId = $actor->userId();
            $report = $this->classes->report(
                $actorId,
                $actor->userLogin(),
                $classId
            );
            $lines = [
                'submission_id,user_login,problem_id,result_code,submitted_at,site_timezone',
            ];
            foreach ($report->submissions() as $submission) {
                $lines[] = implode(',', [
                    $submission->submissionId(),
                    $this->csv($submission->userLogin()),
                    $submission->problemId(),
                    $submission->resultCode(),
                    $this->csv($this->dates->export($submission->submittedAt())),
                    $this->csv($this->dates->timezoneLabel($submission->submittedAt())),
                ]);
            }

            return HttpResponse::download(
                'class-' . $classId . '-progress.csv',
                'text/csv',
                implode("\r\n", $lines) . "\r\n"
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->responses->redirect(
                '/oj-console/classes/' . $classId . '/progress',
                new UiMessage(
                    'classes.export_failed',
                    MessageTone::Danger,
                    '내보내기 실패',
                    '수업 학습 현황을 내보내지 못했습니다.'
                ),
                $actorId
            );
        }
    }

    private function csv(string $value): string
    {
        return SpreadsheetCsv::cell($value);
    }
}
