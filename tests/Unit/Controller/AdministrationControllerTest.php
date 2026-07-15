<?php

/**
 * Unit tests for AdministrationController.
 *
 * Pins the guarded active-administration selection contract (design.md
 * D4/D5): activating an accessible administratie persists the pointer and
 * returns it; activating one the caller has no `AdministrationAccess` row for
 * is refused with 404 and the pointer is never written (the
 * DocumentController/PayrollController no-admin-idor resolve-first
 * precedent); a missing `administrationId` is refused with 400 before any
 * resolve; `context()` returns exactly what `AdministrationService::context()`
 * resolves for the caller.
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
 * @spec openspec/changes/multi-administratie/specs/multi-administratie/spec.md#REQ-MULTI-003
 * @spec openspec/changes/multi-administratie/specs/multi-administratie/spec.md#REQ-MULTI-004
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Controller;

use OCA\Hrmq\Controller\AdministrationController;
use OCA\Hrmq\Service\AdministrationService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AdministrationController.
 *
 * @spec openspec/changes/multi-administratie/specs/multi-administratie/spec.md#REQ-MULTI-003
 * @spec openspec/changes/multi-administratie/specs/multi-administratie/spec.md#REQ-MULTI-004
 */
class AdministrationControllerTest extends TestCase
{


    /**
     * REQ-MULTI-003 "Activating an accessible administratie succeeds"
     * scenario.
     *
     * @return void
     */
    public function testActivatingAnAccessibleAdministratiePersistsAndReturnsIt(): void
    {
        $service = $this->createMock(AdministrationService::class);
        $service->method('hasAccess')->with('admin', 'ADM-002')->willReturn(true);
        $service->expects($this->once())->method('setActiveAdministrationId')->with('admin', 'ADM-002');

        $controller = $this->buildController($service, 'admin');

        $response = $controller->setActive('ADM-002');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('ADM-002', $response->getData()['activeAdministrationId']);

    }//end testActivatingAnAccessibleAdministratiePersistsAndReturnsIt()


    /**
     * REQ-MULTI-003 "Activating an inaccessible administratie is refused"
     * scenario: no AdministrationAccess row -> 404, the pointer is NEVER
     * written (the no-admin-idor guard runs before the persist step).
     *
     * @return void
     */
    public function testActivatingAnInaccessibleAdministratieReturns404AndNeverWrites(): void
    {
        $service = $this->createMock(AdministrationService::class);
        $service->method('hasAccess')->with('admin', 'ADM-099')->willReturn(false);
        $service->expects($this->never())->method('setActiveAdministrationId');

        $controller = $this->buildController($service, 'admin');

        $response = $controller->setActive('ADM-099');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertArrayHasKey('error', $response->getData());

    }//end testActivatingAnInaccessibleAdministratieReturns404AndNeverWrites()


    /**
     * An unknown administrationId (no catalog row at all, distinct from "has
     * no access row") collapses to the identical 404 — existence is never
     * leaked either way.
     *
     * @return void
     */
    public function testUnknownAdministrationIdAlsoReturns404(): void
    {
        $service = $this->createMock(AdministrationService::class);
        $service->method('hasAccess')->willReturn(false);
        $service->expects($this->never())->method('setActiveAdministrationId');

        $controller = $this->buildController($service, 'admin');

        $response = $controller->setActive('does-not-exist');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testUnknownAdministrationIdAlsoReturns404()


    /**
     * A missing/blank administrationId is refused with 400 before any
     * resolve (hasAccess is never even called).
     *
     * @return void
     */
    public function testMissingAdministrationIdReturns400(): void
    {
        $service = $this->createMock(AdministrationService::class);
        $service->expects($this->never())->method('hasAccess');
        $service->expects($this->never())->method('setActiveAdministrationId');

        $controller = $this->buildController($service, 'admin');

        $response = $controller->setActive('   ');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

    }//end testMissingAdministrationIdReturns400()


    /**
     * REQ-MULTI-004 `context()` returns exactly the service's resolved
     * context for the caller (active id + accessible administraties only).
     *
     * @return void
     */
    public function testContextReturnsTheCallersResolvedContext(): void
    {
        $expected = [
            'activeAdministrationId' => 'ADM-001',
            'administrations'        => [
                ['administrationId' => 'ADM-001', 'name' => 'Example: Conduction Demo B.V.', 'role' => 'accountant'],
                ['administrationId' => 'ADM-002', 'name' => 'Example: Tweede Klant B.V.', 'role' => 'accountant'],
            ],
        ];

        $service = $this->createMock(AdministrationService::class);
        $service->method('context')->with('admin')->willReturn($expected);

        $controller = $this->buildController($service, 'admin');

        $response = $controller->context();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($expected, $response->getData());

    }//end testContextReturnsTheCallersResolvedContext()


    /**
     * Build an `AdministrationController` with the given (mocked)
     * `AdministrationService` and a session resolving to `$userId`.
     *
     * @param AdministrationService $service The (mocked) administration service.
     * @param string                $userId  The acting user id.
     *
     * @return AdministrationController
     */
    private function buildController(AdministrationService $service, string $userId): AdministrationController
    {
        $request = $this->createMock(IRequest::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($userId);

        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($user);

        return new AdministrationController($request, $service, $userSession);

    }//end buildController()


}//end class
