<?php
/**
 * Registers only a server-side bridge and an AI-owned cron worker.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\ExternalAssessment;

use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskRequestV1;
use OJAI\Dashboard\Contracts\Module_Interface;
use OJAI\Dashboard\Platform\Addon_Runtime_Bridge;
use OJAI\Dashboard\Storage\Infrastructure\Local_Private_File_Store;
use Throwable;

/** Assessment Module boundary. */
final class Assessment_Module implements Module_Interface {
	public const HOOK = 'oj_ai_external_ml_assessment_sweep';
	public const WAKE = 'oj_ai_external_ml_assessment_wakeup';
	/**
	 * Gateway state.
	 *
	 * @var Assessment_Gateway_V1
	 */
	private Assessment_Gateway_V1 $gateway;
	/**
	 * Store state.
	 *
	 * @var WordPress_Assessment_Store
	 */
	private WordPress_Assessment_Store $store;

	/**
	 * Connect the owned persistence and execution dependencies.
	 *
	 * @param Addon_Runtime_Bridge     $runtime runtime.
	 * @param Local_Private_File_Store $files files.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct( private Addon_Runtime_Bridge $runtime, Local_Private_File_Store $files ) {
		$this->store   = new WordPress_Assessment_Store( $files );
		$this->gateway = new Assessment_Gateway_V1(
			$this->store,
			static fn(): mixed => apply_filters( 'oj_ai_external_assessment_sources_v1', array() ),
			fn( string $task ): ?array => $this->routing( $task ),
			fn( array $run, array $input ): array => $this->execute( $run, $input ),
			static function (): void {
				if ( false === wp_next_scheduled( self::WAKE ) ) {
					wp_schedule_single_event( time() + 1, self::WAKE ); }
			}
		);
	}

	/**
	 * Register the optional bridge and bounded cron worker.
	 *
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function register(): void {
		add_filter( 'oj_ai_external_assessment_gateway_v1', fn( mixed $prior ): mixed => $this->runtime->is_ready() ? $this->gateway : $prior );
		// phpcs:disable WordPress.WP.CronInterval.CronSchedulesInterval -- One leased assessment is processed per bounded worker wakeup.
		add_filter(
			'cron_schedules',
			static function ( array $schedules ): array { // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- One leased assessment is processed per bounded worker wakeup.
				$schedules['oj_ai_dashboard_every_minute'] = array(
					// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- One leased assessment is processed per bounded worker wakeup.
					'interval' => 60,
					'display'  => 'OJ AI assessment every minute',
				);
				return $schedules;
			}
		);
		// phpcs:enable WordPress.WP.CronInterval.CronSchedulesInterval
		add_action(
			'init',
			function (): void {
				if ( $this->runtime->is_ready() && false === wp_next_scheduled( self::HOOK ) ) {
					wp_schedule_event( time() + 60, 'oj_ai_dashboard_every_minute', self::HOOK );
				}
			}
		);
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( self::WAKE, array( $this, 'run' ) );
	}

	/**
	 * Process the private queue without leaking provider or storage errors.
	 *
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function run(): void {
		if ( ! $this->runtime->is_ready() ) {
			return; }
		try {
			$this->store->cleanup();
		} catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Expired file cleanup must not block unrelated assessment jobs; the next sweep retries it.
			/* Retain metadata for a later cleanup attempt. */
		}
		try {
			$this->gateway->run_once();
		} catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Durable metadata and lease expiry preserve interrupted work without logging student content.
			/* Metadata lease permits a safe manual retry. */
		}
	}

	/**
	 * Unschedule the shared worker hooks, retaining their historical names.
	 *
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function clear(): void {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::HOOK );
			wp_clear_scheduled_hook( self::WAKE );
		}
	}

	/**
	 * Read the platform-owned routing snapshot without exposing credentials.
	 *
	 * @param string $task Owned task key.
	 * @return array|null
	 */
	private function routing( string $task ): ?array {
		try {
			$gateway = $this->runtime->ai_tasks();
			if ( null === $gateway ) {
				return null; }
			$snapshot = $gateway->snapshot( $task );
			return $snapshot->isAvailable() ? array( 'fingerprint' => self::fingerprint( $snapshot ) ) : null;
		} catch ( Throwable ) {
			return null; }
	}

	/**
	 * Bind consent to the exact provider, model, profile, and routing revision.
	 *
	 * @param object $snapshot snapshot.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private static function fingerprint( object $snapshot ): string {
		return hash( 'sha256', Assessment_Profile::encode( array( $snapshot->taskKey(), $snapshot->providerKey(), $snapshot->modelKey(), $snapshot->profileFingerprint(), $snapshot->routingRevision() ) ) );
	}

	/**
	 * Execute only through the public platform AI task gateway.
	 *
	 * @param array $run run.
	 * @param array $input input.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function execute( array $run, array $input ): array {
		$gateway = $this->runtime->ai_tasks();
		if ( null === $gateway ) {
			return array( 'success' => false ); }
		$profile = Assessment_Profile::for_source( $run['source'] );
		$result  = $gateway->execute(
			new AddonAiTaskRequestV1(
				$profile::TASK,
				$profile::CONTRACT,
				$run['actor_id'],
				'ml-assessment:' . $run['run_id'],
				$input
			)
		);
		if ( ! $result->isSuccessful() ) {
			return array( 'success' => false ); }
		return array(
			'success'             => true,
			'output'              => $result->output(),
			'routing_fingerprint' => self::fingerprint( $result->routingSnapshot() ),
		);
	}
}
