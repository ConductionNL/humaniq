<?php

/**
 * RegisterPrefillController tests
 *
 * Who may fill a record from the RDW or the BRP, and the legal-basis refusal
 * (people-register-prefill D3, D4).
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
 * @spec openspec/specs/register-prefill/spec.md#REQ-RPF-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\RegisterPrefillController;
use OCA\Humaniq\Service\AdministrationService;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\HumaniqRoles;
use OCA\Humaniq\Service\RbacObjectReader;
use OCA\Humaniq\Service\RegisterPrefillService;
use OCA\Humaniq\Service\RegisterPrefillUnavailableException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The two prefill endpoints.
 */
class RegisterPrefillControllerTest extends TestCase {

	/**
	 * The prefill service double.
	 *
	 * @var RegisterPrefillService&MockObject
	 */
	private RegisterPrefillService&MockObject $prefill;

	/**
	 * The register gateway double.
	 *
	 * @var HoursRegisterGateway&MockObject
	 */
	private HoursRegisterGateway&MockObject $gateway;

	/**
	 * The caller's RBAC reader double.
	 *
	 * @var RbacObjectReader&MockObject
	 */
	private RbacObjectReader&MockObject $rbac;

	/**
	 * The administration double.
	 *
	 * @var AdministrationService&MockObject
	 */
	private AdministrationService&MockObject $administrations;

	/**
	 * The controller for a caller that is or is not HR.
	 *
	 * @param bool $hr Whether the caller is HR (or an administrator).
	 *
	 * @return RegisterPrefillController
	 */
	private function controller(bool $hr): RegisterPrefillController {
		$this->prefill = $this->createMock(RegisterPrefillService::class);
		$this->gateway = $this->createMock(HoursRegisterGateway::class);
		$this->rbac = $this->createMock(RbacObjectReader::class);
		$this->administrations = $this->createMock(AdministrationService::class);
		$this->administrations->method('getActiveAdministrationId')->willReturn('ADM-001');
		$roles = $this->createMock(HumaniqRoles::class);
		$roles->method('isHr')->willReturn($hr);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('hr-demo');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new RegisterPrefillController(
			request: $this->createMock(IRequest::class),
			prefill: $this->prefill,
			gateway: $this->gateway,
			rbac: $this->rbac,
			roles: $roles,
			userSession: $session,
			administrations: $this->administrations
		);
	}//end controller()

