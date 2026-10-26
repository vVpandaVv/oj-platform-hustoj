<?php
/**
 * Curriculum catalog and worksheet learning-context contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader as AddonAutoloader;
use OJAI\Dashboard\Curriculum\Catalog\Application\Manage_Curriculum_Catalog;
use OJAI\Dashboard\Curriculum\Catalog\Domain\Curriculum_Catalog_Profile;
use OJAI\Dashboard\Curriculum\Catalog\Port\Curriculum_Catalog_Repository_Port_Interface;
use OJAI\Dashboard\Curriculum\Catalog\Presentation\Curriculum_Catalog_Rest_Controller;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;

$addon_root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );
require_once $addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core'
	. DIRECTORY_SEPARATOR . 'Autoloader.php';
( new AddonAutoloader( $addon_root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * Catalog assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function catalog_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Catalog same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function catalog_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * Curriculum catalog and worksheet learning-context contract.
 */
final class Curriculum_Catalog_Memory_Repository implements Curriculum_Catalog_Repository_Port_Interface {
	/**
	 * Curriculums.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $curriculums = array();
	/**
	 * Standards.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $standards = array();
	/**
	 * Curriculum command.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $curriculum_command = null;
	/**
	 * Standard command.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $standard_command = null;

	/**
	 * List curriculums.
	 *
	 * @param string $search Search.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array
	 */
	public function list_curriculums( string $search, int $limit ): array {
		catalog_same( '', $search, 'Empty catalog search must remain empty.' );
		catalog_same( Curriculum_Catalog_Profile::MAX_QUERY_ITEMS, $limit, 'Catalog query limit must come from the profile.' );
		return $this->curriculums;
	}

	/**
	 * List standards.
	 *
	 * @param string $curriculum_id Curriculum id.
	 * @param string $search Search.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array
	 */
	public function list_standards( string $curriculum_id, string $search, int $limit ): array {
		catalog_assert( '' !== $curriculum_id, 'Curriculum ID is required for standard lookup.' );
		catalog_same( '', $search, 'Empty standard search must remain empty.' );
		catalog_same( Curriculum_Catalog_Profile::MAX_QUERY_ITEMS, $limit, 'Standard query limit must come from the profile.' );
		return $this->standards;
	}

	/**
	 * Save curriculum.
	 *
	 * @param array $command Command.
	 * @return array
	 */
	public function save_curriculum( array $command ): array {
		$this->curriculum_command = $command;
		return array( 'operation' => 'create' );
	}

	/**
	 * Save standard.
	 *
	 * @param array $command Command.
	 * @return array
	 */
	public function save_standard( array $command ): array {
		$this->standard_command = $command;
		return array( 'operation' => 'create' );
	}
}

$canonical     = new Canonical_Json();
$profile       = new Curriculum_Catalog_Profile( $canonical );
$repository    = new Curriculum_Catalog_Memory_Repository();
$catalog       = new Manage_Curriculum_Catalog( $repository, $profile, $canonical );
$curriculum_id = '01J00000000000000000000000';
$standard_id   = '01J00000000000000000000001';

catalog_same( array(), $catalog->list_curriculums( '', Curriculum_Catalog_Profile::MAX_QUERY_ITEMS ), 'A fresh catalog must not inject preset curricula.' );
catalog_same( array(), $catalog->list_standards( $curriculum_id, '', Curriculum_Catalog_Profile::MAX_QUERY_ITEMS ), 'A fresh catalog must not inject preset standards.' );

$catalog->save_curriculum(
	7,
	'curriculum-save-1',
	null,
	array(
		'curriculum_id'  => null,
		'locale'         => 'ko-KR',
		'source_key'     => 'local-curriculum',
		'source_version' => '2026',
		'title'          => 'Local curriculum',
	),
	str_repeat( 'a', 64 ),
	'catalog-correlation-1'
);
catalog_assert( null !== $repository->curriculum_command, 'Curriculum save command was not sent to the repository.' );
catalog_same( Curriculum_Catalog_Profile::SOURCE_MANUAL, $repository->curriculum_command['prepared']['source_kind'], 'Operator entries must use the manual source protocol.' );
catalog_same( 'Local curriculum', $repository->curriculum_command['prepared']['title'], 'Runtime curriculum content must come from the request.' );

