<?php
/**
 * CFG-BOOTSTRAP-02 / G5-IMP-04 assessment configuration contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Assessment\Configuration\Application\Assessment_Configuration_Failure;
use OJAI\Dashboard\Assessment\Configuration\Application\Manage_Assessment_Configuration;
use OJAI\Dashboard\Assessment\Configuration\Domain\Assessment_Configuration_Profile;
use OJAI\Dashboard\Assessment\Configuration\Infrastructure\WordPress_Assessment_Configuration_Repository;
use OJAI\Dashboard\Assessment\Configuration\Port\Assessment_Configuration_Repository_Port_Interface;
use OJAI\Dashboard\Assessment\Configuration\Presentation\Assessment_Configuration_Rest_Controller;
use OJAI\Dashboard\Core\Activation_Workflow;
use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Database\Foundation\Port\Schema_Installer_Port_Interface;
use OJAI\Dashboard\Database\Foundation\Schema_Installation_Result;
use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Infrastructure\WordPress_Rest_Request_Adapter;
use OJAI\Dashboard\Rest\Port\Current_Actor_Port_Interface;
use OJAI\Dashboard\Rest\Port\Rest_Nonce_Verifier_Port_Interface;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Rest\Rest_Request_Authorizer;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

$addon_root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );
if ( ! class_exists( 'Normalizer' ) ) {
	/**
	 * CFG-BOOTSTRAP-02 / G5-IMP-04 assessment configuration contract.
	 */
	final class Normalizer {
		public const FORM_C = 4;
		/**
		 * Normalize.
		 *
		 * @param string $value Value.
		 * @param int    $form Form.
		 * @return string
		 */
		public static function normalize( string $value, int $form = self::FORM_C ): string {
			unset( $form );
			return $value;
		}
	}
}

$GLOBALS['configuration_s01_routes'] = array();
if ( ! function_exists( 'register_rest_route' ) ) {
	/**
	 * Register rest route.
	 *
	 * @param string              $namespace Namespace.
	 * @param string              $route Route.
	 * @param array<string,mixed> $arguments Arguments.
	 * @return bool
	 */
	function register_rest_route( string $namespace, string $route, array $arguments ): bool {
		$GLOBALS['configuration_s01_routes'][] = array(
			'namespace' => $namespace,
			'route'     => $route,
			'arguments' => $arguments,
		);
		return true;
	}
}

require_once $addon_root . '/src/Core/Autoloader.php';
( new Autoloader( $addon_root . '/src' ) )->register();

/**
 * Configuration s01 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function configuration_s01_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Configuration s01 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function configuration_s01_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * CFG-BOOTSTRAP-02 / G5-IMP-04 assessment configuration contract.
 */
final class Configuration_S01_Database {
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Tables.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public array $tables = array();
	/**
	 * Transactions.
	 *
	 * @var array<int,string>
	 */
	public array $transactions = array();

	/**
	 * Prepare.
	 *
	 * @param string $query Query.
	 * @param mixed  ...$values Values.
	 * @return string
	 */
	public function prepare( string $query, ...$values ): string {
		foreach ( $values as $value ) {
			$replacement = is_int( $value ) ? (string) $value : "'" . str_replace( "'", "''", (string) $value ) . "'";
			$query       = (string) preg_replace( '/%(?:s|d)/', $replacement, $query, 1 );
		}
		return $query;
	}

	/**
	 * Get var.
	 *
	 * @param string $query Query.
	 * @return mixed
	 */
	public function get_var( string $query ) {
		if ( str_contains( $query, 'SELECT event_hash FROM oj_ai_audit_events' ) ) {
			$rows = $this->tables['oj_ai_audit_events'] ?? array();
			return array() === $rows ? null : $rows[ array_key_last( $rows ) ]['event_hash'];
		}
		if ( preg_match( "/SELECT COUNT\\(\\*\\) FROM (oj_ai_[a-z_]+) WHERE subject_type = '([^']+)'/", $query, $matches ) ) {
			$count = 0;
			foreach ( $this->tables[ $matches[1] ] ?? array() as $row ) {
				if ( $matches[2] === ( $row['subject_type'] ?? null ) ) {
					++$count;
				}
			}
			return (string) $count;
		}
		return null;
	}

