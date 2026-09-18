<?php

/**
 * Agenda, availability, capacity, competence and booking tests
 *
 * Five services that answer one question between them: who can do this on
 * Thursday, and what would it cost.
 *
 * Every group opens with the case that must pass, as its control. Without it,
 * "the unqualified colleague was filtered out" cannot be told apart from "this
 * query returns nobody", and "the third booking was refused" cannot be told
 * apart from "every booking is refused".
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

use DateTimeImmutable;
use OCA\Humaniq\Service\AgendaComposer;
use OCA\Humaniq\Service\AvailabilityService;
use OCA\Humaniq\Service\CompetenceCheckService;
use OCA\Humaniq\Service\ForwardCapacityService;
use OCA\Humaniq\Service\IcalBusyParser;
use OCA\Humaniq\Service\ResourceBookingService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the agenda, availability, capacity, competence and booking
 * services.
 */
class AgendaAndAvailabilityTest extends TestCase {

	/**
	 * The composer.
	 *
	 * @var AgendaComposer
	 */
	private AgendaComposer $agenda;

	/**
	 * The competence check.
	 *
	 * @var CompetenceCheckService
	 */
	private CompetenceCheckService $competences;

	/**
	 * The availability query.
	 *
	 * @var AvailabilityService
	 */
	private AvailabilityService $availability;

	/**
	 * The forward capacity read.
	 *
	 * @var ForwardCapacityService
	 */
	private ForwardCapacityService $capacity;

	/**
	 * The booking clash rule.
	 *
	 * @var ResourceBookingService
	 */
	private ResourceBookingService $bookings;

	/**
	 * Build the subjects.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->agenda = new AgendaComposer();
		$this->competences = new CompetenceCheckService();
		$this->availability = new AvailabilityService(agenda: $this->agenda, competences: $this->competences);
		$this->capacity = new ForwardCapacityService(availability: $this->availability);
		$this->bookings = new ResourceBookingService();
	}//end setUp()

	/**
	 * A full-time pattern for one employee, from 2026-01-01.
	 *
	 * @param string $employeeId The employee.
	 * @param float $hoursPerDay Contracted hours on each of Monday to Friday.
	 *
	 * @return array<string, mixed> The pattern.
	 */
	private function pattern(string $employeeId, float $hoursPerDay = 8.0): array {
		return [
			'employeeId' => $employeeId,
			'validFrom' => '2026-01-01',
			'hoursMonday' => $hoursPerDay,
			'hoursTuesday' => $hoursPerDay,
			'hoursWednesday' => $hoursPerDay,
			'hoursThursday' => $hoursPerDay,
			'hoursFriday' => $hoursPerDay,
		];
	}//end pattern()

	/**
	 * A handler's week reads from five sources at once, each entry naming its
	 * kind and the object it came from.
	 *
	 * @return void
	 */
	public function testAWeekReadsFromEverySourceAtOnce(): void {
		// 2026-06-01 is a Monday, 2026-06-03 a Wednesday, 2026-06-05 a Friday.
		$entries = $this->agenda->compose(
			sources: [
				'assignments' => [
					['id' => 'assign-1', 'employeeId' => 'emp-1', 'shiftId' => 'shift-1', 'date' => '2026-06-01', 'plannedStart' => '2026-06-01T09:00:00+02:00', 'plannedEnd' => '2026-06-01T17:00:00+02:00'],
				],
				'shifts' => [['id' => 'shift-1', 'name' => 'Vroege dienst']],
				'leaveRequests' => [
					['id' => 'leave-1', 'employeeId' => 'emp-1', 'startDate' => '2026-06-03', 'endDate' => '2026-06-03', 'status' => 'approved', 'leaveType' => 'holiday'],
					['id' => 'leave-2', 'employeeId' => 'emp-1', 'startDate' => '2026-06-04', 'endDate' => '2026-06-04', 'status' => 'submitted', 'leaveType' => 'holiday'],
				],
				'bookings' => [
					['id' => 'booking-1', 'employeeId' => 'emp-1', 'resourceId' => 'room-2', 'start' => '2026-06-05T10:00:00+02:00', 'end' => '2026-06-05T12:00:00+02:00', 'purpose' => 'Hoorzitting'],
				],
			],
			subjectType: 'employee',
			subjectId: 'emp-1',
			from: '2026-06-01',
			to: '2026-06-07'
		);

		$this->assertSame(['shift', 'leave', 'booking'], array_column($entries, 'kind'));
		$this->assertSame(['RosterAssignment', 'LeaveRequest', 'ResourceBooking'], array_column($entries, 'sourceType'));
		$this->assertSame('Vroege dienst', $entries[0]['label'], 'A shift entry names its shift');
	}//end testAWeekReadsFromEverySourceAtOnce()

