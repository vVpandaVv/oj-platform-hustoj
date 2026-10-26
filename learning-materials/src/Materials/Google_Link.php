<?php
declare(strict_types=1);

namespace LearningMaterials\Materials;

use DomainException;

final class Google_Link {
    /** @return array{source_url:string,viewer_url:string} */
    public static function parse(string $url): array {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || strlen($url) > 2048) {
            throw new DomainException(__('Google 문서·시트·슬라이드 또는 Drive 파일 공유 링크를 입력해 주세요.', 'learning-materials'));
        }
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        parse_str($parts['query'] ?? '', $query);
        parse_str($parts['fragment'] ?? '', $fragment);
        $source = '';
        $viewer = '';
        if ($host === 'docs.google.com' && preg_match('~^/(document|spreadsheets|presentation)/(?:u/\d+/)?d/(e/)?([A-Za-z0-9_-]+)(?:/|$)~D', $path, $match)) {
            $base = 'https://docs.google.com/' . $match[1] . '/d/' . $match[2] . $match[3];
            if ($match[2] !== '') {
                $source = $base . ($match[1] === 'spreadsheets' ? '/pubhtml' : '/pub');
                $viewer = $base . ($match[1] === 'presentation' ? '/embed' : ($match[1] === 'spreadsheets' ? '/pubhtml' : '/pub?embedded=true'));
            } else {
                $source = $base . '/edit';
                $viewer = $base . ($match[1] === 'presentation' ? '/embed' : '/preview');
            }
            $gid = $query['gid'] ?? $fragment['gid'] ?? '';
            if ($match[1] === 'spreadsheets' && is_string($gid) && preg_match('/^\d{1,20}$/D', $gid)) {
                $source .= '?gid=' . $gid;
                $viewer .= '?gid=' . $gid;
            }
        } elseif ($host === 'drive.google.com') {
            $id = '';
            if (preg_match('~^/file/d/([A-Za-z0-9_-]+)(?:/|$)~D', $path, $match)) {
                $id = $match[1];
            } elseif ($path === '/open' && isset($query['id']) && is_string($query['id']) && preg_match('/^[A-Za-z0-9_-]+$/D', $query['id'])) {
                $id = $query['id'];
            }
            if ($id !== '') {
                $source = 'https://drive.google.com/file/d/' . $id . '/view';
                $viewer = 'https://drive.google.com/file/d/' . $id . '/preview';
            }
        }
        if ($viewer === '') {
            throw new DomainException(__('지원하는 Google 문서 공유 링크가 아닙니다.', 'learning-materials'));
        }
        $key = $query['resourcekey'] ?? '';
        if (is_string($key) && preg_match('/^[A-Za-z0-9_-]{1,200}$/D', $key)) {
            $source .= (str_contains($source, '?') ? '&' : '?') . 'resourcekey=' . $key;
            $viewer .= (str_contains($viewer, '?') ? '&' : '?') . 'resourcekey=' . $key;
        }
        return array('source_url' => $source, 'viewer_url' => $viewer);
    }
}
