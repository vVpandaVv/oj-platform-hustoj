<?php

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use OJML\Playgrounds\Domain\Problem;
use OJML\Playgrounds\Ops\Options;

$labDisplayStored = [];
$labDisplayWrites = 0;
function get_option(string $key, mixed $fallback = false): mixed {
    return $key === Options::KEY ? $GLOBALS['labDisplayStored'] : $fallback;
}
function update_option(string $key, mixed $value, bool $autoload = false): bool {
    if ($key !== Options::KEY) { throw new RuntimeException('Unexpected setting target'); }
    $GLOBALS['labDisplayStored'] = $value;
    $GLOBALS['labDisplayWrites']++;
    return true;
}
function esc_url_raw(string $value, array $protocols): string { return $value; }
$checks = 0;
function labDisplayCheck(bool $condition, string $message): void {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
}
$defaults = Options::all();
labDisplayCheck($defaults['labFocusOnStart'] === false, 'Automatic focus starts disabled');
labDisplayCheck($defaults['labGuidanceCollapsed'] === true, 'Long lab guidance starts collapsed');
labDisplayCheck($defaults['retentionHold'] === true && $defaults['allowPersonalErasure'] === false, 'Existing privacy defaults remain unchanged');

$labDisplayStored = ['maxArtifactBytes' => 10485760, 'siteQuotaBytes' => 2147483648,
    'maintenanceMode' => true, 'retentionHold' => true, 'allowPersonalErasure' => false,
    'privacyUrl' => 'https://school.example/privacy'];
$prior = Options::all();
$next = Options::save(['labFocusOnStart' => true]);
labDisplayCheck($next['labFocusOnStart'] === true && $next['labGuidanceCollapsed'] === true, 'Partial save can enable focus without resetting guidance');
$expected = $prior; $expected['labFocusOnStart'] = true;
labDisplayCheck($next === $expected && $labDisplayStored === $expected, 'Display-only save preserves upload quotas maintenance and privacy choices');

$next = Options::save(['labGuidanceCollapsed' => false]);
labDisplayCheck($next['labFocusOnStart'] === true && $next['labGuidanceCollapsed'] === false, 'Guidance setting saves independently');
$unchanged = $next;
$next = Options::save(['maxArtifactBytes' => 5242880]);
labDisplayCheck($next['labFocusOnStart'] === true && $next['labGuidanceCollapsed'] === false, 'Older clients omitting new display keys preserve saved choices');
$unchanged['maxArtifactBytes'] = 5242880;
labDisplayCheck($next === $unchanged, 'Unrelated valid save changes only requested field');
$next = Options::save(['labFocusOnStart' => false, 'labGuidanceCollapsed' => true]);
labDisplayCheck($next['labFocusOnStart'] === false && $next['labGuidanceCollapsed'] === true, 'Both display booleans can return to defaults');

foreach (['labFocusOnStart', 'labGuidanceCollapsed'] as $key) {
    foreach (['true', 'false', '1', '0', 1, 0, null, [], ['on'], new stdClass()] as $invalid) {
        $before = $labDisplayStored; $writes = $labDisplayWrites;
        try {
            Options::save([$key => $invalid, 'maintenanceMode' => false]);
            throw new RuntimeException('Invalid display value was accepted for ' . $key);
        } catch (Problem $error) {
            labDisplayCheck($error->problemCode() === 'OJ_ML_SETTING_INVALID' && $error->httpStatus() === 400, 'Invalid display type rejected with domain error');
            labDisplayCheck($before === $labDisplayStored && $writes === $labDisplayWrites, 'Rejected display value cannot partially change stored options');
        }
    }
}
$labDisplayStored['labFocusOnStart'] = 'false';
$labDisplayStored['labGuidanceCollapsed'] = 'false';
$read = Options::all();
labDisplayCheck($read['labFocusOnStart'] === false && $read['labGuidanceCollapsed'] === true, 'Malformed stored display values use safe defaults instead of PHP truthiness');
$labDisplayStored = false;
labDisplayCheck(Options::all()['labFocusOnStart'] === false && Options::all()['labGuidanceCollapsed'] === true, 'Missing or malformed options need no database migration');
echo "Lab display options regression passed ($checks checks).\n";
