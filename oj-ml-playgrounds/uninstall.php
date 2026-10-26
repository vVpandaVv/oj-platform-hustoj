<?php
/**
 * Optional destructive database uninstall for OJ ML Playgrounds.
 *
 * Database data is retained unless an operator explicitly defines
 * OJ_ML_PLAYGROUNDS_DELETE_DATA as true before uninstalling. Even when enabled,
 * this routine removes only the fixed add-on database tables and version option.
 * Private artifact files are never deleted here; operators must retain or
 * remove them separately under their own retention and destruction policy.
 *
 * @package OJML\Playgrounds
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'OJ_ML_PLAYGROUNDS_DELETE_DATA' ) || true !== OJ_ML_PLAYGROUNDS_DELETE_DATA ) {
	return;
}

require_once __DIR__ . '/src/Core/Autoloader.php';
(new OJML\Playgrounds\Core\Autoloader(__DIR__ . '/src'))->register();
try {
    OJML\Playgrounds\Database\SchemaUpgrade::assertOwnership();
} catch (Throwable $error) {
    // Never drop shared global tables from another installation or multisite.
    return;
}
wp_clear_scheduled_hook('oj_ml_recover');
global $wpdb;
$oj_ml_bound_owner = $wpdb->get_var("SELECT payload_json FROM oj_ml_events WHERE event_type = 'installation.bound' ORDER BY id LIMIT 1");
if ($wpdb->last_error !== '' || !is_string($oj_ml_bound_owner)) {
    return; // Upgrade and verify ownership before destructive uninstall.
}

$oj_ml_playgrounds_tables = array(
	'oj_ml_reviews',
	'oj_ml_evaluations',
	'oj_ml_artifacts',
	'oj_ml_attempts',
	'oj_ml_assignment_learners',
	'oj_ml_assignment_targets',
	'oj_ml_assignments',
	'oj_ml_events',
);

foreach ( $oj_ml_playgrounds_tables as $oj_ml_playgrounds_table ) {
	if ( 1 !== preg_match( '/\Aoj_ml_[a-z_]+\z/D', $oj_ml_playgrounds_table ) ) {
		continue;
	}
	if (false === $wpdb->query( "DROP TABLE IF EXISTS `{$oj_ml_playgrounds_table}`" )) { return; } // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed allow-list above.
}

delete_option( 'oj_ml_playgrounds_db_version' );
delete_option( 'oj_ml_runtime_ownership' );
delete_option( 'oj_ml_playgrounds_migration_error' );
delete_option( 'oj_ml_playgrounds_settings' );
delete_option( 'oj_ml_teaching_datasets_v1' );
$oj_ml_template_options = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('oj_ml_template_library_') . '%'));
foreach ($oj_ml_template_options as $oj_ml_template_option) {
    if (preg_match('/^oj_ml_template_library_[1-9][0-9]*$/D', $oj_ml_template_option) === 1) { delete_option($oj_ml_template_option); }
}
