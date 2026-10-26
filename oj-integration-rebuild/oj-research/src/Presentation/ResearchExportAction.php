<?php

declare(strict_types=1);

namespace CodingPanda\OjResearch\Presentation;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjResearch\Application\ResearchService;
use CodingPanda\OjPlatform\Shared\Export\SpreadsheetCsv;

final class ResearchExportAction
{
    public function __construct(private readonly ResearchService $research)
    {
    }

    public function handle(int $actor, HttpRequest $request): HttpResponse
    {
        $id = $request->bodyInt('study_id');
        $data = $this->research->export($actor, $id);
        if ($request->bodyString('format') === 'csv') {
            $rows = $data['participants'];
            $csv = $rows === [] ? [] : [array_keys($rows[0]), ...array_map('array_values', $rows)];
            return HttpResponse::download('research-' . $id . '.csv', 'text/csv', SpreadsheetCsv::encode($csv));
        }
        return HttpResponse::download(
            'research-' . $id . '.json',
            'application/json',
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );
    }
}
