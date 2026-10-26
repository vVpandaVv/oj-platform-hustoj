<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use EduGames\Core\Legacy_Ranking_Page_Cleanup as Cleanup;
use EduGames\Frontend\Game_Access_Guard as Guard;
use EduGames\Games\Modules\AI_Classifier_Lab\AI_Classifier_Lab_Module as Classifier;
use EduGames\Rest\Controllers\AI_Model_Lab_Controller as AI_Controller;
use EduGames\Rest\Controllers\Games_Controller as Controller;
use EduGames\Scoring\Quiz_Scorer;
use EduGames\Scoring\Score_Validator as Validator;
use EduGames\Sessions\Game_Session_Service as Sessions;

$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    $checks++;
    if (! $condition) { throw new RuntimeException($message); }
}
function payload(array $overrides = array()): array {
    return array_replace(array('score' => 100, 'roundsPlayed' => 1, 'correctCount' => 1, 'durationSeconds' => 60, 'accuracy' => 100, 'sessionToken' => 'valid-token', 'meta' => array()), $overrides);
}

$definitions = edu_games_registry()->all(false);
check(count($definitions) === 13, 'Expected 13 registered games');
$balance = array();
foreach (Classifier::rules()['samples'] as $sample) {
    $fits = array();
    foreach ($sample['rules'] as $rule) {
        $fits[$rule['id']] = count(array_filter($sample['points'], static fn($point) => Classifier::predict($rule, $point) === $point['label']));
        if ($rule['id'] === $sample['answerRule']) {
            check(Classifier::predict($rule, $sample['query']) === $sample['answerLabel'], $sample['id'] . ': query label');
        }
        check($rule['comparison'] === (str_contains($rule['label'], '이하') ? 'lte' : 'gte'), $sample['id'] . ': comparison text');
    }
    check($fits[$sample['answerRule']] === count($sample['points']), $sample['id'] . ': training labels');
    check(count(array_filter($fits, static fn($fit) => $fit === max($fits))) === 1, $sample['id'] . ': ambiguous best rule');
    $balance[$sample['difficulty']][$sample['answerLabel']] = ($balance[$sample['difficulty']][$sample['answerLabel']] ?? 0) + 1;
}
foreach ($balance as $counts) { check($counts['A'] === 5 && $counts['B'] === 5, 'Balanced labels per difficulty'); }

$block = array('blockName' => 'edu-games/group-leaderboard', 'attrs' => array(), 'innerHTML' => '', 'innerBlocks' => array());
$reflection = new ReflectionMethod(Cleanup::class, 'looks_auto_generated');
$post = new WP_Post();
foreach (array(
    array(array($block), true),
    array(array(), false),
    array(array($block, array('blockName' => null, 'innerHTML' => '<p>Teacher lesson</p>')), false),
    array(array(array_replace($block, array('attrs' => array('limit' => 5)))), false),
    array(array(array_replace($block, array('innerHTML' => 'Edited content'))), false),
) as [$parsed_blocks, $expected]) {
    check($reflection->invoke(null, $post) === $expected, 'Cleanup must preserve edited content');
}
$parsed_blocks = array($block);
$post->post_type = 'post';
check(! $reflection->invoke(null, $post), 'Only pages may be retired');
$post->post_type = 'page';
$options = array('edu_games_ranking_page_id' => 42);
$trashed = array();
Cleanup::maybe_remove_legacy_page();
check($trashed === (EMPTY_TRASH_DAYS > 0 ? array(42) : array()), 'Trash only when enabled');
Cleanup::maybe_remove_legacy_page();
check(count($trashed) <= 1, 'Cleanup is idempotent');