	/**
	 * Get results.
	 *
	 * @param string $query Query.
	 * @param mixed  $mode Mode.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_results( string $query, $mode = null ): array {
		unset( $mode );
		if ( preg_match( "/FROM (oj_ai_[a-z_]+) WHERE public_id = '([^']+)' AND subject_type = '([^']+)' AND state IN \('([^']+)', '([^']+)'\) LIMIT 2 FOR UPDATE/", $query, $matches ) ) {
			return array_values(
				array_filter(
					$this->tables[ $matches[1] ] ?? array(),
					static fn ( array $row ): bool => $matches[2] === ( $row['public_id'] ?? null )
						&& $matches[3] === ( $row['subject_type'] ?? null )
						&& in_array( $row['state'] ?? null, array( $matches[4], $matches[5] ), true )
				)
			);
		}
		if ( preg_match( "/FROM (oj_ai_[a-z_]+) WHERE subject_type = '([^']+)' AND state = '([^']+)' ORDER BY revision_no DESC LIMIT 2 FOR UPDATE/", $query, $matches ) ) {
			$rows = array_values(
				array_filter(
					$this->tables[ $matches[1] ] ?? array(),
					static fn ( array $row ): bool => $matches[2] === ( $row['subject_type'] ?? null )
						&& $matches[3] === ( $row['state'] ?? null )
				)
			);
			usort( $rows, static fn ( array $left, array $right ): int => (int) $right['revision_no'] <=> (int) $left['revision_no'] );
			return array_slice( $rows, 0, 2 );
		}
		if ( preg_match( "/SELECT public_id, subject_type, state FROM (oj_ai_[a-z_]+) WHERE public_id = '([^']+)' LIMIT 2 FOR UPDATE/", $query, $matches ) ) {
			return array_values(
				array_filter(
					$this->tables[ $matches[1] ] ?? array(),
					static fn ( array $row ): bool => $matches[2] === ( $row['public_id'] ?? null )
				)
			);
		}
		return array();
	}
	/**
	 * Get row.
	 *
	 * @param string $query Query.
	 * @param mixed  $mode Mode.
	 * @return array<string,mixed>|null
	 */
	public function get_row( string $query, $mode = null ): ?array {
		unset( $mode );
		if ( preg_match( "/SELECT (revision_no|public_id) FROM (oj_ai_[a-z_]+) WHERE subject_type = '([^']+)' ORDER BY revision_no DESC LIMIT 1 FOR UPDATE/", $query, $matches ) ) {
			$latest = null;
			foreach ( $this->tables[ $matches[2] ] ?? array() as $row ) {
				if ( $matches[3] !== ( $row['subject_type'] ?? null ) ) {
					continue;
				}
				if ( null === $latest || (int) $row['revision_no'] > (int) $latest['revision_no'] ) {
					$latest = $row;
				}
			}
			if ( null === $latest ) {
				return null;
			}
			return array( $matches[1] => $latest[ $matches[1] ] );
		}
		return null;
	}
	/**
	 * Insert.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data Data.
	 * @return int
	 */
	public function insert( string $table, array $data ): int {
		$this->tables[ $table ][] = $data;
		return 1;
	}
	/**
	 * Query.
	 *
	 * @param string $query Query.
	 * @return int
	 */
	public function query( string $query ): int {
		if ( in_array( $query, array( 'START TRANSACTION', 'COMMIT', 'ROLLBACK' ), true ) ) {
			$this->transactions[] = $query;
			return 1;
		}
		if ( preg_match( "/^UPDATE (oj_ai_[a-z_]+) SET state = '([^']+)', lock_version = lock_version \\+ 1, .*WHERE public_id = '([^']+)' AND state = '([^']+)' AND lock_version = ([0-9]+)$/", $query, $matches ) ) {
			if ( ! isset( $this->tables[ $matches[1] ] ) ) {
				return 0;
			}
			foreach ( $this->tables[ $matches[1] ] as &$row ) {
				if (
					$matches[3] !== ( $row['public_id'] ?? null )
					|| $matches[4] !== ( $row['state'] ?? null )
					|| (int) $matches[5] !== (int) ( $row['lock_version'] ?? -1 )
				) {
					continue;
				}
				$row['state'] = $matches[2];
				++$row['lock_version'];
				if ( preg_match( "/updated_at_utc = '([^']+)'/", $query, $updated ) ) {
					$row['updated_at_utc'] = $updated[1];
				}
				if ( Assessment_Configuration_Profile::STATE_RETIRED === $matches[2] ) {
					$row['retired_at_utc']     = $updated[1] ?? $row['updated_at_utc'];
					$row['retired_by_user_id'] = str_contains( $query, 'retired_by_user_id = NULL' ) ? null : 0;
				}
				unset( $row );
				return 1;
			}
			unset( $row );
			return 0;
		}
		return 1;
	}
}

/**
 * Replace only the worksheet policy's package prompt and pinned reference IDs.
 *
 * @param Configuration_S01_Database $database Database.
 * @param Canonical_Json             $canonical Canonical JSON encoder used for stable digests.
 * @param string                     $contract Contract.
 * @param string                     $content_hash Content hash.
 * @return void
 */
function configuration_s01_install_historical_prompt(
	Configuration_S01_Database $database,
	Canonical_Json $canonical,
	string $contract,
	string $content_hash
): void {
	$rubric_id = '01K00000000000000000000301';
	$review_id = '01K00000000000000000000302';
	foreach ( $database->tables['oj_ai_rubric_revisions'] as &$row ) {
		if ( Assessment_Configuration_Profile::WORKSHEET_QUALITY === $row['subject_type'] ) {
			$row['public_id'] = $rubric_id;
		}
	}
	unset( $row );
	foreach ( $database->tables['oj_ai_review_rule_revisions'] as &$row ) {
		if ( Assessment_Configuration_Profile::WORKSHEET_QUALITY === $row['subject_type'] ) {
			$row['public_id'] = $review_id;
		}
	}
	unset( $row );
	foreach ( $database->tables['oj_ai_evaluation_policy_revisions'] as &$row ) {
		if ( Assessment_Configuration_Profile::WORKSHEET_QUALITY !== $row['subject_type'] ) {
			continue;
		}
		$value                            = json_decode( $row['policy_json'], true, 64, JSON_THROW_ON_ERROR );
		$value['evaluation_enabled']      = false;
		$value['rubric_revision_id']      = $rubric_id;
		$value['review_rule_revision_id'] = $review_id;
		$value['prompt']                  = array(
			'contract_version' => $contract,
			'content_hash'     => $content_hash,
		);
		$row['policy_json']               = $canonical->encode( $value );
		$row['policy_hash']               = hash( 'sha256', $row['policy_json'] );
		$row['content_hash']              = $row['policy_hash'];
	}
	unset( $row );
}

