<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteDefinition;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccount;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemAccessPolicy;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemBookService;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemCatalogService;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemContextAccessRegistry;
use CodingPanda\OjPlatform\Modules\Problems\Application\QuizAccessPolicy;
use CodingPanda\OjPlatform\Modules\Problems\Application\QuizGrader;
use CodingPanda\OjPlatform\Modules\Problems\Application\QuizKeyFactory;
use CodingPanda\OjPlatform\Modules\Problems\Application\QuizService;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAccessContext;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemContextAccessProvider;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemNavigationContext;
use CodingPanda\OjPlatform\Modules\Problems\Domain\JudgeProblem;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemAssignment;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemVisibility;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Action\StartQuizAttemptActionHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Action\SubmitQuizAttemptActionHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\ProblemDetailPageLoader;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\QuizHtml;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\QuizRequestFactory;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Route\QuizAttemptPageHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Route\QuizDetailPageHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Route\QuizResultPageHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Route\QuizStatisticsPageHandler;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;

require_once dirname(__DIR__) . '/Unit/quizzes.php';
require_once dirname(__DIR__) . '/Unit/problem-detail-view.php';

function problemQuizEnvironment(
    bool $hidden = false,
    bool $allowed = true,
    int $userId = 2
): array {
    global $permissions, $actionUrls, $security, $errors, $view, $topics;

    $id = new ProblemId(1024);
    $problem = new JudgeProblem(
        $id, '덧셈과 출력', '두 수를 더한 결과를 출력하세요.', '두 정수 a, b가 주어집니다.',
        'a + b를 출력하세요.', '2 2', '4', '', '', 1, 128, false,
        $hidden ? ProblemVisibility::Archived : ProblemVisibility::Published,
        0, 0, '', '', '', '', null
    );
    $contexts = new ProblemContextAccessRegistry();
    $providers = [];
    foreach (['class', 'contest'] as $scope) {
        $provider = new class ($scope, $allowed) implements ProblemContextAccessProvider {
            public function __construct(private string $scope, public bool $allowed) {}
            public function scope(): string { return $this->scope; }
            public function canView(int $userId, int $problemId, ProblemAccessContext $context): bool
            {
                return $this->allowed && $userId === 2 && $problemId === 1024
                    && ($this->scope === 'class'
                        ? $context->classId() === 31
                        : $context->contestId() === 77 && $context->contestProblemNumber() === 2);
            }
        };
        $providers[] = $provider;
        $contexts->register($provider);
    }
    $assignments = new QuizAssignments(new ProblemAssignment($id, 1, []));
    $access = new ProblemAccessPolicy($permissions, $assignments, new QuizProblemAiAvailability(), $contexts);
    $catalog = new ProblemCatalogService(new QuizProblemGateway($problem), new QuizMetadata(), $assignments, $access, $permissions);
    $repository = new MemoryQuizRepository();
    $service = new QuizService($repository, $catalog, new QuizAccessPolicy($permissions, $access), new QuizGrader());
    $books = new ProblemBookService(new MemoryProblemBookRepository(), $catalog, $permissions);
    $loader = new ProblemDetailPageLoader($catalog, $access, $books, $errors, $repository);
    $accounts = new class ($userId) implements CurrentAccountProvider {
        public function __construct(public int $userId) {}
        public function current(): CurrentAccount
        {
            return new CurrentAccount(new UserIdentity($this->userId, 'learner', '', '학습자'), null);
        }
    };
    $pages = new class implements PageResponder {
        public function guest(string $title, string $content, HttpRequest $request, int $status = 200): HttpResponse
        { return HttpResponse::html($content, $status); }
        public function authenticated(string $title, string $content, HttpRequest $request, int $status = 200): HttpResponse
        { return HttpResponse::html($content, $status); }
    };
    $guard = new class ($accounts) implements ActionAccessGuard {
        public function __construct(private CurrentAccountProvider $accounts) {}
        public function verifyNonce(string $actionKey, HttpRequest $request): void {}
        public function actor(string $actionKey, HttpRequest $request, string $capability): UserIdentity
        { return $this->authenticatedActor($actionKey, $request); }
        public function authenticatedActor(string $actionKey, HttpRequest $request): UserIdentity
        { return $this->accounts->current()->identity(); }
        public function throttle(string $scope, string $identifier, int $limit, int $windowSeconds): void {}
    };
    $responses = new class implements ActionResponse {
        public ?UiMessage $message = null;
        public function redirect(string $path, UiMessage $message, int $userId = 0): HttpResponse
        {
            $this->message = $message;
            return HttpResponse::redirect($path);
        }
    };
    $problemHtml = new \CodingPanda\OjPlatform\Modules\Problems\Presentation\ProblemHtml($topics);
    $html = new QuizHtml($problemHtml);
    $keys = new QuizKeyFactory();
    $handlers = [
        'detail' => new QuizDetailPageHandler($pages, $accounts, $service, $keys, $actionUrls, $security, $problemHtml, $html),
        'attempt' => new QuizAttemptPageHandler($pages, $accounts, $service, $actionUrls, $security, $problemHtml, $html),
        'result' => new QuizResultPageHandler($pages, $accounts, $service, $problemHtml, $html),
        'statistics' => new QuizStatisticsPageHandler($pages, $accounts, $service, $html),
        'start' => new StartQuizAttemptActionHandler($service, $guard, $responses, $errors),
        'submit' => new SubmitQuizAttemptActionHandler($service, new QuizRequestFactory($keys), $guard, $responses, $errors),
    ];

    return compact('id', 'problem', 'repository', 'service', 'loader', 'view', 'handlers', 'accounts', 'responses', 'providers', 'catalog', 'access', 'books');
}

