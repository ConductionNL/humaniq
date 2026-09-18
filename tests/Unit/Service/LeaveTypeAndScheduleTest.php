<?php

/**
 * Leave type, schedule and coverage tests
 *
 * Three services in one file because they answer one question between them:
 * what a leave type asks for, who is away when, and what approving one more
 * request would leave the department with.
 *
 * Each group opens with the case that must NOT warn or refuse. Without that
 * control, an assertion that a refusal fired cannot be told apart from a
 * service that refuses everything.
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
use OCA\Humaniq\Service\DepartmentLeaveScheduleService;
use OCA\Humaniq\Service\LeaveCoverageService;
use OCA\Humaniq\Service\LeaveSubmissionConditionService;
use OCA\Humaniq\Service\LeaveTypeResolver;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the leave type, schedule and coverage services.
 */
class LeaveTypeAndScheduleTest extends TestCase {

	/**
	 * The resolver.
	 *
	 * @var LeaveTypeResolver
	 */
	private LeaveTypeResolver $resolver;

	/**
	 * The submit conditions.
	 *
	 * @var LeaveSubmissionConditionService
	 */
	private LeaveSubmissionConditionService $conditions;

	/**
	 * The schedule.
	 *
	 * @var DepartmentLeaveScheduleService
	 */
	private DepartmentLeaveScheduleService $schedule;

	/**
	 * The coverage warning.
	 *
	 * @var LeaveCoverageService
	 */
	private LeaveCoverageService $coverage;

	/**
	 * Build the four subjects.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->resolver = new LeaveTypeResolver();
		$this->conditions = new LeaveSubmissionConditionService();
		$this->schedule = new DepartmentLeaveScheduleService();
		$this->coverage = new LeaveCoverageService($this->schedule);
	}//end setUp()

	/**
	 * The administered types this instance offers.
	 *
	 * @return array<int, array<string, mixed>> The types.
	 */
	private function types(): array {
		return [
			['id' => 'type-holiday', 'code' => 'holiday', 'label' => 'Vakantie', 'drawsFromBalance' => true, 'active' => true],
			['id' => 'type-unpaid', 'code' => 'unpaid', 'label' => 'Onbetaald verlof', 'drawsFromBalance' => false, 'requiresReason' => true, 'active' => true],
			['id' => 'type-sabbatical', 'code' => 'sabbatical', 'label' => 'Sabbatical', 'drawsFromBalance' => false, 'active' => false],
		];
	}//end types()

	/**
	 * A request written while `leaveType` was an enum still resolves, by code.
	 *
	 * @return void
	 */
	public function testAnOldRequestResolvesItsTypeByCode(): void {
		$byCode = $this->resolver->resolve(request: ['leaveType' => 'holiday'], types: $this->types());
		$byUuid = $this->resolver->resolve(request: ['leaveType' => 'type-holiday'], types: $this->types());

		$this->assertSame('Vakantie', ($byCode['label'] ?? null), 'The stored enum value must still find its type');
		$this->assertSame('Vakantie', ($byUuid['label'] ?? null), 'A uuid reference resolves too');
		$this->assertNull($this->resolver->resolve(request: ['leaveType' => 'zorgverlof'], types: $this->types()));
	}//end testAnOldRequestResolvesItsTypeByCode()

	/**
	 * A retired type is absent from the picker and still resolves on the
	 * requests that carry it.
	 *
	 * @return void
	 */
	public function testARetiredTypeIsNotOfferedButStillResolves(): void {
		$offerable = array_column($this->resolver->offerable(types: $this->types()), 'code');

		$this->assertSame(['holiday', 'unpaid'], $offerable);
		$this->assertSame(
			'Sabbatical',
			($this->resolver->resolve(request: ['leaveType' => 'sabbatical'], types: $this->types())['label'] ?? null),
			'A request carrying a retired type must not lose its meaning'
		);
	}//end testARetiredTypeIsNotOfferedButStillResolves()

