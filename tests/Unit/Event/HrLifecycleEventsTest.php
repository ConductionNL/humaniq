<?php

/**
 * HR lifecycle events test
 *
 * The lifecycle events are the contract between whatever notices an HR moment
 * and every listener that reacts to it. Two things in that contract can break
 * without a single error: the base constructor takes five strings in a row, so
 * a swapped pair (an account id landing in the administration field) carries a
 * plausible-looking wrong value all the way to the listener; and the two
 * boolean readers answer from a free-form data map, where an absent flag and a
 * string flag must not disagree.
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
 * @spec openspec/specs/hr-lifecycle-events/spec.md#REQ-HLE-001
 * @spec openspec/specs/hr-lifecycle-events/spec.md#REQ-HLE-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Event;

use OCA\Humaniq\Event\EmployeeJobChangedEvent;
use OCA\Humaniq\Event\EmployeeJoinedEvent;
use OCA\Humaniq\Event\EmployeeLeftEvent;
use OCA\Humaniq\Event\HrLifecycleEvent;
use OCA\Humaniq\Event\LeaveApprovedEvent;
use OCA\Humaniq\Event\SicknessReportedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * The typed HR moments and their payload contract.
 *
 * @covers \OCA\Humaniq\Event\EmployeeJobChangedEvent
 * @covers \OCA\Humaniq\Event\EmployeeJoinedEvent
 * @covers \OCA\Humaniq\Event\EmployeeLeftEvent
 * @covers \OCA\Humaniq\Event\HrLifecycleEvent
 * @covers \OCA\Humaniq\Event\LeaveApprovedEvent
 * @covers \OCA\Humaniq\Event\SicknessReportedEvent
 */
class HrLifecycleEventsTest extends TestCase {

	/**
	 * Each of the five positional strings reaches its own reader. All five
	 * have the same type, so a swapped pair would otherwise travel to every
	 * listener as a plausible wrong value.
	 *
	 * @param class-string<HrLifecycleEvent> $class The event class.
	 *
	 * @dataProvider eventClasses
	 *
	 * @return void
	 *
	 * @spec openspec/specs/hr-lifecycle-events/spec.md#REQ-HLE-001
	 */
	public function testEachPositionalFieldReachesItsOwnReader(string $class): void {
		$event = new $class(
			'event-id',
			'employee-id',
			'nextcloud-user-id',
			'administration-id',
			'2026-02-17',
			['field' => 'value']
		);

		$this->assertSame('event-id', $event->getEventId());
		$this->assertSame('employee-id', $event->getEmployeeId());
		$this->assertSame('nextcloud-user-id', $event->getNextcloudUserId());
		$this->assertSame('administration-id', $event->getAdministrationId());
		$this->assertSame('2026-02-17', $event->getOccurredOn());
		$this->assertSame(['field' => 'value'], $event->getData());

	}//end testEachPositionalFieldReachesItsOwnReader()

	/**
	 * Every lifecycle event is a Nextcloud event, which is what lets a
	 * listener be registered for it at all, and each moment is its own type,
	 * so a listener for one never fires for another.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/hr-lifecycle-events/spec.md#REQ-HLE-001
	 */
	public function testEachMomentIsItsOwnDispatchableType(): void {
		$classes = array_map(static fn (array $row): string => $row[0], self::eventClasses());

		foreach ($classes as $class) {
			$event = new $class('e', 'emp', '', '', '2026-02-17', []);

			$this->assertInstanceOf(Event::class, $event, $class . ' must be dispatchable');
			$this->assertInstanceOf(HrLifecycleEvent::class, $event);

			foreach ($classes as $other) {
				if ($other === $class) {
					continue;
				}

				$this->assertNotInstanceOf($other, $event, $class . ' must not satisfy a listener for ' . $other);
			}
		}

	}//end testEachMomentIsItsOwnDispatchableType()

	/**
	 * The empty-string fields are a real part of the contract: an employee
	 * with no account and an unknown administration are carried as '', never
	 * as null, so a listener can read them without a null check.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/hr-lifecycle-events/spec.md#REQ-HLE-001
	 */
	public function testAnAbsentAccountAndAdministrationAreCarriedAsEmptyStrings(): void {
		$event = new EmployeeJoinedEvent('e', 'emp', '', '', '2026-02-17', []);

		$this->assertSame('', $event->getNextcloudUserId());
		$this->assertSame('', $event->getAdministrationId());
		$this->assertSame([], $event->getData());

	}//end testAnAbsentAccountAndAdministrationAreCarriedAsEmptyStrings()

