<?php
/**
 * Legacy migration owner matrix.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Infrastructure;

use LogicException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frozen owner and dispatch coverage for every table in the 0.1.2 source profile.
 */
final class Legacy_Migration_Owner_Matrix {
	public const HANDLER_MAPPING    = 'mapping';
	public const HANDLER_EXCLUDED   = 'excluded';
	public const HANDLER_DIRECT     = 'direct';
	public const HANDLER_ASSIGNMENT = 'assignment';
	public const HANDLER_SUBMISSION = 'submission';
	public const HANDLER_ZIP        = 'zip';
	public const HANDLER_EVALUATION = 'evaluation';

	/**
	 * Legacy migration owner matrix.
	 *
	 * @var array<string,array{disposition:string,owner:string,handler:string}>
	 */
	private const ENTRIES = array(
		'academic_years'              => array(
			'disposition' => 'mapping',
			'owner'       => 'platform_context',
			'handler'     => self::HANDLER_MAPPING,
		),
		'classes'                     => array(
			'disposition' => 'mapping',
			'owner'       => 'platform_context',
			'handler'     => self::HANDLER_MAPPING,
		),
		'groups'                      => array(
			'disposition' => 'mapping',
			'owner'       => 'platform_context',
			'handler'     => self::HANDLER_MAPPING,
		),
		'group_members'               => array(
			'disposition' => 'mapping',
			'owner'       => 'platform_user',
			'handler'     => self::HANDLER_MAPPING,
		),
		'academic_context_mappings'   => array(
			'disposition' => 'mapping',
			'owner'       => 'platform_context',
			'handler'     => self::HANDLER_MAPPING,
		),
		'student_mappings'            => array(
			'disposition' => 'mapping',
			'owner'       => 'platform_user',
			'handler'     => self::HANDLER_MAPPING,
		),
		'activity_events'             => array(
			'disposition' => 'exclude',
			'owner'       => 'none',
			'handler'     => self::HANDLER_EXCLUDED,
		),
		'audit_logs'                  => array(
			'disposition' => 'exclude',
			'owner'       => 'none',
			'handler'     => self::HANDLER_EXCLUDED,
		),
		'worksheet_templates'         => array(
			'disposition' => 'migrate',
			'owner'       => 'worksheet',
			'handler'     => self::HANDLER_DIRECT,
		),
		'worksheet_questions'         => array(
			'disposition' => 'migrate',
			'owner'       => 'worksheet',
			'handler'     => self::HANDLER_DIRECT,
		),
		'worksheet_assignments'       => array(
			'disposition' => 'migrate',
			'owner'       => 'assignment',
			'handler'     => self::HANDLER_ASSIGNMENT,
		),
		'worksheet_submissions'       => array(
			'disposition' => 'migrate',
			'owner'       => 'submission',
			'handler'     => self::HANDLER_SUBMISSION,
		),
		'submission_revisions'        => array(
			'disposition' => 'migrate',
			'owner'       => 'submission',
			'handler'     => self::HANDLER_SUBMISSION,
		),
		'submission_attachments'      => array(
			'disposition' => 'migrate',
			'owner'       => 'submission',
			'handler'     => self::HANDLER_SUBMISSION,
		),
		'zip_uploads'                 => array(
			'disposition' => 'migrate',
			'owner'       => 'zip_import',
			'handler'     => self::HANDLER_ZIP,
		),
		'zip_upload_items'            => array(
			'disposition' => 'migrate',
			'owner'       => 'zip_import',
			'handler'     => self::HANDLER_ZIP,
		),
		'curriculums'                 => array(
			'disposition' => 'migrate',
			'owner'       => 'curriculum',
			'handler'     => self::HANDLER_DIRECT,
		),
		'achievement_standards'       => array(
			'disposition' => 'migrate',
			'owner'       => 'curriculum',
			'handler'     => self::HANDLER_DIRECT,
		),
		'worksheet_achievement_links' => array(
			'disposition' => 'migrate',
			'owner'       => 'worksheet',
			'handler'     => self::HANDLER_DIRECT,
		),
		'question_achievement_links'  => array(
			'disposition' => 'migrate',
			'owner'       => 'worksheet',
			'handler'     => self::HANDLER_DIRECT,
		),
		'assessment_plans'            => array(
			'disposition' => 'migrate',
			'owner'       => 'evaluation',
			'handler'     => self::HANDLER_EVALUATION,
		),
		'assessment_runs'             => array(
			'disposition' => 'migrate',
			'owner'       => 'evaluation',
			'handler'     => self::HANDLER_EVALUATION,
		),
		'grading_inputs'              => array(
			'disposition' => 'migrate',
			'owner'       => 'evaluation',
			'handler'     => self::HANDLER_EVALUATION,
		),
		'rubric_snapshots'            => array(
			'disposition' => 'migrate',
			'owner'       => 'evaluation',
			'handler'     => self::HANDLER_EVALUATION,
		),
		'grading_items'               => array(
			'disposition' => 'migrate',
			'owner'       => 'evaluation',
			'handler'     => self::HANDLER_EVALUATION,
		),
		'assessment_results'          => array(
			'disposition' => 'migrate',
			'owner'       => 'evaluation',
			'handler'     => self::HANDLER_EVALUATION,
		),
		'regrade_reviews'             => array(
			'disposition' => 'migrate',
			'owner'       => 'evaluation',
			'handler'     => self::HANDLER_EVALUATION,
		),
		'feedback'                    => array(
			'disposition' => 'migrate',
			'owner'       => 'evaluation',
			'handler'     => self::HANDLER_EVALUATION,
		),
		'feedback_approvals'          => array(
			'disposition' => 'migrate',
			'owner'       => 'evaluation',
			'handler'     => self::HANDLER_EVALUATION,
		),
		'special_notes'               => array(
			'disposition' => 'migrate',
			'owner'       => 'evaluation',
			'handler'     => self::HANDLER_EVALUATION,
		),
		'reports'                     => array(
			'disposition' => 'exclude',
			'owner'       => 'none',
			'handler'     => self::HANDLER_EXCLUDED,
		),
		'export_jobs'                 => array(
			'disposition' => 'exclude',
			'owner'       => 'none',
			'handler'     => self::HANDLER_EXCLUDED,
		),
		'export_files'                => array(
			'disposition' => 'exclude',
			'owner'       => 'none',
			'handler'     => self::HANDLER_EXCLUDED,
		),
		'import_runs'                 => array(
			'disposition' => 'exclude',
			'owner'       => 'none',
			'handler'     => self::HANDLER_EXCLUDED,
		),
		'import_items'                => array(
			'disposition' => 'exclude',
			'owner'       => 'none',
			'handler'     => self::HANDLER_EXCLUDED,
		),
	);

