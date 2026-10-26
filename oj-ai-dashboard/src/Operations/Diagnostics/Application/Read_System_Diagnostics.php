<?php
/**
 * Q28 safe system diagnostics query.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Diagnostics\Application;

use Closure;
use OJAI\Dashboard\Database\Foundation\Port\Schema_Runtime_Readiness_Port_Interface;
use OJAI\Dashboard\Database\Foundation\Schema_Runtime_Readiness_Result;
use OJAI\Dashboard\Operations\Diagnostics\Domain\Ai_Routing_Diagnostics_Result;
use OJAI\Dashboard\Operations\Diagnostics\Domain\Bridge_Diagnostics_Result;
use OJAI\Dashboard\Operations\Diagnostics\Domain\Queue_Diagnostics_Result;
use OJAI\Dashboard\Operations\Diagnostics\Domain\Storage_Diagnostics_Result;
use OJAI\Dashboard\Operations\Diagnostics\Port\Ai_Routing_Diagnostics_Port_Interface;
use OJAI\Dashboard\Operations\Diagnostics\Port\Bridge_Diagnostics_Port_Interface;
use OJAI\Dashboard\Operations\Diagnostics\Port\Queue_Diagnostics_Port_Interface;
use OJAI\Dashboard\Operations\Diagnostics\Port\Storage_Diagnostics_Port_Interface;
use OJAI\Dashboard\Operations\SchemaRepair\Domain\Schema_Repair_State;
use OJAI\Dashboard\Operations\SchemaRepair\Port\Schema_Repair_State_Port_Interface;
use RuntimeException;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Q28 safe system diagnostics query.
 */
