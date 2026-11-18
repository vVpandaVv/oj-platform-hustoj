<?php
declare(strict_types=1);
function dbDelta(string $sql): void { $GLOBALS['ddl'][] = $sql; }
