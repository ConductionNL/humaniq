<?php

/**
 * OnboardingDocumentController tests
 *
 * Read document and Create contract from document answer 404 for a case the
 * caller cannot read, 200 for a read or a made contract, 403 when the service
 * refuses the caller and 422 with the reason otherwise; a second Create
 * contract on the same document answers 422 and makes nothing.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Humaniq\Tests\Unit\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\OnboardingDocumentController;
use OCA\Humaniq\Service\EmployeeDocumentExtractionService;
use OCA\Humaniq\Service\RbacObjectReader;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The onboarding document endpoints.
 */
class OnboardingDocumentControllerTest extends TestCase {

	/**
	 * The controller over a given service.
	 *
	 * @param bool                              $readable  Whether the caller can read the case.
	 * @param EmployeeDocumentExtractionService $documents The service.
	 *
	 * @return OnboardingDocumentController
	 */
	private function controller(bool $readable, EmployeeDocumentExtractionService $documents): OnboardingDocumentController {
		$rbac = $this->createMock(RbacObjectReader::class);
		$rbac->method('findOrNull')->willReturn($readable ? ['employeeId' => 'emp-1'] : null);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('hr-demo');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new OnboardingDocumentController(request: $this->createMock(IRequest::class), rbac: $rbac, documents: $documents, userSession: $session);
	}//end controller()

	/**
	 * A case the caller cannot read is not found, and nothing is read or made.
	 *
	 * @return void
	 */
	public function testAnUnreadableCaseIsNotFound(): void {
		$documents = $this->createMock(EmployeeDocumentExtractionService::class);
		$documents->expects($this->never())->method('read');
		$documents->expects($this->never())->method('contractFrom');
		$controller = $this->controller(readable: false, documents: $documents);

		$this->assertSame(404, $controller->readDocument('onb-1')->getStatus());
		$this->assertSame(404, $controller->contractFromDocument('onb-1')->getStatus());
	}//end testAnUnreadableCaseIsNotFound()

	/**
	 * Each read outcome has its status; the case id reaches the service.
	 *
	 * @return void
	 */
	public function testEachReadOutcomeHasItsStatus(): void {
		foreach (['read' => 200, 'forbidden' => 403, 'nothing-to-read' => 422] as $status => $code) {
			$documents = $this->createMock(EmployeeDocumentExtractionService::class);
			$documents->expects($this->once())->method('read')
				->with($this->callback(static fn (array $case): bool => $case['id'] === 'onb-1' && $case['employeeId'] === 'emp-1'), 'hr-demo')
				->willReturn(['status' => $status, 'message' => 'reason ' . $status, 'documents' => []]);

			$response = $this->controller(readable: true, documents: $documents)->readDocument('onb-1');
			$this->assertSame($code, $response->getStatus(), $status);
			$this->assertSame('reason ' . $status, $response->getData()['message']);
		}
	}//end testEachReadOutcomeHasItsStatus()

	/**
	 * One read contract makes one contract: the second press answers 422.
	 *
	 * @return void
	 */
	public function testOneExtractionMakesAtMostOneContract(): void {
		$documents = $this->createMock(EmployeeDocumentExtractionService::class);
		$documents->expects($this->exactly(2))->method('contractFrom')->willReturnOnConsecutiveCalls(
			['status' => 'created', 'message' => 'made', 'contractId' => 'ct-9'],
			['status' => 'already-made', 'message' => 'A contract was already made from every read contract document of this case.', 'contractId' => 'ct-9']
		);
		$controller = $this->controller(readable: true, documents: $documents);

		$first = $controller->contractFromDocument('onb-1');
		$this->assertSame(200, $first->getStatus());
		$this->assertSame('ct-9', $first->getData()['contractId']);

		$second = $controller->contractFromDocument('onb-1');
		$this->assertSame(422, $second->getStatus());
	}//end testOneExtractionMakesAtMostOneContract()
}//end class
