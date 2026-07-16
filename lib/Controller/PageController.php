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

/**
 * Renders the main SPA template and serves the bundled app manifest.
 *
 * @spec exclude framework glue — SPA shell + manifest passthrough; no business behaviour
 */
class PageController extends Controller
{


    /**
     * Constructor.
     *
     * @param IRequest              $request               The request object.
     * @param IUserSession          $userSession           The user session.
     * @param IInitialState         $initialState          The initial-state service.
     * @param AdministrationService $administrationService The active-administration pointer service.
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly IUserSession $userSession,
        private readonly IInitialState $initialState,
        private readonly AdministrationService $administrationService,
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
     * @return TemplateResponse
     *
     * @spec openspec/specs/multi-administratie/spec.md#REQ-MULTI-004
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse
    {
        $user = $this->userSession->getUser();
        if ($user !== null) {
            $administrationId = $this->administrationService->getActiveAdministrationId($user->getUID());
            if ($administrationId !== null) {
                $this->initialState->provideInitialState('activeAdministrationId', $administrationId);
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
    public function catchAll(): TemplateResponse
    {
        return $this->index();

    }//end catchAll()


    /**
     * Return the bundled app manifest as JSON (ADR-024 §4).
     *
     * @return JSONResponse
     *
     * @spec exclude framework glue — returns the bundled src/manifest.json blob unchanged
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function manifest(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $manifestPath = __DIR__.'/../../src/manifest.json';
        $manifestJson = file_get_contents($manifestPath);
        $manifest     = json_decode($manifestJson, associative: true);

        return new JSONResponse($manifest);

    }//end manifest()


}//end class
