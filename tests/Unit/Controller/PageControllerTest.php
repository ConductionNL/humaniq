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
 * @spec openspec/changes/single-person-modes/specs/single-person-modes/spec.md#REQ-SPM-002
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
 * @spec openspec/changes/single-person-modes/specs/single-person-modes/spec.md#REQ-SPM-002
 */
class PageControllerTest extends TestCase
{

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
    public function testIndexStampsBothActiveAdministrationIdAndMode(): void
    {
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
    public function testIndexStampsStandardModeButNoIdWhenNoActiveAdministration(): void
    {
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
    public function testIndexSkipsInitialStateWhenNoUserIsLoggedIn(): void
    {
        $administrationService = $this->createMock(AdministrationService::class);
        $administrationService->expects($this->never())->method('getActiveAdministrationId');
        $administrationService->expects($this->never())->method('getActiveAdministrationMode');

        $request      = $this->createMock(IRequest::class);
        $initialState = $this->createMock(IInitialState::class);
        $initialState->expects($this->never())->method('provideInitialState');

        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn(null);

        $controller = new PageController($request, $userSession, $initialState, $administrationService);

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
    public function testCatchAllAlsoStampsInitialStateViaIndex(): void
    {
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
     * Build a `PageController` with the given (mocked) service and a session
     * resolving to `$userId`; the IInitialState mock records every stamped
     * key/value into `$this->stamped`.
     *
     * @param AdministrationService $administrationService The (mocked) administration service.
     * @param string                $userId                The acting user id.
     *
     * @return PageController
     */
    private function buildController(
        AdministrationService $administrationService,
        string $userId
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

        return new PageController($request, $userSession, $initialState, $administrationService);

    }//end buildController()


}//end class