function problemQuizRoute(?int $attemptId = null): RouteMatch
{
    return new RouteMatch(RouteDefinition::fromArray([
        'name' => 'quizzes.show', 'owner_module' => 'problems', 'feature_key' => 'quizzes',
        'path' => '/quizzes/{problem_id}', 'area' => 'public', 'methods' => ['GET'],
        'authentication' => 'authenticated', 'response_type' => 'html',
    ]), ['problem_id' => '1024', 'attempt_id' => (string) ($attemptId ?? 0)]);
}

function problemQuizQuery(ProblemNavigationContext $navigation): array
{
    $query = parse_url($navigation->quizUrl(1024), PHP_URL_QUERY);
    parse_str(is_string($query) ? $query : '', $values);
    return $values;
}

function problemQuizFixtures(): array
{
    $fixtures = [];
    foreach ([
        'general' => ProblemNavigationContext::general(),
        'class' => ProblemNavigationContext::class(31),
        'contest' => ProblemNavigationContext::contest(77, 2),
    ] as $name => $navigation) {
        $env = problemQuizEnvironment($name !== 'general');
        $id = $env['id'];
        $env['service']->publish(1, $id, quizDefinition('덧셈 개념 확인'));
        $query = problemQuizQuery($navigation);
        $request = new HttpRequest('GET', '/quizzes/1024', $query);
        $state = $env['loader']->load(2, $id, $navigation->accessContext());
        $fixtures[$name] = [
            'problem' => $env['view']->render($state, [], $navigation),
            'detail' => $env['handlers']['detail']->handle(problemQuizRoute(), $request)->body(),
        ];
        $attempt = $env['service']->start(2, $id, str_repeat('a', 64), $navigation->accessContext());
        $fixtures[$name]['continue'] = $env['handlers']['detail']->handle(problemQuizRoute(), $request)->body();
        $fixtures[$name]['attempt'] = $env['handlers']['attempt']->handle(problemQuizRoute(), $request)->body();
        $revision = $env['service']->attempt(2, $id, $attempt->id(), $navigation->accessContext())['revision'];
        $question = $revision->definition()->questions()[0];
        $env['service']->submit(2, $id, $attempt->id(), [$question->id() => $question->correctChoice()->id()], $navigation->accessContext());
        $fixtures[$name]['result'] = $env['handlers']['result']->handle(problemQuizRoute($attempt->id()), $request)->body();
        $fixtures[$name]['exhausted'] = $env['handlers']['detail']->handle(problemQuizRoute(), $request)->body();
        $fixtures[$name]['statistics'] = $env['handlers']['statistics']->handle(problemQuizRoute(), $request)->body();
        $fixtures[$name]['query'] = $query;
    }
    $empty = problemQuizEnvironment();
    $fixtures['none'] = $empty['view']->render($empty['loader']->load(2, $empty['id']), []);
    return $fixtures;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    fwrite(STDOUT, 'PROBLEM_QUIZ_FIXTURES=' . json_encode(problemQuizFixtures(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
}
