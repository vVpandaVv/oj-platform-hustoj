<?php
/**
 * Project workspace page.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use OJAI\Dashboard\Platform\Addon_Manifest;
use OJAI\Dashboard\Platform\Assessment_Project_Page_Handler;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;

$argv = array( __FILE__, 'projects' );
ob_start();
require dirname( __DIR__ ) . '/fixtures/render-workflow-page.php';
ob_end_clean();
$projectId   = '01K00000000000000000090001';
$worksheetId = '01HZY3M4N5P6Q7R8S9T0V1W2X3';
$repository  = new Project_Page_Test_Repository(
	array(
		'project_id' => $projectId,
		'worksheets' => array( array( 'worksheet_id' => $worksheetId ) ),
	)
);
$handler     = new Assessment_Project_Page_Handler( $policy, $repository );
$request     = static fn( array $query, int $actor = 41 ) => new AddonPageRequestV1( Addon_Manifest::CONSOLE_PROJECTS, $actor, array(), $query, 'ko_KR', 'project-page-contract' );
$check       = static function ( bool $passed, string $message ): void {
	if ( ! $passed ) {
		throw new RuntimeException( $message );
	}
};
foreach ( array( 'criteria', 'author', 'review', 'assignment', 'assessment', 'report', 'release' ) as $stage ) {
	$response = $handler->handle(
		$request(
			array(
				'project_id' => $projectId,
				'stage'      => $stage,
			)
		)
	);
	$body     = $response->body() ?? '';
	$check( 200 === ( $response->status() ), 'Stage failed: ' . $stage );
	$check( 1 === ( substr_count( $body, ' data-oj-ai-workflow ' ) ), 'Multiple tracks: ' . $stage );
	$check( ! str_contains( $body, 'data-workspace-navigation' ), 'Global work menus must not duplicate project steps: ' . $stage );
	$check( str_contains( $body, 'data-project-worksheet="' . $worksheetId . '"' ), 'Selected worksheet not resolved' );
	if ( in_array( $stage, array( 'author', 'review' ), true ) ) {
		$check( str_contains( $body, 'id="oj-ai-worksheet-authoring-app"' ), 'Real editor is missing' );
	}
	if ( in_array( $stage, array( 'assessment', 'report', 'release' ), true ) ) {
		$check( str_contains( $body, 'id="oj-ai-submission-review-app"' ), 'Submission stage missing' );
	}
}
$check(
	404 === ( $handler->handle(
		$request(
			array(
				'project_id' => $projectId,
				'stage'      => 'author',
			),
			42
		)
	)->status() ),
	'Another owner must be denied'
);
$check(
	404 === ( $handler->handle(
		$request(
			array(
				'project_id'   => $projectId,
				'stage'        => 'author',
				'worksheet_id' => '01K00000000000000000090002',
			)
		)
	)->status() ),
	'Unrelated worksheet must be denied'
);
$check( 422 === ( $handler->handle( $request( array( 'project_id' => array( $projectId ) ) ) )->status() ), 'Array project parameter must be denied' );
$check(
	422 === ( $handler->handle(
		$request(
			array(
				'project_id' => $projectId,
				'stage'      => array( 'author' ),
			)
		)
	)->status() ),
	'Array stage parameter must be denied'
);
$check(
	422 === ( $handler->handle(
		$request(
			array(
				'project_id' => $projectId,
				'stage'      => 'unknown',
			)
		)
	)->status() ),
	'Unknown stage must be denied'
);
echo "Project workspace page contract passed: one owner, seven real stages, actor/worksheet isolation, malformed parameters.\n";