	/**
	 * A withdrawn request leaves the agenda at once, because the agenda is the
	 * requests rather than a copy of them.
	 *
	 * @return void
	 */
	public function testAWithdrawnLeaveRequestIsGoneFromTheNextRead(): void {
		$sources = [
			'leaveRequests' => [
				['id' => 'leave-1', 'employeeId' => 'emp-1', 'startDate' => '2026-06-03', 'endDate' => '2026-06-03', 'status' => 'approved'],
			],
		];

		$before = $this->agenda->compose(sources: $sources, subjectType: 'employee', subjectId: 'emp-1', from: '2026-06-01', to: '2026-06-07');
		$sources['leaveRequests'][0]['status'] = 'draft';
		$after = $this->agenda->compose(sources: $sources, subjectType: 'employee', subjectId: 'emp-1', from: '2026-06-01', to: '2026-06-07');

		$this->assertCount(1, $before);
		$this->assertSame([], $after, 'No sync stands between the two reads');
	}//end testAWithdrawnLeaveRequestIsGoneFromTheNextRead()

	/**
	 * The reason for an absence never reaches the agenda: the entry says
	 * absent and carries no reason, diagnosis or progression.
	 *
	 * @return void
	 */
	public function testAnAbsenceEntryCarriesNoReason(): void {
		$entries = $this->agenda->compose(
			sources: [
				'sickLeaveCases' => [
					[
						'id' => 'case-1',
						'employeeId' => 'emp-1',
						'firstSickDay' => '2026-06-02',
						'status' => 'gemeld',
						'reason' => 'Burn-out na reorganisatie',
						'absenceProgression' => [['effectiveFrom' => '2026-06-02', 'absencePercentage' => 100]],
					],
				],
			],
			subjectType: 'employee',
			subjectId: 'emp-1',
			from: '2026-06-01',
			to: '2026-06-07'
		);

		$this->assertCount(1, $entries);
		$this->assertSame('absent', $entries[0]['kind']);
		$this->assertSame('emp-1', $entries[0]['subjectId'], 'The person is named, which is the point of the entry');
		$this->assertArrayNotHasKey('reason', $entries[0]);
		$this->assertArrayNotHasKey('absenceProgression', $entries[0]);
		$this->assertStringNotContainsString('Burn-out', json_encode($entries[0]) ?: '');
	}//end testAnAbsenceEntryCarriesNoReason()

	/**
	 * Busy time from a private calendar reaches the agenda without its title.
	 *
	 * @return void
	 */
	public function testExternalBusyTimeCarriesNoTitle(): void {
		$entries = $this->agenda->compose(
			sources: [
				'subscriptions' => [
					[
						'id' => 'sub-1',
						'employeeId' => 'emp-1',
						'busyPeriods' => [['start' => '2026-06-04T09:00:00+02:00', 'end' => '2026-06-04T12:00:00+02:00']],
					],
				],
			],
			subjectType: 'employee',
			subjectId: 'emp-1',
			from: '2026-06-01',
			to: '2026-06-07'
		);

		$this->assertCount(1, $entries);
		$this->assertSame('busy', $entries[0]['kind']);
		$this->assertSame('Bezet', $entries[0]['label'], 'The label is the word "busy", not the event');
	}//end testExternalBusyTimeCarriesNoTitle()

