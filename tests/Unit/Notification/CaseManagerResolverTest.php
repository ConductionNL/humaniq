<?php

/**
 * Unit tests for CaseManagerResolver.
 *
 * A sickness case's reminders reach the HR accounts of the case's
 * administration and the employee's managers from their org placement, and
 * nobody else. The OrgResolutionService is the real class; the register reads
 * go through a double of the real HoursRegisterGateway method names.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Notification
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
 * @spec openspec/specs/absence-deadlines-and-signals/spec.md#REQ-ADS-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Notification;

use OCA\Humaniq\Notification\CaseManagerResolver;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;

/**
 * Who hears about a sickness case.
 */
class CaseManagerResolverTest extends TestCase {

	/**
	 * The register the gateway double answers from.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function rows(): array {
		return [
			'AdministrationAccess' => [
				['id' => 'acc-1', 'userId' => 'hr-adviseur', 'administrationId' => 'ADM-001', 'role' => 'hr'],
				['id' => 'acc-2', 'userId' => 'boekhouder', 'administrationId' => 'ADM-001', 'role' => 'accountant'],
				['id' => 'acc-3', 'userId' => 'hr-elders', 'administrationId' => 'ADM-002', 'role' => 'hr'],
				['id' => 'acc-4', 'userId' => 'sjansen', 'administrationId' => 'ADM-001', 'role' => 'employee'],
			],
			'OrgAssignment' => [
				['id' => 'as-1', 'employeeId' => 'emp-jansen', 'orgUnitId' => 'unit-ops', 'startDate' => '2024-01-01', 'endDate' => null],
			],
			'OrgUnit' => [
				['id' => 'unit-ops', 'name' => 'Operations', 'managerId' => 'emp-teamleider'],
			],
			'Employee' => [
				['id' => 'emp-jansen', 'nextcloudUserId' => 'sjansen'],
				['id' => 'emp-teamleider', 'nextcloudUserId' => 'teamleider'],
			],
		];
	}//end rows()

	/**
	 * The resolver over the gateway double and the real org resolution.
	 *
	 * @return CaseManagerResolver
	 */
	private function resolver(): CaseManagerResolver {
		$rows = $this->rows();
		$gateway = $this->createMock(HoursRegisterGateway::class);
		$gateway->method('loadAll')->willReturnCallback(static fn (string $schema): array => ($rows[$schema] ?? []));
		$gateway->method('findFiltered')->willReturnCallback(
			static function (string $schema, array $filters) use ($rows): array {
				return array_values(
					array_filter(
						($rows[$schema] ?? []),
						static function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if ((string)($row[$key] ?? '') !== (string)$value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}
		);

		return new CaseManagerResolver($gateway, new OrgResolutionService());
	}//end resolver()

	/**
	 * A sickness case as OpenRegister hands it over.
	 *
	 * @param array<string, mixed> $data The case.
	 *
	 * @return ObjectEntity
	 */
	private function sickCase(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($data);
		return $entity;
	}//end sickCase()

	/**
	 * HR of the case's administration and the employee's manager, nobody else.
	 *
	 * @return void
	 */
	public function testHrOfTheAdministrationAndTheManager(): void {
		$uids = $this->resolver()->resolve($this->sickCase(['employeeId' => 'emp-jansen', 'administrationId' => 'ADM-001']), []);

		sort($uids);
		self::assertSame(['hr-adviseur', 'teamleider'], $uids);
	}//end testHrOfTheAdministrationAndTheManager()

	/**
	 * The employee is never among the recipients, even as their own manager's
	 * colleague or with an access row.
	 *
	 * @return void
	 */
	public function testTheEmployeeIsNotARecipient(): void {
		$uids = $this->resolver()->resolve($this->sickCase(['employeeId' => 'emp-jansen', 'administrationId' => 'ADM-001']), []);

		self::assertNotContains('sjansen', $uids);
	}//end testTheEmployeeIsNotARecipient()

	/**
	 * A case without an administration reaches only the manager.
	 *
	 * @return void
	 */
	public function testNoAdministrationMeansOnlyTheManager(): void {
		self::assertSame(['teamleider'], $this->resolver()->resolve($this->sickCase(['employeeId' => 'emp-jansen']), []));
	}//end testNoAdministrationMeansOnlyTheManager()

}//end class
