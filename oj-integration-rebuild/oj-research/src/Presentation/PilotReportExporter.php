<?php

declare(strict_types=1);

namespace CodingPanda\OjResearch\Presentation;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjResearch\Domain\PilotReport;
use CodingPanda\OjPlatform\Shared\Export\SpreadsheetCsv;
use DomainException;

final class PilotReportExporter
{
    public function response(array $study, array $data, string $format): HttpResponse
    {
        [$extension, $type, $body] = match ($format) {
            'csv' => ['csv', 'text/csv', $this->csv($data['rows'])],
            'markdown' => ['md', 'text/markdown', PilotReport::markdown($study, $data)],
            'html' => ['html', 'text/html', $this->html($study, $data)],
            'json' => ['json', 'application/json', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)],
            default => throw new DomainException('지원하지 않는 보고서 형식입니다.'),
        };
        return HttpResponse::download('research-' . $study['id'] . '.' . $extension, $type, $body);
    }

    private function csv(array $rows): string
    {
        return SpreadsheetCsv::encode($rows === [] ? [] : [array_keys($rows[0]), ...array_map('array_values', $rows)]);
    }

    private function html(array $study, array $data): string
    {
        $body = '<!doctype html><html lang="ko"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $this->escape($study['title']) . '</title>'
            . '<style>body{font:16px/1.8 sans-serif;max-width:1100px;margin:40px auto;padding:20px;color:#172b38}'
            . 'table{border-collapse:collapse;width:100%;font-size:13px}td,th{border:1px solid #bbc8cc;padding:8px;overflow-wrap:anywhere}'
            . 'h2{margin-top:40px}h3{break-after:avoid}tr{break-inside:avoid}@media print{body{margin:0;padding:0}h2{break-before:page}}'
            . '</style></head><body><h1>' . $this->escape($study['title']) . '</h1>'
            . '<p>연구 보고서 · 저장 버전 ' . (int) $study['revision'] . '</p>';
        foreach (PilotReport::sections($study, $data) as $section) {
            $body .= '<h2>' . $this->escape($section['title']) . '</h2>';
            foreach ($section['paragraphs'] as $text) {
                $body .= '<p>' . nl2br($this->escape($text)) . '</p>';
            }
            foreach ($section['tables'] as $table) {
                $body .= $this->table($table);
            }
        }
        return $body . '</body></html>';
    }

    private function table(array $table): string
    {
        $body = '<h3>' . $this->escape($table['caption']) . '</h3><table><thead><tr>';
        foreach ($table['headers'] as $header) {
            $body .= '<th>' . $this->escape($header) . '</th>';
        }
        $body .= '</tr></thead><tbody>';
        foreach ($table['rows'] as $row) {
            $body .= '<tr>';
            foreach ($row as $value) {
                $body .= '<td>' . $this->escape($value) . '</td>';
            }
            $body .= '</tr>';
        }
        return $body . '</tbody></table>';
    }

    private function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
