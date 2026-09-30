<?php

/**
 * Unit tests for RosterCheckService.
 *
 * Drives `checkRoster()` / `checkPeriod()` end-to-end through a fake
 * ObjectService double (the RuleAuditServiceTest idiom — the real
 * OpenRegister ObjectService is a sibling-app dependency not available in
 * this standalone suite) and the REAL RuleEngine, asserting: the roster + its
 * assignments resolve and are evaluated; a rest-violating pair yields a
 * mandatory count; a compliant plan yields none; a concept roster IS checked
 * on demand (regardless of publish status — design D5); and an assignment
 * missing its projected planned-clock fields is filled in-memory from its
 * Shift for the check (design D2).
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
 * @spec openspec/specs/rostering/spec.md#REQ-ROST-005
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\RosterCheckService;
use OCA\Humaniq\Tests\Unit\Support\FakeSlugResolver;
use OCA\Humaniq\Standards\RuleEngine;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for RosterCheckService.
 *
 * @spec openspec/specs/rostering/spec.md#REQ-ROST-005
 */
class RosterCheckServiceTest extends TestCase {

	/**
	 * @return void
	 */
	protected function setUp(): void {
		RuleEngine::reset();

	}//end setUp()

	/**
	 * Build a RosterCheckService backed by a fake ObjectService that returns
	 * $rowsBySchema[$schema] for any findAll() call.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Rows keyed by schema name.
	 *
	 * @return RosterCheckService
	 */
	private function serviceWithRows(array $rowsBySchema): RosterCheckService {
		$objectService = new class($rowsBySchema) {

			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Rows keyed by schema name.
			 */
			public function __construct(
				private readonly array $rowsBySchema,
			) {

			}//end __construct()

			/**
			 * @param string $register Register slug (unused by the fake).
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			/**
			 * @param string $schema Schema name.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * @param array<string, mixed> $options Query options (unused by the fake).
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $options = []): array {
				return $this->rowsBySchema[$this->schema] ?? [];
			}//end findAll()

		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($objectService);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('humaniq');

		$logger = $this->createMock(LoggerInterface::class);

		return new RosterCheckService($container, $appConfig, $logger);
	}//end serviceWithRows()

	/**
	 * A concept roster with a rest-violating consecutive-day pair is checked
	 * on demand (regardless of publish status) and reports a mandatory
	 * violation, cross-checking the concept roster's own assignments against
	 * each other.
	 *
	 * @return void
	 */
	public function testConceptRosterRestViolationReportsMandatory(): void {
		$service = $this->serviceWithRows(
			[
				'Roster' => [
					['id' => 'roster-w28', 'period' => '2026-W28', 'status' => 'concept'],
				],
				'RosterAssignment' => [
					['id' => 'ra-1', 'rosterId' => 'roster-w28', 'employeeId' => 'emp-1', 'shiftId' => 'shift-late', 'date' => '2026-07-13', 'plannedStart' => '2026-07-13T15:00:00', 'plannedEnd' => '2026-07-13T23:00:00', 'plannedBreakMinutes' => 30],
					['id' => 'ra-2', 'rosterId' => 'roster-w28', 'employeeId' => 'emp-1', 'shiftId' => 'shift-early', 'date' => '2026-07-14', 'plannedStart' => '2026-07-14T06:00:00', 'plannedEnd' => '2026-07-14T14:00:00', 'plannedBreakMinutes' => 30],
				],
				'Shift' => [],
			]
		);

		$report = $service->checkRoster('roster-w28');

		$this->assertSame(1, $report['rostersChecked']);
		$this->assertSame(2, $report['assignmentsChecked']);
		$this->assertGreaterThanOrEqual(1, $report['mandatoryViolations']);

		$ruleIds = array_map(static fn (array $v): string => $v['ruleId'], $report['violations']);
		$this->assertContains('nl-atw-dagelijkse-rust', $ruleIds);

	}//end testConceptRosterRestViolationReportsMandatory()

	/**
	 * A compliant roster reports no violations.
	 *
	 * @return void
	 */
	public function testCompliantRosterReportsNoViolations(): void {
		$service = $this->serviceWithRows(
			[
				'Roster' => [
					['id' => 'roster-ok', 'period' => '2026-W29', 'status' => 'gepubliceerd'],
				],
				'RosterAssignment' => [
					['id' => 'ra-ok', 'rosterId' => 'roster-ok', 'employeeId' => 'emp-2', 'shiftId' => 'shift-day', 'date' => '2026-07-20', 'plannedStart' => '2026-07-20T07:00:00', 'plannedEnd' => '2026-07-20T15:30:00', 'plannedBreakMinutes' => 30],
				],
				'Shift' => [],
			]
		);

		$report = $service->checkRoster('roster-ok');

		$this->assertSame(1, $report['rostersChecked']);
		$this->assertSame(1, $report['assignmentsChecked']);
		$this->assertSame(0, $report['mandatoryViolations']);
		$this->assertSame([], $report['violations']);

	}//end testCompliantRosterReportsNoViolations()

	/**
	 * Approved leave and open sick leave on an assignment's date are reported
	 * in the same act as the working-time rules, as `leave` findings of their
	 * own kind (REQ-ROST-C05): approved leave counts as mandatory, sick leave
	 * as advisory, and neither carries the reason.
	 *
	 * @return void
	 */
	public function testLeaveOnARosteredDayIsReportedInTheSameCheck(): void {
		$service = $this->serviceWithRows(
			[
				'Roster' => [
					['id' => 'roster-ok', 'period' => '2026-W29', 'status' => 'concept'],
				],
				'RosterAssignment' => [
					['id' => 'ra-leave', 'rosterId' => 'roster-ok', 'employeeId' => 'emp-2', 'shiftId' => 'shift-day', 'date' => '2026-07-20', 'plannedStart' => '2026-07-20T07:00:00', 'plannedEnd' => '2026-07-20T15:30:00', 'plannedBreakMinutes' => 30],
					['id' => 'ra-sick', 'rosterId' => 'roster-ok', 'employeeId' => 'emp-3', 'shiftId' => 'shift-day', 'date' => '2026-07-21', 'plannedStart' => '2026-07-21T07:00:00', 'plannedEnd' => '2026-07-21T15:30:00', 'plannedBreakMinutes' => 30],
				],
				'Shift' => [],
				'LeaveRequest' => [
					['id' => 'lr-1', 'employeeId' => 'emp-2', 'status' => 'approved', 'startDate' => '2026-07-20', 'endDate' => '2026-07-24', 'reason' => 'Bruiloft'],
				],
				'SickLeaveCase' => [
					['id' => 'sick-1', 'employeeId' => 'emp-3', 'status' => 'gemeld', 'firstSickDay' => '2026-07-19', 'absenceProgression' => 'griep'],
				],
			]
		);

		$report = $service->checkRoster('roster-ok');

		$this->assertSame(2, $report['leaveFindings']);
		$this->assertSame(1, $report['mandatoryViolations']);
		$kinds = array_column($report['violations'], 'kind');
		$this->assertSame(['leave', 'leave'], $kinds);
		$this->assertSame(['ra-leave', 'ra-sick'], array_column($report['violations'], 'objectId'));
		$serialised = (string)json_encode($report);
		$this->assertStringNotContainsString('Bruiloft', $serialised);
		$this->assertStringNotContainsString('griep', $serialised);

	}//end testLeaveOnARosteredDayIsReportedInTheSameCheck()

	/**
	 * The report shapes that read nothing still carry the leave count, so a
	 * caller never reads a missing key as zero by accident.
	 *
	 * @return void
	 */
	public function testEveryReportShapeCarriesTheLeaveCount(): void {
		$service = $this->serviceWithRows(['Roster' => []]);

		$this->assertSame(0, $service->checkRoster('roster-none')['leaveFindings']);
		$this->assertSame(0, $service->checkRoster('')['leaveFindings']);

	}//end testEveryReportShapeCarriesTheLeaveCount()

	/**
	 * An assignment missing its projected planned-clock fields is filled
	 * in-memory from its referenced Shift for the purposes of the check
	 * (design D2), so a max-werkdag breach is still detected.
	 *
	 * @return void
	 */
	public function testMissingProjectionIsFilledFromShift(): void {
		$service = $this->serviceWithRows(
			[
				'Roster' => [
					['id' => 'roster-proj', 'period' => '2026-W30', 'status' => 'concept'],
				],
				'RosterAssignment' => [
					// No plannedStart/plannedEnd/plannedBreakMinutes — must be projected from the Shift.
					['id' => 'ra-proj', 'rosterId' => 'roster-proj', 'employeeId' => 'emp-3', 'shiftId' => 'shift-long', 'date' => '2026-07-27'],
				],
				'Shift' => [
					// 08:00 -> 21:00 = 13h — breaches nl-atw-max-werkdag.
					['id' => 'shift-long', 'startTime' => '08:00', 'endTime' => '21:00', 'breakMinutes' => 45],
				],
			]
		);

		$report = $service->checkRoster('roster-proj');

		$this->assertSame(1, $report['assignmentsChecked']);
		$ruleIds = array_map(static fn (array $v): string => $v['ruleId'], $report['violations']);
		$this->assertContains('nl-atw-max-werkdag', $ruleIds);

	}//end testMissingProjectionIsFilledFromShift()

	/**
	 * An unknown roster id resolves nothing and reports zero rosters checked.
	 *
	 * @return void
	 */
	public function testUnknownRosterReportsZero(): void {
		$service = $this->serviceWithRows(
			[
				'Roster' => [['id' => 'roster-x', 'period' => '2026-W28', 'status' => 'concept']],
				'RosterAssignment' => [],
				'Shift' => [],
			]
		);

		$report = $service->checkRoster('does-not-exist');

		$this->assertSame(0, $report['rostersChecked']);
		$this->assertSame(0, $report['assignmentsChecked']);
		$this->assertSame([], $report['violations']);

	}//end testUnknownRosterReportsZero()

	/**
	 * `checkPeriod()` resolves every roster of a period and evaluates their
	 * assignments.
	 *
	 * @return void
	 */
	public function testCheckPeriodResolvesRostersOfThePeriod(): void {
		$service = $this->serviceWithRows(
			[
				'Roster' => [
					['id' => 'roster-a', 'period' => '2026-W28', 'status' => 'concept'],
					['id' => 'roster-b', 'period' => '2026-W29', 'status' => 'concept'],
				],
				'RosterAssignment' => [
					['id' => 'ra-a', 'rosterId' => 'roster-a', 'employeeId' => 'emp-4', 'shiftId' => 's', 'date' => '2026-07-13', 'plannedStart' => '2026-07-13T07:00:00', 'plannedEnd' => '2026-07-13T15:30:00', 'plannedBreakMinutes' => 30],
				],
				'Shift' => [],
			]
		);

		$report = $service->checkPeriod('2026-W28');

		$this->assertSame(1, $report['rostersChecked']);
		$this->assertSame(1, $report['assignmentsChecked']);

	}//end testCheckPeriodResolvesRostersOfThePeriod()

	/**
	 * A check that could not read anything is NOT a check that found nothing.
	 *
	 * THE DEFECT THIS TEST EXISTS FOR. Both states used to return the same
	 * report: `rostersChecked => 0`, `violations => []`,
	 * `mandatoryViolations => 0`. That is what a compliant roster looks like.
	 * On an instance that has not run MigrateRegisterSlug the register is still
	 * `hrmq`, the service asked for the canonical `humaniq`, OpenRegister
	 * returned zero rows without raising, and an Arbeidstijdenwet check reported
	 * a clean estate it had never read. See ConductionNL/openregister#3579.
	 *
	 * `registerResolved` is what tells the two apart, so this asserts BOTH
	 * directions: false plus an error here, true on the ordinary empty result
	 * below.
	 *
	 * @return void
	 */
	public function testAnAbsentRegisterIsNotReportedAsACleanRoster(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn(new FakeSlugResolver([]));

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');

		$service = new RosterCheckService($container, $appConfig, $this->createMock(LoggerInterface::class));
		$report = $service->checkRoster('roster-1');

		$this->assertFalse($report['registerResolved'], 'An unread register must not look like a read one.');
		$this->assertArrayHasKey('error', $report, 'The report must say why nothing was checked.');
		$this->assertStringContainsString('humaniq-register is niet gevonden', (string)$report['error']);
		$this->assertSame(0, $report['rostersChecked']);
	}//end testAnAbsentRegisterIsNotReportedAsACleanRoster()

	/**
	 * checkPeriod() answers the same way, so the distinction is not one
	 * entry point deep.
	 *
	 * @return void
	 */
	public function testCheckPeriodAlsoRefusesToReportZerosAsAResult(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn(new FakeSlugResolver([]));

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');

		$service = new RosterCheckService($container, $appConfig, $this->createMock(LoggerInterface::class));
		$report = $service->checkPeriod('2026-W02');

		$this->assertFalse($report['registerResolved']);
		$this->assertArrayHasKey('error', $report);
	}//end testCheckPeriodAlsoRefusesToReportZerosAsAResult()

	/**
	 * A register that WAS read and simply held no matching roster reports
	 * registerResolved=true and carries no error.
	 *
	 * Without this the flag could be hard-coded false and both tests above
	 * would still pass.
	 *
	 * @return void
	 */
	public function testAGenuinelyEmptyResultIsStillMarkedAsRead(): void {
		$report = $this->serviceWithRows(['Roster' => []])->checkRoster('roster-1');

		$this->assertTrue($report['registerResolved'], 'A register that was read must say so.');
		$this->assertArrayNotHasKey('error', $report);
		$this->assertSame(0, $report['rostersChecked']);
	}//end testAGenuinelyEmptyResultIsStillMarkedAsRead()

}//end class