	/**
	 * A type that draws no balance posts nothing, and an unadministered type
	 * keeps the behaviour every request had before types existed.
	 *
	 * @return void
	 */
	public function testDrawsFromBalanceFollowsTheType(): void {
		$types = $this->types();

		$this->assertTrue($this->resolver->drawsFromBalance($types[0]));
		$this->assertFalse($this->resolver->drawsFromBalance($types[1]));
		$this->assertTrue($this->resolver->drawsFromBalance(null), 'An unknown type is not a reason to stop counting holiday');
	}//end testDrawsFromBalanceFollowsTheType()

	/**
	 * The control: a request meeting its type's conditions submits.
	 *
	 * @return void
	 */
	public function testARequestMeetingItsConditionsSubmits(): void {
		$refusal = $this->conditions->refusal(
			request: ['leaveType' => 'unpaid', 'reason' => 'Verbouwing', 'startDate' => '2026-10-01'],
			type: $this->types()[1],
			today: new DateTimeImmutable('2026-09-18')
		);

		$this->assertNull($refusal);
	}//end testARequestMeetingItsConditionsSubmits()

	/**
	 * A type needing a reason refuses a submit without one, and the refusal
	 * says which condition failed.
	 *
	 * @return void
	 */
	public function testAMissingReasonIsRefusedByName(): void {
		$refusal = $this->conditions->refusal(
			request: ['leaveType' => 'unpaid', 'reason' => '   ', 'startDate' => '2026-10-01'],
			type: $this->types()[1],
			today: new DateTimeImmutable('2026-09-18')
		);

		$this->assertNotNull($refusal);
		$this->assertStringContainsString('reden', (string)$refusal, 'The refusal must name the missing reason');
		$this->assertStringContainsString('Onbetaald verlof', (string)$refusal);
	}//end testAMissingReasonIsRefusedByName()

	/**
	 * A type needing a document refuses a submit without one.
	 *
	 * @return void
	 */
	public function testAMissingDocumentIsRefusedByName(): void {
		$type = ['code' => 'parental', 'label' => 'Ouderschapsverlof', 'requiresDocument' => true];

		$without = $this->conditions->refusal(
			request: ['leaveType' => 'parental', 'startDate' => '2026-10-01'],
			type: $type,
			today: new DateTimeImmutable('2026-09-18')
		);
		$with = $this->conditions->refusal(
			request: ['leaveType' => 'parental', 'startDate' => '2026-10-01', 'documentId' => 'doc-7'],
			type: $type,
			today: new DateTimeImmutable('2026-09-18')
		);

		$this->assertStringContainsString('document', (string)$without);
		$this->assertNull($with);
	}//end testAMissingDocumentIsRefusedByName()

	/**
	 * A request starting further ahead than the type allows is refused, and
	 * one inside the window is not.
	 *
	 * @return void
	 */
	public function testANoticePeriodFurtherAheadThanAllowedIsRefused(): void {
		$type = ['code' => 'calamiteit', 'label' => 'Calamiteitenverlof', 'maxNoticeDays' => 2];
		$today = new DateTimeImmutable('2026-09-18');

		$tooFar = $this->conditions->refusal(
			request: ['startDate' => '2026-10-01'],
			type: $type,
			today: $today
		);
		$inTime = $this->conditions->refusal(
			request: ['startDate' => '2026-09-19'],
			type: $type,
			today: $today
		);

		$this->assertStringContainsString('13 dagen', (string)$tooFar, 'The refusal says how far ahead it actually is');
		$this->assertStringContainsString('maximaal 2 dagen', (string)$tooFar);
		$this->assertNull($inTime);
	}//end testANoticePeriodFurtherAheadThanAllowedIsRefused()

	/**
	 * An unadministered type carries no conditions, so a submit is not refused
	 * by an instance that has written no types yet.
	 *
	 * @return void
	 */
	public function testAnUnadministeredTypeRefusesNothing(): void {
		$this->assertNull(
			$this->conditions->refusal(
				request: ['leaveType' => 'zorgverlof', 'startDate' => '2030-01-01'],
				type: null,
				today: new DateTimeImmutable('2026-09-18')
			)
		);
	}//end testAnUnadministeredTypeRefusesNothing()