	/**
	 * An expired qualification stops counting on its own date, and the check
	 * names the employee, the date and the competence.
	 *
	 * @return void
	 */
	public function testAnExpiredCompetenceProducesAFindingOnItsOwnDate(): void {
		$shifts = ['shift-1' => ['id' => 'shift-1', 'name' => 'Nachtcontrole horeca', 'requiredCompetences' => ['boa-domein-1']]];
		$competences = [
			['employeeId' => 'emp-1', 'competenceCode' => 'boa-domein-1', 'issuedOn' => '2025-01-01', 'validUntil' => '2026-06-02'],
		];

		$inDate = $this->competences->findings(
			assignments: [['id' => 'a-1', 'employeeId' => 'emp-1', 'shiftId' => 'shift-1', 'date' => '2026-06-02']],
			shiftsById: $shifts,
			competences: $competences
		);
		$expired = $this->competences->findings(
			assignments: [['id' => 'a-2', 'employeeId' => 'emp-1', 'shiftId' => 'shift-1', 'date' => '2026-06-03']],
			shiftsById: $shifts,
			competences: $competences
		);

		$this->assertSame([], $inDate, 'The control: on the last valid day the shift is fine');
		$this->assertCount(1, $expired);
		$this->assertSame('competence', $expired[0]['kind'], 'The finding has its own kind');
		$this->assertSame('boa-domein-1', $expired[0]['competence']);
		$this->assertSame('2026-06-03', $expired[0]['date']);
		$this->assertStringContainsString('emp-1', $expired[0]['statement']);
	}//end testAnExpiredCompetenceProducesAFindingOnItsOwnDate()

	/**
	 * A shift naming no competence produces no finding, however unqualified
	 * the person is.
	 *
	 * @return void
	 */
	public function testAShiftNeedingNothingIsNeverAFinding(): void {
		$findings = $this->competences->findings(
			assignments: [['id' => 'a-1', 'employeeId' => 'emp-1', 'shiftId' => 'shift-1', 'date' => '2026-06-03']],
			shiftsById: ['shift-1' => ['id' => 'shift-1', 'name' => 'Baliedienst']],
			competences: []
		);

		$this->assertSame([], $findings);
	}//end testAShiftNeedingNothingIsNeverAFinding()

	/**
	 * An unqualified colleague is not offered, and the qualified one is.
	 *
	 * @return void
	 */
	public function testAvailabilityOffersOnlyTheQualified(): void {
		$sources = [
			'workingPatterns' => [$this->pattern('emp-boa'), $this->pattern('emp-other')],
			'competences' => [
				['employeeId' => 'emp-boa', 'competenceCode' => 'boa-domein-1', 'issuedOn' => '2025-01-01'],
			],
			'nonWorkingDates' => [],
		];

		$everybody = $this->availability->availability(
			employeeIds: ['emp-boa', 'emp-other'],
			from: new DateTimeImmutable('2026-06-04'),
			to: new DateTimeImmutable('2026-06-04'),
			sources: $sources
		);
		$qualified = $this->availability->availability(
			employeeIds: ['emp-boa', 'emp-other'],
			from: new DateTimeImmutable('2026-06-04'),
			to: new DateTimeImmutable('2026-06-04'),
			sources: $sources,
			requiredCompetences: ['boa-domein-1']
		);

		$this->assertCount(2, $everybody, 'The control: both are free on Thursday');
		$this->assertSame(['emp-boa'], array_column($qualified, 'employeeId'));
		$this->assertSame(8.0, $qualified[0]['freeHours'], 'Free hours, not a yes or a no');
	}//end testAvailabilityOffersOnlyTheQualified()

	/**
	 * Busy time from a subscribed calendar is deducted from free hours, and
	 * the event's title is nowhere in the answer.
	 *
	 * @return void
	 */
	public function testExternalBusyTimeIsDeductedFromFreeHours(): void {
		$sources = [
			'workingPatterns' => [$this->pattern('emp-1')],
			'nonWorkingDates' => [],
		];

		$without = $this->availability->availability(
			employeeIds: ['emp-1'],
			from: new DateTimeImmutable('2026-06-04'),
			to: new DateTimeImmutable('2026-06-04'),
			sources: $sources
		);

		$sources['subscriptions'] = [
			[
				'id' => 'sub-1',
				'employeeId' => 'emp-1',
				'busyPeriods' => [['start' => '2026-06-04T09:00:00+02:00', 'end' => '2026-06-04T12:00:00+02:00']],
			],
		];

		$with = $this->availability->availability(
			employeeIds: ['emp-1'],
			from: new DateTimeImmutable('2026-06-04'),
			to: new DateTimeImmutable('2026-06-04'),
			sources: $sources
		);

		$this->assertSame(8.0, $without[0]['freeHours'], 'The control: a whole contracted day');
		$this->assertSame(5.0, $with[0]['freeHours'], 'Three hours of private appointment are gone');
	}//end testExternalBusyTimeIsDeductedFromFreeHours()

