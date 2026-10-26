<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Rest;

use OJML\Playgrounds\Http\ArtifactDownload;
use OJML\Playgrounds\Domain\Rubric;
use OJML\Playgrounds\Security\Addon_Capabilities;

final class Presenter
{
    /** @param array<string,mixed> $assignment @return array<string,mixed> */
    public function assignment(array $assignment, bool $teacher = false): array
    {
        $result = [
            'id' => (int) $assignment['id'],
            'title' => (string) $assignment['title'],
            'description' => (string) $assignment['description'],
            'taskType' => (string) $assignment['task_type'],
            'status' => (string) $assignment['status'],
            'opensAt' => $this->iso($assignment['opens_at'] ?? null),
            'dueAt' => $this->iso($assignment['due_at'] ?? null),
            'maxAttempts' => (int) $assignment['max_attempts'],
            'revision' => (int) $assignment['revision'],
            'createdAt' => $this->iso($assignment['created_at'] ?? null),
            'updatedAt' => $this->iso($assignment['updated_at'] ?? null),
            'publishedAt' => $this->iso($assignment['published_at'] ?? null),
        ];
        if (array_key_exists('student_status', $assignment)) {
            $result['studentStatus'] = (string) $assignment['student_status'];
        }
        if (array_key_exists('attempts_used', $assignment)) {
            $result['attemptsUsed'] = (int) $assignment['attempts_used'];
        }
        if (array_key_exists('active_attempt', $assignment)) {
            $result['activeAttempt'] = is_array($assignment['active_attempt'])
                ? $this->attempt($assignment['active_attempt'])
                : null;
        }
        if (array_key_exists('latest_attempt', $assignment)) {
            $result['latestAttempt'] = is_array($assignment['latest_attempt'])
                ? $this->attempt($assignment['latest_attempt'])
                : null;
        }
        if ($teacher) {
            $result += [
                'contextType' => (string) $assignment['context_type'],
                'contextId' => (string) $assignment['context_id'],
                'contextRevision' => (string) ($assignment['context_revision'] ?? ''),
                'rosterRevision' => (string) ($assignment['roster_revision'] ?? ''),
                'policy' => is_array($assignment['policy'] ?? null) ? $assignment['policy'] : [],
                'rubric' => is_array($assignment['rubric'] ?? null) ? $assignment['rubric'] : [],
                'targets' => is_array($assignment['targets'] ?? null) ? $assignment['targets'] : [],
                'learners' => is_array($assignment['learners'] ?? null) ? array_map('intval', $assignment['learners']) : [],
                'createdBy' => (int) $assignment['created_by'],
            ];
        } else {
            $rubric = $this->rubric($assignment['rubric'] ?? null);
            if ($rubric !== []) { $result['rubric'] = $rubric; }
            $policy = is_array($assignment['policy'] ?? null) ? $assignment['policy'] : [];
            $policy['maxArtifactBytes'] = min((int) ($policy['maxArtifactBytes'] ?? 104857600),
                (int) \OJML\Playgrounds\Ops\Options::all()['maxArtifactBytes']);
            $result['submissionPolicy'] = array_intersect_key($policy, array_flip([
                'allowedFormatVersions',
                'allowedDataTypes',
                'allowedTaskTypes',
                'allowedAlgorithms',
                'maxArtifactBytes',
                'maxRows',
                'maxFeatures',
                'allowLateSubmission',
                'requireIntegrityManifest',
                'portfolioTemplate',
                'portfolioRequirements',
                'datasetDescription',
                'datasetDescriptionSectionId',
                'requiredDatasetSha256',
                'requiredDatasetPath',
            ]));
            if (is_array($policy['datasetResource'] ?? null)) {
                $result['submissionPolicy']['datasetResource'] = array_intersect_key(
                    $policy['datasetResource'], array_flip(['id', 'name', 'sha256', 'bytes', 'rowCount', 'columnCount', 'createdAt', 'path'])
                );
            }
        }
        return $result;
    }

