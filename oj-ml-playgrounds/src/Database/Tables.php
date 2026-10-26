<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Database;

use wpdb;

final class Tables
{
    public readonly string $assignments;
    public readonly string $assignmentTargets;
    public readonly string $assignmentLearners;
    public readonly string $attempts;
    public readonly string $artifacts;
    public readonly string $evaluations;
    public readonly string $reviews;
    public readonly string $events;

    public function __construct(wpdb $wpdb)
    {
        // Add-on-owned physical tables are intentionally independent of the
        // configurable WordPress prefix and of platform-owned schemas.
        $this->assignments = 'oj_ml_assignments';
        $this->assignmentTargets = 'oj_ml_assignment_targets';
        $this->assignmentLearners = 'oj_ml_assignment_learners';
        $this->attempts = 'oj_ml_attempts';
        $this->artifacts = 'oj_ml_artifacts';
        $this->evaluations = 'oj_ml_evaluations';
        $this->reviews = 'oj_ml_reviews';
        $this->events = 'oj_ml_events';
    }
}
