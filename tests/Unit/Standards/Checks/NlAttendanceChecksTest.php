<?php

/**
 * Unit tests for the Arbeidstijdenwet attendance checks (NlAttendanceChecks).
 *
 * Pins the three predicates registered under `AttendanceRecord`:
 * `nl-atw-dagelijkse-rust` (>= 11h rest between consecutive working days,
 * fed by the `$context['attendance']['clockByEmployeeDate']` sibling
 * index), `nl-atw-max-werkdag` (<= 12h per dienst), and `nl-atw-pauze`
 * (statutory break tiers read from the corpus rule's `parameters.breakTiers`).
 * All predicates compute from the raw `clockIn`/`clockOut`/`breakMinutes`
 * fields, never from `workedHours` — a hand-edited or stale `workedHours`
 * can neither mask nor fabricate a violation (design D2, REQ-TA-002).
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\Standards\Checks
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
 * @spec openspec/changes/time-attendance-mvp/specs/time-attendance/spec.md#REQ-TA-004
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Standards\Checks;

use OCA\Hrmq\Standards\Checks\NlAttendanceChecks;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NlAttendanceChecks.
 *
 * @spec openspec/changes/time-attendance-mvp/specs/time-attendance/spec.md#REQ-TA-004
 */
class NlAttendanceChecksTest extends TestCase
{