foreach ($definitions as $id => $definition) {
    $options = array('edu_games_closed_games' => array($id));
    check(is_string(Guard::pre_render_block(null, array('blockName' => $definition['block']))), $id . ': closed block');
    foreach (array($definition['shortcode'], 'oj_' . $definition['shortcode']) as $tag) {
        check(is_string(Guard::pre_do_shortcode_tag(false, $tag, array(), array())), $id . ': closed shortcode');
    }
    check(is_wp_error(Validator::client_game($id)), $id . ': closed score endpoint');
}
check(Guard::pre_render_block(null, array('blockName' => 'edu-games/game-hub')) === null, 'Hub is not a game');
$options = array('edu_games_closed_games' => array('ai-model-lab', 'binary-conversion'));
check(! AI_Controller::can_play(), 'AI endpoints respect visibility');
$caps = array('edu_games_play', 'manage_options');
check(Guard::pre_render_block(null, array('blockName' => 'edu-games/binary-arcade')) === null, 'Administrator may preview');
check(! AI_Controller::can_play(), 'Preview does not permit closed-game submissions');
$options = array();
check(AI_Controller::can_play(), 'Open AI endpoint works');
check(is_wp_error(Validator::client_game('ai-model-lab')), 'Generic endpoint cannot forge server-computed AI results');
check(is_wp_error(Validator::client_game('unknown')), 'Unknown game rejected');
$caps = array();
check(! AI_Controller::can_play(), 'AI still requires play capability');
$caps = array('edu_games_play');

$quiz_payloads = array();
foreach (Quiz_Scorer::GAMES as $game) {
    $rules = $definitions[$game]['rules'];
    $samples = array_column($rules['samples'], null, 'id');
    foreach (array('easy', 'normal', 'hard') as $difficulty) {
        $history = $seen = array();
        $expected_score = 0;
        for ($i = 0; $i < $rules['rankedRounds']; $i++) {
            $early = in_array($game, array('outlier-detection', 'data-extraction'), true);
            $tier = $i < ($early ? 2 : 3) ? 'easy' : ($i < ($early ? 5 : 7) ? $difficulty : 'hard');
            $pool = $rules['difficulties'][$tier]['samples'];
            $fresh = array_values(array_diff($pool, $seen));
            $s = $samples[($fresh ?: $pool)[0]];
            $seen[] = $s['id'];
            $entry = array('sampleId' => $s['id'], 'correct' => false, 'gained' => 999999);
            $entry += match ($game) {
                'outlier-detection' => array('selected' => $s['outlierIndexes']),
                'data-structure-lab' => array('selected' => $s['answer']),
                'data-visualization' => array('chart' => $s['chartType'], 'insight' => $s['insight']),
                'ai-classifier-lab' => array('selectedRule' => $s['answerRule'], 'selectedLabel' => $s['answerLabel']),
                'missing-values' => array('method' => $s['method'], 'answer' => $s['answers']),
                'data-extraction' => array('selectedColumns' => $s['relevantColumns'], 'selectedProcessing' => array_values(array_filter($s['processingOptions'], static fn($o) => $o['correct']))[0]['key']),
            };
            $history[] = $entry;
            $expected_score += $rules['difficulties'][$tier]['baseScore'] + min($rules['scoring']['maxStreakBonus'], $i * $rules['scoring']['streakBonus'])
                + ($game === 'missing-values' ? max(0, count($s['missingIndexes']) - 1) * $rules['scoring']['multiBlankBonus'] : 0);
        }
        $data = payload(array('score' => 999999, 'meta' => array('difficulty' => $difficulty, 'history' => $history)));
        // The legacy /scores endpoint is closed for quiz games; the grader
        // itself is still checked directly.
        check(is_wp_error(Validator::validate($game, $data, 600)), $game . ': legacy score endpoint closed');
        $result = Quiz_Scorer::grade($game, $definitions[$game]['rules'], $data);
        check(! is_wp_error($result), $game . ': normal play accepted');
        check($result['score'] === $expected_score && $result['correctCount'] === count($history) && $result['accuracy'] === 100.0, $game . ': server-calculated result');
        $quiz_payloads[$game] = $data;
        $data['meta']['history'][0] = array('sampleId' => $history[0]['sampleId'], 'correct' => true, 'answer' => 'forged');
        $wrong = Quiz_Scorer::grade($game, $definitions[$game]['rules'], $data);
        check(! is_wp_error($wrong) && $wrong['correctCount'] === count($history) - 1 && $wrong['score'] < $result['score'], $game . ': client correct flag ignored');
        $data['meta']['history'][0]['sampleId'] = 'not-a-sample';
        check(is_wp_error(Quiz_Scorer::grade($game, $definitions[$game]['rules'], $data)), $game . ': unknown sample rejected');
        array_pop($data['meta']['history']);
        check(is_wp_error(Quiz_Scorer::grade($game, $definitions[$game]['rules'], $data)), $game . ': truncated history rejected');
    }
}