	/**
	 * A withdrawn leave approval is one whose data says so. An absent flag is
	 * an ordinary approval, not a withdrawal — the direction that matters,
	 * since a listener that mistook it would cancel granted leave.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/hr-lifecycle-events/spec.md#REQ-HLE-002
	 */
	public function testAWithdrawnLeaveApprovalIsTheOneWhoseDataSaysSo(): void {
		$this->assertFalse($this->leave([])->isWithdrawn(), 'no flag is an ordinary approval');
		$this->assertFalse($this->leave(['withdrawn' => false])->isWithdrawn());
		$this->assertTrue($this->leave(['withdrawn' => true])->isWithdrawn());

	}//end testAWithdrawnLeaveApprovalIsTheOneWhoseDataSaysSo()

	/**
	 * The flag arrives from a JSON data map, so the forms a transport can
	 * produce are pinned: 1 and the string "1" are withdrawn, 0 and "" are
	 * not. The string "false" reads as TRUE, as any non-empty string does —
	 * a producer must send a real boolean or 0/1, never the word.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/hr-lifecycle-events/spec.md#REQ-HLE-002
	 */
	public function testTheWithdrawnFlagFollowsTheUsualBooleanCoercion(): void {
		$this->assertTrue($this->leave(['withdrawn' => 1])->isWithdrawn());
		$this->assertTrue($this->leave(['withdrawn' => '1'])->isWithdrawn());
		$this->assertTrue($this->leave(['withdrawn' => 'false'])->isWithdrawn(), 'any non-empty string is true');
		$this->assertFalse($this->leave(['withdrawn' => 0])->isWithdrawn());
		$this->assertFalse($this->leave(['withdrawn' => ''])->isWithdrawn());
		$this->assertFalse($this->leave(['withdrawn' => null])->isWithdrawn());

	}//end testTheWithdrawnFlagFollowsTheUsualBooleanCoercion()

	/**
	 * A recovery is a sickness report whose data says recovered; an absent
	 * flag is a report of sickness, not of recovery.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/hr-lifecycle-events/spec.md#REQ-HLE-002
	 */
	public function testARecoveryIsTheSicknessReportWhoseDataSaysSo(): void {
		$this->assertFalse($this->sickness([])->isRecovered(), 'no flag is a sickness report');
		$this->assertFalse($this->sickness(['recovered' => false])->isRecovered());
		$this->assertTrue($this->sickness(['recovered' => true])->isRecovered());
		$this->assertTrue($this->sickness(['recovered' => 1])->isRecovered());
		$this->assertFalse($this->sickness(['recovered' => 0])->isRecovered());

	}//end testARecoveryIsTheSicknessReportWhoseDataSaysSo()

	/**
	 * The two flags are read from their own keys, so a sickness recovery flag
	 * never makes a leave approval look withdrawn and the other way round.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/hr-lifecycle-events/spec.md#REQ-HLE-002
	 */
	public function testTheTwoFlagsAreReadFromTheirOwnKeys(): void {
		$this->assertFalse($this->leave(['recovered' => true])->isWithdrawn());
		$this->assertFalse($this->sickness(['withdrawn' => true])->isRecovered());

	}//end testTheTwoFlagsAreReadFromTheirOwnKeys()

	/**
	 * Every lifecycle event class.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function eventClasses(): array {
		return [
			'joined'      => [EmployeeJoinedEvent::class],
			'left'        => [EmployeeLeftEvent::class],
			'job changed' => [EmployeeJobChangedEvent::class],
			'leave'       => [LeaveApprovedEvent::class],
			'sickness'    => [SicknessReportedEvent::class],
		];

	}//end eventClasses()

	/**
	 * A leave approval carrying the given data.
	 *
	 * @param array<string, mixed> $data The moment's own fields.
	 *
	 * @return LeaveApprovedEvent
	 */
	private function leave(array $data): LeaveApprovedEvent {
		return new LeaveApprovedEvent('e', 'emp', 'user', 'adm', '2026-02-17', $data);
	}//end leave()

	/**
	 * A sickness report carrying the given data.
	 *
	 * @param array<string, mixed> $data The moment's own fields.
	 *
	 * @return SicknessReportedEvent
	 */
	private function sickness(array $data): SicknessReportedEvent {
		return new SicknessReportedEvent('e', 'emp', 'user', 'adm', '2026-02-17', $data);
	}//end sickness()

}//end class