/**
 * Replace the package student rubric with the exact pre-level-scoring default.
 *
 * @param Configuration_S01_Database $database Database.
 * @param Canonical_Json             $canonical Canonical JSON encoder used for stable digests.
 * @return void
 */
function configuration_s01_install_historical_student_rubric(
	Configuration_S01_Database $database,
	Canonical_Json $canonical
): void {
	foreach ( $database->tables['oj_ai_rubric_revisions'] as &$row ) {
		if ( Assessment_Configuration_Profile::STUDENT_SUBMISSION !== $row['subject_type'] ) {
			continue;
		}
		$value                = array(
			'criteria' => array(
				array(
					'criterion_id'   => '01K00000000000000000000211',
					'label'          => '완성도와 사고 과정',
					'maximum_points' => '10',
					'position'       => 0,
					'scope'          => 'submission',
				),
			),
		);
		$row['criteria_json'] = $canonical->encode( $value );
		$row['criteria_hash'] = hash( 'sha256', $row['criteria_json'] );
		$row['content_hash']  = $row['criteria_hash'];
		$row['total_points']  = '10';
	}
	unset( $row );
}

/**
 * Simulate an operator activating a current rubric without repinning policy.
 *
 * @param Configuration_S01_Database $database Database.
 * @param array<string,mixed>        $current_default Current default.
 * @return string
 */
function configuration_s01_activate_current_student_rubric(
	Configuration_S01_Database $database,
	array $current_default
): string {
	$target_id = '01K00000000000000000000401';
	foreach ( $database->tables['oj_ai_rubric_revisions'] as &$row ) {
		if ( Assessment_Configuration_Profile::STUDENT_SUBMISSION !== $row['subject_type'] ) {
			continue;
		}
		$row['state']                                 = Assessment_Configuration_Profile::STATE_RETIRED;
		$row['retired_at_utc']                        = '2026-08-25 09:00:00';
		$current                                      = $row;
		$current['public_id']                         = $target_id;
		$current['revision_no']                       = 2;
		$current['state']                             = Assessment_Configuration_Profile::STATE_ACTIVE;
		$current['lock_version']                      = 0;
		$current['criteria_json']                     = $current_default['json'];
		$current['criteria_hash']                     = $current_default['hash'];
		$current['content_hash']                      = $current_default['content_hash'];
		$current['total_points']                      = $current_default['total_points'];
		$current['activated_at_utc']                  = '2026-08-25 09:00:00';
		$current['retired_at_utc']                    = null;
		$database->tables['oj_ai_rubric_revisions'][] = $current;
		break;
	}
	unset( $row );
	return $target_id;
}
/**
 * CFG-BOOTSTRAP-02 / G5-IMP-04 assessment configuration contract.
 */
final class Configuration_S01_Installer implements Schema_Installer_Port_Interface {
	/**
	 * Initialize configuration s01 installer dependencies and state.
	 *
	 * @param array<int,string> &$events Events.
	 */
	public function __construct( private array &$events ) {
	}
	/**
	 * Install.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $correlation_id Request correlation identifier for diagnostics.
	 * @return Schema_Installation_Result
	 */
	public function install( int $actor_user_id, string $correlation_id ): Schema_Installation_Result {
		unset( $actor_user_id );
		$this->events[] = 'install';
		return new Schema_Installation_Result( Schema_Installation_Result::INSTALLED, 'schema_installed', $correlation_id );
	}
}

/**
 * CFG-BOOTSTRAP-02 / G5-IMP-04 assessment configuration contract.
 */
final class Configuration_S01_Repository_Port implements Assessment_Configuration_Repository_Port_Interface {
	/**
	 * Rows.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $rows = array();
	/**
	 * Last create.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $last_create = null;
	/**
	 * Last activate.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $last_activate = null;
	/**
	 * List revisions.
	 *
	 * @param string $resource_type Resource type.
	 * @return array
	 */
	public function list_revisions( string $resource_type ): array {
		return array_values( array_filter( $this->rows, static fn ( array $row ): bool => $resource_type === $row['resource_type'] ) );
	}
	/**
	 * Create revision.
	 *
	 * @param array $command Command.
	 * @return array
	 */
	public function create_revision( array $command ): array {
		$this->last_create = $command;
		return array(
			'operation'           => 'create',
			'occurred_at_utc'     => $command['occurred_at_utc'],
			'replayed'            => false,
			'retired_revision_id' => null,
			'revision'            => array(),
		);
	}
	/**
	 * Activate revision.
	 *
	 * @param array $command Command.
	 * @return array
	 */
	public function activate_revision( array $command ): array {
		$this->last_activate = $command;
		return array(
			'operation'           => 'activate',
			'occurred_at_utc'     => $command['occurred_at_utc'],
			'replayed'            => false,
			'retired_revision_id' => null,
			'revision'            => array(),
		);
	}
	/**
	 * Seed defaults.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $correlation_id Request correlation identifier for diagnostics.
	 * @return array
	 */
	public function seed_defaults( int $actor_user_id, string $correlation_id ): array {
		unset( $actor_user_id, $correlation_id );
		return array();
	}
}

/**
 * CFG-BOOTSTRAP-02 / G5-IMP-04 assessment configuration contract.
 */
final class Configuration_S01_Actor implements Current_Actor_Port_Interface {
	/**
	 * Current actor user id.
	 *
	 * @return int
	 */
	public function current_actor_user_id(): int {
		return 42;
	}
}
/**
 * CFG-BOOTSTRAP-02 / G5-IMP-04 assessment configuration contract.
 */
