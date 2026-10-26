<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteDefinition;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\Auth\Application\GuardianConsentRequestService;
use CodingPanda\OjPlatform\Modules\Auth\Contract\GuardianConsentNotifier;
use CodingPanda\OjPlatform\Modules\Auth\Contract\GuardianVerificationRepository;
use CodingPanda\OjPlatform\Modules\Auth\Contract\RegistrationPolicyProvider;
use CodingPanda\OjPlatform\Modules\Auth\Domain\AgreementDocument;
use CodingPanda\OjPlatform\Modules\Auth\Domain\ApprovalMode;
use CodingPanda\OjPlatform\Modules\Auth\Domain\GuardianConsentReview;
use CodingPanda\OjPlatform\Modules\Auth\Domain\GuardianRelationship;
use CodingPanda\OjPlatform\Modules\Auth\Domain\GuardianVerificationChallenge;
use CodingPanda\OjPlatform\Modules\Auth\Domain\RegistrationPolicy;
use CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressAgreementRepository;
use CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressGuardianVerificationRepository;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Action\GuardianConsentActionHandler;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionGuard;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionResponder;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Route\GuardianConsentPageHandler;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Route\RegistrationPageHandler;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;
use CodingPanda\OjPlatform\Ui\Message\FlashStore;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;

require __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/Support/wordpress-stubs.php';

