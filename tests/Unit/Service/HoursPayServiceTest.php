<?php

/**
 * Approved hours and overtime as pay: which timesheets a run pays, what the
 * hours and the overtime come to, and the stamp that stops a second payment.
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
 * @spec openspec/specs/time-hours-and-overtime-to-payroll/spec.md#REQ-HTP-001
 * @spec openspec/specs/time-hours-and-overtime-to-payroll/spec.md#REQ-HTP-003
 * @spec openspec/specs/time-hours-and-overtime-to-payroll/spec.md#REQ-HTP-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\EmploymentTermsResolver;
use OCA\Humaniq\Service\HoursPayService;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Service\TimesheetAggregationService;
use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Selection, arithmetic, stamping.
 */
class HoursPayServiceTest extends TestCase {

	private const RUN = '5f0c2a1e-7b3d-4c8e-9f10-2a3b4c5d6e7f';

	/** @var list<array{payload: array<string, mixed>, schema: string, uuid: ?string}> */
	private array $saved = [];

	/**
	 * The service with a gateway that records its writes.
	 *
	 * @return HoursPayService
	 */
	private function service(): HoursPayService {
		$gateway = $this->createMock(HoursRegisterGateway::class);
		$gateway->method('save')->willReturnCallback(function (array $payload, string $schema, ?string $uuid = null): object {
			$this->saved[] = ['payload' => $payload, 'schema' => $schema, 'uuid' => $uuid];
			return new \stdClass();
		});

		return new HoursPayService(new EmploymentTermsResolver(), $gateway, new InternalWriteMarker());
	}//end service()

	/**
	 * An approved timesheet.
	 *
	 * @param string               $id        The id.
	 * @param string               $period    The period.
	 * @param float                $hours     Total hours.
	 * @param array<string, mixed> $overrides Other fields.
	 *
	 * @return array<string, mixed>
	 */
	private static function timesheet(string $id, string $period, float $hours, array $overrides = []): array {
		return array_merge(['id' => $id, 'employeeId' => 'emp-1', 'period' => $period, 'hours' => $hours, 'status' => 'approved'], $overrides);
	}//end timesheet()

	/**
	 * A run pays approved, unpaid timesheets up to its period, and its own
	 * earlier stamps; a timesheet stamped by an approved run is never paid
	 * again, one stamped by a deleted run is.
	 *
	 * @return void
	 */
	public function testWhichTimesheetsARunPays(): void {
		$timesheets = [
			self::timesheet('ts-may', '2026-05', 128),
			self::timesheet('ts-april-late', '2026-04', 8),
			self::timesheet('ts-june', '2026-06', 100),
			self::timesheet('ts-draft', '2026-05', 10, ['status' => 'submitted']),
			self::timesheet('ts-paid', '2026-04', 120, ['payrollRunId' => 'run-april']),
			self::timesheet('ts-own', '2026-05', 4, ['payrollRunId' => 'run-may']),
			self::timesheet('ts-orphan', '2026-03', 2, ['payrollRunId' => 'run-deleted']),
			self::timesheet('ts-other', '2026-05', 3, ['employeeId' => 'emp-2']),
		];
		$runs = ['run-april' => ['id' => 'run-april', 'status' => 'approved'], 'run-may' => ['id' => 'run-may', 'status' => 'draft']];

		$selected = $this->service()->timesheetsToPay(timesheets: $timesheets, employeeId: 'emp-1', period: '2026-05', runId: 'run-may', runsById: $runs);

		self::assertSame(['ts-may', 'ts-april-late', 'ts-own', 'ts-orphan'], array_column($selected, 'id'));
	}//end testWhichTimesheetsARunPays()

	/**
	 * An hourly employee is paid the approved hours times the hourly wage:
	 * 128 hours at 16.00 is 2048.00.
	 *
	 * @return void
	 */
	public function testHourlyPay(): void {
		$pay = $this->service()->payFor(
			employee: ['id' => 'emp-1'],
			contract: ['hoursPerWeek' => 32, 'hourlyWage' => 16.00],
			timesheets: [self::timesheet('ts-may', '2026-05', 128)],
			entries: [],
			nonWorkingDates: []
		);

		self::assertSame(204800, $pay['hourlyCents']);
		self::assertSame(0, $pay['overtimeCents']);
		self::assertSame(128.0, $pay['hoursPaid']);
		self::assertSame(16.0, $pay['hourlyRate']);
		self::assertSame(['ts-may'], $pay['timesheetIds']);
	}//end testHourlyPay()

