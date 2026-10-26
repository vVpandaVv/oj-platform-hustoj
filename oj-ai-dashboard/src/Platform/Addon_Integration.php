<?php
/**
 * OJ Platform Addon API registration entry point.
 *
 * @package OJAI\Dashboard
 */

namespace OJAI\Dashboard\Platform;

use CodingPanda\OjPlatform\AddonApi\V1\AddonManifestBuilderV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRegistrationGatewayV1;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers one atomic add-on candidate during the platform registration window.
 */
final class Addon_Integration {
	public const ADDON_ID        = 'oj-ai-dashboard';
	public const ADDON_NAMESPACE = 'oj_ai_dashboard';
	public const PLATFORM_RANGE  = '>=2.0.0-dev.33 <3.0.0';
	public const ADDON_API_RANGE = '>=1.1.0 <2.0.0';
	public const MIN_PLATFORM    = '2.0.0-dev.33';
	public const MAX_PLATFORM    = '3.0.0';
	public const MIN_ADDON_API   = '1.1.0';
	public const MAX_ADDON_API   = '2.0.0';

	/** Addon API 1.1 public interfaces. */
	private const REQUIRED_API_INTERFACES = array(
		'AddonAiTaskGatewayV1',
		'AddonAssignmentRosterGatewayV1',
		'AddonContextGatewayV1',
		'AddonLearningContextGatewayV1',
		'AddonManifestBuilderV1',
		'AddonPageHandlerV1',
		'AddonRegistrationGatewayV1',
		'AddonRuntimeContextV1',
	);

	/** Addon API 1.1 public DTOs and utility. */
	private const REQUIRED_API_CLASSES = array(
		'AddonAiTaskDefinitionV1',
		'AddonAiTaskInstructionV1',
		'AddonAiTaskRequestV1',
		'AddonAiTaskResultV1',
		'AddonAiTaskRouteDecisionV1',
		'AddonAiTaskRoutingSnapshotV1',
		'AddonApiProblemV1',
		'AddonAssetBundleDefinitionV1',
		'AddonAssignmentLearnerPageV1',
		'AddonAssignmentLearnerV1',
		'AddonAssignmentRosterQueryV1',
		'AddonCapabilityDefinitionV1',
		'AddonCapabilityKeyV1',
		'AddonConsoleMenuDefinitionV1',
		'AddonConsolePageDefinitionV1',
		'AddonContextAccessDecisionV1',
		'AddonContextAccessRequestV1',
		'AddonContextPageV1',
		'AddonContextSnapshotRequestV1',
		'AddonContextSnapshotResultV1',
		'AddonContextSnapshotV1',
		'AddonContextSummaryV1',
		'AddonLearningContextQueryV1',
		'AddonPageRequestV1',
		'AddonPageResponseV1',
		'AddonPublicMenuDefinitionV1',
		'AddonPublicPageDefinitionV1',
		'AddonRuntimeSnapshotV1',
		'AddonTeachingContextQueryV1',
	);

	/**
	 * Platform Runtime bridge.
	 *
	 * @var Addon_Runtime_Bridge
	 */
	private $runtime;

	/**
	 * Accepted onReady gate.
	 *
	 * @var Addon_Runtime_Readiness_Gate
	 */
	private $readiness;

	/**
	 * Common access policy.
	 *
	 * @var Access_Policy_Port_Interface
	 */
	private $policy;

	/**
	 * Whether the WordPress hook was registered.
	 *
	 * @var bool
	 */
	private $hook_registered = false;

	/**
	 * Feature Page handlers keyed by Manifest route.
	 *
	 * @var array<string,object>
	 */
	private $page_handlers;

	/**
	 * Initialize addon integration dependencies and state.
	 *
	 * @param Addon_Runtime_Bridge         $runtime Runtime bridge.
	 * @param Addon_Runtime_Readiness_Gate $readiness Accepted onReady gate.
	 * @param Access_Policy_Port_Interface $policy Common access policy.
	 * @param array<string,object>         $page_handlers Feature Page handlers.
	 */
	public function __construct(
		Addon_Runtime_Bridge $runtime,
		Addon_Runtime_Readiness_Gate $readiness,
		Access_Policy_Port_Interface $policy,
		array $page_handlers = array()
	) {
		$this->runtime       = $runtime;
		$this->readiness     = $readiness;
		$this->policy        = $policy;
		$this->page_handlers = $page_handlers;
	}

	/**
	 * Register the only platform lifecycle hook consumed by the add-on.
	 *
	 * @return void
	 */
	public function register_hooks() {
		if ( $this->hook_registered ) {
			return;
		}

		$this->hook_registered = true;
		add_action( 'oj_platform_register_addons', array( $this, 'register' ), 10, 1 );
	}

	/**
	 * Submit the add-on Manifest candidate.
	 *
	 * @param mixed $gateway Registration gateway supplied by OJ Platform.
	 * @return void
	 */
	public function register( $gateway ) {
		if ( ! self::api_contract_available() || ! $gateway instanceof AddonRegistrationGatewayV1 ) {
			$this->runtime->mark_unavailable( 'addon_api_unavailable' );
			return;
		}

		try {
			$api_version = $gateway->apiVersion();
			if ( ! self::version_in_range( $api_version, self::MIN_ADDON_API, self::MAX_ADDON_API ) ) {
				$this->runtime->mark_unavailable( 'addon_api_incompatible' );
				return;
			}

			$readiness     = $this->readiness;
			$policy        = $this->policy;
			$page_handlers = $this->page_handlers;
			$gateway->register(
				self::ADDON_ID,
				static function ( AddonManifestBuilderV1 $builder ) use ( $readiness, $policy, $page_handlers ) {
					Addon_Manifest::build( $builder, $readiness, $policy, $page_handlers );
				}
			);
		} catch ( Throwable $error ) {
			$this->runtime->mark_unavailable( 'addon_registration_failed' );
		}
	}

	/**
	 * Verify the complete Addon API 1.1 public symbol contract.
	 *
	 * @return bool
	 */
	public static function api_contract_available() {
		$namespace = 'CodingPanda\\OjPlatform\\AddonApi\\V1\\';

		foreach ( self::REQUIRED_API_INTERFACES as $symbol ) {
			if ( ! interface_exists( $namespace . $symbol ) ) {
				return false;
			}
		}

		foreach ( self::REQUIRED_API_CLASSES as $symbol ) {
			if ( ! class_exists( $namespace . $symbol ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Compare a version against an inclusive lower and exclusive upper bound.
	 *
	 * @param mixed  $version Version to validate.
	 * @param string $minimum Minimum.
	 * @param string $maximum Maximum.
	 * @return bool
	 */
	public static function version_in_range( $version, $minimum, $maximum ) {
		return is_string( $version )
			&& version_compare( $version, $minimum, '>=' )
			&& version_compare( $version, $maximum, '<' );
	}
}