$guardianChecks = 0;
$check = static function (bool $condition, string $message) use (&$guardianChecks): void {
    ++$guardianChecks;
    if (! $condition) { throw new RuntimeException($message); }
};
$documents = new AuthMemoryAgreements();
$policyProvider = new class implements RegistrationPolicyProvider {
    public bool $enabled = true;
    public function current(): RegistrationPolicy {
        return new RegistrationPolicy(true, ApprovalMode::Manual, 'subscriber', ['terms', 'privacy', 'guardian-consent'], $this->enabled, 14, 24);
    }
};
$check($policyProvider->current()->requiredAgreementTypes() === ['terms', 'privacy'], 'Guardian-only documents must not become normal registration requirements.');
$document = new AgreementDocument(33, 'guardian-consent', '1.0', '법정대리인 동의서', '<h2>수집 목적</h2><p><strong>동의 내용</strong></p><script>alert(1)</script>', false);
$guardians = new class implements GuardianVerificationRepository {
    public ?GuardianConsentReview $review = null;
    public int $issued = 0;
    public int $cancelled = 0;
    public int $confirmed = 0;
    public function issue(string $childEmail, string $guardianName, string $guardianEmail, int $expiresHours, AgreementDocument $document): GuardianVerificationChallenge {
        ++$this->issued;
        $this->review = new GuardianConsentReview($childEmail, $guardianName, $guardianEmail, $document);
        return new GuardianVerificationChallenge($this->issued, str_repeat('a', 64), '12345678', new DateTimeImmutable('+1 day'));
    }
    public function cancel(int $verificationId): void { ++$this->cancelled; $this->review = null; }
    public function pendingReview(string $token): ?GuardianConsentReview { return $token === str_repeat('a', 64) ? $this->review : null; }
    public function confirm(string $token, int $documentId, GuardianRelationship $relationship): bool {
        if ($this->pendingReview($token)?->document->documentId() !== $documentId) return false;
        ++$this->confirmed; $this->review = null; return true;
    }
    public function isVerified(string $childEmail, string $code): bool { return $this->confirmed > 0 && $code === '12345678'; }
    public function claimVerified(string $childEmail, string $code): ?int { return null; }
    public function bindClaim(int $verificationId, int $userId): void {}
    public function releaseClaim(int $verificationId): void {}
    public function hasConsumedVerification(int $userId): bool { return false; }
};
$notifier = new class implements GuardianConsentNotifier {
    public bool $fail = false;
    public int $sent = 0;
    public function send(string $childEmail, string $guardianName, string $guardianEmail, GuardianVerificationChallenge $challenge): void {
        if ($this->fail) throw new RuntimeException('mail unavailable');
        ++$this->sent;
    }
};
$service = new GuardianConsentRequestService($guardians, $notifier, $policyProvider, $documents);
$failed = false;
try { $service->request('child@example.test', '법정대리인', 'guardian@example.test'); } catch (DomainException) { $failed = true; }
$check($failed && $guardians->issued === 0 && $notifier->sent === 0, 'No document means no request or email.');
$documents->documents['guardian-consent'] = $document;
$service->request('child@example.test', '<보호자>', 'guardian@example.test');
$check($guardians->review?->document === $document && $notifier->sent === 1, 'The exact document must be attached before sending the link.');
$check(! $service->confirm(str_repeat('a', 64), 99, GuardianRelationship::Parent) && $guardians->confirmed === 0, 'A forged or stale document ID cannot confirm.');
$policyProvider->enabled = false;
$check($service->pendingReview(str_repeat('a', 64)) === null && ! $service->confirm(str_repeat('a', 64), 33, GuardianRelationship::Parent), 'Disabled guardian flow must not render or confirm.');
$policyProvider->enabled = true;
$pages = new class implements PageResponder {
    public function guest(string $title, string $content, HttpRequest $request, int $status = 200): HttpResponse { return HttpResponse::html($content, $status); }
    public function authenticated(string $title, string $content, HttpRequest $request, int $status = 200): HttpResponse { return HttpResponse::html($content, $status); }
};
$security = new class implements ActionSecurity {
    public bool $valid = true;
    public function verify(string $actionKey, HttpRequest $request): bool { return $this->valid; }
    public function hiddenFields(string $actionKey): string { return '<input type="hidden" name="action" value="' . $actionKey . '">'; }
};
$urls = new class implements ActionUrlProvider {
    public function actionUrl(): string { return '/wp-admin/admin-post.php'; }
    public function ajaxUrl(): string { return '/wp-admin/admin-ajax.php'; }
};
$definition = null;
foreach (require dirname(__DIR__, 2) . '/config/routes.php' as $item) {
    if ($item['name'] === 'auth.guardian_consent') $definition = RouteDefinition::fromArray($item);
}
$route = new RouteMatch($definition, ['token' => str_repeat('a', 64)]);
$request = new HttpRequest('GET', '/guardian-consent/' . str_repeat('a', 64));
$page = new GuardianConsentPageHandler($pages, $urls, $security, $service);
$response = $page->handle($route, $request);
$check($response->status() === 200 && str_contains($response->body(), '<h2>수집 목적</h2>') && str_contains($response->body(), '버전 1.0'), 'Guardian view must display the exact HTML document and version.');
$check(str_contains($response->body(), '&lt;보호자&gt;') && str_contains($response->body(), 'child@example.test') && ! str_contains($response->body(), '<script'), 'Guardian identity is escaped and document HTML sanitized.');
$check(str_contains($response->body(), 'name="document_id" value="33"') && str_contains($response->body(), 'name="guardian_relationship" required'), 'Form must bind the document and require a relationship.');
$check(($response->headers()['Cache-Control'] ?? '') === 'private, no-store' && ($response->headers()['Referrer-Policy'] ?? '') === 'no-referrer', 'Private consent links must not be cached or leaked as referrers.');
$guardianPreview = $response->body();
$register = new RegistrationPageHandler($pages, $documents, $policyProvider, $urls, $security);
$registration = $register->handle($route, $request)->body();
$check(! str_contains($registration, 'name="agreement_document_ids[]" value="33"'), 'Registration must not allow the child to tick the guardian document.');
$flash = new class implements FlashStore {
    public ?UiMessage $last = null;
    public function issue(UiMessage $message, int $userId = 0): string { $this->last = $message; return ''; }
    public function consume(string $token, int $userId = 0): ?UiMessage { return null; }
};
$currentAccounts = new class implements \CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider {
    public function current(): \CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccount { throw new LogicException('Guardian confirmation must not require a user login.'); }
};
$authorization = new class implements \CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization {
    public function can(int $userId, string $capability, ?\CodingPanda\OjPlatform\Modules\Permission\Contract\PermissionContext $context = null): bool { return false; }
    public function canAny(int $userId, array $capabilities): bool { return false; }
    public function isSuperAdmin(int $userId): bool { return false; }
};
$limiter = new class implements \CodingPanda\OjPlatform\Modules\Auth\Contract\AttemptLimiter {
    public bool $allowed = true;
    public function consume(string $scope, string $identifier, int $limit, int $windowSeconds): bool { return $this->allowed; }
    public function resetAll(): int { return 0; }
};
$action = new GuardianConsentActionHandler($service, new ActionGuard($security, $currentAccounts, $authorization, $limiter), new ActionResponder($flash));
$body = ['token' => str_repeat('a', 64), 'document_id' => '33', 'guardian_relationship' => 'parent', 'consent' => '1'];
foreach ([['consent' => '0'], ['guardian_relationship' => 'teacher'], ['document_id' => '34'], ['token' => 'invalid']] as $invalid) {
    $action->handle(new HttpRequest('POST', '/wp-admin/admin-post.php', [], array_replace($body, $invalid)));
    $check($guardians->confirmed === 0 && $flash->last?->code() === 'auth.guardian_consent_failed', 'Invalid consent input must not confirm.');
}
$security->valid = false;
$action->handle(new HttpRequest('POST', '/wp-admin/admin-post.php', [], $body));
$check($guardians->confirmed === 0, 'Nonce rejection must precede confirmation.');
$security->valid = true;
$action->handle(new HttpRequest('POST', '/wp-admin/admin-post.php', [], $body));
$check($guardians->confirmed === 1 && $flash->last?->code() === 'auth.guardian_consent_complete', 'Valid document-specific consent must complete.');
$action->handle(new HttpRequest('POST', '/wp-admin/admin-post.php', [], $body));
$check($guardians->confirmed === 1 && $flash->last?->code() === 'auth.guardian_consent_failed', 'A consumed link cannot confirm twice.');
$check($page->handle($route, $request)->status() === 404, 'An unavailable or completed request must not show private document context.');
$notifier->fail = true;
try { $service->request('child@example.test', '보호자', 'guardian@example.test'); } catch (RuntimeException) {}
$check($guardians->cancelled === 1 && $guardians->review === null, 'Failed email delivery must cancel its pending request.');