	/**
	 * A Saturday overtime entry under a 50% surcharge pays 150% of the hourly
	 * rate; a salaried employee's rate is the salary over the contracted
	 * monthly hours.
	 *
	 * @return void
	 */
	public function testSaturdayOvertimeUnderAFiftyPercentSurcharge(): void {
		$contract = [
			'hoursPerWeek' => 40,
			'overtimeToeslagPercentages' => ['doordeweeks' => 25, 'zaterdag' => 50, 'zondag' => 100, 'feestdag' => 100],
			'overtimeTermsOverrideReason' => 'Arbeidsvoorwaardenregeling 2026, artikel 4.',
		];
		$entries = [
			['timesheetId' => 'ts-may', 'date' => '2026-05-16', 'hours' => 4, 'overtime' => true, 'overtimeCompensation' => 'pay'],
			['timesheetId' => 'ts-may', 'date' => '2026-05-18', 'hours' => 8],
		];

		$pay = $this->service()->payFor(
			employee: ['id' => 'emp-1', 'grossMonthlySalary' => 3466.67],
			contract: $contract,
			timesheets: [self::timesheet('ts-may', '2026-05', 12, ['overtimeHours' => 4])],
			entries: $entries,
			nonWorkingDates: []
		);

		// 3466.67 / (40 x 52 / 12) = 20.00 an hour; 4 x 20.00 x 1.5 = 120.00.
		self::assertSame(12000, $pay['overtimeCents']);
		self::assertSame(0, $pay['hourlyCents'], 'A salaried employee is paid the salary, not the regular hours.');
		self::assertSame(4.0, $pay['overtimeHours']);
		self::assertFalse($pay['surchargeUnresolved']);
	}//end testSaturdayOvertimeUnderAFiftyPercentSurcharge()

	/**
	 * A feestdag in the calendar is the feestdag category; overtime to be
	 * taken off is credited at the surcharge factor, not paid.
	 *
	 * @return void
	 */
	public function testTimeOffOnAFeestdag(): void {
		$contract = [
			'hoursPerWeek' => 36,
			'hourlyWage' => 20.00,
			'overtimeToeslagPercentages' => ['doordeweeks' => 25, 'zaterdag' => 50, 'zondag' => 100, 'feestdag' => 100],
			'overtimeTermsOverrideReason' => 'Arbeidsvoorwaardenregeling 2026, artikel 4.',
		];
		$entries = [['timesheetId' => 'ts-apr', 'date' => '2026-04-27', 'hours' => 3, 'overtime' => true, 'overtimeCompensation' => 'time']];

		$pay = $this->service()->payFor(
			employee: ['id' => 'emp-1'],
			contract: $contract,
			timesheets: [self::timesheet('ts-apr', '2026-04', 3, ['overtimeHours' => 3])],
			entries: $entries,
			nonWorkingDates: ['2026-04-27']
		);

		self::assertSame(0, $pay['overtimeCents']);
		self::assertSame(0, $pay['hourlyCents'], 'The overtime hours are not also paid as regular hours.');
		self::assertSame([['timesheetId' => 'ts-apr', 'hours' => 6.0]], $pay['timeCredits']);
	}//end testTimeOffOnAFeestdag()

	/**
	 * A CAO whose overtime article is not confirmed pays the base rate and
	 * says the surcharge was not resolved.
	 *
	 * @return void
	 */
	public function testAPlaceholderCaoIsFlagged(): void {
		$entries = [['timesheetId' => 'ts-may', 'date' => '2026-05-16', 'hours' => 2, 'overtime' => true, 'overtimeCompensation' => 'pay']];

		$pay = $this->service()->payFor(
			employee: ['id' => 'emp-1'],
			contract: ['hoursPerWeek' => 32, 'hourlyWage' => 16.00, 'cao' => 'cao-gemeenten'],
			timesheets: [self::timesheet('ts-may', '2026-05', 10, ['overtimeHours' => 2])],
			entries: $entries,
			nonWorkingDates: null
		);

		self::assertTrue($pay['surchargeUnresolved']);
		self::assertSame(3200, $pay['overtimeCents'], '2 hours at 16.00, no surcharge.');
		self::assertSame(12800, $pay['hourlyCents'], '8 regular hours at 16.00.');
	}//end testAPlaceholderCaoIsFlagged()

	/**
	 * Without the employee's choice, a confirmed CAO that says tijd-voor-tijd
	 * credits the overtime as time off.
	 *
	 * @return void
	 */
	public function testTheCaoDefaultIsTimeOff(): void {
		$entries = [['timesheetId' => 'ts-may', 'date' => '2026-05-18', 'hours' => 2, 'overtime' => true]];

		$pay = $this->service()->payFor(
			employee: ['id' => 'emp-1'],
			contract: ['hoursPerWeek' => 32, 'hourlyWage' => 16.00, 'cao' => 'cao-voorbeeld'],
			timesheets: [self::timesheet('ts-may', '2026-05', 2, ['overtimeHours' => 2])],
			entries: $entries,
			nonWorkingDates: []
		);

		self::assertSame(0, $pay['overtimeCents']);
		self::assertSame([['timesheetId' => 'ts-may', 'hours' => 2.5]], $pay['timeCredits'], 'cao-voorbeeld: 25% on a weekday.');
	}//end testTheCaoDefaultIsTimeOff()

