<?php

/**
 * Unit tests for PayrollController::dgaStatus().
 *
 * Pins the self-service gebruikelijkloon-status endpoint contract
 * (single-person-modes design.md D5, spec.md REQ-SPM-006): the caller's own
 * `Employee` is resolved via `nextcloudUserId`; NO own Employee and an own
 * Employee that is NOT a DGA both collapse to the SAME 404 (existence/DGA-ness
 * is never leaked — D5.1); a resolved DGA gets `{isDga, grossAnnualSalaryCents,
 * jaarnormCents, met, justification}` where `met` is the EXISTING
 * `NlDgaChecks::meetsGebruikelijkloonNorm()` verdict (below-norm/unjustified →
 * false, justified → true, above-norm → true) computed against the REAL
 * `nl-2026.json` table (never a hardcoded figure); and nothing is ever written
 * (the fake ObjectService throws on any method other than the read chain).
 * Drives the controller through a fake ObjectService double — the real
 * OpenRegister ObjectService is a sibling-app dependency not available in this
 * standalone suite (the PayrollControllerProformaTest precedent).
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
 * @spec openspec/changes/single-person-modes/specs/single-person-modes/spec.md#REQ-SPM-006
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Controller;

use OCA\Hrmq\Controller\PayrollController;
use OCA\Hrmq\Service\PayrollMutationService;
use OCA\Hrmq\Service\PayrollRunService;
use OCA\Hrmq\Service\ProformaPayslipService;
use OCA\Hrmq\Service\RetroAdjustmentService;
use OCA\Hrmq\Service\SettingsService;
use OCA\Hrmq\Service\WkrService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for PayrollController::dgaStatus().
 *
 * @spec openspec/changes/single-person-modes/specs/single-person-modes/spec.md#REQ-SPM-006
 */
class PayrollControllerDgaStatusTest extends TestCase {

	/**
	 * REQ-SPM-006 "A non-DGA caller and a caller with no Employee record both
	 * receive 404": a caller with NO linked Employee gets 404 with no
	 * distinguishing detail.
	 *
	 * @return void
	 */
	public function testNoEmployeeReturns404(): void {
		$controller = $this->buildController([], 'nobody');

		$response = $controller->dgaStatus();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertArrayNotHasKey('isDga', $response->getData());

	}//end testNoEmployeeReturns404()

	/**
	 * REQ-SPM-006: a caller whose own Employee is NOT a DGA receives the
	 * IDENTICAL 404 — the response never distinguishes "no Employee" from
	 * "Employee, not a DGA".
	 *
	 * @return void
	 */
	public function testNonDgaEmployeeReturnsIdentical404(): void {
		$noEmployee = $this->buildController([], 'devries')->dgaStatus();
		$notDga = $this->buildController(
			[['nextcloudUserId' => 'devries', 'isDga' => false, 'grossMonthlySalary' => 3500.00]],
			'devries'
		)->dgaStatus();

		$this->assertSame(Http::STATUS_NOT_FOUND, $noEmployee->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $notDga->getStatus());
		// Indistinguishable from the response alone (same status, same body).
		$this->assertSame($noEmployee->getData(), $notDga->getData());

	}//end testNonDgaEmployeeReturnsIdentical404()

	/**
	 * REQ-SPM-006 "A below-norm DGA sees a warning": isDga true,
	 * grossMonthlySalary 3500 (annualised €42.000, below the €58.000 norm),
	 * no justification → met false, with the exact cents figures.
	 *
	 * @return void
	 */
	public function testBelowNormUnjustifiedDgaReturnsMetFalse(): void {
		$controller = $this->buildController(
			[['nextcloudUserId' => 'devries', 'isDga' => true, 'grossMonthlySalary' => 3500.00]],
			'devries'
		);

		$response = $controller->dgaStatus();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($data['isDga']);
		$this->assertSame(4200000, $data['grossAnnualSalaryCents']);
		$this->assertSame(5800000, $data['jaarnormCents']);
		$this->assertFalse($data['met']);
		$this->assertNull($data['justification']);

	}//end testBelowNormUnjustifiedDgaReturnsMetFalse()

