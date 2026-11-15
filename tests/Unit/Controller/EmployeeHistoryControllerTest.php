<?php

/**
 * Unit tests for the employment read of EmployeeHistoryController.
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
 * @spec openspec/specs/employee-history/spec.md#REQ-EHI-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\EmployeeHistoryController;
use OCA\Humaniq\Service\AbsenceProgression;
use OCA\Humaniq\Service\EmployeeHistoryService;
use OCA\Humaniq\Service\RbacObjectReader;
use OCA\Humaniq\Service\RecordStoreInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Who reads which employments and balances, and what the answer holds.
 */
class EmployeeHistoryControllerTest extends TestCase {

	/**
	 * The controller over rows of emp-1; the caller may read the ids listed.
	 *
	 * @param list<string> $readable The ids the caller's RBAC returns.
	 *
	 * @return EmployeeHistoryController
	 */
	private function controller(array $readable): EmployeeHistoryController {
		$rows = [
			'EmploymentContract' => [
				['id' => 'ct-1', 'employeeId' => 'emp-1', 'startDate' => '2025-01-01', 'hoursPerWeek' => 32, 'jobTitle' => 'Adviseur'],
				['id' => 'ct-2', 'employeeId' => 'emp-1', 'startDate' => '2026-03-01', 'hoursPerWeek' => 8, 'jobTitle' => 'Docent'],
				['id' => 'ct-hidden', 'employeeId' => 'emp-1', 'startDate' => '2026-01-01', 'hoursPerWeek' => 40],
				['employeeId' => 'emp-1', 'startDate' => '2026-01-01', 'hoursPerWeek' => 40],
			],
			'LeaveBalance' => [
				['id' => 'lb-2026', 'employeeId' => 'emp-1', 'year' => 2026, 'leaveType' => 'holiday'],
				['id' => 'lb-2025', 'employeeId' => 'emp-1', 'year' => 2025, 'leaveType' => 'holiday'],
				['id' => 'lb-hidden', 'employeeId' => 'emp-1', 'year' => 2026, 'leaveType' => 'extra'],
			],
		];
		$gateway = $this->createMock(RecordStoreInterface::class);
		$gateway->method('findFiltered')->willReturnCallback(static fn (string $schema): array => ($rows[$schema] ?? []));
		$rbac = $this->createMock(RbacObjectReader::class);
		$rbac->method('findOrNull')->willReturnCallback(static fn (string $id): ?array => (in_array($id, $readable, true) === true ? ['id' => $id] : null));

		return new EmployeeHistoryController($this->createMock(IRequest::class), $gateway, new EmployeeHistoryService(new AbsenceProgression()), $rbac);
	}//end controller()

	/**
	 * An unreadable employee is a 404, a bad date a 400; a readable one gets
	 * only the readable contracts and the readable balances of the year.
	 *
	 * @return void
	 */
	public function testTheEmploymentsAnswer(): void {
		$all = ['emp-1', 'ct-1', 'ct-2', 'lb-2026', 'lb-2025'];
		$missing = $this->controller(['ct-1'])->employments(id: 'emp-1', date: '2026-06-01');
		self::assertSame([Http::STATUS_NOT_FOUND, ['error' => 'Medewerker niet gevonden.']], [$missing->getStatus(), $missing->getData()]);

		$bad = $this->controller($all)->employments(id: 'emp-1', date: 'gisteren-ish');
		self::assertSame([Http::STATUS_BAD_REQUEST, ['error' => 'date moet een geldige datum zijn.']], [$bad->getStatus(), $bad->getData()]);

		$data = $this->controller($all)->employments(id: 'emp-1', date: '2026-06-01')->getData();
		$fixture = dirname(__DIR__, 2) . '/fixtures/golden/employee-employments.json';
		self::assertSame(json_decode((string)file_get_contents($fixture), true), json_decode((string)json_encode($data), true));
		self::assertSame(['lb-2026'], array_column($data['leaveBalances'], 'id'));
	}//end testTheEmploymentsAnswer()
}//end class