	/**
	 * Approved leave costs the contracted day rather than twenty-four hours,
	 * so a part-timer's free time cannot go negative.
	 *
	 * @return void
	 */
	public function testApprovedLeaveCostsTheContractedDay(): void {
		$answer = $this->availability->availability(
			employeeIds: ['emp-1'],
			from: new DateTimeImmutable('2026-06-04'),
			to: new DateTimeImmutable('2026-06-04'),
			sources: [
				'workingPatterns' => [$this->pattern('emp-1', 4.8)],
				'leaveRequests' => [
					['id' => 'leave-1', 'employeeId' => 'emp-1', 'startDate' => '2026-06-04', 'endDate' => '2026-06-04', 'status' => 'approved'],
				],
				'nonWorkingDates' => [],
			]
		);

		$this->assertSame(4.8, $answer[0]['committedHours']);
		$this->assertSame(0.0, $answer[0]['freeHours']);
	}//end testApprovedLeaveCostsTheContractedDay()

	/**
	 * A part-timer is measured against their own week, not the instance's
	 * full-time one.
	 *
	 * @return void
	 */
	public function testCapacityMeasuresAPartTimerAgainstTheirOwnWeek(): void {
		// A 24-hour week: three eight-hour days, Monday to Wednesday.
		$pattern = [
			'employeeId' => 'emp-1',
			'validFrom' => '2026-01-01',
			'hoursMonday' => 8,
			'hoursTuesday' => 8,
			'hoursWednesday' => 8,
		];

		$report = $this->capacity->capacity(
			employeeIds: ['emp-1'],
			from: new DateTimeImmutable('2026-06-01'),
			to: new DateTimeImmutable('2026-06-07'),
			sources: [
				'workingPatterns' => [$pattern],
				'nonWorkingDates' => [],
				'shifts' => [['id' => 'shift-1', 'name' => 'Dagdienst']],
				'assignments' => [
					['id' => 'a-1', 'employeeId' => 'emp-1', 'shiftId' => 'shift-1', 'date' => '2026-06-01', 'plannedStart' => '2026-06-01T09:00:00+02:00', 'plannedEnd' => '2026-06-01T17:00:00+02:00'],
					['id' => 'a-2', 'employeeId' => 'emp-1', 'shiftId' => 'shift-1', 'date' => '2026-06-02', 'plannedStart' => '2026-06-02T09:00:00+02:00', 'plannedEnd' => '2026-06-02T17:00:00+02:00'],
					['id' => 'a-3', 'employeeId' => 'emp-1', 'shiftId' => 'shift-1', 'date' => '2026-06-03', 'plannedStart' => '2026-06-03T09:00:00+02:00', 'plannedEnd' => '2026-06-03T13:00:00+02:00'],
				],
			]
		);

		$this->assertSame(20.0, $report['employees'][0]['plannedHours']);
		$this->assertSame(24.0, $report['employees'][0]['contractedHours'], 'Against their own 24-hour week');
		$this->assertSame(83.3, $report['employees'][0]['utilisationPercentage']);
	}//end testCapacityMeasuresAPartTimerAgainstTheirOwnWeek()

	/**
	 * A missing contract is said, not guessed: no contracted hours and no
	 * percentage, and the answer counts how many people are in that state.
	 *
	 * @return void
	 */
	public function testAMissingContractIsSaidRatherThanSubstituted(): void {
		$report = $this->capacity->capacity(
			employeeIds: ['emp-nopattern'],
			from: new DateTimeImmutable('2026-06-01'),
			to: new DateTimeImmutable('2026-06-07'),
			sources: ['workingPatterns' => [], 'nonWorkingDates' => []]
		);

		$this->assertFalse($report['employees'][0]['hasContractedHours']);
		$this->assertNull($report['employees'][0]['contractedHours'], 'The instance default is never substituted');
		$this->assertNull($report['employees'][0]['utilisationPercentage']);
		$this->assertSame(1, $report['totals']['employeesWithoutContractedHours']);
	}//end testAMissingContractIsSaidRatherThanSubstituted()

