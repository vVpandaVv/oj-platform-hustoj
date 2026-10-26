<?php
/**
 * Worksheet target adapter for private asset staging.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Infrastructure;

use OJAI\Dashboard\Storage\Port\Worksheet_Asset_Target_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Repository_Port_Interface;
use UnexpectedValueException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Projects only the owner-visible state needed by the Storage use case.
 */
final class Worksheet_Asset_Target_Reader implements Worksheet_Asset_Target_Port_Interface {
	/**
	 * Worksheets.
	 *
	 * @var Worksheet_Repository_Port_Interface
	 */
	private Worksheet_Repository_Port_Interface $worksheets;

	/**
	 * Initialize worksheet asset target reader dependencies and state.
	 *
	 * @param Worksheet_Repository_Port_Interface $worksheets Worksheets.
	 */
	public function __construct( Worksheet_Repository_Port_Interface $worksheets ) {
		$this->worksheets = $worksheets;
	}

	/**
	 * Current owned target.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $worksheet_id Worksheet public identifier.
	 * @return array|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function current_owned_target( int $actor_user_id, string $worksheet_id ): ?array {
		$worksheet = $this->worksheets->find_owned_worksheet( $actor_user_id, $worksheet_id );
		if ( null === $worksheet ) {
			return null;
		}

		$state       = (string) ( $worksheet['state'] ?? '' );
		$revision_id = (string) ( $worksheet['current_revision_id'] ?? '' );
		if ( ! in_array( $state, array( 'draft', 'active' ), true ) || ! Public_Id::is_valid( $revision_id ) ) {
			throw new UnexpectedValueException( 'Worksheet asset target projection is invalid.' );
		}

		return array(
			'state'               => $state,
			'current_revision_id' => $revision_id,
		);
	}
}
