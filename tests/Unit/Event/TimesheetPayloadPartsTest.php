<?php

/**
 * Timesheet payload parts test
 *
 * The four small value objects a timesheet-approved event is assembled from.
 * Two of them do real work: `TimesheetPeriodHours` derives the period grain
 * from the period string, and `TimesheetBooking` normalises a raw time-entry
 * row into the four booking fields a consumer reads. Both run on whatever a
 * stored time entry happens to hold, so the absent-field and wrong-type cases
 * are the ones worth pinning.
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
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Event;

use OCA\Humaniq\Event\TimesheetApproval;
use OCA\Humaniq\Event\TimesheetApprovedEvent;
use OCA\Humaniq\Event\TimesheetBooking;
use OCA\Humaniq\Event\TimesheetEventIds;
use OCA\Humaniq\Event\TimesheetPeriodHours;
use PHPUnit\Framework\TestCase;

/**
 * The timesheet event's payload parts.
 *
 * @covers \OCA\Humaniq\Event\TimesheetApproval
 * @covers \OCA\Humaniq\Event\TimesheetBooking
 * @covers \OCA\Humaniq\Event\TimesheetEventIds
 * @covers \OCA\Humaniq\Event\TimesheetPeriodHours
 * @uses   \OCA\Humaniq\Event\TimesheetApprovedEvent
 */
class TimesheetPayloadPartsTest extends TestCase {

	/**
	 * The period grain is derived from the period string, not supplied: a
	 * month, an ISO week and a week-day each classify to their own grain, and
	 * anything else is unknown rather than guessed.
	 *
	 * @return void
	 */
	public function testThePeriodGrainIsDerivedFromThePeriodString(): void {
		$grains = [
			'2026-02'     => TimesheetApprovedEvent::GRAIN_MONTH,
			'2026-W07'    => TimesheetApprovedEvent::GRAIN_WEEK,
			'2026-W07-3'  => TimesheetApprovedEvent::GRAIN_DAY,
			'2026-02-17'  => TimesheetApprovedEvent::GRAIN_UNKNOWN,
			'februari'    => TimesheetApprovedEvent::GRAIN_UNKNOWN,
			''            => TimesheetApprovedEvent::GRAIN_UNKNOWN,
		];

		foreach ($grains as $period => $grain) {
			$hours = new TimesheetPeriodHours((string)$period, 7.5);

			$this->assertSame($grain, $hours->periodGrain, 'period "' . $period . '"');
			$this->assertSame((string)$period, $hours->period, 'the period itself is carried unchanged');
			$this->assertSame(7.5, $hours->hours);
		}

	}//end testThePeriodGrainIsDerivedFromThePeriodString()

	/**
	 * A booking reads its four fields off a raw time entry, coercing each to
	 * the type a consumer is promised.
	 *
	 * @return void
	 */
	public function testABookingReadsItsFourFieldsOffARawTimeEntry(): void {
		$booking = new TimesheetBooking(
			[
				'projectId'  => 'project-1',
				'costCenter' => 'cc-42',
				'billable'   => true,
				'clientRef'  => 'client-7',
				'hours'      => 8,
			]
		);

		$this->assertSame('project-1', $booking->projectId);
		$this->assertSame('cc-42', $booking->costCenter);
		$this->assertTrue($booking->billable);
		$this->assertSame('client-7', $booking->clientRef);

	}//end testABookingReadsItsFourFieldsOffARawTimeEntry()

	/**
	 * A time entry missing every booking field yields empty strings and a
	 * non-billable booking — never null, so a consumer reads it without a
	 * null check, and never billable by default, since billing by accident is
	 * the worse direction.
	 *
	 * @return void
	 */
	public function testAnEntryWithoutBookingFieldsIsEmptyAndNotBillable(): void {
		$booking = new TimesheetBooking([]);

		$this->assertSame('', $booking->projectId);
		$this->assertSame('', $booking->costCenter);
		$this->assertSame('', $booking->clientRef);
		$this->assertFalse($booking->billable, 'absent means not billable');

	}//end testAnEntryWithoutBookingFieldsIsEmptyAndNotBillable()

	/**
	 * Stored entries carry whatever the writer put there, so the ids are
	 * coerced to strings and the billable flag through the usual boolean
	 * coercion — a numeric project id does not reach a consumer as an int.
	 *
	 * @return void
	 */
	public function testBookingFieldsAreCoercedToTheirPromisedTypes(): void {
		$booking = new TimesheetBooking(
			[
				'projectId'  => 42,
				'costCenter' => 7,
				'billable'   => '1',
				'clientRef'  => null,
			]
		);

		$this->assertSame('42', $booking->projectId);
		$this->assertSame('7', $booking->costCenter);
		$this->assertTrue($booking->billable);
		$this->assertSame('', $booking->clientRef, 'a null ref is the absent one');

	}//end testBookingFieldsAreCoercedToTheirPromisedTypes()

	/**
	 * A zero-hours or empty-string billable flag is not billable, which is the
	 * direction that matters for an invoice.
	 *
	 * @return void
	 */
	public function testAFalsyBillableFlagIsNotBillable(): void {
		foreach ([0, '0', '', false, null] as $flag) {
			$this->assertFalse(
				(new TimesheetBooking(['billable' => $flag]))->billable,
				'flag ' . var_export($flag, true)
			);
		}

	}//end testAFalsyBillableFlagIsNotBillable()

	/**
	 * The four ids each reach their own field. They are all strings, so a
	 * swapped pair would carry a plausible wrong id to every consumer.
	 *
	 * @return void
	 */
	public function testEachIdReachesItsOwnField(): void {
		$ids = new TimesheetEventIds('event-1', 'timesheet-2', 'employee-3', 'administration-4');

		$this->assertSame('event-1', $ids->eventId);
		$this->assertSame('timesheet-2', $ids->timesheetId);
		$this->assertSame('employee-3', $ids->employeeId);
		$this->assertSame('administration-4', $ids->administrationId);

	}//end testEachIdReachesItsOwnField()

	/**
	 * An approval carries who approved and when, in that order.
	 *
	 * @return void
	 */
	public function testAnApprovalCarriesWhoApprovedAndWhen(): void {
		$approval = new TimesheetApproval('manager-1', '2026-02-17T09:30:00+01:00');

		$this->assertSame('manager-1', $approval->approvedBy);
		$this->assertSame('2026-02-17T09:30:00+01:00', $approval->approvedAt);

	}//end testAnApprovalCarriesWhoApprovedAndWhen()

}//end class
