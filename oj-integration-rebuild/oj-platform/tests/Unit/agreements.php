<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteDefinition;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AccessStatus;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AgreementDocumentType;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AgreementRepository;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AttemptLimiter;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccount;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Auth\Domain\AgreementDocument;
use CodingPanda\OjPlatform\Modules\Auth\Domain\UserProfile;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Action\SaveAgreementDocumentActionHandler;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionGuard;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionResponder;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Console\AgreementAdminPageHandler;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Route\LegalDocumentPageHandler;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Permission\Contract\PermissionContext;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;
use CodingPanda\OjPlatform\Ui\Message\FlashStore;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use CodingPanda\OjPlatform\Ui\Editor\RichTextContent;
use CodingPanda\OjPlatform\Ui\Editor\RichTextEditor;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($pluginRoot);

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (! $condition) { throw new RuntimeException($message); }
};
$richText = new RichTextContent();
$assert(str_contains($richText->render('<h2>본문</h2>'), '&lt;h2&gt;'), 'Without WordPress, HTML rendering must fail closed to escaped text.');
$unavailableRejected = false;
try { $richText->forStorage('<h2>본문</h2>'); } catch (RuntimeException) { $unavailableRejected = true; }
$assert($unavailableRejected, 'HTML must not be stored without the WordPress sanitizer.');

