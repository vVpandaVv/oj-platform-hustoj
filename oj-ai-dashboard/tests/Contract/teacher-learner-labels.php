<?php
/**
 * Teacher-only live identity projection contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
require ABSPATH . 'vendor/autoload.php';

use OJAI\Dashboard\Submission\Teacher\Infrastructure\WordPress_Teacher_Learner_Labels;
use OJAI\Dashboard\Submission\Assignment\Infrastructure\WordPress_Learner_Display_Snapshot;

$GLOBALS['label_users'] = array(
	7 => (object) array(
		'user_login'   => 'student10101',
		'display_name' => 'Current display',
		'user_email'   => 'private@example.test',
	),
);
$GLOBALS['label_names'] = array( 7 => 'Management name' );
$GLOBALS['label_reads'] = array();

/**
 * Public WordPress user API fixture.
 *
 * @param int $id User identifier.
 * @return object|false
 */
function get_userdata( int $id ): object|false {
	$GLOBALS['label_reads'][] = $id;
	return $GLOBALS['label_users'][ $id ] ?? false;
}

/**
 * Public WordPress metadata API fixture.
 *
 * @param int    $id User identifier.
 * @param string $key Meta key.
 * @param bool   $single Single-value request.
 * @return mixed
 * @throws RuntimeException If an unrelated private field is requested.
 */
function get_user_meta( int $id, string $key, bool $single ): mixed {
	if ( 'nickname' !== $key || ! $single ) {
		throw new RuntimeException( 'Only the platform management-name field may be read.' );
	}
	return $GLOBALS['label_names'][ $id ] ?? '';
}

/**
 * Assert a contract without depending on PHP assertion configuration.
 *
 * @param bool   $ok Result.
 * @param string $message Failure message.
 * @return void
 * @throws RuntimeException On a failed assertion.
 */
function labels_assert( bool $ok, string $message ): void {
	if ( ! $ok ) {
		throw new RuntimeException( $message );
	}
}

$presenter = new WordPress_Teacher_Learner_Labels();
foreach ( array(
	'learner' => 'user_id',
	'target'  => 'learner_user_id',
) as $key => $id_key ) {
	$item = array(
		$key    => array(
			$id_key        => 7,
			'display_name' => 'Snapshot display',
		),
		'state' => 'submitted',
	);
	$copy = $presenter->present( $item );
	labels_assert(
		$copy[ $key ]['labels'] === array(
			'management_name' => 'Management name',
			'login'           => 'student10101',
			'display_name'    => 'Current display',
		),
		'Three current labels must use the platform account fields.'
	);
	labels_assert( 'Snapshot display' === $copy[ $key ]['display_name'] && ! isset( $item[ $key ]['labels'] ), 'Stored snapshots and source DTOs must not change.' );
	labels_assert( 'submitted' === $copy['state'] && ! str_contains( json_encode( $copy ), 'private@example.test' ), 'No private account fields or workflow state changes.' );
}

$deleted = $presenter->present(
	array(
		'learner' => array(
			'user_id'      => 8,
			'display_name' => 'Historical learner',
		),
	)
);
labels_assert(
	array(
		'management_name' => '',
		'login'           => '',
		'display_name'    => '',
	) === $deleted['learner']['labels'],
	'Missing accounts retain the historical label without guessed identifiers.'
);
$GLOBALS['label_names'][7] = array( 'bad' );
labels_assert( '' === $presenter->present( array( 'target' => array( 'learner_user_id' => 7 ) ) )['target']['labels']['management_name'], 'Malformed metadata must not break submission reads.' );
$GLOBALS['label_names'][7] = "\xff";
labels_assert( '' === $presenter->present( array( 'target' => array( 'learner_user_id' => 7 ) ) )['target']['labels']['management_name'], 'Invalid UTF-8 must not break JSON responses.' );
$GLOBALS['label_names'][7] = 'Updated management name';
labels_assert( 'Updated management name' === $presenter->present( array( 'target' => array( 'learner_user_id' => 7 ) ) )['target']['labels']['management_name'], 'Refresh uses current account labels, not distribution-time names.' );

$reads  = count( $GLOBALS['label_reads'] );
$source = array(
	'source'  => array( 'learner' => array( 'user_id' => 7 ) ),
	'answers' => array( 'target' => array( 'learner_user_id' => 7 ) ),
);
labels_assert( $source === $presenter->present( $source ) && $reads === count( $GLOBALS['label_reads'] ), 'Never recursively enrich answer bodies or AI source input.' );
labels_assert( array( 'display_name' => 'Current display' ) === ( new WordPress_Learner_Display_Snapshot() )->snapshot( 7 ), 'Assignment snapshots still contain only display_name.' );

echo "PASS teacher learner labels: current identities, missing/invalid metadata, immutable snapshots, minimal fields\n";
