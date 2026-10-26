<?php
/**
 * Add-on scoped OJ Platform Runtime bridge.
 *
 * @package OJAI\Dashboard
 */

namespace OJAI\Dashboard\Platform;

use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentRosterGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningContextGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeContextV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeSnapshotV1;
use LogicException;
use OJAI\Dashboard\Contracts\Platform_Context_Runtime_Port_Interface;
use RuntimeException;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores only the public Runtime contracts handed to this accepted add-on.
 */
final class Addon_Runtime_Bridge implements Platform_Context_Runtime_Port_Interface {
	public const STATE_UNAVAILABLE = 'unavailable';
	public const STATE_READY       = 'ready';
	public const STATE_FAILED      = 'failed';

	/**
	 * Current bridge state.
	 *
	 * @var string
	 */
	private $state = self::STATE_UNAVAILABLE;

	/**
	 * Stable diagnostic code.
	 *
	 * @var string
	 */
	private $code = 'addon_runtime_not_ready';

	/**
	 * Whether onReady has attempted a connection.
	 *
	 * @var bool
	 */
	private $connection_attempted = false;

	/**
	 * Accepted add-on snapshot.
	 *
	 * @var AddonRuntimeSnapshotV1|null
	 */
	private $snapshot;

	/**
	 * Platform Context gateway.
	 *
	 * @var AddonContextGatewayV1|null
	 */
	private $contexts;

	/**
	 * Platform AI task gateway.
	 *
	 * @var AddonAiTaskGatewayV1|null
	 */
	private $ai_tasks;

	/**
	 * Atomically connect the public Runtime contracts.
	 *
	 * @param AddonRuntimeContextV1 $context Accepted add-on Runtime context.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function connect( AddonRuntimeContextV1 $context ) {
		if ( $this->connection_attempted ) {
			throw new LogicException( 'The OJ AI add-on Runtime bridge can only connect once.' );
		}

		$this->connection_attempted = true;

		try {
			$snapshot = $context->snapshot();
			$contexts = $context->contexts();
			$ai_tasks = $context->aiTasks();

			$this->assert_snapshot( $snapshot );

			if ( ! $contexts instanceof AddonLearningContextGatewayV1 || ! $contexts instanceof AddonAssignmentRosterGatewayV1 ) {
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
	 * Keep the bridge unavailable with a stable reason.
	 *
	 * @param mixed $code Value to validate as a stable error code.
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
	 * Whether all required public Runtime contracts are connected.
	 *
	 * @return bool
	 */
	public function is_ready() {
		return self::STATE_READY === $this->state
			&& $this->snapshot instanceof AddonRuntimeSnapshotV1
			&& $this->contexts instanceof AddonContextGatewayV1
			&& $this->ai_tasks instanceof AddonAiTaskGatewayV1;
	}

	/**
	 * Return the current bridge state.
	 *
	 * @return string
	 */
	public function state() {
		return $this->state;
	}

	/**
	 * Return the stable diagnostic code.
	 *
	 * @return string
	 */
	public function code() {
		return $this->code;
	}

	/**
	 * Return the accepted Runtime snapshot.
	 *
	 * @return AddonRuntimeSnapshotV1|null
	 */
	public function snapshot() {
		return $this->snapshot;
	}

	/**
	 * Return the public Context gateway only when ready.
	 *
	 * @return AddonContextGatewayV1|null
	 */
	public function contexts(): ?AddonContextGatewayV1 {
		return $this->is_ready() ? $this->contexts : null;
	}

	/**
	 * Return the public AI task gateway only when ready.
	 *
	 * @return AddonAiTaskGatewayV1|null
	 */
	public function ai_tasks() {
		return $this->is_ready() ? $this->ai_tasks : null;
	}

	/**
	 * Return safe diagnostics without raw provider or callback details.
	 *
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
	 * Verify the Runtime snapshot before exposing either gateway.
	 *
	 * @param AddonRuntimeSnapshotV1 $snapshot Runtime snapshot.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_snapshot( AddonRuntimeSnapshotV1 $snapshot ) {
		$counts             = $snapshot->contributionCounts();
		$platform_local_nav = array_key_exists( 'console_local_nav', $counts );
		$expected_counts    = Addon_Manifest::contribution_counts( $platform_local_nav );
		if ( $platform_local_nav ) {
			// OJ Platform 2.1+ (Addon API 1.3) also counts the local navigation.
			$expected_counts['console_local_nav'] = Addon_Manifest::local_nav_count();
		}
		ksort( $counts );
		ksort( $expected_counts );

		if (
			'accepted' !== $snapshot->status()
			|| Addon_Integration::ADDON_ID !== $snapshot->addonId()
			|| OJ_AI_DASHBOARD_VERSION !== $snapshot->addonVersion()
			|| Addon_Integration::PLATFORM_RANGE !== $snapshot->requiredPlatform()
			|| Addon_Integration::ADDON_API_RANGE !== $snapshot->requiredAddonApi()
			|| ! self::version_in_range( $snapshot->actualPlatform(), '2.0.0-dev.33', '3.0.0' )
			|| ! self::version_in_range( $snapshot->actualAddonApi(), '1.1.0', '2.0.0' )
			|| $counts !== $expected_counts
		) {
			throw new RuntimeException( 'The accepted OJ AI add-on Runtime snapshot is inconsistent.' );
		}
	}

	/**
	 * Compare a version against an inclusive lower and exclusive upper bound.
	 *
	 * @param string $version Version.
	 * @param string $minimum Minimum.
	 * @param string $maximum Maximum.
	 * @return bool
	 */
	private static function version_in_range( $version, $minimum, $maximum ) {
		return version_compare( $version, $minimum, '>=' ) && version_compare( $version, $maximum, '<' );
	}
}
