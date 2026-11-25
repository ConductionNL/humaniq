<?php

/**
 * Unit tests for PageController.
 *
 * Pins the #64 correction to REQ-MULTI-004: `index()` stamps the caller's
 * active administratie id as initial state (`IInitialState`) so the
 * frontend's `cnWorkspaceContext` (App.vue's SPA-root provide) seeds
 * correctly on first paint, WITHOUT the wrongly-assumed upstream
 * `@administration` nextcloud-vue token (REQ-MULTI-005, superseded). An
 * unset active administratie (never switched) MUST stamp no `activeAdministrationId`,
 * so `loadState()` falls back to `''` client-side and the `?`-optional
 * `@workspace.activeAdministrationId?` manifest filters drop the clause.
 *
 * single-person-modes (REQ-SPM-002/D2): `index()` additionally ALWAYS stamps
 * the resolved `activeAdministrationMode` (defaulting `standard`), so
 * `App.vue` can seed `manifest.runtime.user.administrationMode` for the
 * `visibleIf` primitive on first paint — a mode predicate needs a value
 * present to evaluate, and `standard` is the no-menu-change default.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/multi-administratie/spec.md#REQ-MULTI-004
 * @spec openspec/specs/single-person-modes/spec.md#REQ-SPM-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\PageController;
use OCA\Humaniq\Service\AdministrationService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for PageController.
 *
 * @spec openspec/specs/multi-administratie/spec.md#REQ-MULTI-004
 * @spec openspec/specs/single-person-modes/spec.md#REQ-SPM-002
 */
class PageControllerTest extends TestCase {

	/**
	 * Recorded `provideInitialState` calls, as `key => value`, populated by
	 * the IInitialState mock built in `buildController()`.
	 *
	 * @var array<string, mixed>
	 */
	private array $stamped = [];

	/**
	 * REQ-MULTI-004 + REQ-SPM-002: a caller with an active administratie gets
	 * BOTH the id and the resolved mode stamped as initial state before the
	 * template renders.
	 *
	 * @return void
	 */
	public function testIndexStampsBothActiveAdministrationIdAndMode(): void {
		$administrationService = $this->createMock(AdministrationService::class);
		$administrationService->method('getActiveAdministrationId')->with('admin')->willReturn('ADM-002');
		$administrationService->method('getActiveAdministrationMode')->with('admin')->willReturn('dga_single_person');

		$controller = $this->buildController($administrationService, 'admin');

		$response = $controller->index();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame('ADM-002', $this->stamped['activeAdministrationId']);
		$this->assertSame('dga_single_person', $this->stamped['activeAdministrationMode']);

	}//end testIndexStampsBothActiveAdministrationIdAndMode()

	/**
	 * REQ-MULTI-004 "unset active administratie stamps no id" + REQ-SPM-002
	 * default: a caller who never switched gets NO `activeAdministrationId`
	 * (the `?`-optional filters drop the clause) but STILL gets
	 * `activeAdministrationMode` = `standard` — the no-regression default so
	 * no visibleIf predicate hides a menu for them.
	 *
	 * @return void
	 */
	public function testIndexStampsStandardModeButNoIdWhenNoActiveAdministration(): void {
		$administrationService = $this->createMock(AdministrationService::class);
		$administrationService->method('getActiveAdministrationId')->with('admin')->willReturn(null);
		$administrationService->method('getActiveAdministrationMode')->with('admin')->willReturn('standard');

		$controller = $this->buildController($administrationService, 'admin');

		$response = $controller->index();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertArrayNotHasKey('activeAdministrationId', $this->stamped);
		$this->assertSame('standard', $this->stamped['activeAdministrationMode']);

	}//end testIndexStampsStandardModeButNoIdWhenNoActiveAdministration()

	/**
	 * An unauthenticated caller (no session user) never reaches the
	 * administration lookup — `index()` degrades to the bare template with
	 * nothing stamped.
	 *
	 * @return void
	 */
	public function testIndexSkipsInitialStateWhenNoUserIsLoggedIn(): void {
		$administrationService = $this->createMock(AdministrationService::class);
		$administrationService->expects($this->never())->method('getActiveAdministrationId');
		$administrationService->expects($this->never())->method('getActiveAdministrationMode');

		$request = $this->createMock(IRequest::class);
		$initialState = $this->createMock(IInitialState::class);
		$initialState->expects($this->never())->method('provideInitialState');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		// AdministrationService is resolved from the CONTAINER now, not injected
		// (ADR-083 rule 3 — the default route must render on an instance that
		// has no OpenRegister). The expectations above are unchanged and still
		// meaningful: with no user, neither method may be called at all.
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($administrationService);

		$controller = new PageController(
			$request,
			$userSession,
			$initialState,
			$container,
			$this->createMock(LoggerInterface::class)
		);

		$response = $controller->index();

		$this->assertInstanceOf(TemplateResponse::class, $response);

	}//end testIndexSkipsInitialStateWhenNoUserIsLoggedIn()

