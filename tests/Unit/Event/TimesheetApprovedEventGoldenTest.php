<?php

/**
 * Golden test for the typed approved-timesheet event.
 *
 * Builds the event through TimeEntryEventService::buildTypedEvent() for
 * timesheets of every period grain, with and without project, cost centre,
 * client, administration, billable flag and approval stamp, with numeric,
 * string and missing hours, and records every getter plus the event's own
 * fields in declaration order. Every case is compared with
 * tests/fixtures/golden/timesheet-approved-event.json. Set RECORD_GOLDEN=1 to
 * rewrite that file.
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
 * @spec openspec/specs/humaniq-timesheet-approved-typed-event/spec.md#Requirement:-A-typed-cross-app-event-SHALL-accompany-the-approved-timesheet-webhook
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Event;

use OCA\Humaniq\Event\TimesheetApprovedEvent;
use OCA\Humaniq\Service\TimeEntryEventService;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionObject;

/**
 * Golden cases for the typed approved-timesheet event.
 *
 * @covers \OCA\Humaniq\Event\TimesheetApprovedEvent
 * @covers \OCA\Humaniq\Service\TimeEntryEventService
 * @uses   \OCA\Humaniq\Event\TimesheetApproval
 * @uses   \OCA\Humaniq\Event\TimesheetBooking
 * @uses   \OCA\Humaniq\Event\TimesheetEventIds
 * @uses   \OCA\Humaniq\Event\TimesheetPeriodHours
 */
class TimesheetApprovedEventGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/golden/timesheet-approved-event.json';

	/**
	 * Every case, by name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function entries(): array {
		$full = [
			'id' => 'ts-1',
			'employeeId' => 'emp-1',
			'period' => '2026-07',
			'hours' => 36.5,
			'projectId' => 'proj-alpha',
			'costCenter' => 'cc-42',
			'billable' => true,
			'clientRef' => 'client-7',
			'administrationId' => 'ADM-001',
			'approvedBy' => 'manager-jansen',
			'approvedAt' => '2026-07-15T10:00:00Z',
		];

		return [
			'month, everything' => $full,
			'week' => (['period' => '2026-W29'] + $full),
			'day' => (['period' => '2026-W29-3'] + $full),
			'unknown grain' => (['period' => 'juli'] + $full),
			'empty period' => (['period' => ''] + $full),
			'uuid instead of id' => (['uuid' => 'ts-uuid'] + array_diff_key($full, ['id' => true])),
			'string hours, not billable' => (['hours' => '7.25', 'billable' => false] + $full),
			'integer hours' => (['hours' => 8] + $full),
			'bare' => ['id' => 'ts-2', 'approvedAt' => '2026-01-02T03:04:05Z'],
			'numeric references' => (['employeeId' => 12, 'projectId' => 3, 'administrationId' => 7] + $full),
		];

	}//end entries()

	/**
	 * Every case, compared with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$service = new TimeEntryEventService(
			container: $this->createMock(ContainerInterface::class),
			eventDispatcher: $this->createMock(IEventDispatcher::class),
			logger: $this->createMock(LoggerInterface::class)
		);

		$actual = [];
		foreach (self::entries() as $name => $entry) {
			$actual[$name] = $this->describe($service->buildTypedEvent($entry));
		}

		$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, $flags) . "\n");
			$this->markTestSkipped('recorded');
		}

		$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		$this->assertSame(array_keys($golden), array_keys($actual));
		foreach ($golden as $name => $expected) {
			$this->assertSame(json_encode($expected, $flags), json_encode($actual[$name], $flags), $name);
		}

	}//end testGolden()

	/**
	 * Every getter and the event's own fields, in declaration order.
	 *
	 * @param TimesheetApprovedEvent $event The event.
	 *
	 * @return array<string, mixed>
	 */
	private function describe(TimesheetApprovedEvent $event): array {
		$fields = [];
		foreach ((new ReflectionObject($event))->getProperties() as $property) {
			if ($property->getDeclaringClass()->getName() !== TimesheetApprovedEvent::class) {
				continue;
			}

			$fields[$property->getName()] = $property->getValue($event);
		}

		return [
			'getters' => [
				'eventId' => $event->getEventId(),
				'timesheetId' => $event->getTimesheetId(),
				'employeeId' => $event->getEmployeeId(),
				'period' => $event->getPeriod(),
				'periodGrain' => $event->getPeriodGrain(),
				'hours' => $event->getHours(),
				'projectId' => $event->getProjectId(),
				'costCenter' => $event->getCostCenter(),
				'billable' => $event->isBillable(),
				'clientRef' => $event->getClientRef(),
				'administrationId' => $event->getAdministrationId(),
				'approvedBy' => $event->getApprovedBy(),
				'approvedAt' => $event->getApprovedAt(),
			],
			'fields' => $fields,
		];

	}//end describe()

}//end class
