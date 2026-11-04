<?php

declare(strict_types=1);

// Explicit CLI adapter for browser fixtures; production requests use REST.
// The no-argument invocation is intentionally inert for the PHP test sweep.
if (($argv[1] ?? '') !== '--stdin') {
    return;
}

require_once dirname(__DIR__) . '/src/Core/Autoloader.php';
\OjMicrobit\Core\Autoloader::register();
try {
    $request = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($request)) {
        throw new InvalidArgumentException('JSON object required.');
    }
    $data = match ($request['mode'] ?? 'project') {
        'exercise' => \OjMicrobit\Domain\ExerciseSchema::normalize($request['data']),
        'publicationReview' => (static function (array $input): array {
            $exercise = \OjMicrobit\Domain\ExerciseSchema::normalize(\OjMicrobit\Domain\PublicationReview::prepareInput($input));
            return \OjMicrobit\Domain\PublicationReview::describe($exercise, $input['publicationReview']['starterMode'] ?? null);
        })($request['data']),
        'publicationSave' => (static function (array $input): array {
            $exercise = \OjMicrobit\Domain\ExerciseSchema::normalize(\OjMicrobit\Domain\PublicationReview::prepareInput($input));
            \OjMicrobit\Domain\PublicationReview::requireConfirmed($exercise, $input['publicationReview'] ?? null);
            return $exercise;
        })($request['data']),
        'result' => \OjMicrobit\Domain\RunnerResultSchema::normalize($request['data']['result'], $request['data']['project'], $request['data']['exercise']),
        'learnerResult' => \OjMicrobit\Domain\ResultVisibility::learner($request['data']),
        default => \OjMicrobit\Domain\ProjectSchema::normalize($request['data']),
    };
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    echo json_encode(['ok' => false, 'message' => $error->getMessage(), 'cause' => $error->getPrevious()?->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
