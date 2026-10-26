<?php
/**
 * Data and REST composition for OJ ML Playgrounds.
 *
 * @package OJML\Playgrounds
 */

declare(strict_types=1);

namespace OJML\Playgrounds\Core;

use OJML\Playgrounds\Application\AssignmentService;
use OJML\Playgrounds\Application\ArtifactPreviewService;
use OJML\Playgrounds\Application\ContextService;
use OJML\Playgrounds\Application\ReinspectionService;
use OJML\Playgrounds\Application\SubmissionService;
use OJML\Playgrounds\Database\Installer;
use OJML\Playgrounds\Database\Repository;
use OJML\Playgrounds\Http\ArtifactDownload;
use OJML\Playgrounds\Inspection\MlpxInspector;
use OJML\Playgrounds\Platform\Addon_Integration;
use OJML\Playgrounds\Platform\Addon_Runtime_Bridge;
use OJML\Playgrounds\Rest\ArtifactPreviewRoute;
use OJML\Playgrounds\Rest\Presenter;
use OJML\Playgrounds\Rest\ReinspectionRoute;
use OJML\Playgrounds\Rest\Routes;
use OJML\Playgrounds\Storage\ArtifactIntegrityVerifier;
use OJML\Playgrounds\Storage\PrivateArtifactStore;
use Throwable;
use wpdb;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the data graph only after the accepted add-on Runtime is ready.
 */
final class Composition_Root {
	/** @var bool */
	private $hooks_registered = false;

	/** @var bool */
	private $initialized = false;

	/** @var Addon_Runtime_Bridge|null */
	private $runtime;

	/** @var Routes|null */
	private $routes;

	/** @var ReinspectionRoute|null */
	private $reinspection_route;

	/** @var ArtifactDownload|null */
	private $artifact_download;

	/** @var ArtifactPreviewRoute|null */
	private $artifact_preview_route;

	/** @var string */
	private $failure_code = '';

	/**
	 * Register hooks while the root plugin file is loading.
	 *
	 * @return void
	 */
	public function register_hooks() {
		if ( $this->hooks_registered ) {
			return;
		}

		$this->hooks_registered = true;
		add_action( 'oj_ml_playgrounds_compose', array( $this, 'capture_runtime' ), 10, 2 );
		add_action( 'init', array( $this, 'initialize' ), 5 );
	}

	/**
	 * Capture the add-on-scoped bridge during plugins_loaded priority 0.
	 * Runtime access itself remains closed until the platform calls onReady.
	 *
	 * @param mixed $plugin Plugin singleton from the local composition hook.
	 * @param mixed $runtime Add-on-scoped Runtime bridge.
	 * @return void
	 */
	public function capture_runtime( $plugin, $runtime ) {
		if ( ! $plugin instanceof Plugin || ! $runtime instanceof Addon_Runtime_Bridge ) {
			$this->failure_code = 'addon_composition_invalid';
			return;
		}

		if ( $this->runtime instanceof Addon_Runtime_Bridge && $this->runtime !== $runtime ) {
			$this->failure_code = 'addon_runtime_conflict';
			return;
		}

		$this->runtime = $runtime;
	}

	/**
	 * Upgrade storage and compose REST services without allowing boot errors
	 * to escape into WordPress. No route is registered unless every required
	 * dependency and the accepted Runtime bridge are available.
	 *
	 * @return void
	 */
	public function initialize() {
		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;
		if ( ! Addon_Integration::environment_available() ) {
			$this->failure_code = 'addon_platform_unavailable';
			return;
		}

		if ( ! $this->runtime instanceof Addon_Runtime_Bridge || ! $this->runtime->is_ready() ) {
			$this->failure_code = 'addon_runtime_unavailable';
			return;
		}

		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) {
			$this->failure_code = 'addon_database_unavailable';
			return;
		}