    /** @param array<string,mixed> $assignment @return array<string,mixed> */
    public function learnerAssignmentListItem(array $assignment): array
    {
        $summary = is_array($assignment['latest_attempt_summary'] ?? null)
            ? $assignment['latest_attempt_summary']
            : null;
        unset(
            $assignment['attempts_used'],
            $assignment['active_attempt'],
            $assignment['latest_attempt'],
            $assignment['latest_attempt_summary']
        );
        $result = $this->assignment($assignment);
        $result['latestAttempt'] = $summary !== null && is_string($summary['uuid'] ?? null)
            ? [
                'id' => $summary['uuid'],
                'receivedAt' => $this->iso($summary['received_at'] ?? null),
            ]
            : null;
        return $result;
    }
    /** @param array<string,mixed> $assignment @return array{id:int,title:string,status:string} */
    public function assignmentSummary(array $assignment): array
    {
        return [
            'id' => (int) $assignment['id'],
            'title' => (string) $assignment['title'],
            'status' => (string) $assignment['status'],
        ];
    }


    /** @param array<string,mixed> $attempt @return array<string,mixed> */
    public function attempt(array $attempt, bool $teacher = false): array
    {
        $mayEvaluate = $teacher
            && ($attempt['context_may_evaluate'] ?? false) === true
            && ArtifactDownload::currentActorMayDownload();
        $result = [
            'id' => (string) $attempt['uuid'],
            'assignmentId' => (int) $attempt['assignment_id'],
            'attemptNo' => (int) $attempt['attempt_no'],
            'status' => (string) $attempt['status'],
            'policyRevision' => (int) $attempt['policy_revision'],
            'clientAppVersion' => $attempt['client_app_version'] ?? null,
            'formatVersion' => isset($attempt['format_version']) ? (int) $attempt['format_version'] : null,
            'progress' => is_array($attempt['progress'] ?? null) ? $attempt['progress'] : null,
            'failure' => $this->failure($attempt['failure'] ?? null),
            'late' => (bool) ($attempt['late'] ?? false),
            'receivedAt' => $this->iso($attempt['received_at'] ?? null),
            'createdAt' => $this->iso($attempt['created_at'] ?? null),
            'updatedAt' => $this->iso($attempt['updated_at'] ?? null),
        ];
        if ($teacher) {
            $result['canEvaluate'] = $mayEvaluate;
            $manageCapability = Addon_Capabilities::wordpress(Addon_Capabilities::MANAGE);
            $result['canReconsider'] = $mayEvaluate && $manageCapability !== ''
                && current_user_can($manageCapability) && ($attempt['status'] ?? '') === 'needs_resubmit'
                && is_array($attempt['artifact'] ?? null) && (int) ($attempt['evaluation']['id'] ?? 0) > 0;
            if ($mayEvaluate && array_key_exists('rubric', $attempt)) {
                $result['rubric'] = $this->rubric($attempt['rubric']);
            }
            if (isset($attempt['user_id'])) {
                $result['userId'] = (int) $attempt['user_id'];
            }
        }
        if (array_key_exists('artifact', $attempt)) {
            $result['artifact'] = is_array($attempt['artifact'])
                ? $this->artifact($attempt['artifact'])
                : null;
            if ($teacher && !$mayEvaluate && is_array($result['artifact'])) {
                unset($result['artifact']['originalName']);
            }
            if ($mayEvaluate && is_array($result['artifact'])) {
                $download = ArtifactDownload::form((string) $attempt['uuid'], (string) $attempt['status']);
                if ($download !== null) {
                    $result['artifact']['download'] = $download;
                }
            }
        }
        if (array_key_exists('evaluation', $attempt)) {
            $result['evaluation'] = is_array($attempt['evaluation']) ? $this->evaluation($attempt['evaluation']) : null;
        }
        if ($teacher && (array_key_exists('review', $attempt) || array_key_exists('released_review', $attempt))) {
            $review = $mayEvaluate ? ($attempt['review'] ?? null) : ($attempt['released_review'] ?? null);
            $result['review'] = is_array($review)
                ? ($mayEvaluate ? $this->review($review, true) : $this->reviewSummary($review))
                : null;
        } elseif (!$teacher && array_key_exists('review', $attempt)) {
            $result['review'] = is_array($attempt['review']) && ($attempt['review']['status'] ?? null) === 'released'
                ? $this->review($attempt['review'])
                : null;
        }
        return $result;
    }
    /** @param array{user_id:int,attempts_used:int,attempt:?array} $row @return array<string,mixed> */
    public function resultRow(array $row): array
    {
        $userId = (int) $row['user_id'];
        $user = function_exists('get_userdata') ? get_userdata($userId) : false;
        $displayName = is_object($user) && is_string($user->display_name ?? null)
            ? sanitize_text_field($user->display_name)
            : '';
        $contextMayEvaluate = ($row['context_may_evaluate'] ?? false) === true;
        $canEvaluate = $contextMayEvaluate && ArtifactDownload::currentActorMayDownload();
        $attempt = is_array($row['attempt']) ? $row['attempt'] : null;
        if ($attempt !== null) {
            $attempt['context_may_evaluate'] = $contextMayEvaluate;
        }
        return [
            'userId' => $userId,
            'displayName' => $displayName !== '' ? $displayName : '사용자 #' . $userId,
            'attemptsUsed' => (int) $row['attempts_used'],
            'allowance' => [
                'maxAttempts' => $row['allowance']['maxAttempts'] ?? null,
                'dueAt' => $this->iso($row['allowance']['dueAt'] ?? null),
                'revision' => (int) ($row['allowance']['revision'] ?? 0),
            ],
            'canEvaluate' => $canEvaluate,
            'attempt' => $attempt !== null ? $this->attempt($attempt, true) : null,
        ];
    }
    /** @param array<string,mixed> $review @return array<string,mixed> */
    public function review(array $review, bool $teacher = false): array
    {
        $result = [
            'version' => (int) $review['version'],
            'status' => (string) $review['status'],
            'score' => isset($review['score']) ? (float) $review['score'] : null,
            'maxScore' => isset($review['max_score']) ? (float) $review['max_score'] : null,
            'feedback' => (string) ($review['feedback'] ?? ''),
            'rubricRevision' => (int) $review['rubric_revision'],
            'createdAt' => $this->iso($review['created_at'] ?? null),
            'releasedAt' => $this->iso($review['released_at'] ?? null),
        ];
        if ($teacher) {
            $result['reviewerId'] = (int) $review['reviewer_id'];
        }
        $result['details'] = $teacher || ($review['status'] ?? null) === 'released'
            ? Rubric::details($review['details'] ?? null) : null;
        return $result;
    }

