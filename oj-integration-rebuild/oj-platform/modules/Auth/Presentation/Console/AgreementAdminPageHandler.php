<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AgreementRepository;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AgreementDocumentType;
use CodingPanda\OjPlatform\Ui\Editor\RichTextEditor;
use CodingPanda\OjPlatform\Kernel\Addon\AddonPrivacyInventory;

final class AgreementAdminPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly AgreementRepository $agreements,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly ?AddonPrivacyInventory $addonPrivacy = null
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);

        $editType = AgreementDocumentType::tryFrom($request->queryString('edit_type'));
        $editing = $editType === null ? null : $this->agreements->activeDocumentByType($editType->value);

        $rows = '';
        foreach ($this->agreements->activeDocuments() as $document) {
            $type = AgreementDocumentType::tryFrom($document->typeSlug());
            $rows .= '<tr><td>' . $this->escape($type?->label() ?? '기타 문서')
                . '</td><td>' . ($type === null ? $this->escape($document->title())
                    : '<a href="' . $this->escape($type->publicPath()) . '">' . $this->escape($document->title()) . '</a>')
                . '</td><td>' . $this->escape($document->version())
                . '</td><td>' . (! $document->isForUserConsent() ? '법정대리인 전용' : ($document->isRequired() ? '필수' : '선택'))
                . '</td><td>' . ($type === null ? '' : '<a class="ojp-row-link" href="/oj-console/agreements?edit_type='
                    . $this->escape(rawurlencode($type->value)) . '#agreement-editor">수정</a>') . '</td></tr>';
        }

        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">사용자</p>'
            . '<h1>동의 문서</h1><p>게시할 때마다 새 버전을 만듭니다.</p></div></header>'
            . '<div class="ojp-table-wrap"><table class="ojp-table">'
            . '<thead><tr><th>문서 종류</th><th>제목</th><th>버전</th><th>구분</th><th>편집</th></tr></thead>'
            . '<tbody>' . ($rows !== '' ? $rows : '<tr><td colspan="5">게시된 문서가 없습니다.</td></tr>')
            . '</tbody></table></div>'
            . '<section class="ojp-section" id="agreement-editor"><div class="ojp-section-heading"><div>'
            . '<h2>' . ($editing === null ? '새 버전 게시' : $this->escape($editing->title()) . ' 수정') . '</h2>'
            . ($editing === null ? '' : '<p>현재 버전: ' . $this->escape($editing->version()) . '</p>')
            . '</div>' . ($editing === null ? '' : '<a class="ojp-row-link" href="/oj-console/agreements#agreement-editor">새 문서 작성</a>') . '</div>'
            . '<form class="ojp-form-stack" data-ojp-agreement-document-form method="post" action="'
            . $this->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_save_agreement_document')
            . '<div class="ojp-form-grid">'
            . $this->documentTypeField($editing?->typeSlug() ?? '')
            . $this->field('버전', 'version', '예: 1.0', true, $editing === null ? '' : $editing->version() . '.1')
            . $this->field('제목 (선택)', 'title', '선택한 문서 이름 사용', false, $editing?->title() ?? '')
            . '</div>' . (new RichTextEditor())->render('content', '본문', $editing?->content() ?? '', 16, true, tables: true)
            . '<label class="ojp-check"><input type="checkbox" name="required" value="1"'
            . ($editing === null || $editing->isRequired() ? ' checked' : '') . ' data-ojp-agreement-required>'
            . '<span data-ojp-agreement-required-label>필수 동의 문서</span></label>'
            . '<button class="ojp-button ojp-button--primary" type="submit">새 버전 게시</button>'
            . '</form></section>'
            . $this->addonPrivacyInventory();

        return $this->pages->respond(
            '동의 문서',
            $content,
            'users.agreements',
            $request
        );
    }

    private function addonPrivacyInventory(): string
    {
        $items = $this->addonPrivacy?->all() ?? [];
        if ($items === []) {
            return '';
        }
        $rows = '';
        foreach ($items as $item) {
            $definition = $item['definition'];
            $rows .= '<tr><td>' . $this->escape($item['addon']) . '</td><td>'
                . $this->escape($definition->label()) . '</td><td>'
                . $this->escape(implode(', ', $definition->dataCategories())) . '</td><td>'
                . $this->escape($definition->retention()) . '</td><td>'
                . $this->escape($definition->administrationNote()) . '</td></tr>';
        }

        return '<section class="ojp-section"><div class="ojp-section-heading"><div>'
            . '<h2>애드온 개인정보 관리 참고</h2>'
            . '<p>아래 저장 항목을 검토한 뒤 이 플랫폼의 개인정보 처리방침에 직접 반영하세요. 애드온은 정책 문구를 자동으로 제시하거나 게시하지 않습니다.</p>'
            . '</div></div><div class="ojp-table-wrap"><table class="ojp-table">'
            . '<thead><tr><th>애드온</th><th>기능</th><th>저장 항목</th><th>보존</th><th>관리 참고</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div></section>';
    }

    private function documentTypeField(string $selected = ''): string
    {
        $options = '<option value=""' . ($selected === '' ? ' selected' : '') . ' disabled>문서를 선택하세요</option>';
        foreach (AgreementDocumentType::cases() as $type) {
            $options .= '<option value="' . $this->escape($type->value) . '"'
                . ($selected === $type->value ? ' selected' : '')
                . ($type->isGuardianOnly() ? ' data-guardian-only="true"' : '') . '>'
                . $this->escape($type->label()) . '</option>';
        }

        return '<label class="ojp-field"><span class="ojp-label">문서 종류</span>'
            . '<select class="ojp-select" name="type_slug" required>' . $options . '</select></label>';
    }

    private function field(string $label, string $name, string $placeholder, bool $required = true, string $value = ''): string
    {
        return '<label class="ojp-field"><span class="ojp-label">'
            . $this->escape($label) . '</span><input class="ojp-input" type="text" name="'
            . $this->escape($name) . '" placeholder="' . $this->escape($placeholder) . '"'
            . ' value="' . $this->escape($value) . '"'
            . ($required ? ' required' : '') . '></label>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
