<?php

/**
 * HoursRegisterGateway unit tests
 *
 * The shared plumbing the hours-process listeners stand on: object load
 * (with id echo), filtered queries, the org-chain index shapes, the
 * account-link Employee lookup, and slug resolution — including the
 * unresolvable/'' degradations the listeners' no-op paths depend on.
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\Service
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
 * @spec openspec/changes/hrmq-hours-process-redesign/specs/mss-team-scope/spec.md#Requirement:-The-approval-carrying-schemas-SHALL-gain-an-optional-denormalized-managerUserId-scoping-property-(REQ-MSS-001)
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Service;

use OCA\Hrmq\Service\HoursRegisterGateway;
use OCA\Hrmq\Service\OrgResolutionService;
use OCA\Hrmq\Service\SettingsService;
use OCA\Hrmq\Tests\Unit\Support\FakeContainer;
use OCA\Hrmq\Tests\Unit\Support\FakeObjectStore;
use OCA\Hrmq\Tests\Unit\Support\FakeSchemaMapper;
use PHPUnit\Framework\TestCase;

/**
 * Reads, writes, index shapes and degradations of the gateway.
 */
class HoursRegisterGatewayTest extends TestCase {

	/**
	 * The in-memory register.
	 *
	 * @var FakeObjectStore
	 */
	private FakeObjectStore $store;

	/**
	 * The subject.
	 *
	 * @var HoursRegisterGateway
	 */
	private HoursRegisterGateway $gateway;

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new FakeObjectStore();

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('hrmq');

		$this->gateway = new HoursRegisterGateway(
			container: new FakeContainer([
				'OCA\OpenRegister\Service\ObjectService' => $this->store,
				'OCA\OpenRegister\Db\SchemaMapper' => new FakeSchemaMapper(),
			]),
			settingsService: $settings,
			orgResolution: new OrgResolutionService()
		);
	}//end setUp()

	/**
	 * findObjectData returns the payload with the uuid echoed as id, and
	 * null for a missing object.
	 *
	 * @return void
	 */
	public function testFindObjectDataEchoesIdAndDegradesToNull(): void {
		$this->store->seed('Timesheet', 'ts-1', ['period' => '2026-05']);

		$data = $this->gateway->findObjectData('ts-1', 'Timesheet');
		$this->assertSame('2026-05', $data['period']);
		$this->assertSame('ts-1', $data['id']);

		$this->assertNull($this->gateway->findObjectData('ts-gone', 'Timesheet'));
	}//end testFindObjectDataEchoesIdAndDegradesToNull()

	/**
	 * findFiltered applies top-level equality and save() round-trips.
	 *
	 * @return void
	 */
	public function testFindFilteredAndSaveRoundTrip(): void {
		$this->gateway->save(['employeeId' => 'e1', 'period' => '2026-05'], 'Timesheet', 'ts-1');
		$this->gateway->save(['employeeId' => 'e1', 'period' => '2026-06'], 'Timesheet', 'ts-2');
		$this->gateway->save(['employeeId' => 'e2', 'period' => '2026-05'], 'Timesheet', 'ts-3');

		$matches = $this->gateway->findFiltered('Timesheet', ['employeeId' => 'e1', 'period' => '2026-05']);
		$this->assertCount(1, $matches);
		$this->assertSame('ts-1', $matches[0]['id']);
	}//end testFindFilteredAndSaveRoundTrip()

	/**
	 * The chain lookups resolve manager / cost centre through the SAME
	 * OrgResolutionService the audit uses, with the never-guessed
	 * unique-or-null posture; the account-link lookup finds the matching
	 * Employee (or null).
	 *
	 * @return void
	 */
	public function testChainLookupsAndAccountLinkLookup(): void {
		$this->store->seed('Employee', 'emp-1', ['nextcloudUserId' => 'admin']);
		$this->store->seed('Employee', 'emp-9', ['nextcloudUserId' => 'manager1']);
		$this->store->seed('OrgAssignment', 'as-1', ['employeeId' => 'emp-1', 'orgUnitId' => 'unit-1', 'endDate' => '']);
		$this->store->seed('OrgAssignment', 'as-2', ['employeeId' => 'emp-2', 'orgUnitId' => 'unit-2', 'endDate' => '']);
		$this->store->seed('OrgUnit', 'unit-1', ['managerId' => 'emp-9', 'costCenter' => 'CC-100']);

		$this->assertSame('manager1', $this->gateway->uniqueManagerUserIdFor('emp-1', '2026-08-21'));
		$this->assertSame('CC-100', $this->gateway->uniqueCostCenterFor('emp-1', '2026-08-21'));
		$this->assertNull($this->gateway->uniqueManagerUserIdFor('emp-2', '2026-08-21'), 'A dead-end chain stamps nothing.');
		$this->assertNull($this->gateway->uniqueCostCenterFor('emp-unknown', '2026-08-21'));

		$this->assertSame('emp-1', $this->gateway->findEmployeeByUserId('admin')['id']);
		$this->assertNull($this->gateway->findEmployeeByUserId('nobody'));
		$this->assertNull($this->gateway->findEmployeeByUserId(''));
	}//end testChainLookupsAndAccountLinkLookup()

	/**
	 * Slug resolution echoes the mapper's slug and degrades to '' for an
	 * empty id — the listeners' schema gates depend on both.
	 *
	 * @return void
	 */
	public function testSlugResolutionAndDegradation(): void {
		$this->assertSame('TimeEntry', $this->gateway->resolveSchemaSlug('TimeEntry'));
		$this->assertSame('', $this->gateway->resolveSchemaSlug(''));
	}//end testSlugResolutionAndDegradation()

}//end class
