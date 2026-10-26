<?php
/**
 * Stable target evidence shared by creation and later additions.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Assignment\Application;

use OJAI\Dashboard\Submission\Assignment\Port\Learner_Display_Snapshot_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Builds the same immutable per-learner snapshot for both commands. */
final class Assignment_Target_Factory {
	/**
	 * Initialize dependencies.
	 *
	 * @param Learner_Display_Snapshot_Port_Interface $displays Display snapshots.
	 * @param Public_Id_Generator                     $ids ID generator.
	 * @param Canonical_Json                          $canonical Canonical encoder.
	 */
	public function __construct(
		private Learner_Display_Snapshot_Port_Interface $displays,
		private Public_Id_Generator $ids,
		private Canonical_Json $canonical
	) {
	}

	/**
	 * Build one active target from validated platform evidence.
	 *
	 * @param array<string,mixed> $learner Platform learner.
	 * @param array<string,mixed> $context Authorized context.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function create( array $learner, array $context ): array {
		$target_id    = $this->ids->create()->value();
		$display      = $this->displays->snapshot( $learner['learner_user_id'] );
		$display_name = $this->canonical->text( trim( (string) ( $display['display_name'] ) ) );
		if ( '' === $display_name || strlen( $display_name ) > 255 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::DISPLAY_UNAVAILABLE );
		}
		$display_value = array( 'display_name' => $display_name );
		$display_json  = $this->canonical->encode( $display_value );
		$snapshot      = array(
			'context_id'            => $context['context_id'],
			'context_revision'      => $context['context_revision'],
			'context_type'          => $context['context_type'],
			'display_snapshot_hash' => hash( 'sha256', $display_json ),
			'learner_user_id'       => $learner['learner_user_id'],
			'membership_revision'   => $learner['membership_revision'],
			'state'                 => 'active',
		);
		$snapshot_hash = hash( 'sha256', $this->canonical->encode( $snapshot ) );
		return array(
			'public_id'                => $target_id,
			'content_hash'             => hash(
				'sha256',
				$this->canonical->encode(
					array(
						'public_id'     => $target_id,
						'snapshot_hash' => $snapshot_hash,
					)
				)
			),
			'membership_revision'      => $learner['membership_revision'],
			'learner_user_id'          => $learner['learner_user_id'],
			'display_snapshot_schema'  => 'oj_ai.assignment_target_display',
			'display_snapshot_version' => 1,
			'display_snapshot_json'    => $display_json,
			'display_snapshot_hash'    => $snapshot['display_snapshot_hash'],
			'snapshot_hash'            => $snapshot_hash,
			'state'                    => 'active',
		);
	}
}