	/**
	 * The August requests used by the schedule tests.
	 *
	 * @return array<int, array<string, mixed>> The requests.
	 */
	private function augustRequests(): array {
		return [
			['id' => 'req-1', 'employeeId' => 'emp-1', 'startDate' => '2026-08-03', 'endDate' => '2026-08-07', 'status' => 'approved', 'leaveType' => 'holiday'],
			['id' => 'req-2', 'employeeId' => 'emp-2', 'startDate' => '2026-08-03', 'endDate' => '2026-08-14', 'status' => 'submitted', 'leaveType' => 'holiday'],
			['id' => 'req-3', 'employeeId' => 'emp-3', 'startDate' => '2026-08-24', 'endDate' => '2026-08-28', 'status' => 'draft', 'leaveType' => 'holiday'],
			['id' => 'req-4', 'employeeId' => 'emp-4', 'startDate' => '2026-08-10', 'endDate' => '2026-08-12', 'status' => 'rejected', 'leaveType' => 'holiday'],
			['id' => 'req-5', 'employeeId' => 'emp-outsider', 'startDate' => '2026-08-03', 'endDate' => '2026-08-07', 'status' => 'approved', 'leaveType' => 'holiday'],
			['id' => 'req-6', 'employeeId' => 'emp-1', 'startDate' => '2026-07-01', 'endDate' => '2026-07-05', 'status' => 'approved', 'leaveType' => 'holiday'],
		];
	}//end augustRequests()

	/**
	 * August is read in one view: the unit's submitted and approved requests
	 * inside the month, and nothing else.
	 *
	 * @return void
	 */
	public function testTheScheduleHoldsTheUnitsSubmittedAndApprovedRequests(): void {
		$entries = $this->schedule->compose(
			requests: $this->augustRequests(),
			memberEmployeeIds: ['emp-1', 'emp-2', 'emp-3', 'emp-4'],
			from: new DateTimeImmutable('2026-08-01'),
			to: new DateTimeImmutable('2026-08-31')
		);

		$this->assertSame(['req-1', 'req-2'], array_column($entries, 'requestId'));
		$this->assertSame('approved', $entries[0]['status'], 'A full entry carries its status');
		$this->assertSame('holiday', $entries[0]['leaveType']);
	}//end testTheScheduleHoldsTheUnitsSubmittedAndApprovedRequests()

	/**
	 * A withdrawn request leaves the schedule at once, because the schedule is
	 * the requests rather than a copy of them.
	 *
	 * @return void
	 */
	public function testAWithdrawnRequestIsGoneFromTheNextRead(): void {
		$requests = $this->augustRequests();
		$before = $this->schedule->compose(
			requests: $requests,
			memberEmployeeIds: ['emp-1', 'emp-2'],
			from: new DateTimeImmutable('2026-08-01'),
			to: new DateTimeImmutable('2026-08-31')
		);

		$requests[1]['status'] = 'draft';
		$after = $this->schedule->compose(
			requests: $requests,
			memberEmployeeIds: ['emp-1', 'emp-2'],
			from: new DateTimeImmutable('2026-08-01'),
			to: new DateTimeImmutable('2026-08-31')
		);

		$this->assertSame(['req-1', 'req-2'], array_column($before, 'requestId'));
		$this->assertSame(['req-1'], array_column($after, 'requestId'), 'No sync stands between the two reads');
	}//end testAWithdrawnRequestIsGoneFromTheNextRead()

	/**
	 * A reader who may not see a request sees that the person is unavailable
	 * and nothing further: no type, no status, no reason.
	 *
	 * @return void
	 */
	public function testARequestTheReaderMayNotSeeIsRedactedToUnavailability(): void {
		$entries = $this->schedule->compose(
			requests: $this->augustRequests(),
			memberEmployeeIds: ['emp-1', 'emp-2'],
			from: new DateTimeImmutable('2026-08-01'),
			to: new DateTimeImmutable('2026-08-31'),
			visibleRequestIds: ['req-1']
		);

		$redacted = $entries[1];

		$this->assertTrue($redacted['redacted']);
		$this->assertSame('emp-2', $redacted['employeeId'], 'Unavailability is still readable');
		$this->assertSame('2026-08-03', $redacted['startDate']);
		$this->assertArrayNotHasKey('leaveType', $redacted, 'The kind of leave is not availability');
		$this->assertArrayNotHasKey('status', $redacted);
		$this->assertFalse($entries[0]['redacted'], 'The control: the request this reader may see is not redacted');
	}//end testARequestTheReaderMayNotSeeIsRedactedToUnavailability()

