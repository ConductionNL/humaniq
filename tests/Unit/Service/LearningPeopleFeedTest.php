<?php

/**
 * Unit tests for LearningPeopleFeed.
 *
 * The people feed returns active employees with exactly the fields a
 * learning platform needs (name, account, current unit and role, managers,
 * employment dates, modified), drops a leaver, and with `modifiedSince`
 * returns an employee whose placement changed. Driven through the real
 * HoursRegisterGateway and OrgResolutionService over the shared
 * FakeObjectStore.
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
 * @spec openspec/specs/training-and-lms-sync/spec.md#REQ-TRN-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\LearningPeopleFeed;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\FakeObjectStore;
use OCA\Humaniq\Tests\Unit\Support\FakeSchemaMapper;
use PHPUnit\Framework\TestCase;

/**
 * The minimal people feed for learning platforms.
 */
class LearningPeopleFeedTest extends TestCase {

	/**
	 * The fake register.
	 *
	 * @var FakeObjectStore
	 */
	private FakeObjectStore $store;

	/**
	 * The feed under test.
	 *
	 * @var LearningPeopleFeed
	 */
	private LearningPeopleFeed $feed;

	/**
	 * Seed Finance and HR, their managers, a mover, a stayer and a leaver.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new FakeObjectStore();
		$old = ['@self' => ['updated' => '2026-01-10T09:00:00+00:00']];
		$this->store->seed('Employee', 'emp-fin-mgr', array_merge($old, ['firstName' => 'Fleur', 'lastName' => 'Financien', 'nextcloudUserId' => 'f.financien', 'startDate' => '2020-01-01']));
		$this->store->seed('Employee', 'emp-hr-mgr', array_merge($old, ['firstName' => 'Hanna', 'lastName' => 'Personeel', 'nextcloudUserId' => 'h.personeel', 'startDate' => '2020-01-01']));
		$this->store->seed(
			'Employee',
			'emp-mover',
			array_merge(
				$old,
				['firstName' => 'Anna', 'lastName' => 'Visser', 'nextcloudUserId' => 'a.visser', 'startDate' => '2023-02-01', 'bsn' => '111222333', 'grossMonthlySalary' => 4100.0, 'iban' => 'NL00BANK0123456789']
			)
		);
		$this->store->seed('Employee', 'emp-stayer', array_merge($old, ['firstName' => 'Bram', 'lastName' => 'Bakker', 'nextcloudUserId' => 'b.bakker', 'startDate' => '2022-05-01']));
		$this->store->seed('Employee', 'emp-leaver', array_merge($old, ['firstName' => 'Lies', 'lastName' => 'Vertrek', 'nextcloudUserId' => 'l.vertrek', 'startDate' => '2021-01-01', 'endDate' => '2026-09-22']));

		$this->store->seed('OrgUnit', 'unit-fin', array_merge($old, ['name' => 'Finance', 'managerId' => 'emp-fin-mgr']));
		$this->store->seed('OrgUnit', 'unit-hr', array_merge($old, ['name' => 'HR', 'managerId' => 'emp-hr-mgr']));

		$this->store->seed('OrgAssignment', 'as-mover-fin', ['employeeId' => 'emp-mover', 'orgUnitId' => 'unit-fin', 'role' => 'Adviseur', 'startDate' => '2023-02-01', 'endDate' => '2026-09-28', '@self' => ['updated' => '2026-09-28T16:00:00+00:00']]);
		$this->store->seed('OrgAssignment', 'as-mover-hr', ['employeeId' => 'emp-mover', 'orgUnitId' => 'unit-hr', 'role' => 'HR-adviseur', 'startDate' => '2026-09-29', '@self' => ['updated' => '2026-09-28T16:00:00+00:00']]);
		$this->store->seed('OrgAssignment', 'as-stayer', array_merge($old, ['employeeId' => 'emp-stayer', 'orgUnitId' => 'unit-fin', 'role' => 'Controller', 'startDate' => '2022-05-01']));
		$this->store->seed('OrgAssignment', 'as-leaver', array_merge($old, ['employeeId' => 'emp-leaver', 'orgUnitId' => 'unit-hr', 'role' => 'Adviseur', 'startDate' => '2021-01-01', 'endDate' => '2026-09-22']));

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$gateway = new HoursRegisterGateway(
			container: new FakeContainer([
				'OCA\OpenRegister\Service\ObjectService' => $this->store,
				'OCA\OpenRegister\Db\SchemaMapper' => new FakeSchemaMapper(),
			]),
			settingsService: $settings,
			orgResolution: new OrgResolutionService()
		);
		$this->feed = new LearningPeopleFeed(gateway: $gateway, orgResolution: new OrgResolutionService());
	}//end setUp()

	/**
	 * The feed rows keyed by employee id.
	 *
	 * @param string|null $modifiedSince The cut-off.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function byId(?string $modifiedSince=null): array {
		$rows = [];
		foreach ($this->feed->people(today: '2026-09-30', modifiedSince: $modifiedSince) as $row) {
			$rows[$row['id']] = $row;
		}

		return $rows;
	}//end byId()

	/**
	 * A row holds exactly the fields of the feed, and nothing like a BSN or a salary.
	 *
	 * @return void
	 */
	public function testARowHoldsExactlyTheFeedFields(): void {
		$mover = $this->byId()['emp-mover'];

		self::assertSame(
			['id', 'firstName', 'lastName', 'nextcloudUserId', 'orgUnit', 'role', 'managerUserIds', 'startDate', 'endDate', 'modified'],
			array_keys($mover)
		);
		self::assertSame('a.visser', $mover['nextcloudUserId']);
		self::assertSame('2023-02-01', $mover['startDate']);
		self::assertNull($mover['endDate']);
	}//end testARowHoldsExactlyTheFeedFields()

	/**
	 * A move from Finance to HR yesterday reaches a platform that asks for
	 * changes since two days ago, with HR and HR's manager; the stayer does not.
	 *
	 * @return void
	 */
	public function testAMoveToAnotherTeamReachesThePlatform(): void {
		$changed = $this->byId('2026-09-28T00:00:00+00:00');

		self::assertSame(['emp-mover'], array_keys($changed));
		self::assertSame(['id' => 'unit-hr', 'name' => 'HR'], $changed['emp-mover']['orgUnit']);
		self::assertSame('HR-adviseur', $changed['emp-mover']['role']);
		self::assertSame(['h.personeel'], $changed['emp-mover']['managerUserIds']);
		self::assertSame('2026-09-28T16:00:00+00:00', $changed['emp-mover']['modified']);
	}//end testAMoveToAnotherTeamReachesThePlatform()

	/**
	 * An employee whose end date passed last week is not in the feed.
	 *
	 * @return void
	 */
	public function testALeaverDropsOut(): void {
		$all = $this->byId();

		self::assertArrayNotHasKey('emp-leaver', $all);
		self::assertArrayHasKey('emp-stayer', $all);
		self::assertSame(['id' => 'unit-fin', 'name' => 'Finance'], $all['emp-stayer']['orgUnit']);
		self::assertSame(['f.financien'], $all['emp-stayer']['managerUserIds']);
	}//end testALeaverDropsOut()

}//end class