	/**
	 * The run stamps what it paid and unstamps what it no longer pays; the
	 * stamp writes the whole timesheet, valid for its schema.
	 *
	 * @return void
	 */
	public function testStamping(): void {
		$timesheets = [
			self::timesheet('ts-may', '2026-05', 128, ['managerUserId' => 'boss', '@self' => ['id' => 'ts-may']]),
			self::timesheet('ts-reopened', '2026-05', 8, ['status' => 'submitted', 'payrollRunId' => self::RUN, 'paidInPeriod' => '2026-05']),
		];

		$this->service()->stamp(timesheets: $timesheets, paid: ['ts-may' => 0.0], runId: self::RUN, period: '2026-05');

		self::assertCount(2, $this->saved);
		self::assertSame('ts-may', $this->saved[0]['uuid']);
		self::assertSame(self::RUN, $this->saved[0]['payload']['payrollRunId']);
		self::assertSame('2026-05', $this->saved[0]['payload']['paidInPeriod']);
		self::assertSame('boss', $this->saved[0]['payload']['managerUserId']);
		self::assertArrayNotHasKey('@self', $this->saved[0]['payload']);
		self::assertSame([], RegisterSchemaValidator::errors('Timesheet', array_merge($this->saved[0]['payload'], ['employeeId' => '0127394a-be27-48b4-a592-b6a41774b221'])));
		self::assertNull($this->saved[1]['payload']['payrollRunId']);
		self::assertNull($this->saved[1]['payload']['paidInPeriod']);
	}//end testStamping()

	/**
	 * Edge paths: a Sunday is the zondag category; an override without a reason
	 * resolves no surcharge; an employee with neither salary nor hourly wage
	 * has no rate and is paid nothing; a stamp that is already right is not
	 * written again.
	 *
	 * @return void
	 */
	public function testEdgePaths(): void {
		$service = $this->service();
		$sunday = [['timesheetId' => 'ts-1', 'date' => '2026-05-17', 'hours' => 1, 'overtime' => true, 'overtimeCompensation' => 'pay']];
		$override = ['hoursPerWeek' => 40, 'hourlyWage' => 10.00, 'overtimeToeslagPercentages' => ['zondag' => 100], 'overtimeTermsOverrideReason' => 'Regeling 2026.'];
		self::assertSame(2000, $service->payFor(employee: ['id' => 'emp-1'], contract: $override, timesheets: [self::timesheet('ts-1', '2026-05', 1)], entries: $sunday, nonWorkingDates: [])['overtimeCents']);

		$noReason = ['hoursPerWeek' => 40, 'hourlyWage' => 10.00, 'overtimeToeslagPercentages' => ['zondag' => 100]];
		$pay = $service->payFor(employee: ['id' => 'emp-1'], contract: $noReason, timesheets: [self::timesheet('ts-1', '2026-05', 1)], entries: $sunday, nonWorkingDates: []);
		self::assertTrue($pay['surchargeUnresolved']);
		self::assertSame(1000, $pay['overtimeCents']);

		$none = $service->payFor(employee: ['id' => 'emp-1'], contract: ['hoursPerWeek' => 0], timesheets: [self::timesheet('ts-1', '2026-05', 8)], entries: [], nonWorkingDates: null);
		self::assertNull($none['hourlyRate']);
		self::assertSame(0, $none['hourlyCents']);
		self::assertNull($service->payFor(employee: ['id' => 'emp-1', 'grossMonthlySalary' => 3000], contract: ['hoursPerWeek' => 0], timesheets: [], entries: [], nonWorkingDates: null)['hourlyRate']);

		$service->stamp(timesheets: [self::timesheet('ts-1', '2026-05', 8, ['payrollRunId' => self::RUN, 'paidInPeriod' => '2026-05'])], paid: ['ts-1' => 0.0], runId: self::RUN, period: '2026-05');
		$service->stamp(timesheets: [self::timesheet('ts-2', '2026-05', 8, ['payrollRunId' => 'other-run'])], paid: [], runId: self::RUN, period: '2026-05');
		self::assertSame([], $this->saved);
	}//end testEdgePaths()

	/**
	 * The timesheet adds up its overtime hours next to its total.
	 *
	 * @return void
	 */
	public function testTheTimesheetAddsUpItsOvertime(): void {
		$settings = $this->createMock(SettingsService::class);
		$aggregates = (new TimesheetAggregationService($this->createMock(ContainerInterface::class), new InternalWriteMarker(), $settings))->computeAggregates(
			[
				['hours' => 8],
				['hours' => 7.5],
				['hours' => 3, 'overtime' => true],
			]
		);

		self::assertSame(18.5, $aggregates['hours']);
		self::assertSame(3.0, $aggregates['overtimeHours']);
	}//end testTheTimesheetAddsUpItsOvertime()

}//end class