final class Configuration_S01_Nonce implements Rest_Nonce_Verifier_Port_Interface {
	/**
	 * Verify configuration s01 nonce.
	 *
	 * @param string $nonce Nonce.
	 * @return bool
	 */
	public function verify( string $nonce ): bool {
		unset( $nonce );
		return true;
	}
}
/**
 * CFG-BOOTSTRAP-02 / G5-IMP-04 assessment configuration contract.
 */
final class Configuration_S01_Policy implements Access_Policy_Port_Interface {
	/**
	 * Authorize.
	 *
	 * @param Access_Policy_Request $request Request.
	 * @return Access_Policy_Decision
	 */
	public function authorize( Access_Policy_Request $request ): Access_Policy_Decision {
		return Access_Policy_Decision::allowed( $request->correlation_id() );
	}
}

$canonical = new Canonical_Json();
$profile   = new Assessment_Configuration_Profile( $canonical );
$defaults  = $profile->defaults();
configuration_s01_same( array( 'worksheet_quality', 'student_submission' ), array_keys( $defaults ), 'Both frozen subjects need defaults.' );
foreach ( $defaults as $subject_type => $records ) {
	configuration_s01_same( array( 'rubric', 'review_rule', 'policy' ), array_keys( $records ), 'Every subject needs rubric, review, then policy defaults.' );
	foreach ( $records as $record ) {
		configuration_s01_assert( Public_Id::is_valid( $record['public_id'] ), 'Package default IDs must satisfy PublicIdV1.' );
		configuration_s01_same( hash( 'sha256', $record['json'] ), $record['content_hash'], 'Default content hash must cover canonical JSON.' );
	}
}
configuration_s01_same(
	array( 'learning_goal_alignment', 'instruction_clarity', 'answerability', 'difficulty_fit', 'rubric_quality', 'safety' ),
	array_column( $defaults['worksheet_quality']['rubric']['value']['dimensions'], 'dimension_key' ),
	'Worksheet-quality seed must preserve the six core dimensions in order.'
);
configuration_s01_same( '학습 목표 정렬', $defaults['worksheet_quality']['rubric']['value']['dimensions'][0]['label'], 'Worksheet-quality default label must be operator-facing Korean.' );
configuration_s01_same( '완성도와 사고 과정', $defaults['student_submission']['rubric']['value']['criteria'][0]['label'], 'Student default criterion must be operator-facing Korean.' );
configuration_s01_same( 'submission', $defaults['student_submission']['rubric']['value']['criteria'][0]['scope'], 'The safe student seed must not guess future response bindings.' );
configuration_s01_same( '10', $defaults['student_submission']['rubric']['total_points'], 'Student seed total points drifted.' );
configuration_s01_assert( ! str_contains( implode( '|', array_map( array( Assessment_Configuration_Profile::class, 'table' ), Assessment_Configuration_Profile::resource_types() ) ), 'wp_' ), 'Configuration table names must remain literal and prefix-free.' );

$historical_policy_value           = $defaults['worksheet_quality']['policy']['value'];
$historical_policy_value['prompt'] = array(
	'contract_version' => 'worksheet_quality_prompt.v2',
	'content_hash'     => '5499f44428a9e504e045fb6035c6d650bef8601a8421567095063a231e5db2cd',
);
$historical_policy_json            = $canonical->encode( $historical_policy_value );
$historical_policy_hash            = hash( 'sha256', $historical_policy_json );
$historical_policy                 = $profile->project_row(
	Assessment_Configuration_Profile::POLICY,
	array(
		'public_id'        => '01K00000000000000000000901',
		'subject_type'     => Assessment_Configuration_Profile::WORKSHEET_QUALITY,
		'revision_no'      => 1,
		'state'            => Assessment_Configuration_Profile::STATE_ACTIVE,
		'lock_version'     => 0,
		'policy_schema'    => 'oj_ai.worksheet_quality_policy',
		'policy_version'   => 1,
		'policy_json'      => $historical_policy_json,
		'policy_hash'      => $historical_policy_hash,
		'content_hash'     => $historical_policy_hash,
		'created_at_utc'   => '2026-08-20 09:00:00',
		'updated_at_utc'   => '2026-08-20 09:00:00',
		'activated_at_utc' => '2026-08-20 09:00:00',
		'retired_at_utc'   => null,
	)
);
configuration_s01_same( 'worksheet_quality_prompt.v2', $historical_policy['value']['prompt']['contract_version'], 'Historical worksheet policies must remain readable after a prompt upgrade.' );
$historical_activation_rejected = false;
try {
	$profile->assert_activatable_revision( $historical_policy );
} catch ( InvalidArgumentException ) {
	$historical_activation_rejected = true;
}
configuration_s01_assert( $historical_activation_rejected, 'An outdated worksheet policy must not be reactivated under the current Runtime contract.' );
$profile->assert_activatable_revision( $defaults['worksheet_quality']['policy'] );