// An optional local WordPress checkout exercises the real KSES implementation without a database.
$wordpressRoot = getenv('OJ_TEST_WORDPRESS_ROOT');
if (is_string($wordpressRoot) && $wordpressRoot !== '') {
    define('ABSPATH', rtrim($wordpressRoot, '/\\') . '/');
    define('WPINC', 'wp-includes');
    foreach (['compat-utf8.php', 'class-wp-token-map.php'] as $file) {
        $path = ABSPATH . WPINC . '/' . $file;
        if (is_file($path)) { require_once $path; }
    }
    foreach (['plugin.php', 'formatting.php', 'functions.php', 'kses.php'] as $file) {
        require_once ABSPATH . WPINC . '/' . $file;
    }
    foreach (['html5-named-character-references.php', 'class-wp-html-attribute-token.php', 'class-wp-html-span.php', 'class-wp-html-doctype-info.php', 'class-wp-html-text-replacement.php', 'class-wp-html-decoder.php', 'class-wp-html-tag-processor.php'] as $file) {
        $path = ABSPATH . WPINC . '/html-api/' . $file;
        if (is_file($path)) { require_once $path; }
    }
}
require_once $pluginRoot . '/tests/Support/wordpress-stubs.php';
$assert(str_contains($richText->forStorage('<p><font size="5">서식</font></p>'), '<font size="5">'), 'The WordPress sanitizer must preserve the editor font-size markup.');
$repository = new class implements AgreementRepository {
    public array $documents = [];
    public array $writes = [];
    public array $agreed = [];
    public function activeDocuments(): array { return array_values($this->documents); }
    public function activeDocumentByType(string $typeSlug): ?AgreementDocument { return $this->documents[$typeSlug] ?? null; }
    public function recordAgreement(int $userId, array $documentIds, ?string $ipAddress, ?string $userAgent): void {}
    public function agreedDocumentIds(int $userId): array { return $this->agreed; }
    public function publish(string $typeSlug, string $typeLabel, string $version, string $title, string $content, bool $required, int $createdByUserId): AgreementDocument {
        $this->writes[] = compact('typeSlug', 'typeLabel', 'version', 'title', 'content', 'required', 'createdByUserId');
        return $this->documents[$typeSlug] = new AgreementDocument(count($this->writes), $typeSlug, $version, $title, $content, $required);
    }
};
$pages = new class implements ConsoleResponder, PageResponder {
    public function respond(string $title, string $content, string $currentMenu, HttpRequest $request, int $status = 200): HttpResponse { return HttpResponse::html($content, $status); }
    public function guest(string $title, string $content, HttpRequest $request, int $status = 200): HttpResponse { return HttpResponse::html($content, $status); }
    public function authenticated(string $title, string $content, HttpRequest $request, int $status = 200): HttpResponse { return HttpResponse::html($content, $status); }
};
$security = new class implements ActionSecurity {
    public bool $valid = true;
    public function hiddenFields(string $actionKey): string { return '<input type="hidden" name="action" value="' . $actionKey . '">'; }
    public function verify(string $actionKey, HttpRequest $request): bool { return $this->valid && $actionKey === 'oj_save_agreement_document'; }
};
$urls = new class implements ActionUrlProvider {
    public function actionUrl(): string { return '/wp-admin/admin-post.php'; }
    public function ajaxUrl(): string { return '/wp-admin/admin-ajax.php'; }
};
$accounts = new class implements CurrentAccountProvider {
    public function current(): CurrentAccount {
        return new CurrentAccount(new UserIdentity(7, 'admin', '', '관리자'), new UserProfile(7, null, null, AccessStatus::Active, false));
    }
};
$permissions = new class implements Authorization {
    public bool $allowed = true;
    public function can(int $userId, string $capability, ?PermissionContext $context = null): bool { return $this->allowed && $userId === 7 && $capability === 'oj_manage_agreements'; }
    public function canAny(int $userId, array $capabilities): bool { return false; }
    public function isSuperAdmin(int $userId): bool { return false; }
};
$attempts = new class implements AttemptLimiter {
    public function consume(string $scope, string $identifier, int $limit, int $windowSeconds): bool { return true; }
    public function resetAll(): int { return 0; }
};
$flash = new class implements FlashStore {
    public ?UiMessage $last = null;
    public function issue(UiMessage $message, int $userId = 0): string { $this->last = $message; return ''; }
    public function consume(string $token, int $userId = 0): ?UiMessage { return null; }
};
$handler = new SaveAgreementDocumentActionHandler($repository, new ActionGuard($security, $accounts, $permissions, $attempts), new ActionResponder($flash));
$routes = require $pluginRoot . '/config/routes.php';
$route = null;
foreach ($routes as $definition) {
    if ($definition['name'] === 'console.agreements.index') { $route = new RouteMatch(RouteDefinition::fromArray($definition), []); }
}
$assert($route instanceof RouteMatch, 'Agreement admin route must exist.');
$request = new HttpRequest('GET', '/oj-console/agreements');
$admin = new AgreementAdminPageHandler($pages, $repository, $urls, $security);
$html = $admin->handle($route, $request)->body();
$dom = new DOMDocument();
@$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
$xpath = new DOMXPath($dom);
$assert($xpath->query('//select[@name="type_slug"]')->length === 1, 'One document selector is required.');
$assert($xpath->query('//select[@name="type_slug"]/option[@value!=""]')->length === 5, 'Four public policies and a guardian-only document must be selectable.');
$assert($xpath->query('//input[@name="type_slug" or @name="type_label"]')->length === 0, 'Internal type fields must not be user inputs.');
$assert($xpath->query('//input[@name="title" and @required]')->length === 0, 'Title is optional and defaults to the selected document label.');
$assert($xpath->query('//input[@name="version" and @placeholder]')->length === 1, 'Version must provide an example.');
$assert($xpath->query('//textarea[@name="content" and @data-ojp-rich-text-source]')->length === 1, 'HTML source must use the shared editor field.');
$assert($xpath->query('//*[@data-ojp-rich-text-editor]')->length === 1, 'Exactly one shared editor must render the document body.');
$assert($xpath->query('//*[@data-editor-tables="true"]')->length === 1, 'Agreement editor must enable common table and source tools.');
$assert($xpath->query('//*[@data-editor-latex="false"]')->length === 1, 'Legal documents must not inherit problem-only LaTeX tools.');