final class Read_System_Diagnostics {
	public const PROFILE = 'oj_ai.diagnostics@1.0.0';

	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize system diagnostics dependencies and state.
	 *
	 * @param Bridge_Diagnostics_Port_Interface          $bridge Bridge.
	 * @param Schema_Runtime_Readiness_Port_Interface    $schema Schema.
	 * @param Queue_Diagnostics_Port_Interface           $queue Queue.
	 * @param Closure|null                               $clock Clock.
	 * @param Ai_Routing_Diagnostics_Port_Interface|null $ai_routing Ai routing.
	 * @param Storage_Diagnostics_Port_Interface|null    $storage Storage.
	 * @param Schema_Repair_State_Port_Interface|null    $schema_repair Schema repair.
	 */
	public function __construct(
		private Bridge_Diagnostics_Port_Interface $bridge,
		private Schema_Runtime_Readiness_Port_Interface $schema,
		private Queue_Diagnostics_Port_Interface $queue,
		?Closure $clock = null,
		private ?Ai_Routing_Diagnostics_Port_Interface $ai_routing = null,
		private ?Storage_Diagnostics_Port_Interface $storage = null,
		private ?Schema_Repair_State_Port_Interface $schema_repair = null
	) {
		$this->clock = $clock ?? static fn (): string => gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Execute system diagnostics.
	 *
	 * @return array<string,mixed>
	 */
	public function execute(): array {
		$bridge   = $this->bridge_result();
		$schema   = $this->schema_result();
		$queue    = $this->queue_result();
		$checks   = array(
			'bridge' => array(
				'status'      => $bridge->status(),
				'label'       => $this->label( $bridge->status() ),
				'code'        => $bridge->code(),
				'next_action' => Bridge_Diagnostics_Result::READY === $bridge->status() ? 'none' : 'open_platform_operations',
				'details'     => $bridge->details(),
			),
			'schema' => array(
				'status'      => $schema->status(),
				'label'       => $this->label( $schema->status() ),
				'code'        => $schema->code(),
				'next_action' => $schema->is_ready() ? 'none' : 'check_data_structure',
				'issues'      => $schema->issue_codes(),
			),
			'queue'  => array(
				'status'      => $queue->status(),
				'label'       => $this->label( $queue->status() ),
				'code'        => $queue->code(),
				'next_action' => $this->queue_action( $queue->status() ),
				'issues'      => $queue->issue_codes(),
				'metrics'     => $queue->metrics(),
			),
		);
		$statuses = array( $bridge->status(), $schema->status(), $queue->status() );

		if ( null !== $this->storage ) {
			$storage           = $this->storage_result();
			$checks['storage'] = array(
				'status'      => $storage->status(),
				'label'       => $this->label( $storage->status() ),
				'code'        => $storage->code(),
				'next_action' => $this->storage_action( $storage ),
				'issues'      => $storage->issue_codes(),
				'path'        => $storage->path(),
				'metrics'     => $storage->metrics(),
			);
			$statuses[]        = $storage->status();
		}

		if ( null !== $this->ai_routing ) {
			$ai_routing           = $this->ai_routing_result();
			$checks['ai_routing'] = array(
				'status'      => $ai_routing->status(),
				'label'       => $this->label( $ai_routing->status() ),
				'code'        => $ai_routing->code(),
				'next_action' => $this->ai_routing_action( $ai_routing ),
				'tasks'       => $ai_routing->tasks(),
			);
			$statuses[]           = $ai_routing->status();
		}

		$status = $this->overall_status( ...$statuses );

		return array(
			'profile'        => self::PROFILE,
			'status'         => $status,
			'label'          => $this->label( $status ),
			'repair'         => $this->repair_projection(),
			'checked_at_utc' => $this->datetime( ( $this->clock )() ),
			'checks'         => $checks,
		);
	}

	/**
	 * Bridge result.
	 *
	 * @return Bridge_Diagnostics_Result
	 */
	private function bridge_result(): Bridge_Diagnostics_Result {
		try {
			return $this->bridge->inspect();
		} catch ( Throwable ) {
			return new Bridge_Diagnostics_Result(
				Bridge_Diagnostics_Result::UNAVAILABLE,
				'addon_runtime_diagnostics_failed'
			);
		}
	}

	/**
	 * Schema result.
	 *
	 * @return Schema_Runtime_Readiness_Result
	 */
	private function schema_result(): Schema_Runtime_Readiness_Result {
		try {
			return $this->schema->verify();
		} catch ( Throwable ) {
			return new Schema_Runtime_Readiness_Result(
				Schema_Runtime_Readiness_Result::UNAVAILABLE,
				'schema_runtime_verification_failed'
			);
		}
	}

	/**
	 * Queue result.
	 *
	 * @return Queue_Diagnostics_Result
	 */
	private function queue_result(): Queue_Diagnostics_Result {
		try {
			return $this->queue->inspect();
		} catch ( Throwable ) {
			return Queue_Diagnostics_Result::unavailable( 'queue_observation_failed' );
		}
	}

	/**
	 * Storage result.
	 *
	 * @return Storage_Diagnostics_Result
	 */
	private function storage_result(): Storage_Diagnostics_Result {
		try {
			return $this->storage instanceof Storage_Diagnostics_Port_Interface
				? $this->storage->inspect()
				: Storage_Diagnostics_Result::unavailable();
		} catch ( Throwable ) {
			return Storage_Diagnostics_Result::unavailable();
		}
	}

	/**
	 * Ai routing result.
	 *
	 * @return Ai_Routing_Diagnostics_Result
	 */
	private function ai_routing_result(): Ai_Routing_Diagnostics_Result {
		try {
			return $this->ai_routing instanceof Ai_Routing_Diagnostics_Port_Interface
				? $this->ai_routing->inspect()
				: Ai_Routing_Diagnostics_Result::unavailable();
		} catch ( Throwable ) {
			return Ai_Routing_Diagnostics_Result::unavailable();
		}
	}

	/**
	 * Repair projection.
	 *
	 * @return array{available:bool,code:string,expected:?array{installation_state:string,catalog_fingerprint:string,repair_state_hash:string}}
	 */
	private function repair_projection(): array {
		if ( ! $this->schema_repair instanceof Schema_Repair_State_Port_Interface ) {
			return Schema_Repair_State::not_required()->projection();
		}
		try {
			return $this->schema_repair->inspect()->projection();
		} catch ( Throwable ) {
			return Schema_Repair_State::blocked( 'schema_repair_state_unavailable' )->projection();
		}
	}

	/**
	 * Overall status.
	 *
	 * @param string ...$statuses Statuses.
	 * @return string
	 */
	private function overall_status( string ...$statuses ): string {
		if ( in_array( Queue_Diagnostics_Result::UNAVAILABLE, $statuses, true ) ) {
			return Queue_Diagnostics_Result::UNAVAILABLE;
		}
		if ( in_array( Queue_Diagnostics_Result::ATTENTION, $statuses, true ) ) {
			return Queue_Diagnostics_Result::ATTENTION;
		}
		return Queue_Diagnostics_Result::READY;
	}

	/**
	 * Label.
	 *
	 * @param string $status Status.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function label( string $status ): string {
		return match ( $status ) {
			Queue_Diagnostics_Result::READY => '사용 준비됨',
			Queue_Diagnostics_Result::ATTENTION => '확인 필요',
			Queue_Diagnostics_Result::UNAVAILABLE => '사용할 수 없음',
			default => throw new RuntimeException( 'Unknown diagnostic status label.' ),
		};
	}

	/**
	 * Queue action.
	 *
	 * @param string $status Status.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function queue_action( string $status ): string {
		return match ( $status ) {
			Queue_Diagnostics_Result::READY => 'none',
			Queue_Diagnostics_Result::ATTENTION => 'check_jobs',
			Queue_Diagnostics_Result::UNAVAILABLE => 'resume_jobs',
			default => throw new RuntimeException( 'Unknown queue diagnostic action.' ),
		};
	}

	/**
	 * Storage action.
	 *
	 * @param Storage_Diagnostics_Result $result Result.
	 * @return string
	 */
	private function storage_action( Storage_Diagnostics_Result $result ): string {
		return match ( $result->code() ) {
			'storage_runtime_ready' => 'none',
			'storage_scanner_unavailable' => 'check_file_scanner',
			'storage_schedule_inactive',
			'storage_metadata_inconsistent',
			'storage_scan_delayed',
			'storage_cleanup_delayed' => 'resume_storage_jobs',
			'storage_path_not_private',
			'storage_path_not_readable',
			'storage_path_not_writable',
			'storage_space_unavailable' => 'check_private_storage',
			default => 'retry_storage_check',
		};
	}

	/**
	 * Ai routing action.
	 *
	 * @param Ai_Routing_Diagnostics_Result $result Result.
	 * @return string
	 */
	private function ai_routing_action( Ai_Routing_Diagnostics_Result $result ): string {
		return match ( $result->code() ) {
			'ai_routing_ready' => 'none',
			'ai_routing_configuration_required' => 'open_platform_ai_assignments',
			'ai_routing_refresh_required' => 'refresh_ai_routing',
			'ai_routing_observation_failed' => 'open_platform_operations',
			default => 'retry_ai_routing',
		};
	}

	/**
	 * Datetime.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function datetime( $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			throw new RuntimeException( 'System diagnostics clock is invalid.' );
		}
		return $value;
	}
}
