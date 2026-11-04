<?php

declare(strict_types=1);

namespace CodingPanda\OjResearch\Presentation;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjResearch\Domain\PilotReport;
use CodingPanda\OjResearch\Domain\ClassroomProtocol;
use CodingPanda\OjResearch\Domain\ClassroomDocuments;
use CodingPanda\OjPlatform\Shared\Export\SpreadsheetCsv;
use DomainException;

final class PilotReportExporter
{
    public function response(array $study, array $data, string $format,string $document='all'): HttpResponse
    {
        if($document!=='all'&&!ClassroomProtocol::is($study)){throw new DomainException('이 연구는 개별 첨부자료를 지원하지 않습니다.');}
        $sections=$document==='all'?PilotReport::sections($study,$data):ClassroomDocuments::sections($study,$data,$document);
        $title=$document==='all'?'연구 보고서':ClassroomDocuments::title($document);
        if($document!=='all'&&$format==='csv'){throw new DomainException('CSV는 전체 학생별 데이터에서 내려받아 주세요.');}
        [$extension, $type, $body] = match ($format) {
            'csv' => ['csv', 'text/csv', $this->csv($data['rows'])],
            'markdown' => ['md', 'text/markdown', $this->markdown($study,$sections,$title)],
            'html' => ['html', 'text/html', $this->html($study,$sections,$title)],
            'json' => ['json', 'application/json', json_encode($document==='all'?$data:['document'=>$document,'title'=>$title,'protocol_hash'=>$data['protocol_hash'],'data_hash'=>$data['data_hash'],'sections'=>$sections], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)],
            default => throw new DomainException('지원하지 않는 보고서 형식입니다.'),
        };
        return HttpResponse::download('research-' . $study['id'] . ($document==='all'?'':'-'.$document) . '.' . $extension, $type, $body);
    }

    private function csv(array $rows): string
    {
        return SpreadsheetCsv::encode($rows === [] ? [] : [array_keys($rows[0]), ...array_map('array_values', $rows)]);
    }

    private function html(array $study,array $sections,string $title): string
    {
        $body = '<!doctype html><html lang="ko"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $this->escape($study['title']) . '</title>'
            . '<style>body{font:16px/1.8 sans-serif;max-width:1100px;margin:40px auto;padding:20px;color:#172b38}'
            . 'table{border-collapse:collapse;width:100%;font-size:13px}td,th{border:1px solid #bbc8cc;padding:8px;overflow-wrap:anywhere}'
            . 'h2{margin-top:40px}h3{break-after:avoid}tr{break-inside:avoid}@media print{body{margin:0;padding:0}h2{break-before:page}}'
            . '</style></head><body><h1>' . $this->escape($study['title']) . '</h1>'
            . '<p>'.$this->escape($title).' · 저장 버전 ' . (int) $study['revision'] . '</p>';
        foreach ($sections as $section) {
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

    private function markdown(array $study,array $sections,string $title):string
    {
        $e=static fn($v)=>htmlspecialchars((string)$v,ENT_NOQUOTES|ENT_SUBSTITUTE,'UTF-8');
        $cell=static fn($v)=>str_replace(["|","\r","\n"],["\\|",'', '<br>'],$e($v));
        $out='# '.$e($study['title'])."\n\n".$e($title).' · 저장 버전 '.$study['revision']."\n\n";
        foreach($sections as$section){$out.='## '.$e($section['title'])."\n\n".implode("\n\n",array_map($e,$section['paragraphs']))."\n\n";
            foreach($section['tables']as$t){$out.='### '.$e($t['caption'])."\n\n| ".implode(' | ',array_map($cell,$t['headers']))." |\n| ".implode(' | ',array_fill(0,count($t['headers']),'---'))." |\n";
                foreach($t['rows']as$row){$out.='| '.implode(' | ',array_map($cell,$row))." |\n";}$out.="\n";}}
        return $out;
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
