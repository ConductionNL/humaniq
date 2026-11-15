<?php

/**
 * Unit tests for the installable-app controller.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Controller
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\PwaController;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Response;
use OCP\Defaults;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Pins what /manifest.webmanifest and /service-worker.js serve.
 *
 * @covers \OCA\Humaniq\Controller\PwaController
 */
class PwaControllerTest extends TestCase {

	/**
	 * A temporary app directory.
	 *
	 * @var string
	 */
	private string $appPath = '';

	/**
	 * Create an empty app directory.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->appPath = sys_get_temp_dir().'/humaniq-pwa-'.bin2hex(random_bytes(4));
		mkdir($this->appPath.'/js', 0777, true);
	}//end setUp()

	/**
	 * Remove the app directory.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		@unlink($this->appPath.'/'.PwaController::SERVICE_WORKER_FILE);
		@rmdir($this->appPath.'/js');
		@rmdir($this->appPath);
		parent::tearDown();
	}//end tearDown()

	/**
	 * Build the controller.
	 *
	 * @param string $appRoot What linkToRoute answers for the app's index route.
	 *
	 * @return PwaController The controller.
	 */
	private function controller(string $appRoot = '/apps/humaniq/'): PwaController {
		$urlGenerator = $this->createMock(originalClassName: IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->with('humaniq.page.index')->willReturn($appRoot);
		$urlGenerator->method('imagePath')->willReturnCallback(
			static fn (string $appName, string $file): string => '/custom_apps/'.$appName.'/img/'.$file
		);

		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('getAppPath')->with('humaniq')->willReturn($this->appPath);

		$defaults = $this->createMock(originalClassName: Defaults::class);
		$defaults->method('getColorPrimary')->willReturn('#00679e');

		return new PwaController(
			request: $this->createMock(originalClassName: IRequest::class),
			urlGenerator: $urlGenerator,
			appManager: $appManager,
			defaults: $defaults
		);
	}//end controller()

	/**
	 * The manifest opens Mijn HR, stays within the app and installs standalone.
	 *
	 * @return void
	 */
	public function testTheManifestDescribesTheInstallableMijnHr(): void {
		$response = $this->controller()->manifest();
		$manifest = json_decode($response->render(), true);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('application/manifest+json', $this->headersOf(response: $response)['Content-Type']);
		self::assertSame('Mijn HR', $manifest['name']);
		self::assertSame('Mijn HR', $manifest['short_name']);
		self::assertSame('/apps/humaniq/mijn', $manifest['start_url']);
		self::assertSame('/apps/humaniq/', $manifest['scope']);
		self::assertSame('standalone', $manifest['display']);
		self::assertSame('#00679e', $manifest['theme_color']);
		self::assertSame(
			[
				['src' => '/custom_apps/humaniq/img/pwa-icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
				['src' => '/custom_apps/humaniq/img/pwa-icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
			],
			$manifest['icons']
		);
	}//end testTheManifestDescribesTheInstallableMijnHr()

	/**
	 * Without pretty URLs the scope and start URL carry the index.php prefix.
	 *
	 * @return void
	 */
	public function testTheScopeFollowsTheInstanceUrlForm(): void {
		$manifest = json_decode($this->controller(appRoot: '/index.php/apps/humaniq')->manifest()->render(), true);

		self::assertSame('/index.php/apps/humaniq/', $manifest['scope']);
		self::assertSame('/index.php/apps/humaniq/mijn', $manifest['start_url']);
	}//end testTheScopeFollowsTheInstanceUrlForm()

	/**
	 * The built worker is served as JavaScript, allowed the app scope, and may fetch from this Nextcloud only.
	 *
	 * @return void
	 */
	public function testTheServiceWorkerIsServedWithItsScope(): void {
		file_put_contents($this->appPath.'/'.PwaController::SERVICE_WORKER_FILE, 'self.addEventListener("fetch", () => {})');

		$response = $this->controller()->serviceWorker();
		$headers = $this->headersOf(response: $response);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('self.addEventListener("fetch", () => {})', $response->render());
		self::assertSame('application/javascript', $headers['Content-Type']);
		self::assertSame('/apps/humaniq/', $headers['Service-Worker-Allowed']);
		self::assertStringContainsString("connect-src 'self'", $response->getContentSecurityPolicy()->buildPolicy());
	}//end testTheServiceWorkerIsServedWithItsScope()

	/**
	 * An unbuilt worker is a 404, not an empty script that would register and do nothing.
	 *
	 * @return void
	 */
	public function testAMissingServiceWorkerIsNotFound(): void {
		$response = $this->controller()->serviceWorker();

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame('', $response->render());
		self::assertArrayNotHasKey('Service-Worker-Allowed', $this->headersOf(response: $response));
	}//end testAMissingServiceWorkerIsNotFound()

	/**
	 * Both assets are public and need no CSRF token: a browser fetches them before any session.
	 *
	 * @return void
	 */
	public function testBothAssetsArePublic(): void {
		foreach (['manifest', 'serviceWorker'] as $method) {
			$reflection = new ReflectionMethod(PwaController::class, $method);
			self::assertCount(1, $reflection->getAttributes(PublicPage::class), $method.' is public');
			self::assertCount(1, $reflection->getAttributes(NoCSRFRequired::class), $method.' needs no CSRF token');
		}
	}//end testBothAssetsArePublic()

	/**
	 * Both routes exist and come before the SPA catch-all, which would otherwise answer them.
	 *
	 * @return void
	 */
	public function testTheRoutesPrecedeTheCatchAll(): void {
		$names = array_column((require dirname(__DIR__, 3).'/appinfo/routes.php')['routes'], 'name');

		$catchAll = array_search('page#catchAll', $names, true);
		self::assertIsInt($catchAll);
		foreach (['pwa#manifest', 'pwa#serviceWorker'] as $name) {
			$position = array_search($name, $names, true);
			self::assertIsInt($position, $name.' is routed');
			self::assertLessThan($catchAll, $position, $name.' precedes the catch-all');
		}
	}//end testTheRoutesPrecedeTheCatchAll()

	/**
	 * The headers a controller set on a response, without the server-wide defaults.
	 *
	 * @param Response $response The response.
	 *
	 * @return array<string, string> The headers.
	 */
	private function headersOf(Response $response): array {
		$property = new ReflectionProperty(Response::class, 'headers');

		return $property->getValue($response);
	}//end headersOf()
}//end class