$binary = payload(array('score' => 90, 'meta' => array('maxBits' => 8)));
check(! is_wp_error(Validator::validate('binary-conversion', $binary, 600)), 'Valid binary result');
foreach (array('score' => -1, 'roundsPlayed' => 0, 'correctCount' => 2, 'durationSeconds' => 603, 'accuracy' => INF, 'meta' => 'bad') as $key => $value) {
    check(is_wp_error(Validator::validate('binary-conversion', array_replace($binary, array($key => $value)), 600)), 'Invalid ' . $key);
}
check(is_wp_error(Validator::validate('binary-conversion', array_replace($binary, array('score' => 999999)), 600)), 'Binary score upper bound');
check(is_wp_error(Validator::validate('binary-conversion', array_replace($binary, array('score' => array())), 600)), 'Malformed score rejected');
$result = Validator::validate('binary-conversion', array_replace($binary, array('accuracy' => 0)), 600);
check($result['accuracy'] === 100.0, 'Accuracy is recomputed');
// Pace: a run may not claim more answers than the game could produce in its time.
check(is_wp_error(Validator::validate('binary-conversion', payload(array('score' => 90, 'roundsPlayed' => 200, 'correctCount' => 200, 'durationSeconds' => 60, 'meta' => array('maxBits' => 8))), 600)), 'Binary: 200 answers in 60 s rejected');
check(! is_wp_error(Validator::validate('binary-conversion', payload(array('score' => 90, 'roundsPlayed' => 30, 'correctCount' => 30, 'durationSeconds' => 60, 'meta' => array('maxBits' => 8))), 600)), 'Binary: fastest real pace (30 in 60 s) accepted');
$long_binary = payload(array('roundsPlayed' => 1000, 'correctCount' => 1000, 'durationSeconds' => 2700, 'meta' => array('maxBits' => 8, 'originalScore' => 1, 'scoreCapped' => true)));
foreach (array(999998, 999999, 1000000, 1000001, 1200000) as $score) {
    $result = Validator::validate('binary-conversion', array_replace($long_binary, array('score' => $score)), 2700);
    check(!is_wp_error($result) && $result['score'] === min(999999, $score), 'Binary score boundary accepted: '.$score);
    if ($score > 999999) {
        check($result['meta']['originalScore'] === $score && $result['meta']['scoreCapped'] === true, 'Capped score retains validated original, not client metadata');
    } else {
        check(!array_key_exists('originalScore', $result['meta']) && !array_key_exists('scoreCapped', $result['meta']), 'Uncapped score removes forged cap metadata');
    }
}
foreach (array(-1, 1000000.5, '1000000', true, INF, NAN, 9007199254740992, 2000000) as $score) {
    check(is_wp_error(Validator::validate('binary-conversion', array_replace($long_binary, array('score' => $score)), 2700)), 'Malformed or impossible score cannot become a capped high score');
}
$over_limit_request = new WP_REST_Request('binary-conversion', array_replace($long_binary, array('score' => 1000001)));
$wpdb = new Fake_DB();
$wpdb->session_age = 3000; // 1000 numbers take ~45 minutes of real play
$saved = Controller::record_score($over_limit_request);
check(!is_wp_error($saved) && $saved['score']['score'] === 999999 && $wpdb->score_inserts === 1, 'Over-limit result is stored and returned at cap');
check($wpdb->last_score['score'] === 999999 && json_decode($wpdb->last_score['meta'], true)['originalScore'] === 1000001, 'Database receives capped ranking score and original score');
check(is_wp_error(Controller::record_score($over_limit_request)) && $wpdb->score_inserts === 1, 'Capped result replay is not duplicated');
$wpdb = new Fake_DB();
check(is_wp_error(Controller::record_score(new WP_REST_Request('binary-conversion', array_replace($binary, array('score' => 1000000))))) && !$wpdb->consumed && $wpdb->score_inserts === 0, 'Impossible raw score is rejected before capping or claiming');
$wpdb = new Fake_DB();$wpdb->fail_insert = true;$wpdb->session_age = 3000;
check(is_wp_error(Controller::record_score($over_limit_request)) && !$wpdb->consumed, 'Failed capped insert releases claim');
$wpdb->fail_insert = false;
check(!is_wp_error(Controller::record_score($over_limit_request)) && $wpdb->last_score['score'] === 999999, 'Capped insert failure can retry');
foreach (array('character-code' => array('difficulty' => 'easy', 'mistakes' => 1), 'keyword-rain' => array('difficulty' => 'easy', 'language' => 'python'), 'device-matching' => array()) as $game => $meta) {
    check(! is_wp_error(Validator::validate($game, payload(array('score' => 90, 'meta' => $meta)), 600)), $game . ': valid real-time result');
    check(is_wp_error(Validator::validate($game, payload(array('score' => 999999, 'meta' => $meta)), 600)), $game . ': impossible score');
}
$typing_sample = array_values(array_filter($definitions['code-typing']['rules']['samples'], static fn($s) => $s['mode'] === 'assessment'))[0];
$typing = payload(array('score' => 999999, 'correctCount' => 0, 'durationSeconds' => 300, 'meta' => array('mode' => 'assessment', 'language' => $typing_sample['language'], 'sampleId' => $typing_sample['id'], 'samplesCompleted' => 0, 'typedCharacters' => 100, 'mistakes' => 10)));
$result = Validator::validate('code-typing', $typing, 600);
check(! is_wp_error($result) && $result['score'] === 694 && $result['accuracy'] === 90.0 && $result['meta']['cpm'] === 18.0, 'Typing score and speed recalculated');
$typing['meta']['mode'] = 'short';
check(is_wp_error(Validator::validate('code-typing', $typing, 600)), 'Practice cannot submit ranked typing results');

