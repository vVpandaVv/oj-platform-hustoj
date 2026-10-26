<?php
/**
 * Malformed report sources must fail before aggregation without PHP warnings.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require __DIR__ . '/worksheet-report-list-g5-imp-01-s01.php';
require_once ABSPATH . 'src/Reporting/Report/Domain/Student_Roster_Report_Projector.php';

use OJAI\Dashboard\Reporting\Report\Application\Read_Worksheet_Reports;
use OJAI\Dashboard\Reporting\Report\Application\Report_Query_Failure;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Source_Validator;
use OJAI\Dashboard\Reporting\Report\Domain\Student_Roster_Report_Projector;

set_error_handler(
	static function ( int $severity, string $message ): never {
		throw new RuntimeException( $message, $severity );
	}
);
try {
	foreach ( array(
		null,
		false,
		12,
		'bad',
		new stdClass(),
		array( 'list item' ),
		array(
			'field' => 'value',
			4       => 'mixed key',
		),
	) as $invalid ) {
		q20_same( false, Report_Source_Validator::records( array( $invalid ), 1 ), 'Malformed source record accepted.' );
		q20_same(
			false,
			Report_Source_Validator::detail_page(
				array(
					'source'      => $invalid,
					'next_cursor' => '',
				),
				191
			),
			'Malformed detail source accepted.'
		);
		try {
			( new Student_Roster_Report_Projector( $canonical ) )->project( array( $invalid ), array() );
			throw new RuntimeException( 'Malformed student row escaped report validation.' );
		} catch ( Report_Query_Failure $failure ) {
			q20_same( Report_Query_Failure::STORED_DATA_INVALID, $failure->reason(), 'Malformed student rows must not cause a PHP TypeError.' );
		}
	}
	q20_same(
		true,
		Report_Source_Validator::detail_page(
			array(
				'source'      => array( 'id' => 'source' ),
				'next_cursor' => '',
			),
			191
		),
		'Valid detail envelope rejected.'
	);
	q20_same( false, Report_Source_Validator::detail_page( array( 'source' => array() ), 191 ), 'Missing cursor accepted.' );
	q20_same( true, Report_Source_Validator::records( array(), 0 ), 'Empty list should remain valid.' );

	$bad_pages = array(
		array(),
		array(
			'items'       => null,
			'next_cursor' => '',
		),
		array(
			'items'       => new stdClass(),
			'next_cursor' => '',
		),
		array(
			'items'       => array( 'key' => array() ),
			'next_cursor' => '',
		),
		array(
			'items'       => array( null ),
			'next_cursor' => '',
		),
		array(
			'items'       => array( 'invalid' ),
			'next_cursor' => '',
		),
		array(
			'items'       => array( new stdClass() ),
			'next_cursor' => '',
		),
		array(
			'items'       => array( array(), array() ),
			'next_cursor' => '',
		),
		array(
			'items'       => array(),
			'next_cursor' => array(),
		),
		array(
			'items'       => array(),
			'next_cursor' => str_repeat( 'x', 192 ),
		),
		array(
			'items'       => array(),
			'next_cursor' => null,
		),
		array( 'items' => array() ),
	);
	foreach ( $bad_pages as $bad_page ) {
		q20_same( false, Report_Source_Validator::list_page( $bad_page, 1, 191 ), 'Malformed list envelope accepted.' );
		$reader = new Read_Worksheet_Reports(
			new Q20_Fixed_Progress( $bad_page ),
			new Q20_Fixed_Worksheets( $worksheet_sources ),
			new Q20_Fixed_Assessments( $assessment_sources ),
			$projector,
			$canonical
		);
		try {
			$reader->list( 77, 'class', 'class-q20', 'revision-q20', '', 1 );
			throw new RuntimeException( 'Malformed report source escaped application validation.' );
		} catch ( Report_Query_Failure $failure ) {
			q20_same( Report_Query_Failure::STORED_DATA_INVALID, $failure->reason(), 'Malformed envelope must use the stable stored-data failure.' );
		}
	}
} finally {
	restore_error_handler();
}

echo "Report source validation PASS: valid envelopes and malformed page, row and cursor rejection.\n";
