<?php

/**
 * A small team for the approvals inbox tests (self-service-approvals-inbox).
 *
 * Manager Mila (account `mila`) leads Backoffice, where Sam (account `sam`)
 * and Noor (no account) work. Deputy Dirk (account `dirk`) stands in for Mila
 * from 14 July to 1 August 2026. Everything runs on the real
 * HoursRegisterGateway, ManagerDeputies and RbacObjectReader over the
 * in-memory object store; the caller's read rights are a second store that
 * holds only the rows the caller may read.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Support
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
 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Support;

use OCA\Humaniq\Service\ApprovalsInboxService;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\HumaniqRoles;
use OCA\Humaniq\Service\ManagerDeputies;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\RbacObjectReader;
use OCA\Humaniq\Service\SettingsService;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Builds the team, its requests and the services over them.
 */
trait ApprovalsFixture {

	protected FakeObjectStore $store;

	protected FakeObjectStore $readable;

	/**
	 * Seed the team and the deputy period.
	 *
	 * @return void
	 */
	protected function seedTeam(): void {
		$this->store = new FakeObjectStore();
		$this->readable = new FakeObjectStore();
		$this->store->seed('Employee', 'emp-mila', ['firstName' => 'Mila', 'lastName' => 'Manager', 'nextcloudUserId' => 'mila']);
		$this->store->seed('Employee', 'emp-sam', ['firstName' => 'Sam', 'lastName' => 'Jansen', 'nextcloudUserId' => 'sam']);
		$this->store->seed('Employee', 'emp-noor', ['firstName' => 'Noor', 'lastName' => 'Visser']);
		$this->store->seed('OrgUnit', 'unit-bo', ['name' => 'Backoffice', 'managerId' => 'emp-mila']);
		$this->store->seed('OrgAssignment', 'as-sam', ['employeeId' => 'emp-sam', 'orgUnitId' => 'unit-bo', 'startDate' => '2024-01-01']);
		$this->store->seed('OrgAssignment', 'as-noor', ['employeeId' => 'emp-noor', 'orgUnitId' => 'unit-bo', 'startDate' => '2024-01-01']);
		$this->store->seed('ManagerDeputy', 'dep-summer', ['managerUserId' => 'mila', 'deputyUserId' => 'dirk', 'from' => '2026-07-14', 'until' => '2026-08-01']);
	}//end seedTeam()

	/**
	 * Add a request the caller may read.
	 *
	 * @param string $schema The schema.
	 * @param string $id The id.
	 * @param array<string, mixed> $data The request.
	 *
	 * @return void
	 */
	protected function request(string $schema, string $id, array $data): void {
		$this->store->seed($schema, $id, $data);
		$this->readable->seed($schema, $id, $data);
	}//end request()

	/**
	 * The gateway over the whole store.
	 *
	 * @return HoursRegisterGateway
	 */
	protected function gateway(): HoursRegisterGateway {
		return new HoursRegisterGateway(
			container: new FakeContainer([
				'OCA\OpenRegister\Service\ObjectService' => $this->store,
				'OCA\OpenRegister\Db\SchemaMapper' => new FakeSchemaMapper(),
			]),
			settingsService: $this->settings(),
			orgResolution: new OrgResolutionService()
		);
	}//end gateway()

	/**
	 * The deputy rules, with `hr` as the only HR account.
	 *
	 * @return ManagerDeputies
	 */
	protected function deputies(): ManagerDeputies {
		/** @var TestCase $this */
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$groups->method('isInGroup')->willReturnCallback(static fn (string $uid, string $group): bool => $uid === 'hr' && $group === HumaniqRoles::HR_GROUP);

		return new ManagerDeputies(gateway: $this->gateway(), roles: new HumaniqRoles($groups));
	}//end deputies()

	/**
	 * The inbox over the store, reading under the caller's rights.
	 *
	 * @return ApprovalsInboxService
	 */
	protected function inbox(): ApprovalsInboxService {
		$reader = new RbacObjectReader(
			container: new FakeContainer(['OCA\OpenRegister\Service\ObjectService' => $this->readable]),
			settingsService: $this->settings(),
			logger: new NullLogger()
		);

		return new ApprovalsInboxService(gateway: $this->gateway(), deputies: $this->deputies(), rbac: $reader);
	}//end inbox()

	/**
	 * Settings answering the humaniq register.
	 *
	 * @return SettingsService
	 */
	private function settings(): SettingsService {
		/** @var TestCase $this */
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');

		return $settings;
	}//end settings()

}//end trait