$wpdb = new Fake_DB();
$request = new WP_REST_Request('binary-conversion', $binary);
$concurrent = null;
$wpdb->on_insert = static function () use ($request, &$concurrent): void {
    global $wpdb;
    $wpdb->stale_read = true;
    $concurrent = Controller::record_score($request);
    $wpdb->stale_read = false;
};
check(! is_wp_error(Controller::record_score($request)), 'Score save succeeds');
check(is_wp_error($concurrent) && $concurrent->code === 'edu_games_session_already_used' && $wpdb->score_inserts === 1, 'Stale concurrent reader cannot insert twice');
check(is_wp_error(Controller::record_score($request)) && $wpdb->score_inserts === 1, 'Replay rejected');
$wpdb = new Fake_DB();
$wpdb->fail_insert = true;
check(is_wp_error(Controller::record_score($request)) && ! $wpdb->consumed, 'Insert failure releases claim');
$wpdb->fail_insert = false;
check(! is_wp_error(Controller::record_score($request)) && $wpdb->score_inserts === 1, 'Failed save can retry');
$wpdb = new Fake_DB();
check(is_wp_error(Controller::record_score(new WP_REST_Request('binary-conversion', array_replace($binary, array('score' => -2))))) && ! $wpdb->consumed, 'Invalid payload does not consume session');
$options = array('edu_games_closed_games' => array('binary-conversion'));
check(is_wp_error(Controller::start_session(new WP_REST_Request('binary-conversion'))), 'Closed game cannot mint REST session');
$options = array();
$wpdb->fail_insert = true;
check(Sessions::start(7, 'binary-conversion') === '', 'Session insert failure is reported');

echo "PASS: {$checks} regression checks (WordPress/DB doubles).\n";