    /**
     * The registered AttendanceRecord predicates, keyed by rule id.
     *
     * @var array<string, callable>
     */
    private array $checks;


    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->checks = NlAttendanceChecks::checks()['AttendanceRecord'];

    }//end setUp()


    /**
     * A minimal AttendanceRecord fixture; each test overrides the fields it
     * exercises.
     *
     * @param array<string, mixed> $overrides Fields to override.
     *
     * @return array<string, mixed>
     */
    private function record(array $overrides=[]): array
    {
        return array_merge(
            [
                'employeeId'   => 'employee-jansen',
                'date'         => '2026-07-09',
                'clockIn'      => '2026-07-09T08:30:00Z',
                'clockOut'     => '2026-07-09T17:00:00Z',
                'breakMinutes' => 30,
                'workedHours'  => 8.0,
                'location'     => 'kantoor',
                'status'       => 'gesloten',
            ],
            $overrides
        );

    }//end record()


    /**
     * An `$context['attendance']['clockByEmployeeDate']` fixture.
     *
     * @param array<string, array<string, array<string, mixed>>> $index Per-employee date index.
     *
     * @return array<string, mixed>
     */
    private function context(array $index=[]): array
    {
        return ['attendance' => ['clockByEmployeeDate' => $index]];

    }//end context()


    // -- nl-atw-dagelijkse-rust ----------------------------------------------


    /**
     * @return void
     */
    public function testCompliantWithNoPreviousDaySiblingPassesVacuously(): void
    {
        $record  = $this->record();
        $context = $this->context();

        $this->assertTrue(($this->checks['nl-atw-dagelijkse-rust'])($record, $context));

    }//end testCompliantWithNoPreviousDaySiblingPassesVacuously()


    /**
     * @return void
     */
    public function testShortOvernightRestViolates(): void
    {
        // devries: clockOut 2026-07-08T23:00Z, next day clockIn 2026-07-09T07:00Z — 8h gap.
        $record = $this->record(
            [
                'employeeId' => 'employee-devries',
                'date'       => '2026-07-09',
                'clockIn'    => '2026-07-09T07:00:00Z',
                'clockOut'   => '2026-07-09T15:30:00Z',
            ]
        );
        $context = $this->context(
            [
                'employee-devries' => [
                    '2026-07-08' => ['clockIn' => '2026-07-08T14:00:00Z', 'clockOut' => '2026-07-08T23:00:00Z'],
                ],
            ]
        );

        $this->assertFalse(($this->checks['nl-atw-dagelijkse-rust'])($record, $context));

    }//end testShortOvernightRestViolates()


    /**
     * @return void
     */
    public function testExactlyElevenHoursRestIsCompliant(): void
    {
        $record = $this->record(
            [
                'employeeId' => 'employee-devries',
                'date'       => '2026-07-09',
                'clockIn'    => '2026-07-09T09:00:00Z',
            ]
        );
        $context = $this->context(
            [
                'employee-devries' => [
                    '2026-07-08' => ['clockIn' => '2026-07-08T14:00:00Z', 'clockOut' => '2026-07-08T22:00:00Z'],
                ],
            ]
        );

        $this->assertTrue(($this->checks['nl-atw-dagelijkse-rust'])($record, $context));

    }//end testExactlyElevenHoursRestIsCompliant()


    /**
     * @return void
     */
    public function testPreviousDayStillOpenPassesVacuously(): void
    {
        $record = $this->record(['employeeId' => 'employee-devries', 'date' => '2026-07-09']);
        $context = $this->context(
            [
                'employee-devries' => [
                    '2026-07-08' => ['clockIn' => '2026-07-08T14:00:00Z', 'clockOut' => null],
                ],
            ]
        );

        $this->assertTrue(($this->checks['nl-atw-dagelijkse-rust'])($record, $context));

    }//end testPreviousDayStillOpenPassesVacuously()


    /**
     * @return void
     */
    public function testAbsentAttendanceIndexPassesVacuously(): void
    {
        $record = $this->record();

        $this->assertTrue(($this->checks['nl-atw-dagelijkse-rust'])($record, []));

    }//end testAbsentAttendanceIndexPassesVacuously()


    // -- nl-atw-max-werkdag ---------------------------------------------------


    /**
     * @return void
     */
    public function testTwelveHourShiftIsCompliant(): void
    {
        $record = $this->record(['clockIn' => '2026-07-09T08:00:00Z', 'clockOut' => '2026-07-09T20:00:00Z']);

        $this->assertTrue(($this->checks['nl-atw-max-werkdag'])($record, []));

    }//end testTwelveHourShiftIsCompliant()


    /**
     * @return void
     */
    public function testThirteenHourShiftViolates(): void
    {
        $record = $this->record(['clockIn' => '2026-07-10T08:00:00Z', 'clockOut' => '2026-07-10T21:00:00Z']);

        $this->assertFalse(($this->checks['nl-atw-max-werkdag'])($record, []));

    }//end testThirteenHourShiftViolates()


    /**
     * @return void
     */
    public function testOpenDayPassesMaxWerkdagVacuously(): void
    {
        $record = $this->record(['clockOut' => null, 'workedHours' => null, 'status' => 'open']);

        $this->assertTrue(($this->checks['nl-atw-max-werkdag'])($record, []));

    }//end testOpenDayPassesMaxWerkdagVacuously()


    /**
     * @return void
     */
    public function testMaxWerkdagIgnoresAHandEditedWorkedHours(): void
    {
        // 13h elapsed, but workedHours is (wrongly) set to 8 — the check must
        // still flag the violation, computed from clockIn/clockOut (REQ-TA-002).
        $record = $this->record(
            [
                'clockIn'     => '2026-07-10T08:00:00Z',
                'clockOut'    => '2026-07-10T21:00:00Z',
                'workedHours' => 8,
            ]
        );

        $this->assertFalse(($this->checks['nl-atw-max-werkdag'])($record, []));

    }//end testMaxWerkdagIgnoresAHandEditedWorkedHours()


    // -- nl-atw-pauze -----------------------------------------------------------


    /**
     * @return void
     */
    public function testCompliantThirtyMinuteBreakSatisfiesTheLowerTier(): void
    {
        // 8.5h elapsed, 30 min break — >= the 5.5h tier's 30 min requirement.
        $record = $this->record(['clockIn' => '2026-07-08T14:00:00Z', 'clockOut' => '2026-07-08T22:30:00Z', 'breakMinutes' => 30]);

        $this->assertTrue(($this->checks['nl-atw-pauze'])($record, []));

    }//end testCompliantThirtyMinuteBreakSatisfiesTheLowerTier()


    /**
     * @return void
     */
    public function testZeroBreakOnAnEightHourDayViolates(): void
    {
        $record = $this->record(['clockIn' => '2026-07-10T08:00:00Z', 'clockOut' => '2026-07-10T16:00:00Z', 'breakMinutes' => 0]);

        $this->assertFalse(($this->checks['nl-atw-pauze'])($record, []));

    }//end testZeroBreakOnAnEightHourDayViolates()


    /**
     * @return void
     */
    public function testMoreThanTenHoursRequiresFortyFiveMinutes(): void
    {
        // 10.5h elapsed with only 30 min break — satisfies the 5.5h tier but
        // not the 10h tier (needs >= 45 min).
        $record = $this->record(['clockIn' => '2026-07-09T07:00:00Z', 'clockOut' => '2026-07-09T17:30:00Z', 'breakMinutes' => 30]);

        $this->assertFalse(($this->checks['nl-atw-pauze'])($record, []));

    }//end testMoreThanTenHoursRequiresFortyFiveMinutes()


    /**
     * @return void
     */
    public function testMoreThanTenHoursWithFortyFiveMinutesIsCompliant(): void
    {
        $record = $this->record(['clockIn' => '2026-07-09T07:00:00Z', 'clockOut' => '2026-07-09T17:30:00Z', 'breakMinutes' => 45]);

        $this->assertTrue(($this->checks['nl-atw-pauze'])($record, []));

    }//end testMoreThanTenHoursWithFortyFiveMinutesIsCompliant()


    /**
     * @return void
     */
    public function testShortShiftUnderTheLowerTierRequiresNoBreak(): void
    {
        // 5h elapsed — under the 5.5h tier, so a zero break is compliant.
        $record = $this->record(['clockIn' => '2026-07-09T08:00:00Z', 'clockOut' => '2026-07-09T13:00:00Z', 'breakMinutes' => 0]);

        $this->assertTrue(($this->checks['nl-atw-pauze'])($record, []));

    }//end testShortShiftUnderTheLowerTierRequiresNoBreak()


    /**
     * @return void
     */
    public function testOpenDayPassesPauzeVacuously(): void
    {
        $record = $this->record(['clockOut' => null, 'workedHours' => null, 'status' => 'open']);

        $this->assertTrue(($this->checks['nl-atw-pauze'])($record, []));

    }//end testOpenDayPassesPauzeVacuously()


}//end class