	/**
	 * Profile.
	 *
	 * @var Legacy_Source_Profile_0_1_2
	 */
	private Legacy_Source_Profile_0_1_2 $profile;

	/**
	 * Initialize legacy migration owner matrix dependencies and state.
	 *
	 * @param Legacy_Source_Profile_0_1_2|null $profile Profile.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct( ?Legacy_Source_Profile_0_1_2 $profile = null ) {
		$this->profile = $profile ?? new Legacy_Source_Profile_0_1_2();
		$tables        = $this->profile->tables();
		if ( array_keys( $tables ) !== array_keys( self::ENTRIES ) ) {
			throw new LogicException( 'Legacy owner matrix inventory drifted.' );
		}

		foreach ( self::ENTRIES as $source_type => $entry ) {
			$disposition = $tables[ $source_type ]['disposition'] ?? null;
			if ( $disposition !== $entry['disposition'] ) {
				throw new LogicException( 'Legacy owner matrix disposition drifted.' );
			}
		}
	}

	/**
	 * Entries.
	 *
	 * @return array<string,array{disposition:string,owner:string,handler:string}>
	 */
	public function entries(): array {
		$result = array();
		foreach ( array_keys( $this->profile->execution_tables() ) as $source_type ) {
			$result[ $source_type ] = self::ENTRIES[ $source_type ];
		}
		return $result;
	}

	/**
	 * Entry.
	 *
	 * @param string $source_type Source type.
	 * @return array{disposition:string,owner:string,handler:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function entry( string $source_type ): array {
		if ( ! isset( self::ENTRIES[ $source_type ] ) ) {
			throw new LogicException( 'Legacy source type has no frozen owner.' );
		}
		return self::ENTRIES[ $source_type ];
	}

	/**
	 * Handler.
	 *
	 * @param string $source_type Source type.
	 * @return string
	 */
	public function handler( string $source_type ): string {
		return $this->entry( $source_type )['handler'];
	}

	/**
	 * Migration source types.
	 *
	 * @return array<int,string>
	 */
	public function migration_source_types(): array {
		return array_keys(
			array_filter(
				$this->entries(),
				static fn ( array $entry ): bool => 'migrate' === $entry['disposition']
			)
		);
	}
}
