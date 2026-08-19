<?php

/**
 * Hrmq Page Controller
 *
 * Renders the SPA shell that mounts the @conduction/nextcloud-vue manifest
 * renderer (CnAppRoot) and serves the bundled app manifest (ADR-024 §4). The
 * app stores no data of its own — the Timesheet/Expense pages are declarative
 * `type: "index"` / `type: "detail"` pages that read and write the hrmq
 * OpenRegister register directly via the library's object store; this
 * controller is pure SPA-shell glue.
 *
 * `index()` also stamps the caller's active administratie id (multi-
 * administratie, REQ-MULTI-004) as initial state so the frontend can seed the
 * page-level `cnWorkspaceContext` it provides at the SPA root BEFORE the
 * first paint — the Nextcloud-idiomatic `IInitialState`/`loadState()` pattern
 * (never a DOM data-attribute). This is what makes `@workspace.
 * activeAdministrationId?` filters in the manifest resolve on first load,
 * not only after a switch.
 *
 * @category Controller
 * @package  OCA\Hrmq\Controller
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
 * @spec exclude framework glue — SPA shell + bundled-manifest passthrough; no business behaviour
 * @spec openspec/specs/multi-administratie/spec.md#REQ-MULTI-004
 */

declare(strict_types=1);

namespace OCA\Hrmq\Controller;

use OCA\Hrmq\AppInfo\Application;
use OCA\Hrmq\Service\AdministrationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Renders the main SPA template and serves the bundled app manifest.
 *
 * @spec exclude framework glue — SPA shell + manifest passthrough; no business behaviour
 */
class PageController extends Controller {

	/**
	 * Constructor.
	 *
	 * ADR-083 rule 3: THE START SCREEN MUST BOOT WITHOUT OPENREGISTER.
	 *
	 * AdministrationService was constructor-injected here, and it reaches
	 * OpenRegister. Nextcloud resolves a controller's constructor before the
	 * method runs, so on an instance without OpenRegister the DEFAULT ROUTE
	 * 500s — the one page that could have told the admin which app to install
	 * is the page that cannot render. The failure is total and silent-looking:
	 * an empty app with a server error, not a message.
	 *
	 * It is resolved lazily from the container inside index() instead, and its
	 * absence degrades to "no administratie stamped" rather than to a 500. The
	 * pointer is a scoping convenience; the shell renders fine without it.
	 *
	 * @param IRequest $request The request object.
	 * @param IUserSession $userSession The user session.
	 * @param IInitialState $initialState The initial-state service.
	 * @param ContainerInterface $container DI container for lazy AdministrationService resolution.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IInitialState $initialState,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Render the main SPA page.
	 *
	 * Stamps the caller's active administratie id (when set) as initial
	 * state under the `activeAdministrationId` key BEFORE the template
	 * renders, so the frontend's `loadState('hrmq', 'activeAdministrationId',
	 * '')` seeds the `cnWorkspaceContext` it provides at the SPA root with
	 * the right value on first paint (REQ-MULTI-004). Unset (never switched)
	 * intentionally stamps nothing — `loadState()` then falls back to `''`
	 * and the `@workspace.activeAdministrationId?` manifest filters drop the
	 * clause, matching the documented no-regression default for
	 * single-administratie installs.
	 *
	 * single-person-modes (REQ-SPM-002/D2): additionally stamps the caller's
	 * active administratie's resolved `mode` under `activeAdministrationMode`
	 * (the exact `activeAdministrationId` mechanism, applied to a second key),
	 * so `App.vue` can seed `manifest.runtime.user.administrationMode` on first
	 * paint and nc-vue's `visibleIf` primitive hides the mode-scoped menus.
	 * Unlike the id (which stamps nothing when unset so the `?`-optional
	 * filters drop), the mode ALWAYS resolves to a concrete value
	 * (`standard` by default), so it is always stamped -- a `visibleIf`
	 * predicate keyed on `administrationMode` needs a value present to
	 * evaluate, and `standard` is the no-menu-change default.
	 *
	 * @return TemplateResponse
	 *
	 * @spec openspec/specs/multi-administratie/spec.md#REQ-MULTI-004
	 * @spec openspec/changes/single-person-modes/specs/single-person-modes/spec.md#REQ-SPM-002
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		$user = $this->userSession->getUser();
		if ($user !== null) {
			// Resolved here, not in the constructor, and allowed to fail:
			// AdministrationService reaches OpenRegister, and this is the route
			// that has to render on an instance that does not have it (ADR-083
			// rule 3). Without the try, an admin whose OpenRegister is missing
			// or broken gets a 500 on the app's front page instead of the shell
			// that would have explained it.
			//
			// Degrading here is safe because these two values are a SCOPING
			// CONVENIENCE. `activeAdministrationId` is consumed by
			// `@workspace.activeAdministrationId?` — the `?` makes it optional,
			// and an unset pointer drops the filter clause and shows all
			// accessible rows, which is exactly the single-administratie case
			// (REQ-MULTI-004).
			try {
				$administrationService = $this->container->get(AdministrationService::class);

				$administrationId = $administrationService->getActiveAdministrationId($user->getUID());
				if ($administrationId !== null) {
					$this->initialState->provideInitialState('activeAdministrationId', $administrationId);
				}

				$this->initialState->provideInitialState(
					'activeAdministrationMode',
					$administrationService->getActiveAdministrationMode($user->getUID())
				);
			} catch (\Throwable $e) {
				// Deliberately swallowed, and deliberately NOT a fail-open in the
				// gate-8 sense: nothing here is an authorization decision. The
				// page renders unscoped, which is the same state a fresh install
				// is in before an administratie has been chosen.
				$this->logger->debug(
					'hrmq: active-administratie state not stamped; rendering the shell unscoped.',
					['exception' => $e]
				);
			}
		}

		return new TemplateResponse(Application::APP_ID, 'index');
	}//end index()

	/**
	 * Serve the SPA for deep links (Vue history mode). Delegates to index().
	 *
	 * @return TemplateResponse
	 *
	 * @spec exclude framework glue — deep-link catch-all delegating to index() so Vue Router resolves the path
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function catchAll(): TemplateResponse {
		return $this->index();
	}//end catchAll()

	/**
	 * Return the bundled app manifest as JSON (ADR-024 §4).
	 *
	 * @return JSONResponse
	 *
	 * @spec exclude framework glue — returns the bundled src/manifest.json blob unchanged
	 *
	 * @contract exclude this serves src/manifest.json back verbatim, so its
	 * response shape IS that file and is already validated on every run by
	 * `npm run check:manifest` against the app-manifest-v2 JSON Schema — a
	 * far stronger check than an HTTP contract test asserting a couple of
	 * keys. The only behaviour of this method's own is the 401 for an
	 * unauthenticated caller, which is covered by PageControllerTest. A
	 * contract test here would restate the schema check in a weaker form and
	 * go stale the moment the manifest grows a page.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function manifest(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$manifestPath = __DIR__ . '/../../src/manifest.json';
		$manifestJson = file_get_contents($manifestPath);
		$manifest = json_decode($manifestJson, associative: true);

		return new JSONResponse($manifest);
	}//end manifest()

}//end class
