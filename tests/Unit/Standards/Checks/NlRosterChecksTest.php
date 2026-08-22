<?php

/**
 * Unit tests for the Arbeidstijdenwet roster checks (NlRosterChecks).
 *
 * Pins the three predicates registered under `RosterAssignment`, which REUSE
 * the exact same corpus rule ids `NlAttendanceChecks` enforces over
 * `AttendanceRecord` — `nl-atw-dagelijkse-rust` (>= 11h rest between
 * consecutive planned working days, fed by
 * `$context['rostering']['plannedClockByEmployeeDate']`),
 * `nl-atw-max-werkdag` (<= 12h per dienst) and `nl-atw-pauze` (statutory
 * break tiers read from the corpus rule's `parameters.breakTiers`) — over
 * the projected `plannedStart`/`plannedEnd`/`plannedBreakMinutes` fields.
 *
 * The `testRuleEngine*` cases drive the REAL `RuleEngine::evaluate()` end to
 * end (auto-discovering NlRosterChecks via the provider glob) to prove the
 * predicates are not an orphaned capability: a rest-violating published
 * assignment yields a mandatory `nl-atw-dagelijkse-rust` Violation, and a
 * compliant assignment yields none.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Standards\Checks
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
 * @spec openspec/changes/rostering/specs/rostering/spec.md#REQ-ROST-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Standards\Checks;

use OCA\Humaniq\Standards\Checks\NlRosterChecks;
use OCA\Humaniq\Standards\RuleEngine;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NlRosterChecks.
 *
 * @spec openspec/changes/rostering/specs/rostering/spec.md#REQ-ROST-004
 */
class NlRosterChecksTest extends TestCase {