$database   = new Configuration_S01_Database();
$repository = new WordPress_Assessment_Configuration_Repository( $canonical, new Public_Id_Generator(), $profile, $database );
$seeded     = $repository->seed_defaults( 0, 'activation:configuration-s01' );
configuration_s01_same(
	array(
		'worksheet_quality'  => 'seeded',
		'student_submission' => 'seeded',
	),
	$seeded,
	'Fresh install must seed both subjects.'
);
configuration_s01_same( 2, count( $database->tables['oj_ai_evaluation_policy_revisions'] ?? array() ), 'Fresh seed policy count drifted.' );
configuration_s01_same( 2, count( $database->tables['oj_ai_rubric_revisions'] ?? array() ), 'Fresh seed rubric count drifted.' );
configuration_s01_same( 2, count( $database->tables['oj_ai_review_rule_revisions'] ?? array() ), 'Fresh seed review count drifted.' );
configuration_s01_same( 2, count( $database->tables['oj_ai_audit_events'] ?? array() ), 'Fresh seed needs one redacted audit per subject.' );
$seed_audit = $database->tables['oj_ai_audit_events'][0];
configuration_s01_same( null, $seed_audit['actor_user_id'], 'System seed actor must be stored as null.' );
$expected_seed_event_hash = hash(
	'sha256',
	$canonical->encode(
		array(
			'action_key'          => $seed_audit['action_key'],
			'actor_user_id'       => null,
			'after_digest'        => $seed_audit['after_digest'],
			'before_digest'       => $seed_audit['before_digest'],
			'correlation_id'      => $seed_audit['correlation_id'],
			'metadata_hash'       => $seed_audit['metadata_hash'],
			'object_public_id'    => $seed_audit['object_public_id'],
			'object_type'         => $seed_audit['object_type'],
			'occurred_at_utc'     => $seed_audit['occurred_at_utc'],
			'previous_event_hash' => null,
		)
	)
);
configuration_s01_same( $expected_seed_event_hash, $seed_audit['event_hash'], 'System seed audit hash must use the stored null actor.' );
$historical_prompts     = array();
$current_prompt_version = (int) substr( \OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Quality_Prompt_Contract::VERSION, strlen( 'worksheet_quality_prompt.v' ) );
for ( $version = 1; $version < $current_prompt_version; ++$version ) {
	$prompt_path  = dirname( __DIR__, 2 ) . '/resources/contracts/ai/worksheet-quality-prompt-v' . $version . '.txt';
	$prompt_bytes = file_get_contents( $prompt_path );
	configuration_s01_assert( is_string( $prompt_bytes ), 'Every released prompt needs upgrade coverage.' );
	$historical_prompts[] = array( 'worksheet_quality_prompt.v' . $version, hash( 'sha256', str_replace( array( "\r\n", "\r" ), "\n", $prompt_bytes ) ) );
}
foreach ( $historical_prompts as $case => $descriptor ) {
	$upgrade_database   = new Configuration_S01_Database();
	$upgrade_repository = new WordPress_Assessment_Configuration_Repository( $canonical, new Public_Id_Generator(), $profile, $upgrade_database );
	$upgrade_repository->seed_defaults( 0, 'activation:configuration-upgrade-source-' . $case );
	configuration_s01_install_historical_prompt( $upgrade_database, $canonical, $descriptor[0], $descriptor[1] );
	$upgrade_database->transactions = array();

	$upgraded = $upgrade_repository->seed_defaults( 0, 'upgrade:configuration-prompt-' . $case );
	configuration_s01_same(
		array(
			'worksheet_quality'  => 'upgraded',
			'student_submission' => 'preserved',
		),
		$upgraded,
		'Known historical worksheet prompts must upgrade without resetting other subjects.'
	);
	$worksheet_policies = array_values(
		array_filter(
			$upgrade_database->tables['oj_ai_evaluation_policy_revisions'],
			static fn ( array $row ): bool => Assessment_Configuration_Profile::WORKSHEET_QUALITY === $row['subject_type']
		)
	);
	usort( $worksheet_policies, static fn ( array $left, array $right ): int => (int) $left['revision_no'] <=> (int) $right['revision_no'] );
	configuration_s01_same( 2, count( $worksheet_policies ), 'Prompt upgrade must append exactly one policy revision.' );
	configuration_s01_same( Assessment_Configuration_Profile::STATE_RETIRED, $worksheet_policies[0]['state'], 'Historical policy must remain as a retired revision.' );
	configuration_s01_same( Assessment_Configuration_Profile::STATE_ACTIVE, $worksheet_policies[1]['state'], 'Current prompt policy must become active.' );
	$upgraded_value = json_decode( $worksheet_policies[1]['policy_json'], true, 64, JSON_THROW_ON_ERROR );
	configuration_s01_same( false, $upgraded_value['evaluation_enabled'], 'Prompt upgrade must preserve the operator evaluation switch.' );
	configuration_s01_same( '01K00000000000000000000301', $upgraded_value['rubric_revision_id'], 'Prompt upgrade must preserve the pinned rubric.' );
	configuration_s01_same( '01K00000000000000000000302', $upgraded_value['review_rule_revision_id'], 'Prompt upgrade must preserve the pinned review rule.' );
	configuration_s01_same( $defaults['worksheet_quality']['policy']['value']['prompt'], $upgraded_value['prompt'], 'Prompt upgrade must pin the current contract and hash.' );
	$upgrade_audit = $upgrade_database->tables['oj_ai_audit_events'][ array_key_last( $upgrade_database->tables['oj_ai_audit_events'] ) ];
	configuration_s01_same( 'assessment_configuration.prompt_contract_upgraded', $upgrade_audit['action_key'], 'Prompt upgrade needs a dedicated audit event.' );
	configuration_s01_same( null, $upgrade_audit['actor_user_id'], 'Automatic prompt upgrade must not invent a human actor.' );
	configuration_s01_same( array( 'START TRANSACTION', 'COMMIT' ), $upgrade_database->transactions, 'Prompt upgrade must commit atomically.' );

	$policy_count                   = count( $upgrade_database->tables['oj_ai_evaluation_policy_revisions'] );
	$audit_count                    = count( $upgrade_database->tables['oj_ai_audit_events'] );
	$upgrade_database->transactions = array();
	$replayed                       = $upgrade_repository->seed_defaults( 0, 'upgrade:configuration-prompt-replay-' . $case );
	configuration_s01_same(
		array(
			'worksheet_quality'  => 'preserved',
			'student_submission' => 'preserved',
		),
		$replayed,
		'Current prompt upgrade replay must be a no-op.'
	);
	configuration_s01_same( $policy_count, count( $upgrade_database->tables['oj_ai_evaluation_policy_revisions'] ), 'Prompt upgrade replay must not append a revision.' );
	configuration_s01_same( $audit_count, count( $upgrade_database->tables['oj_ai_audit_events'] ), 'Prompt upgrade replay must not append an audit.' );
}

