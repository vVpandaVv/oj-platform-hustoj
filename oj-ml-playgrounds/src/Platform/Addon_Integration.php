<?php
/**
 * OJ Platform Addon API registration entry point.
 *
 * @package OJML\Playgrounds
 */

namespace OJML\Playgrounds\Platform;

use CodingPanda\OjPlatform\AddonApi\V1\AddonManifestBuilderV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRegistrationGatewayV1;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers one atomic candidate during the platform registration window.
 */
final class Addon_Integration {
	public const ADDON_ID        = 'oj-ml-playgrounds';
	public const ADDON_NAMESPACE = 'oj_ml_playgrounds';
	public const PLATFORM_RANGE  = '>=2.0.0-dev.33 <3.0.0';
	public const ADDON_API_RANGE = '>=1.1.0 <2.0.0';
	public const MIN_PLATFORM    = '2.0.0-dev.33';
	public const MAX_PLATFORM    = '3.0.0';
	public const MIN_ADDON_API   = '1.1.0';
	public const MAX_ADDON_API   = '2.0.0';

	/** @var string[] */
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

	/** @var string[] */
	private const REQUIRED_API_CLASSES = array(
		'AddonAssetBundleDefinitionV1',
		'AddonCapabilityDefinitionV1',
		'AddonCapabilityKeyV1',
		'AddonConsoleMenuDefinitionV1',
		'AddonConsolePageDefinitionV1',
		'AddonPageRequestV1',
		'AddonPageResponseV1',
		'AddonPublicMenuDefinitionV1',
		'AddonPublicPageDefinitionV1',
		'AddonRuntimeSnapshotV1',
	);

	/** @var Addon_Runtime_Bridge */
	private $runtime;

	/** @var bool */
	private $hook_registered = false;

	/** @param Addon_Runtime_Bridge $runtime Runtime bridge. */
	public function __construct( Addon_Runtime_Bridge $runtime ) {
		$this->runtime = $runtime;
	}

	/**
	 * Register the only OJ Platform lifecycle hook consumed by this add-on.
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
	 * Submit the complete manifest candidate.
	 *
	 * @param mixed $gateway Registration gateway supplied by OJ Platform.
	 * @return void
	 */
	public function register( $gateway ) {
		if ( ! self::environment_available() || ! $gateway instanceof AddonRegistrationGatewayV1 ) {
			$this->runtime->mark_unavailable( 'addon_api_unavailable' );
			return;
		}

		try {
			if ( ! self::version_in_range( $gateway->apiVersion(), self::MIN_ADDON_API, self::MAX_ADDON_API ) ) {
				$this->runtime->mark_unavailable( 'addon_api_incompatible' );
				return;
			}

			$runtime = $this->runtime;
			$gateway->register(
				self::ADDON_ID,
				static function ( AddonManifestBuilderV1 $builder ) use ( $runtime ) {
					Addon_Manifest::build( $builder, $runtime );
				}
			);
		} catch ( Throwable $error ) {
			$this->runtime->mark_unavailable( 'addon_registration_failed' );
		}
	}

	/**
	 * Verify constants, versions, and every public API symbol used here.
	 *
	 * @return bool
	 */
	public static function environment_available() {
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			return false;
		}
		return self::api_contract_available()
			&& defined( 'OJ_PLATFORM_VERSION' )
			&& defined( 'OJ_PLATFORM_ADDON_API_VERSION' )
			&& self::version_in_range( (string) OJ_PLATFORM_VERSION, self::MIN_PLATFORM, self::MAX_PLATFORM )
			&& self::version_in_range( (string) OJ_PLATFORM_ADDON_API_VERSION, self::MIN_ADDON_API, self::MAX_ADDON_API );
	}

	/**
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
	 * @param string $version Version to test.
	 * @param string $minimum Inclusive minimum.
	 * @param string $maximum Exclusive maximum.
	 * @return bool
	 */
	public static function version_in_range( $version, $minimum, $maximum ) {
		return is_string( $version )
			&& version_compare( $version, $minimum, '>=' )
			&& version_compare( $version, $maximum, '<' );
	}
}