	/**
	 * REQ-SPM-006 "A justified below-norm DGA is reported as met": the SAME
	 * below-norm salary with a non-empty gebruikelijkloonJustification →
	 * met true (the shipped vacuous-when-justified rule, unchanged), and the
	 * justification is echoed back.
	 *
	 * @return void
	 */
	public function testJustifiedBelowNormDgaReturnsMetTrue(): void {
		$controller = $this->buildController(
			[
				[
					'nextcloudUserId' => 'devries',
					'isDga' => true,
					'grossMonthlySalary' => 3500.00,
					'gebruikelijkloonJustification' => 'Vergelijkbare functie elders verdient minder; onderbouwd.',
				],
			],
			'devries'
		);

		$response = $controller->dgaStatus();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($data['met']);
		$this->assertSame('Vergelijkbare functie elders verdient minder; onderbouwd.', $data['justification']);

	}//end testJustifiedBelowNormDgaReturnsMetTrue()

	/**
	 * An at/above-norm DGA is reported as met without any justification —
	 * €6.000/maand annualised (€72.000) clears the €58.000 norm.
	 *
	 * @return void
	 */
	public function testAboveNormDgaReturnsMetTrue(): void {
		$controller = $this->buildController(
			[['nextcloudUserId' => 'devries', 'isDga' => true, 'grossMonthlySalary' => 6000.00]],
			'devries'
		);

		$response = $controller->dgaStatus();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($data['met']);
		$this->assertSame(7200000, $data['grossAnnualSalaryCents']);

	}//end testAboveNormDgaReturnsMetTrue()

	/**
	 * REQ-SPM-006 "The status is computed fresh, never persisted": the fake
	 * ObjectService throws on ANY method other than the read chain
	 * (setRegister/setSchema/findAll), so a passing OK response proves no
	 * register write occurred.
	 *
	 * @return void
	 */
	public function testStatusIsReadOnlyNoWrites(): void {
		$controller = $this->buildController(
			[['nextcloudUserId' => 'devries', 'isDga' => true, 'grossMonthlySalary' => 3500.00]],
			'devries'
		);

		// Would throw inside dgaStatus() if any write method were invoked.
		$response = $controller->dgaStatus();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testStatusIsReadOnlyNoWrites()

	/**
	 * Build a `PayrollController` whose fake ObjectService returns the given
	 * Employee rows from `findAll()` and throws on any non-read method (so a
	 * write is a hard test failure), and whose session resolves to `$uid`.
	 *
	 * @param array<int, array<string, mixed>> $employees Canned Employee rows.
	 * @param string $uid The acting user id.
	 *
	 * @return PayrollController
	 */
	private function buildController(array $employees, string $uid): PayrollController {
		$request = $this->createMock(IRequest::class);

		$objectService = new class($employees) {

			/**
			 * @param array<int, array<string, mixed>> $employees Canned Employee rows.
			 */
			public function __construct(
				private readonly array $employees,
			) {
			}

			/**
			 * @param string $register Ignored; chainable.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}

			/**
			 * @param string $schema Ignored; chainable.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				return $this;
			}

			/**
			 * @param array<string, mixed> $options Ignored.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $options): array {
				return $this->employees;
			}

			/**
			 * Any other call (a write) is a contract violation for a
			 * read-only endpoint — fail loudly.
			 *
			 * @param string $name The method name.
			 * @param array<int, mixed> $args The arguments.
			 *
			 * @return mixed
			 */
			public function __call(string $name, array $args): mixed {
				throw new \RuntimeException('dgaStatus must not call ObjectService::' . $name . ' (read-only endpoint).');
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($objectService);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('hrmq');
		// objectService() now establishes availability first (ADR-083). A bare
		// createMock() answers a bool method with false, so without this the
		// guard trips and the test fails on a missing app, not on its subject.
		$settings->method('isOpenRegisterAvailable')->willReturn(true);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		// Not exercised by dgaStatus(); present only to satisfy the merged
		// constructor.
		$payrollRunService = $this->createMock(PayrollRunService::class);
		$payrollMutationService = $this->createMock(PayrollMutationService::class);
		$proformaService = $this->createMock(ProformaPayslipService::class);
		$retroAdjustmentService = $this->createMock(RetroAdjustmentService::class);
		$wkrService = $this->createMock(WkrService::class);
		$groupManager = $this->createMock(IGroupManager::class);
		$logger = $this->createMock(LoggerInterface::class);

		return new PayrollController($request, $container, $payrollRunService, $payrollMutationService, $proformaService, $retroAdjustmentService, $wkrService, $settings, $userSession, $groupManager, $logger);
	}//end buildController()

}//end class