    /** @param array<string,mixed> $review @return array<string,mixed>|null */
    private function reviewSummary(array $review): ?array
    {
        if (($review['status'] ?? null) !== 'released') {
            return null;
        }
        return [
            'version' => (int) $review['version'],
            'status' => (string) $review['status'],
            'score' => isset($review['score']) ? (float) $review['score'] : null,
            'maxScore' => isset($review['max_score']) ? (float) $review['max_score'] : null,
            'feedback' => '',
            'rubricRevision' => (int) $review['rubric_revision'],
            'createdAt' => null,
            'releasedAt' => $this->iso($review['released_at'] ?? null),
        ];
    }

    /** @return null|array{code:string} */
    private function failure(mixed $failure): ?array
    {
        if (!is_array($failure) || !is_string($failure['code'] ?? null)) {
            return null;
        }
        $code = preg_replace('/[^A-Za-z0-9_.:-]+/', '_', trim($failure['code']));
        if (!is_string($code)) {
            return null;
        }
        $code = substr(trim($code, '_'), 0, 64);
        return $code === '' ? null : ['code' => $code];
    }

    /** @param array<string,mixed> $artifact @return array<string,mixed> */
    private function artifact(array $artifact): array
    {
        return [
            'id' => (string) $artifact['uuid'],
            'sha256' => (string) $artifact['sha256'],
            'originalName' => isset($artifact['original_name']) ? (string) $artifact['original_name'] : null,
            'bytes' => (int) $artifact['bytes'],
            'mimeType' => (string) $artifact['mime_type'],
            'createdAt' => $this->iso($artifact['created_at'] ?? null),
        ];
    }