$legacy_student_database   = new Configuration_S01_Database();
$legacy_student_repository = new WordPress_Assessment_Configuration_Repository( $canonical, new Public_Id_Generator(), $profile, $legacy_student_database );
$legacy_student_repository->seed_defaults( 0, 'activation:configuration-student-legacy-source' );
configuration_s01_install_historical_student_rubric( $legacy_student_database, $canonical );
$legacy_student_database->transactions = array();
$legacy_student_upgraded               = $legacy_student_repository->seed_defaults( 0, 'upgrade:configuration-student-legacy' );
configuration_s01_same(
	array(
		'worksheet_quality'  => 'preserved',
		'student_submission' => 'upgraded',
	),
	$legacy_student_upgraded,
	'Package-owned pre-level student rubric must upgrade automatically.'
);
$student_rubrics = array_values(
	array_filter(
		$legacy_student_database->tables['oj_ai_rubric_revisions'],
		static fn ( array $row ): bool => Assessment_Configuration_Profile::STUDENT_SUBMISSION === $row['subject_type']
	)
);
usort( $student_rubrics, static fn ( array $left, array $right ): int => (int) $left['revision_no'] <=> (int) $right['revision_no'] );
configuration_s01_same( 2, count( $student_rubrics ), 'Legacy student rubric upgrade must append one rubric revision.' );
configuration_s01_same( Assessment_Configuration_Profile::STATE_RETIRED, $student_rubrics[0]['state'], 'Legacy package rubric must remain retired.' );
configuration_s01_same( Assessment_Configuration_Profile::STATE_ACTIVE, $student_rubrics[1]['state'], 'Current package rubric must become active.' );
$current_student_value = json_decode( $student_rubrics[1]['criteria_json'], true, 64, JSON_THROW_ON_ERROR );
configuration_s01_same( 4, count( $current_student_value['criteria'][0]['levels'] ?? array() ), 'Upgraded student rubric must include observable score levels.' );
configuration_s01_assert( isset( $current_student_value['criteria'][0]['description'] ), 'Upgraded student rubric must include an evaluation method.' );
$student_policies = array_values(
	array_filter(
		$legacy_student_database->tables['oj_ai_evaluation_policy_revisions'],
		static fn ( array $row ): bool => Assessment_Configuration_Profile::STUDENT_SUBMISSION === $row['subject_type']
	)
);
usort( $student_policies, static fn ( array $left, array $right ): int => (int) $left['revision_no'] <=> (int) $right['revision_no'] );
configuration_s01_same( 2, count( $student_policies ), 'Legacy student rubric upgrade must append one policy revision.' );
configuration_s01_same( Assessment_Configuration_Profile::STATE_RETIRED, $student_policies[0]['state'], 'Legacy student policy must remain retired.' );
configuration_s01_same( Assessment_Configuration_Profile::STATE_ACTIVE, $student_policies[1]['state'], 'Repinned student policy must become active.' );
$current_student_policy_value = json_decode( $student_policies[1]['policy_json'], true, 64, JSON_THROW_ON_ERROR );
configuration_s01_same( $student_rubrics[1]['public_id'], $current_student_policy_value['rubric_revision_id'], 'Upgraded policy must pin the current rubric.' );
$student_upgrade_audit = $legacy_student_database->tables['oj_ai_audit_events'][ array_key_last( $legacy_student_database->tables['oj_ai_audit_events'] ) ];
configuration_s01_same( 'assessment_configuration.student_rubric_contract_upgraded', $student_upgrade_audit['action_key'], 'Student rubric upgrade needs a dedicated audit event.' );
configuration_s01_same( null, $student_upgrade_audit['actor_user_id'], 'Automatic student rubric upgrade must not invent a human actor.' );
configuration_s01_same( array( 'START TRANSACTION', 'COMMIT' ), $legacy_student_database->transactions, 'Student rubric upgrade must commit atomically.' );
$student_rubric_count                  = count( $legacy_student_database->tables['oj_ai_rubric_revisions'] );
$student_policy_count                  = count( $legacy_student_database->tables['oj_ai_evaluation_policy_revisions'] );
$student_audit_count                   = count( $legacy_student_database->tables['oj_ai_audit_events'] );
$legacy_student_database->transactions = array();
$legacy_student_replayed               = $legacy_student_repository->seed_defaults( 0, 'upgrade:configuration-student-legacy-replay' );
configuration_s01_same(
	array(
		'worksheet_quality'  => 'preserved',
		'student_submission' => 'preserved',
	),
	$legacy_student_replayed,
	'Student rubric upgrade replay must be a no-op.'
);
configuration_s01_same( $student_rubric_count, count( $legacy_student_database->tables['oj_ai_rubric_revisions'] ), 'Student rubric replay must not append a rubric.' );
configuration_s01_same( $student_policy_count, count( $legacy_student_database->tables['oj_ai_evaluation_policy_revisions'] ), 'Student rubric replay must not append a policy.' );
configuration_s01_same( $student_audit_count, count( $legacy_student_database->tables['oj_ai_audit_events'] ), 'Student rubric replay must not append an audit.' );

