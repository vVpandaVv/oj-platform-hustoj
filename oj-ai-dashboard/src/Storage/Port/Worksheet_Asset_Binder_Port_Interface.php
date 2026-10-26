<?php
/**
 * Worksheet asset transaction participant.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Storage\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verifies worksheet-owned private files and promotes staged receipts.
 */
interface Worksheet_Asset_Binder_Port_Interface {
	/**
	 * The caller owns the surrounding database transaction.
	 *
	 * @param int                            $actor_user_id Authenticated actor from the access context.
	 * @param string                         $worksheet_public_id Worksheet public id.
	 * @param array<int,array<string,mixed>> $asset_bindings Asset bindings.
	 * @param array<int,string>              $staged_receipt_ids Staged receipt ids.
	 * @param string                         $saved_at_utc Saved at utc.
	 * @return array<int,array<string,mixed>>
	 */
	public function bind_for_revision(
		int $actor_user_id,
		string $worksheet_public_id,
		array $asset_bindings,
		array $staged_receipt_ids,
		string $saved_at_utc
	): array;
}
