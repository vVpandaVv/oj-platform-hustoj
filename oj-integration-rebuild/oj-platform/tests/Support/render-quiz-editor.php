<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\Problems\Application\QuizKeyFactory;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizBankQuestion;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizDefinition;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizResultPolicy;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizRevision;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Console\QuizEditForm;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\ProblemHtml;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\QuizRequestFactory;

require_once dirname(__DIR__, 2) . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register(dirname(__DIR__, 2));

if (in_array($argv[1] ?? '', ['--validate-post', '--validate-bank-post'], true)) {
    parse_str((string) stream_get_contents(STDIN), $body);
    $factory = new QuizRequestFactory(new QuizKeyFactory());
    if ($argv[1] === '--validate-bank-post') {
        $question = $factory->bankQuestion(new HttpRequest('POST', '/quiz-action', [], $body));
        echo json_encode(['type' => $question->type(), 'title' => $question->title()], JSON_THROW_ON_ERROR);
        exit;
    }
    $definition = $factory->definition(new HttpRequest('POST', '/quiz-action', [], $body));
    echo json_encode([
        'title' => $definition->title(),
        'types' => array_map(static fn ($question): string => $question->type(), $definition->questions()),
        'max_score' => $definition->maxScore(),
    ], JSON_THROW_ON_ERROR);
    exit;
}

require dirname(__DIR__) . '/Unit/quizzes.php';
$quizEditorForm = new QuizEditForm(
    new class implements ActionUrlProvider {
        public function actionUrl(): string { return '/quiz-action'; }
        public function ajaxUrl(): string { return '/quiz-ajax'; }
    },
    new class implements ActionSecurity {
        public function hiddenFields(string $actionKey): string {
            return '<input type="hidden" name="action" value="' . $actionKey . '">'
                . '<input type="hidden" name="_oj_nonce" value="nonce-' . $actionKey . '">';
        }
        public function verify(string $actionKey, HttpRequest $request): bool { return true; }
    },
    new ProblemHtml(new class implements \CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemTopicDictionary {
        public function all(): array { return []; }
        public function find(string $key): ?\CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemTopicRecord { return null; }
        public function assertSelectableDetails(array $detailKeys): void {}
        public function version(): string { return 'fixture'; }
        public function createCategory(string $key, string $label, array $aliases, int $actorUserId): void {}
        public function createDetail(string $categoryKey, string $key, string $label, array $aliases, int $actorUserId): void {}
    }),
    new QuizKeyFactory()
);
$editorDefinition = new QuizDefinition('편집 테스트', '설명', 1, QuizResultPolicy::Full, null, false, true, true, [$bankQuestion]);
$editorDraft = new QuizRevision(901, $problem->id(), 0, 'draft', false, $editorDefinition, $editorDefinition->contentHash(), 1, null, null);
$editorPublished = new QuizRevision(902, $problem->id(), 1, 'published', true, $editorDefinition, $editorDefinition->contentHash(), 1, 1, '2026-10-27 00:00:00');
$editorBankItems = [new QuizBankQuestion(5, $bankQuestion, 1, '2026-10-27 00:00:00')];
$quizEditorFixtures = [
    'new_empty_bank' => $quizEditorForm->render($problem, null, null, false, 'oj_quiz_create'),
    'new_with_bank' => $quizEditorForm->render($problem, null, null, false, 'oj_quiz_create', $editorBankItems),
    'edit' => $quizEditorForm->render($problem, $editorDraft, $editorPublished, true, 'oj_quiz_save', $editorBankItems),
    'bank' => $quizEditorForm->renderBankItem(null, 'oj_quiz_bank_save', null),
];
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    fwrite(STDOUT, 'QUIZ_EDITOR_FIXTURES=' . json_encode($quizEditorFixtures, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
}
