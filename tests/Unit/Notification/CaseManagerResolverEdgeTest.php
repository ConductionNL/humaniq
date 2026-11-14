<?php

/**
 * Edge-case unit tests for CaseManagerResolver.
 *
 * How the resolver reads padded ids and roles, drops empty and duplicate
 * accounts, and keeps HR before the managers.
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
use OCA\Humaniq\Service\AdministrationAccessTrust;
use OCA\Humaniq\Service\HumaniqRoles;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\RecordStoreInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Who hears about a sickness case, at the edges.
 *
 * @covers \OCA\Humaniq\Notification\CaseManagerResolver
 * @uses   \OCA\Humaniq\Service\AdministrationAccessTrust
 * @uses   \OCA\Humaniq\Service\OrgResolutionService
 */
class CaseManagerResolverEdgeTest extends TestCase {

	/**
	 * Schemas the resolver asked the store for, in order.
	 *
	 * @var list<string>
	 */
	private array $loads = [];

	/**
	 * The resolver over a store double holding the given rows.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rows Rows by schema.
	 *
	 * @return CaseManagerResolver
	 */
	private function resolver(array $rows): CaseManagerResolver {
		$store = $this->createMock(RecordStoreInterface::class);
		$store->method('loadAll')->willReturnCallback(
			function (string $schema) use ($rows): array {
				$this->loads[] = $schema;

				return ($rows[$schema] ?? []);
			}
		);

		$roles = $this->createMock(HumaniqRoles::class);
		$roles->method('isAdmin')->willReturnCallback(static fn (?string $uid): bool => $uid === 'admin');
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');

		return new CaseManagerResolver(gateway: $store, orgResolution: new OrgResolutionService(), trust: new AdministrationAccessTrust(roles: $roles, appConfig: $appConfig));
	}//end resolver()

	/**
	 * A case entity carrying the given data.
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
	 * An hr access row granted by the admin.
	 *
	 * @param string $userId           The account.
	 * @param string $administrationId The administration.
	 * @param string $role             The role.
	 *
	 * @return array<string, mixed>
	 */
	private static function access(string $userId, string $administrationId, string $role = 'hr'): array {
		return ['userId' => $userId, 'administrationId' => $administrationId, 'role' => $role, '@self' => ['owner' => 'admin']];
	}//end access()

	/**
	 * The org chart: `emp-a` placed in a unit managed by `emp-boss`.
	 *
	 * @param string $bossAccount The manager's account.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function orgChart(string $bossAccount = 'boss'): array {
		return [
			'OrgAssignment' => [
				['id' => 'as-1', 'employeeId' => ' emp-a ', 'orgUnitId' => 'unit-1', 'startDate' => '2000-01-01', 'endDate' => null],
			],
			'OrgUnit' => [
				['id' => 'unit-1', 'managerId' => 'emp-boss'],
			],
			'Employee' => [
				['id' => 'emp-a', 'nextcloudUserId' => ' alice '],
				['id' => 'emp-boss', 'nextcloudUserId' => $bossAccount],
			],
		];
	}//end orgChart()

	/**
	 * Padded case ids, roles and account ids are trimmed before they are
	 * compared; HR comes before the manager.
	 *
	 * @return void
	 */
	public function testPaddedIdsAndRolesAreTrimmedAndHrComesFirst(): void {
		$rows = self::orgChart() + [
			'AdministrationAccess' => [
				self::access(userId: ' hr-1 ', administrationId: 'ADM-1', role: ' hr '),
				self::access(userId: 'hr-2', administrationId: 'ADM-1', role: 'HR'),
			],
		];

		$uids = $this->resolver(rows: $rows)->resolve($this->sickCase(['employeeId' => "\temp-a ", 'administrationId' => ' ADM-1 ']), []);

		self::assertSame(['hr-1', 'boss'], $uids);
	}//end testPaddedIdsAndRolesAreTrimmedAndHrComesFirst()

