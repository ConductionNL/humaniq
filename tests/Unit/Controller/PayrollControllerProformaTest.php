<?php

/**
 * Unit tests for PayrollController::proforma().
 *
 * Pins the proforma-payslip endpoint contract (design.md D4): an HR caller
 * whose RBAC can resolve the hrmq payroll register/schema receives the full
 * breakdown, a caller whose RBAC cannot resolve it gets HTTP 404 (unknown and
 * unauthorized collapse to the same status — no capability leak), and
 * malformed input (non-numeric gross) is refused with HTTP 400 before any
 * computation. Drives the controller through a fake ObjectService double (a
 * fake collaborator, not a fake of the logic under test) since the real
 * OpenRegister ObjectService is a sibling-app dependency not available in
 * this standalone suite — the fake's `findAll()` either succeeds (RBAC ok)
 * or throws (RBAC denies/register unresolvable), exactly like
 * `authorizeProformaAccess()` expects.
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
 * @spec openspec/changes/proforma-payslip/specs/proforma-payslip/spec.md#REQ-PRO-002
 * @spec openspec/changes/proforma-payslip/specs/proforma-payslip/spec.md#REQ-PRO-004
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Controller;

use OCA\Hrmq\Controller\PayrollController;
use OCA\Hrmq\Payroll\PayrollCalculator;
use OCA\Hrmq\Service\PayrollMutationService;
use OCA\Hrmq\Service\PayrollRunService;
use OCA\Hrmq\Service\ProformaPayslipService;
use OCA\Hrmq\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for PayrollController::proforma().
 *
 * @spec openspec/changes/proforma-payslip/specs/proforma-payslip/spec.md#REQ-PRO-002
 * @spec openspec/changes/proforma-payslip/specs/proforma-payslip/spec.md#REQ-PRO-004
 */
class PayrollControllerProformaTest extends TestCase
{


    /**
     * REQ-PRO-004 "An HR caller reaches the simulation" scenario.
     *
     * @return void
     */
    public function testAuthorizedCallerReceivesTheFullBreakdown(): void
    {
        $controller = $this->buildController(rbacAllowed: true);

        $response = $controller->proforma(gross: 3800, table: 'wit', dateOfBirth: '1990-04-12', period: '2026-02', aof: 'laag', whk: 1.52);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertSame(3081.17, $data['nettoPay']);
        $this->assertArrayHasKey('loonheffing', $data);
        $this->assertArrayHasKey('werknemersverzekeringen', $data);

    }//end testAuthorizedCallerReceivesTheFullBreakdown()


    /**
     * REQ-PRO-004 "A non-HR caller cannot reach the engine" scenario: RBAC
     * cannot resolve the payroll register -> 404, no calculation performed
     * (asserted by the response carrying no breakdown fields).
     *
     * @return void
     */
    public function testUnauthorizedCallerReceives404(): void
    {
        $controller = $this->buildController(rbacAllowed: false);

        $response = $controller->proforma(gross: 3800, table: 'wit', period: '2026-02');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertArrayNotHasKey('nettoPay', $response->getData());

    }//end testUnauthorizedCallerReceives404()


    /**
     * REQ-PRO-002 "Malformed input is refused" scenario: RBAC passes but the
     * gross is non-numeric -> 400, nothing computed.
     *
     * @return void
     */
    public function testMalformedGrossReturns400(): void
    {
        $controller = $this->buildController(rbacAllowed: true);

        $response = $controller->proforma(gross: 'n/a');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertArrayHasKey('error', $response->getData());
        $this->assertArrayNotHasKey('nettoPay', $response->getData());

    }//end testMalformedGrossReturns400()


    /**
     * Build a `PayrollController` with a fake container-resolved
     * ObjectService whose `findAll()` either succeeds (RBAC ok) or throws
     * (RBAC denies / register unresolvable).
     *
     * @param bool $rbacAllowed Whether the fake ObjectService allows the capability probe.
     *
     * @return PayrollController
     */
    private function buildController(bool $rbacAllowed): PayrollController
    {
        $request = $this->createMock(IRequest::class);

        $objectService = new class ($rbacAllowed) {

            /**
             * @param bool $allowed Whether findAll() should succeed.
             */
            public function __construct(private readonly bool $allowed)
            {
            }

            /**
             * @param string $register Ignored; chainable.
             *
             * @return self
             */
            public function setRegister(string $register): self
            {
                return $this;
            }

            /**
             * @param string $schema Ignored; chainable.
             *
             * @return self
             */
            public function setSchema(string $schema): self
            {
                return $this;
            }

            /**
             * @param array<string, mixed> $options Ignored.
             *
             * @return array<int, mixed>
             */
            public function findAll(array $options): array
            {
                if ($this->allowed === false) {
                    throw new \RuntimeException('RBAC denied.');
                }

                return [];
            }
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($objectService);

        $payrollRunService = $this->createMock(PayrollRunService::class);

        $settings = $this->createMock(SettingsService::class);
        $settings->method('getRegisterSlug')->willReturn('hrmq');
        $settings->method('getPayrollAofTariff')->willReturn('laag');
        $settings->method('getPayrollWhkPercentage')->willReturnArgument(0);

        $proformaService = new ProformaPayslipService(new PayrollCalculator(), $settings);

        // Not exercised by proforma(); present only to satisfy the merged
        // constructor (payroll-mutation-reports coexists on this controller).
        $payrollMutationService = $this->createMock(PayrollMutationService::class);
        $userSession            = $this->createMock(IUserSession::class);
        $groupManager           = $this->createMock(IGroupManager::class);

        $logger = $this->createMock(LoggerInterface::class);

        return new PayrollController($request, $container, $payrollRunService, $payrollMutationService, $proformaService, $settings, $userSession, $groupManager, $logger);

    }//end buildController()


}//end class
