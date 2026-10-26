<?php
// Test double: never connects to a database.
function dbDelta(string $sql): array { $GLOBALS["schema_calls"][] = $sql; return array(); }
