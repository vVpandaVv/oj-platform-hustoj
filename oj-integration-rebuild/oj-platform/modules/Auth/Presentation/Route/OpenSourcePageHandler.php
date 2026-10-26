<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Presentation\Route;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;
use CodingPanda\OjPlatform\Ui\License\OpenSourceCatalog;

final class OpenSourcePageHandler implements RouteHandler
{
    public function __construct(
        private readonly PageResponder $pages,
        private readonly ?CurrentAccountProvider $accounts = null
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);
        $rows = $notices = '';
        foreach ((new OpenSourceCatalog())->components() as $item) {
            $rows .= '<tr><th scope="row"><a href="#license-' . $this->escape($item['id']) . '">'
                . $this->escape($item['name']) . '</a></th><td>' . $this->escape($item['version'])
                . '</td><td>' . $this->escape($item['license']) . '</td><td>' . $this->escape($item['usage']) . '</td></tr>';
            $notices .= '<section class="ojp-section" id="license-' . $this->escape($item['id']) . '"><h2>'
                . $this->escape($item['name']) . '</h2><p><a href="' . $this->escape($item['source'])
                . '" target="_blank" rel="noopener noreferrer">원본 프로젝트 및 소스</a></p>';
            foreach ($item['notices'] as $notice) {
                $notices .= '<details class="ojp-license-notice"><summary>' . $this->escape(basename($notice['path']))
                    . '</summary><pre class="ojp-license-text">' . $this->escape($notice['text']) . '</pre></details>';
            }
            $notices .= '</section>';
        }
        $addonNotices = '<section class="ojp-section" id="addons"><h2>설치된 추가 플러그인</h2><p>제공 애드온의 자체 선언과 주요 외부 고지입니다. 미선언은 독점 라이선스나 단독 저작을 뜻하지 않습니다. 전체 외부 고지 파일은 각 설치 ZIP에도 포함되어 있습니다.</p>';
        foreach ((new OpenSourceCatalog())->addons() as $addon) {
            $addonNotices .= '<section id="addon-' . $this->escape($addon['id']) . '"><h3>' . $this->escape($addon['name']) . '</h3><p>' . $this->escape($addon['license']) . '</p>';
            if ($addon['notices'] === []) {
                $addonNotices .= '<p>고지 파일을 찾을 수 없습니다. 해당 애드온의 설치 ZIP을 확인해 주세요.</p>';
            }
            foreach ($addon['notices'] as $notice) {
                $addonNotices .= '<details class="ojp-license-notice"><summary>' . $this->escape($notice['path']) . '</summary><pre class="ojp-license-text">' . $this->escape($notice['text']) . '</pre></details>';
            }
            $addonNotices .= '</section>';
        }
        $addonNotices .= '</section>';
        $content = '<article><header class="ojp-page-header"><h1>오픈소스 라이선스</h1></header>'
            . '<section class="ojp-section"><h2>플랫폼과 외부 시스템</h2>'
            . '<h3>OJ Platform — GPL-3.0-or-later</h3>'
            . '<p>Copyright © 2026 codingpanda. 자체 코드는 GNU GPL 버전 3 또는 선택에 따라 이후 버전의 조건으로 수정·재배포할 수 있으며, 어떠한 보증도 제공하지 않습니다.</p>'
            . '<details class="ojp-license-notice"><summary>GNU GPL v3 전문</summary><pre class="ojp-license-text">'
            . $this->escape((new OpenSourceCatalog())->platformLicense()) . '</pre></details>'
            . '<p>자체 PHP·JavaScript 및 이미지 채점용 C++ 소스는 설치 ZIP에 포함됩니다. 수정본을 재배포할 때에는 적용되는 GPL 조건에 따라 해당 수정 소스와 고지도 제공해야 합니다. 외부 라이브러리는 아래 각 라이선스를 유지합니다. 사용자 제출 코드와 문서 내용에 이 라이선스가 자동 적용되는 것은 아닙니다.</p>'
            . '<p><a href="https://github.com/zhblue/hustoj/blob/master/LICENSE" target="_blank" rel="noopener noreferrer">HUSTOJ — GPLv2 라이선스 고지</a>: 별도로 설치·연동하는 채점 엔진입니다. 설치본 및 수정본의 저작권·라이선스 고지와 배포 조건을 함께 확인해야 합니다.</p>'
            . '</section><section class="ojp-section"><h2>플러그인에 포함된 구성요소</h2>'
            . '<div class="ojp-table-wrap"><table class="ojp-table"><thead><tr><th>구성요소</th><th>버전</th><th>라이선스</th><th>사용 용도</th></tr></thead><tbody>'
            . $rows . '</tbody></table></div></section>' . $notices . $addonNotices . '</article>';

        return $this->accounts?->current()->identity() !== null
            ? $this->pages->authenticated('오픈소스 라이선스', $content, $request)
            : $this->pages->guest('오픈소스 라이선스', $content, $request);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
