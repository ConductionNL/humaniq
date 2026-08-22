<?php

/**
 * Unit tests for TimesheetApprovedEvent.
 *
 * Pins the typed cross-app event's own contract, independent of the service
 * that builds it: getters expose the constructor values a listener consumes,
 * and `classifyPeriodGrain()` correctly classifies every Timesheet.period
 * shape (`YYYY-MM` / `YYYY-Www` / `YYYY-Wnn-D`) plus the unrecognised case.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Event
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
 * @spec openspec/changes/humaniq-timesheet-approved-typed-event/specs/humaniq-timesheet-approved-typed-event/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Event;

use OCA\Humaniq\Event\TimesheetApprovedEvent;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TimesheetApprovedEvent.
 *
 * @spec openspec/changes/humaniq-timesheet-approved-typed-event/specs/humaniq-timesheet-approved-typed-event/spec.md
 */
class TimesheetApprovedEventTest extends TestCase {

	/**
	 * Every getter exposes exactly the value passed to the constructor.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/humaniq-timesheet-approved-typed-event/specs/humaniq-timesheet-approved-typed-event/spec.md#Requirement:-A-typed-cross-app-event-SHALL-accompany-the-approved-timesheet-webhook
	 */
	public function testGettersExposeConstructorValues(): void {
		$event = new TimesheetApprovedEvent(
			eventId: 'evt-1',
			timesheetId: 'ts-1',
			employeeId: 'emp-1',
			period: '2026-07',
			periodGrain: TimesheetApprovedEvent::GRAIN_MONTH,
			hours: 36.5,
			projectId: 'proj-alpha',
			costCenter: 'cc-42',
			billable: true,
			clientRef: 'client-7',
			administrationId: 'ADM-001',
			approvedBy: 'manager-jansen',
			approvedAt: '2026-07-15T10:00:00Z',
		);

		$this->assertSame('evt-1', $event->getEventId());
		$this->assertSame('ts-1', $event->getTimesheetId());
		$this->assertSame('emp-1', $event->getEmployeeId());
		$this->assertSame('2026-07', $event->getPeriod());
		$this->assertSame(TimesheetApprovedEvent::GRAIN_MONTH, $event->getPeriodGrain());
		$this->assertSame(36.5, $event->getHours());
		$this->assertSame('proj-alpha', $event->getProjectId());
		$this->assertSame('cc-42', $event->getCostCenter());
		$this->assertTrue($event->isBillable());
		$this->assertSame('client-7', $event->getClientRef());
		$this->assertSame('ADM-001', $event->getAdministrationId());
		$this->assertSame('manager-jansen', $event->getApprovedBy());
		$this->assertSame('2026-07-15T10:00:00Z', $event->getApprovedAt());

	}//end testGettersExposeConstructorValues()

	/**
	 * classifyPeriodGrain() recognises every documented Timesheet.period shape
	 * and falls back to `unknown` for anything else — never throws.
	 *
	 * @param string $period The raw period string.
	 * @param string $expectedGrain The expected `GRAIN_*` constant.
	 *
	 * @return void
	 *
	 * @dataProvider periodGrainProvider
	 *
	 * @spec openspec/changes/humaniq-timesheet-approved-typed-event/specs/humaniq-timesheet-approved-typed-event/spec.md#Requirement:-The-typed-event-SHALL-carry-the-raw-period-plus-an-explicit-grain-marker
	 */
	public function testClassifyPeriodGrain(string $period, string $expectedGrain): void {
		$this->assertSame($expectedGrain, TimesheetApprovedEvent::classifyPeriodGrain($period));

	}//end testClassifyPeriodGrain()

	/**
	 * Data provider for {@see testClassifyPeriodGrain()}.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function periodGrainProvider(): array {
		return [
			'calendar month' => ['2026-07', TimesheetApprovedEvent::GRAIN_MONTH],
			'calendar month, single digit padded' => ['2026-01', TimesheetApprovedEvent::GRAIN_MONTH],
			'ISO week' => ['2026-W29', TimesheetApprovedEvent::GRAIN_WEEK],
			'ISO week-day' => ['2026-W29-3', TimesheetApprovedEvent::GRAIN_DAY],
			'ISO week-day, Sunday' => ['2026-W29-7', TimesheetApprovedEvent::GRAIN_DAY],
			'empty string' => ['', TimesheetApprovedEvent::GRAIN_UNKNOWN],
			'quarter shorthand' => ['Q3-2026', TimesheetApprovedEvent::GRAIN_UNKNOWN],
			'full ISO date' => ['2026-07-15', TimesheetApprovedEvent::GRAIN_UNKNOWN],
			'year only' => ['2026', TimesheetApprovedEvent::GRAIN_UNKNOWN],
		];

	}//end periodGrainProvider()

}//end class
