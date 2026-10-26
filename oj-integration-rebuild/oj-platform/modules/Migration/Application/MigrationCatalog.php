<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Migration\Application;

use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationTable;
use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationWave;
use DomainException;

final class MigrationCatalog
{
    /** @var array<string, MigrationTable> */
    private array $tables = [];

    /**
     * @param list<MigrationTable>|null $tables
     */
    public function __construct(?array $tables = null)
    {
        foreach ($tables ?? self::defaults() as $table) {
            if (isset($this->tables[$table->key()])) {
                throw new DomainException('이전 테이블 키가 중복되었습니다.');
            }
            $this->tables[$table->key()] = $table;
        }
    }

    /** @return list<MigrationTable> */
    public function all(): array
    {
        return array_values($this->tables);
    }

    /** @return list<MigrationTable> */
    public function forWave(MigrationWave $wave): array
    {
        return array_values(array_filter(
            $this->tables,
            static fn (MigrationTable $table): bool => $table->wave() === $wave
        ));
    }

    public function find(string $key): MigrationTable
    {
        $table = $this->tables[$key] ?? null;
        if (! $table instanceof MigrationTable) {
            throw new DomainException('등록되지 않은 이전 테이블입니다.');
        }

        return $table;
    }

    /** @return list<string> */
    public function legacyLogicalNames(): array
    {
        $names = [];
        foreach ($this->tables as $table) {
            if ($table->legacyLogicalName() !== null) {
                $names[] = $table->legacyLogicalName();
            }
        }

        return array_values(array_unique($names));
    }

    /** @return list<MigrationTable> */
    private static function defaults(): array
    {
        $definitions = [
            [MigrationWave::Jobs, null, 'background_jobs', 'id', 'none'],
            [MigrationWave::Jobs, null, 'background_job_items', 'id', 'none'],
            [MigrationWave::Jobs, null, 'background_job_events', 'id', 'none'],
            [MigrationWave::Jobs, null, 'background_job_steps', 'id', 'none'],

            [MigrationWave::Auth, 'users', 'user_profiles', 'id', 'user_profiles'],
            [MigrationWave::Auth, 'user_preferences', 'user_preferences', 'id', 'user_preferences'],
            [MigrationWave::Auth, 'agreement_document_types', 'agreement_document_types', 'id', 'identity'],
            [MigrationWave::Auth, 'agreement_documents', 'agreement_documents', 'id', 'agreement_documents'],
            [MigrationWave::Auth, 'guardian_verifications', 'guardian_verifications', 'id', 'guardian_verifications'],
            [MigrationWave::Auth, 'user_agreement_log', 'user_agreement_log', 'id', 'user_agreement_log'],
            [MigrationWave::Auth, null, 'user_login_log', 'id', 'none'],
            [MigrationWave::Auth, 'deleted_users', 'deleted_users', 'id', 'deleted_users'],

            [MigrationWave::Problems, 'problem_metadata', 'problem_ownerships', 'problem_id', 'problem_ownerships'],
            [MigrationWave::Problems, null, 'problem_collaborators', 'id', 'none'],
            [MigrationWave::Problems, 'problem_metadata', 'problem_metadata', 'problem_id', 'problem_metadata', true],
            [MigrationWave::Problems, 'problem_metadata', 'problem_topic_links', 'problem_id', 'problem_topic_links'],
            [MigrationWave::Problems, 'quiz_problem_settings', 'quiz_revisions', 'problem_id', 'quiz_revisions'],
            [MigrationWave::Problems, 'quiz_questions', 'quiz_questions', 'id', 'quiz_questions'],
            [MigrationWave::Problems, 'quiz_choices', 'quiz_choices', 'id', 'quiz_choices'],
            [MigrationWave::Problems, null, 'quiz_question_bank', 'id', 'none'],
            [MigrationWave::Problems, null, 'quiz_question_bank_choices', 'id', 'none'],
            [MigrationWave::Problems, 'quiz_attempt_summaries', 'quiz_attempt_summaries', 'id', 'quiz_attempt_summaries'],
            [MigrationWave::Problems, 'quiz_attempt_items', 'quiz_attempt_items', 'id', 'quiz_attempt_items'],
            [MigrationWave::Problems, null, 'quiz_attempt_item_choices', 'id', 'none'],
            [MigrationWave::Problems, 'problem_book_categories', 'problem_book_categories', 'id', 'problem_book_categories'],
            [MigrationWave::Problems, 'problem_book_category_items', 'problem_book_category_items', 'id', 'problem_book_category_items'],
            [MigrationWave::Problems, 'personal_problem_book_items', 'personal_problem_book_items', 'id', 'personal_problem_book_items'],

            [MigrationWave::Submissions, null, 'submission_contexts', 'submission_id', 'none'],
            [MigrationWave::Submissions, 'submission_language_settings_log', 'submission_language_settings_log', 'id', 'submission_language_settings_log'],
            [MigrationWave::Submissions, 'submission_events', 'submission_events', 'id', 'submission_events'],

            [MigrationWave::Classes, 'classes', 'classes', 'id', 'classes'],
            [MigrationWave::Classes, 'classes', 'class_instructors', 'id', 'class_instructors'],
            [MigrationWave::Classes, 'class_enrollments', 'class_enrollments', 'id', 'class_enrollments'],
            [MigrationWave::Classes, 'class_group_links', 'class_group_links', 'id', 'class_group_links'],
            [MigrationWave::Classes, 'class_problem_links', 'class_problem_links', 'id', 'class_problem_links'],

            [MigrationWave::Contests, 'contest_settings', 'contest_settings', 'contest_id', 'contest_settings'],
            [MigrationWave::Contests, 'contest_settings', 'contest_problem_settings', 'contest_id', 'contest_problem_settings'],
            [MigrationWave::Contests, 'contest_settings', 'contest_managers', 'contest_id', 'contest_managers'],
            [MigrationWave::Contests, 'contest_participants', 'contest_participants', 'id', 'contest_participants'],
            [MigrationWave::Contests, 'contest_ip_logs', 'contest_ip_logs', 'id', 'contest_ip_logs'],
            [MigrationWave::Contests, 'deleted_contests', 'deleted_contests', 'contest_id', 'deleted_contests'],

            [MigrationWave::Groups, 'groups', 'learning_groups', 'id', 'learning_groups'],
            [MigrationWave::Groups, 'group_managers', 'group_managers', 'id', 'group_managers'],
            [MigrationWave::Groups, 'group_memberships', 'group_memberships', 'id', 'group_memberships'],

            [MigrationWave::AiFeedback, 'ai_assist', 'submission_ai_feedback', 'submission_id', 'submission_ai_feedback'],
            [MigrationWave::AiFeedback, 'student_level_profiles', 'learner_level_profiles', 'user_id', 'learner_level_profiles'],

            [MigrationWave::Messaging, null, 'messages', 'id', 'none'],
            [MigrationWave::Messaging, null, 'message_reports', 'id', 'none'],
            [MigrationWave::Settings, null, 'ai_usage_logs', 'id', 'none'],
            [MigrationWave::Settings, null, 'addon_ai_requests', 'id', 'none'],
        ];

        return array_map(
            static fn (array $definition): MigrationTable => new MigrationTable(
                $definition[0]->value . '_' . $definition[2],
                $definition[0],
                $definition[1],
                $definition[2],
                $definition[3],
                $definition[4],
                (bool) ($definition[5] ?? false)
            ),
            $definitions
        );
    }
}