$payload = ['version' => '1.0', 'content' => '<h2>문서 목적</h2><p><font size="5">큰 글씨</font>와 <strong>중요 내용</strong></p><ul><li>첫 항목</li></ul><script>alert(1)</script><a href="javascript:alert(1)" onclick="alert(1)">링크</a><img src="x" onerror="alert(1)"><iframe src="https://example.invalid/"></iframe>'];
$expected = ['terms' => '이용약관', 'privacy' => '개인정보 처리방침', 'copyright' => '저작권 정책', 'email-collection-refusal' => '이메일 수집 거부', 'guardian-consent' => '법정대리인 동의서'];
foreach ($expected as $slug => $label) {
    $type = AgreementDocumentType::from($slug);
    $assert($type->label() === $label, 'Fixed document labels must match the public pages.');
    $response = $handler->handle(new HttpRequest('POST', '/wp-admin/admin-post.php', [], $payload + ['type_slug' => $slug, 'type_label' => '조작된 유형 이름']));
    $write = $repository->writes[array_key_last($repository->writes)];
    $assert($response->status() === 302 && $flash->last?->code() === 'auth.agreement_published', 'Each type must publish without a manually supplied label or title.');
    $assert($write['typeLabel'] === $label && $write['title'] === $label && $write['createdByUserId'] === 7, 'Server must select the label, default title and current actor.');
    $assert($write['content'] === wp_kses_post($payload['content']), 'HTML must be sanitized before storage.');
    $assert(str_contains($write['content'], '<font size="5">') && str_contains($write['content'], '<strong>중요 내용</strong>') && str_contains($write['content'], '<ul>'), 'Font size and allowed HTML formatting must survive storage.');
    $public = new LegalDocumentPageHandler($slug, $label, $pages, $repository);
    $view = $public->handle($route, $request);
    $assert($view->status() === 200 && str_contains($view->body(), '<h2>문서 목적</h2>'), 'Public document must render headings as HTML.');
    $assert(str_contains($view->body(), '<font size="5">') && str_contains($view->body(), '<strong>중요 내용</strong>'), 'Public view must preserve font and emphasis formatting.');
    foreach (['<script', 'onclick=', 'onerror=', 'javascript:', '<iframe'] as $unsafe) {
        $assert(! str_contains($view->body(), $unsafe) && ! str_contains($write['content'], $unsafe), 'Unsafe markup must be removed on save and read.');
    }
}