	/**
	 * An administration id is compared exactly as stored on the access row:
	 * a padded row id does not match.
	 *
	 * @return void
	 */
	public function testTheAccessRowsAdministrationIdIsComparedExactly(): void {
		$rows = ['AdministrationAccess' => [self::access(userId: 'hr-1', administrationId: ' ADM-1')]];

		self::assertSame([], $this->resolver(rows: $rows)->resolve($this->sickCase(['administrationId' => 'ADM-1']), []));
	}//end testTheAccessRowsAdministrationIdIsComparedExactly()

	/**
	 * An account that is both HR and the manager is listed once, and an
	 * access row without an account adds nobody; the result is a list.
	 *
	 * @return void
	 */
	public function testDuplicateAndEmptyAccountsAreDropped(): void {
		$rows = self::orgChart() + [
			'AdministrationAccess' => [
				self::access(userId: '', administrationId: 'ADM-1'),
				self::access(userId: 'boss', administrationId: 'ADM-1'),
				self::access(userId: 'boss', administrationId: 'ADM-1'),
			],
		];

		$uids = $this->resolver(rows: $rows)->resolve($this->sickCase(['employeeId' => 'emp-a', 'administrationId' => 'ADM-1']), []);

		self::assertSame(['boss'], $uids);
		self::assertTrue(array_is_list($uids));
	}//end testDuplicateAndEmptyAccountsAreDropped()

	/**
	 * The employee's own account is left out even when it holds an hr row,
	 * matched after trimming.
	 *
	 * @return void
	 */
	public function testTheEmployeesOwnHrRowIsLeftOut(): void {
		$rows = self::orgChart() + [
			'AdministrationAccess' => [self::access(userId: 'alice', administrationId: 'ADM-1'), self::access(userId: 'hr-1', administrationId: 'ADM-1')],
		];

		self::assertSame(['hr-1', 'boss'], $this->resolver(rows: $rows)->resolve($this->sickCase(['employeeId' => 'emp-a', 'administrationId' => 'ADM-1']), []));
	}//end testTheEmployeesOwnHrRowIsLeftOut()

	/**
	 * A case without an employee reaches HR only and never reads the org chart;
	 * a case with neither reads no access rows either and reaches nobody.
	 *
	 * @return void
	 */
	public function testWithoutAnEmployeeOnlyHrIsReachedAndTheOrgChartIsNotRead(): void {
		$rows = self::orgChart() + ['AdministrationAccess' => [self::access(userId: 'hr-1', administrationId: 'ADM-1')]];
		$resolver = $this->resolver(rows: $rows);

		self::assertSame(['hr-1'], $resolver->resolve($this->sickCase(['employeeId' => '  ', 'administrationId' => 'ADM-1']), []));
		self::assertSame(['AdministrationAccess', 'Employee'], $this->loads);

		$this->loads = [];
		self::assertSame([], $resolver->resolve($this->sickCase([]), []));
		self::assertSame(['Employee'], $this->loads);
	}//end testWithoutAnEmployeeOnlyHrIsReachedAndTheOrgChartIsNotRead()

	/**
	 * An employee with no placement and no account reaches nobody, and a
	 * manager without an account adds nobody.
	 *
	 * @return void
	 */
	public function testAnEmployeeWithoutPlacementOrAManagerWithoutAccountReachesNobody(): void {
		self::assertSame([], $this->resolver(rows: [])->resolve($this->sickCase(['employeeId' => 'emp-x']), []));
		self::assertSame([], $this->resolver(rows: self::orgChart(bossAccount: ''))->resolve($this->sickCase(['employeeId' => 'emp-a']), []));
	}//end testAnEmployeeWithoutPlacementOrAManagerWithoutAccountReachesNobody()

	/**
	 * An access row whose owner has no standing is not believed, so its
	 * account is not notified.
	 *
	 * @return void
	 */
	public function testAnUntrustedHrRowIsIgnored(): void {
		$rows = [
			'AdministrationAccess' => [
				['userId' => 'self-made', 'administrationId' => 'ADM-1', 'role' => 'hr', '@self' => ['owner' => 'self-made']],
				['userId' => 'orphan', 'administrationId' => 'ADM-1', 'role' => 'hr'],
			],
		];

		self::assertSame([], $this->resolver(rows: $rows)->resolve($this->sickCase(['administrationId' => 'ADM-1']), []));
	}//end testAnUntrustedHrRowIsIgnored()
}//end class
