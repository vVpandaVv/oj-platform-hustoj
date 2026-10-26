<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use OJML\Playgrounds\Domain\PortfolioTemplate;
use OJML\Playgrounds\Domain\PortfolioRequirements;
use OJML\Playgrounds\Domain\Problem;

$checks = 0;
function requirementCheck(bool $condition, string $message): void {
    global $checks; if (!$condition) { throw new RuntimeException($message); } $checks++;
}
$template = PortfolioTemplate::normalize(['title' => '탐구', 'markdown' => "## 문제 {#topic}\n안내\n## 성찰 {#reflection}\n안내\n"]);
$requirements = PortfolioRequirements::normalize(['requiredSectionIds' => ['topic', 'reflection'], 'minAnswerChars' => 2], $template);
requirementCheck($requirements['minAnswerChars'] === 2, 'normalize valid requirement');
requirementCheck(PortfolioRequirements::normalize(null, null) === [], 'legacy optional');
requirementCheck(PortfolioRequirements::normalize(['requiredSectionIds' => [], 'minAnswerChars' => 1], null) === [], 'none selected optional');
foreach ([['requiredSectionIds' => ['unknown']], ['requiredSectionIds' => ['topic', 'topic']], ['requiredSectionIds' => 'topic'], ['requiredSectionIds' => ['topic'], 'minAnswerChars' => 0], ['requiredSectionIds' => ['topic'], 'minAnswerChars' => 1001], ['requiredSectionIds' => ['topic'], 'minAnswerChars' => '2'], ['requiredSectionIds' => ['topic'], 'execute' => true]] as $bad) {
    try { PortfolioRequirements::normalize($bad, $template); throw new RuntimeException('invalid accepted'); }
    catch (Problem $problem) { requirementCheck($problem->problemCode() === 'OJ_ML_PORTFOLIO_REQUIREMENTS_INVALID', 'invalid rejected'); }
}
$portfolio = ['template' => ['sections' => [['id' => 'topic'], ['id' => 'reflection']]], 'answers' => ['topic' => '문제', 'reflection' => '성찰']];
requirementCheck(PortfolioRequirements::missing($portfolio, $requirements) === [], 'complete answers');
foreach (['', ' ', "\n\t", "\u{200B}\u{FEFF}", "\u{00A0}", '가', null, [], true] as $blank) {
    $copy = $portfolio; $copy['answers']['topic'] = $blank;
    requirementCheck(PortfolioRequirements::missing($copy, $requirements) === ['topic'], 'blank/short/invalid failed');
}
$copy = $portfolio; $copy['answers']['topic'] = ' 가 나 ';
requirementCheck(PortfolioRequirements::missing($copy, $requirements) === [], 'Unicode visible chars');
$copy = $portfolio; $copy['template']['sections'] = [['id' => 'reflection']];
requirementCheck(PortfolioRequirements::missing($copy, $requirements) === ['topic'], 'orphan answer not complete');
$copy = $portfolio; $copy['template']['sections'][] = ['id' => 'topic'];
requirementCheck(PortfolioRequirements::missing($copy, $requirements) === ['topic'], 'duplicate section failed');
requirementCheck(PortfolioRequirements::missing([], []) === [], 'no requirement no inference');
echo "Portfolio requirements regression passed: $checks checks.\n";