$repository->documents['privacy'] = new AgreementDocument(99, 'privacy', 'old', '이전 문서', $payload['content'], false);
$legacyHtml = (new LegalDocumentPageHandler('privacy', '개인정보 처리방침', $pages, $repository))->handle($route, $request)->body();
$assert(! str_contains($legacyHtml, '<script') && ! str_contains($legacyHtml, 'onerror='), 'Unsanitized older documents must also be sanitized at display time.');
$plain = "기존 일반 텍스트\n2 < 3 & 4 > 1";
$assert($richText->forStorage($plain) === $plain && $richText->render($plain) === nl2br(htmlspecialchars($plain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false), 'Existing plain-text documents must preserve line breaks and literal punctuation.');
$initialEditor = (new RichTextEditor())->render('existing', '본문', $payload['content']);
$tableHtml = '<table><tbody><tr><th scope="col" colspan="2">수집 항목</th></tr><tr><td rowspan="2">필수</td><td>이름</td></tr><tr><td>이메일</td></tr></tbody></table>';
$assert($richText->forStorage($tableHtml) === $tableHtml && $richText->render($tableHtml) === $tableHtml, 'Agreement tables and merged cells must survive storage and public rendering.');
$tableEditor = (new RichTextEditor())->render('table-content', '본문', $tableHtml, tables: true);
$assert(str_contains($tableEditor, 'data-editor-tables="true"'), 'Agreement editor must enable library table editing.');
$assert(str_contains($initialEditor, 'data-editor-tables="true"'), 'All common editors must provide table and source editing by default.');
$assert(! str_contains($initialEditor, '<script') && ! str_contains($initialEditor, '&lt;script&gt;'), 'Editor initial HTML must be sanitized before loading into the library.');

$handler->handle(new HttpRequest('POST', '/wp-admin/admin-post.php', [], $payload + ['type_slug' => 'terms', 'title' => '서비스 이용약관', 'required' => '1']));
$assert($repository->documents['terms']->title() === '서비스 이용약관' && $repository->documents['terms']->isRequired(), 'Custom title and existing required-consent semantics must remain available.');
$count = count($repository->writes);
$handler->handle(new HttpRequest('POST', '/wp-admin/admin-post.php', [], $payload + ['type_slug' => 'guardian-consent', 'required' => '1']));
$guardianDocument = $repository->documents['guardian-consent'];
$assert($guardianDocument->title() === '법정대리인 동의서' && ! $guardianDocument->isForUserConsent() && ! $guardianDocument->isRequired(), 'Guardian consent must never become a global user agreement, even when required is forged.');
$count = count($repository->writes);
foreach (['', '   ', '<p><br></p>', '<p>&nbsp;</p>', '<iframe src="https://example.invalid/"></iframe>'] as $empty) {
    $handler->handle(new HttpRequest('POST', '/wp-admin/admin-post.php', [], array_replace($payload, ['type_slug' => 'terms', 'content' => $empty])));
    $assert(count($repository->writes) === $count && $flash->last?->code() === 'auth.agreement_publish_failed', 'Empty or sanitizer-emptied HTML must not be published.');
}
foreach (['', 'unknown', 'email_collection_refusal', '<script>', ['terms']] as $invalid) {
    $handler->handle(new HttpRequest('POST', '/wp-admin/admin-post.php', [], $payload + ['type_slug' => $invalid]));
    $assert(count($repository->writes) === $count && $flash->last?->code() === 'auth.agreement_publish_failed', 'Unknown or malformed type must not reach storage.');
}
foreach (['permission', 'nonce'] as $failure) {
    $permissions->allowed = $failure !== 'permission';
    $security->valid = $failure !== 'nonce';
    $handler->handle(new HttpRequest('POST', '/wp-admin/admin-post.php', [], $payload + ['type_slug' => 'terms']));
    $assert(count($repository->writes) === $count, 'Authorization and CSRF checks must precede publication.');
}
$permissions->allowed = true;
$security->valid = true;
$agreementPreview = $admin->handle($route, $request)->body();
$assert(! str_contains($agreementPreview, '<td>terms</td>') && str_contains($agreementPreview, '<td>이용약관</td>'), 'Published table must use readable type names.');
$assert(str_contains($agreementPreview, 'href="/guardian-consent-policy"'), 'Administrators can open the published guardian document directly.');
$consentPage = new \CodingPanda\OjPlatform\Modules\Auth\Presentation\Route\UserAgreementsPageHandler($pages, $accounts, $repository, $urls, $security);
$consentBody = $consentPage->handle($route, $request)->body();
$assert(str_contains($consentBody, '<h2>문서 목적</h2>') && str_contains($consentBody, '<details open>'), 'Pending consent must show the document body, not just its title.');
$assert(str_contains($consentBody, 'agreement_document_ids[]') && str_contains($consentBody, '본문 확인'), 'Checkboxes remain associated with the displayed document.');
$assert(! str_contains($consentBody, '<script') && ! str_contains($consentBody, 'onclick='), 'Consent bodies must use the safe HTML renderer.');
$guardianId = $repository->documents['guardian-consent']->documentId();
$assert(! str_contains($consentBody, 'value="' . $guardianId . '"'), 'Public access to guardian text must not turn it into ordinary user consent.');
$repository->agreed = [$repository->documents['terms']->documentId()];
$agreedBody = $consentPage->handle($route, $request)->body();
$assert(str_contains($agreedBody, 'agreement-document-' . $repository->documents['terms']->documentId()) && str_contains($agreedBody, '<h2>문서 목적</h2>'), 'Already agreed documents must remain readable.');
$assert(! str_contains($agreedBody, 'name="agreement_document_ids[]" value="' . $repository->documents['terms']->documentId() . '"'), 'An agreed active version must not be requested again.');
$assert(str_contains($agreedBody, '선택 동의'), 'Optional documents must not be presented as mandatory pending consent.');
$routesConfig = require $pluginRoot . '/config/routes.php';
$guardianRoute = array_values(array_filter($routesConfig, static fn (array $row): bool => $row['name'] === 'legal.guardian_policy'))[0];
$assert($guardianRoute['authentication'] === 'anonymous' && $guardianRoute['feature_key'] === 'auth', 'Guardian policy must remain public when registration is disabled.');
$editable = new AgreementDocument(801, 'privacy', '7', '개인정보 "안내"', $tableHtml, false);
$repository->documents['privacy'] = $editable;
$editHtml = $admin->handle($route, new HttpRequest('GET', '/oj-console/agreements', ['edit_type' => 'privacy']))->body();
$editDom = new DOMDocument();
@$editDom->loadHTML('<?xml encoding="UTF-8">' . $editHtml);
$editXpath = new DOMXPath($editDom);
$assert($editXpath->query('//select[@name="type_slug"]/option[@selected and @value="privacy"]')->length === 1, 'Editing must select the stored document type.');
$assert($editXpath->query('//input[@name="title"]')->item(0)?->getAttribute('value') === $editable->title(), 'Stored title must be safely populated for editing.');
$assert($editXpath->query('//textarea[@name="content"]')->item(0)?->textContent === $tableHtml, 'Existing HTML tables must load into the common editor without flattening.');
$assert($editXpath->query('//input[@name="required" and @checked]')->length === 0, 'Editing optional documents must retain their consent setting.');
$assert(str_contains($editHtml, '/oj-console/agreements?edit_type=privacy#agreement-editor') && str_contains($editHtml, '현재 버전: 7'), 'Document rows must expose an edit link and show the base version.');
$assert($editXpath->query('//input[@name="version"]')->item(0)?->getAttribute('value') === '7.1', 'Editing must suggest a distinct new version.');
$unknownEdit = $admin->handle($route, new HttpRequest('GET', '/oj-console/agreements', ['edit_type' => '<script>']))->body();
$assert(! str_contains($unknownEdit, '<script>') && str_contains($unknownEdit, '새 버전 게시'), 'Unknown edit types must not be rendered as document content.');
$permissions->allowed = true;
$security->valid = true;
$handler->handle(new HttpRequest('POST', '/wp-admin/admin-post.php', [], ['type_slug' => 'privacy', 'version' => '7.1', 'title' => $editable->title(), 'content' => $tableHtml]));
$assert($repository->documents['privacy']->documentId() !== $editable->documentId() && $editable->version() === '7' && $editable->content() === $tableHtml, 'Saving an edited agreement must publish a new record rather than overwrite the consent document.');
fwrite(STDOUT, "Agreement documents: $checks checks passed.\n");

if (($argv[1] ?? '') === '--render' && isset($argv[2])) {
    $css = file_get_contents($pluginRoot . '/resources/ui/css/oj-ui-system.css');
    $js = str_replace('</script', '<\\/script', file_get_contents($pluginRoot . '/resources/ui/js/oj-ui.js'));
    file_put_contents($argv[2], '<!doctype html><html lang="ko"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>' . $css . '</style><title>동의 문서</title></head><body class="ojp-app"><div class="ojp-console-shell"><div class="ojp-console-layout"><aside class="ojp-console-sidebar">관리자 메뉴</aside><main class="ojp-console-main"><div class="ojp-shell">' . $agreementPreview . '</div></main></div></div><script>' . $js . '</script></body></html>');
}
