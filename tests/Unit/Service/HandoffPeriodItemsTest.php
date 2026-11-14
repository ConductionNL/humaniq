<?php

/**
 * Handoff period items tests
 *
 * Pins HandoffPeriodItems::mutationsFor directly: which never-sent items are
 * due in a period (open and ended ranges, statuses), the whole mutation row
 * with its sent state, and a sent item that did not change.
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
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\HandoffPeriodItems;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\FakeObjectStore;
use OCA\Humaniq\Tests\Unit\Support\FakeSchemaMapper;
use PHPUnit\Framework\TestCase;

/**
 * The period items of one employee.
 */
class HandoffPeriodItemsTest extends TestCase {

	/**
	 * The object store.
	 *
	 * @var FakeObjectStore
	 */
	private FakeObjectStore $store;

	/**
	 * The items under test.
	 *
	 * @var HandoffPeriodItems
	 */
	private HandoffPeriodItems $items;

	/**
	 * Build the items over a fake register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new FakeObjectStore();
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
		$this->items = new HandoffPeriodItems($gateway);
	}

	/**
	 * Only the items due in April are mutations, each row complete.
	 *
	 * @return void
	 */
	public function testOnlyTheItemsDueInThePeriodAreMutations(): void {
		$this->store->seed('RecurringAllowance', 'alw-ended', ['employeeId' => 'emp-a', 'kind' => 'reis', 'amountPerMonth' => 50, 'startDate' => '2026-01-01', 'endDate' => '2026-03-31', 'status' => 'active']);
		$this->store->seed('RecurringAllowance', 'alw-stopped', ['employeeId' => 'emp-a', 'kind' => 'reis', 'amountPerMonth' => 50, 'startDate' => '2026-01-01', 'status' => 'stopped']);
		$this->store->seed('RecurringAllowance', 'alw-late', ['employeeId' => 'emp-a', 'kind' => 'reis', 'amountPerMonth' => 50, 'startDate' => '2026-05-01', 'status' => 'active']);
		$this->store->seed('SickLeaveCase', 'sick-old', ['employeeId' => 'emp-a', 'firstSickDay' => '2026-02-01', 'recoveredDate' => '2026-03-31']);
		$this->store->seed('SickLeaveCase', 'sick-now', ['employeeId' => 'emp-a', 'firstSickDay' => '2026-03-20', 'recoveredDate' => '2026-04-01', 'loondoorbetalingPercentage' => '70.00']);
		$this->store->seed('LeaveTransaction', 'ltx-draft', ['employeeId' => 'emp-a', 'transactionType' => 'sell', 'hours' => 8, 'status' => 'draft']);

		self::assertSame(
			[
				[
					'kind' => 'sickness',
					'effectiveDate' => '2026-03-20',
					'fields' => [
						'firstSickDay' => ['old' => null, 'new' => '2026-03-20'],
						'recoveredDate' => ['old' => null, 'new' => '2026-04-01'],
						'loondoorbetalingPercentage' => ['old' => null, 'new' => 70.0],
					],
					'sourceSchema' => 'SickLeaveCase',
					'sourceId' => 'sick-now',
					'employeeId' => 'emp-a',
					'sentState' => ['item:sick-now' => ['firstSickDay' => '2026-03-20', 'recoveredDate' => '2026-04-01', 'loondoorbetalingPercentage' => 70.0]],
				],
			],
			$this->items->mutationsFor(employeeId: 'emp-a', period: '2026-04', sent: [])
		);
	}

	/**
	 * A sent item that did not change is no mutation, even out of its period;
	 * a sent item that changed is, from the item's start.
	 *
	 * @return void
	 */
	public function testASentItemIsAMutationOnlyWhenItChanged(): void {
		$this->store->seed('Timesheet', 'ts-mar', ['employeeId' => 'emp-a', 'period' => '2026-03', 'hours' => 160, 'status' => 'approved']);
		$this->store->seed('Expense', 'exp-1', ['employeeId' => 'emp-a', 'title' => 'Taxi', 'amount' => 30, 'expenseDate' => '2026-03-02', 'status' => 'approved', 'reimbursementRoute' => 'payroll']);
		$sent = [
			'item:ts-mar' => ['period' => '2026-03', 'hours' => 160.0, 'overtimeHours' => null],
			'item:exp-1' => ['title' => 'Taxi', 'category' => null, 'amount' => 25.0, 'taxFreeAmount' => null, 'taxableAmount' => null, 'expenseDate' => '2026-03-02'],
		];

		$mutations = $this->items->mutationsFor(employeeId: 'emp-a', period: '2026-04', sent: $sent);

		self::assertSame([['claim', 'exp-1', '2026-03-02', ['amount' => ['old' => 25.0, 'new' => 30.0]]]], array_map(static fn (array $m): array => [$m['kind'], $m['sourceId'], $m['effectiveDate'], $m['fields']], $mutations));
	}
}