	/**
	 * The registered RosterAssignment predicates, keyed by rule id.
	 *
	 * @var array<string, callable>
	 */
	private array $checks;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->checks = NlRosterChecks::checks()['RosterAssignment'];
		RuleEngine::reset();

	}//end setUp()

	/**
	 * A minimal RosterAssignment fixture (a compliant 07:00-15:30 shift with
	 * a 30-minute break); each test overrides the fields it exercises.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function assignment(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'assignment-a',
				'rosterId' => 'roster-w28',
				'employeeId' => 'employee-jansen',
				'shiftId' => 'shift-vroeg',
				'date' => '2026-07-13',
				'plannedStart' => '2026-07-13T07:00:00',
				'plannedEnd' => '2026-07-13T15:30:00',
				'plannedBreakMinutes' => 30,
				'userId' => 'jansen',
			],
			$overrides
		);

	}//end assignment()

	/**
	 * A `$context['rostering']['plannedClockByEmployeeDate']` fixture.
	 *
	 * @param array<string, array<string, array<string, mixed>>> $index Per-employee date index.
	 *
	 * @return array<string, mixed>
	 */
	private function context(array $index = []): array {
		return ['rostering' => ['plannedClockByEmployeeDate' => $index]];
	}//end context()

	// -- nl-atw-dagelijkse-rust (predicate-level) ----------------------------

	/**
	 * @return void
	 */
	public function testNoPreviousDaySiblingPassesVacuously(): void {
		$this->assertTrue(($this->checks['nl-atw-dagelijkse-rust'])($this->assignment(), $this->context()));

	}//end testNoPreviousDaySiblingPassesVacuously()

	/**
	 * @return void
	 */
	public function testShortOvernightRestViolates(): void {
		// Previous planned dienst ends 2026-07-13T23:00, this one starts
		// 2026-07-14T06:00 — 7h planned rest, below the 11h norm.
		$assignment = $this->assignment(
			[
				'date' => '2026-07-14',
				'plannedStart' => '2026-07-14T06:00:00',
				'plannedEnd' => '2026-07-14T14:00:00',
			]
		);
		$context = $this->context(
			[
				'employee-jansen' => [
					'2026-07-13' => ['clockIn' => '2026-07-13T15:00:00', 'clockOut' => '2026-07-13T23:00:00'],
				],
			]
		);

		$this->assertFalse(($this->checks['nl-atw-dagelijkse-rust'])($assignment, $context));

	}//end testShortOvernightRestViolates()

	/**
	 * @return void
	 */
	public function testExactlyElevenHoursRestIsCompliant(): void {
		$assignment = $this->assignment(
			[
				'date' => '2026-07-14',
				'plannedStart' => '2026-07-14T09:00:00',
				'plannedEnd' => '2026-07-14T17:00:00',
			]
		);
		$context = $this->context(
			[
				'employee-jansen' => [
					'2026-07-13' => ['clockIn' => '2026-07-13T14:00:00', 'clockOut' => '2026-07-13T22:00:00'],
				],
			]
		);

		$this->assertTrue(($this->checks['nl-atw-dagelijkse-rust'])($assignment, $context));

	}//end testExactlyElevenHoursRestIsCompliant()

	/**
	 * @return void
	 */
	public function testSiblingWithNullPlannedEndPassesVacuously(): void {
		$assignment = $this->assignment(['date' => '2026-07-14', 'plannedStart' => '2026-07-14T06:00:00']);
		$context = $this->context(
			[
				'employee-jansen' => [
					'2026-07-13' => ['clockIn' => '2026-07-13T15:00:00', 'clockOut' => null],
				],
			]
		);

		$this->assertTrue(($this->checks['nl-atw-dagelijkse-rust'])($assignment, $context));

	}//end testSiblingWithNullPlannedEndPassesVacuously()

	/**
	 * @return void
	 */
	public function testAbsentRosterIndexPassesVacuously(): void {
		$this->assertTrue(($this->checks['nl-atw-dagelijkse-rust'])($this->assignment(), []));

	}//end testAbsentRosterIndexPassesVacuously()

	// -- nl-atw-max-werkdag ---------------------------------------------------

	/**
	 * @return void
	 */
	public function testTwelveHourPlannedShiftIsCompliant(): void {
		$assignment = $this->assignment(['plannedStart' => '2026-07-13T08:00:00', 'plannedEnd' => '2026-07-13T20:00:00']);

		$this->assertTrue(($this->checks['nl-atw-max-werkdag'])($assignment, []));

	}//end testTwelveHourPlannedShiftIsCompliant()

	/**
	 * @return void
	 */
	public function testThirteenHourPlannedShiftViolates(): void {
		$assignment = $this->assignment(['plannedStart' => '2026-07-13T08:00:00', 'plannedEnd' => '2026-07-13T21:00:00']);

		$this->assertFalse(($this->checks['nl-atw-max-werkdag'])($assignment, []));

	}//end testThirteenHourPlannedShiftViolates()

	/**
	 * @return void
	 */
	public function testNightShiftAcrossMidnightRespectsMaxWerkdag(): void {
		// 22:00 -> 06:00 next day = 8h — compliant.
		$assignment = $this->assignment(['plannedStart' => '2026-07-13T22:00:00', 'plannedEnd' => '2026-07-14T06:00:00']);

		$this->assertTrue(($this->checks['nl-atw-max-werkdag'])($assignment, []));

	}//end testNightShiftAcrossMidnightRespectsMaxWerkdag()

	/**
	 * @return void
	 */
	public function testNullPlannedEndPassesMaxWerkdagVacuously(): void {
		$assignment = $this->assignment(['plannedEnd' => null]);

		$this->assertTrue(($this->checks['nl-atw-max-werkdag'])($assignment, []));

	}//end testNullPlannedEndPassesMaxWerkdagVacuously()

	// -- nl-atw-pauze ---------------------------------------------------------

	/**
	 * @return void
	 */
	public function testCompliantThirtyMinuteBreakSatisfiesTheLowerTier(): void {
		// 8.5h elapsed, 30 min break.
		$assignment = $this->assignment(['plannedStart' => '2026-07-13T07:00:00', 'plannedEnd' => '2026-07-13T15:30:00', 'plannedBreakMinutes' => 30]);

		$this->assertTrue(($this->checks['nl-atw-pauze'])($assignment, []));

	}//end testCompliantThirtyMinuteBreakSatisfiesTheLowerTier()

	/**
	 * @return void
	 */
	public function testZeroBreakOnAnEightHourPlannedDayViolates(): void {
		$assignment = $this->assignment(['plannedStart' => '2026-07-13T08:00:00', 'plannedEnd' => '2026-07-13T16:00:00', 'plannedBreakMinutes' => 0]);

		$this->assertFalse(($this->checks['nl-atw-pauze'])($assignment, []));

	}//end testZeroBreakOnAnEightHourPlannedDayViolates()

	/**
	 * @return void
	 */
	public function testMoreThanTenHoursRequiresFortyFiveMinutes(): void {
		// 10.5h elapsed with only 30 min break — fails the 10h tier.
		$assignment = $this->assignment(['plannedStart' => '2026-07-13T07:00:00', 'plannedEnd' => '2026-07-13T17:30:00', 'plannedBreakMinutes' => 30]);

		$this->assertFalse(($this->checks['nl-atw-pauze'])($assignment, []));

	}//end testMoreThanTenHoursRequiresFortyFiveMinutes()

	/**
	 * @return void
	 */
	public function testShortPlannedShiftUnderTheLowerTierRequiresNoBreak(): void {
		// 5h elapsed — under the 5.5h tier, so a zero break is compliant.
		$assignment = $this->assignment(['plannedStart' => '2026-07-13T08:00:00', 'plannedEnd' => '2026-07-13T13:00:00', 'plannedBreakMinutes' => 0]);

		$this->assertTrue(($this->checks['nl-atw-pauze'])($assignment, []));

	}//end testShortPlannedShiftUnderTheLowerTierRequiresNoBreak()

	// -- REAL RuleEngine::evaluate() (predicates are reachable) ---------------

	/**
	 * A rest-violating published assignment yields a MANDATORY
	 * `nl-atw-dagelijkse-rust` violation through the real engine — proving
	 * the provider is auto-discovered and the predicate fires.
	 *
	 * @return void
	 */
	public function testRuleEngineReportsMandatoryRestViolation(): void {
		$assignment = $this->assignment(
			[
				'date' => '2026-07-14',
				'plannedStart' => '2026-07-14T06:00:00',
				'plannedEnd' => '2026-07-14T14:00:00',
			]
		);
		$context = $this->context(
			[
				'employee-jansen' => [
					'2026-07-13' => ['clockIn' => '2026-07-13T15:00:00', 'clockOut' => '2026-07-13T23:00:00'],
				],
			]
		);

		$violations = RuleEngine::evaluate('RosterAssignment', $assignment, $context);

		$ruleIds = array_map(static fn ($v): string => $v->ruleId, $violations);
		$this->assertContains('nl-atw-dagelijkse-rust', $ruleIds);
		$this->assertTrue(RuleEngine::hasMandatory($violations));

	}//end testRuleEngineReportsMandatoryRestViolation()

	/**
	 * A compliant published assignment (8.5h elapsed, 30 min break, no prior
	 * sibling) yields NO ATW violation through the real engine.
	 *
	 * @return void
	 */
	public function testRuleEngineReportsNoViolationForCompliantAssignment(): void {
		$violations = RuleEngine::evaluate('RosterAssignment', $this->assignment(), $this->context());

		$this->assertSame([], $violations);

	}//end testRuleEngineReportsNoViolationForCompliantAssignment()

	/**
	 * The engine actually enforces all three ATW rules over RosterAssignment
	 * (supportedTypes + checkedRuleIds), and NlRosterChecks reuses the exact
	 * existing corpus ids — it registers no new rule.
	 *
	 * @return void
	 */
	public function testProviderReusesTheThreeExistingAtwRuleIds(): void {
		$this->assertContains('RosterAssignment', RuleEngine::supportedTypes());

		$expected = ['nl-atw-dagelijkse-rust', 'nl-atw-max-werkdag', 'nl-atw-pauze'];
		$this->assertSame($expected, array_keys($this->checks));

	}//end testProviderReusesTheThreeExistingAtwRuleIds()

}//end class