	/**
	 * A unit with no administered minimum produces no warning at all.
	 *
	 * @return void
	 */
	public function testNoMinimumMeansNoWarning(): void {
		$warning = $this->coverage->warning(
			request: ['id' => 'req-new', 'employeeId' => 'emp-3', 'startDate' => '2026-08-03', 'endDate' => '2026-08-03'],
			orgUnit: ['name' => 'Burgerzaken'],
			memberEmployeeIds: ['emp-1', 'emp-2', 'emp-3'],
			otherRequests: $this->augustRequests()
		);

		$this->assertFalse($warning['belowMinimum']);
		$this->assertSame([], $warning['dates']);
		$this->assertSame('', $warning['message']);
	}//end testNoMinimumMeansNoWarning()

	/**
	 * The first week of August is said before it happens: the date, the count
	 * after approving, the minimum, and who else is away.
	 *
	 * @return void
	 */
	public function testThinCoverageIsWarnedWithDatesCountsAndNames(): void {
		// 2026-08-03 is a Monday. emp-1 and emp-2 are already away that day, so
		// approving emp-3 leaves nought of three present against a minimum of two.
		$warning = $this->coverage->warning(
			request: ['id' => 'req-new', 'employeeId' => 'emp-3', 'startDate' => '2026-08-03', 'endDate' => '2026-08-03'],
			orgUnit: ['name' => 'Burgerzaken', 'minimumPresentMonday' => 2],
			memberEmployeeIds: ['emp-1', 'emp-2', 'emp-3'],
			otherRequests: $this->augustRequests()
		);

		$this->assertTrue($warning['belowMinimum']);
		$this->assertSame('2026-08-03', $warning['dates'][0]['date']);
		$this->assertSame(0, $warning['dates'][0]['present']);
		$this->assertSame(2, $warning['dates'][0]['minimum']);
		$this->assertSame(['emp-1', 'emp-2'], $warning['dates'][0]['othersAway'], 'A manager needs the names, not only the count');
		$this->assertStringContainsString('Goedkeuren kan wel', $warning['message'], 'The warning says it does not block');
	}//end testThinCoverageIsWarnedWithDatesCountsAndNames()

	/**
	 * A minimum the unit still meets after the approval warns about nothing.
	 *
	 * @return void
	 */
	public function testAUnitThatStaysAboveItsMinimumIsNotWarnedAbout(): void {
		$warning = $this->coverage->warning(
			request: ['id' => 'req-new', 'employeeId' => 'emp-5', 'startDate' => '2026-08-17', 'endDate' => '2026-08-17'],
			orgUnit: ['minimumPresentMonday' => 2],
			memberEmployeeIds: ['emp-1', 'emp-2', 'emp-3', 'emp-5'],
			otherRequests: $this->augustRequests()
		);

		$this->assertFalse($warning['belowMinimum']);
	}//end testAUnitThatStaysAboveItsMinimumIsNotWarnedAbout()

	/**
	 * A zero minimum is "none administered", not "nobody need be present": a
	 * warning on every approval is a warning nobody reads.
	 *
	 * @return void
	 */
	public function testAZeroMinimumIsNoMinimum(): void {
		$this->assertNull(
			$this->coverage->minimumOn(orgUnit: ['minimumPresentMonday' => 0], date: new DateTimeImmutable('2026-08-03'))
		);
		$this->assertSame(
			2,
			$this->coverage->minimumOn(orgUnit: ['minimumPresentMonday' => 2], date: new DateTimeImmutable('2026-08-03'))
		);
	}//end testAZeroMinimumIsNoMinimum()
}//end class