    /** @param array<string,mixed> $evaluation @return array<string,mixed> */
    private function evaluation(array $evaluation): array
    {
        return [
            'id' => isset($evaluation['id']) ? (int) $evaluation['id'] : null,
            'status' => (string) $evaluation['status'],
            'evaluator' => (string) $evaluation['evaluator'],
            'evaluatorVersion' => (string) $evaluation['evaluator_version'],
            'findings' => $this->evaluationFindings($evaluation['findings'] ?? null),
            'facts' => $this->evaluationFacts($evaluation['facts'] ?? null),
            'createdAt' => $this->iso($evaluation['created_at'] ?? null),
            'completedAt' => $this->iso($evaluation['completed_at'] ?? null),
        ];
    }

    /** @return list<array{severity:string,code:string,message:string}> */
    private function evaluationFindings(mixed $findings): array
    {
        if (!is_array($findings)) {
            return [];
        }
        $result = [];
        foreach (array_slice($findings, 0, 100) as $finding) {
            if (!is_array($finding)) {
                continue;
            }
            $result[] = [
                'severity' => is_string($finding['severity'] ?? null) ? $finding['severity'] : '',
                'code' => is_string($finding['code'] ?? null) ? $finding['code'] : '',
                'message' => is_string($finding['message'] ?? null) ? $finding['message'] : '',
            ];
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function evaluationFacts(mixed $facts): array
    {
        if (!is_array($facts)) {
            return [];
        }
        $result = [];
        foreach ([
            'formatVersion', 'appVersion', 'projectName', 'projectCreatedAt', 'projectUpdatedAt',
            'taskType', 'dataType', 'entryCount', 'totalUncompressedBytes', 'runCount',
        ] as $key) {
            if (array_key_exists($key, $facts)) {
                $result[$key] = $facts[$key];
            }
        }
        $result['algorithms'] = array_values(array_slice(array_filter(
            is_array($facts['algorithms'] ?? null) ? $facts['algorithms'] : [],
            static fn (mixed $algorithm): bool => is_string($algorithm)
        ), 0, 50));
        $integrity = is_array($facts['integrity'] ?? null) ? $facts['integrity'] : [];
        $result['integrity'] = [
            'present' => ($integrity['present'] ?? null) === true,
            'verified' => ($integrity['verified'] ?? null) === true,
        ];
        $tabular = is_array($facts['tabular'] ?? null) ? $facts['tabular'] : [];
        $result['tabular'] = [];
        foreach ([
            'applicable', 'datasetPresent', 'hasHeader', 'records', 'rows', 'columns',
            'currentFeatureCount', 'maxSelectedFeatureCount', 'policyMaxRows', 'policyMaxFeatures',
        ] as $key) {
            if (array_key_exists($key, $tabular)) {
                $result['tabular'][$key] = $tabular[$key];
            }
        }
        return $result;
    }

    private function iso(mixed $mysqlDate): ?string
    {
        if (!is_string($mysqlDate) || $mysqlDate === '') {
            return null;
        }
        $timestamp = strtotime($mysqlDate . ' UTC');
        return $timestamp === false ? null : gmdate(DATE_ATOM, $timestamp);
    }

    private function rubric(mixed $value): array
    {
        if (!is_array($value) || !Rubric::structured($value)) {
            return [];
        }
        try {
            return Rubric::normalize($value);
        } catch (\OJML\Playgrounds\Domain\Problem) {
            return [];
        }
    }
}
