<?php
/**
 * Add-on-scoped OJ Platform Runtime bridge.
 *
 * @package OJML\Playgrounds
 */

namespace OJML\Playgrounds\Platform;

use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentRosterGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningContextGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeContextV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeSnapshotV1;
use LogicException;
use RuntimeException;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores only contracts handed to this accepted add-on through onReady.
 */
final class Addon_Runtime_Bridge {
	public const STATE_UNAVAILABLE = 'unavailable';
	public const STATE_READY       = 'ready';
	public const STATE_FAILED      = 'failed';

	/** @var string */
	private $state = self::STATE_UNAVAILABLE;

	/** @var string */
	private $code = 'addon_runtime_not_ready';

	/** @var bool */
	private $connection_attempted = false;

	/** @var AddonRuntimeSnapshotV1|null */
	private $snapshot;

	/** @var AddonContextGatewayV1|null */
	private $contexts;

	/** @var AddonAiTaskGatewayV1|null */
	private $ai_tasks;

	/**
	 * Atomically accept the public Runtime contracts.
	 *
	 * @param AddonRuntimeContextV1 $context Accepted add-on Runtime context.
	 * @return void
	 */
	public function connect( AddonRuntimeContextV1 $context ) {
		if ( $this->connection_attempted ) {
			throw new LogicException( 'The OJ ML Playgrounds Runtime bridge can only connect once.' );
		}

		$this->connection_attempted = true;

		try {
			$snapshot = $context->snapshot();
			$contexts = $context->contexts();
			$ai_tasks = $context->aiTasks();

			$this->assert_snapshot( $snapshot );
			if (
				! $contexts instanceof AddonLearningContextGatewayV1
				|| ! $contexts instanceof AddonAssignmentRosterGatewayV1
			) {
				throw new RuntimeException( 'The required Addon API 1.1 Context extensions are unavailable.' );
			}

			$this->snapshot = $snapshot;
			$this->contexts = $contexts;
			$this->ai_tasks = $ai_tasks;
			$this->state    = self::STATE_READY;
			$this->code     = '';
		} catch ( Throwable $error ) {
			$this->snapshot = null;
			$this->contexts = null;
			$this->ai_tasks = null;
			$this->state    = self::STATE_FAILED;
			$this->code     = 'addon_runtime_invalid';

			throw $error;
		}
	}

	/**
	 * Record a stable reason while keeping all Runtime access closed.
	 *
	 * @param string $code Stable diagnostic code.
	 * @return void
	 */
	public function mark_unavailable( $code ) {
		if ( $this->connection_attempted || self::STATE_READY === $this->state ) {
			return;
		}

		if ( is_string( $code ) && 1 === preg_match( '/^[a-z][a-z0-9_]{2,95}$/D', $code ) ) {
			$this->code = $code;
		}
	}

	/**
	 * @return bool
	 */
	public function is_ready() {
		return self::STATE_READY === $this->state
			&& $this->snapshot instanceof AddonRuntimeSnapshotV1
			&& $this->contexts instanceof AddonContextGatewayV1
			&& $this->contexts instanceof AddonLearningContextGatewayV1
			&& $this->contexts instanceof AddonAssignmentRosterGatewayV1
			&& $this->ai_tasks instanceof AddonAiTaskGatewayV1;
	}

	/** @return string */
	public function state() {
		return $this->state;
	}

	/** @return string */
	public function code() {
		return $this->code;
	}

	/** @return AddonRuntimeSnapshotV1|null */
	public function snapshot() {
		return $this->is_ready() ? $this->snapshot : null;
	}

	/** @return AddonContextGatewayV1|null */
	public function contexts() {
		return $this->is_ready() ? $this->contexts : null;
	}

	/** @return AddonLearningContextGatewayV1|null */
	public function learning_contexts() {
		return $this->is_ready() ? $this->contexts : null;
	}

	/** @return AddonAssignmentRosterGatewayV1|null */
	public function assignment_rosters() {
		return $this->is_ready() ? $this->contexts : null;
	}

	/** @return AddonAiTaskGatewayV1|null */
	public function ai_tasks() {
		return $this->is_ready() ? $this->ai_tasks : null;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function diagnostics() {
		$data = array(
			'state' => $this->state,
			'code'  => $this->code,
		);

		if ( $this->snapshot instanceof AddonRuntimeSnapshotV1 ) {
			$data['platform_version']  = $this->snapshot->actualPlatform();
			$data['addon_api_version'] = $this->snapshot->actualAddonApi();
			$data['catalog_revision']  = $this->snapshot->catalogRevision();
			$data['correlation_id']    = $this->snapshot->correlationId();
			$data['contributions']     = $this->snapshot->contributionCounts();
		}

		return $data;
	}

	/**
	 * Verify the accepted manifest snapshot before exposing any gateway.
	 *
	 * @param AddonRuntimeSnapshotV1 $snapshot Runtime snapshot.
	 * @return void
	 */
	private function assert_snapshot( AddonRuntimeSnapshotV1 $snapshot ) {
		$counts = $snapshot->contributionCounts();
		$expected_counts = array(
			'capability'   => 6,
			'console_page' => 3,
			'public_page'  => 3,
			'console_menu' => 3,
			'public_menu'  => 1,
			'asset_bundle' => 1,
			'ai_task'      => 0,
		);
		// Addon API 1.3 (OJ Platform 2.1+) counts local navigation: the
		// manifest then declares one menu and three console segments
		// (과제, 제출·평가, 설정).
		if ( array_key_exists( 'console_local_nav', $counts ) ) {
			$expected_counts['console_menu']      = 1;
			$expected_counts['console_local_nav'] = 3;
		}
		ksort( $counts );
		ksort( $expected_counts );

		if (
			'accepted' !== $snapshot->status()
			|| Addon_Integration::ADDON_ID !== $snapshot->addonId()
			|| OJ_ML_PLAYGROUNDS_VERSION !== $snapshot->addonVersion()
			|| Addon_Integration::PLATFORM_RANGE !== $snapshot->requiredPlatform()
			|| Addon_Integration::ADDON_API_RANGE !== $snapshot->requiredAddonApi()
			|| ! Addon_Integration::version_in_range( $snapshot->actualPlatform(), Addon_Integration::MIN_PLATFORM, Addon_Integration::MAX_PLATFORM )
			|| ! Addon_Integration::version_in_range( $snapshot->actualAddonApi(), Addon_Integration::MIN_ADDON_API, Addon_Integration::MAX_ADDON_API )
			|| $counts !== $expected_counts
		) {
			throw new RuntimeException( 'The accepted OJ ML Playgrounds Runtime snapshot is inconsistent.' );
		}
	}
}
