<?php

/**
 * Humaniq installable-app controller.
 *
 * Serves the two static assets a phone browser needs to install `Mijn HR` as
 * a web app: the web manifest and the service worker.
 *
 * @category  Controller
 * @package   OCA\Humaniq\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * @spec openspec/changes/self-service-mobile/specs/self-service-mobile/spec.md#requirement-mijn-hr-shall-be-installable-on-a-phones-home-screen-req-mob-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use OCA\Humaniq\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\Defaults;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * The web manifest and the service worker of the installable `Mijn HR`.
 *
 * Both are public and need no CSRF token: they carry no data, and a browser
 * fetches them before any session exists (self-service-mobile design.md D1).
 */
class PwaController extends Controller {

	/**
	 * The built service worker, relative to the app directory.
	 */
	public const SERVICE_WORKER_FILE = 'js/humaniq-service-worker.js';

	/**
	 * The home screen icon sizes, each shipped as img/pwa-icon-<size>.png.
	 */
	public const ICON_SIZES = [192, 512];

	/**
	 * Constructor.
	 *
	 * @param IRequest      $request      The request.
	 * @param IURLGenerator $urlGenerator Builds the app's URLs.
	 * @param IAppManager   $appManager   Locates the app directory.
	 * @param Defaults      $defaults     The instance theme (colours).
	 */
	public function __construct(
		IRequest $request,
		private readonly IURLGenerator $urlGenerator,
		private readonly IAppManager $appManager,
		private readonly Defaults $defaults,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The web manifest: `Mijn HR`, opening on /mijn, scoped to the app.
	 *
	 * @return DataDisplayResponse The manifest as application/manifest+json.
	 *
	 * @spec openspec/changes/self-service-mobile/specs/self-service-mobile/spec.md#requirement-mijn-hr-shall-be-installable-on-a-phones-home-screen-req-mob-001
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 60)]
	public function manifest(): DataDisplayResponse {
		$scope = $this->scope();
		$colour = $this->defaults->getColorPrimary();

		$icons = [];
		foreach (self::ICON_SIZES as $size) {
			$icons[] = [
				'src' => $this->urlGenerator->imagePath(appName: Application::APP_ID, file: 'pwa-icon-'.$size.'.png'),
				'sizes' => $size.'x'.$size,
				'type' => 'image/png',
				'purpose' => 'any maskable',
			];
		}

		$manifest = [
			'name' => 'Mijn HR',
			'short_name' => 'Mijn HR',
			'id' => $scope.'mijn',
			'start_url' => $scope.'mijn',
			'scope' => $scope,
			'display' => 'standalone',
			'background_color' => $colour,
			'theme_color' => $colour,
			'icons' => $icons,
		];

		$response = new DataDisplayResponse(
			data: (string) json_encode($manifest, (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
			statusCode: Http::STATUS_OK,
			headers: ['Content-Type' => 'application/manifest+json']
		);

		return $response;
	}//end manifest()

	/**
	 * The service worker, allowed to control the whole app scope.
	 *
	 * A service worker takes the content security policy of its OWN script
	 * response, and Nextcloud's default for a controller response has no
	 * `connect-src`, under which every fetch the worker makes is blocked. The
	 * policy therefore allows the worker to talk back to this Nextcloud, and
	 * nothing else.
	 *
	 * @return DataDisplayResponse The worker script, or a 404 when it is not built.
	 *
	 * @spec openspec/changes/self-service-mobile/specs/self-service-mobile/spec.md#requirement-the-app-shall-keep-no-personal-data-on-the-phone-req-mob-002
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 60)]
	public function serviceWorker(): DataDisplayResponse {
		$path = $this->appManager->getAppPath(appId: Application::APP_ID).'/'.self::SERVICE_WORKER_FILE;
		if (is_file($path) === false || is_readable($path) === false) {
			return new DataDisplayResponse(
				data: '',
				statusCode: Http::STATUS_NOT_FOUND,
				headers: ['Content-Type' => 'application/javascript']
			);
		}

		$response = new DataDisplayResponse(
			data: (string) file_get_contents($path),
			statusCode: Http::STATUS_OK,
			headers: [
				'Content-Type' => 'application/javascript',
				'Service-Worker-Allowed' => $this->scope(),
				'Cache-Control' => 'no-cache',
			]
		);

		$csp = new EmptyContentSecurityPolicy();
		$csp->addAllowedConnectDomain(domain: '\'self\'');
		$response->setContentSecurityPolicy(csp: $csp);

		return $response;
	}//end serviceWorker()

	/**
	 * The app's URL root, ending in a slash: `/apps/humaniq/` (or with the
	 * `/index.php` prefix on an instance without pretty URLs).
	 *
	 * @return string The scope.
	 */
	private function scope(): string {
		return rtrim($this->urlGenerator->linkToRoute(routeName: Application::APP_ID.'.page.index'), '/').'/';
	}//end scope()
}//end class
