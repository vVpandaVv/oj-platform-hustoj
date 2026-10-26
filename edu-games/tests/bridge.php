<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

if (($argv[1] ?? '') === 'rules') {
    echo json_encode(edu_games_registry()->all(false), JSON_THROW_ON_ERROR);
    exit;
}
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (($argv[1] ?? '') === 'grade') {
    // Quiz games: the legacy endpoint is closed, the grader is compared directly.
    $rules = edu_games_registry()->all(false)[$input['game']]['rules'];
    echo json_encode(EduGames\Scoring\Quiz_Scorer::grade($input['game'], $rules, $input['data']), JSON_THROW_ON_ERROR);
    exit;
}
echo json_encode(EduGames\Scoring\Score_Validator::validate($input['game'], $input['data'], 7200), JSON_THROW_ON_ERROR);
