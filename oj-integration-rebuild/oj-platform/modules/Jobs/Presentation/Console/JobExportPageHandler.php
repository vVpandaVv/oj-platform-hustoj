<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Jobs\Application\JobAccessPolicy;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobQuery;
use CodingPanda\OjPlatform\Modules\Jobs\Domain\JobDetails;
use CodingPanda\OjPlatform\Modules\Jobs\Domain\JobItemDetails;
use JsonException;
use CodingPanda\OjPlatform\Shared\Export\SpreadsheetCsv;
use RuntimeException;

final class JobExportPageHandler implements RouteHandler
{
    public function __construct(
        private readonly CurrentAccountProvider $accounts,
        private readonly JobQuery $jobs,
        private readonly JobAccessPolicy $access
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($request);
        $jobId = (int) ($route->parameters()['job_id'] ?? 0);
        $userId = $this->accounts->current()->identity()?->userId() ?? 0;
        $job = $this->jobs->find($jobId);
        if ($job === null || ! $this->access->canView($userId, $job)) {
            return HttpResponse::notFound('작업 결과를 찾을 수 없습니다.');
        }

        return new HttpResponse(
            200,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => sprintf(
                    'attachment; filename="oj-job-%d.csv"',
                    $job->id()
                ),
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
            $this->csv($job)
        );
    }

    private function csv(JobDetails $job): string
    {
        $stream = fopen('php://temp', 'w+b');
        if (! is_resource($stream)) {
            throw new RuntimeException('작업 결과 내보내기를 준비하지 못했습니다.');
        }
        try {
            fwrite($stream, "\xEF\xBB\xBF");
            $this->writeRow($stream, [
                '작업 번호',
                '작업 종류',
                '항목 번호',
                '대상 종류',
                '대상 키',
                '상태',
                '시도',
                '최대 시도',
                '결과 코드',
                '결과 메시지',
                '안전한 결과',
            ]);
            foreach ($job->items() as $item) {
                $this->writeItem($stream, $job, $item);
            }
            rewind($stream);
            $contents = stream_get_contents($stream);
            if (! is_string($contents)) {
                throw new RuntimeException('작업 결과 내보내기를 읽지 못했습니다.');
            }

            return $contents;
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param resource $stream
     */
    private function writeItem(
        mixed $stream,
        JobDetails $job,
        JobItemDetails $item
    ): void {
        try {
            $safeResult = json_encode(
                $item->safeResult(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException) {
            $safeResult = '{}';
        }
        $this->writeRow($stream, [
            (string) $job->id(),
            $job->jobType(),
            (string) $item->id(),
            $item->targetType(),
            $item->targetKey(),
            $item->status(),
            (string) $item->attemptCount(),
            (string) $item->maxAttempts(),
            $item->resultCode() ?? '',
            $item->resultMessage() ?? '',
            $safeResult,
        ]);
    }

    /**
     * @param resource $stream
     * @param list<string> $values
     */
    private function writeRow(mixed $stream, array $values): void
    {
        SpreadsheetCsv::writeRow($stream, $values);
    }
}