$catalog->save_standard(
	$curriculum_id,
	7,
	'standard-save-1',
	0,
	array(
		'code'        => 'LOCAL-1',
		'description' => 'Operator supplied description.',
		'grade_label' => null,
		'standard_id' => $standard_id,
		'title'       => 'Operator supplied standard',
	),
	str_repeat( 'b', 64 ),
	'catalog-correlation-2'
);
catalog_assert( null !== $repository->standard_command, 'Standard save command was not sent to the repository.' );
catalog_same( $curriculum_id, $repository->standard_command['curriculum_id'], 'Standard save must remain scoped to its curriculum.' );
catalog_same( 'Operator supplied standard', $repository->standard_command['prepared']['title'], 'Runtime standard content must come from the request.' );

$unknown_field_failed = false;
try {
	$profile->prepare_standard(
		array(
			'code'         => 'LOCAL-2',
			'description'  => '',
			'grade_label'  => null,
			'standard_id'  => null,
			'title'        => 'Standard',
			'preset_grade' => 'fixed',
		)
	);
} catch ( InvalidArgumentException ) {
	$unknown_field_failed = true;
}
catalog_assert( $unknown_field_failed, 'Unknown preset fields must be rejected.' );

catalog_same( '/curriculums', Curriculum_Catalog_Rest_Controller::ROUTE_CURRICULUMS, 'Q02 route drifted.' );
catalog_same( '/curriculums/(?P<curriculum_id>[0-9A-HJKMNP-TV-Z]{26})/standards', Curriculum_Catalog_Rest_Controller::ROUTE_STANDARDS, 'Q03 route drifted.' );
catalog_same( '/curriculums/commands/save', Curriculum_Catalog_Rest_Controller::ROUTE_CURRICULUM_SAVE, 'C80 route drifted.' );
catalog_same( '/curriculums/(?P<curriculum_id>[0-9A-HJKMNP-TV-Z]{26})/standards/commands/save', Curriculum_Catalog_Rest_Controller::ROUTE_STANDARD_SAVE, 'C81 route drifted.' );

$repository_source = file_get_contents( $addon_root . '/src/Curriculum/Catalog/Infrastructure/WordPress_Curriculum_Catalog_Repository.php' );
$workbench_source  = file_get_contents( $addon_root . '/resources/js/worksheet-authoring-workbench.js' );
$settings_source   = file_get_contents( $addon_root . '/src/Platform/External_Ai_Consent_Settings_Page_Handler.php' );
$manifest_source   = file_get_contents( $addon_root . '/src/Platform/Addon_Manifest.php' );
catalog_assert( is_string( $repository_source ) && str_contains( $repository_source, "'oj_ai_curriculums'" ), 'Curriculum storage must use the literal add-on table.' );
catalog_assert( is_string( $repository_source ) && str_contains( $repository_source, "'oj_ai_achievement_standards'" ), 'Standard storage must use the literal add-on table.' );
catalog_assert( ! str_contains( (string) $repository_source, '$wpdb->prefix' ), 'Curriculum catalog must not use a WordPress table prefix.' );
catalog_assert( ! str_contains( (string) $workbench_source, '/achievement-standards' ), 'Workbench must not call the retired achievement-standard route.' );
catalog_assert( str_contains( (string) $workbench_source, 'settings.learning_goals' ), 'Workbench must persist learning goals in the worksheet document.' );
$render = proc_open( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Runs an isolated local contract process with controlled command arguments.
	array( PHP_BINARY, $addon_root . '/tests/fixtures/render-workflow-page.php', 'criteria' ),
	array(
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	),
	$pipes
);
catalog_assert( is_resource( $render ), 'Criteria library renderer must start.' );
$settings_html = stream_get_contents( $pipes[1] );
$render_error  = stream_get_contents( $pipes[2] );
fclose( $pipes[1] );
fclose( $pipes[2] );
catalog_same( 0, proc_close( $render ), 'Criteria library render failed: ' . $render_error );
catalog_assert( str_contains( $settings_html, 'data-settings-tab="curriculum"' ), 'Criteria library must expose the achievement-standard tab.' );
catalog_assert( str_contains( $settings_html, 'aria-controls="oj-ai-settings-panel-curriculum"' ), 'Standard tab must target its panel.' );
catalog_assert( str_contains( $settings_html, 'data-settings-panel="curriculum"' ), 'Standard tab must have a reachable panel.' );
catalog_assert( str_contains( (string) $manifest_source, 'resources/js/curriculum-catalog-settings.js' ), 'Catalog JavaScript must be registered in the settings bundle.' );
catalog_assert( str_contains( (string) $manifest_source, 'resources/css/curriculum-catalog-settings.css' ), 'Catalog CSS must be registered in the settings bundle.' );

fwrite( STDOUT, "Curriculum catalog and worksheet learning-context contract passed.\n" );