// Exercise production SQL separately from the service fakes, including state-change predicates.
$db = new class {
    public int $insert_id = 1;
    public array $inserted = [];
    public array $prepared = [];
    public array $rows = [];
    public ?array $row = null;
    public int $queryResult = 1;
    public int $value = 1;
    public string $lastSql = '';
    public function prepare(string $sql, mixed ...$args): string { $this->prepared = [$sql, $args]; return $sql; }
    public function insert(string $table, array $values, array $formats): int { $this->inserted = [$table, $values, $formats]; return 1; }
    public function update(string $table, array $values, array $where, array $formats, array $whereFormats): int { return 1; }
    public function query(string $sql): int { $this->lastSql = $sql; return $this->queryResult; }
    public function get_var(string $sql): int { $this->lastSql = $sql; return $this->value; }
    public function get_row(string $sql, string $format): ?array { $this->lastSql = $sql; return $this->row; }
    public function get_results(string $sql, string $format): array { $this->lastSql = $sql; return $this->rows; }
};
$sqlRepository = new WordPressGuardianVerificationRepository($db);
$challenge = $sqlRepository->issue('Child@example.test', '법정대리인', 'Guardian@example.test', 24, $document);
$stored = $db->inserted[1];
$check($stored['document_id'] === 33 && $stored['document_version'] === '1.0' && $stored['document_sha256'] === hash('sha256', $document->content()), 'Request storage must record exact document identity and content fingerprint.');
$check($stored['verification_method'] === null && $stored['guardian_relationship'] === null && $stored['verified_at'] === null, 'Unconfirmed requests must not manufacture consent evidence.');
$check($stored['token_hash'] === hash('sha256', $challenge->token()) && ! in_array($challenge->token(), $stored, true) && ! in_array($challenge->code(), $stored, true), 'Raw tokens and codes must not be stored.');
$check(count($db->inserted[1]) === count($db->inserted[2]), 'WordPress insert format count must match new columns.');
$rejected = false;
try { $sqlRepository->issue('same@example.test', '보호자', 'same@example.test', 24, $document); } catch (RuntimeException) { $rejected = true; }
$check($rejected, 'The child mailbox cannot also be the guardian mailbox.');
$check($sqlRepository->pendingReview('bad-token') === null, 'Malformed tokens must not query the database.');
$db->row = ['child_email' => 'child@example.test', 'guardian_name' => '법정대리인', 'guardian_email' => 'guardian@example.test', 'id' => 33, 'version' => '1.0', 'title' => $document->title(), 'content' => $document->content(), 'required' => 0];
$check($sqlRepository->pendingReview($challenge->token())?->document->documentId() === 33, 'Repository must hydrate the bound document.');
foreach (['g.status = %s', 'g.expires_at >= %s', 'proof.id = g.document_id', 'proof.version = g.document_version', 'SHA2(proof.content, 256) = g.document_sha256', 'proof.active = 1', 'kind.enabled = 1', "kind.slug = 'guardian-consent'"] as $clause) {
    $check(str_contains($db->lastSql, $clause), 'Review SQL must fail closed: ' . $clause);
}
$check($sqlRepository->confirm($challenge->token(), 33, GuardianRelationship::Parent), 'One matching pending row must confirm.');
$check(in_array('email_link', $db->prepared[1], true) && in_array('parent', $db->prepared[1], true) && in_array(33, $db->prepared[1], true), 'Confirmation must record method and relationship alongside the exact document.');
foreach (['document_id = %d', 'status = %s', 'expires_at >= %s', 'proof.active = 1', 'SHA2(proof.content, 256)'] as $clause) {
    $check(str_contains($db->lastSql, $clause), 'Atomic confirm must guard replay, expiry and changed document: ' . $clause);
}
$db->queryResult = 0;
$check(! $sqlRepository->confirm($challenge->token(), 33, GuardianRelationship::LegalGuardian), 'A concurrent update, expired token or stale document must report failure.');
$check($sqlRepository->claimVerified('child@example.test', '12345678') === null, 'An unavailable verification cannot be claimed.');
$check(str_contains($db->lastSql, 'proof.active = 1') && str_contains($db->lastSql, 'verified_at IS NOT NULL') && str_contains($db->lastSql, "verification_method = 'email_link'"), 'Claim must require recorded evidence and current document; old rows cannot bypass.');
$db->queryResult = 1;
$sqlRepository->bindClaim(1, 7);
$check(str_contains($db->lastSql, 'user_id IS NULL') && str_contains($db->lastSql, 'expires_at >= %s') && str_contains($db->lastSql, 'proof.active = 1'), 'Account binding must recheck expiry, document and single-use state.');
$sqlRepository->hasConsumedVerification(7);
$check(str_contains($db->lastSql, 'document_sha256') && str_contains($db->lastSql, 'verified_at IS NOT NULL') && ! str_contains($db->lastSql, 'proof.active = 1'), 'Completed evidence must remain tied to its historical document without requiring the newest version.');
$db->value = 0;
$check(! $sqlRepository->hasConsumedVerification(7), 'Legacy rows without document evidence must not be treated as new verified consent.');
$db->rows = [['id' => 33, 'slug' => 'guardian-consent', 'version' => '1.0', 'title' => '법정대리인 동의서', 'content' => '본문', 'required' => 0]];
$rejected = false;
try { (new WordPressAgreementRepository($db))->recordAgreement(7, [33], null, null); } catch (RuntimeException) { $rejected = true; }
$check($rejected, 'User agreement POST must reject guardian-only document IDs at the storage boundary.');

fwrite(STDOUT, "Guardian consent: $guardianChecks checks passed.\n");
if (($argv[1] ?? '') === '--render' && isset($argv[2])) {
    $css = file_get_contents(dirname(__DIR__, 2) . '/resources/ui/css/oj-ui-system.css');
    file_put_contents($argv[2], '<!doctype html><html lang="ko"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>법정대리인 동의</title><style>' . $css . '</style></head><body class="ojp-app"><main class="ojp-shell">' . $guardianPreview . '</main></body></html>');
}
