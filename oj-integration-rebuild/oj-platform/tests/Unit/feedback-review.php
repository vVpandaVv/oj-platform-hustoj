<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Modules\AI\Application\FeedbackReviewService;
use CodingPanda\OjPlatform\Modules\AI\Contract\FeedbackReviewRepository;
use CodingPanda\OjPlatform\Modules\AI\Contract\SubmissionFeedbackRepository;
use CodingPanda\OjPlatform\Modules\AI\Domain\SubmissionFeedback;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjection;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionReviewProvider;

require __DIR__ . '/class-management.php';
$checks = 0;
$assertReview = static function (bool $ok, string $message) use (&$checks): void {
    ++$checks;
    if (! $ok) throw new RuntimeException($message);
};
$store = new class implements FeedbackReviewRepository, SubmissionFeedbackRepository {
    public array $items = [];
    public function find(int $submissionId): ?SubmissionFeedback { return $this->items[$submissionId]['feedback'] ?? null; }
    public function save(SubmissionFeedback $feedback): void { $this->items[$feedback->submissionId()] = ['feedback' => $feedback, 'class_id' => 7]; }
    public function recent(int $limit = 100): array { return array_column(array_slice($this->items, 0, $limit), 'feedback'); }
    public function reviewBatch(int $beforeId, ?int $classId, int $limit = 100): array {
        $items = array_filter($this->items, static fn (array $row): bool => ($beforeId === 0 || $row['feedback']->submissionId() < $beforeId) && ($classId === null || $row['class_id'] === $classId));
        krsort($items);
        return array_values(array_slice($items, 0, $limit, true));
    }
};
$submissionReviews = new class implements SubmissionReviewProvider {
    public array $items = [];
    public array $denied = [];
    public function findForReview(int $actorUserId, string $actorUserLogin, int $submissionId): ?SubmissionProjection {
        return in_array($submissionId, $this->denied, true) ? null : ($this->items[$submissionId] ?? null);
    }
    public function findManyForReview(int $actorUserId, string $actorUserLogin, array $submissionIds): array {
        $result = [];
        foreach ($submissionIds as $id) {
            $row = $this->findForReview($actorUserId, $actorUserLogin, $id);
            if ($row !== null) $result[$id] = $row;
        }
        return $result;
    }
};
for ($id = 1; $id <= 125; ++$id) {
    $store->save(SubmissionFeedback::fromRow(['submission_id' => $id, 'result_code' => 6, 'content' => json_encode(['result_understanding' => '<script>alert(1)</script>', 'step_hints' => ['hint'], 'review_question' => 'why?']), 'updated_at' => '2026-10-15 10:00:00']));
    $submissionReviews->items[$id] = new SubmissionProjection($id, $id % 2 ? 1001 : 1002, $id % 2 ? 'owner' : 'manager', 6, 6, 1, 1, '', true);
}
$reviews = new FeedbackReviewService($store, $store, $submissionReviews, new LearningAccessAuthorization());
$assertReview($reviews->find(10, 'owner', 1) === null, 'AI management permission is mandatory.');
$submissionReviews->denied = [125,124];
$page = $reviews->page(1, 'admin', '', null, null, 0);
$assertReview(count($page['rows']) === 20 && $page['rows'][0]['feedback']->submissionId() === 123, 'Apply access filtering before pagination.');
$next = $reviews->page(1, 'admin', '', null, null, $page['next']);
$assertReview($next['rows'][0]['feedback']->submissionId() === 103, 'Cursor pagination must not duplicate or omit authorized rows.');
$page = $reviews->page(1, 'admin', 'owner', 7, 1001, 0);
$assertReview(count($page['rows']) === 20, 'Combine all three filters.');
foreach ($page['rows'] as $entry) $assertReview($entry['submission']->userLogin() === 'owner' && $entry['submission']->problemId() === 1001 && $entry['class_id'] === 7, 'Never leak another filter scope.');
$assertReview($reviews->page(1, 'admin', '', 999, null, 0)['rows'] === [], 'Unknown classes produce no feedback.');
$submissionReviews->denied = range(26, 125);
$assertReview($reviews->page(1, 'admin', '', null, null, 0)['rows'][0]['feedback']->submissionId() === 25, 'Scan beyond the first store batch without exposing denied rows.');
$submissionReviews->denied = [];
$handler = new \CodingPanda\OjPlatform\Modules\AI\Presentation\Console\FeedbackReviewPageHandler($classPages, $classAccounts, $reviews, $classUsers, new \CodingPanda\OjPlatform\Modules\AI\Presentation\AiHtml());
$classAccounts->actor = 1;
$route = new \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch(\CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteDefinition::fromArray(['name'=>'console.submissions.ai_feedback.records','owner_module'=>'ai_feedback','feature_key'=>'ai_feedback','path'=>'/oj-console/submissions/ai-feedback/records','area'=>'console','methods'=>['GET'],'authentication'=>'authenticated','response_type'=>'html']), []);
$response = $handler->handle($route, new \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest('GET', '/oj-console/submissions/ai-feedback/records'));
$assertReview($response->status() === 200 && str_contains($response->body(), '/oj-console/submissions/125'), 'List links directly to the admin submission.');
$assertReview(! str_contains($response->body(), '<script>') && str_contains($response->body(), '&lt;script&gt;'), 'Feedback output must be escaped.');
$assertReview(str_contains($handler->renderAdmin(1, 'admin', 1), '학습자 AI 피드백'), 'Stored feedback remains available in admin detail regardless of generation policy.');
$assertReview($handler->renderAdmin(10, 'owner', 1) === '', 'Admin panels enforce permission independently of the route.');
$bad = $handler->handle($route, new \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest('GET', '/', ['problem_id'=>'oops']));
$assertReview($bad->status() === 400, 'Malformed filters must not silently broaden the query.');
$sqlDatabase = new class {
    public string $sql = '';
    public array $values = [];
    public function prepare(string $sql, mixed ...$values): string { $this->sql = $sql; $this->values = $values; return $sql; }
    public function get_results(string $sql, string $mode): array { return [['submission_id' => 42, 'review_class_id' => 7]]; }
};
$sqlStore = new \CodingPanda\OjPlatform\Modules\AI\Infrastructure\WordPress\WordPressSubmissionFeedbackRepository(new \CodingPanda\OjPlatform\Modules\PlatformDb\Infrastructure\WordPress\WordPressApplicationDatabase($sqlDatabase));
$storedRows = $sqlStore->reviewBatch(100, 7);
$assertReview(str_contains($sqlDatabase->sql, 'LEFT JOIN submission_contexts') && str_contains($sqlDatabase->sql, 'c.class_id = %d') && str_contains($sqlDatabase->sql, 'f.submission_id < %d'), 'Class and cursor conditions belong in the parameterized platform query.');
$assertReview($storedRows[0]['class_id'] === 7 && $storedRows[0]['feedback']->submissionId() === 42, 'Hydrate the feedback and class context together.');
// The admin extension path is separate from learner generation-policy rendering.
$registry = new \CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionDetailExtensionRegistry();
$registry->registerAdmin($handler);
$assertReview(str_contains($registry->renderAdmin(1, 'admin', 1), '학습자 AI 피드백'), 'The shared submission extension registry renders the admin panel.');
$assertReview($registry->renderAdmin(10, 'owner', 1) === '', 'Registry rendering does not bypass panel authorization.');
echo "Feedback review: $checks checks passed.\n";