$repin_database   = new Configuration_S01_Database();
$repin_repository = new WordPress_Assessment_Configuration_Repository( $canonical, new Public_Id_Generator(), $profile, $repin_database );
$repin_repository->seed_defaults( 0, 'activation:configuration-student-repin-source' );
configuration_s01_install_historical_student_rubric( $repin_database, $canonical );
$operator_rubric_id           = configuration_s01_activate_current_student_rubric(
	$repin_database,
	$defaults[ Assessment_Configuration_Profile::STUDENT_SUBMISSION ][ Assessment_Configuration_Profile::RUBRIC ]
);
$repin_database->transactions = array();
$repinned                     = $repin_repository->seed_defaults( 0, 'upgrade:configuration-student-repin' );
configuration_s01_same(
	array(
		'worksheet_quality'  => 'preserved',
		'student_submission' => 'upgraded',
	),
	$repinned,
	'An active current rubric must replace a pinned package legacy rubric without adding another rubric.'
);
$repin_student_rubrics = array_values(
	array_filter(
		$repin_database->tables['oj_ai_rubric_revisions'],
		static fn ( array $row ): bool => Assessment_Configuration_Profile::STUDENT_SUBMISSION === $row['subject_type']
	)
);
configuration_s01_same( 2, count( $repin_student_rubrics ), 'Policy repin must preserve the operator rubric without adding a package rubric.' );
$repin_active_policies = array_values(
	array_filter(
		$repin_database->tables['oj_ai_evaluation_policy_revisions'],
		static fn ( array $row ): bool => Assessment_Configuration_Profile::STUDENT_SUBMISSION === $row['subject_type']
			&& Assessment_Configuration_Profile::STATE_ACTIVE === $row['state']
	)
);
configuration_s01_same( 1, count( $repin_active_policies ), 'Policy repin must leave exactly one active student policy.' );
$repin_policy_value = json_decode( $repin_active_policies[0]['policy_json'], true, 64, JSON_THROW_ON_ERROR );
configuration_s01_same( $operator_rubric_id, $repin_policy_value['rubric_revision_id'], 'Policy repin must select the operator active rubric.' );
$repin_audit    = $repin_database->tables['oj_ai_audit_events'][ array_key_last( $repin_database->tables['oj_ai_audit_events'] ) ];
$repin_metadata = json_decode( $repin_audit['metadata_json'], true, 64, JSON_THROW_ON_ERROR );
configuration_s01_same( false, $repin_metadata['rubric_appended'], 'Policy-only recovery must be explicit in audit metadata.' );
configuration_s01_same( array( 'START TRANSACTION', 'COMMIT' ), $repin_database->transactions, 'Policy repin must commit atomically.' );
$unknown_database   = new Configuration_S01_Database();
$unknown_repository = new WordPress_Assessment_Configuration_Repository( $canonical, new Public_Id_Generator(), $profile, $unknown_database );
$unknown_repository->seed_defaults( 0, 'activation:configuration-unknown-source' );
configuration_s01_install_historical_prompt( $unknown_database, $canonical, 'worksheet_quality_prompt.v99', str_repeat( 'f', 64 ) );
$unknown_policy_count = count( $unknown_database->tables['oj_ai_evaluation_policy_revisions'] );
$unknown_audit_count  = count( $unknown_database->tables['oj_ai_audit_events'] );
$unknown_rejected     = false;
try {
	$unknown_repository->seed_defaults( 0, 'upgrade:configuration-unknown' );
} catch ( Assessment_Configuration_Failure $failure ) {
	$unknown_rejected = Assessment_Configuration_Failure::STORED_DATA_INVALID === $failure->reason();
}
configuration_s01_assert( $unknown_rejected, 'Unknown prompt descriptors must fail closed.' );
configuration_s01_same( $unknown_policy_count, count( $unknown_database->tables['oj_ai_evaluation_policy_revisions'] ), 'Unknown prompt rejection must not append a policy.' );
configuration_s01_same( $unknown_audit_count, count( $unknown_database->tables['oj_ai_audit_events'] ), 'Unknown prompt rejection must not append an audit.' );

configuration_s01_same( null, $database->tables['oj_ai_evaluation_policy_revisions'][0]['activated_by_user_id'], 'Package seed must not invent a human activator.' );
configuration_s01_same( array( 'START TRANSACTION', 'COMMIT' ), $database->transactions, 'Fresh seed must commit atomically.' );
$preserved = $repository->seed_defaults( 0, 'activation:configuration-s02' );
configuration_s01_same(
	array(
		'worksheet_quality'  => 'preserved',
		'student_submission' => 'preserved',
	),
	$preserved,
	'Reactivation must preserve existing revisions.'
);
configuration_s01_same( 2, count( $database->tables['oj_ai_evaluation_policy_revisions'] ), 'Reactivation must not duplicate policy revisions.' );

