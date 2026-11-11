<?php

/**
 * Golden test for SideActivityRegister::place: every report placed or refused.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Service
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
 * @spec openspec/specs/secondment-and-side-activities/spec.md#REQ-SEC-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\HumaniqRoles;
use OCA\Humaniq\Service\SideActivityRegister;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

/**
 * Every report placed or refused, by whom.
 */
class SideActivityRegisterGoldenTest extends TestCase {

	/**
	 * Every report, placed by every kind of caller.
	 *
	 * @return void
	 */
	public function testEveryReportPlacedOrRefused(): void {
		$employees = [
			'emp-1' => ['id' => 'emp-1', 'nextcloudUserId' => 'ingrid', 'administrationId' => 'ADM-001'],
			'emp-2' => ['nextcloudUserId' => '', 'administrationId' => 'ADM-002'],
		];
		$gateway = $this->createMock(HoursRegisterGateway::class);
		$gateway->method('findObjectData')->willReturnCallback(static fn (string $id, string $schema): ?array => ($employees[$id] ?? null));
		$gateway->method('findEmployeeByUserId')->willReturnCallback(
			static fn (string $uid): ?array => match ($uid) {
				'ingrid' => $employees['emp-1'],
				'no-id' => ['nextcloudUserId' => 'no-id'],
				default => null,
			}
		);
		$gateway->method('uniqueManagerUserIdFor')->willReturnCallback(static fn (string $id, string $day): ?string => ($id === 'emp-1' ? 'manager.' . $day : null));
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$groups->method('isInGroup')->willReturnCallback(static fn (string $uid, string $group): bool => $uid === 'hr.user' && $group === HumaniqRoles::HR_GROUP);
		$register = new SideActivityRegister(gateway: $gateway, roles: new HumaniqRoles($groups));
		$cases = [
			'own' => [['description' => 'Bestuurslid'], 'ingrid'],
			'own-named' => [['employeeId' => 'emp-1', 'description' => 'Bestuurslid', 'administrationId' => 'ADM-X'], 'ingrid'],
			'nil-report' => [['noneToReport' => true], 'ingrid'],
			'nil-not-true' => [['noneToReport' => 'yes', 'description' => ' '], 'ingrid'],
			'empty' => [[], 'ingrid'],
			'someone-else' => [['employeeId' => 'emp-1', 'description' => 'Iets'], 'colleague'],
			'someone-else-empty' => [['employeeId' => 'emp-1'], 'colleague'],
			'hr' => [['employeeId' => 'emp-1', 'description' => 'Iets'], 'hr.user'],
			'system' => [['employeeId' => 'emp-1', 'description' => 'Iets'], ''],
			'no-account-employee' => [['employeeId' => 'emp-2', 'description' => 'Iets'], 'hr.user'],
			'unknown-employee' => [['employeeId' => 'emp-9', 'description' => 'Iets'], 'hr.user'],
			'no-employee-for-caller' => [['description' => 'Iets'], 'nobody'],
			'caller-record-without-id' => [['description' => 'Iets'], 'no-id'],
		];
		$got = [];
		foreach ($cases as $name => [$report, $uid]) {
			$got[$name] = $register->place(report: $report, userId: $uid, today: '2026-06-01');
		}

		$this->assertSame((string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/golden/side-activity-placements.json'), json_encode($got, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
	}//end testEveryReportPlacedOrRefused()
}//end class