		try {
			Installer::maybeUpgrade();

			$repository  = new Repository( $wpdb );
			$contexts    = new ContextService( $this->runtime );
			$storage     = new PrivateArtifactStore();
			$datasets = new \OJML\Playgrounds\Application\TeachingDatasetService( $repository, new \OJML\Playgrounds\Storage\TeachingAssetStore( $storage ), $contexts );
			$assignments = new AssignmentService( $repository, $contexts, $datasets );
			$inspector   = new MlpxInspector();
			$integrity   = new ArtifactIntegrityVerifier();
			$submissions  = new SubmissionService( $repository, $assignments, $storage, $inspector );
			$reinspection = new ReinspectionService( $repository, $assignments, $storage, $inspector );
			$artifact_preview = new ArtifactPreviewService( $repository, $assignments, $storage, $integrity, $inspector );
			$presenter    = new Presenter();
			$routes       = new Routes( $assignments, $submissions, $contexts, $presenter );
			$reinspection_route = new ReinspectionRoute( $reinspection, $presenter );
			$artifact_preview_route = new ArtifactPreviewRoute( $artifact_preview );
			$ai_source = new \OJML\Playgrounds\Application\AiEvidenceSource( $assignments, $artifact_preview );
			$ai_source->register();
			$ai_review_route = new \OJML\Playgrounds\Rest\AiReviewRoute(
				new \OJML\Playgrounds\Application\AiReviewService( $ai_source, $repository ), $presenter
			);
			add_action( 'rest_api_init', array( $ai_review_route, 'register' ) );
			$artifact_download = new ArtifactDownload( $repository, $assignments, $storage, $integrity );

			add_action( 'rest_api_init', array( $routes, 'register' ) );
			add_action( 'rest_api_init', array( $reinspection_route, 'register' ) );
			add_action( 'rest_api_init', array( $artifact_preview_route, 'register' ) );
			$template_library_route = new \OJML\Playgrounds\Rest\TemplateLibraryRoute( $repository );
			$reconsideration_route = new \OJML\Playgrounds\Rest\ReconsiderationRoute(
				new \OJML\Playgrounds\Application\ReconsiderationService( $repository, $assignments ), $presenter
			);
			add_action( 'rest_api_init', array( $template_library_route, 'register' ) );
			add_action( 'rest_api_init', array( $reconsideration_route, 'register' ) );
			$dataset_route = new \OJML\Playgrounds\Rest\TeachingDatasetRoute( $datasets );
			add_action( 'rest_api_init', array( $dataset_route, 'register' ) );
			$artifact_download->register();
			$this->routes             = $routes;
			$this->reinspection_route = $reinspection_route;
			$this->artifact_preview_route = $artifact_preview_route;
			$this->artifact_download  = $artifact_download;
			$this->failure_code       = '';
		} catch ( Throwable $error ) {
			$this->routes             = null;
			$this->reinspection_route = null;
			$this->artifact_preview_route = null;
			$this->artifact_download  = null;
			$this->failure_code       = 'addon_composition_failed';
			try {
				do_action( 'oj_ml_playgrounds_composition_failed', $error, $this->failure_code );
			} catch ( Throwable ) {
				// A diagnostic listener must not turn a fail-closed composition error into a fatal error.
			}
		}
	}

	/**
	 * Return safe local composition diagnostics.
	 *
	 * @return array{ready:bool,code:string,runtime:array<string,mixed>}
	 */
	public function diagnostics() {
		return array(
			'ready'   => $this->routes instanceof Routes
				&& $this->reinspection_route instanceof ReinspectionRoute
				&& $this->artifact_preview_route instanceof ArtifactPreviewRoute
				&& $this->artifact_download instanceof ArtifactDownload,
			'code'    => $this->failure_code,
			'runtime' => $this->runtime instanceof Addon_Runtime_Bridge
				? $this->runtime->diagnostics()
				: array(
					'state' => Addon_Runtime_Bridge::STATE_UNAVAILABLE,
					'code'  => 'addon_runtime_not_captured',
				),
		);
	}
}
