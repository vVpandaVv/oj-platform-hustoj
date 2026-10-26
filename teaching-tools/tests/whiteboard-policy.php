<?php
declare(strict_types=1);
function wp_json_encode(mixed $value): string|false { return json_encode($value, JSON_UNESCAPED_UNICODE); }
require dirname(__DIR__) . '/src/Sessions/Session_State_Policy.php';
use TeachingTools\Sessions\Session_State_Policy;
$pages = array();
for ($i = 1; $i <= 500; $i++) {
    $pages[] = array('elements' => array(), 'background' => array('type' => 'pdf', 'url' => 'https://school.test/material.pdf', 'page' => $i));
}
if (!Session_State_Policy::accepts('whiteboard', array('pages' => $pages, 'currentPage' => 499))) { throw new RuntimeException('A full 500-page document must be accepted'); }
$pages[] = $pages[0];
if (Session_State_Policy::accepts('whiteboard', array('pages' => $pages))) { throw new RuntimeException('The page limit must remain enforced'); }
echo "Whiteboard policy checks passed: 500 PDF pages accepted, overflow rejected.\n";
$ink = array('pages' => array(array('elements' => array(array('type' => 'draw', 'points' => array_fill(0, 10000, array(100, 200)))))));
if (!Session_State_Policy::accepts('whiteboard', $ink)) { throw new RuntimeException('Normal handwriting must not silently exceed the generic node budget'); }
if (Session_State_Policy::accepts('quiz', $ink)) { throw new RuntimeException('Other tools must retain their node budget'); }
$ink['overflow'] = str_repeat('x', Session_State_Policy::MAX_BYTES);
if (Session_State_Policy::accepts('whiteboard', $ink)) { throw new RuntimeException('The byte limit must remain enforced'); }
echo "Dense ink accepted within byte limit; other tool and overflow limits retained.\n";
