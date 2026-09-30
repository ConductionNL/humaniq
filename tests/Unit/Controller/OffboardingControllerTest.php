<?php

/**
 * Unit tests for the offboarding page actions: resolve first, then the role.
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
 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\OffboardingController;
use OCA\Humaniq\Service\AccessRevocationService;
use OCA\Humaniq\Service\ExitInterviewService;
use OCA\Humaniq\Service\HumaniqRoles;
use OCA\Humaniq\Service\RbacObjectReader;
use OCA\Humaniq\Service\TransitionPaymentService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * 404 before 403, and the services are reached only past both.
 */
class OffboardingControllerTest extends TestCase {

	private AccessRevocationService&MockObject $revocation;
	private TransitionPaymentService&MockObject $payments;

	/**
	 * The controller for a caller.
	 *
	 * @param bool $readable Whether the case resolves for the caller.
	 * @param bool $hr       Whether the caller is HR.
	 * @param bool $payroll  Whether the caller is payroll.
	 *
	 * @return OffboardingController
	 */
	private function controller(bool $readable, bool $hr, bool $payroll=false): OffboardingController {
		$rbac = $this->createMock(RbacObjectReader::class);
		$rbac->method('findOrNull')->willReturn($readable ? ['id' => 'case-1'] : null);
		$roles = $this->createMock(HumaniqRoles::class);
		$roles->method('isHr')->willReturn($hr);
		$roles->method('isPayroll')->willReturn($payroll);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('hr-demo');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$this->revocation = $this->createMock(AccessRevocationService::class);
		$this->payments = $this->createMock(TransitionPaymentService::class);

		return new OffboardingController($this->createMock(IRequest::class), $rbac, $roles, $this->revocation, $this->payments, $this->createMock(ExitInterviewService::class), $session);
	}//end controller()

	/**
	 * A case the caller cannot read answers 404, even for HR.
	 *
	 * @return void
	 */
	public function testAnUnreadableCaseIs404(): void {
		$controller = $this->controller(readable: false, hr: true);
		$this->revocation->expects(self::never())->method('revoke');

		self::assertSame(404, $controller->revokeAccess('case-1')->getStatus());
		self::assertSame(404, $controller->transitionPayment('case-1')->getStatus());
	}//end testAnUnreadableCaseIs404()

	/**
	 * A reader who is not HR is refused revoking.
	 *
	 * @return void
	 */
	public function testANonHrCallerIs403(): void {
		$controller = $this->controller(readable: true, hr: false);
		$this->revocation->expects(self::never())->method('revoke');

		self::assertSame(403, $controller->revokeAccess('case-1')->getStatus());
		self::assertSame(403, $controller->transitionPayment('case-1')->getStatus());
		self::assertSame(403, $controller->exitReasons()->getStatus());
	}//end testANonHrCallerIs403()

	/**
	 * HR revokes as themselves; a refusal passes its status and reason on.
	 *
	 * @return void
	 */
	public function testHrRevokesAsThemselves(): void {
		$controller = $this->controller(readable: true, hr: true);
		$this->revocation->expects(self::once())->method('revoke')
			->with('case-1', 'hr-demo', self::isType('string'))
			->willReturn(['status' => 409, 'message' => 'You cannot disable your own account.', 'offboarding' => null]);

		$response = $controller->revokeAccess('case-1');

		self::assertSame(409, $response->getStatus());
		self::assertSame('You cannot disable your own account.', $response->getData()['message']);
	}//end testHrRevokesAsThemselves()

	/**
	 * Payroll may calculate the payment; zero with its reason is a 200.
	 *
	 * @return void
	 */
	public function testPayrollCalculatesThePayment(): void {
		$controller = $this->controller(readable: true, hr: false, payroll: true);
		$this->payments->expects(self::once())->method('calculateFor')->with('case-1')
			->willReturn(['amountEur' => 0.00, 'reason' => 'The departure was not initiated by the employer, so no transition payment is due.']);

		$response = $controller->transitionPayment('case-1');

		self::assertSame(200, $response->getStatus());
		self::assertSame(0.00, $response->getData()['amountEur']);
	}//end testPayrollCalculatesThePayment()

}//end class