$partial_database   = new Configuration_S01_Database();
$partial_repository = new WordPress_Assessment_Configuration_Repository( $canonical, new Public_Id_Generator(), $profile, $partial_database );
$partial_repository->seed_defaults( 0, 'activation:configuration-partial-source' );
foreach ( array( 'oj_ai_evaluation_policy_revisions', 'oj_ai_review_rule_revisions' ) as $table ) {
	$partial_database->tables[ $table ] = array_values(
		array_filter(
			$partial_database->tables[ $table ],
			static fn ( array $row ): bool => 'worksheet_quality' !== $row['subject_type']
		)
	);
}
$partial_database->tables['oj_ai_audit_events'] = array();
$partial_database->transactions                 = array();
$repaired                                       = $partial_repository->seed_defaults( 0, 'activation:configuration-partial-repair' );
configuration_s01_same(
	array(
		'worksheet_quality'  => 'repaired',
		'student_submission' => 'preserved',
	),
	$repaired,
	'Partial installation must fill only missing subject resources.'
);
configuration_s01_same( 2, count( $partial_database->tables['oj_ai_evaluation_policy_revisions'] ), 'Partial repair must restore the missing policy.' );
configuration_s01_same( 2, count( $partial_database->tables['oj_ai_review_rule_revisions'] ), 'Partial repair must restore the missing review rule.' );
configuration_s01_same( 2, count( $partial_database->tables['oj_ai_rubric_revisions'] ), 'Partial repair must not duplicate the existing rubric.' );
configuration_s01_same( 1, count( $partial_database->tables['oj_ai_audit_events'] ), 'Partial repair needs one redacted audit for the repaired subject.' );
configuration_s01_same( array( 'START TRANSACTION', 'COMMIT' ), $partial_database->transactions, 'Partial repair must commit atomically.' );

$events   = array();
$workflow = new Activation_Workflow(
	new Configuration_S01_Installer( $events ),
	static function () use ( &$events ): bool {
		$events[] = 'version';
		return true;
	},
	static function ( int $actor_user_id, string $correlation_id ) use ( &$events ): bool {
		configuration_s01_same( 42, $actor_user_id, 'Activation bootstrap actor drifted.' );
		configuration_s01_same( 'activation:workflow-s01', $correlation_id, 'Activation bootstrap correlation drifted.' );
		$events[] = 'configuration';
		return true;
	}
);
configuration_s01_same( null, $workflow->run( 42, 'activation:workflow-s01', '0.1.7' ), 'Verified bootstrap should activate.' );
configuration_s01_same( array( 'install', 'configuration', 'version' ), $events, 'Schema, configuration, and version order drifted.' );

$blocked_version_writes = 0;
$workflow               = new Activation_Workflow(
	new Configuration_S01_Installer( $events ),
	static function () use ( &$blocked_version_writes ): bool {
		++$blocked_version_writes;
		return true;
	},
	static fn (): bool => false
);
$problem                = $workflow->run( 42, 'activation:workflow-s02', '0.1.7' );
configuration_s01_same( 'assessment_configuration_bootstrap_failed', $problem['code'], 'Bootstrap failure code drifted.' );
configuration_s01_same( 0, $blocked_version_writes, 'Version must not be stored after bootstrap failure.' );

$port    = new Configuration_S01_Repository_Port();
$manager = new Manage_Assessment_Configuration( $port, $profile, $canonical );
$manager->create(
	Assessment_Configuration_Profile::POLICY,
	42,
	'command:configuration-s01',
	1,
	array(
		'subject_type' => 'student_submission',
		'value'        => $defaults['student_submission']['policy']['value'],
	),
	str_repeat( 'a', 64 ),
	'configuration:manager-s01'
);
configuration_s01_assert( is_array( $port->last_create ), 'Create use case must reach the repository.' );
configuration_s01_assert( 1 === preg_match( '/^configuration:[a-f0-9]{64}$/D', $port->last_create['idempotency_storage_key'] ), 'Create idempotency storage key drifted.' );
configuration_s01_assert( 1 === preg_match( '/^[a-f0-9]{64}$/D', $port->last_create['request_digest'] ), 'Create request digest drifted.' );

$correlations = new Correlation_Id_Factory();
$envelopes    = new Rest_Envelope_Factory();
$handoff      = new Rest_Permission_Handoff(
	new WordPress_Rest_Request_Adapter( new Configuration_S01_Actor() ),
	new Rest_Request_Authorizer( new Configuration_S01_Policy(), new Configuration_S01_Nonce(), $correlations ),
	$envelopes,
	$correlations
);
$controller   = new Assessment_Configuration_Rest_Controller( $handoff, $manager, $envelopes, $correlations );
$controller->register_routes();
configuration_s01_same( 9, count( $GLOBALS['configuration_s01_routes'] ), 'Q25-Q27 and C55-C60 must register exactly nine REST routes.' );
$method_counts = array(
	'GET'  => 0,
	'POST' => 0,
);
foreach ( $GLOBALS['configuration_s01_routes'] as $route ) {
	configuration_s01_same( 'oj-ai/v1', $route['namespace'], 'Configuration REST namespace drifted.' );
	++$method_counts[ $route['arguments']['methods'] ];
}
configuration_s01_same(
	array(
		'GET'  => 3,
		'POST' => 6,
	),
	$method_counts,
	'Configuration query and command method counts drifted.'
);

echo "Assessment configuration bootstrap contract passed.\n";
echo "- two safe package-owned subject seeds\n";
echo "- prefix-free literal tables and idempotent reactivation\n";
echo "- schema -> configuration -> version activation order\n";
echo "- Q25-Q27 and C55-C60 route registration\n";
