<?php
/**
 * Preview purge.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Purge\Application;

use OJAI\Dashboard\Database\Foundation\Schema_Catalog;
use OJAI\Dashboard\Operations\Purge\Port\Purge_Inventory_Port_Interface;
use OJAI\Dashboard\Operations\Purge\Port\Purge_Preview_Token_Port_Interface;
use OJAI\Dashboard\Operations\Purge\Port\Purge_Site_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Preview purge.
 */
final class Preview_Purge {
	public const PREVIEW_PROFILE = Purge_Preview_Builder::PROFILE;

	/**
	 * Builder.
	 *
	 * @var Purge_Preview_Builder
	 */
	private Purge_Preview_Builder $builder;

	/**
	 * Initialize preview purge dependencies and state.
	 *
	 * @param Purge_Inventory_Port_Interface     $inventory Inventory.
	 * @param Purge_Preview_Token_Port_Interface $tokens Tokens.
	 * @param Purge_Site_Port_Interface          $sites Sites.
	 * @param Schema_Catalog                     $catalog Catalog.
	 * @param Canonical_Json                     $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct(
		private Purge_Inventory_Port_Interface $inventory,
		private Purge_Preview_Token_Port_Interface $tokens,
		private Purge_Site_Port_Interface $sites,
		private Schema_Catalog $catalog,
		private Canonical_Json $canonical
	) {
		$this->builder = new Purge_Preview_Builder( $inventory, $catalog, $canonical );
	}

	/**
	 * Execute preview purge.
	 *
	 * @param Preview_Purge_Command $command Command.
	 * @return Preview_Purge_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Preview_Purge_Command $command ): Preview_Purge_Result {
		try {
			$site_id = $this->sites->site_id();
			$preview = $this->builder->build( $command->scope_type() );

			$token = $this->tokens->issue(
				$site_id,
				(string) $preview['scope_hash'],
				$preview['preview_hash'],
				$command->idempotency_key_hash()
			);

			return new Preview_Purge_Result(
				$preview,
				$token['preview_token'],
				$token['issued_at_utc'],
				$token['expires_at_utc']
			);
		} catch ( Purge_Failure $failure ) {
			throw $failure;
		} catch ( Throwable $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::INVENTORY_UNAVAILABLE, $error );
		}
	}
}
