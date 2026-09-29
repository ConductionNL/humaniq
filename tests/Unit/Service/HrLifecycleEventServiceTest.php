<?php

/**
 * Unit tests for HrLifecycleEventService (platform-hr-lifecycle-events D1 to D3).
 *
 * Every edge of design D1 on the real gateway over the in-memory store, with a
 * spy in the place of OpenRegister's WebhookService and a recording
 * dispatcher for the typed events: one event per moment, none on a repeated
 * save, and payloads that hold only design D2's fields.
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
 * @spec openspec/specs/hr-lifecycle-events/spec.md#REQ-HLE-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Event\EmployeeJobChangedEvent;
use OCA\Humaniq\Event\EmployeeJoinedEvent;
use OCA\Humaniq\Event\EmployeeLeftEvent;
use OCA\Humaniq\Event\LeaveApprovedEvent;
use OCA\Humaniq\Event\SicknessReportedEvent;
use OCA\Humaniq\Service\HrLifecycleEventService;
use OCA\Humaniq\Service\HrLifecycleMoments;
use OCA\Humaniq\Tests\Unit\Support\ApprovalsFixture;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class HrLifecycleEventServiceTest extends TestCase {

	use ApprovalsFixture;

	/**
	 * @var array<int, array{name: string, payload: array<string, mixed>}>
	 */
	public array $sent = [];

	/**
	 * @var array<int, object>
	 */
	public array $typed = [];

	public bool $typedThrows = false;

	protected function setUp(): void {
		parent::setUp();
		$this->seedTeam();
		$this->store->state->objects['Employee']['emp-sam']['administrationId'] = 'ADM-001';
	}//end setUp()

	/**
	 * Scenario: the identity manager hears about a leaver.
	 */
	public function testCompletingAnOffboardingSendsOneLeftEvent(): void {
		$old = ['employeeId' => 'emp-sam', 'lastWorkingDay' => '2026-10-31', 'reason' => 'Nieuwe baan', 'status' => 'eindafrekening_gereed'];

		self::assertSame(1, $this->service()->emit('offboarding', $old, array_merge($old, ['id' => 'off-1', 'status' => 'afgerond'])));
		self::assertSame('nl.conduction.hrmq.employee.left', $this->sent[0]['name']);
		self::assertSame('emp-sam', $this->sent[0]['payload']['subject']);
		self::assertSame(
			['employeeId' => 'emp-sam', 'nextcloudUserId' => 'sam', 'administrationId' => 'ADM-001', 'occurredOn' => '2026-10-31', 'lastWorkingDay' => '2026-10-31'],
			$this->sent[0]['payload']['data']
		);
		self::assertInstanceOf(EmployeeLeftEvent::class, $this->typed[0]);
		self::assertSame($this->sent[0]['payload']['id'], $this->typed[0]->getEventId());
		self::assertSame('sam', $this->typed[0]->getNextcloudUserId());
	}//end testCompletingAnOffboardingSendsOneLeftEvent()

	/**
	 * Scenario: saving twice does not send twice.
	 */
	public function testSavingAnApprovedLeaveAgainSendsNothing(): void {
		$leave = ['id' => 'lv-1', 'employeeId' => 'emp-sam', 'leaveType' => 'care', 'reason' => 'Zorg voor moeder', 'startDate' => '2026-08-03', 'endDate' => '2026-08-07', 'hours' => 40, 'status' => 'submitted'];
		$approved = array_merge($leave, ['status' => 'approved']);

		self::assertSame(1, $this->service()->emit('leaverequest', $leave, $approved));
		self::assertSame(0, $this->service()->emit('leaverequest', $approved, $approved));
		self::assertCount(1, $this->sent);
		self::assertSame('nl.conduction.hrmq.leave.approved', $this->sent[0]['name']);
		self::assertSame(['startDate' => '2026-08-03', 'endDate' => '2026-08-07', 'hours' => 40.0], array_intersect_key($this->sent[0]['payload']['data'], ['startDate' => 1, 'endDate' => 1, 'hours' => 1]));
		self::assertArrayNotHasKey('leaveType', $this->sent[0]['payload']['data']);
		self::assertArrayNotHasKey('reason', $this->sent[0]['payload']['data']);
		self::assertInstanceOf(LeaveApprovedEvent::class, $this->typed[0]);
		self::assertFalse($this->typed[0]->isWithdrawn());
	}//end testSavingAnApprovedLeaveAgainSendsNothing()

	public function testApprovedLeaveNoLongerApprovedIsWithdrawn(): void {
		$approved = ['id' => 'lv-1', 'employeeId' => 'emp-sam', 'startDate' => '2026-08-03', 'endDate' => '2026-08-07', 'status' => 'approved'];

		self::assertSame(1, $this->service()->emit('leaverequest', $approved, array_merge($approved, ['status' => 'rejected'])));
		self::assertSame('nl.conduction.hrmq.leave.withdrawn', $this->sent[0]['name']);
		self::assertTrue($this->typed[0]->isWithdrawn());
	}//end testApprovedLeaveNoLongerApprovedIsWithdrawn()

	/**
	 * Scenario: a sick report reaches rostering without detail.
	 */
	public function testASickReportCarriesOnlyTheDates(): void {
		$case = ['id' => 'sick-1', 'employeeId' => 'emp-sam', 'firstSickDay' => '2026-09-21', 'status' => 'gemeld', 'loondoorbetalingPercentage' => 70, 'currentAbsencePercentage' => 100, 'wachtdag' => true];

		self::assertSame(1, $this->service()->emit('sickleavecase', null, $case));
		self::assertSame('nl.conduction.hrmq.sickness.reported', $this->sent[0]['name']);
		self::assertSame(
			['employeeId' => 'emp-sam', 'nextcloudUserId' => 'sam', 'administrationId' => 'ADM-001', 'occurredOn' => '2026-09-21', 'from' => '2026-09-21', 'to' => null],
			$this->sent[0]['payload']['data']
		);
		self::assertInstanceOf(SicknessReportedEvent::class, $this->typed[0]);
		self::assertFalse($this->typed[0]->isRecovered());

		$this->service()->emit('sickleavecase', $case, array_merge($case, ['status' => 'hersteld', 'recoveredDate' => '2026-09-25']));
		self::assertSame('nl.conduction.hrmq.sickness.recovered', $this->sent[1]['name']);
		self::assertSame(['from' => '2026-09-21', 'to' => '2026-09-25'], array_intersect_key($this->sent[1]['payload']['data'], ['from' => 1, 'to' => 1]));
		self::assertTrue($this->typed[1]->isRecovered());
	}//end testASickReportCarriesOnlyTheDates()

	public function testAFirstContractJoinsWithTheSameIdAsTheOnboardingAndASecondDoesNot(): void {
		$this->store->seed('EmploymentContract', 'ct-1', ['employeeId' => 'emp-new', 'startDate' => '2026-10-01']);
		$this->store->seed('Employee', 'emp-new', ['firstName' => 'Nieuw', 'nextcloudUserId' => 'nieuw']);

		self::assertSame(1, $this->service()->emit('employmentcontract', null, ['id' => 'ct-1', 'employeeId' => 'emp-new', 'startDate' => '2026-10-01']));
		self::assertSame('nl.conduction.hrmq.employee.joined', $this->sent[0]['name']);
		self::assertSame('2026-10-01', $this->sent[0]['payload']['data']['startDate']);
		self::assertInstanceOf(EmployeeJoinedEvent::class, $this->typed[0]);

		$this->service()->emit('onboarding', ['employeeId' => 'emp-new', 'startDate' => '2026-10-01', 'status' => 'proeftijd_lopend'], ['id' => 'onb-1', 'employeeId' => 'emp-new', 'startDate' => '2026-10-01', 'status' => 'afgerond']);
		self::assertSame($this->sent[0]['payload']['id'], $this->sent[1]['payload']['id']);

		$this->store->seed('EmploymentContract', 'ct-2', ['employeeId' => 'emp-new', 'startDate' => '2027-01-01']);
		self::assertSame(0, $this->service()->emit('employmentcontract', null, ['id' => 'ct-2', 'employeeId' => 'emp-new', 'startDate' => '2027-01-01']));
	}//end testAFirstContractJoinsWithTheSameIdAsTheOnboardingAndASecondDoesNot()

	public function testANewFunctionOrASecondPlacementIsAJobChange(): void {
		$contract = ['id' => 'ct-sam', 'employeeId' => 'emp-sam', 'startDate' => '2024-01-01', 'normfunctieId' => 'nf-medewerker'];
		$this->store->seed('EmploymentContract', 'ct-sam', $contract);

		self::assertSame(1, $this->service()->emit('employmentcontract', $contract, array_merge($contract, ['normfunctieId' => 'nf-teamleider'])));
		self::assertSame('nl.conduction.hrmq.employee.jobchanged', $this->sent[0]['name']);
		self::assertSame(['orgUnitId' => 'unit-bo', 'normfunctieId' => 'nf-medewerker'], $this->sent[0]['payload']['data']['from']);
		self::assertSame(['orgUnitId' => 'unit-bo', 'normfunctieId' => 'nf-teamleider'], $this->sent[0]['payload']['data']['to']);
		self::assertInstanceOf(EmployeeJobChangedEvent::class, $this->typed[0]);

		$this->store->seed('OrgAssignment', 'as-sam-2', ['employeeId' => 'emp-sam', 'orgUnitId' => 'unit-ops', 'startDate' => '2026-11-01']);
		self::assertSame(1, $this->service()->emit('orgassignment', null, ['id' => 'as-sam-2', 'employeeId' => 'emp-sam', 'orgUnitId' => 'unit-ops', 'startDate' => '2026-11-01']));
		self::assertSame('unit-bo', $this->sent[1]['payload']['data']['from']['orgUnitId']);
		self::assertSame('unit-ops', $this->sent[1]['payload']['data']['to']['orgUnitId']);

		self::assertSame(0, $this->service()->emit('employmentcontract', $contract, array_merge($contract, ['hoursPerWeek' => 32])));
	}//end testANewFunctionOrASecondPlacementIsAJobChange()

	public function testTheLastContractEndingIsALeaverButOneOfTwoIsNot(): void {
		$contract = ['id' => 'ct-sam', 'employeeId' => 'emp-sam', 'startDate' => '2024-01-01'];
		$this->store->seed('EmploymentContract', 'ct-sam', $contract);
		$this->store->seed('EmploymentContract', 'ct-sam-2', ['employeeId' => 'emp-sam', 'startDate' => '2025-01-01']);

		self::assertSame(0, $this->service()->emit('employmentcontract', $contract, array_merge($contract, ['endDate' => '2026-01-31'])));

		$this->store->state->objects['EmploymentContract']['ct-sam-2']['endDate'] = '2026-01-31';
		self::assertSame(1, $this->service()->emit('employmentcontract', $contract, array_merge($contract, ['endDate' => '2026-01-31'])));
		self::assertSame('nl.conduction.hrmq.employee.left', $this->sent[0]['name']);
		self::assertSame('2026-01-31', $this->sent[0]['payload']['data']['lastWorkingDay']);
	}//end testTheLastContractEndingIsALeaverButOneOfTwoIsNot()

	public function testATypedDispatchFailureDoesNotStopTheWebhook(): void {
		$this->typedThrows = true;
		$case = ['id' => 'sick-1', 'employeeId' => 'emp-sam', 'firstSickDay' => '2026-09-21', 'status' => 'gemeld'];

		self::assertSame(1, $this->service()->emit('sickleavecase', null, $case));
		self::assertCount(1, $this->sent);
	}//end testATypedDispatchFailureDoesNotStopTheWebhook()

	/**
	 * The service over the store, with a webhook spy and a recording dispatcher.
	 */
	private function service(): HrLifecycleEventService {
		$test = $this;
		$webhook = new class($test) {

			public function __construct(
				private readonly HrLifecycleEventServiceTest $test,
			) {
			}//end __construct()

			public function dispatchEvent(Event $_event, string $eventName, array $payload): void {
				$this->test->sent[] = ['name' => $eventName, 'payload' => $payload];
			}//end dispatchEvent()

		};
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (object $event): void {
				if ($this->typedThrows === true) {
					throw new \RuntimeException('no listener');
				}

				$this->typed[] = $event;
			}
		);

		return new HrLifecycleEventService(
			moments: new HrLifecycleMoments($this->gateway()),
			gateway: $this->gateway(),
			container: new FakeContainer(['OCA\OpenRegister\Service\WebhookService' => $webhook]),
			eventDispatcher: $dispatcher,
			logger: new NullLogger()
		);
	}//end service()

}//end class
