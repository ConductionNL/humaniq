<?php

/**
 * Unit tests for PageController.
 *
 * Pins the #64 correction to REQ-MULTI-004: `index()` stamps the caller's
 * active administratie id as initial state (`IInitialState`) so the
 * frontend's `cnWorkspaceContext` (App.vue's SPA-root provide) seeds
 * correctly on first paint, WITHOUT the wrongly-assumed upstream
 * `@administration` nextcloud-vue token (REQ-MULTI-005, superseded). An
 * unset active administratie (never switched) MUST stamp nothing, so
 * `loadState()` falls back to `''` client-side and the `?`-optional
 * `@workspace.activeAdministrationId?` manifest filters drop the clause
 * instead of resolving to an empty/broken filter.
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\Controller
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
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Controller;

use OCA\Hrmq\Controller\PageController;
use OCA\Hrmq\Service\AdministrationService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PageController.
 *
 * @spec openspec/specs/multi-administratie/spec.md#REQ-MULTI-004
 */
class PageControllerTest extends TestCase
{


    /**
     * REQ-MULTI-004 "active administratie is stamped as initial state"
     * scenario: a caller with an active administratie gets it stamped under
     * the `activeAdministrationId` key BEFORE the template renders.
     *
     * @return void
     */
    public function testIndexStampsTheCallersActiveAdministrationIdAsInitialState(): void
    {
        $administrationService = $this->createMock(AdministrationService::class);
        $administrationService->method('getActiveAdministrationId')->with('admin')->willReturn('ADM-002');

        $initialState = $this->createMock(IInitialState::class);
        $initialState->expects($this->once())
            ->method('provideInitialState')
            ->with('activeAdministrationId', 'ADM-002');

        $controller = $this->buildController($administrationService, $initialState, 'admin');

        $response = $controller->index();

        $this->assertInstanceOf(TemplateResponse::class, $response);

    }//end testIndexStampsTheCallersActiveAdministrationIdAsInitialState()


    /**
     * REQ-MULTI-004 "unset active administratie stamps nothing" scenario: a
     * caller who never switched MUST NOT get any `activeAdministrationId`
     * initial state — `loadState()` then falls back to `''` client-side and
     * the `?`-optional manifest filters drop the clause (no regression for
     * single-administratie installs).
     *
     * @return void
     */
    public function testIndexStampsNothingWhenNoActiveAdministrationIsSet(): void
    {
        $administrationService = $this->createMock(AdministrationService::class);
        $administrationService->method('getActiveAdministrationId')->with('admin')->willReturn(null);

        $initialState = $this->createMock(IInitialState::class);
        $initialState->expects($this->never())->method('provideInitialState');

        $controller = $this->buildController($administrationService, $initialState, 'admin');

        $response = $controller->index();

        $this->assertInstanceOf(TemplateResponse::class, $response);

    }//end testIndexStampsNothingWhenNoActiveAdministrationIsSet()


    /**
     * An unauthenticated caller (no session user) never reaches the
     * administration lookup — `index()` degrades to the bare template.
     *
     * @return void
     */
    public function testIndexSkipsInitialStateWhenNoUserIsLoggedIn(): void
    {
        $administrationService = $this->createMock(AdministrationService::class);
        $administrationService->expects($this->never())->method('getActiveAdministrationId');

        $initialState = $this->createMock(IInitialState::class);
        $initialState->expects($this->never())->method('provideInitialState');

        $request = $this->createMock(IRequest::class);
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn(null);

        $controller = new PageController($request, $userSession, $initialState, $administrationService);

        $response = $controller->index();

        $this->assertInstanceOf(TemplateResponse::class, $response);

    }//end testIndexSkipsInitialStateWhenNoUserIsLoggedIn()


    /**
     * `catchAll()` delegates to `index()` — the same initial-state stamping
     * applies to deep links (Vue history mode), not only the bare app root.
     *
     * @return void
     */
    public function testCatchAllAlsoStampsInitialStateViaIndex(): void
    {
        $administrationService = $this->createMock(AdministrationService::class);
        $administrationService->method('getActiveAdministrationId')->with('admin')->willReturn('ADM-001');

        $initialState = $this->createMock(IInitialState::class);
        $initialState->expects($this->once())
            ->method('provideInitialState')
            ->with('activeAdministrationId', 'ADM-001');

        $controller = $this->buildController($administrationService, $initialState, 'admin');

        $response = $controller->catchAll();

        $this->assertInstanceOf(TemplateResponse::class, $response);

    }//end testCatchAllAlsoStampsInitialStateViaIndex()


    /**
     * Build a `PageController` with the given (mocked) collaborators and a
     * session resolving to `$userId`.
     *
     * @param AdministrationService $administrationService The (mocked) administration service.
     * @param IInitialState         $initialState          The (mocked) initial-state service.
     * @param string                $userId                The acting user id.
     *
     * @return PageController
     */
    private function buildController(
        AdministrationService $administrationService,
        IInitialState $initialState,
        string $userId
    ): PageController {
        $request = $this->createMock(IRequest::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($userId);

        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($user);

        return new PageController($request, $userSession, $initialState, $administrationService);

    }//end buildController()


}//end class