	/**
	 * `catchAll()` delegates to `index()` — the same initial-state stamping
	 * (id + mode) applies to deep links (Vue history mode), not only the bare
	 * app root.
	 *
	 * @return void
	 */
	public function testCatchAllAlsoStampsInitialStateViaIndex(): void {
		$administrationService = $this->createMock(AdministrationService::class);
		$administrationService->method('getActiveAdministrationId')->with('admin')->willReturn('ADM-001');
		$administrationService->method('getActiveAdministrationMode')->with('admin')->willReturn('standard');

		$controller = $this->buildController($administrationService, 'admin');

		$response = $controller->catchAll();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame('ADM-001', $this->stamped['activeAdministrationId']);
		$this->assertSame('standard', $this->stamped['activeAdministrationMode']);

	}//end testCatchAllAlsoStampsInitialStateViaIndex()

	/**
	 * The endpoint serves the EFFECTIVE manifest, not the base one.
	 *
	 * It used to return `src/manifest.json`, which is 11 pages: the base,
	 * before the 33 `src/manifest.d/` fragments and the menu-layout
	 * relocations are merged. The shipping app has 113. Nothing in the SPA
	 * noticed, because it does that merge itself from the bundle, so this
	 * endpoint quietly described an app that does not exist.
	 *
	 * This test reads the real shipped blob rather than a fixture, on purpose:
	 * a fixture would pass while the shipped file was wrong, which is exactly
	 * the failure being fixed.
	 *
	 * @return void
	 *
	 * @spec exclude framework glue -- manifest passthrough shape
	 */
	public function testTheManifestEndpointServesTheEffectiveManifestNotTheBase(): void {
		$controller = $this->buildController($this->createMock(AdministrationService::class), 'admin');

		$data = $controller->manifest()->getData();

		$this->assertIsArray($data['pages'], 'the manifest must carry a pages array');
		$this->assertGreaterThan(
			11,
			count($data['pages']),
			'11 pages means the BASE manifest is being served again'
		);

		$ids = array_column($data['pages'], 'id');
		$this->assertContains(
			'TimeEntries',
			$ids,
			'TimeEntries exists only in a manifest.d fragment, so its absence means the fragments were not merged'
		);
		$this->assertContains(
			'AssetDetail',
			$ids,
			'AssetDetail exists only in a manifest.d fragment, so its absence means the fragments were not merged'
		);

	}//end testTheManifestEndpointServesTheEffectiveManifestNotTheBase()

	/**
	 * The response is conditionally cacheable, and privately so.
	 *
	 * The ETag is what lets `NotModifiedMiddleware` answer a repeat call with a
	 * 304 instead of ~290KB of JSON. `private` matters because the endpoint is
	 * behind a session check: every caller gets identical bytes, but a shared
	 * cache still must not hold a response served to an authenticated caller.
	 *
	 * @return void
	 *
	 * @spec exclude framework glue -- manifest passthrough shape
	 */
	public function testTheManifestResponseIsConditionallyAndPrivatelyCacheable(): void {
		$controller = $this->buildController($this->createMock(AdministrationService::class), 'admin');

		$response = $controller->manifest();

		$this->assertNotEmpty($response->getETag(), 'without an ETag no repeat call can ever be answered 304');

		// `getHeaders()` resolves IRequest out of the Nextcloud container to
		// stamp X-Request-Id, which a pure unit test has not booted. The
		// private array is the value this method actually set.
		$headers = (new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers'));
		$headers->setAccessible(true);

		$this->assertSame(
			'private, max-age=3600, must-revalidate',
			$headers->getValue($response)['Cache-Control'],
			'a public cache must not be allowed to hold a session-gated response'
		);

	}//end testTheManifestResponseIsConditionallyAndPrivatelyCacheable()

	/**
	 * The ETag tracks the CONTENT, not the app version.
	 *
	 * A dev rebuild moves the manifest without moving the version, so a cache
	 * key derived from the version would serve yesterday's pages until the next
	 * release.
	 *
	 * @return void
	 *
	 * @spec exclude framework glue -- manifest passthrough shape
	 */
	public function testTheManifestEtagIsTheContentHash(): void {
		$controller = $this->buildController($this->createMock(AdministrationService::class), 'admin');

		$blob = file_get_contents(__DIR__ . '/../../../src/manifest.effective.json');

		$this->assertSame(md5($blob), $controller->manifest()->getETag());

	}//end testTheManifestEtagIsTheContentHash()

	/**
	 * Build a `PageController` with the given (mocked) service and a session
	 * resolving to `$userId`; the IInitialState mock records every stamped
	 * key/value into `$this->stamped`.
	 *
	 * @param AdministrationService $administrationService The (mocked) administration service.
	 * @param string $userId The acting user id.
	 *
	 * @return PageController
	 */
	private function buildController(
		AdministrationService $administrationService,
		string $userId,
	): PageController {
		$this->stamped = [];

		$request = $this->createMock(IRequest::class);

		$initialState = $this->createMock(IInitialState::class);
		$initialState->method('provideInitialState')
			->willReturnCallback(function (string $key, mixed $value): void {
				$this->stamped[$key] = $value;
			});

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($administrationService);

		return new PageController(
			$request,
			$userSession,
			$initialState,
			$container,
			$this->createMock(LoggerInterface::class)
		);
	}//end buildController()

}//end class