	/**
	 * The second booking of one room loses, and the refusal names the booking
	 * that blocks it.
	 *
	 * @return void
	 */
	public function testTheSecondBookingOfOneRoomIsRefused(): void {
		$room = ['id' => 'room-2', 'name' => 'Hoorzittingzaal 2', 'quantity' => 1, 'active' => true];
		$existing = [
			['id' => 'booking-1', 'resourceId' => 'room-2', 'employeeId' => 'emp-1', 'start' => '2026-06-05T10:00:00+02:00', 'end' => '2026-06-05T12:00:00+02:00'],
		];

		$afterwards = $this->bookings->refusal(
			booking: ['resourceId' => 'room-2', 'start' => '2026-06-05T12:00:00+02:00', 'end' => '2026-06-05T13:00:00+02:00'],
			resource: $room,
			existing: $existing
		);
		$overlapping = $this->bookings->refusal(
			booking: ['resourceId' => 'room-2', 'start' => '2026-06-05T11:00:00+02:00', 'end' => '2026-06-05T13:00:00+02:00'],
			resource: $room,
			existing: $existing
		);

		$this->assertNull($afterwards, 'The control: a booking that starts when the other ends is fine');
		$this->assertNotNull($overlapping);
		$this->assertStringContainsString('Hoorzittingzaal 2', (string)$overlapping);
		$this->assertStringContainsString('2026-06-05T10:00:00+02:00', (string)$overlapping, 'The refusal names the blocking booking');
	}//end testTheSecondBookingOfOneRoomIsRefused()

	/**
	 * Three inspectors and two meters: the third overlapping booking is
	 * refused and a fourth outside the overlap is accepted.
	 *
	 * @return void
	 */
	public function testAQuantityOfTwoAcceptsTwoAndRefusesTheThird(): void {
		$meter = ['id' => 'meter', 'name' => 'Geluidsmeter', 'quantity' => 2, 'active' => true];
		$existing = [
			['id' => 'b-1', 'resourceId' => 'meter', 'start' => '2026-06-05T10:00:00+02:00', 'end' => '2026-06-05T12:00:00+02:00'],
			['id' => 'b-2', 'resourceId' => 'meter', 'start' => '2026-06-05T10:30:00+02:00', 'end' => '2026-06-05T11:30:00+02:00'],
		];

		$third = $this->bookings->refusal(
			booking: ['resourceId' => 'meter', 'start' => '2026-06-05T11:00:00+02:00', 'end' => '2026-06-05T13:00:00+02:00'],
			resource: $meter,
			existing: $existing
		);
		$outside = $this->bookings->refusal(
			booking: ['resourceId' => 'meter', 'start' => '2026-06-05T14:00:00+02:00', 'end' => '2026-06-05T15:00:00+02:00'],
			resource: $meter,
			existing: $existing
		);

		$this->assertNotNull($third, 'Two meters are already out');
		$this->assertNull($outside, 'A booking outside the overlap is ordinary');
	}//end testAQuantityOfTwoAcceptsTwoAndRefusesTheThird()

	/**
	 * A resource out of service refuses a booking, even with nothing else
	 * booked on it.
	 *
	 * @return void
	 */
	public function testAResourceOutOfServiceRefusesABooking(): void {
		$refusal = $this->bookings->refusal(
			booking: ['resourceId' => 'room-2', 'start' => '2026-06-05T10:00:00+02:00', 'end' => '2026-06-05T12:00:00+02:00'],
			resource: ['id' => 'room-2', 'name' => 'Hoorzittingzaal 2', 'quantity' => 1, 'active' => false],
			existing: []
		);

		$this->assertStringContainsString('buiten gebruik', (string)$refusal);
	}//end testAResourceOutOfServiceRefusesABooking()

	/**
	 * A feed's events become periods, and its titles do not become anything.
	 *
	 * @return void
	 */
	public function testTheIcalParserKeepsPeriodsAndDropsEverythingElse(): void {
		$ical = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"
			. "BEGIN:VEVENT\r\nSUMMARY:Tandarts\r\nLOCATION:Praktijk Centrum\r\n"
			. "DTSTART:20260604T070000Z\r\nDTEND:20260604T100000Z\r\nEND:VEVENT\r\n"
			. "BEGIN:VEVENT\r\nSUMMARY:Afgezegd\r\nSTATUS:CANCELLED\r\n"
			. "DTSTART:20260605T070000Z\r\nDTEND:20260605T080000Z\r\nEND:VEVENT\r\n"
			. "END:VCALENDAR\r\n";

		$periods = (new IcalBusyParser())->busyPeriods($ical);

		$this->assertCount(1, $periods, 'A cancelled event is not busy time');
		$this->assertSame(['start', 'end'], array_keys($periods[0]), 'A period has two fields and no third');
		$this->assertStringContainsString('2026-06-04', $periods[0]['start']);
		$this->assertStringNotContainsString('Tandarts', json_encode($periods) ?: '');
	}//end testTheIcalParserKeepsPeriodsAndDropsEverythingElse()
}//end class