	/**
	 * HR fills a car: the filled fields are saved, the answer lists both.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-prefill/spec.md#REQ-RPF-001
	 */
	public function testHrFillsACarAndTheFilledFieldsAreSaved(): void {
		$controller = $this->controller(true);
		$asset = ['category' => 'vehicle', 'licencePlate' => 'GZS78Z'];
		$this->rbac->method('findOrNull')->with('asset-1', 'Asset')->willReturn($asset);
		$this->prefill->method('vehicle')->with($asset)->willReturn(['filled' => ['make' => 'TOYOTA'], 'differs' => []]);
		$this->gateway->method('findObjectData')->with('asset-1', 'Asset')->willReturn(['id' => 'asset-1', 'name' => 'Lease car', 'category' => 'vehicle', 'licencePlate' => 'GZS78Z', 'status' => 'available']);
		// OpenRegister's save replaces the object: the stored fields go along.
		$this->gateway->expects(self::once())->method('save')->with(['name' => 'Lease car', 'category' => 'vehicle', 'licencePlate' => 'GZS78Z', 'status' => 'available', 'make' => 'TOYOTA'], 'Asset', 'asset-1');

		$response = $controller->vehicle('asset-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['filled' => ['make' => 'TOYOTA'], 'differs' => []], $response->getData());
	}//end testHrFillsACarAndTheFilledFieldsAreSaved()

	/**
	 * A caller outside HR is refused before anything is read or sent.
	 *
	 * @return void
	 */
	public function testANonHrCallerIsRefused(): void {
		$controller = $this->controller(false);
		$this->rbac->expects(self::never())->method('findOrNull');
		$this->prefill->expects(self::never())->method('vehicle');
		$this->prefill->expects(self::never())->method('employee');

		self::assertSame(Http::STATUS_FORBIDDEN, $controller->vehicle('asset-1')->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $controller->employee('emp-1')->getStatus());
	}//end testANonHrCallerIsRefused()

	/**
	 * Scenario: no basis, no lookup.
	 *
	 * @return void
	 */
	public function testWithoutARecordedBasisNothingIsSentToTheBrp(): void {
		foreach ([[], [['administrationId' => 'ADM-001', 'brpGrondslag' => '']], [['administrationId' => 'ADM-001']]] as $administrations) {
			$controller = $this->controller(true);
			$this->rbac->method('findOrNull')->willReturn(['bsn' => '999993653', 'administrationId' => 'ADM-001']);
			$this->gateway->method('findFiltered')->with('hrAdministration', ['administrationId' => 'ADM-001'])->willReturn($administrations);
			$this->prefill->expects(self::never())->method('employee');
			$this->gateway->expects(self::never())->method('save');

			$response = $controller->employee('emp-1');

			self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
			self::assertSame('no-legal-basis', $response->getData()['reason']);
		}
	}//end testWithoutARecordedBasisNothingIsSentToTheBrp()

	/**
	 * With a recorded basis the lookup runs and the filled address is saved.
	 *
	 * @return void
	 */
	public function testWithARecordedBasisTheEmployeeIsFilled(): void {
		$controller = $this->controller(true);
		$employee = ['bsn' => '999993653', 'administrationId' => 'ADM-001'];
		$this->rbac->method('findOrNull')->with('emp-1', 'Employee')->willReturn($employee);
		$this->gateway->method('findFiltered')->willReturn([['administrationId' => 'ADM-001', 'brpGrondslag' => 'Wet BRP art. 3.3, gemeente als werkgever']]);
		$this->prefill->method('employee')->with($employee)->willReturn(['filled' => ['straat' => 'Lange Voorhout'], 'differs' => []]);
		$this->gateway->method('findObjectData')->willReturn(['firstName' => 'Suzanne', 'bsn' => '999993653']);
		$this->gateway->expects(self::once())->method('save')->with(['firstName' => 'Suzanne', 'bsn' => '999993653', 'straat' => 'Lange Voorhout'], 'Employee', 'emp-1');

		self::assertSame(Http::STATUS_OK, $controller->employee('emp-1')->getStatus());
	}//end testWithARecordedBasisTheEmployeeIsFilled()

	/**
	 * An unreadable record is 404; an unavailable register is 409 with its reason; nothing filled saves nothing.
	 *
	 * @return void
	 */
	public function testMissingRecordAndUnavailableRegister(): void {
		$controller = $this->controller(true);
		$this->rbac->method('findOrNull')->willReturnOnConsecutiveCalls(null, ['category' => 'vehicle'], ['category' => 'vehicle']);
		$this->prefill->method('vehicle')->willReturnOnConsecutiveCalls(
			self::throwException(new RegisterPrefillUnavailableException('Integriq is not installed.', 'skipped-no-integriq')),
			['filled' => [], 'differs' => [['field' => 'make', 'stored' => 'VW', 'register' => 'TOYOTA']]]
		);
		$this->gateway->expects(self::never())->method('save');

		self::assertSame(Http::STATUS_NOT_FOUND, $controller->vehicle('gone')->getStatus());
		$conflict = $controller->vehicle('asset-1');
		self::assertSame(Http::STATUS_CONFLICT, $conflict->getStatus());
		self::assertSame('skipped-no-integriq', $conflict->getData()['reason']);
		self::assertSame(Http::STATUS_OK, $controller->vehicle('asset-1')->getStatus());
	}//end testMissingRecordAndUnavailableRegister()

	/**
	 * A save the approval guard refuses answers 409 with its message.
	 *
	 * @return void
	 */
	public function testARefusedSaveAnswersConflict(): void {
		$controller = $this->controller(true);
		$this->rbac->method('findOrNull')->willReturn(['bsn' => '999993653', 'administrationId' => 'ADM-001']);
		$this->gateway->method('findFiltered')->willReturn([['brpGrondslag' => 'Wet BRP']]);
		$this->prefill->method('employee')->willReturn(['filled' => ['straat' => 'Lange Voorhout'], 'differs' => []]);
		$this->gateway->method('save')->willThrowException(new \RuntimeException('Een wijziging van adres (straat) moet worden goedgekeurd.'));

		$response = $controller->employee('emp-1');

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		self::assertStringContainsString('goedgekeurd', $response->getData()['message']);
	}//end testARefusedSaveAnswersConflict()

	/**
	 * Fill from BRP is offered to HR whose active administration records a basis, to nobody else.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-prefill/spec.md#REQ-RPF-002
	 */
	public function testTheBrpActionIsOfferedOnlyOnARecordedBasis(): void {
		$controller = $this->controller(true);
		$this->gateway->method('findFiltered')->willReturnOnConsecutiveCalls([['brpGrondslag' => 'Wet BRP']], [['brpGrondslag' => '']]);
		self::assertSame(['available' => true], $controller->brpAvailable()->getData());
		self::assertSame(['available' => false], $controller->brpAvailable()->getData());

		self::assertSame(['available' => false], $this->controller(false)->brpAvailable()->getData());
	}//end testTheBrpActionIsOfferedOnlyOnARecordedBasis()

}//end class
